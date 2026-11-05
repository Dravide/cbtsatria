<?php

namespace App\Console\Commands;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Daftarkan satu sekolah baru beserta Admin pertamanya - satu langkah.
 *
 * =====================================================================
 * Mengapa perintah ini ada sama sekali
 * =====================================================================
 * PRD §2.2 no.4 menetapkan bahwa pembuatan sekolah dilakukan "melalui proses
 * provisioning di luar aplikasi". Yang tidak pernah ditetapkan adalah DI MANA
 * "di luar aplikasi" itu - dan sampai sekarang jawabannya adalah "tidak di
 * mana-mana". Satu-satunya kode yang pernah membuat baris `schools` adalah
 * DatabaseSeeder dan factory test. Menambah sekolah kedua berarti seseorang
 * membuka tinker, menulis lima baris PHP yang tidak pernah diuji siapa pun,
 * lalu mengisi lima belas tabel master data lain lewat layar satu per satu.
 *
 * Perintah ini menutup setengah pertama celah itu: sekolah, Admin pertama,
 * tahun ajaran aktif, dan dua semester - dalam satu transaksi.
 *
 * Setengah kedua SENGAJA tidak dikerjakan: kelas, mata pelajaran, ruang, dan
 * seterusnya diisi Admin lewat layar konsol (plate 05, 06, 09). Mengisinya di
 * sini berarti menebak struktur kurikulum sekolah yang belum tentu benar,
 * dan data yang salah lebih mahal daripada data yang kosong - yang kosong
 * terlihat dan akan diisi, yang salah tidak.
 *
 * =====================================================================
 * Tahun ajaran dibuat di sini, dan itu bukan kelengkapan administratif
 * =====================================================================
 * Ini bagian yang paling mudah dikira berlebihan, dan justru yang paling
 * menentukan. Tanpa satu pun tahun ajaran aktif:
 *
 *   - `EventController::validasi` menolak setiap pembuatan event
 *     (`academic_year_id` wajib saat membuat), sehingga Admin baru tidak
 *     bisa membuat event APA PUN - dan event adalah satu-satunya jalan masuk
 *     ke seluruh alur ujian;
 *   - prop bersama `tahunAktif` (HandleInertiaRequests) mengembalikan null,
 *     sehingga bar atas konsol kehilangan label "TA 2026/2027" di setiap
 *     laman (wireframe plate 07).
 *
 * Jadi perintah ini bukan "membuat sekolah lalu menyerahkan sisanya"; ia
 * mengantar sekolah sampai pada titik di mana layarnya benar-benar bisa
 * dipakai. Nama tahun ajarannya diturunkan dari kalender Indonesia - tahun
 * ajaran berganti tiap Juli - bukan dari tahun kalender, karena sekolah yang
 * didaftarkan bulan Agustus tidak boleh mendapat "2026/2026".
 *
 * =====================================================================
 * Idempoten, dan apa artinya di sini
 * =====================================================================
 * Dijalankan dua kali dengan opsi yang sama, hasilnya sama - bukan galat,
 * dan bukan sekolah kedua. Artinya per kunci:
 *
 *   - `code` sudah ada  -> sekolahnya DIPAKAI, kolomnya tidak disentuh.
 *     Sengaja tidak menimpa: di antara dua eksekusi, Admin mungkin sudah
 *     memperbaiki nama atau alamatnya lewat plate 04, dan perintah yang
 *     menimpanya berarti membatalkan pekerjaan itu diam-diam.
 *   - `npsn` sudah dipakai sekolah LAIN -> gagal. Kolomnya unik, dan NPSN
 *     adalah identitas yang tidak pernah berubah lewat aplikasi (SchoolPolicy
 *     `ubahNpsn` mengembalikan false untuk alasan yang sama).
 *   - `username` Admin sudah dipakai akun sekolah LAIN -> gagal. Username
 *     unik global; lihat catatan panjang di model User.
 *   - tahun ajaran sudah ada -> dibiarkan. Yang dibuat hanya bila sekolah itu
 *     belum punya satu pun - sehingga perintah ini juga memperbaiki sekolah
 *     yang terlanjur dibuat lewat tinker tanpa tahun ajaran.
 *
 * =====================================================================
 * `activity_logs` sengaja TIDAK ditulis
 * =====================================================================
 * Tergoda untuk mencatat "mendaftarkan sekolah" ke log aktivitas. Tetapi
 * ActivityLogger mengambil `school_id` dari pengguna yang sedang masuk, lalu
 * dari SchoolResolver, lalu dari `school_id` milik entitas yang dicatat -
 * dan ketiganya kosong di sini: perintah console tidak punya pengguna, dan
 * model School adalah tabel INDUK yang memang tidak punya kolom `school_id`.
 * Hasilnya baris dengan `school_id` null, yang ditolak constraint NOT NULL,
 * dan kegagalannya ditelan `log()` tanpa suara.
 *
 * Jadi bukan hanya tidak berguna - ia akan tampak seperti sudah mencatat
 * padahal tidak. Yang mencatat pendaftaran sekolah adalah riwayat shell dan
 * dokumen penyediaan sekolah, bukan tabel log yang dibaca Admin per sekolah.
 *
 * =====================================================================
 * Sandi Admin: kosongkan opsinya
 * =====================================================================
 * Tanpa `--sandi`, sandi dibuat acak dan dicetak SEKALI. Itu jalur yang
 * disarankan: opsi baris perintah tercatat di riwayat shell (`~/.bash_history`)
 * dan terlihat di `ps` oleh pengguna lain di mesin yang sama. Sandi acak tidak
 * pernah menyentuh dua tempat itu.
 *
 * Opsinya tetap ada untuk penyediaan otomatis, dan dengan peringatan yang
 * tercetak di layar saat ia dipakai.
 */
class DaftarkanSekolah extends Command
{
    protected $signature = 'sekolah:daftarkan
                            {--kode= : Kode internal sekolah, huruf/angka, mis. SMKN1}
                            {--nama= : Nama sekolah, mis. "SMK Negeri 1 Sukamaju"}
                            {--npsn= : NPSN, tepat 8 angka}
                            {--zona=Asia/Jakarta : Zona waktu sekolah}
                            {--alamat= : Alamat (opsional, muncul di kop berita acara)}
                            {--email= : Email sekolah (opsional)}
                            {--telepon= : Telepon sekolah (opsional)}
                            {--admin-nama= : Nama Admin pertama}
                            {--admin-username= : Username Admin pertama; unik lintas platform}
                            {--sandi= : Sandi Admin pertama. Kosongkan - sandi acak dicetak sekali}
                            {--kering : Periksa dan laporkan saja, jangan tulis apa pun}';

    protected $description = 'Daftarkan satu sekolah beserta Admin pertama, tahun ajaran aktif, dan dua semester';

    public function handle(): int
    {
        try {
            $data = $this->validasi();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $pesan) {
                foreach ($pesan as $satu) {
                    $this->components->error($satu);
                }
            }

            return self::FAILURE;
        }

        $kering = (bool) $this->option('kering');

        $sekolahAda = School::query()->where('code', $data['kode'])->first();
        $npsnMilik = School::query()->where('npsn', $data['npsn'])->first();

        // NPSN milik sekolah LAIN. Kalau barisnya justru sekolah yang sedang
        // kita daftarkan ulang, itu bukan bentrok - itu eksekusi kedua.
        if ($npsnMilik !== null && $npsnMilik->code !== $data['kode']) {
            $this->components->error(
                "NPSN {$data['npsn']} sudah dipakai sekolah '{$npsnMilik->name}' (kode {$npsnMilik->code})."
            );

            return self::FAILURE;
        }

        $usernameMilik = User::tanpaTenant()
            ->where('username', $data['admin_username'])
            ->first();

        // Satu sekolah boleh punya beberapa Admin (PRD §2.2 no.5), jadi akun
        // yang sudah ada di sekolah yang SAMA bukan kesalahan - itu eksekusi
        // kedua, dan yang benar adalah membiarkannya.
        if ($usernameMilik !== null && $usernameMilik->school_id !== $sekolahAda?->id) {
            $this->components->error(
                "Username '{$data['admin_username']}' sudah dipakai akun di sekolah lain. Username unik lintas platform."
            );

            return self::FAILURE;
        }

        if ($kering) {
            return $this->laporanKering($data, $sekolahAda, $usernameMilik);
        }

        $sandi = $data['sandi'] ?? Str::password(12, symbols: false);
        $sandiDicetak = $data['sandi'] === null;

        $hasil = DB::transaction(fn () => $this->tulis($data, $sekolahAda, $sandi));

        $this->ringkasan($hasil, $sandi, $sandiDicetak);

        return self::SUCCESS;
    }

    /**
     * Validasi opsi, dengan nama bahasa Indonesia di pesannya.
     *
     * @return array<string, string|null>
     *
     * @throws ValidationException
     */
    private function validasi(): array
    {
        $data = Validator::make([
            'kode' => $this->option('kode'),
            'nama' => $this->option('nama'),
            'npsn' => $this->option('npsn'),
            'zona' => $this->option('zona'),
            'alamat' => $this->option('alamat'),
            'email' => $this->option('email'),
            'telepon' => $this->option('telepon'),
            'admin_nama' => $this->option('admin-nama'),
            'admin_username' => $this->option('admin-username'),
            'sandi' => $this->option('sandi'),
        ], [
            'kode' => ['required', 'string', 'regex:/^[A-Za-z0-9]{2,20}$/'],
            'nama' => ['required', 'string', 'max:255'],
            // Tepat 8 angka. NPSN Indonesia selalu 8 digit, dan nilai yang
            // salah di sini tidak akan pernah ketahuan dari layar mana pun -
            // NPSN terkunci di plate 04 dan tidak dipakai untuk login.
            'npsn' => ['required', 'digits:8'],
            'zona' => ['required', 'in:Asia/Jakarta,Asia/Makassar,Asia/Jayapura'],
            'alamat' => ['nullable', 'string', 'max:1000'],
            'email' => ['nullable', 'email', 'max:255'],
            'telepon' => ['nullable', 'string', 'max:40'],
            'admin_nama' => ['required', 'string', 'max:255'],
            // Huruf kecil saja, tanpa spasi: nilainya diketik siswa di layar
            // login, dan UserRegistrar::bersihkan menurunkan hurufnya juga.
            'admin_username' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{2,39}$/'],
            'sandi' => ['nullable', 'string', 'min:8', 'max:255'],
        ], [
            // Pesan ditulis satu per satu, bukan mengandalkan terjemahan
            // bawaan Laravel. Aplikasi ini tidak punya direktori `lang/`, jadi
            // tanpa blok ini setiap galat keluar dalam bahasa Inggris -
            // termasuk `regex` yang isinya pola, dan pola mentah bukan pesan
            // yang bisa dibaca orang yang sedang menyediakan sekolah.
            'kode.required' => 'Opsi --kode wajib diisi. Mis. --kode=SMKN1',
            'kode.regex' => 'Kode sekolah hanya huruf dan angka, 2 sampai 20 karakter. Mis. SMKN1',
            'nama.required' => 'Opsi --nama wajib diisi. Mis. --nama="SMK Negeri 1 Sukamaju"',
            'npsn.required' => 'Opsi --npsn wajib diisi.',
            'npsn.digits' => 'NPSN harus tepat 8 angka.',
            'zona.in' => 'Zona waktu harus Asia/Jakarta, Asia/Makassar, atau Asia/Jayapura.',
            'admin_nama.required' => 'Opsi --admin-nama wajib diisi.',
            'admin_username.required' => 'Opsi --admin-username wajib diisi.',
            'admin_username.regex' => 'Username Admin hanya huruf kecil, angka, titik, garis bawah, atau strip; 3 sampai 40 karakter.',
            'sandi.min' => 'Sandi Admin minimal 8 karakter.',
        ])->validate();

        // Kode sekolah dipakai sebagai awalan username (UserRegistrar:
        // `smkn1.2024001`), dan bentuknya yang didokumentasikan adalah huruf
        // kecil. Menormalkan di sini sekali lebih murah daripada menjelaskan
        // nanti mengapa kode di layar berbeda dari awalan username.
        $data['kode'] = Str::upper($data['kode']);

        return $data;
    }

    /**
     * @param  array<string, string|null>  $data
     */
    private function laporanKering(array $data, ?School $sekolahAda, ?User $usernameMilik): int
    {
        $this->components->info('Mode kering - tidak ada yang ditulis.');

        $this->components->twoColumnDetail(
            'Sekolah',
            $sekolahAda
                ? "<fg=yellow>sudah ada</> (id {$sekolahAda->id}) - kolomnya tidak akan disentuh"
                : '<fg=green>akan dibuat</>',
        );

        $this->components->twoColumnDetail(
            'Admin',
            $usernameMilik
                ? "<fg=yellow>sudah ada</> (id {$usernameMilik->id}) - sandinya tidak akan diubah"
                : '<fg=green>akan dibuat</> '.$data['admin_username'],
        );

        $punyaTahun = $sekolahAda !== null
            && AcademicYear::query()->withoutGlobalScopes()
                ->where('school_id', $sekolahAda->id)->exists();

        $this->components->twoColumnDetail(
            'Tahun ajaran',
            $punyaTahun
                ? '<fg=yellow>sudah ada</> - dibiarkan'
                : '<fg=green>akan dibuat</> '.$this->namaTahunAjaran().' + Ganjil/Genap',
        );

        return self::SUCCESS;
    }

    /**
     * Semua penulisan, di dalam satu transaksi.
     *
     * Kalau pembuatan Admin gagal - mis. username yang baru saja diambil
     * proses lain - sekolahnya ikut dibatalkan. Sekolah tanpa Admin adalah
     * sekolah yang tidak bisa dibuka siapa pun, dan yang lebih buruk: `code`
     * dan `npsn`-nya sudah terpakai, sehingga percobaan berikutnya gagal
     * dengan "sudah dipakai" untuk baris yang tidak bisa dipakai.
     *
     * @param  array<string, string|null>  $data
     * @return array<string, mixed>
     */
    private function tulis(array $data, ?School $sekolahAda, string $sandi): array
    {
        $sekolah = $sekolahAda ?? School::create([
            'code' => $data['kode'],
            'name' => $data['nama'],
            'npsn' => $data['npsn'],
            'address' => $data['alamat'],
            'email' => $data['email'],
            'phone' => $data['telepon'],
            'timezone' => $data['zona'],
            'status' => 'aktif',
            // Sengaja null, bukan salinan PengaturanBawaan::NILAI. `gabung()`
            // yang menggabungkannya saat dibaca, dan menyimpannya di sini
            // berarti nilai itu membeku - sekolah yang didaftarkan hari ini
            // tidak akan ikut mendapat bawaan baru yang ditambahkan kelak.
            'settings' => null,
        ]);

        $admin = User::tanpaTenant()
            ->where('username', $data['admin_username'])
            ->first();

        if ($admin === null) {
            $admin = User::create([
                'school_id' => $sekolah->id,
                'role' => Role::Admin,
                'name' => $data['admin_nama'],
                'username' => $data['admin_username'],
                'email' => $data['email'],
                'password' => $sandi,
                'is_active' => true,
            ]);
        }

        $tahun = $this->pastikanTahunAjaran($sekolah);

        return [
            'sekolah' => $sekolah,
            'admin' => $admin,
            'admin_baru' => $admin->wasRecentlyCreated,
            'tahun' => $tahun,
            'tahun_baru' => $tahun->wasRecentlyCreated,
        ];
    }

    /**
     * Tahun ajaran aktif beserta dua semester.
     *
     * Dibuat hanya bila sekolah itu belum punya satu pun tahun ajaran. Yang
     * sudah punya dibiarkan utuh - termasuk yang aktifnya bukan tahun ini,
     * karena mengaktifkan tahun ajaran adalah keputusan Admin lewat plate 06,
     * dan perintah ini tidak boleh membatalkannya dari belakang.
     *
     * Penulisannya langsung, BUKAN lewat AcademicYearService::aktifkan().
     * Service itu mematikan tahun ajaran aktif lain dengan kueri yang
     * mengandalkan SchoolScope - dan di perintah console tidak ada pengguna,
     * sehingga `SchoolResolver::id()` mengembalikan null dan scope-nya diam.
     * Kueri "matikan yang lain" itu akan mematikan tahun ajaran aktif milik
     * SELURUH sekolah lain di basis data. Sekolah yang baru dibuat pasti belum
     * punya tahun ajaran, jadi tidak ada "yang lain" untuk dimatikan - dan
     * justru karena itu jalur service-nya tidak dibutuhkan di sini.
     */
    private function pastikanTahunAjaran(School $sekolah): AcademicYear
    {
        $ada = AcademicYear::query()->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->orderByDesc('is_active')
            ->orderByDesc('start_date')
            ->first();

        if ($ada !== null) {
            return $ada;
        }

        $kini = now();
        $mulai = $kini->month >= 7 ? $kini->year : $kini->year - 1;

        return DB::transaction(function () use ($sekolah, $kini, $mulai) {
            $tahun = AcademicYear::create([
                'school_id' => $sekolah->id,
                'name' => $this->namaTahunAjaran(),
                'start_date' => "{$mulai}-07-01",
                'end_date' => ($mulai + 1).'-06-30',
                'is_active' => true,
            ]);

            // Ganjil Juli-Desember, Genap Januari-Juni. Yang memuat hari ini
            // yang menyala - satu sekolah yang didaftarkan bulan Februari
            // tidak boleh memulai dengan semester yang sudah lewat.
            $ganjilAktif = $kini->month >= 7;

            Semester::create([
                'school_id' => $sekolah->id,
                'academic_year_id' => $tahun->id,
                'name' => 'Ganjil',
                'start_date' => "{$mulai}-07-01",
                'end_date' => "{$mulai}-12-31",
                'is_active' => $ganjilAktif,
            ]);

            Semester::create([
                'school_id' => $sekolah->id,
                'academic_year_id' => $tahun->id,
                'name' => 'Genap',
                'start_date' => ($mulai + 1).'-01-01',
                'end_date' => ($mulai + 1).'-06-30',
                'is_active' => ! $ganjilAktif,
            ]);

            return $tahun;
        });
    }

    /** Nama tahun ajaran menurut kalender Indonesia: berganti tiap Juli. */
    private function namaTahunAjaran(): string
    {
        $kini = now();
        $mulai = $kini->month >= 7 ? $kini->year : $kini->year - 1;

        return $mulai.'/'.($mulai + 1);
    }

    /**
     * @param  array<string, mixed>  $hasil
     */
    private function ringkasan(array $hasil, string $sandi, bool $sandiDicetak): void
    {
        /** @var School $sekolah */
        $sekolah = $hasil['sekolah'];
        /** @var User $admin */
        $admin = $hasil['admin'];

        $this->components->info('Sekolah didaftarkan.');

        $this->components->twoColumnDetail('Sekolah', "{$sekolah->name} (id {$sekolah->id})");
        $this->components->twoColumnDetail('Kode', $sekolah->code);
        $this->components->twoColumnDetail('NPSN', $sekolah->npsn);
        $this->components->twoColumnDetail(
            'Tahun ajaran',
            $hasil['tahun']->name.($hasil['tahun_baru'] ? '' : ' (sudah ada - dibiarkan)'),
        );
        $this->components->twoColumnDetail(
            'Admin',
            $admin->name.' — '.$admin->username.($hasil['admin_baru'] ? '' : ' (sudah ada)'),
        );

        if (! $hasil['admin_baru']) {
            $this->newLine();
            $this->components->warn(
                'Akun Admin sudah ada, jadi sandinya TIDAK diubah. Lupakan sandi? Ganti lewat konsol: /admin/pengguna.'
            );

            return;
        }

        $this->newLine();

        if ($sandiDicetak) {
            // Dicetak sekali, dan tidak disimpan dalam bentuk terbaca di mana
            // pun - hanya hash-nya yang ada di basis data. Muat ulang layar
            // tidak akan menampilkannya kembali.
            $this->components->twoColumnDetail('<fg=yellow>Sandi Admin</>', "<fg=yellow;options=bold>{$sandi}</>");
            $this->newLine();
            $this->components->warn(
                'Sandi ini hanya tampil sekali dan tidak tersimpan dalam bentuk terbaca. Salin sekarang.'
            );
        } else {
            $this->components->warn(
                'Sandi diambil dari opsi --sandi, sehingga ia tercatat di riwayat shell. Ganti sesudah login pertama.'
            );
        }

        $this->newLine();
        $this->components->bulletList([
            'Masuk di /login dengan username di atas.',
            'Langkah berikutnya: kelas, mata pelajaran, dan ruang (konsol Admin), lalu event pertama.',
        ]);
    }
}
