<?php

namespace App\Domain\Academic\Policies;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Identity\Models\User;

/**
 * Master data akademik: siapa yang boleh mengelola.
 *
 * ---------------------------------------------------------------------
 * Apa yang TIDAK diperiksa di sini
 * ---------------------------------------------------------------------
 * school_id. Itu bukan kelalaian - itu aturan yang harus bertahan. SchoolScope
 * sudah memastikan setiap model yang sampai ke sini berasal dari sekolah
 * pemanggilnya; baris sekolah lain tidak pernah ada untuknya.
 *
 * Policy yang menambahkan $user->school_id === $model->school_id justru
 * merusak jaminan itu: pemeriksaan tersebut hanya bisa tercapai bila barisnya
 * ADA, dan keberadaannya lalu terbukti lewat 403 - padahal jawaban yang benar
 * adalah 404, seolah barisnya memang tidak pernah ada.
 *
 * Yang tersisa untuk diperiksa di sini hanya satu hal: peran. Admin mengelola
 * master data; peran lain membacanya sesuai kebutuhan mereka.
 *
 * ---------------------------------------------------------------------
 * Mengapa satu Policy untuk empat model
 * ---------------------------------------------------------------------
 * Keempat model ini berbagi aturan yang sama persis, dan aturan yang identik
 * di empat tempat adalah empat tempat yang bisa berbeda diam-diam. Ketika
 * Fase 4 menambahkan Guru yang boleh melihat mapelnya sendiri, perubahannya
 * terjadi di satu berkas, bukan dicari ke seluruh modul.
 */
class AcademicPolicy
{
    public function viewAny(User $user): bool
    {
        // Guru perlu melihat kelas, mapel, dan penugasannya - di situlah
        // batas bank soalnya. Melarangnya membaca berarti Fase 4 harus
        // membuka izin yang sebenarnya sudah ada.
        return in_array($user->role->value, ['admin', 'teacher'], true);
    }

    public function view(User $user, AcademicYear|SchoolClass|Subject|TeachingAssignment $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function update(User $user, AcademicYear|SchoolClass|Subject|TeachingAssignment $model): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Menghapus master data hampir selalu jawaban yang salah.
     *
     * Tahun ajaran ditutup, kelas dinonaktifkan, mapel diarsipkan. Menghapus
     * baris yang sudah dipakai event lama berarti memutus riwayat - dan
     * riwayat itu justru yang harus bisa ditelusuri setelah ujian selesai.
     */
    public function delete(User $user, AcademicYear|SchoolClass|Subject|TeachingAssignment $model): bool
    {
        return false;
    }
}
