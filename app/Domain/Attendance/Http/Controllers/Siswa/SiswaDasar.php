<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Services\NomorKursi;
use Illuminate\Http\Request;

/**
 * Dasar seluruh laman siswa - siapa yang boleh melihat apa.
 *
 * ---------------------------------------------------------------------
 * Kepemilikan baris diperiksa di SATU tempat, dan jawabannya 404
 * ---------------------------------------------------------------------
 * Setiap rute siswa memuat `{peserta}` - id `exam_participants`. Model itu
 * di-bind implisit dan sudah tersaring tenant, tetapi ia TIDAK tersaring
 * per-siswa: `exam_participants` milik seluruh sekolah terlihat oleh siapa
 * pun yang punya konteks sekolahnya, dan seluruh siswa satu sekolah
 * konteksnya sama.
 *
 * Karena itu satu pemeriksaan tambahan diperlukan di setiap laman, dan
 * menuliskannya di enam controller berarti enam tempat yang bisa lupa.
 * Yang dipakai di sini adalah pola yang sama dengan KonteksRuang: barisnya
 * dicari LEWAT kepemilikan itu sendiri, sehingga penempatan milik anak lain
 * tampak TIDAK ADA.
 *
 * Jawabannya 404, bukan 403. 403 pada laman ini akan memberi tahu seorang
 * siswa bahwa id penempatan tertentu benar-benar ada - dan daftar id yang
 * ada adalah separuh pekerjaan untuk menebak yang lain.
 *
 * ---------------------------------------------------------------------
 * Attempt TERAKHIR, bukan attempt berjalan
 * ---------------------------------------------------------------------
 * Hampir setiap laman di sini menanyakan "apa yang terakhir terjadi pada
 * pengerjaan anak ini", dan jawabannya harus tetap ada SESUDAH ujiannya
 * selesai - plate 27 dan 26 menggambar keadaan sesudah dikumpulkan, dan
 * plate 25 menggambar yang terkunci. Helper ini karena itu mengembalikan
 * attempt bernomor terbesar, apa pun statusnya, dan yang memutuskan apa
 * artinya adalah laman masing-masing.
 */
abstract class SiswaDasar
{
    /**
     * Penempatan milik siswa yang sedang masuk, atau 404.
     */
    protected function milikSaya(ExamParticipant $peserta): ExamParticipant
    {
        if ((int) $peserta->student_id !== (int) auth()->id()) {
            abort(404);
        }

        return $peserta;
    }

    /** Attempt terakhir pada satu penempatan, bila ada. */
    protected function attemptTerakhir(ExamParticipant $peserta): ?ExamAttempt
    {
        return ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->orderByDesc('attempt_number')
            ->first();
    }

    /**
     * Nomor kursi siswa di ruangnya.
     *
     * Dihitung dari urutan penempatan di ruang itu, dan BUKAN dari kolom -
     * tabelnya memang tidak punya kolom kursi. Aturan urutannya ada di
     * `NomorKursi`, dan itu SATU aturan yang sama dengan yang dipakai daftar
     * hadir pengawas, denah, dan tablo.
     *
     * Sebelum 2026-11-09 method ini mengurutkan sendiri dengan `orderBy('id')`
     * sambil menyatakan di docblock-nya bahwa urutannya sama dengan papan
     * pengawas. Itu tidak benar - papan pengawas mengurutkan dengan abjad
     * nama - sehingga nomor di ponsel anak bisa berbeda dari nomor yang
     * dibacakan pengawas di ruang yang sama.
     *
     * @return int|null Null bila siswa ini belum ditempatkan di ruang mana pun.
     */
    protected function nomorKursi(ExamParticipant $peserta): ?int
    {
        return app(NomorKursi::class)->satu($peserta);
    }

    /** @return array<string, mixed> */
    protected function ringkasPeserta(ExamParticipant $peserta): array
    {
        $ujian = $peserta->exam;
        $sesi = $peserta->session;

        return [
            'peserta_id' => $peserta->id,
            'ujian' => $ujian?->title,
            'mapel' => $ujian?->subject?->name,
            'durasi' => $sesi?->durasiMenit(),
            'sesi' => $sesi?->label(),
            'mulai' => $sesi?->access_opens_at?->toIso8601String(),
            'berakhir' => $sesi?->hard_end_at?->toIso8601String(),
            'ruang' => $peserta->room?->eventRoom?->room?->code,
            'ruang_nama' => $peserta->room?->eventRoom?->room?->name,
            'kursi' => $this->nomorKursi($peserta),
        ];
    }

    /** Identitas peramban yang dikirim setiap laman siswa. */
    protected function peramban(Request $request): array
    {
        return [
            'ip' => $request->ip(),
        ];
    }
}
