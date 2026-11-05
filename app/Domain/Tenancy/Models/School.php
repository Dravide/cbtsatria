<?php

namespace App\Domain\Tenancy\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Sekolah - batas isolasi data paling luar.
 *
 * Perhatikan: model ini TIDAK memakai BelongsToSchool. Ia justru tabel
 * induknya, satu-satunya tabel yang dibatasi oleh dirinya sendiri dan bukan
 * oleh school_id milik induk lain. Kalau ia ikut ter-scope, tidak ada yang
 * bisa membacanya.
 *
 * Tidak ada relasi users() di sini. Arah ketergantungan modul adalah
 * Identity -> Tenancy, bukan sebaliknya; menaruh hasMany(User::class) di
 * sini akan membuat Tenancy menyentuh model milik Identity dan melanggar
 * aturan batas modul. Relasinya hidup di sisi User.
 *
 * npsn dikunci di UI (wireframe plate 04): mengubahnya berarti memindahkan
 * sekolah ke identitas lain, dan itu operasi di luar aplikasi. Ia tidak
 * pernah diketik siapa pun di layar login.
 */
#[Fillable([
    'code',
    'name',
    'npsn',
    'logo_path',
    'address',
    'email',
    'phone',
    'timezone',
    'status',
    'settings',
])]
class School extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function isAktif(): bool
    {
        return $this->status === 'aktif';
    }
}
