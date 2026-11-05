<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Untuk setiap model milik satu sekolah.
 *
 * Scope didaftarkan lewat atribut #[\ScopedBy] pada model yang memakainya,
 * bukan di sini, karena atribut itu harus menempel pada kelas - lihat
 * contohnya di App\Domain\Identity\Models\User.
 *
 * Aturan yang menyertainya dan tidak boleh dilanggar:
 *
 *   Scope menangani tenant; Policy menangani peran dan kepemilikan.
 *   Ketidakcocokan tenant TIDAK BOLEH sampai ke Policy.
 *
 * Policy yang memeriksa $user->school_id === $model->school_id justru
 * menghadirkan kembali kebocoran 403: ia membuktikan barisnya ada, padahal
 * baris sekolah lain seharusnya tampak tidak ada sama sekali. Karena scope
 * menyembunyikan barisnya, route binding melempar ModelNotFoundException
 * dan pengguna menerima 404 - di setiap route ter-bind, tanpa kode
 * per-controller.
 */
trait BelongsToSchool
{
    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Lewati scope tenant untuk query ini saja.
     *
     * Ada supaya kode yang memang harus lintas-tenant melakukannya secara
     * terbaca, alih-alih menyusun whereNull('school_id') yang tampak seperti
     * bug enam bulan kemudian.
     */
    public static function tanpaTenant(): Builder
    {
        return static::query()->withoutGlobalScope(SchoolScope::class);
    }
}
