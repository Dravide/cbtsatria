<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Policies\UserPolicy;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Akun keempat peran.
 *
 * ---------------------------------------------------------------------
 * username unik GLOBAL, bukan school_id + username
 * ---------------------------------------------------------------------
 * PRD §16.2 menulis unique `school_id + username`. Itu ditimpa oleh dua hal:
 * blueprint baris 738, dan keputusan Anda sendiri bahwa layar login hanya
 * punya username + password - tanpa kode sekolah, tanpa NPSN. Tanpa kolom
 * kode sekolah di form, username harus unik lintas platform, karena di
 * situlah satu-satunya identitas akun.
 *
 * Akibat yang harus ditangani di Fase 2: impor dua sekolah yang berisi NIS
 * sama harus menolak atau memberi awalan (blueprint: `smkn1.2024001`),
 * tidak pernah menghasilkan dua akun berusername sama.
 *
 * ---------------------------------------------------------------------
 * Mengapa scope TIDAK menyembunyikan baris ini saat login
 * ---------------------------------------------------------------------
 * SchoolScope melewatkan penyaringan saat school_id belum ter-resolve.
 * Saat login, belum ada user, jadi resolvernya null dan scope tidak
 * melakukan apa pun - sehingga username yang unik global menemukan barisnya.
 * Tanpa perilaku itu, setiap login akan gagal. Lihat SchoolResolver.
 */
#[ScopedBy(SchoolScope::class)]
#[UsePolicy(UserPolicy::class)]
#[Fillable([
    'school_id',
    'role',
    'name',
    'username',
    'email',
    'phone',
    'password',
    'is_active',
    'last_login_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use BelongsToSchool, HasFactory, Notifiable, SoftDeletes;

    /**
     * Sandi bawaan akun yang dibuat tanpa sandi.
     *
     * Literal ini tinggal di tiga produsen (buat pengguna, reset massal, impor)
     * dan sekarang juga di satu pembaca (laman Akun siswa, yang memperingatkan
     * pemiliknya). Ditulis sekali di sini karena pembaca yang menulis ulang
     * literalnya sendiri akan berbeda diam-diam begitu salah satu produsen
     * diubah.
     */
    public const SANDI_BAWAAN = 'password';

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Profil tambahan, satu sesuai peran (PRD §17 butir 2).
     *
     * Relasinya ada di sini, bukan hasOne di masing-masing model profil,
     * karena ketiga model itu sudah punya user() ke arah sini. Dua arah
     * untuk pasangan yang sama membuat "siapa pemilik relasi ini" tidak
     * pernah punya jawaban tunggal.
     *
     * @return HasOne<TeacherProfile, $this>
     */
    public function teacherProfile(): HasOne
    {
        return $this->hasOne(TeacherProfile::class);
    }

    /** @return HasOne<StudentProfile, $this> */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /** @return HasOne<SupervisorProfile, $this> */
    public function supervisorProfile(): HasOne
    {
        return $this->hasOne(SupervisorProfile::class);
    }

    public function isAktif(): bool
    {
        return (bool) $this->is_active;
    }
}
