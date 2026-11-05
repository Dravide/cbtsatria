<?php

namespace App\Domain\Grading\Models;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Grading\Enums\StatusNilai;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nilai akhir satu pengerjaan - PRD §13.4, plate 07 & 28.
 *
 * ---------------------------------------------------------------------
 * Dibaca dari sini, tidak dihitung ulang di setiap laman
 * ---------------------------------------------------------------------
 * `PenghitungHasil` yang mengisi baris ini; sesudahnya, plate 07 (nilai
 * kelas), plate 08 (analisis), plate 21 (publikasi), dan plate 28 (nilai
 * saya) hanya MEMBACA. Itu bukan penghematan kueri - ia satu-satunya cara
 * membuat empat layar menampilkan angka yang sama.
 *
 * Rata-rata kelas yang dihitung di PHP dengan pembulatan berbeda dari rata-
 * rata yang dihitung di laporan adalah dua rata-rata yang berbeda, dan yang
 * paling mungkin terjadi adalah Admin dan Guru berdebat tentang angka yang
 * keduanya benar.
 *
 * ---------------------------------------------------------------------
 * `final_percentage` disimpan meski bisa dihitung
 * ---------------------------------------------------------------------
 * PRD §13.4: (total / maksimal) x 100. Kolomnya tetap ada karena
 * pembulatannya adalah KEPUTUSAN, bukan turunan: 2/3 x 100 = 66,666...
 * menjadi 66,67 di sini, dan setiap tempat yang menghitungnya sendiri bisa
 * memilih desimal yang berbeda.
 *
 * ---------------------------------------------------------------------
 * `pending_essays` adalah penjaga tombol publikasi
 * ---------------------------------------------------------------------
 * Plate 21 menonaktifkan tombol publikasi selama masih ada uraian belum
 * dinilai. Angka itu dibaca dari sini, untuk SELURUH ujian dalam event, pada
 * setiap penyegaran laman. Menghitungnya langsung berarti menggabungkan
 * `student_answers` ke `exam_questions` untuk setiap attempt setiap kali -
 * tepat saat seluruh sekolah sedang mengerjakan ujian.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_attempt_id',
    'automatic_score',
    'essay_score',
    'total_score',
    'maximum_score',
    'final_percentage',
    'grading_status',
    'pending_essays',
    'published_at',
    'published_by',
    'dihitung_pada',
])]
class ExamResult extends Model
{
    use BelongsToSchool;

    protected $table = 'exam_results';

    protected function casts(): array
    {
        return [
            'automatic_score' => 'decimal:2',
            'essay_score' => 'decimal:2',
            'total_score' => 'decimal:2',
            'maximum_score' => 'decimal:2',
            'final_percentage' => 'decimal:2',
            'grading_status' => StatusNilai::class,
            'pending_essays' => 'integer',
            'published_at' => 'datetime',
            'dihitung_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExamAttempt, $this> */
    public function pengerjaan(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    /**
     * Siapa yang menerbitkan. Boleh null - lihat catatan migrasinya:
     * menghapus akun Admin tidak boleh menarik kembali publikasinya.
     *
     * @return BelongsTo<User, $this>
     */
    public function penerbit(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Apakah angkanya sudah boleh dipakai sebagai nilai akhir.
     *
     * Satu method, bukan satu pemeriksaan yang ditulis ulang di setiap
     * laporan - persis kalimat PRD §13.4 "hasil yang masih mempunyai essay
     * belum terkoreksi tidak boleh dianggap final".
     */
    public function final(): bool
    {
        return $this->pending_essays === 0;
    }

    /**
     * Lulus atau tidak, terhadap KKM yang berlaku.
     *
     * KKM-nya milik sekolah (`PengaturanBawaan::NILAI['kkm']`), dan dibawa
     * pemanggil alih-alih dibaca di sini: model yang membaca pengaturan
     * sendiri tidak bisa diuji tanpa basis data, dan setiap laporan yang
     * memanggilnya akan membaca ulang pengaturan yang sama ratusan kali.
     *
     * Hasil yang BELUM final selalu `false`, dan itu bukan jawaban yang
     * salah - siswa dengan uraian belum dikoreksi memang belum bisa
     * dinyatakan lulus. Yang menampilkannya wajib membedakan "belum" dari
     * "tidak lulus", dan `final()` di atas adalah caranya.
     */
    public function lulus(float $kkm): bool
    {
        return $this->final() && (float) $this->final_percentage >= $kkm;
    }
}
