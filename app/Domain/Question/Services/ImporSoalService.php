<?php

namespace App\Domain\Question\Services;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Enums\StatusImpor;
use App\Domain\Question\Enums\StatusSoal;
use App\Domain\Question\Import\PenjajarTemplate;
use App\Domain\Question\Models\QuestionImportBatch;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Support\SkemaJenisSoal;
use App\Support\Impor\BerkasImportTidakTerbaca;
use App\Support\Impor\PembacaBerkas;
use App\Support\Impor\PembacaCsv;
use App\Support\Impor\PembacaXlsx;
use App\Support\Impor\PenjajarKolom;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Impor soal bertahap - wireframe plate 04.
 *
 * =====================================================================
 * Tiga langkah, dan hanya langkah KETIGA yang menyimpan soal
 * =====================================================================
 *   1. UNGGAH     berkas ke disk privat, hitung hash, status `menunggu`.
 *   2. PRATINJAU  urai, validasi SETIAP baris, simpan hasilnya, status
 *                 `dipratinjau`. Tidak ada satu pun baris `questions` yang
 *                 ditulis di langkah ini.
 *   3. KONFIRMASI simpan baris yang SAH saja, tulis laporan galat, status
 *                 `dikonfirmasi`.
 *
 * Pemisahan itu bukan kerapian prosedural. Guru yang mengimpor 50 soal
 * menyusun berkasnya di Excel, dan berkas pertama SELALU bermasalah di
 * beberapa baris. Tanpa langkah tengah, pilihannya hanya dua: menolak seluruh
 * berkas (dan 48 soal baik ikut hilang), atau menyimpannya diam-diam (dan
 * bank soal terisi soal yang kuncinya kosong). Pratinjau adalah jalan ketiga,
 * dan ia sekaligus menjawab kalimat blueprint: "Guru yang mengimpor 50 soal
 * harus tahu baris mana yang perlu diperbaiki - bukan menerima pesan 'impor
 * gagal' tanpa alamat."
 *
 * =====================================================================
 * Hasil pratinjau DISIMPAN, termasuk muatannya
 * =====================================================================
 * `pratinjau_json` menyimpan dua hal: daftar baris beserta nomor dan
 * pesannya - yang ditampilkan ke Guru - dan muatan soal tiap baris yang sah.
 *
 * Yang kedua yang membuat "konfirmasi" berarti "menyimpan apa yang tadi
 * dilihat" alih-alih "mengurai ulang berkasnya". Bedanya bukan teoretis:
 * berkasnya bisa sudah dihapus, dan `PembersihHtml` atau `SkemaJenisSoal`
 * bisa berubah di antara dua permintaan - sehingga Guru menekan tombol
 * Konfirmasi dan menyimpan 48 soal yang sudah lewat aturan yang berbeda dari
 * yang ia lihat di layar.
 *
 * =====================================================================
 * Baris yang gagal TIDAK menggagalkan berkasnya
 * =====================================================================
 * Berkas berisi 50 baris dengan 2 baris rusak tetap menjadi impor yang
 * berhasil 48 baris, berstatus `dikonfirmasi` - bukan `gagal`. `gagal`
 * disediakan untuk berkas yang tidak bisa dibaca sama sekali: bukan zip,
 * tanpa lembar, tanpa kolom wajib, melebihi batas. Mencampur keduanya berarti
 * berkas yang 96% berhasil dilaporkan sebagai kegagalan, dan Guru akan
 * mengunggah ulang lalu menggandakan 48 soal yang sudah masuk.
 *
 * =====================================================================
 * Konvensi kolom yang TIDAK ada di template
 * =====================================================================
 * Template impor adalah tabel datar empat belas kolom: kode, judul, jenis,
 * tingkat, bobot, soal, opsi_a..opsi_e, kunci, rubrik, pembahasan. Sepuluh
 * jenis soal harus muat ke dalamnya, dan lima di antaranya butuh kesepakatan
 * yang tidak bisa ditebak dari nama kolomnya:
 *
 *   PILIHAN GANDA / GANDA   `kunci` berisi huruf: "a", atau "a, c".
 *   BENAR/SALAH             `kunci` berisi b/s - "benar"/"salah" diterima.
 *   ISIAN SINGKAT           `kunci` berisi varian, dipisah "|": "7|tujuh".
 *   ISIAN RUMPANG           `kunci` berisi jawaban setiap lubang, dipisah
 *                           "|", dan varian dalam satu lubang dipisah "~":
 *                           "gravitasi~gaya gravitasi|newton~N" = dua lubang.
 *                           Teks soalnya wajib memuat {{1}}..{{n}} sebanyak
 *                           lubangnya; yang tidak cocok ditolak.
 *   ANGKA                   `kunci` berisi bilangannya: "3.14".
 *   MENJODOHKAN             `opsi_a..opsi_e` adalah item KIRI, dan `kunci`
 *                           berisi pasangannya: "a=Jakarta; b=Bandung".
 *                           Item kanan dibangkitkan dari sini, dan item kiri
 *                           yang tidak muncul di kolom `kunci` dilaporkan
 *                           sebagai "pasangan kurang" - tepat barisnya.
 *   MENGURUTKAN             `opsi_a..opsi_e` adalah itemnya, dan `kunci`
 *                           berisi urutan benarnya: "c, a, b".
 *   URAIAN                  `rubrik` berisi "Kriteria:5 | Kriteria lain:3".
 *
 * Kesepakatan ini ditulis di template unduhan sebagai baris contoh, bukan di
 * dokumen terpisah: Guru membuka templatenya, bukan manualnya.
 *
 * =====================================================================
 * MEDIA tidak bisa diimpor, dan itu disebut apa adanya
 * =====================================================================
 * Soal media membutuhkan berkas - gambar, audio, video - dan berkas tidak
 * bisa dititipkan ke dalam sel Excel. Baris berjenis media ditolak dengan
 * pesan yang menyebutkan bahwa berkasnya harus diunggah lewat builder, bukan
 * dengan galat skema yang berbunyi "mekanisme tidak dikenal". Guru yang
 * membaca pesan pertama tahu apa yang harus dilakukan; yang membaca pesan
 * kedua akan mengira jenisnya salah tulis.
 */
class ImporSoalService
{
    public function __construct(
        private readonly PembacaXlsx $xlsx,
        private readonly PembacaCsv $csv,
        private readonly SkemaJenisSoal $skema,
        private readonly BankSoalService $bankSoal,
        private readonly PaketSoalService $paketSoal,
        private readonly ActivityLogger $logger,
    ) {}

    /* ===================================================== 1. Unggah */

    /**
     * Terima berkasnya, simpan ke disk privat, dan catat barisnya.
     *
     * Berkas di disk diberi nama acak `Str::random(40)`, dan nama aslinya
     * disimpan di kolomnya sendiri. Alasannya sama dengan berita acara Fase 6:
     * berkas impor memuat SELURUH soal satu mapel - bahan yang belum boleh
     * dibaca siapa pun yang menebak namanya - sehingga nama di disk tidak
     * boleh berarti apa-apa.
     *
     * @throws ValidationException
     */
    public function unggah(
        UploadedFile $berkas,
        int $schoolId,
        int $guruId,
        ?int $mapelId,
        ?int $paketId = null,
    ): QuestionImportBatch {
        $ekstensi = strtolower($berkas->getClientOriginalExtension() ?: $berkas->extension() ?: '');
        $pembaca = $this->pilihPembaca($ekstensi);

        if ($pembaca === null) {
            throw ValidationException::withMessages([
                'berkas' => 'Berkas ".'.$ekstensi.'" tidak didukung. Unggah berkas .xlsx atau .csv - template impor bisa diunduh dari laman ini.',
            ]);
        }

        $isi = (string) file_get_contents($berkas->getRealPath());

        // Panjang isinya diperiksa di sini juga, sebelum ditulis: pemeriksaan
        // yang sama ada di dalam pembacanya, tetapi menulis 30 MB ke disk
        // lebih dulu lalu menolaknya adalah penolakan yang sudah terlanjur
        // mahal.
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

        // Mengunggah ulang berkas yang sama adalah DUA KALI impor yang sama.
        // Tanpa penjagaan ini gejalanya bukan galat melainkan bank soal yang
        // berisi dua salinan setiap soal - baru ketahuan berbulan kemudian,
        // dan membereskannya berarti membandingkan isi soal satu per satu.
        $sudahAda = QuestionImportBatch::query()
            ->where('teacher_id', $guruId)
            ->where('berkas_hash', $hash)
            ->first();

        if ($sudahAda !== null) {
            throw ValidationException::withMessages([
                'berkas' => 'Berkas ini sudah pernah Anda unggah ('.$sudahAda->nama_asli.', '.
                    $sudahAda->statusImpor()->label().'). Berkas yang sama tidak diimpor dua kali - '.
                    'perbaiki berkasnya lebih dulu, atau lanjutkan impor yang sudah ada.',
            ]);
        }

        return DB::transaction(function () use ($berkas, $isi, $hash, $schoolId, $guruId, $mapelId, $paketId, $ekstensi) {
            $batch = QuestionImportBatch::create([
                'school_id' => $schoolId,
                // Mapel ditanyakan saat mengunggah, bukan disimpulkan dari
                // paket tujuan: paket bersifat opsional ("Impor ke bank saja"),
                // dan soal tanpa mapel tidak bisa dilihat Guru mana pun.
                'subject_id' => $mapelId,
                'teacher_id' => $guruId,
                'question_package_id' => $paketId,
                // Diisi di bawah, setelah id barisnya ada: jalurnya memuat
                // id batch, dan kolomnya NOT NULL sehingga tidak bisa
                // dikosongkan lalu diisi. Satu UPDATE, dan tidak ada berkas
                // yang ditulis ke jalur yang tidak tercatat.
                'file_path' => '',
                'nama_asli' => $berkas->getClientOriginalName(),
                'berkas_hash' => $hash,
                'status' => StatusImpor::Menunggu->value,
                'total_rows' => 0,
                'success_rows' => 0,
                'failed_rows' => 0,
            ]);

            $jalur = $this->folder($schoolId, (int) $batch->id).'/'.Str::random(40).'.'.($ekstensi ?: 'xlsx');

            Storage::disk('local')->put($jalur, $isi);
            $batch->update(['file_path' => $jalur]);

            $this->logger->log('Mengunggah berkas impor soal', $batch, [
                'nama_asli' => $batch->nama_asli,
                'baris' => 0,
            ]);

            return $batch;
        });
    }

    /* ================================================== 2. Pratinjau */

    /**
     * Urai berkasnya dan periksa setiap barisnya - tanpa menyimpan satu soal.
     *
     * @throws ValidationException
     */
    public function pratinjau(QuestionImportBatch $batch): QuestionImportBatch
    {
        if ($batch->statusImpor() === StatusImpor::Dikonfirmasi) {
            throw ValidationException::withMessages([
                'berkas' => 'Berkas ini sudah diimpor. Menguraikannya ulang akan menyimpan soal yang sama untuk kedua kalinya.',
            ]);
        }

        $pembaca = $this->pilihPembaca($this->ekstensi($batch));

        if ($pembaca === null) {
            $this->tolakBerkas($batch, 'Jenis berkasnya tidak dikenali. Unggah berkas .xlsx atau .csv.');
        }

        try {
            $baris = $pembaca->baca(Storage::disk('local')->path($batch->file_path));
        } catch (BerkasImportTidakTerbaca $e) {
            // Pesan kelas itu ditulis untuk GURU, dan ditampilkan apa adanya.
            // Barisnya tetap tercatat `gagal` sebelum dilempar - sehingga
            // daftar riwayat impor tidak menampilkan berkas yang tampak
            // belum diproses padahal Guru sudah menerima pesannya.
            $this->tolakBerkas($batch, $e->getMessage());
        }

        $judul = PenjajarKolom::temukan($baris, PenjajarTemplate::class);

        if ($judul === null) {
            $this->tolakBerkas($batch, 'Berkasnya tidak berisi satu baris pun. Isi dulu soalnya, lalu unggah ulang.');
        }

        [$noJudul, $selJudul] = $judul;
        $penjajar = new PenjajarTemplate($selJudul, $noJudul);

        if (! $penjajar->sah()) {
            $this->tolakBerkas($batch, $penjajar->pesanKolomHilang());
        }

        $rincian = [];
        $sah = 0;
        $gagal = 0;
        $nomorSoal = 0;

        foreach ($baris as $nomor => $sel) {
            // Baris judulnya dilewati, dan baris yang benar-benar kosong juga.
            // Baris kosong TIDAK dilaporkan sebagai galat: Guru yang
            // mengosongkan baris 2 saat menghapus satu soal bermasalah tidak
            // melakukan kesalahan apa pun.
            if ($nomor <= $noJudul || PenjajarKolom::kosong($sel)) {
                continue;
            }

            $nomorSoal++;
            $hasil = $this->periksaBaris($penjajar, $sel, $nomor, (int) $batch->subject_id);

            if ($hasil['sah']) {
                $sah++;
            } else {
                $gagal++;
            }

            $rincian[] = $hasil;
        }

        if ($rincian === []) {
            $this->tolakBerkas($batch, 'Berkasnya hanya berisi baris judul kolom. Isi dulu baris soalnya, lalu unggah ulang.');
        }

        $batch->update([
            'status' => StatusImpor::Dipratinjau->value,
            'pratinjau_json' => [
                'baris' => $rincian,
                'sah' => $sah,
                'gagal' => $gagal,
                'kolom' => $penjajar->namaKolomTerbaca(),
                'baris_judul' => $noJudul,
            ],
            'total_rows' => $sah + $gagal,
            'success_rows' => 0,
            'failed_rows' => 0,
            'imported_at' => null,
        ]);

        $this->logger->log('Mempratinjau berkas impor soal', $batch, [
            'sah' => $sah,
            'gagal' => $gagal,
        ]);

        return $batch->fresh();
    }

    /* ================================================= 3. Konfirmasi */

    /**
     * Simpan baris yang sah saja.
     *
     * @param  bool  $kePaket  Masukkan butirnya ke paket tujuan dalam
     *                         transaksi yang sama.
     *
     * @throws ValidationException
     */
    public function konfirmasi(QuestionImportBatch $batch, bool $kePaket = true): QuestionImportBatch
    {
        if (! $batch->statusImpor()->bolehDikonfirmasi()) {
            throw ValidationException::withMessages([
                'berkas' => $batch->statusImpor() === StatusImpor::Dikonfirmasi
                    ? 'Berkas ini sudah diimpor. Mengimpor ulang akan menggandakan soalnya.'
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

        $tersimpan = $this->simpanBaris($batch, $rincian, $kePaket);

        $gagal = (int) ($pratinjau['gagal'] ?? 0);
        $laporan = $gagal > 0 ? $this->tulisLaporan($batch, $rincian) : null;

        $batch->update([
            'status' => StatusImpor::Dikonfirmasi->value,
            'success_rows' => $tersimpan,
            'failed_rows' => $gagal,
            'total_rows' => $tersimpan + $gagal,
            'error_report_path' => $laporan,
            'imported_at' => now(),
        ]);

        $this->logger->log('Mengimpor soal dari berkas', $batch, [
            'tersimpan' => $tersimpan,
            'gagal' => $gagal,
            'ke_paket' => $kePaket && $batch->question_package_id !== null,
        ]);

        return $batch->fresh();
    }

    /* ------------------------------------------------------- Mekanisme */

    /**
     * Simpan seluruh baris sah, beserta pemasangan ke paketnya.
     *
     * Ketiganya dalam SATU transaksi: soal yang tersimpan tetapi gagal masuk
     * ke paketnya berarti Guru melihat "48 soal diimpor" lalu menemukan
     * paketnya kosong - dan tidak ada cara mengetahui 48 mana yang jadi.
     *
     * @param  array<int, array<string, mixed>>  $rincian
     * @return int Berapa soal yang benar-benar tersimpan.
     */
    private function simpanBaris(QuestionImportBatch $batch, array $rincian, bool $kePaket): int
    {
        return DB::transaction(function () use ($batch, $rincian, $kePaket) {
            $ids = [];

            foreach ($rincian as $baris) {
                if (! ($baris['sah'] ?? false) || empty($baris['muatan'])) {
                    continue;
                }

                $soal = $this->bankSoal->buat(
                    $baris['muatan'],
                    (int) $batch->school_id,
                    (int) $batch->teacher_id,
                );

                $ids[] = (int) $soal->id;
            }

            // Paketnya diambil tanpa scope sekolah karena barisnya sudah
            // ter-scope lewat batch; yang diperiksa di sini adalah sekolahnya
            // benar-benar sama, dan `tambahButir` menolak soal dari mapel lain.
            if ($kePaket && $batch->question_package_id !== null && $ids !== []) {
                $paket = QuestionPackage::query()
                    ->where('id', $batch->question_package_id)
                    ->where('school_id', $batch->school_id)
                    ->first();

                if ($paket === null) {
                    throw ValidationException::withMessages([
                        'question_package_id' => 'Paket tujuan tidak ditemukan. Impor tanpa paket, atau pilih paketnya lagi.',
                    ]);
                }

                $this->paketSoal->tambahButir($paket, $ids);
            }

            return count($ids);
        });
    }

    /**
     * Periksa satu baris, kembalikan hasilnya - sah atau tidak.
     *
     * Mapelnya diterima dari batch, bukan dibaca dari barisnya: satu berkas
     * impor berisi soal SATU mapel, dan itulah yang membuat `subject_id`
     * tercatat di barisnya alih-alih ditebak dari paket tujuannya.
     *
     * @param  array<int, string>  $sel
     * @return array<string, mixed>
     */
    private function periksaBaris(PenjajarTemplate $penjajar, array $sel, int $nomor, int $mapelId): array
    {
        $judul = $penjajar->ambil($sel, 'judul');
        $jenisMentah = $penjajar->ambil($sel, 'jenis');
        $soal = $penjajar->ambil($sel, 'soal');
        $kunciMentah = $penjajar->ambil($sel, 'kunci');
        $bobotMentah = $penjajar->ambil($sel, 'bobot');
        $rubrikMentah = $penjajar->ambil($sel, 'rubrik');

        $dasar = [
            'nomor' => $nomor,
            'jenis' => null,
            'jenis_pendek' => '—',
            'ringkas' => $this->ringkas($soal, $judul),
            'sah' => false,
            'kolom' => null,
            'pesan' => null,
            'muatan' => null,
        ];

        if ($judul === '') {
            return array_merge($dasar, ['kolom' => 'judul', 'pesan' => 'Kolom judul kosong. Isi nama singkat soal supaya ia bisa dicari di bank soal.']);
        }

        if ($soal === '') {
            return array_merge($dasar, ['kolom' => 'soal', 'pesan' => 'Kolom teks soal kosong.']);
        }

        if ($jenisMentah === '') {
            return array_merge($dasar, ['kolom' => 'jenis', 'pesan' => 'Kolom jenis kosong. Isi salah satu dari: '.$this->daftarJenis().'.']);
        }

        $jenis = $this->jenisDari($jenisMentah);

        if ($jenis === null) {
            return array_merge($dasar, ['kolom' => 'jenis', 'pesan' => 'Jenis soal "'.$jenisMentah.'" tidak dikenal. Isi salah satu dari: '.$this->daftarJenis().'.']);
        }

        $dasar['jenis'] = $jenis->value;
        $dasar['jenis_pendek'] = $jenis->pendek();

        // Media ditolak dengan sebabnya, bukan dengan galat skema. Berkasnya
        // tidak bisa dititipkan ke dalam sel Excel, dan Guru yang membaca
        // "mekanisme tidak dikenal" akan mengira ia salah menulis jenisnya.
        if ($jenis === JenisSoal::Media) {
            return array_merge($dasar, ['kolom' => 'jenis', 'pesan' => 'Soal media membutuhkan berkas, dan berkas tidak bisa diimpor dari Excel. Susun soalnya lewat builder soal.']);
        }

        $bobot = 1.0;

        if ($bobotMentah !== '') {
            // Koma desimal diterima: berkas yang disusun di Excel berbahasa
            // Indonesia menulis "2,5", dan menolaknya sebagai "bukan angka"
            // adalah galat yang menunjuk ke berkas yang sebenarnya benar.
            $angka = str_replace(',', '.', $bobotMentah);

            if (! is_numeric($angka)) {
                return array_merge($dasar, ['kolom' => 'bobot', 'pesan' => 'Bobot "'.$bobotMentah.'" bukan angka.']);
            }

            $bobot = (float) $angka;
        }

        $muatan = [
            'subject_id' => $mapelId,
            'code' => null,
            'title' => $judul,
            'question_type' => $jenis->value,
            'difficulty' => $this->tingkatDari($penjajar->ambil($sel, 'tingkat')),
            'status' => StatusSoal::Siap->value,
            'prompt_json' => ['teks' => $this->teks($soal)],
            'scoring_config_json' => ['bobot' => $bobot],
            'explanation_json' => $penjajar->ambil($sel, 'pembahasan') !== ''
                ? ['teks' => $this->teks($penjajar->ambil($sel, 'pembahasan'))]
                : null,
            'answer_key_json' => null,
            'opsi' => [],
            'butir' => [],
            'rubrik' => [],
        ];

        $bentuk = $this->bentukKunci($penjajar, $sel, $jenis, $kunciMentah, $rubrikMentah);

        if ($bentuk['galat'] !== null) {
            return array_merge($dasar, ['kolom' => $bentuk['kolom'], 'pesan' => $bentuk['galat']]);
        }

        $muatan['answer_key_json'] = $bentuk['kunci'];
        $muatan['opsi'] = $bentuk['opsi'];
        $muatan['butir'] = $bentuk['butir'];
        $muatan['rubrik'] = $bentuk['rubrik'];

        try {
            $this->skema->validasi(
                $jenis,
                $muatan['answer_key_json'],
                $bobot,
                $muatan['opsi'],
                $muatan['butir'],
                $muatan['rubrik'],
                $muatan['scoring_config_json'],
                // Penanda rumpang diperiksa juga di jalur impor: baris Excel
                // dengan empat `{{n}}` tetapi dua kunci harus ditolak di
                // pratinjau, bukan tersimpan lalu dinilai salah selamanya.
                $muatan['prompt_json']['teks'] ?? null,
            );
        } catch (ValidationException $e) {
            [$kolom, $pesan] = $this->galatPertama($e);

            return array_merge($dasar, ['kolom' => $kolom, 'pesan' => $pesan]);
        }

        return array_merge($dasar, [
            'sah' => true,
            'kolom' => null,
            'pesan' => 'Siap disimpan.',
            'muatan' => $muatan,
        ]);
    }

    /**
     * Terjemahkan kolom template menjadi kunci, opsi, butir, dan rubrik.
     *
     * Kolom `kunci` berarti sesuatu yang berbeda di setiap jenis, dan seluruh
     * kesepakatan itu ditulis di kepala kelas ini.
     *
     * @param  array<int, string>  $sel
     * @return array{galat: ?string, kolom: ?string, kunci: ?array<string, mixed>, opsi: array<int, array<string, mixed>>, butir: array<int, array<string, mixed>>, rubrik: array<int, array<string, mixed>>}
     */
    private function bentukKunci(
        PenjajarTemplate $penjajar,
        array $sel,
        JenisSoal $jenis,
        string $kunciMentah,
        string $rubrikMentah,
    ): array {
        $kosong = ['galat' => null, 'kolom' => null, 'kunci' => null, 'opsi' => [], 'butir' => [], 'rubrik' => []];

        $opsiTeks = [];

        foreach (['a', 'b', 'c', 'd', 'e'] as $huruf) {
            $isi = $penjajar->ambil($sel, 'opsi_'.$huruf);

            if ($isi !== '') {
                $opsiTeks[$huruf] = $isi;
            }
        }

        $butir = [];
        $rubrik = [];

        switch ($jenis) {
            case JenisSoal::PilihanGanda:
            case JenisSoal::JawabanGanda:
                if ($opsiTeks === []) {
                    return array_merge($kosong, ['galat' => 'Kolom pilihan jawaban kosong - soal '.$jenis->label().' butuh sekurang-kurangnya dua pilihan di opsi_a dan seterusnya.', 'kolom' => 'opsi_a']);
                }

                $dipilih = $this->pisah($kunciMentah, ['/', ',', ';', ' ']);

                if ($dipilih === []) {
                    return array_merge($kosong, ['galat' => 'Kolom kunci kosong. Isi huruf jawaban benarnya, misalnya "a" atau "a, c".', 'kolom' => 'kunci']);
                }

                foreach ($dipilih as $huruf) {
                    if (! array_key_exists(strtolower($huruf), $opsiTeks)) {
                        return array_merge($kosong, ['galat' => 'Kunci "'.$huruf.'" tidak punya pilihan jawabannya di kolom opsi_'.$huruf.'.', 'kolom' => 'kunci']);
                    }
                }

                $kunci = ['opsi' => array_map('strtolower', $dipilih)];

                return array_merge($kosong, ['kunci' => $kunci, 'opsi' => $this->opsiDari($opsiTeks)]);

            case JenisSoal::BenarSalah:
                $huruf = strtolower(trim($kunciMentah));

                $huruf = match ($huruf) {
                    'benar', 'b', 'true', 'ya' => 'b',
                    'salah', 's', 'false', 'tidak' => 's',
                    default => $huruf,
                };

                if (! in_array($huruf, SkemaJenisSoal::HURUF_BENAR_SALAH, true)) {
                    return array_merge($kosong, ['galat' => 'Kunci benar/salah harus "b" (benar) atau "s" (salah).', 'kolom' => 'kunci']);
                }

                // Dua pilihan dibangkitkan sendiri untuk jenis ini: Guru yang
                // harus menuliskan "Benar" dan "Salah" di opsi_a/opsi_b akan
                // menuliskannya berbeda-beda di setiap baris, dan layarnya
                // tidak pernah memakai teks itu.
                $opsi = [
                    ['option_key' => 'b', 'content_json' => ['teks' => 'Benar'], 'position' => 0],
                    ['option_key' => 's', 'content_json' => ['teks' => 'Salah'], 'position' => 1],
                ];

                return array_merge($kosong, ['kunci' => ['opsi' => [$huruf]], 'opsi' => $opsi]);

            case JenisSoal::IsianSingkat:
                $varian = $this->pisah($kunciMentah, ['|', ';']);

                if ($varian === []) {
                    return array_merge($kosong, ['galat' => 'Kolom kunci kosong. Isi jawaban yang diterima; beberapa varian dipisah "|", misalnya "7|tujuh".', 'kolom' => 'kunci']);
                }

                return array_merge($kosong, ['kunci' => ['teks' => $varian]]);

            case JenisSoal::IsianRumpang:
                // Dua pemisah, dua tingkat: `|` memisahkan LUBANG, `~`
                // memisahkan varian di dalam satu lubang.
                // "gravitasi~gaya gravitasi|newton~N" = dua lubang.
                $lubang = $this->pisah($kunciMentah, ['|']);

                if ($lubang === []) {
                    return array_merge($kosong, [
                        'galat' => 'Kolom kunci kosong. Isi jawaban setiap rumpang dan pisahkan lubangnya dengan "|", misalnya "gravitasi|newton". Varian dalam satu lubang dipisah "~".',
                        'kolom' => 'kunci',
                    ]);
                }

                $daftar = [];

                foreach ($lubang as $nomor => $satu) {
                    $varian = array_map('trim', explode('~', $satu));
                    $varian = array_values(array_filter($varian, fn (string $s) => $s !== ''));

                    if ($varian === []) {
                        return array_merge($kosong, [
                            'galat' => 'Rumpang ke-'.($nomor + 1).' belum punya jawaban. Setiap lubang wajib punya sekurang-kurangnya satu varian.',
                            'kolom' => 'kunci',
                        ]);
                    }

                    $daftar[] = $varian;
                }

                return array_merge($kosong, ['kunci' => ['rumpang' => $daftar]]);

            case JenisSoal::Angka:
                $angka = str_replace(',', '.', trim($kunciMentah));

                if (! is_numeric($angka)) {
                    return array_merge($kosong, ['galat' => 'Kunci soal angka harus berupa bilangan, misalnya "3.14".', 'kolom' => 'kunci']);
                }

                return array_merge($kosong, ['kunci' => ['nilai' => (float) $angka, 'toleransi' => 0]]);

            case JenisSoal::Menjodohkan:
                if ($opsiTeks === []) {
                    return array_merge($kosong, ['galat' => 'Soal menjodohkan butuh item kirinya di kolom opsi_a dan seterusnya.', 'kolom' => 'opsi_a']);
                }

                foreach ($opsiTeks as $huruf => $isi) {
                    $butir[] = [
                        'item_key' => $huruf,
                        'item_group' => 'kiri',
                        'content_json' => ['teks' => $this->teks($isi)],
                        'correct_match_key' => null,
                        'position' => count($butir),
                    ];
                }

                $pasangan = [];

                foreach ($this->pisah($kunciMentah, [';', '|']) as $satu) {
                    $bagian = explode('=', $satu, 2);

                    if (count($bagian) !== 2) {
                        return array_merge($kosong, ['galat' => 'Pasangan "'.$satu.'" tidak berbentuk "kiri=kanan". Contoh: "a=Jakarta; b=Bandung".', 'kolom' => 'kunci']);
                    }

                    $kiri = strtolower(trim($bagian[0]));
                    $kanan = trim($bagian[1]);

                    if (! array_key_exists($kiri, $opsiTeks)) {
                        return array_merge($kosong, ['galat' => 'Pasangan menunjuk item kiri "'.$kiri.'" yang tidak ada di kolom opsi_'.$kiri.'.', 'kolom' => 'kunci']);
                    }

                    if ($kanan === '') {
                        return array_merge($kosong, ['galat' => 'Pasangan "'.$kiri.'" belum punya item kanannya.', 'kolom' => 'kunci']);
                    }

                    $kunciKanan = 'p-'.$kiri;

                    $pasangan[$kiri] = $kunciKanan;

                    $butir[] = [
                        'item_key' => $kunciKanan,
                        'item_group' => 'kanan',
                        'content_json' => ['teks' => $this->teks($kanan)],
                        'correct_match_key' => null,
                        'position' => count($butir),
                    ];
                }

                // Item kiri yang tidak muncul di kolom kunci adalah kegagalan
                // yang paling mahal di jenis ini - dan yang paling sering
                // terjadi: Guru menambah satu item kiri lalu lupa menambah
                // pasangannya. Ia dilaporkan di sini, tepat barisnya.
                foreach (array_keys($opsiTeks) as $huruf) {
                    if (! array_key_exists($huruf, $pasangan)) {
                        return array_merge($kosong, [
                            'galat' => 'Item kiri "'.$huruf.'" belum punya pasangan. Pasangan ditulis di kolom kunci, misalnya "a=Jakarta; b=Bandung".',
                            'kolom' => 'kunci',
                        ]);
                    }
                }

                return array_merge($kosong, ['kunci' => ['pasangan' => $pasangan], 'butir' => $butir]);

            case JenisSoal::Mengurutkan:
                if ($opsiTeks === []) {
                    return array_merge($kosong, ['galat' => 'Soal mengurutkan butuh itemnya di kolom opsi_a dan seterusnya.', 'kolom' => 'opsi_a']);
                }

                foreach ($opsiTeks as $huruf => $isi) {
                    $butir[] = [
                        'item_key' => $huruf,
                        'item_group' => 'kiri',
                        'content_json' => ['teks' => $this->teks($isi)],
                        'correct_position' => null,
                        'position' => count($butir),
                    ];
                }

                $urutan = array_map('strtolower', $this->pisah($kunciMentah, ['>', ',', ';', '|']));

                if ($urutan === []) {
                    return array_merge($kosong, ['galat' => 'Kolom kunci kosong. Isi urutan benarnya, misalnya "c, a, b".', 'kolom' => 'kunci']);
                }

                if (count($urutan) !== count($butir)) {
                    return array_merge($kosong, [
                        'galat' => 'Urutan benar memuat '.count($urutan).' item, sedangkan soalnya punya '.count($butir).'. Seluruh item harus muncul tepat sekali.',
                        'kolom' => 'kunci',
                    ]);
                }

                foreach ($urutan as $huruf) {
                    if (! array_key_exists($huruf, $opsiTeks)) {
                        return array_merge($kosong, ['galat' => 'Urutan menunjuk item "'.$huruf.'" yang tidak ada di kolom opsi_'.$huruf.'.', 'kolom' => 'kunci']);
                    }
                }

                return array_merge($kosong, ['kunci' => ['urutan' => $urutan], 'butir' => $butir]);

            case JenisSoal::Uraian:
                $rubrik = $this->rubrikDari($rubrikMentah);

                if ($rubrik === []) {
                    return array_merge($kosong, [
                        'galat' => 'Soal uraian butuh rubrik. Tulis kriterianya di kolom rubrik, misalnya "Langkah benar:4 | Hasil akhir:3".',
                        'kolom' => 'rubrik',
                    ]);
                }

                return array_merge($kosong, ['kunci' => null, 'rubrik' => $rubrik]);

            default:
                return array_merge($kosong, ['galat' => 'Jenis soal ini belum bisa diimpor dari berkas.', 'kolom' => 'jenis']);
        }
    }

    /**
     * Rubrik dari kolomnya: "Kriteria:4 | Kriteria lain:3".
     *
     * Titik dua dipakai sebagai pemisah nama dan skornya, dan koma tidak -
     * nama kriteria berbahasa Indonesia memuat koma jauh lebih sering
     * daripada memuat titik dua.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rubrikDari(string $mentah): array
    {
        $hasil = [];

        foreach ($this->pisah($mentah, ['|', ';']) as $satu) {
            $bagian = explode(':', $satu, 2);

            if (count($bagian) !== 2 || ! is_numeric(trim($bagian[1]))) {
                continue;
            }

            $nama = trim($bagian[0]);

            if ($nama === '') {
                continue;
            }

            $hasil[] = [
                'criterion' => $nama,
                'description' => null,
                'max_score' => (float) trim($bagian[1]),
                'position' => count($hasil),
            ];
        }

        return $hasil;
    }

    /**
     * @param  array<string, string>  $opsiTeks
     * @return array<int, array<string, mixed>>
     */
    private function opsiDari(array $opsiTeks): array
    {
        $hasil = [];

        foreach ($opsiTeks as $huruf => $teks) {
            $hasil[] = [
                'option_key' => $huruf,
                'content_json' => ['teks' => $this->teks($teks)],
                'position' => count($hasil),
            ];
        }

        return $hasil;
    }

    /**
     * Pemecah daftar yang menerima seluruh pemisah yang masuk akal.
     *
     * @param  array<int, string>  $pemisah
     * @return array<int, string>
     */
    private function pisah(string $mentah, array $pemisah): array
    {
        $mentah = trim($mentah);

        if ($mentah === '') {
            return [];
        }

        $hasil = array_map('trim', preg_split('/['.preg_quote(implode('', $pemisah), '/').']+/u', $mentah) ?: []);

        return array_values(array_filter($hasil, fn (string $s) => $s !== ''));
    }

    /**
     * Teks sel menjadi blok teks soal.
     *
     * Baris baru di dalam sel disimpan sebagai `<br>`: soal Matematika hampir
     * selalu punya beberapa baris, dan meratakannya menjadi satu paragraf
     * panjang mengubah soalnya. Seluruh teksnya tetap melewati PembersihHtml
     * sebelum disimpan - Guru boleh menempelkan tabel dari sumber lain.
     */
    private function teks(string $mentah): string
    {
        return nl2br(htmlspecialchars(trim($mentah), ENT_QUOTES, 'UTF-8'), false);
    }

    /** Tingkat kesulitan dari kolomnya, dengan sinonim yang masuk akal. */
    private function tingkatDari(string $mentah): string
    {
        return match (strtolower(trim($mentah))) {
            'mudah', 'gampang', 'rendah' => 'mudah',
            'sulit', 'sukar', 'tinggi' => 'sulit',
            default => 'sedang',
        };
    }

    /**
     * Jenis soal dari kolomnya.
     *
     * Menerima nilai enumnya (`pilihan_ganda`), labelnya ("Pilihan ganda"),
     * singkatan yang dipakai di tabel ("PG"), dan kepanjangan bahasa
     * Inggrisnya - Guru menulis apa yang ia ingat, dan yang menolaknya berarti
     * mengajari Guru sebuah kosakata yang tidak ia pakai.
     */
    private function jenisDari(string $mentah): ?JenisSoal
    {
        $kunci = strtolower(trim($mentah));
        $kunci = str_replace([' ', '-', '/', '.'], '_', $kunci);

        $sinonim = [
            'pg' => 'pilihan_ganda',
            'pilihanganda' => 'pilihan_ganda',
            'multiple_choice' => 'pilihan_ganda',
            'ganda' => 'jawaban_ganda',
            'jawaban_ganda_kompleks' => 'jawaban_ganda',
            'benar_salah' => 'benar_salah',
            'b_s' => 'benar_salah',
            'b_s_' => 'benar_salah',
            'true_false' => 'benar_salah',
            'isian' => 'isian_singkat',
            'isian_singkat' => 'isian_singkat',
            'short_answer' => 'isian_singkat',
            'rumpang' => 'isian_rumpang',
            'isian_rumpang' => 'isian_rumpang',
            'fill_in_the_blank' => 'isian_rumpang',
            'fill_in_blank' => 'isian_rumpang',
            'cloze' => 'isian_rumpang',
            'jodoh' => 'menjodohkan',
            'jodohkan' => 'menjodohkan',
            'matching' => 'menjodohkan',
            'urut' => 'mengurutkan',
            'ordering' => 'mengurutkan',
            'angka' => 'angka',
            'numeric' => 'angka',
            'uraian' => 'uraian',
            'essay' => 'uraian',
            'esai' => 'uraian',
            'media' => 'media',
        ];

        $nilai = $sinonim[$kunci] ?? $kunci;

        return JenisSoal::tryFrom($nilai);
    }

    private function daftarJenis(): string
    {
        return implode(', ', array_map(
            fn (JenisSoal $j) => $j->value.' ('.$j->pendek().')',
            JenisSoal::cases(),
        ));
    }

    /** Cuplikan teks soal untuk daftar pratinjau. */
    private function ringkas(string $soal, string $judul): string
    {
        $teks = trim(strip_tags(str_replace('<br>', ' ', $soal)));

        if ($teks === '') {
            $teks = $judul;
        }

        if ($teks === '') {
            return '(baris kosong)';
        }

        return mb_strimwidth($teks, 0, 70, '…');
    }

    /**
     * Ambil kolom dan pesan pertama dari galat skema.
     *
     * Kolom `butir` diterjemahkan menjadi `kunci`, dan itu yang membuat pesan
     * "pasangan kurang" menunjuk ke kolom yang benar-benar diisi Guru: item
     * jodoh dan urut datang dari kolom `kunci` di dalam berkas, meski di
     * basis datanya ia hidup di `question_items`.
     *
     * @return array{0: string, 1: string}
     */
    private function galatPertama(ValidationException $e): array
    {
        $errors = $e->errors();
        $kolom = (string) (array_key_first($errors) ?? 'soal');
        $pesan = (string) (collect($errors)->flatten()->first() ?? 'Baris ini tidak lolos pemeriksaan.');

        if ($kolom === 'butir') {
            $kolom = 'kunci';
        }

        return [$kolom, $pesan];
    }

    /**
     * Pilih pembaca menurut ekstensinya.
     *
     * Yang memutuskan adalah ekstensi, bukan isi berkasnya: `.xls` lama
     * (OLE2) harus jatuh ke sini sebagai "tidak didukung", bukan dibuka
     * sebagai zip lalu gagal dengan galat yang menunjuk ke tempat lain.
     */
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

    private function ekstensi(QuestionImportBatch $batch): string
    {
        return strtolower(pathinfo($batch->file_path, PATHINFO_EXTENSION));
    }

    private function folder(int $schoolId, int $batchId): string
    {
        return 'laporan/impor/'.$schoolId.'/'.$batchId;
    }

    /**
     * Tandai berkasnya gagal lalu LEMPAR - selalu, tanpa kecuali.
     *
     * -----------------------------------------------------------------
     * Mengapa satu method, bukan `tandaiGagal()` yang dipanggil dua-tiga kali
     * -----------------------------------------------------------------
     * Versi pertama memanggil `tandaiGagal()` lalu melempar ValidationException
     * hanya di SATU cabang - cabang pembaca yang melempar. Tiga cabang lain
     * mengembalikan barisnya begitu saja, dan controller-nya melanjutkan ke
     * `redirect()->with('sukses', ...)`: Guru menerima lencana HIJAU
     * "berkasnya tidak bisa dibaca: kolom wajib belum ada" untuk berkas yang
     * ditolak. Warna lencana adalah seluruh isi pesannya bagi siapa pun yang
     * membacanya sambil lalu.
     *
     * Karena itu keduanya diikat di satu tempat: mustahil menandai gagal tanpa
     * memberi tahu pemanggilnya.
     *
     * @throws ValidationException
     */
    private function tolakBerkas(QuestionImportBatch $batch, string $pesan): never
    {
        $this->tandaiGagal($batch, $pesan);

        throw ValidationException::withMessages(['berkas' => $pesan]);
    }

    /**
     * Tandai berkasnya gagal dibaca.
     *
     * Hanya dipanggil `tolakBerkas()`. Barisnya harus tetap tercatat `gagal`
     * sebelum pesannya sampai ke Guru, kalau tidak riwayat impor akan
     * menampilkan berkas yang tampak belum diproses padahal Guru sudah
     * menerima pesannya.
     */
    private function tandaiGagal(QuestionImportBatch $batch, string $pesan): QuestionImportBatch
    {
        $batch->update([
            'status' => StatusImpor::Gagal->value,
            'pratinjau_json' => [
                'baris' => [],
                'sah' => 0,
                'gagal' => 0,
                'kolom' => [],
                'pesan' => $pesan,
            ],
        ]);

        $this->logger->log('Berkas impor soal gagal dibaca', $batch, ['pesan' => $pesan]);

        return $batch->fresh();
    }

    /**
     * Tulis laporan galat `.csv` ke disk privat.
     *
     * Ditaruh di disk yang sama dengan berkasnya, dan itu disengaja: keduanya
     * adalah bahan yang belum boleh dibaca siapa pun yang menebak namanya.
     * Yang bisa mengunduhnya adalah rute berwenang yang memeriksa
     * kepemilikan barisnya lebih dulu.
     *
     * Formatnya `.csv` supaya ia terbuka di Excel dengan sekali klik - Guru
     * memperbaiki berkasnya di Excel, dan laporan yang menuntut perantara
     * lain berarti sebuah langkah yang tidak ia lakukan.
     *
     * @param  array<int, array<string, mixed>>  $rincian
     */
    private function tulisLaporan(QuestionImportBatch $batch, array $rincian): string
    {
        $pegangan = fopen('php://temp', 'r+');

        // BOM ditulis supaya Excel berbahasa Indonesia tidak menampilkan
        // karakter berantakan pada huruf beraksen di pesannya.
        fwrite($pegangan, "\xEF\xBB\xBF");

        fputcsv($pegangan, ['baris', 'kolom', 'pesan', 'ringkas'], ';', '"', '\\');

        foreach ($rincian as $baris) {
            if ($baris['sah'] ?? false) {
                continue;
            }

            fputcsv($pegangan, [
                $baris['nomor'] ?? '',
                $baris['kolom'] ?? '',
                $baris['pesan'] ?? '',
                $baris['ringkas'] ?? '',
            ], ';', '"', '\\');
        }

        rewind($pegangan);
        $isi = (string) stream_get_contents($pegangan);
        fclose($pegangan);

        $jalur = $this->folder((int) $batch->school_id, (int) $batch->id).'/laporan-galat-'.Str::random(20).'.csv';

        Storage::disk('local')->put($jalur, $isi);

        return $jalur;
    }
}
