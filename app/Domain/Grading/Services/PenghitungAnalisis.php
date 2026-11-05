<?php

namespace App\Domain\Grading\Services;

use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Grading\Models\ExamResult;
use App\Domain\Grading\Models\QuestionAnalysis;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Analisis butir - PRD §13.6, wireframe plate 08.
 *
 * =====================================================================
 * Dua angka, dan yang kedua yang membuat pekerjaan ini layak disimpan
 * =====================================================================
 *
 *   persen benar   berapa bagian peserta yang menjawab benar.
 *   daya beda      apakah butir ini membedakan siswa kuat dari siswa lemah.
 *
 * Yang pertama mudah. Yang kedua menuntut seluruh peserta diurutkan
 * berdasarkan nilai lebih dulu, lalu kelompok atas dan kelompok bawah
 * dibandingkan - untuk SETIAP butir. Itu pekerjaan yang tumbuh dengan
 * kuadrat peserta kali butir, dan karena itu hasilnya disimpan
 * (`question_analyses`) alih-alih dihitung setiap kali laman dibuka.
 *
 * =====================================================================
 * Kelompok atas dan bawah: 27% paling atas dan 27% paling bawah
 * =====================================================================
 * Angka 27% bukan pilihan kami - ia bentuk yang dipakai ujian sekolah pada
 * umumnya, dan berasal dari titik di mana pemisahan kelompok puncak dan
 * dasar paling stabil. Yang penting bukan angkanya, melainkan bahwa ia ADA
 * di satu tempat: dua laporan dengan dua definisi kelompok akan menghasilkan
 * dua daya beda untuk butir yang sama.
 *
 * Ujian dengan peserta kurang dari 10 orang tidak punya kelompok yang
 * bermakna. Untuk itu besarannya adalah `max(1, ceil(27%))`, dan daya beda
 * yang lahir dari 2 peserta diberi `null` - bukan angka yang terlihat
 * meyakinkan padahal dihitung dari dua orang.
 *
 * =====================================================================
 * `null` berarti "tidak bisa dihitung", bukan nol
 * =====================================================================
 * Butir yang dijawab satu orang pun tidak punya daya beda. Menyimpannya
 * sebagai 0 membuatnya masuk kategori "periksa kunci" dan memunculkan
 * peringatan merah untuk soal yang hanya belum banyak dikerjakan.
 */
class PenghitungAnalisis
{
    /** Bagian peserta di kelompok atas dan bawah. */
    public const PECAHAN_KELOMPOK = 0.27;

    /** Di bawah jumlah peserta ini, daya beda tidak dihitung. */
    public const MINIMAL_PESERTA = 10;

    /**
     * Hitung ulang analisis seluruh butir satu ujian.
     *
     * @return int Jumlah butir yang dianalisis.
     */
    public function hitungUlang(int $examId): int
    {
        $butir = ExamQuestion::query()
            ->where('exam_id', $examId)
            ->orderBy('position')
            ->get();

        if ($butir->isEmpty()) {
            return 0;
        }

        // Nilai setiap attempt, diurutkan. `exam_results` sudah menyimpan
        // persentasenya, jadi tabel inilah sumber urutannya - bukan hitungan
        // ulang yang bisa berbeda dari yang dilihat Guru di plate 07.
        $peringkat = ExamResult::query()
            ->whereIn('exam_attempt_id', ExamAttempt::query()
                ->whereHas('penempatan', fn ($q) => $q->where('exam_id', $examId))
                ->whereIn('status', [
                    ExamAttempt::STATUS_DIKIRIM,
                    ExamAttempt::STATUS_KEDALUWARSA,
                    ExamAttempt::STATUS_DIKUNCI,
                ])
                ->select('id'))
            ->orderByDesc('final_percentage')
            ->get(['exam_attempt_id', 'final_percentage']);

        $jumlahAttempt = $peringkat->count();
        $kelompok = $this->susunKelompok($peringkat);

        // Satu kueri untuk seluruh jawaban ujian ini. Membaca per butir
        // berarti 40 kueri untuk satu laporan, dan itu persis beban yang
        // membuat halaman ini disimpan sebagai tabel.
        //
        // Penyaringnya `exam_question_id`, BUKAN `exam_id`: `attempt_questions`
        // tidak punya kolom `exam_id` - yang ia simpan adalah rujukan ke
        // BUTIR SNAPSHOT, dan butir itulah yang tahu ujiannya. Kueri
        // `where('exam_id', ...)` akan berakhir sebagai Column not found,
        // bukan sebagai hasil kosong yang kelihatan seperti "belum ada
        // jawaban".
        // `answer_json` WAJIB ikut terpilih: `StudentAnswer::terjawab()`
        // membacanya untuk memisahkan "tidak menjawab" dari "menjawab salah",
        // dan kolom yang tidak terpilih menjadi null tanpa satu pun galat -
        // akibatnya SELURUH jawaban terbaca belum terjawab, dan setiap butir
        // tercatat 0% benar dengan putusan "sulit".
        $jawaban = AttemptQuestion::query()
            ->whereIn('exam_question_id', $butir->pluck('id')->all())
            ->whereIn('exam_attempt_id', $peringkat->pluck('exam_attempt_id')->all())
            ->with('jawaban:id,attempt_question_id,answer_json,is_correct')
            ->get()
            ->groupBy('exam_question_id');

        return DB::transaction(function () use ($butir, $jawaban, $kelompok, $jumlahAttempt) {
            foreach ($butir as $satu) {
                $this->hitungSatu($satu, $jawaban->get($satu->id, collect()), $kelompok, $jumlahAttempt);
            }

            return $butir->count();
        });
    }

    /**
     * Susun himpunan attempt kelompok atas dan bawah.
     *
     * @param  Collection<int, ExamResult>  $peringkat
     * @return array{atas: array<int, int>, bawah: array<int, int>}
     */
    private function susunKelompok(Collection $peringkat): array
    {
        $jumlah = $peringkat->count();

        if ($jumlah < self::MINIMAL_PESERTA) {
            return ['atas' => [], 'bawah' => []];
        }

        $ambil = (int) max(1, ceil($jumlah * self::PECAHAN_KELOMPOK));
        $id = $peringkat->pluck('exam_attempt_id')->values();

        return [
            'atas' => $id->take($ambil)->all(),
            'bawah' => $id->slice($jumlah - $ambil)->all(),
        ];
    }

    /**
     * Hitung satu butir, lalu simpan.
     *
     * @param  Collection<int, AttemptQuestion>  $jawaban
     * @param  array{atas: array<int, int>, bawah: array<int, int>}  $kelompok
     */
    private function hitungSatu(
        ExamQuestion $butir,
        Collection $jawaban,
        array $kelompok,
        int $jumlahAttempt,
    ): void {
        // Butir URAIAN tidak punya `is_correct` yang bisa dijumlahkan - nilai
        // uraian bersifat parsial, dan "benar" bukan pertanyaan yang bisa
        // dijawab. Menganalisisnya dengan rumus yang sama akan menghasilkan
        // persen benar yang selalu nol dan putusan "sulit" untuk setiap soal
        // uraian, yang menyesatkan Guru yang membacanya.
        if ($butir->perluKoreksiManual()) {
            return;
        }

        $terjawab = $jawaban->filter(fn (AttemptQuestion $q) => $q->jawaban?->terjawab() !== false);
        $benar = $terjawab->filter(fn (AttemptQuestion $q) => $q->jawaban?->is_correct === true);

        $jumlahTerjawab = $terjawab->count();
        $jumlahBenar = $benar->count();

        $persen = $jumlahTerjawab > 0
            ? round($jumlahBenar / $jumlahTerjawab * 100, 2)
            : 0.0;

        $dayaBeda = $this->dayaBeda($jawaban, $kelompok, $jumlahAttempt);

        $analisis = QuestionAnalysis::query()
            ->where('exam_question_id', $butir->id)
            ->first() ?? new QuestionAnalysis;

        $analisis->forceFill([
            'school_id' => $butir->school_id,
            'exam_question_id' => $butir->id,
            'answered_count' => $jumlahTerjawab,
            'correct_count' => $jumlahBenar,
            'percent_correct' => $persen,
            'discrimination' => $dayaBeda,
            'verdict' => QuestionAnalysis::putuskan($persen, $dayaBeda),
            'dihitung_pada' => now(),
        ])->save();
    }

    /**
     * Indeks daya beda: (benar atas - benar bawah) / ukuran kelompok.
     *
     * @param  Collection<int, AttemptQuestion>  $jawaban
     * @param  array{atas: array<int, int>, bawah: array<int, int>}  $kelompok
     */
    private function dayaBeda(Collection $jawaban, array $kelompok, int $jumlahAttempt): ?float
    {
        if ($jumlahAttempt < self::MINIMAL_PESERTA || $kelompok['atas'] === [] || $kelompok['bawah'] === []) {
            return null;
        }

        $perAttempt = $jawaban->keyBy('exam_attempt_id');

        $benarDi = function (array $daftar) use ($perAttempt): int {
            $jumlah = 0;

            foreach ($daftar as $attemptId) {
                if ($perAttempt->get($attemptId)?->jawaban?->is_correct === true) {
                    $jumlah++;
                }
            }

            return $jumlah;
        };

        $ukuran = count($kelompok['atas']);

        if ($ukuran === 0) {
            return null;
        }

        $nilai = ($benarDi($kelompok['atas']) - $benarDi($kelompok['bawah'])) / $ukuran;

        return round(max(-1.0, min(1.0, $nilai)), 3);
    }
}
