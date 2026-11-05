<?php

namespace App\Domain\Event\Policies;

use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;

/**
 * Siapa yang boleh mengelola event.
 *
 * ---------------------------------------------------------------------
 * Apa yang TIDAK diperiksa di sini
 * ---------------------------------------------------------------------
 * school_id. Alasan yang sama dengan AcademicPolicy: SchoolScope sudah
 * memastikan setiap event yang sampai ke sini berasal dari sekolah
 * pemanggilnya. Policy yang menambahkan $user->school_id === $event->school_id
 * justru menghadirkan kembali kebocoran 403 - ia membuktikan barisnya ada,
 * padahal jawaban yang benar adalah 404, seolah eventnya tidak pernah ada.
 *
 * ---------------------------------------------------------------------
 * Cakupan panitia diperiksa di sini, bukan di policy terpisah
 * ---------------------------------------------------------------------
 * PRD §6.6 menegaskan kepanitiaan bukan role autentikasi baru. Cakupan
 * karena itu bukan peran kelima, melainkan syarat tambahan di atas peran
 * yang sudah ada - dan syarat tambahan yang membatasi satu model memang
 * tempatnya di policy model itu.
 *
 * Fase 3 belum membangun portal panitia; yang dibangun di sini adalah
 * pemeriksaannya, supaya Fase 5 tidak perlu membuka izin yang seharusnya
 * sudah tertutup.
 */
class EventPolicy
{
    public function viewAny(User $user): bool
    {
        // Guru dan Pengawas perlu melihat event yang ditugaskan kepadanya.
        // Melarangnya membaca berarti Fase 4-6 harus membuka izin yang
        // sebenarnya sudah ada di sini.
        return in_array($user->role, [
            Role::Admin,
            Role::Teacher,
            Role::Supervisor,
        ], true);
    }

    public function view(User $user, SchoolEvent $event): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->role->isAdmin()) {
            return true;
        }

        // Non-admin hanya melihat event yang benar-benar ditugaskan
        // kepadanya. Ini yang membuat "Guru hanya memperoleh akses ke tugas
        // yang secara eksplisit diberikan kepadanya" (PRD §6.8) berlaku
        // untuk eventnya, bukan hanya untuk mapelnya.
        return $event->kepanitiaan($user) !== null
            || $event->teacherAssignments()->where('teacher_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function update(User $user, SchoolEvent $event): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Menghapus event adalah operasi tersendiri, dan Fase 3 tidak
     * menyediakannya lewat UI.
     *
     * Alasannya bukan belum sempat: event yang salah dibuat sudah punya
     * jawabannya - Batal. Menghapusnya keras berarti menghapus pula jejak
     * bahwa ia pernah ada, dan justru jejak itulah yang membuat pertanyaan
     * "kenapa UAS tahun ini tidak jalan" bisa dijawab. Metodenya tetap ada
     * dan hanya terbuka bagi Admin, supaya keputusan itu bisa diambil
     * sengaja, bukan karena kebetulan ada tombolnya.
     */
    public function delete(User $user, SchoolEvent $event): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Mengubah status.
     *
     * Dipisahkan dari update karena bobotnya berbeda: mengubah nama event
     * tidak sama dengan memindahkannya ke Berjalan, yang berarti peserta
     * sedang mengerjakan. Fase 5 memakai gerbang yang sama untuk transisi
     * otomatis.
     */
    public function ubahStatus(User $user, SchoolEvent $event): bool
    {
        return $user->role->isAdmin();
    }
}
