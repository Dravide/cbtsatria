<?php

namespace App\Domain\Event\Models;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Support\AmbangPelanggaran;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Event - induk seluruh aktivitas kegiatan sekolah (PRD §6.1).
 *
 * ---------------------------------------------------------------------
 * Event dulu, komponen menyusul
 * ---------------------------------------------------------------------
 * PRD §6.1 menetapkan urutannya: "Admin harus membuat event terlebih dahulu
 * sebelum menyusun komponen operasional kegiatan." Karena itu tidak ada tabel
 * komponen yang bisa berdiri tanpa event - jadwal, sesi, ruang, pengawas,
 * peserta, paket soal, semuanya menunjuk ke sini lewat composite FK. Yang
 * tidak menunjuk ke sini adalah ruang master, kelas, dan mata pelajaran: itu
 * data sekolah yang dipakai ulang lintas event sepanjang tahun.
 *
 * ---------------------------------------------------------------------
 * Ambang pelanggaran disalin, bukan diwarisi
 * ---------------------------------------------------------------------
 * Kolomnya ada di sini, bukan di schools, dan alasannya bukan preferensi:
 * toleransi berbeda menurut jenis ujian. Ujian harian dan ujian akhir tidak
 * seharusnya memakai ambang yang sama, jadi ambangnya melekat pada event.
 *
 * Nilai bawaannya DISALIN dari Pengaturan sekolah saat event dibuat, lalu
 * tidak pernah dibaca balik. Kalimat di wireframe plate 23 - "Event yang
 * sudah dibuat menyimpan nilainya sendiri dan tidak ikut berubah" - hanya
 * bisa ditepati bila penyalinannya terjadi satu kali. Kalau event membaca
 * balik ke pengaturan, mengubah ambang di tengah ujian yang sedang berjalan
 * akan mengubah aturan main peserta yang sedang mengerjakan.
 *
 * ---------------------------------------------------------------------
 * Status disimpan sebagai string ber-enum
 * ---------------------------------------------------------------------
 * Sama seperti `users.role`: kolomnya string supaya migrasi lama tetap bisa
 * dibaca, tetapi seluruh kode memakai EventStatus lewat casts(). String
 * mentah yang lolos ke perbandingan adalah sumber bug yang sama dengan
 * `match` atas role - lihat catatan di UserRegistrar::nomorIdentitas.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'academic_year_id',
    'semester_id',
    'code',
    'name',
    'event_type',
    'description',
    'start_at',
    'end_at',
    'status',
    'violation_thresholds',
    'enforce_fullscreen',
    'results_published_at',
    'results_published_by',
    'results_announced_at',
    'show_objectives',
    'show_explanations',
    'gradings_locked',
    'created_by',
])]
class SchoolEvent extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'school_events';

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'violation_thresholds' => 'array',
            'enforce_fullscreen' => 'boolean',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'results_published_at' => 'datetime',
            'results_announced_at' => 'datetime',
            'show_objectives' => 'boolean',
            'show_explanations' => 'boolean',
            'gradings_locked' => 'boolean',
        ];
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<Semester, $this> */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<EventActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(EventActivity::class, 'event_id');
    }

    /** @return HasMany<EventCommitteeMember, $this> */
    public function committee(): HasMany
    {
        return $this->hasMany(EventCommitteeMember::class, 'event_id');
    }

    /** @return HasMany<EventClass, $this> */
    public function classes(): HasMany
    {
        return $this->hasMany(EventClass::class, 'event_id');
    }

    /** @return HasMany<EventSubject, $this> */
    public function subjects(): HasMany
    {
        return $this->hasMany(EventSubject::class, 'event_id');
    }

    /** @return HasMany<EventParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(EventParticipant::class, 'event_id');
    }

    /** @return HasMany<EventRoom, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(EventRoom::class, 'event_id');
    }

    /** @return HasMany<EventTeacherAssignment, $this> */
    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(EventTeacherAssignment::class, 'event_id');
    }

    /**
     * Apakah hasil event ini sudah dipublikasikan ke siswa.
     *
     * Satu method, dan seluruh aplikasi menanyakannya ke sini - termasuk
     * `NilaiSiswaController` dan gerbang `/siswa`. Itu bukan kerapian:
     * `results_published_at` adalah satu-satunya sumber kebenaran sejak
     * `exams.publish_results` dijatuhkan (lihat catatan kepala kelas di
     * `Exam`), dan setiap tempat yang membandingkan kolomnya sendiri bisa
     * lupa memeriksa satu simpul rantai peserta -> ujian -> event.
     */
    public function hasilTerbit(): bool
    {
        return $this->results_published_at !== null;
    }

    /**
     * Apakah koreksi uraian sedang dikunci.
     *
     * TERPISAH dari `hasilTerbit()` dengan sengaja. Blueprint keputusan #10:
     * koreksi dikunci saat hasil dipublikasikan, dan Admin boleh membukanya
     * kembali dengan alasan yang tercatat. Kalau kuncinya DITURUNKAN dari
     * publikasi, membuka kembali koreksi hanya bisa dilakukan dengan
     * membatalkan publikasinya - dan hasil yang sudah dibaca 300 siswa
     * lenyap dari layar mereka karena seorang Guru perlu memperbaiki satu
     * poin.
     */
    public function koreksiTerkunci(): bool
    {
        return $this->gradings_locked;
    }

    /**
     * Ambang pelanggaran sebagai objek, bukan array mentah.
     *
     * Angka pengaturan sekolah dipakai sebagai cadangan bila kolomnya masih
     * kosong - event yang dibuat sebelum Admin pernah membuka Pengaturan
     * tetap punya ambang yang masuk akal, bukan null yang membuat peringatan
     * tidak pernah muncul.
     */
    public function ambang(int $bawaan = 5): AmbangPelanggaran
    {
        return AmbangPelanggaran::dari($this->violation_thresholds, $bawaan);
    }

    /**
     * Apakah pengguna ini pemegang seluruh event, atau hanya sebagian ruang.
     *
     * Keanggotaan panitia BUKAN role autentikasi baru (PRD §6.6). Ia
     * pemeriksaan di atas peran yang sudah ada, dan itulah sebabnya ia
     * tinggal di model - ia pertanyaan tentang event ini, bukan tentang
     * siapa penggunanya secara umum.
     */
    public function kepanitiaan(User $user): ?EventCommitteeMember
    {
        return $this->committee()->where('user_id', $user->id)->first();
    }
}
