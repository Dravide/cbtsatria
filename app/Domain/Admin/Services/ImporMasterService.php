<?php

namespace App\Domain\Admin\Services;

use App\Domain\Admin\Enums\JenisImporMaster;
use App\Domain\Admin\Enums\StatusImporPengguna;
use App\Domain\Admin\Models\MasterImportBatch;
use App\Domain\Audit\Services\ActivityLogger;
use App\Support\Impor\BerkasImportTidakTerbaca;
use App\Support\Impor\PembacaBerkas;
use App\Support\Impor\PembacaCsv;
use App\Support\Impor\PembacaXlsx;
use App\Support\Impor\PenjajarKolom;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Impor master data - mata pelajaran, ruang, dan kelas.
 *
 * =====================================================================
 * Mengapa satu service untuk tiga jenis
 * =====================================================================
 * Bentuk ketiga impor ini identik: unggah, pratinjau per baris tanpa menyentuh
 * basis data, lalu konfirmasi. Yang berbeda diungkapkan sebagai PERTANYAAN ke
 * `JenisImporMaster` - daftar kolomnya, aturan validasinya, kolom kuncinya -
 * bukan sebagai percabangan yang tersebar di seluruh kelas ini. Kelas ini tidak
 * pernah menulis `if ($jenis === 'mapel')` untuk hal yang bisa ditanyakan ke
 * enum.
 *
 * =====================================================================
 * Baris dicocokkan lewat kolom kuncinya, dan itu yang membuat impor bisa
 * diulang
 * =====================================================================
 * `subjects.code` dan `rooms.code` unik per sekolah; `classes.name` unik per
 * (sekolah, tahun ajaran). Karena itu berkas yang sama boleh diunggah ulang
 * setelah diperbaiki: barisnya MEMPERBARUI yang sudah ada, bukan membuat yang
 * kedua. Yang akan terjadi pada jalur `create`-buta adalah galat unique
 * constraint - dan itu bukan kalimat yang bisa ditindaklanjuti Admin.
 *
 * Kolom kuncinya datang dari `JenisImporMaster::kolomKunci()`, dan seluruh
 * kelas ini bekerja atas nama KOLOM BERKAS - bukan nama bidang basis datanya.
 * Nama bidangnya hanya muncul di satu tempat, `bidangKunci()` saat konfirmasi
 * menanyakan baris mana yang sudah ada.
 *
 * Mode "perbarui" juga yang membuat angka `diperbarui` di pratinjau punya
 * artinya: Admin melihat bahwa berkasnya akan menyentuh 12 mapel yang sudah
 * ada SEBELUM ia menekan Konfirmasi.
 *
 * =====================================================================
 * Yang TIDAK disentuh impor ini
 * =====================================================================
 * Penugasan mengajar, wali kelas, dan keanggotaan kelas. Itu disengaja, dan
 * alasannya sama untuk ketiganya: yang disimpannya bukan atribut satu baris,
 * melainkan HUBUNGAN antara dua baris. Impor mapel menyentuh daftar mapelnya,
 * bukan siapa yang mengajar apa - dan berkas yang bisa membuat penugasan
 * adalah berkas yang bisa memberi guru izin menyusun soal lewat unggahan.
 *
 * Impor kelas mengikuti aturan yang sama: ia menyentuh daftar kelasnya, bukan
 * siapa walinya dan siapa anggotanya. Keduanya tetap dibuat di layar, tempat
 * Admin melihat kelas yang ia maksud.
 *
 * =====================================================================
 * Sel kosong tidak mengosongkan kolom yang sudah terisi
 * =====================================================================
 * Sama dengan impor pengguna, dan alasannya lebih kuat di sini: berkas yang
 * hanya memuat kode dan nama tidak boleh menghapus `kelompok` dan `tingkat`
 * mapel yang sudah diisi lengkap. Yang benar-benar ingin mengosongkan sebuah
 * kolom melakukannya di layar, tempat ia melihat kolom yang ia kosongkan.
 */
class ImporMasterService
{
    public function __construct(
        private readonly PembacaXlsx $xlsx,
        private readonly PembacaCsv $csv,
        private readonly ActivityLogger $logger,
    ) {}

    /* ===================================================== 1. Unggah */

    /**
     * Terima berkasnya, simpan ke disk privat, dan catat barisnya.
     *
     * @param  int|null  $tahunAjaranId  Tahun ajaran sasaran, WAJIB untuk jenis
     *                                   yang `butuhTahunAjaran()`. Disimpan di baris batch, bukan
     *                                   hanya di URL: Konfirmasi datang lebih lambat, dan bisa
     *                                   sesudah Admin berganti tahun ajaran di tab lain.
     *
     * @throws ValidationException
     */
    public function unggah(
        UploadedFile $berkas,
        JenisImporMaster $jenis,
        int $schoolId,
        int $userId,
        ?int $tahunAjaranId = null,
    ): MasterImportBatch {
        $ekstensi = strtolower($berkas->getClientOriginalExtension() ?: $berkas->extension() ?: '');
        $pembaca = $this->pilihPembaca($ekstensi);

        if ($pembaca === null) {
            throw ValidationException::withMessages([
                'berkas' => 'Berkas ".'.$ekstensi.'" tidak didukung. Unggah berkas .xlsx atau .csv - template impor bisa diunduh dari laman ini.',
            ]);
        }

        $isi = (string) file_get_contents($berkas->getRealPath());

        if ($isi === '') {
            throw ValidationException::withMessages([
                'berkas' => BerkasImportTidakTerbaca::kosong()->getMessage(),
            ]);
        }

        if (strlen($isi) > PembacaBerkas::BYTES_MAKS) {
            throw ValidationException::withMessages([
                'berkas' => BerkasImportTidakTerbaca::terlaluBesar(strlen($isi))->getMessage(),
            ]);
        }

        $hash = hash('sha256', $isi);

        // Mengunggah ulang berkas yang sama adalah dua kali impor yang sama.
        // Akibatnya di sini bukan duplikat - kuncinya unik - melainkan
        // `updated_at` yang bergeser pada seluruh baris sekolah tanpa satu pun
        // perubahan yang terlihat. Kerusakan yang tidak berbunyi.
        //
        // Tahun ajaran ikut diperiksa, dan itu yang membuat berkas kelas yang
        // sama boleh diimpor ke tahun ajaran BERBEDA: bentuk daftar kelas
        // jarang berubah antar tahun, isinya yang berubah. Menolaknya berarti
        // menolak impor yang sah untuk masalah yang tidak ada.
        //
        // Pemeriksaan ini - bukan unique index - yang menjadi penjaga
        // sebenarnya bagi mapel dan ruang: MySQL menganggap setiap NULL
        // berbeda di dalam unique index, sehingga index itu tidak menolak
        // apa pun untuk dua jenis yang tahunnya kosong.
        $sudahAda = MasterImportBatch::query()
            ->where('user_id', $userId)
            ->where('jenis', $jenis->value)
            ->where('academic_year_id', $tahunAjaranId)
            ->where('berkas_hash', $hash)
            ->first();

        if ($sudahAda !== null) {
            throw ValidationException::withMessages([
                'berkas' => 'Berkas ini sudah pernah Anda unggah ('.$sudahAda->nama_asli.', '.
                    $sudahAda->statusImpor()->label().'). Berkas yang sama tidak diimpor dua kali - '.
                    'perbaiki berkasnya lebih dulu, atau lanjutkan impor yang sudah ada.',
            ]);
        }

        return DB::transaction(function () use ($berkas, $isi, $hash, $jenis, $schoolId, $userId, $ekstensi, $tahunAjaranId) {
            $batch = MasterImportBatch::create([
                'school_id' => $schoolId,
                'user_id' => $userId,
                'jenis' => $jenis->value,
                'academic_year_id' => $tahunAjaranId,
                // Diisi di bawah, setelah id barisnya ada: jalurnya memuat id
                // batch, dan kolomnya NOT NULL. Satu UPDATE, dan tidak ada
                // berkas yang ditulis ke jalur yang tidak tercatat.
                'file_path' => '',
                'nama_asli' => $berkas->getClientOriginalName(),
                'berkas_hash' => $hash,
                'status' => StatusImporPengguna::Menunggu->value,
            ]);

            $jalur = $this->folder($jenis, $schoolId, (int) $batch->id)
                .'/'.Str::random(40).'.'.($ekstensi ?: 'xlsx');

            Storage::disk('local')->put($jalur, $isi);
            $batch->update(['file_path' => $jalur]);

            $this->logger->log('Mengunggah berkas impor '.$jenis->label(), $batch, [
                'nama_asli' => $batch->nama_asli,
            ]);

            return $batch;
        });
    }

    /* ================================================== 2. Pratinjau */

    /**
     * Urai berkasnya dan periksa setiap barisnya - tanpa menyimpan satu baris
     * pun.
     *
     * @throws ValidationException
     */
    public function pratinjau(MasterImportBatch $batch): MasterImportBatch
    {
        $jenis = $batch->jenisImpor();

        if ($jenis === null) {
            $this->tolakBerkas($batch, 'Jenis impor berkas ini tidak dikenali. Hapus barisnya, lalu unggah berkasnya lagi dari laman yang sesuai.');
        }

        // Jenis yang terikat tahun ajaran tidak bisa dipratinjau tanpa satu -
        // lihat `JenisImporMaster::butuhTahunAjaran()`. Tanpa pemeriksaan ini,
        // seluruh barisnya akan terlihat sah lalu gagal satu per satu saat
        // Konfirmasi, karena `classes.academic_year_id` NOT NULL. Yang dilaporkan
        // harus sebabnya, bukan 40 kegagalan yang sama.
        //
        // Pemeriksaan ini ada di sini, bukan hanya di controller: baris batch
        // bisa bertahan lebih lama daripada bentuk laman yang membuatnya.
        if ($jenis->butuhTahunAjaran() && $batch->academic_year_id === null) {
            $this->tolakBerkas($batch, 'Berkas '.strtolower($jenis->label()).' harus diimpor untuk satu tahun ajaran. Buka laman Kelas, pilih tahun ajarannya lebih dulu, lalu ulangi impornya dari sana.');
        }

        if ($batch->statusImpor() === StatusImporPengguna::Dikonfirmasi) {
            throw ValidationException::withMessages([
                'berkas' => 'Berkas ini sudah diimpor. Menguraikannya ulang akan memperbarui baris yang sama untuk kedua kalinya.',
            ]);
        }

        $pembaca = $this->pilihPembaca($this->ekstensi($batch));

        if ($pembaca === null) {
            $this->tolakBerkas($batch, 'Jenis berkasnya tidak dikenali. Unggah berkas .xlsx atau .csv.');
        }

        try {
            $baris = $pembaca->baca(Storage::disk('local')->path($batch->file_path));
        } catch (BerkasImportTidakTerbaca $e) {
            // Pesan kelas itu ditulis untuk ADMIN, dan ditampilkan apa adanya.
            $this->tolakBerkas($batch, $e->getMessage());
        }

        $kelasPenjajar = $jenis->penjajar();

        $judul = PenjajarKolom::temukan($baris, $kelasPenjajar);

        if ($judul === null) {
            $this->tolakBerkas($batch, 'Berkasnya tidak berisi satu baris pun. Isi dulu datanya, lalu unggah ulang.');
        }

        [$noJudul, $selJudul] = $judul;
        $penjajar = new $kelasPenjajar($selJudul, $noJudul);

        if (! $penjajar->sah()) {
            $this->tolakBerkas($batch, $penjajar->pesanKolomHilang());
        }

        $sekolahId = (int) $batch->school_id;

        // Kunci yang sudah ada dibaca SEKALI untuk seluruh berkas. Membacanya
        // per baris berarti satu query untuk setiap baris - dan lebih buruk
        // lagi, daftar yang bisa berbeda antar barisnya.
        $sudahAda = $this->kunciYangAda($jenis, $sekolahId, $batch->academic_year_id);

        $rincian = [];
        $sah = 0;
        $gagal = 0;
        $dibuat = 0;
        $diperbarui = 0;

        // Deteksi duplikat DI DALAM BERKAS sebelum menyentuh basis data:
        // kunci -> nomor baris pertama yang memakainya. Dua baris dengan kunci
        // yang sama akan saling menimpa pada Konfirmasi, dan yang terlihat
        // Admin adalah "2 baris sah" untuk satu mapel - angka yang membuatnya
        // mengira ada dua.
        $terlihat = [];

        foreach ($baris as $nomor => $sel) {
            if ($nomor <= $noJudul || PenjajarKolom::kosong($sel)) {
                continue;
            }

            $hasil = $this->periksaBaris(
                $jenis,
                $penjajar,
                $sel,
                (int) $nomor,
                $sudahAda,
                $terlihat,
            );

            if ($hasil['sah']) {
                $sah++;

                if ($hasil['aksi'] === 'baru') {
                    $dibuat++;
                } else {
                    $diperbarui++;
                }
            } else {
                $gagal++;
            }

            $rincian[] = $hasil;
        }

        if ($rincian === []) {
            $this->tolakBerkas($batch, 'Berkasnya hanya berisi baris judul kolom. Isi dulu baris datanya, lalu unggah ulang.');
        }

        $batch->update([
            'status' => StatusImporPengguna::Dipratinjau->value,
            'pratinjau_json' => [
                'baris' => $rincian,
                'sah' => $sah,
                'gagal' => $gagal,
                'baru' => $dibuat,
                'diperbarui' => $diperbarui,
                'kolom' => $penjajar->namaKolomTerbaca(),
                'baris_judul' => $noJudul,
            ],
            'total_rows' => $sah + $gagal,
            'success_rows' => 0,
            'failed_rows' => 0,
            'created_rows' => $dibuat,
            'updated_rows' => $diperbarui,
            'imported_at' => null,
        ]);

        $this->logger->log('Mempratinjau berkas impor '.$jenis->label(), $batch, [
            'sah' => $sah,
            'gagal' => $gagal,
            'baru' => $dibuat,
            'diperbarui' => $diperbarui,
        ]);

        return $batch->fresh();
    }

    /* ================================================= 3. Konfirmasi */

    /**
     * Simpan baris yang sah saja.
     *
     * @throws ValidationException
     */
    public function konfirmasi(MasterImportBatch $batch): MasterImportBatch
    {
        $jenis = $batch->jenisImpor();

        if ($jenis === null) {
            throw ValidationException::withMessages([
                'berkas' => 'Jenis impor berkas ini tidak dikenali.',
            ]);
        }

        if (! $batch->statusImpor()->bolehDikonfirmasi()) {
            throw ValidationException::withMessages([
                'berkas' => $batch->statusImpor() === StatusImporPengguna::Dikonfirmasi
                    ? 'Berkas ini sudah diimpor.'
                    : 'Berkas ini belum dipratinjau. Pratinjau lebih dulu supaya Anda tahu baris mana yang akan tersimpan.',
            ]);
        }

        $pratinjau = $batch->pratinjau_json ?? [];
        $rincian = $pratinjau['baris'] ?? [];

        if ($rincian === []) {
            throw ValidationException::withMessages([
                'berkas' => 'Tidak ada hasil pratinjau yang tersimpan. Ulangi pratinjaunya.',
            ]);
        }

        $sekolahId = (int) $batch->school_id;

        $hasil = $this->simpanBaris($jenis, $rincian, $sekolahId, $batch->academic_year_id);

        $laporan = $hasil['gagal_total'] > 0
            ? $this->tulisLaporan($jenis, $batch, $hasil['rincian'])
            : null;

        $batch->update([
            'status' => StatusImporPengguna::Dikonfirmasi->value,
            'success_rows' => $hasil['tersimpan'],
            'failed_rows' => $hasil['gagal_total'],
            'total_rows' => $hasil['tersimpan'] + $hasil['gagal_total'],
            'created_rows' => $hasil['dibuat'],
            'updated_rows' => $hasil['diperbarui'],
            'error_report_path' => $laporan,
            'imported_at' => now(),
        ]);

        $this->logger->log('Mengimpor '.$jenis->label().' dari berkas', $batch, [
            'dibuat' => $hasil['dibuat'],
            'diperbarui' => $hasil['diperbarui'],
            'gagal' => $hasil['gagal_total'],
        ]);

        return $batch->fresh();
    }

    /* ------------------------------------------------------- Mekanisme */

    /**
     * Tulis seluruh baris sah.
     *
     * @param  array<int, array<string, mixed>>  $rincian
     * @return array{tersimpan: int, dibuat: int, diperbarui: int, gagal_total: int, rincian: array<int, array<string, mixed>>}
     */
    private function simpanBaris(
        JenisImporMaster $jenis,
        array $rincian,
        int $sekolahId,
        ?int $tahunAjaranId = null,
    ): array {
        $model = $jenis->kelasModel();
        $kolom = $jenis->bidangKunci();

        // Kunci yang sah SEKARANG, diperiksa ulang untuk seluruh baris
        // sekaligus. Mapel bisa saja dibuat orang lain antara pratinjau dan
        // konfirmasi, dan baris yang menunjuk kunci yang sama harus MENIMPA -
        // bukan menempa jalur `create` lalu pecah pada unique constraint.
        //
        // Dibaca lewat `kunciYangAda()` yang sama dengan pratinjau, sehingga
        // year-scoping dan penurunan hurufnya tidak bisa berbeda antara
        // keduanya. Menyusun query kedua di sini adalah cara paling mudah
        // membuat "akan diperbarui" di layar menjadi "baris baru" saat
        // disimpan.
        $adaSekarang = $this->kunciYangAda($jenis, $sekolahId, $tahunAjaranId);

        $tersimpan = 0;
        $dibuat = 0;
        $diperbarui = 0;
        $gagal = 0;
        $rincianAkhir = [];

        DB::transaction(function () use (
            $model, $jenis, $kolom, $rincian, $sekolahId, $tahunAjaranId, $adaSekarang,
            &$tersimpan, &$dibuat, &$diperbarui, &$gagal, &$rincianAkhir
        ) {
            foreach ($rincian as $baris) {
                if (! ($baris['sah'] ?? false)) {
                    $gagal++;
                    $rincianAkhir[] = $baris;

                    continue;
                }

                $kunci = (string) $baris['kode'];
                $muatan = $baris['muatan'];

                // Id yang dicari adalah id yang SUDAH ADA saat pratinjau -
                // tapi peta itu dibaca ulang di atas, tepat sebelum menyimpan,
                // sehingga perubahan di antaranya tetap tertangkap.
                $id = $adaSekarang[mb_strtolower(trim($kunci))] ?? null;

                try {
                    if ($id !== null) {
                        $model::query()->whereKey($id)->update($muatan);
                        $diperbarui++;
                        $aksi = 'diperbarui';
                    } else {
                        $model::create($muatan + [
                            'school_id' => $sekolahId,
                            $kolom => $kunci,
                        ] + ($jenis->butuhTahunAjaran()
                            ? ['academic_year_id' => $tahunAjaranId]
                            : []));
                        $dibuat++;
                        $aksi = 'baru';
                    }

                    $tersimpan++;
                    $rincianAkhir[] = $baris + ['aksi_akhir' => $aksi];
                } catch (\Throwable $e) {
                    // Baris yang gagal TIDAK menggagalkan berkasnya. Sisa
                    // barisnya tetap tersimpan, dan yang gagal masuk laporan
                    // dengan sebabnya - aturan yang sama dengan dua importer
                    // sebelumnya.
                    $gagal++;
                    $rincianAkhir[] = $baris + [
                        'sah' => false,
                        'pesan' => 'Baris ini gagal disimpan: '.$e->getMessage(),
                    ];
                }
            }
        });

        return [
            'tersimpan' => $tersimpan,
            'dibuat' => $dibuat,
            'diperbarui' => $diperbarui,
            'gagal_total' => $gagal,
            'rincian' => $rincianAkhir,
        ];
    }

    /**
     * Periksa satu baris, tanpa menyentuh basis data.
     *
     * @param  array<int, string>  $sel
     * @param  array<string, int>  $sudahAda  kunci (ternormalkan) => id
     * @param  array<string, int>  $terlihat  kunci (ternormalkan) => nomor baris pertama
     * @return array<string, mixed>
     */
    private function periksaBaris(
        JenisImporMaster $jenis,
        PenjajarKolom $penjajar,
        array $sel,
        int $nomor,
        array $sudahAda,
        array &$terlihat,
    ): array {
        $kolomKunci = $jenis->kolomKunci();
        $kunci = $penjajar->ambil($sel, $kolomKunci);
        $nama = $penjajar->ambil($sel, 'nama');

        // Nama array sengaja `kode` untuk ketiga jenis, meski kolom kuncinya
        // berbeda-beda. Itu nama KUNCI di dalam muatan pratinjau, bukan nama
        // kolom berkasnya - dan laman pratinjau, laporan galat, serta riwayat
        // membacanya dengan nama itu. Menggantinya menjadi `kunci` berarti
        // mengubah bentuk seluruh baris pratinjau yang sudah tersimpan, demi
        // satu kata yang tidak pernah terlihat Admin.
        $dasar = [
            'nomor' => $nomor,
            'kode' => $kunci,
            'nama' => $nama,
            'sah' => false,
            'aksi' => 'gagal',
            'kolom' => null,
            'pesan' => null,
            'muatan' => [],
        ];

        if ($kunci === '') {
            return $this->gagalkanBaris($dasar, $kolomKunci, $jenis->pesanKunciKosong());
        }

        // Kunci dicocokkan TANPA memperhatikan huruf besar-kecilnya, dan itu
        // bukan kenyamanan: kolomnya di basis data memakai kolasi
        // `utf8mb4_unicode_ci`, sehingga 'X-1' dan 'x-1' adalah nilai yang
        // SAMA bagi unique index. Berkas yang menulis "x-1" untuk kelas "X-1"
        // akan lolos pemeriksaan ini bila dibandingkan persis, lalu pecah pada
        // unique constraint saat Konfirmasi - galat basis data mentah untuk
        // baris yang seharusnya menimpa, bukan menambah.
        $kunciBanding = mb_strtolower($kunci);

        // Duplikat DI DALAM berkas. Baris KEDUA yang ditolak, bukan yang
        // pertama: yang membaca laporan membuka berkasnya dari atas, dan baris
        // pertama itulah yang ia lihat lebih dulu.
        if (isset($terlihat[$kunciBanding])) {
            return $this->gagalkanBaris(
                $dasar,
                $kolomKunci,
                $jenis->labelKunci().' ini sudah dipakai baris '.$terlihat[$kunciBanding].' di berkas yang sama. Gabungkan keduanya, lalu unggah ulang.',
            );
        }

        $terlihat[$kunciBanding] = $nomor;

        $mentah = $this->selMentah($jenis, $penjajar, $sel);

        $validator = Validator::make($mentah, $this->aturan($jenis), JenisImporMaster::PESAN);

        if ($validator->fails()) {
            $bidang = (string) array_key_first($validator->errors()->toArray());

            // Nama kolom BERKASNYA, bukan nama bidang basis datanya - lihat
            // `JenisImporMaster::KOLOM`. Laporan yang menyebut "code" untuk
            // berkas berjudul "kode" membuat Admin mencari kolom yang tidak
            // ada di Excelnya.
            return $this->gagalkanBaris(
                $dasar,
                JenisImporMaster::kolomDari($bidang),
                (string) $validator->errors()->first(),
            );
        }

        $bersih = $validator->validated();

        // PC lebih banyak daripada kapasitas hampir selalu salah ketik, dan
        // salah ketik di sini muncul sebagai ruang yang "cukup" pada
        // pemeriksaan kesiapan padahal kursinya tidak ada. Aturan yang sama
        // dengan `RoomController::store()`, dan tempatnya di sini supaya
        // kedua jalur tidak bisa berbeda diam-diam.
        //
        // Diperiksa setelah validasi, bukan lewat `lte:capacity`: kolom
        // kapasitas boleh KOSONG, dan `lte` atas kolom kosong membandingkan
        // dengan nol - sehingga ruang tanpa kapasitas tertulis akan menolak
        // setiap PC yang jumlahnya wajar.
        if ($jenis === JenisImporMaster::Ruang
            && ($bersih['capacity'] ?? '') !== ''
            && ($bersih['device_count'] ?? '') !== ''
            && (int) $bersih['device_count'] > (int) $bersih['capacity']) {
            return $this->gagalkanBaris(
                $dasar,
                'pc',
                'Jumlah PC ('.$bersih['device_count'].') melebihi kapasitas ruangnya ('.$bersih['capacity'].').',
            );
        }

        // Sel kosong tidak mengosongkan kolom yang sudah terisi - lihat
        // catatan kepala kelas. Yang benar-benar ingin mengosongkan sebuah
        // kolom melakukannya di layar, tempat ia melihat kolom yang ia
        // kosongkan.
        //
        // `0` sengaja LOLOS di sini: ruang tanpa PC adalah ruang BYOD, dan
        // `array_filter` bawaan membuangnya sebagai falsy - sehingga jumlah PC
        // yang benar-benar nol tidak pernah tersimpan.
        $muatan = array_filter($bersih, fn ($nilai) => $nilai !== null && $nilai !== '');

        $muatan['status'] = $bersih['status'] ?? $jenis->statusBawaan();

        $dasar['sah'] = true;
        $dasar['aksi'] = isset($sudahAda[$kunciBanding]) ? 'diperbarui' : 'baru';
        $dasar['nama'] = $bersih['name'] ?? $kunci;
        $dasar['muatan'] = $muatan;
        $dasar['pesan'] = $dasar['aksi'] === 'diperbarui'
            ? 'akan memperbarui baris yang sudah ada'
            : 'baris baru';

        return $dasar;
    }

    /**
     * Nilai mentah satu baris, siap divalidasi.
     *
     * Angka diambil sebagai STRING apa adanya, bukan di-cast lebih dulu:
     * `(int) 'dua belas'` adalah 0, dan 0 lolos `integer` - sehingga
     * kapasitas yang salah ketik tersimpan sebagai kapasitas nol. Validator
     * yang menerima string numerik dan menolak sisanya.
     *
     * @param  array<int, string>  $sel
     * @return array<string, mixed>
     */
    private function selMentah(JenisImporMaster $jenis, PenjajarKolom $penjajar, array $sel): array
    {
        $ambil = fn (string $kolom) => $penjajar->ambil($sel, $kolom);

        $mentah = [
            'status' => strtolower($ambil('status')),
        ];

        // Kelas tidak punya bidang `code` sama sekali, dan nama kelas ITU
        // sendiri yang menjadi kuncinya. Menyalin `code` ke sini akan
        // memunculkan bidang yang tidak punya aturan - dan baris yang
        // divalidasi atas bidang yang tidak ada adalah baris yang kelak
        // membingungkan orang yang membacanya.
        if ($jenis === JenisImporMaster::Kelas) {
            $mentah['name'] = $ambil('nama');
            $mentah['grade_level'] = $ambil('tingkat');

            return $mentah;
        }

        $mentah['code'] = $ambil('kode');
        $mentah['name'] = $ambil('nama');

        if ($jenis === JenisImporMaster::Mapel) {
            $mentah['category'] = $ambil('kelompok');
            $mentah['grade_level'] = $ambil('tingkat');

            return $mentah;
        }

        $mentah['location'] = $ambil('lokasi');
        $mentah['capacity'] = $ambil('kapasitas');
        $mentah['device_count'] = $ambil('pc');

        return $mentah;
    }

    /**
     * Aturan validasi satu baris, per jenis.
     *
     * Dijalankan lewat `Validator`, bukan `$request->validate()`: yang
     * divalidasi di sini adalah 1.284 baris dari berkas, dan yang harus
     * dilaporkan adalah SELURUH baris yang gagal beserta nomornya - bukan
     * kegagalan pertama yang menghentikan sisanya.
     *
     * `status` sengaja TIDAK `required`: sel kosong berarti "biarkan seperti
     * adanya", dan itu diisi `statusBawaan()` di pemanggilnya.
     *
     * Batas panjangnya datang dari `JenisImporMaster::panjang()`, bukan angka
     * di sini: `classes.name` dibatasi 40 huruf sementara `subjects.name` 255,
     * dan satu angka bersama akan meloloskan nama kelas yang tetap ditolak
     * saat Admin menyuntingnya di layar.
     *
     * @return array<string, array<int, string>>
     */
    private function aturan(JenisImporMaster $jenis): array
    {
        $aturan = [
            'status' => ['nullable', 'string', 'in:'.implode(',', $jenis->statusSah())],
        ];

        if ($jenis === JenisImporMaster::Kelas) {
            return [
                'name' => ['required', 'string', 'max:'.$jenis->panjang('name')],
                'grade_level' => ['nullable', 'string', 'max:'.$jenis->panjang('grade_level')],
            ] + $aturan;
        }

        $aturan['code'] = ['required', 'string', 'max:'.$jenis->panjang('code')];

        if ($jenis === JenisImporMaster::Mapel) {
            return $aturan + [
                'name' => ['required', 'string', 'max:'.$jenis->panjang('name')],
                'category' => ['nullable', 'string', 'max:'.$jenis->panjang('category')],
                'grade_level' => ['nullable', 'string', 'max:'.$jenis->panjang('grade_level')],
            ];
        }

        return $aturan + [
            'name' => ['nullable', 'string', 'max:'.$jenis->panjang('name')],
            'location' => ['nullable', 'string', 'max:'.$jenis->panjang('location')],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'device_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * Kunci yang sudah ada di sekolah ini - pada tahun ajaran yang berlaku.
     *
     * =====================================================================
     * Mengapa hurufnya diturunkan
     * =====================================================================
     * Kolomnya di basis data memakai kolasi `utf8mb4_unicode_ci`, sehingga
     * 'X-1' dan 'x-1' adalah nilai yang SAMA bagi unique index. Peta ini
     * dibandingkan dengan kunci barisnya yang juga diturunkan di
     * `periksaBaris()`, supaya keduanya sepakat. Tanpa itu, berkas yang
     * menulis "x-1" untuk kelas "X-1" akan dianggap baris BARU, lolos
     * pratinjau, lalu pecah pada unique constraint saat Konfirmasi - galat
     * basis data mentah untuk baris yang seharusnya menimpa.
     *
     * =====================================================================
     * Tahun ajaran ikut menyaring, dan itu yang membuat hasilnya benar
     * =====================================================================
     * "X-1" milik 2026/2027 dan "X-1" milik 2027/2028 adalah dua kelas yang
     * berbeda. Tanpa penyaringan ini, impor kelas ke tahun ajaran baru akan
     * melaporkan seluruh barisnya "akan memperbarui baris yang sudah ada" -
     * lalu benar-benar memperbarui kelas tahun LAMA, dan tahun yang dituju
     * tetap kosong.
     *
     * @return array<string, int>
     */
    private function kunciYangAda(
        JenisImporMaster $jenis,
        int $sekolahId,
        ?int $tahunAjaranId,
    ): array {
        $model = $jenis->kelasModel();
        $kolom = $jenis->bidangKunci();

        $query = $model::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolahId);

        if ($jenis->butuhTahunAjaran()) {
            $query->where('academic_year_id', $tahunAjaranId);
        }

        return $query
            ->get(['id', $kolom])
            ->mapWithKeys(fn ($baris) => [
                mb_strtolower(trim((string) $baris->{$kolom})) => (int) $baris->id,
            ])
            ->all();
    }

    /**
     * Satu baris yang gagal, dalam bentuk yang sama dengan baris yang sah.
     *
     * Satu pembangun, bukan tiga tempat yang menyusun array-nya sendiri:
     * bentuk yang berbeda sedikit saja membuat laman pratinjau menampilkan
     * baris kosong - dan Admin yang melihatnya menyimpulkan berkasnya rusak,
     * padahal importernya yang salah membaca.
     *
     * @param  array<string, mixed>  $dasar
     * @return array<string, mixed>
     */
    private function gagalkanBaris(array $dasar, ?string $kolom, string $pesan): array
    {
        $dasar['sah'] = false;
        $dasar['kolom'] = $kolom;
        $dasar['pesan'] = $pesan;

        return $dasar;
    }

    private function pilihPembaca(string $ekstensi): ?PembacaBerkas
    {
        if ($this->xlsx->dukung($ekstensi)) {
            return $this->xlsx;
        }

        if ($this->csv->dukung($ekstensi)) {
            return $this->csv;
        }

        return null;
    }

    private function ekstensi(MasterImportBatch $batch): string
    {
        return strtolower(pathinfo($batch->file_path, PATHINFO_EXTENSION));
    }

    private function folder(JenisImporMaster $jenis, int $schoolId, int $batchId): string
    {
        return 'impor-'.$jenis->value.'/'.$schoolId.'/'.$batchId;
    }

    /**
     * Tandai berkasnya gagal lalu LEMPAR - selalu, tanpa kecuali.
     *
     * Satu method, bukan `tandaiGagal()` yang dipanggil dua-tiga kali: versi
     * pertama `ImporSoalService` melempar hanya di satu cabang, dan tiga
     * cabang lain membuat Guru menerima lencana HIJAU "berkasnya tidak bisa
     * dibaca". Warna lencana adalah seluruh isi pesannya bagi siapa pun yang
     * membacanya sambil lalu.
     *
     * @throws ValidationException
     */
    private function tolakBerkas(MasterImportBatch $batch, string $pesan): never
    {
        $batch->update([
            'status' => StatusImporPengguna::Gagal->value,
            'pratinjau_json' => [
                'baris' => [],
                'sah' => 0,
                'gagal' => 0,
                'baru' => 0,
                'diperbarui' => 0,
                'kolom' => [],
                'pesan' => $pesan,
            ],
        ]);

        $this->logger->log('Berkas impor master data gagal dibaca', $batch, ['pesan' => $pesan]);

        throw ValidationException::withMessages(['berkas' => $pesan]);
    }

    /**
     * Tulis laporan galat `.csv` ke disk privat.
     *
     * Ditaruh di disk yang sama dengan berkasnya: keduanya bahan yang belum
     * boleh dibaca siapa pun yang menebak namanya. Yang bisa mengunduhnya
     * adalah rute berwenang setelah memeriksa kepemilikan barisnya.
     *
     * @param  array<int, array<string, mixed>>  $rincian
     */
    private function tulisLaporan(JenisImporMaster $jenis, MasterImportBatch $batch, array $rincian): string
    {
        $kolomKunci = $jenis->kolomKunci();

        // Laporan diberi judul kolom yang SAMA dengan berkas yang Admin
        // unggah, bukan nama bidang di basis datanya. Untuk kelas, kolom
        // kuncinya memang bernama `nama` - dan kolom "nama" kedua di
        // sebelahnya akan berisi nilai yang persis sama, sehingga yang
        // ditulis hanya satu.
        $adaNamaTerpisah = $kolomKunci !== 'nama';

        $pegangan = fopen('php://temp', 'r+');

        // BOM: tanpa itu Excel berbahasa Indonesia menampilkan huruf beraksen
        // yang rusak di pesannya.
        fwrite($pegangan, "\xEF\xBB\xBF");

        fputcsv(
            $pegangan,
            $adaNamaTerpisah
                ? ['baris', $kolomKunci, 'nama', 'kolom', 'pesan']
                : ['baris', $kolomKunci, 'kolom', 'pesan'],
            ';',
            '"',
            '\\',
        );

        foreach ($rincian as $baris) {
            if ($baris['sah'] ?? false) {
                continue;
            }

            $sel = [
                $baris['nomor'] ?? '',
                $baris['kode'] ?? '',
            ];

            if ($adaNamaTerpisah) {
                $sel[] = $baris['nama'] ?? '';
            }

            $sel[] = $baris['kolom'] ?? '';
            $sel[] = $baris['pesan'] ?? '';

            fputcsv($pegangan, $sel, ';', '"', '\\');
        }

        rewind($pegangan);
        $isi = (string) stream_get_contents($pegangan);
        fclose($pegangan);

        $jalur = $this->folder($jenis, (int) $batch->school_id, (int) $batch->id)
            .'/laporan-galat-'.Str::random(20).'.csv';

        Storage::disk('local')->put($jalur, $isi);

        return $jalur;
    }
}
