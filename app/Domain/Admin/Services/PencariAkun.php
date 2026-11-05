<?php

namespace App\Domain\Admin\Services;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\SupervisorProfile;
use App\Domain\Identity\Models\TeacherProfile;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * Mencari akun lama yang cocok dengan satu baris impor.
 *
 * =====================================================================
 * `nip` dan `staff_code` TIDAK unik, dan itu bukan detail
 * =====================================================================
 * Diperiksa langsung di `0002_01_01_000005_create_profile_tables.php`:
 * `teacher_profiles` dan `supervisor_profiles` hanya punya
 * `unique(['school_id','user_id'])`. Hanya `student_profiles` yang punya
 * `unique(school_id, nis)` + `unique(school_id, nisn)`.
 *
 * Konsekuensinya keras: `where('nip', …)->first()` bisa MEMPERBARUI ORANG
 * YANG SALAH. Dan kalau itu terjadi, kerusakannya tidak muncul di layar Admin
 * yang mengimpor - ia muncul di layar login orang lain, berhari-hari
 * kemudian, sebagai nama yang berubah.
 *
 * Karena itu method di sini mengembalikan SELURUH kandidat, dan pemanggilnya
 * yang memutuskan: `count > 1` berarti barisnya GAGAL dengan pesan yang
 * menyuruh Admin merapikan daftar pengguna lebih dulu. Tidak ada `first()` di
 * kelas ini, dan itu disengaja.
 *
 * =====================================================================
 * Siswa dicari lewat `nis` ATAU `nisn`
 * =====================================================================
 * Dua-duanya identitas siswa, dan berkas dari sekolah yang berbeda memakai
 * salah satunya. Mencari hanya lewat `nis` berarti sekolah yang mengisi
 * `nisn` saja tidak pernah menemukan akun lamanya - dan setiap unggahan
 * berikutnya membuat akun baru.
 *
 * Karena itu kandidatnya bisa dua baris BERBEDA: satu siswa yang `nis`-nya
 * cocok, dan siswa lain yang `nisn`-nya cocok. Itu keadaan yang memang gagal -
 * NIS satu orang tidak boleh menunjuk NISN orang lain - dan pesan `count > 1`
 * sudah tepat untuknya.
 */
class PencariAkun
{
    /**
     * Akun yang cocok dengan nomor identitas ini, sesuai perannya.
     *
     * @return Collection<int, User>
     */
    public function cari(Role $peran, string $nomor, int $schoolId): Collection
    {
        $nomor = trim($nomor);

        if ($nomor === '' || $peran === Role::Admin) {
            return collect();
        }

        // `withoutGlobalScopes()` - bukan kelonggaran. Scope sekolah menyala
        // hanya saat ada pengguna terautentikasi, dan pencarian ini memang
        // selalu berjalan di dalam permintaan Admin; tetapi sekolahnya
        // disebut EKSPLISIT di bawah supaya hasilnya tidak bergantung pada
        // apakah scope-nya kebetulan sedang menyala.
        return match ($peran) {
            Role::Student => User::query()
                ->withoutGlobalScopes()
                ->where('users.school_id', $schoolId)
                ->where('users.role', Role::Student)
                ->whereIn('users.id', $this->idSiswa($nomor, $schoolId))
                ->get(),

            Role::Teacher => $this->lewatProfil(
                TeacherProfile::class,
                'nip',
                $nomor,
                $schoolId,
                Role::Teacher,
            ),

            Role::Supervisor => $this->lewatProfil(
                SupervisorProfile::class,
                'staff_code',
                $nomor,
                $schoolId,
                Role::Supervisor,
            ),

            default => collect(),
        };
    }

    /**
     * Id siswa yang `nis` atau `nisn`-nya cocok.
     *
     * @return array<int, int>
     */
    private function idSiswa(string $nomor, int $schoolId): array
    {
        return StudentProfile::query()
            // Profil memakai SchoolScope juga, dan yang menentukan di sini
            // adalah sekolah yang diminta pemanggilnya.
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where(fn ($q) => $q->where('nis', $nomor)->orWhere('nisn', $nomor))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Kandidat lewat kolom profil guru/pengawas.
     *
     * @param  class-string  $kelasProfil
     * @return Collection<int, User>
     */
    private function lewatProfil(
        string $kelasProfil,
        string $kolom,
        string $nomor,
        int $schoolId,
        Role $peran,
    ): Collection {
        $id = $kelasProfil::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where($kolom, $nomor)
            ->pluck('user_id')
            ->map(fn ($satu) => (int) $satu)
            ->all();

        if ($id === []) {
            return collect();
        }

        return User::query()
            ->withoutGlobalScopes()
            ->where('users.school_id', $schoolId)
            ->where('users.role', $peran)
            ->whereIn('users.id', $id)
            ->get();
    }
}
