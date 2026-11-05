<?php

namespace App\Domain\Question\Policies;

use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionPackage;

/**
 * Siapa yang boleh melihat dan mengubah bank soal.
 *
 * =====================================================================
 * Dua izin yang berbeda, dan dua kode jawaban yang berbeda
 * =====================================================================
 *
 *   LIHAT   Admin sekolah itu, atau Guru yang punya TeachingAssignment
 *           pada subjek soal          -> gagal = 404
 *
 *   UBAH    created_by === user->id   -> gagal = 403
 *
 * ---------------------------------------------------------------------
 * Mengapa bedanya bukan kerapian
 * ---------------------------------------------------------------------
 * Bank soal kini BERSAMA (keputusan PRD §24 #5). Guru A melihat soal Guru B -
 * dua-duanya mengajar Matematika - dan boleh memakainya di paketnya. Karena
 * soalnya terlihat di daftar, jawaban "seolah tidak ada" adalah jawaban yang
 * membuat Guru mengira datanya hilang: ia baru saja melihat barisnya, lalu
 * membuka tautannya dan menerima 404.
 *
 * Karena itu pembagiannya: yang tidak berhak MELIHAT menerima 404 (barisnya
 * memang bukan miliknya untuk dilihat - dan bagi Guru dari mapel lain, ia
 * memang tidak muncul di daftar), sedangkan yang berhak melihat tetapi tidak
 * berhak mengubah menerima 403 (barisnya ada, dan pesannya adalah "bukan
 * milikmu untuk diubah" - kalimat yang bisa ditindaklanjuti).
 *
 * ---------------------------------------------------------------------
 * Admin TIDAK mengubah isi bank soal (PRD §3.1)
 * ---------------------------------------------------------------------
 * `update()` dan `delete()` menolak Admin yang bukan penulisnya, dan itu
 * ditegakkan DI SINI, bukan di UI. Menyembunyikan tombolnya saja berarti
 * Admin yang mengirim permintaan langsung tetap bisa menyunting soal Guru -
 * dan soal yang disunting Admin adalah soal yang tiba-tiba berbeda dari yang
 * ditulis Gurunya, tanpa satu pun baris log yang menjelaskan mengapa.
 *
 * Admin tetap bisa melihat semuanya, dan tetap bisa memasang paket ke event
 * lewat EventPolicy - pemasangan bukan penyuntingan isi soal.
 *
 * ---------------------------------------------------------------------
 * Apa yang TIDAK diperiksa di sini
 * ---------------------------------------------------------------------
 * school_id. Alasan yang sama dengan EventPolicy: SchoolScope sudah memastikan
 * setiap baris yang sampai ke sini berasal dari sekolah pemanggilnya. Policy
 * yang menambahkan `$user->school_id === $soal->school_id` justru
 * menghadirkan kembali kebocoran 403 - ia membuktikan barisnya ada, padahal
 * jawaban yang benar adalah 404, seolah soalnya tidak pernah ada.
 */
class QuestionPolicy
{
    /**
     * Daftar bank soal.
     *
     * Ketiga peran non-siswa boleh membuka lamannya; yang membatasi ISI
     * daftarnya adalah kueri di controller (hanya mapel yang diampu), bukan
     * gerbang ini. Memindahkan pembatasan itu ke sini berarti setiap Guru
     * yang mengajar satu mapel saja menerima 403 alih-alih daftar berisi.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Teacher], true);
    }

    /**
     * Melihat satu soal.
     *
     * Admin melihat semua - PRD §3.1 memberinya "lihat semua". Guru melihat
     * soal dari mapel yang ia ampu, dan itulah yang membuat bank soal
     * berguna: satu mapel, beberapa Guru, satu kumpulan soal.
     */
    public function view(User $user, Question $soal): bool
    {
        if ($user->role->isAdmin()) {
            return true;
        }

        if ($user->role !== Role::Teacher) {
            return false;
        }

        // Soal yang belum bertuan (created_by null) tetap boleh dilihat
        // penulisnya sendiri lewat cabang di bawah - dan cabang ini hanya
        // memeriksa mapelnya.
        return $this->mengampu($user, (int) $soal->subject_id);
    }

    /**
     * Menyusun soal baru.
     *
     * Guru saja. PRD §3.1 menulis Admin "Tidak" untuk bank soal, dan itu
     * konsisten dengan seluruh halaman ini: Admin merangkai event, Guru
     * menyusun soalnya.
     */
    public function create(User $user): bool
    {
        return $user->role === Role::Teacher;
    }

    /**
     * Mengubah soal - hanya penulisnya.
     *
     * Admin sengaja TIDAK termasuk, meski ia bisa melihat soal itu. Lihat
     * catatan kelas ini.
     */
    public function update(User $user, Question $soal): bool
    {
        return $this->penulisnya($user, $soal);
    }

    /**
     * Menghapus soal - hanya penulisnya.
     *
     * Dipisahkan dari update meski aturannya identik, karena bobotnya
     * berbeda dan Fase 8 bisa saja memisahkannya (mis. melarang hapus total
     * dan hanya mengizinkan arsip). Signature terpisah membuat pemisahan itu
     * tidak menuntut perubahan di pemanggil.
     */
    public function delete(User $user, Question $soal): bool
    {
        return $this->penulisnya($user, $soal);
    }

    /**
     * Menduplikat soal menjadi salinan MILIK PENGGUNA.
     *
     * Ini jalan keluar yang disediakan sengaja: Guru A yang ingin memakai
     * soal Guru B dengan perubahan tidak perlu meminta izin - ia menduplikatnya,
     * dan salinannya miliknya sendiri. Tanpa ini, satu-satunya alternatifnya
     * adalah meminta Admin membuka izin, dan itu jalur yang tidak bisa
     * diselesaikan Guru sendirian di ruang guru lima menit sebelum ujian.
     */
    public function duplikat(User $user, Question $soal): bool
    {
        return $this->view($user, $soal) && $user->role === Role::Teacher;
    }

    /* --------------------------------------------------------------- Paket */

    public function viewPaket(User $user, QuestionPackage $paket): bool
    {
        if ($user->role->isAdmin()) {
            return true;
        }

        if ($user->role !== Role::Teacher) {
            return false;
        }

        return (int) $paket->created_by === (int) $user->id
            || $this->mengampu($user, (int) $paket->subject_id);
    }

    /**
     * Mengubah paket.
     *
     * Lebih ketat daripada soal, dan alasannya berbeda: paket dipakai UTUH.
     * Paket yang isinya diubah Guru lain setelah dipasang Admin ke sebuah
     * event adalah paket yang tidak lagi sama dengan yang disetujui - dan
     * ujiannya sudah dijadwalkan.
     */
    public function ubahPaket(User $user, QuestionPackage $paket): bool
    {
        return $user->role === Role::Teacher
            && (int) $paket->created_by === (int) $user->id;
    }

    /**
     * Mengimpor soal.
     *
     * Guru saja, sesuai PRD §3.1. Importir membawa 50 soal sekaligus, dan
     * yang menanggung akibatnya bila salah adalah bank soal satu mapel -
     * sehingga izinnya sengaja disamakan dengan izin menyusun soal, bukan
     * dengan izin memasang paket.
     */
    public function impor(User $user): bool
    {
        return $user->role === Role::Teacher;
    }

    /* ------------------------------------------------------------- Pembantu */

    private function penulisnya(User $user, Question $soal): bool
    {
        return $user->role === Role::Teacher
            && (int) $soal->created_by === (int) $user->id;
    }

    /**
     * Apakah Guru ini punya penugasan mengajar pada mapel tersebut.
     *
     * Sumber kebenaran izin mengajar tetap SATU: tabel yang diisi Admin di
     * /admin/mapel (plate 08). Tidak ada daftar kedua di laman Guru - dan itu
     * keputusan yang sudah tertulis di docblock TeachingAssignment dan
     * SubjectController sebelum Fase 4 dimulai.
     */
    private function mengampu(User $user, int $subjectId): bool
    {
        return TeachingAssignment::query()
            ->where('teacher_id', $user->id)
            ->where('subject_id', $subjectId)
            ->exists();
    }
}
