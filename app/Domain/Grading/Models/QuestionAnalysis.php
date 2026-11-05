<?php

namespace App\Domain\Grading\Models;

use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Analisis satu butir - PRD §13.6, wireframe plate 08.
 *
 * ---------------------------------------------------------------------
 * Mengapa disimpan, padahal seluruhnya turunan
 * ---------------------------------------------------------------------
 * `answered_count`, `correct_count`, `percent_correct`, dan `discrimination`
 * semuanya bisa dihitung dari `student_answers`. Yang membuatnya disimpan
 * adalah bentuk perhitungannya: daya beda menuntut SELURUH peserta diurutkan
 * berdasarkan nilai, lalu kelompok atas dan bawah diperiksa untuk setiap
 * butir. Menghitungnya saat laman dibuka berarti pekerjaan yang tumbuh
 * kuadratik terhadap jumlah peserta dan jumlah butir - dan dilakukan ulang
 * setiap kali Guru menyegarkan layar.
 *
 * Baris ini lahir dari satu penulis (`PenghitungAnalisis`) yang dipanggil
 * setelah ujian selesai atau saat Guru menekan "Hitung ulang".
 *
 * ---------------------------------------------------------------------
 * `verdict` menyimpan PUTUSAN, bukan hanya angkanya
 * ---------------------------------------------------------------------
 * Plate 08 menampilkan empat chip: `baik`, `sulit`, `terlalu mudah`,
 * `periksa kunci`. Yang keempat bukan tingkat kesulitan - ia peringatan
 * bahwa butirnya menghukum siswa yang pintar, dan hampir selalu berarti
 * kuncinya tertukar. Menyimpannya sebagai putusan membuat ambangnya tinggal
 * di satu tempat; menghitungnya di React berarti ambang daya beda ada di
 * frontend, dan satu build ulang bisa mengubah putusan bagi seluruh butir
 * yang sudah dianalisis.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_question_id',
    'answered_count',
    'correct_count',
    'percent_correct',
    'discrimination',
    'verdict',
    'dihitung_pada',
])]
class QuestionAnalysis extends Model
{
    use BelongsToSchool;

    public const PUTUSAN_BAIK = 'baik';

    public const PUTUSAN_SULIT = 'sulit';

    public const PUTUSAN_TERLALU_MUDAH = 'terlalu mudah';

    public const PUTUSAN_PERIKSA_KUNCI = 'periksa kunci';

    /**
     * Ambang putusan - satu tempat, dan nilainya sengaja konservatif.
     *
     * `MUDAH_DI_ATAS` 85%: butir yang dijawab benar oleh hampir semua orang
     * tidak membedakan apa pun. `SULIT_DI_BAWAH` 30%: butir yang dijawab
     * benar oleh sepertiga peserta masih layak, di bawah itu ia lebih sering
     * menandakan soal yang ambigu daripada soal yang sulit.
     *
     * `DAYA_BEDA_MINIMAL` 0,20 adalah ambang yang dipakai ujian sekolah pada
     * umumnya; di bawah nol, butirnya benar-benar merugikan.
     */
    public const MUDAH_DI_ATAS = 85.0;

    public const SULIT_DI_BAWAH = 30.0;

    public const DAYA_BEDA_MINIMAL = 0.20;

    protected $table = 'question_analyses';

    protected function casts(): array
    {
        return [
            'answered_count' => 'integer',
            'correct_count' => 'integer',
            'percent_correct' => 'decimal:2',
            'discrimination' => 'decimal:3',
            'dihitung_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExamQuestion, $this> */
    public function butir(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'exam_question_id');
    }

    /**
     * Putusan dari dua angka yang tersimpan.
     *
     * Urutannya penting dan disengaja: `periksa kunci` diperiksa LEBIH DULU.
     * Butir yang daya bedanya negatif dan persentase benarnya 90% memenuhi
     * syarat "terlalu mudah" - tetapi sebabnya bukan karena mudah, melainkan
     * karena kuncinya tertukar. Menampilkan "terlalu mudah" di sana
     * menyarankan Guru membuang soal yang sebenarnya hanya salah kunci.
     */
    public static function putuskan(float $persenBenar, ?float $dayaBeda): string
    {
        if ($dayaBeda !== null && $dayaBeda < 0) {
            return self::PUTUSAN_PERIKSA_KUNCI;
        }

        if ($dayaBeda !== null && $dayaBeda < self::DAYA_BEDA_MINIMAL) {
            return self::PUTUSAN_PERIKSA_KUNCI;
        }

        if ($persenBenar > self::MUDAH_DI_ATAS) {
            return self::PUTUSAN_TERLALU_MUDAH;
        }

        if ($persenBenar < self::SULIT_DI_BAWAH) {
            return self::PUTUSAN_SULIT;
        }

        return self::PUTUSAN_BAIK;
    }

    public function nada(): string
    {
        return match ($this->verdict) {
            self::PUTUSAN_BAIK => 'ok',
            self::PUTUSAN_SULIT => 'warn',
            self::PUTUSAN_TERLALU_MUDAH => 'info',
            default => 'bad',
        };
    }
}
