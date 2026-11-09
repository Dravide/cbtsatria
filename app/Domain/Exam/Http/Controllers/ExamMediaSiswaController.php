<?php

namespace App\Domain\Exam\Http\Controllers;

use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Question\Models\QuestionMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Media soal yang dibaca SISWA yang sedang mengerjakan - wireframe plate 23.
 *
 * =====================================================================
 * Mengapa rute ini ada, padahal `/guru/berkas/{media}` sudah ada
 * =====================================================================
 * `MediaSoalController` menjaga berkasnya dengan `Gate::allows('view', $soal)`,
 * dan `QuestionPolicy::view()` bertanya "apakah Guru ini mengampu mapel soal
 * itu" lewat `TeachingAssignment`. Siswa TIDAK punya penugasan mengajar, dan
 * tidak akan pernah punya - sehingga seluruh soal bergambar di plate 23
 * berakhir 404 di layar peserta kalau rute itu yang dipakai.
 *
 * Yang tidak boleh dilakukan adalah melonggarkan `QuestionPolicy` supaya
 * Siswa lolos: policy itu satu-satunya penjaga BANK SOAL, dan satu cabang
 * `if ($user->isStudent()) return true;` di dalamnya membuka seluruh soal
 * yang belum diujikan kepada 300 anak yang menebak id-nya.
 *
 * Karena itu rutenya DIPISAH, dan yang dijaga di sini bukan soalnya
 * melainkan PENEMPATANNYA. Pertanyaan yang dijawab kelas ini: "apakah anak
 * ini memang peserta ujian yang butirnya memuat berkas ini" - dan jawabannya
 * hanya bisa ya bagi berkas yang memang sudah dikirim ke peramban anak itu.
 *
 * ---------------------------------------------------------------------
 * Yang diperiksa, dan mengapa ketiganya
 * ---------------------------------------------------------------------
 *   1. Penempatan itu MILIK siswa yang meminta. Bukan pemeriksaan sekolah -
 *      itu sudah dijawab SchoolScope saat binding. Ini pemeriksaan
 *      kepemilikan baris, dan jawabannya 404: pola yang sama dengan
 *      SiswaDasar::milikSaya().
 *   2. Butir snapshot itu milik ujian dari penempatan yang sama. Tanpa ini,
 *      siswa yang punya satu penempatan sah bisa membaca gambar dari ujian
 *      lain di sekolah yang sama - dan id snapshot itu berurutan.
 *   3. Id berkas itu memang TERCANTUM di `media_json` butir tersebut.
 *      Pemeriksaan yang paling menentukan: `exam_questions.media_json`
 *      adalah daftar berkas yang sudah sampai ke layar anak, dan berkas soal
 *      LAIN di bank soal - yang belum diujikan - tidak ada di daftar itu.
 *      Tanpa langkah ini, rutenya menjadi pintu baca seluruh bank soal satu
 *      sekolah lewat nomor id.
 *
 * ---------------------------------------------------------------------
 * Mengapa modul Exam, bukan Attendance
 * ---------------------------------------------------------------------
 * Peta modul mengizinkan `Exam -> Question` dan TIDAK mengizinkan
 * `Attendance -> Question` (`DomainBoundariesTest`). Yang dipegang kelas ini
 * adalah penempatan, snapshot, dan berkas medianya - ketiganya milik Exam
 * dan Question, dan tidak satu pun menyentuh attempt. Menaruhnya di
 * Attendance akan memaksa satu entri peta baru hanya demi satu rute
 * pembacaan berkas.
 */
class ExamMediaSiswaController
{
    public function tampilkan(
        Request $request,
        ExamParticipant $peserta,
        ExamQuestion $soal,
        QuestionMedia $media,
    ): StreamedResponse {
        // 1 - kepemilikan penempatan.
        if ((int) $peserta->student_id !== (int) $request->user()->id) {
            abort(404);
        }

        // 2 - snapshot yang sama ujiannya dengan penempatan.
        if ((int) $soal->exam_id !== (int) $peserta->exam_id) {
            abort(404);
        }

        // 3 - berkasnya memang dikirim ke butir ini.
        if (! $this->tercantumDiSnapshot($soal, $media->id)) {
            abort(404);
        }

        $jalur = $media->storage_path;

        if ($jalur === null || ! Storage::disk('local')->exists($jalur)) {
            abort(404, 'Berkasnya tidak ada di penyimpanan.');
        }

        return Storage::disk('local')->response(
            $jalur,
            $media->nama_asli ?? basename($jalur),
            ['Content-Type' => $media->mime_type ?? 'application/octet-stream'],
        );
    }

    /**
     * Apakah id berkas ini ada di `media_json` butir tersebut.
     *
     * Dibandingkan pada ID, bukan pada jalur simpan: yang dikirim ke peramban
     * adalah daftar penunjuk (`PembekuPaket::media()`), dan jalur simpan
     * sengaja TIDAK ikut ke sana - nama berkas di disk adalah nama yang tidak
     * boleh diketahui siapa pun yang tidak berhak membacanya.
     */
    private function tercantumDiSnapshot(ExamQuestion $soal, int $idMedia): bool
    {
        // Media tingkat SOAL.
        $daftar = $soal->media_json ?? [];

        foreach ($daftar as $berkas) {
            if (is_array($berkas) && (int) ($berkas['id'] ?? 0) === $idMedia) {
                return true;
            }
        }

        // Media PILIHAN JAWABAN - tinggal di dalam `media` masing-masing opsi.
        // Tanpa cabang ini, gambar yang menempel pada pilihan tak akan pernah
        // lolos pemeriksaan, dan soal yang gambarnya ada di pilihan B tampil
        // tanpa gambarnya di layar siswa.
        foreach ($soal->opsi_json ?? [] as $opsi) {
            foreach ((is_array($opsi) ? ($opsi['media'] ?? []) : []) as $berkas) {
                if (is_array($berkas) && (int) ($berkas['id'] ?? 0) === $idMedia) {
                    return true;
                }
            }
        }

        return false;
    }
}
