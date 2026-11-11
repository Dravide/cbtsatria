<?php

namespace App\Domain\Question\Models;

use App\Domain\Academic\Models\Subject;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\StatusPaket;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Paket soal - kumpulan soal yang dipakai satu ujian.
 *
 * ---------------------------------------------------------------------
 * Paket TIDAK PERNAH memiliki soal
 * ---------------------------------------------------------------------
 * Blueprint `:861`: "soal tetap disimpan di bank soal lebih dulu, lalu
 * dirujuk oleh paket. Paket tidak pernah memiliki soal secara langsung, dan
 * menghapus paket tidak pernah menghapus soal."
 *
 * Aturan itu bukan kerapian, melainkan yang membuat bank soal berguna: satu
 * soal bagus dipakai di tiga ujian berbeda dalam tiga tahun, dan paket yang
 * menghapus soalnya saat dibuang akan menghabiskan bank soal satu sekolah
 * dalam dua tahun ajaran. Karena itu `question_package_items.question_id`
 * memakai `restrictOnDelete`, bukan cascade - Guru harus mengeluarkan soalnya
 * dari paket dulu, dan langkah itu terlihat.
 *
 * ---------------------------------------------------------------------
 * Nama paket unik per PEMBUAT, bukan per sekolah
 * ---------------------------------------------------------------------
 * Dua Guru yang sama-sama menamai paketnya "UAS Matematika X" adalah dua paket
 * yang berbeda. Memaksa unik lintas sekolah berarti Guru kedua tidak boleh
 * memakai nama yang wajar - dan nama paket adalah kalimat yang dibaca Admin
 * saat memasangnya ke event (plate 15).
 *
 * ---------------------------------------------------------------------
 * Paket boleh dibaca Guru lain, tetapi TIDAK boleh diubah
 * ---------------------------------------------------------------------
 * Sama seperti soal. Bedanya, paket dipakai utuh - dan paket yang isinya
 * diubah Guru lain setelah dipasang ke event adalah paket yang tidak lagi
 * sama dengan yang disetujui Admin. Karena itu izin ubahnya lebih ketat di
 * permukaan: hanya pembuatnya, meski Guru lain mengajar mapel yang sama.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'created_by',
    'subject_id',
    'code',
    'name',
    'grade_level',
    'description',
    'status',
])]
class QuestionPackage extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => StatusPaket::class,
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

    /** @return HasMany<QuestionPackageItem, $this> */
    public function butir(): HasMany
    {
        return $this->hasMany(QuestionPackageItem::class)->orderBy('position');
    }

    /** @return HasMany<EventQuestionPackage, $this> */
    public function pemasangan(): HasMany
    {
        return $this->hasMany(EventQuestionPackage::class);
    }

    public function siapDipakai(): bool
    {
        return $this->status === StatusPaket::Siap;
    }
}
