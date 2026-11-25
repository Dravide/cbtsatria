<?php

namespace App\Domain\Admin\Services;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Services\PenempatanKelas;
use App\Domain\Admin\Enums\StatusImporPengguna;
use App\Domain\Admin\Import\PenjajarPengguna;
use App\Domain\Admin\Models\UserImportBatch;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
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
 * Impor pengguna bertahap: unggah ke disk privat, pratinjau tanpa menulis akun, lalu konfirmasi.
 * Baris perbarui tidak pernah mengubah username/sandi, dan sel kosong tidak mengosongkan kolom lama.
 */
class ImporPenggunaService
{
    /**
     * Batas panjang maksimum per kolom (selaras UserController::aturan()); ditolak per baris, tidak dipotong.
     *
     * @var array<string, int>
     */
    private const PANJANG = [
        'nama' => 255,
        'nis' => 40,
        'nisn' => 40,
        'nip' => 40,
        'kode_pegawai' => 40,
        'email' => 255,
        'telepon' => 40,
        'alamat' => 500,
    ];

    public function __construct(
        private readonly PembacaXlsx $xlsx,
        private readonly PembacaCsv $csv,
        private readonly UserRegistrar $registrar,
        private readonly PencariAkun $pencari,
        private readonly PenempatanKelas $penempatan,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @throws ValidationException
     */
    public function unggah(UploadedFile $berkas, int $schoolId, int $userId): UserImportBatch
    {
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

        // Tolak berkas dengan hash yang sama agar impor tidak berjalan dua kali.
        $sudahAda = UserImportBatch::query()
            ->where('user_id', $userId)
            ->where('berkas_hash', $hash)
            ->first();

        if ($sudahAda !== null) {
            throw ValidationException::withMessages([
                'berkas' => 'Berkas ini sudah pernah Anda unggah ('.$sudahAda->nama_asli.', '.
                    $sudahAda->statusImpor()->label().'). Berkas yang sama tidak diimpor dua kali - '.
                    'perbaiki berkasnya lebih dulu, atau lanjutkan impor yang sudah ada.',
            ]);
        }

        return DB::transaction(function () use ($berkas, $isi, $hash, $schoolId, $userId, $ekstensi) {
            $batch = UserImportBatch::create([
                'school_id' => $schoolId,
                'user_id' => $userId,
                // Diisi setelah ID batch tersedia karena jalur memuat ID dan kolomnya NOT NULL.
                'file_path' => '',
                'nama_asli' => $berkas->getClientOriginalName(),
                'berkas_hash' => $hash,
                'status' => StatusImporPengguna::Menunggu->value,
            ]);

            $jalur = $this->folder($schoolId, (int) $batch->id).'/'.Str::random(40).'.'.($ekstensi ?: 'xlsx');

            Storage::disk('local')->put($jalur, $isi);
            $batch->update(['file_path' => $jalur]);

            $this->logger->log('Mengunggah berkas impor pengguna', $batch, [
                'nama_asli' => $batch->nama_asli,
            ]);

            return $batch;
        });
    }

    /**
     * Urai dan validasi setiap baris tanpa menulis akun ke basis data.
     *
     * @throws ValidationException
     */
    public function pratinjau(UserImportBatch $batch): UserImportBatch
    {
        if ($batch->statusImpor() === StatusImporPengguna::Dikonfirmasi) {
            throw ValidationException::withMessages([
                'berkas' => 'Berkas ini sudah diimpor. Menguraikannya ulang akan memperbarui akun yang sama untuk kedua kalinya.',
            ]);
        }

        $pembaca = $this->pilihPembaca($this->ekstensi($batch));

        if ($pembaca === null) {
            $this->tolakBerkas($batch, 'Jenis berkasnya tidak dikenali. Unggah berkas .xlsx atau .csv.');
        }

        try {
            $baris = $pembaca->baca(Storage::disk('local')->path($batch->file_path));
        } catch (BerkasImportTidakTerbaca $e) {
            $this->tolakBerkas($batch, $e->getMessage());
        }

        $judul = PenjajarKolom::temukan($baris, PenjajarPengguna::class);

        if ($judul === null) {
            $this->tolakBerkas($batch, 'Berkasnya tidak berisi satu baris pun. Isi dulu datanya, lalu unggah ulang.');
        }

        [$noJudul, $selJudul] = $judul;
        $penjajar = new PenjajarPengguna($selJudul, $noJudul);

        if (! $penjajar->sah()) {
            $this->tolakBerkas($batch, $penjajar->pesanKolomHilang());
        }

        $sekolahId = (int) $batch->school_id;

        // Tolak seluruh berkas sejak awal jika kolom kelas terisi tetapi belum ada tahun ajaran aktif.
        $tahunAktif = $this->tahunAktif($sekolahId);

        if ($tahunAktif === null && $this->adaBarisKelas($penjajar, $baris, $noJudul)) {
            $this->tolakBerkas($batch, 'Berkas ini mengisi kolom kelas, tetapi belum ada tahun ajaran aktif. '.
                'Aktifkan dulu salah satu tahun ajaran di laman Tahun Ajaran, atau kosongkan kolom kelasnya. '.
                'Tanpa tahun ajaran aktif, tidak ada kelas yang bisa dicocokkan.');
        }

        // Muat daftar kelas sekali untuk seluruh berkas agar tidak terjadi N+1 query.
        $kelas = $this->petaKelas($tahunAktif);

        $emailDipakai = $this->emailSekolah($sekolahId);

        $rincian = [];
        $sah = 0;
        $gagal = 0;
        $dibuat = 0;
        $diperbarui = 0;

        // Peta "peran|nomor" => nomor baris pertama untuk deteksi duplikat dalam satu berkas.
        $terlihat = [];

        foreach ($baris as $nomor => $sel) {
            if ($nomor <= $noJudul || PenjajarKolom::kosong($sel)) {
                continue;
            }

            $hasil = $this->periksaBaris(
                $penjajar,
                $sel,
                (int) $nomor,
                $sekolahId,
                $tahunAktif,
                $kelas,
                $emailDipakai,
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

        $this->logger->log('Mempratinjau berkas impor pengguna', $batch, [
            'sah' => $sah,
            'gagal' => $gagal,
            'baru' => $dibuat,
            'diperbarui' => $diperbarui,
        ]);

        return $batch->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function konfirmasi(UserImportBatch $batch): UserImportBatch
    {
        if (! $batch->statusImpor()->bolehDikonfirmasi()) {
            throw ValidationException::withMessages([
                'berkas' => $batch->statusImpor() === StatusImporPengguna::Dikonfirmasi
                    ? 'Berkas ini sudah diimpor.'
                    : 'Berkas ini belum dipratinjau. Pratinjau lebih dulu supaya Anda tahu akun mana yang akan tersimpan.',
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
        $tahunAktif = $this->tahunAktif($sekolahId);

        $hasil = $this->simpanBaris($rincian, $sekolahId, $tahunAktif);

        $laporan = $hasil['gagal_total'] > 0 ? $this->tulisLaporan($batch, $hasil['rincian']) : null;

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

        $this->logger->log('Mengimpor pengguna dari berkas', $batch, [
            'dibuat' => $hasil['dibuat'],
            'diperbarui' => $hasil['diperbarui'],
            'gagal' => $hasil['gagal_total'],
        ]);

        return $batch->fresh();
    }

    /**
     * Hapus batch yang belum dikonfirmasi beserta berkasnya agar hash berkas bisa diunggah ulang.
     *
     * @throws ValidationException
     */
    public function batalkan(UserImportBatch $batch): void
    {
        if ($batch->statusImpor() === StatusImporPengguna::Dikonfirmasi) {
            throw ValidationException::withMessages([
                'berkas' => 'Berkas ini sudah diimpor, jadi tidak bisa dibatalkan. Akun yang sudah tersimpan harus diubah satu per satu di laman Pengguna.',
            ]);
        }

        $this->logger->log('Membatalkan berkas impor pengguna', $batch, [
            'nama_asli' => $batch->nama_asli,
            'status' => $batch->statusImpor()->value,
        ]);

        // Hapus seluruh folder batch agar tidak ada berkas unggahan atau laporan galat yang yatim.
        Storage::disk('local')->deleteDirectory(
            $this->folder((int) $batch->school_id, (int) $batch->id),
        );

        $batch->delete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rincian
     * @return array{tersimpan: int, dibuat: int, diperbarui: int, gagal_total: int, rincian: array<int, array<string, mixed>>}
     */
    private function simpanBaris(
        array $rincian,
        int $sekolahId,
        ?AcademicYear $tahunAktif,
    ): array {
        // Periksa ulang keberadaan kelas karena kelas bisa dihapus antara pratinjau dan konfirmasi.
        $kelasAda = $this->idKelas($tahunAktif);

        $tersimpan = 0;
        $dibuat = 0;
        $diperbarui = 0;

        // Transaksi per baris agar satu baris gagal tidak membatalkan akun lain yang sah.
        foreach ($rincian as $i => $baris) {
            if (! ($baris['sah'] ?? false)) {
                continue;
            }

            if ($baris['kelas_id'] !== null && ! in_array((int) $baris['kelas_id'], $kelasAda, true)) {
                $rincian[$i] = $this->gagalkan(
                    $baris,
                    'Kelas "'.$baris['kelas'].'" sudah tidak ada lagi. Periksa daftar kelas, lalu unggah ulang berkasnya.',
                );

                continue;
            }

            try {
                $hasil = DB::transaction(fn () => $this->tulisSatu($baris, $sekolahId, $tahunAktif));

                $tersimpan++;

                if ($hasil === 'baru') {
                    $dibuat++;
                } else {
                    $diperbarui++;
                }
            } catch (\Throwable $e) {
                report($e);

                $rincian[$i] = $this->gagalkan(
                    $baris,
                    'Baris ini gagal disimpan: '.mb_substr($e->getMessage(), 0, 160, 'UTF-8'),
                );
            }
        }

        return [
            'tersimpan' => $tersimpan,
            'dibuat' => $dibuat,
            'diperbarui' => $diperbarui,
            // Hitung dari rincian akhir agar mencakup galat pratinjau maupun galat saat penulisan.
            'gagal_total' => count(array_filter($rincian, fn ($b) => ! ($b['sah'] ?? false))),
            'rincian' => $rincian,
        ];
    }

    /**
     * @param  array<string, mixed>  $baris
     * @return string `baru` atau `diperbarui`
     */
    private function tulisSatu(array $baris, int $sekolahId, ?AcademicYear $tahunAktif): string
    {
        $muatan = $baris['muatan'];
        $peran = Role::from($muatan['role']);

        if ($baris['user_id'] !== null) {
            $user = User::query()
                ->withoutGlobalScopes()
                ->where('school_id', $sekolahId)
                ->find((int) $baris['user_id']);

            // Jika akun terhapus antara pratinjau dan konfirmasi, buat akun baru agar baris tidak gagal.
            if ($user !== null) {
                $this->registrar->perbarui($user, $muatan);
                $aksi = 'diperbarui';
            } else {
                $user = $this->buatAkun($muatan, $sekolahId, $peran);
                $aksi = 'baru';
            }
        } else {
            $user = $this->buatAkun($muatan, $sekolahId, $peran);
            $aksi = 'baru';
        }

        if ($baris['kelas_id'] !== null && $tahunAktif !== null) {
            $kelas = SchoolClass::query()
                ->withoutGlobalScopes()
                ->where('school_id', $sekolahId)
                ->find((int) $baris['kelas_id']);

            if ($kelas !== null) {
                $this->penempatan->tempatkan((int) $user->id, $kelas);
            }
        }

        return $aksi;
    }

    /**
     * @param  array<string, mixed>  $muatan
     */
    private function buatAkun(array $muatan, int $sekolahId, Role $peran): User
    {
        $nomor = $this->nomorDariMuatan($muatan, $peran);

        return $this->registrar->buat(
            array_merge($muatan, [
                'role' => $peran,
                'username' => $this->registrar->usernameDari($nomor, (string) $this->kodeSekolah($sekolahId)),
                // Wajib dikirim karena UserRegistrar::buat() membacanya tanpa syarat.
                'password' => User::SANDI_BAWAAN,
            ]),
            $sekolahId,
        );
    }

    /**
     * @param  array<int, string>  $sel
     * @param  array<string, int>  $kelas  nama kelas (huruf kecil) => id
     * @param  array<int, string>  $emailDipakai  user_id => email
     * @param  array<string, int>  $terlihat  "peran|nomor" => nomor baris pertama
     * @return array<string, mixed>
     */
    private function periksaBaris(
        PenjajarPengguna $penjajar,
        array $sel,
        int $nomor,
        int $sekolahId,
        ?AcademicYear $tahunAktif,
        array $kelas,
        array $emailDipakai,
        array &$terlihat,
    ): array {
        $peranMentah = $penjajar->ambil($sel, 'peran');
        $nama = $penjajar->ambil($sel, 'nama');

        $dasar = [
            'nomor' => $nomor,
            'peran' => null,
            'peran_label' => null,
            'nama' => $nama,
            'nomor_identitas' => null,
            'aksi' => 'gagal',
            'user_id' => null,
            'kelas' => null,
            'kelas_id' => null,
            'kelas_aksi' => null,
            'kelas_dari' => null,
            'sah' => false,
            'kolom' => null,
            'pesan' => null,
            'muatan' => null,
        ];

        if ($peranMentah === '') {
            return array_merge($dasar, ['kolom' => 'peran', 'pesan' => 'Kolom peran kosong. Isi salah satu dari: '.$this->daftarPeran().'.']);
        }

        $peran = $this->peranDari($peranMentah);

        if ($peran === null) {
            // Tolak peran admin secara eksplisit untuk mencegah eskalasi hak lewat spreadsheet.
            $pesan = strtolower(trim($peranMentah)) === 'admin'
                ? 'Peran "admin" tidak bisa dibuat lewat impor. Buat akun Admin satu per satu di laman Pengguna.'
                : 'Peran "'.$peranMentah.'" tidak dikenal. Isi salah satu dari: '.$this->daftarPeran().'.';

            return array_merge($dasar, ['kolom' => 'peran', 'pesan' => $pesan]);
        }

        $dasar['peran'] = $peran->value;
        $dasar['peran_label'] = $peran->label();

        if ($nama === '') {
            return array_merge($dasar, ['kolom' => 'nama', 'pesan' => 'Kolom nama kosong.']);
        }

        foreach (self::PANJANG as $kolom => $maks) {
            $isi = $penjajar->ambil($sel, $kolom);

            if (mb_strlen($isi) > $maks) {
                return array_merge($dasar, [
                    'kolom' => $kolom,
                    'pesan' => 'Isi kolom '.$kolom.' melebihi '.$maks.' karakter.',
                ]);
            }
        }

        $kolomNomor = match ($peran) {
            Role::Student => $penjajar->ambil($sel, 'nis') !== '' ? 'nis' : 'nisn',
            Role::Teacher => 'nip',
            Role::Supervisor => 'kode_pegawai',
            default => 'nis',
        };

        $nomorIdentitas = $penjajar->ambil($sel, $kolomNomor);

        if ($nomorIdentitas === '') {
            return array_merge($dasar, [
                'kolom' => $kolomNomor,
                'pesan' => 'Kolom '.$kolomNomor.' kosong. Nomor identitas dibutuhkan untuk mengenali akun lamanya dan menurunkan username-nya.',
            ]);
        }

        $dasar['nomor_identitas'] = $nomorIdentitas;

        $kunci = $peran->value.'|'.$nomorIdentitas;

        if (array_key_exists($kunci, $terlihat)) {
            return array_merge($dasar, [
                'kolom' => $kolomNomor,
                'pesan' => 'Nomor '.$nomorIdentitas.' sudah dipakai baris '.$terlihat[$kunci].' di berkas yang sama. Sisakan satu baris untuk nomor ini.',
            ]);
        }

        $terlihat[$kunci] = $nomor;

        $gender = $penjajar->ambil($sel, 'jenis_kelamin');
        $jenisKelamin = null;

        if ($gender !== '') {
            $jenisKelamin = $this->genderDari($gender);

            if ($jenisKelamin === null) {
                return array_merge($dasar, [
                    'kolom' => 'jenis_kelamin',
                    'pesan' => 'Jenis kelamin "'.$gender.'" tidak dikenal. Isi L (laki-laki) atau P (perempuan).',
                ]);
            }
        }

        $aktifMentah = $penjajar->ambil($sel, 'aktif');
        $aktif = true;

        if ($aktifMentah !== '') {
            $aktif = $this->aktifDari($aktifMentah);

            if ($aktif === null) {
                return array_merge($dasar, [
                    'kolom' => 'aktif',
                    'pesan' => 'Nilai kolom aktif "'.$aktifMentah.'" tidak dikenal. Isi ya atau tidak.',
                ]);
            }
        }

        // Abaikan kolom kelas untuk non-siswa karena semua peran memakai satu template.
        $kelasId = null;
        $kelasNama = $penjajar->ambil($sel, 'kelas');

        if ($peran === Role::Student && $kelasNama !== '') {
            $kunciKelas = mb_strtolower(trim($kelasNama));

            if (! array_key_exists($kunciKelas, $kelas)) {
                return array_merge($dasar, [
                    'kolom' => 'kelas',
                    'pesan' => 'Kelas "'.$kelasNama.'" tidak ada di tahun ajaran '.
                        ($tahunAktif?->name ?? '(tidak ada yang aktif)').'. '.
                        'Kelas tidak dibuat otomatis - periksa ejaannya, atau tambahkan kelasnya lebih dulu di laman Kelas.',
                ]);
            }

            $kelasId = $kelas[$kunciKelas];
        }

        // Cari akun lama setelah validasi ringan agar baris salah tidak membebani basis data.
        $kandidat = $this->pencari->cari($peran, $nomorIdentitas, $sekolahId);

        if ($kandidat->count() > 1) {
            return array_merge($dasar, [
                'kolom' => $kolomNomor,
                'pesan' => 'Nomor '.$nomorIdentitas.' dipakai lebih dari satu akun ('.$kandidat->count().' akun). '.
                    'Rapikan dulu di daftar pengguna, lalu unggah ulang berkasnya. '.
                    'Impor tidak menebak akun mana yang dimaksud.',
            ]);
        }

        $lama = $kandidat->first();

        $email = $penjajar->ambil($sel, 'email');

        if ($email !== '') {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return array_merge($dasar, ['kolom' => 'email', 'pesan' => 'Email "'.$email.'" bukan alamat surel yang sah.']);
            }

            $pemilik = array_search($email, $emailDipakai, true);

            if ($pemilik !== false && (int) $pemilik !== (int) ($lama?->id ?? 0)) {
                return array_merge($dasar, [
                    'kolom' => 'email',
                    'pesan' => 'Email '.$email.' sudah dipakai akun lain di sekolah ini. Alamat surel unik per sekolah.',
                ]);
            }
        }

        $telepon = $penjajar->ambil($sel, 'telepon');
        $alamat = $penjajar->ambil($sel, 'alamat');

        // Lewati sel kosong agar baris perbarui tidak menimpa data lama dengan null.
        $muatan = [
            'role' => $peran->value,
            'name' => $nama,
            'is_active' => $aktif,
            ...$this->hanyaTerisi([
                'email' => $email,
                'phone' => $telepon,
                'gender' => $jenisKelamin,
                'address' => $alamat,
            ]),
        ];

        $muatan = array_merge($muatan, $this->kolomIdentitas($peran, $penjajar, $sel));

        $dasar['aksi'] = $lama === null ? 'baru' : 'diperbarui';
        $dasar['user_id'] = $lama?->id;
        $dasar['kelas'] = $kelasId === null ? null : $kelasNama;
        $dasar['kelas_id'] = $kelasId;

        if ($kelasId !== null && $lama !== null && $tahunAktif !== null) {
            $kelasModel = SchoolClass::query()
                ->withoutGlobalScopes()
                ->where('school_id', $sekolahId)
                ->find($kelasId);

            if ($kelasModel !== null) {
                // Hitung rencana penempatan tanpa menulis agar pratinjau identik dengan konfirmasi.
                $rencana = $this->penempatan->rencana((int) $lama->id, $kelasModel);

                $dasar['kelas_aksi'] = $rencana['aksi'];
                $dasar['kelas_dari'] = $rencana['dari'];
            }
        } elseif ($kelasId !== null) {
            $dasar['kelas_aksi'] = 'baru';
        }

        return array_merge($dasar, [
            'sah' => true,
            'kolom' => null,
            'pesan' => $lama === null
                ? 'Akun baru akan dibuat dengan username dari nomor identitasnya.'
                : 'Akun lama akan diperbarui. Username dan sandinya tidak berubah.',
            'muatan' => $muatan,
        ]);
    }

    /**
     * Hanya sertakan kolom identitas yang terisi agar baris perbarui tidak mengosongkan data lama.
     *
     * @param  array<int, string>  $sel
     * @return array<string, string>
     */
    private function kolomIdentitas(Role $peran, PenjajarPengguna $penjajar, array $sel): array
    {
        $kolom = match ($peran) {
            Role::Student => ['nis' => 'nis', 'nisn' => 'nisn'],
            Role::Teacher => ['nip' => 'nip'],
            Role::Supervisor => ['staff_code' => 'kode_pegawai'],
            default => [],
        };

        $isi = [];

        foreach ($kolom as $kolomDb => $kolomTemplate) {
            $nilai = $penjajar->ambil($sel, $kolomTemplate);

            if ($nilai !== '') {
                $isi[$kolomDb] = $nilai;
            }
        }

        return $isi;
    }

    /**
     * Buang sel kosong agar baris perbarui tidak mengosongkan kolom yang sudah terisi.
     *
     * @param  array<string, string|null>  $nilai
     * @return array<string, string>
     */
    private function hanyaTerisi(array $nilai): array
    {
        return array_filter($nilai, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Cek langsung dari sel mentah apakah ada baris data yang mengisi kolom kelas.
     *
     * @param  array<int, array<int, string>>  $baris
     */
    private function adaBarisKelas(PenjajarPengguna $penjajar, array $baris, int $noJudul): bool
    {
        foreach ($baris as $nomor => $sel) {
            if ($nomor <= $noJudul || PenjajarKolom::kosong($sel)) {
                continue;
            }

            if ($penjajar->ambil($sel, 'kelas') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $baris
     * @return array<string, mixed>
     */
    private function gagalkan(array $baris, string $pesan): array
    {
        return array_merge($baris, [
            'sah' => false,
            'aksi' => 'gagal',
            'pesan' => $pesan,
        ]);
    }

    private function tahunAktif(int $sekolahId): ?AcademicYear
    {
        return AcademicYear::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolahId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Nama kelas (huruf kecil) => id, pada satu tahun ajaran.
     *
     * @return array<string, int>
     */
    private function petaKelas(?AcademicYear $tahun): array
    {
        if ($tahun === null) {
            return [];
        }

        return SchoolClass::query()
            ->withoutGlobalScopes()
            ->where('academic_year_id', $tahun->id)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (SchoolClass $k) => [mb_strtolower(trim((string) $k->name)) => (int) $k->id])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function idKelas(?AcademicYear $tahun): array
    {
        return array_values($this->petaKelas($tahun));
    }

    /**
     * Email yang sudah dipakai di sekolah ini: user_id => email.
     *
     * @return array<int, string>
     */
    private function emailSekolah(int $sekolahId): array
    {
        return User::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolahId)
            ->whereNotNull('email')
            ->pluck('email', 'id')
            ->all();
    }

    private function kodeSekolah(int $sekolahId): string
    {
        return (string) (School::query()->withoutGlobalScopes()->whereKey($sekolahId)->value('code') ?? '');
    }

    /**
     * @param  array<string, mixed>  $muatan
     */
    private function nomorDariMuatan(array $muatan, Role $peran): string
    {
        return match ($peran) {
            Role::Student => (string) ($muatan['nis'] ?? $muatan['nisn'] ?? ''),
            Role::Teacher => (string) ($muatan['nip'] ?? ''),
            Role::Supervisor => (string) ($muatan['staff_code'] ?? ''),
            default => '',
        };
    }

    private function peranDari(string $mentah): ?Role
    {
        $kunci = strtolower(trim($mentah));
        $kunci = str_replace([' ', '-', '.'], '_', $kunci);

        return match ($kunci) {
            'siswa', 'student', 'murid', 'peserta_didik', 'pesertadidik', 'anak' => Role::Student,
            'guru', 'teacher', 'pengajar', 'pendidik', 'ustadz' => Role::Teacher,
            'pengawas', 'supervisor', 'proctor', 'pengawas_ruang' => Role::Supervisor,
            'admin', 'administrator' => null,
            default => Role::tryFrom($kunci),
        };
    }

    private function daftarPeran(): string
    {
        return 'siswa, guru, pengawas';
    }

    private function genderDari(string $mentah): ?string
    {
        return match (strtolower(trim($mentah))) {
            'l', 'lk', 'laki_laki', 'lakilaki', 'pria', 'male', 'm' => 'L',
            'p', 'pr', 'perempuan', 'wanita', 'female', 'f' => 'P',
            default => null,
        };
    }

    private function aktifDari(string $mentah): ?bool
    {
        return match (strtolower(trim($mentah))) {
            'ya', 'y', '1', 'true', 'aktif', 'yes', 'benar' => true,
            'tidak', 't', '0', 'false', 'nonaktif', 'no', 'salah' => false,
            default => null,
        };
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

    private function ekstensi(UserImportBatch $batch): string
    {
        return strtolower(pathinfo($batch->file_path, PATHINFO_EXTENSION));
    }

    private function folder(int $schoolId, int $batchId): string
    {
        return 'impor-pengguna/'.$schoolId.'/'.$batchId;
    }

    /**
     * Tandai batch gagal lalu selalu lempar ValidationException agar UI tidak menampilkan status sukses.
     *
     * @throws ValidationException
     */
    private function tolakBerkas(UserImportBatch $batch, string $pesan): never
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

        $this->logger->log('Berkas impor pengguna gagal dibaca', $batch, ['pesan' => $pesan]);

        throw ValidationException::withMessages(['berkas' => $pesan]);
    }

    /**
     * Tulis laporan galat CSV ke disk privat yang sama dengan berkas unggahan.
     *
     * @param  array<int, array<string, mixed>>  $rincian
     */
    private function tulisLaporan(UserImportBatch $batch, array $rincian): string
    {
        $pegangan = fopen('php://temp', 'r+');

        // Sertakan BOM UTF-8 agar Excel membaca karakter beraksen dengan benar.
        fwrite($pegangan, "\xEF\xBB\xBF");

        fputcsv($pegangan, ['baris', 'peran', 'nama', 'nomor', 'kolom', 'pesan'], ';', '"', '\\');

        foreach ($rincian as $baris) {
            if ($baris['sah'] ?? false) {
                continue;
            }

            fputcsv($pegangan, [
                $baris['nomor'] ?? '',
                $baris['peran_label'] ?? '',
                $baris['nama'] ?? '',
                $baris['nomor_identitas'] ?? '',
                $baris['kolom'] ?? '',
                $baris['pesan'] ?? '',
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
