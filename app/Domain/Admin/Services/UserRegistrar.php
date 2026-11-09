<?php

namespace App\Domain\Admin\Services;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\SupervisorProfile;
use App\Domain\Identity\Models\TeacherProfile;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pembuatan dan perubahan akun dari konsol Admin.
 *
 * ---------------------------------------------------------------------
 * Mengapa di Admin, bukan di Identity
 * ---------------------------------------------------------------------
 * Membuat satu siswa bukan satu penulisan: ia menulis users, lalu
 * student_profiles, lalu class_enrollments - tiga modul berbeda. Identity
 * tidak boleh menyentuh Academic (lihat peta batas modul), dan menaruh
 * orkestrasi lintas modul di sana berarti memaksakan pengecualian pada aturan
 * yang justru sedang dijaga.
 *
 * Admin adalah composition root konsol: tempat merangkai modul. Di sinilah
 * "membuat akun siswa" sebagai satu operasi bermakna bisa tinggal.
 *
 * ---------------------------------------------------------------------
 * Username diturunkan dari NIS/NIP
 * ---------------------------------------------------------------------
 * Wireframe plate 05 mencatatnya, dan alasan sesungguhnya ada di layar login:
 * satu-satunya identitas yang diketik siswa adalah username. Kalau username
 * dikarang sistem - mis. "siswa0173" - maka siswa tidak bisa mengingatnya,
 * dan setiap awal ujian menjadi sesi tanya-jawab dengan pengawas.
 *
 * NIS adalah nomor yang sudah tertempel di kartu siswa. Menurunkannya menjadi
 * username berarti tidak ada yang perlu dihafal.
 *
 * Tetapi username unik GLOBAL, sementara NIS hanya unik per sekolah. Karena
 * itu bentrokan lintas sekolah diselesaikan dengan awalan kode sekolah
 * (blueprint: `smkn1.2024001`), dan algoritmanya: pakai NIS apa adanya bila
 * masih bebas; kalau tidak, tambahkan awalan kode sekolah; bila masih
 * bentrok, mundur ke nomor urut. Tidak ada jalur yang menghasilkan dua akun
 * berusername sama - gate kualitas blueprint butir 2.
 */
class UserRegistrar
{
    /**
     * Username yang belum dipakai siapa pun.
     *
     * Pemeriksaannya sengaja melewati tenant: bentrokan yang dicegah di sini
     * justru bentrokan ANTAR sekolah, dan query yang ter-scope tidak akan
     * pernah melihat baris sekolah lain - sehingga ia akan melaporkan "bebas"
     * untuk username yang sudah dipakai sekolah lain, dan penggunaannya gagal
     * di tingkat basis data dengan galat yang membingungkan.
     */
    public function usernameDari(string $nomor, string $kodeSekolah): string
    {
        $dasar = $this->bersihkan($nomor);

        if ($dasar === '') {
            $dasar = 'pengguna';
        }

        if (! $this->terpakai($dasar)) {
            return $dasar;
        }

        $berawalan = $this->bersihkan($kodeSekolah).'.'.$dasar;

        if (! $this->terpakai($berawalan)) {
            return $berawalan;
        }

        $urut = 2;

        while ($this->terpakai($berawalan.'-'.$urut)) {
            $urut++;
        }

        return $berawalan.'-'.$urut;
    }

    public function terpakai(string $username): bool
    {
        return User::tanpaTenant()->where('username', $username)->exists();
    }

    /**
     * Buat akun lengkap dengan profilnya.
     *
     * @param  array<string, mixed>  $data
     */
    public function buat(array $data, int $schoolId): User
    {
        return DB::transaction(function () use ($data, $schoolId) {
            $user = User::create([
                'school_id' => $schoolId,
                'role' => $data['role'],
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->simpanProfil($user, $data);

            return $user;
        });
    }

    /**
     * Perbarui akun beserta profilnya.
     *
     * @param  array<string, mixed>  $data
     */
    public function perbarui(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            // Sengaja bukan array_filter atas nilai null. Filter itu tampak
            // rapi tetapi membuat "kosongkan email" mustahil: null dibuang
            // sebelum sampai ke fill(), sehingga kolomnya tidak pernah
            // berubah dan Admin melihat nilainya kembali setelah menyimpan.
            // Yang benar adalah memeriksa KEBERADAAN kuncinya, bukan isinya.
            $kolom = [];

            foreach (['name', 'email', 'phone', 'is_active'] as $kunci) {
                if (array_key_exists($kunci, $data)) {
                    $kolom[$kunci] = $data[$kunci];
                }
            }

            if ($kolom !== []) {
                $user->fill($kolom)->save();
            }

            if (isset($data['password']) && $data['password'] !== '') {
                $user->forceFill(['password' => $data['password']])->save();
            }

            $this->simpanProfil($user, $data);

            return $user;
        });
    }

    /**
     * Profil ditulis sesuai peran.
     *
     * Satu user punya satu profil (PRD §17 butir 2), jadi peran lain tidak
     * ikut ditulis - dan kalau peran berubah kelak, profil lama harus
     * dipindahkan, bukan dibiarkan berdampingan. Untuk Fase 2 peran tidak
     * diubah lewat form: mengubah siswa menjadi guru berarti memindahkan
     * seluruh riwayatnya, dan itu operasi tersendiri, bukan efek samping
     * sebuah dropdown.
     *
     * @param  array<string, mixed>  $data
     */
    private function simpanProfil(User $user, array $data): void
    {
        $atribut = fn (array $kolom) => array_merge(
            ['school_id' => $user->school_id, 'user_id' => $user->id],
            array_intersect_key($data, array_flip($kolom)),
        );

        match ($user->role) {
            Role::Student => StudentProfile::updateOrCreate(
                ['user_id' => $user->id],
                $atribut(['nis', 'nisn', 'gender', 'address']),
            ),
            Role::Teacher => TeacherProfile::updateOrCreate(
                ['user_id' => $user->id],
                $atribut(['nip', 'gender', 'address']),
            ),
            Role::Supervisor => SupervisorProfile::updateOrCreate(
                ['user_id' => $user->id],
                $atribut(['staff_code', 'address']),
            ),
            default => null,
        };
    }

    /**
     * Ganti username akun yang sudah ada.
     *
     * =====================================================================
     * Mengapa ini ada, padahal username sengaja diturunkan dari NIS/NIP
     * =====================================================================
     * Karena NIS dan NIP memang salah ketik. Siswa yang NIS-nya terlanjur
     * dimasukkan sebagai `20240017` padahal kartunya berbunyi `20240071`
     * mendapat username yang salah, dan ia akan menghafal nomor yang salah
     * itu selama satu tahun ajaran. Selama tidak ada jalan memperbaikinya,
     * satu-satunya cara adalah menonaktifkan akun lalu membuat yang baru -
     * dan itu memutus riwayat ujian, presensi, serta jawabannya.
     *
     * Yang TIDAK dilakukan fungsi ini: menurunkan username ulang dari nomor
     * identitas. Adminlah yang mengetik username barunya, dan itu disengaja.
     * Memperbaiki NIS tidak boleh diam-diam mengubah username, karena username
     * tertempel di kartu login dan setiap siswa yang sudah menghafalnya akan
     * terkunci pada hari ujian.
     *
     * =====================================================================
     * Mengapa normanya diperiksa DI SINI, bukan di form
     * =====================================================================
     * Username adalah satu-satunya identitas yang diketik saat login, dan ia
     * unik GLOBAL - bentrokan antar sekolah tidak akan terlihat dari layar
     * satu sekolah. Aturan bentuknya karena itu harus hidup di tempat yang
     * sama dengan pemeriksaan ketersediaannya; form yang memeriksanya sendiri
     * hanya akan sepakat dengan server sampai keduanya berbeda diam-diam.
     *
     * Yang ditolak, dan alasannya masing-masing:
     * - spasi di tengah: tidak terlihat di layar login, mustahil diketik ulang
     *   dengan benar dari kartu;
     * - huruf besar: bentuk yang didokumentasikan blueprint adalah huruf
     *   kecil, dan `2024001` vs `2024001` yang berbeda hanya pada kasus akan
     *   tampak identik saat dicetak;
     * - karakter selain huruf, angka, dan titik: `/`, `\`, `@`, dan `+` punya
     *   arti di jalur URL dan di surel, dan username yang memuatnya akan
     *   rusak di salah satu tempat itu tanpa galat.
     *
     * @return string Username LAMA, supaya pemanggil bisa mencatatnya di log.
     *
     * @throws \InvalidArgumentException Bila bentuknya tidak sah atau sudah terpakai.
     */
    public function gantiUsername(User $user, string $username): string
    {
        $baru = strtolower(trim($username));

        if (preg_match('/^[a-z0-9][a-z0-9.]*$/', $baru) !== 1) {
            throw new \InvalidArgumentException(
                'Username hanya boleh berisi huruf, angka, dan titik, serta tidak boleh diawali titik.'
            );
        }

        if (mb_strlen($baru) < 3) {
            throw new \InvalidArgumentException('Username minimal 3 karakter.');
        }

        // Melewati tenant, seperti `usernameDari`: yang dicegah adalah
        // bentrokan ANTAR sekolah, dan query yang ter-scope tidak akan pernah
        // melihat baris sekolah lain - sehingga ia melaporkan "bebas" untuk
        // username yang sudah dipakai, lalu gagal di tingkat basis data.
        //
        // Akun itu sendiri dikecualikan: menyimpan tanpa mengubah username
        // adalah hal yang wajar, dan menolaknya berarti form yang tidak
        // mengubah apa pun tetap berbunyi sebagai galat.
        $terpakai = User::tanpaTenant()
            ->where('username', $baru)
            ->whereKeyNot($user->getKey())
            ->exists();

        if ($terpakai) {
            throw new \InvalidArgumentException(
                'Username '.$baru.' sudah dipakai akun lain. Username dipakai lintas sekolah, '
                .'jadi bentrokannya bisa datang dari sekolah lain.'
            );
        }

        $lama = $user->username;

        $user->forceFill(['username' => $baru])->save();

        return $lama;
    }

    /**
     * Aktifkan/nonaktifkan sekumpulan akun.
     *
     * @param  array<int, int>  $id
     * @return int Jumlah baris yang berubah.
     */
    public function setAktif(array $id, bool $aktif): int
    {
        // Scope tetap berlaku: id milik sekolah lain tidak akan cocok di sini,
        // dan permintaan itu berakhir sebagai "0 baris berubah" - bukan galat
        // yang membocorkan bahwa barisnya ada.
        return User::query()->whereIn('id', $id)->update(['is_active' => $aktif]);
    }

    /**
     * Nomor identitas yang dipakai sebagai dasar username, sesuai peran.
     *
     * Peran di sini bisa datang dalam dua bentuk, dan itu bukan kelonggaran
     * yang disengaja: Form Request memvalidasi dengan Rule::enum, sehingga
     * nilaianya masih string mentah dari request, sementara tempat lain
     * (seeder, importer) sudah memegang enum-nya. Menormalkan di sini -
     * bukan memaksa pemanggil - menutup satu kelas bug yang sulit terlihat:
     * match atas string 'student' tidak cocok dengan Role::Student, jatuh ke
     * default, dan setiap siswa diam-diam mendapat username 'pengguna'.
     *
     * @param  array<string, mixed>  $data
     */
    public function nomorIdentitas(array $data): string
    {
        $peran = $data['role'] instanceof Role
            ? $data['role']
            : Role::tryFrom((string) ($data['role'] ?? ''));

        return match ($peran) {
            Role::Student => (string) ($data['nis'] ?? $data['nisn'] ?? ''),
            Role::Teacher => (string) ($data['nip'] ?? ''),
            Role::Supervisor => (string) ($data['staff_code'] ?? ''),
            default => '',
        };
    }

    /**
     * Buang karakter yang menyulitkan pengetikan di depan pintu atau di HP:
     * spasi, titik, dan tanda hubung yang tidak sengaja ikut tersalin dari
     * berkas Excel.
     *
     * Hurufnya juga diturunkan menjadi kecil. Alasannya bukan selera: bentuk
     * yang didokumentasikan blueprint adalah `smkn1.2024001`, dan NIP sering
     * ditulis dengan huruf besar di berkas kepegawaian. Tanpa penurunan ini,
     * satu sekolah yang mengimpor NIP berhuruf besar akan punya akun
     * `SMKN1.1987654321`, sementara layar login menampilkan aturan yang
     * berbeda dari kenyataan - dan siswa yang mengetik ulang dari kartunya
     * tidak akan pernah tahu mengapa gagal.
     */
    private function bersihkan(string $nilai): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nilai) ?? '');
    }
}
