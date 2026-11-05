<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\PeranTandaTangan;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tanda tangan berita acara - PRD §12.3.
 *
 * ---------------------------------------------------------------------
 * Berkunci pada PERAN, bukan pada orang
 * ---------------------------------------------------------------------
 * Kunci uniknya `(school_id, session_report_id, peran)`, dan pilihan itu
 * persis yang membuat janji blueprint berlaku: "tanda tangan melekat pada
 * peran dan sesi, bukan pada orang semata, supaya pergantian pengawas tidak
 * memindahkan tanda tangan lama."
 *
 * Kunci pada orang akan menghasilkan hal yang salah dengan cara yang halus.
 * Pengawas yang sama bertugas di S-2 dan S-3 tidak akan bisa digantikan di
 * S-3 tanpa memindahkan tanda tangannya di S-2 - karena barisnya berkunci
 * pada orangnya, dan orangnya sama.
 *
 * ---------------------------------------------------------------------
 * nama disimpan, dan bukan hanya ditunjuk
 * ---------------------------------------------------------------------
 * `ditandatangani_oleh` menunjuk akunnya; `nama` menyalinnya. Salinan itu
 * disengaja: berita acara adalah dokumen yang harus tetap terbaca lima
 * tahun kemudian, dan akun pengawas yang sudah tidak aktif - atau sudah
 * dihapus - tidak boleh membuat tanda tangannya kehilangan pemiliknya.
 *
 * `pelaku_label` melengkapinya untuk penanda tangan yang tidak punya akun
 * sama sekali: pengawas yang masuk lewat tautan ruang.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'session_report_id',
    'peran',
    'nama',
    'path',
    'ditandatangani_pada',
    'ditandatangani_oleh',
    'pelaku_label',
])]
class SessionReportSignature extends Model
{
    use BelongsToSchool;

    protected $table = 'session_report_signatures';

    protected function casts(): array
    {
        return [
            'peran' => PeranTandaTangan::class,
            'ditandatangani_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<SessionReport, $this> */
    public function laporan(): BelongsTo
    {
        return $this->belongsTo(SessionReport::class, 'session_report_id');
    }

    /** @return BelongsTo<User, $this> */
    public function penandatangan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditandatangani_oleh');
    }
}
