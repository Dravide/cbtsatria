<?php

namespace App\Domain\Question\Models;

use App\Domain\Academic\Models\Subject;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Enums\StatusSoal;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu soal di bank soal - PRD §16.5, wireframe plate 02-03.
 *
 * ---------------------------------------------------------------------
 * Soal adalah aset SEKOLAH, bukan milik pribadi Guru yang menulisnya
 * ---------------------------------------------------------------------
 * Keputusan PRD §24 #5. Yang tampak di layar: Guru A membuka `/guru/bank-soal`
 * dan melihat soal Guru B - dua-duanya mengajar Matematika. Guru A boleh
 * memakainya di paketnya; ia TIDAK boleh mengubah atau menghapusnya.
 *
 * Pemisahan itu dipegang `created_by` di sini: satu kolom, dan artinya
 * tunggal. `created_by` BUKAN "pemilik" - ia penentu izin TULIS. Izin LIHAT
 * ditentukan `TeachingAssignment` pada `subject_id` soal, dan itu tabel yang
 * sudah ada sejak Fase 2 supaya tidak ada daftar izin kedua di aplikasi ini.
 *
 * Akibat yang harus dijaga setiap kali kueri di berkas ini disentuh: daftar
 * bank soal TIDAK menyaring `created_by = auth()->id()`. Itu filter yang
 * tampak benar dan justru membatalkan keputusan di atas.
 *
 * ---------------------------------------------------------------------
 * Mengapa isi soal ada di EMPAT kolom JSON yang berbeda
 * ---------------------------------------------------------------------
 * `prompt_json` (teks soal), `answer_key_json` (kunci), `scoring_config_json`
 * (cara menilai), `explanation_json` (pembahasan). Menyatukannya menjadi satu
 * `content_json` terdengar lebih sederhana sampai disadari bahwa keempatnya
 * punya aturan yang berbeda: kunci hanya sah bila jenisnya cocok (SkemaJenisSoal),
 * konfigurasi skor dibaca saat penilaian, pembahasan TIDAK BOLEH ikut ke
 * peramban siswa. Kolom terpisah membuat aturan itu bisa ditegakkan per kolom,
 * termasuk di kueri - `select` yang membuang `answer_key_json` adalah operasi
 * yang terbaca, sementara membuang satu kunci dari dalam JSON tidak.
 *
 * ---------------------------------------------------------------------
 * `difficulty` diisi Guru, bukan dihitung sistem
 * ---------------------------------------------------------------------
 * Sampai ada data jawaban siswa (Fase 8), "tingkat kesulitan" hanya bisa
 * berarti "perkiraan penyusunnya". Kolom yang mengaku lebih tahu daripada
 * pengisinya adalah kolom yang berbohong.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'created_by',
    'subject_id',
    'code',
    'title',
    'question_type',
    'difficulty',
    'prompt_json',
    'answer_key_json',
    'scoring_config_json',
    'explanation_json',
    'status',
    'published_at',
])]
class Question extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'question_type' => JenisSoal::class,
            'prompt_json' => 'array',
            'answer_key_json' => 'array',
            'scoring_config_json' => 'array',
            'explanation_json' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function penulis(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return HasMany<QuestionOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    /** @return HasMany<QuestionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(QuestionItem::class)->orderBy('position');
    }

    /** @return HasMany<EssayRubricCriterion, $this> */
    public function rubrik(): HasMany
    {
        return $this->hasMany(EssayRubricCriterion::class)->orderBy('position');
    }

    /** @return HasMany<QuestionMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(QuestionMedia::class)->orderBy('id');
    }

    /** @return HasMany<QuestionPackageItem, $this> */
    public function pemakaian(): HasMany
    {
        return $this->hasMany(QuestionPackageItem::class);
    }

    public function jenis(): JenisSoal
    {
        return $this->question_type;
    }

    public function statusSoal(): StatusSoal
    {
        return StatusSoal::tryFrom((string) $this->status) ?? StatusSoal::Draft;
    }
}
