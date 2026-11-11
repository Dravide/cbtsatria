<?php

namespace App\Domain\Grading\Support;

use App\Domain\Exam\Models\Exam;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ujian yang boleh disentuh seorang Guru - satu tempat, dipakai tiga laman.
 *
 * ---------------------------------------------------------------------
 * Mengapa satu kelas pembantu, bukan tiga kueri
 * ---------------------------------------------------------------------
 * Plate 06, 07, dan 08 adalah tiga layar dengan pertanyaan yang sama di
 * baliknya: "ujian mana yang menjadi urusan saya". Menuliskannya tiga kali
 * berarti tiga tempat yang bisa berbeda - dan yang berbeda akan terlihat
 * sebagai Guru yang bisa mengoreksi ujian yang tidak muncul di daftar
 * nilainya.
 *
 * Aturannya sama persis dengan `GradingPolicy::mengampuUjian()`: penugasan
 * EVENT lebih dulu, lalu penugasan MENGAJAR. Yang dibedakan hanya bentuknya
 * (kueri, bukan pemeriksaan satu attempt), dan itu bukan duplikasi aturan -
 * `GradingPolicy` tetap yang memutuskan izin satu baris; kelas ini menyusun
 * DAFTARNYA supaya layar tidak menawarkan yang akan ditolak.
 *
 * ---------------------------------------------------------------------
 * Ujian yang belum punya attempt tetap muncul
 * ---------------------------------------------------------------------
 * Plate 08 (analisis butir) dan plate 07 (nilai kelas) sama-sama dibuka
 * SEBELUM ujiannya selesai, dan daftar yang kosong sampai ada jawaban masuk
 * akan membuat Guru mengira ia tidak ditugaskan. Penyaringan "sudah ada
 * jawabannya" terjadi di dalam laman masing-masing, bukan di sini.
 *
 * ---------------------------------------------------------------------
 * `event` sengaja TIDAK dipersempit kolomnya
 * ---------------------------------------------------------------------
 * `with('event:id,name')` terlihat hemat, tetapi model `SchoolEvent` yang
 * setengah termuat menjawab `null` untuk setiap kolom yang tidak dipilih -
 * dan `SchoolEvent::koreksiTerkunci(): bool` lalu melempar TypeError, bukan
 * mengembalikan `false`. Gejalanya adalah halaman koreksi yang rusak total,
 * dan penyebabnya satu daftar kolom yang terlihat tidak berbahaya.
 *
 * Kalau daftar itu dipersempit lagi, kolom berikut harus ikut: `gradings_locked`
 * (`koreksiTerkunci()`), `results_published_at` (`hasilTerbit()`),
 * `results_announced_at`, `show_objectives`, `show_explanations`. Daftar itu
 * akan bertambah setiap kali `SchoolEvent` mendapat accessor baru, dan satu
 * kelalaian mengulangi galat yang sama. Baris event adalah satu per event -
 * memuat seluruhnya tidak menambah satu kueri pun, hanya beberapa kolom.
 */
class UjianGuru
{
    /**
     * @return Builder<Exam>
     */
    public static function untuk(int $guruId): Builder
    {
        return Exam::query()
            ->with(['subject:id,name', 'event'])
            ->where(function (Builder $q) use ($guruId) {
                // Penugasan event - yang paling sempit dan paling langsung.
                $q->whereIn('subject_id', function ($sub) use ($guruId) {
                    $sub->select('subject_id')
                        ->from('event_teacher_assignments')
                        ->where('teacher_id', $guruId)
                        ->whereColumn('event_teacher_assignments.event_id', 'exams.event_id');
                })
                    // Cadangan: Guru yang mengampu mapelnya tetap bisa membuka
                    // laman ini meski Admin belum sempat menugaskannya di
                    // event. Lihat catatan kelas GradingPolicy.
                    ->orWhereIn('subject_id', function ($sub) use ($guruId) {
                        $sub->select('subject_id')
                            ->from('teaching_assignments')
                            ->where('teacher_id', $guruId);
                    });
            });
    }

    /**
     * Satu ujian, bila memang urusan Guru ini - atau 404.
     *
     * 404, bukan 403: ujian sekolah lain dan ujian mapel lain adalah dua hal
     * yang sama bagi Guru ini, yaitu tidak ada. 403 akan memberi tahu bahwa
     * id itu menunjuk ujian yang benar-benar ada.
     */
    public static function satu(int $guruId, int $examId): Exam
    {
        return self::untuk($guruId)->whereKey($examId)->firstOrFail();
    }
}
