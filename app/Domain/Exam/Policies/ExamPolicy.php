<?php

namespace App\Domain\Exam\Policies;

use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamClass;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\ExamToken;
use App\Domain\Exam\Models\ExamTokenView;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Siapa yang boleh mengelola ujian, sesi, penugasan, dan token.
 *
 * ---------------------------------------------------------------------
 * Satu policy untuk delapan model
 * ---------------------------------------------------------------------
 * Alasannya sama persis dengan EventPolicy: aturannya identik untuk
 * kedelapannya - "Admin mengelola, yang lain tidak" - dan aturan identik yang
 * ditulis delapan kali adalah delapan tempat yang bisa berbeda diam-diam.
 *
 * Yang membedakan akses bukan modelnya. Untuk baca, yang menentukan adalah
 * apakah pengguna berhak atas EVENT yang menaungi baris itu.
 *
 * ---------------------------------------------------------------------
 * Mengapa baca didelegasikan ke eventnya, bukan diperiksa ulang
 * ---------------------------------------------------------------------
 * PRD §3.5 menegaskan Guru "tidak mengelola akun pengguna, ruang, jadwal,
 * token, atau penugasan Pengawas". Jadi guru memang TIDAK boleh mengubah
 * apa pun di sini.
 *
 * Tetapi guru yang memegang penugasan mengajar pada satu event harus bisa
 * MELIHAT jadwal ujiannya - kalau tidak, ia tidak bisa tahu kapan mengajar,
 * dan satu-satunya jalan keluar adalah bertanya ke ruang guru. Karena itu
 * jalur bacanya diteruskan ke `view` milik eventnya, yang sudah memeriksa
 * kepanitiaan dan penugasan mengajar.
 *
 * Mendelegasikan, bukan menyalin: menyalin aturan event ke sini berarti
 * Fase 4 (portal Guru) harus memperbaiki dua tempat, dan yang kedua akan
 * terlupakan.
 *
 * ---------------------------------------------------------------------
 * school_id tidak diperiksa di sini
 * ---------------------------------------------------------------------
 * Alasan yang sama dengan seluruh policy lain: SchoolScope sudah memastikan
 * setiap baris yang sampai ke sini berasal dari sekolah pemanggilnya.
 * Menambahkan $user->school_id === $model->school_id justru menghadirkan
 * kembali kebocoran 403 - ia membuktikan barisnya ada, padahal jawaban yang
 * benar adalah 404.
 */
class ExamPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Teacher, Role::Supervisor], true);
    }

    /**
     * Baca: siapa pun yang boleh melihat eventnya boleh melihat isinya.
     *
     * Model apa pun dari kedelapan model ini bisa sampai ke sini; yang
     * dicari eventnya, dan setiap model punya jalannya sendiri menuju ke
     * sana - ujian langsung, sisanya lewat induknya.
     */
    public function view(User $user, Model $model): bool
    {
        $event = $this->eventDari($model);

        if ($event === null) {
            return false;
        }

        return $user->can('view', $event);
    }

    /** Mengelola jadwal, sesi, ruang sesi, penempatan, penugasan, dan token. */
    public function create(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function update(User $user, Model $model): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Menghapus ujian atau sesi.
     *
     * Terbuka bagi Admin dan sengaja tidak dipakai lewat UI untuk ujian yang
     * sudah pernah berjalan: sesi yang dibatalkan sudah punya jawabannya -
     * Batal. Alasan yang sama dengan EventPolicy::delete.
     */
    public function delete(User $user, Model $model): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Menerbitkan dan mencabut token.
     *
     * Dipisahkan dari update karena bobotnya berbeda, seperti ubahStatus di
     * EventPolicy: mengubah jendela sesi tidak sama dengan menerbitkan
     * kredensial yang akan dibacakan ke tiga ratus siswa.
     */
    public function kelolaToken(User $user, Exam $exam): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Membaca token dalam bentuk terbaca - dan setiap pembacaan tercatat.
     *
     * Pengawas ikut di sini, dan itu keputusan yang sadar: PRD §9.7 mengakui
     * token harus bisa dibacakan pengawas di ruangnya. Yang menjaga bukan
     * larangan membacanya, melainkan pencatatan siapa yang membacanya -
     * lihat TokenSesi::bacaBerwenang.
     */
    public function bacaToken(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Supervisor], true);
    }

    /**
     * Event yang menaungi satu baris, apa pun jenis barisnya.
     */
    private function eventDari(Model $model): ?SchoolEvent
    {
        return match (true) {
            $model instanceof Exam => $model->event,
            $model instanceof ExamSession => $model->exam?->event,
            $model instanceof ExamClass => $model->exam?->event,
            $model instanceof ExamSessionRoom => $model->session?->exam?->event,
            $model instanceof ExamParticipant => $model->exam?->event,
            $model instanceof SupervisionAssignment => $model->room?->session?->exam?->event,
            $model instanceof ExamToken => $model->session?->exam?->event,
            $model instanceof ExamTokenView => $model->token?->session?->exam?->event,
            default => null,
        };
    }
}
