<?php

namespace App\Domain\Exam\Models;

use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Models\EventActivity;
use App\Domain\Event\Models\SchoolEvent;
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
 * Ujian untuk satu mata pelajaran dan satu set kelas sasaran - PRD §9.2.
 *
 * ---------------------------------------------------------------------
 * Ujian berada DI DALAM event, bukan di sampingnya
 * ---------------------------------------------------------------------
 * PRD §6.1 menetapkan event sebagai induk seluruh kegiatan sekolah, dan
 * §9.1 menempatkan exam di bawahnya lewat event activity. Satu event
 * menampung beberapa ujian (teori, praktik, remedial) dan masing-masing
 * punya jadwal, sesi, dan paket soalnya sendiri.
 *
 * Karena itu setiap ujian menunjuk event, dan seluruh laman jadwal/sesi/
 * pengawas/token hidup di bawah event yang sama.
 *
 * ---------------------------------------------------------------------
 * question_package_id adalah BENIH snapshot, bukan sekadar rujukan
 * ---------------------------------------------------------------------
 * Kolomnya ada sejak Fase 5 supaya Fase 5 tidak perlu menyentuh tabelnya
 * lagi, dan sejak Fase 4 ia benar-benar diisi. NULL di sini berarti "belum
 * ada paket", bukan "boleh tanpa paket".
 *
 * Yang membacanya ada dua, dan keduanya berbeda peran:
 *
 *   `bekukanPaket()`         - menyalin isi paket ke `exam_questions` saat
 *                              ujian lahir, dan hanya saat itu.
 *   gerbang kesiapan event   - menuntut setiap mapel event punya paket.
 *
 * Perhatikan yang TIDAK membacanya: laman siswa. Snapshot adalah yang dibaca
 * saat ujian berjalan, dan karena itu mengubah atau melepas paket sesudahnya
 * tidak mengubah soal yang sedang dikerjakan siapa pun.
 *
 * ---------------------------------------------------------------------
 * Ambang pelanggaran ada di EVENT, bukan di sini
 * ---------------------------------------------------------------------
 * Kolomnya sengaja tidak diduplikasi dari school_events. Satu event dengan
 * ambang berbeda per ujian terdengar masuk akal sampai disadari bahwa
 * peserta mengerjakan beberapa ujian dalam satu duduk, dan aturan main
 * yang berubah di antara dua ujian dalam satu hari adalah aturan yang tidak
 * bisa dijelaskan kepada siswa.
 *
 * ---------------------------------------------------------------------
 * `publish_results` PINDAH ke event di Fase 8, dan tidak ditinggalkan di sini
 * ---------------------------------------------------------------------
 * Kolomnya hidup di tabel ini sejak Fase 5 dengan catatan "perluasan ke
 * tingkat event kelak bisa menimpanya tanpa membongkar kolomnya". Yang
 * terjadi di Fase 8 justru sebaliknya: plate 21 menegaskan publikasi adalah
 * SATU aksi untuk seluruh event, sehingga `exams.publish_results` dijatuhkan
 * dan diganti `school_events.results_published_at`.
 *
 * Blueprint §14: jangan tinggalkan kolom kembar. Satu fakta di dua tempat
 * akan berbeda suatu hari, dan saat berbeda tidak ada yang tahu mana yang
 * benar. Yang menjawab "apakah ujian ini hasilnya boleh dilihat siswa"
 * sekarang `$exam->event->hasilTerbit()`.
 *
 * ---------------------------------------------------------------------
 * `status` juga dijatuhkan, dan alasannya berbeda dari `publish_results`
 * ---------------------------------------------------------------------
 * `exams.status` lahir di Fase 5 dengan `default('draft')`, diisi `'draft'`
 * sekali oleh `ExamRegistrar::buat()`, dan tidak pernah ditulis lagi oleh
 * kode mana pun selama enam fase. Yang membacanya satu: satu chip di laman
 * jadwal. Tidak ada gerbang, tidak ada pemeriksaan kesiapan, tidak ada
 * kueri - sehingga chip itu secara struktural tidak mungkin berbunyi selain
 * "draft", dan Admin yang melihatnya menyimpulkan ujiannya belum tertinjau
 * padahal tidak ada satu pun tindakan yang bisa mengubahnya.
 *
 * Yang membuatnya tidak perlu dipertahankan: **ujian bukan pemilik kata
 * "status" di sistem ini**. PRD §9.2 sengaja tidak mencantumkan status pada
 * daftar field ujian, sementara §9.3 memang mencantumkannya untuk SESI. Yang
 * ditinjau adalah jendela sesinya, dan `StatusSesi` - yang benar-benar
 * bergerak dan benar-benar dibaca - sudah menyatakannya.
 *
 * Dijatuhkan 2026-11-07; lihat migrasi 0016_01_01_000030.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'event_id',
    'event_activity_id',
    'subject_id',
    'question_package_id',
    'created_by',
    'code',
    'title',
    'exam_type',
    'instructions',
    'duration_minutes',
    'max_attempts',
    'shuffle_questions',
    'shuffle_options',
    'allow_back_navigation',
    'allow_late_entry',
    'late_entry_deadline',
])]
class Exam extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'max_attempts' => 'integer',
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'allow_back_navigation' => 'boolean',
            'allow_late_entry' => 'boolean',
            'late_entry_deadline' => 'datetime',
        ];
    }

    /** @return BelongsTo<SchoolEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SchoolEvent::class, 'event_id');
    }

    /** @return BelongsTo<EventActivity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(EventActivity::class, 'event_activity_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ExamClass, $this> */
    public function classes(): HasMany
    {
        return $this->hasMany(ExamClass::class);
    }

    /** @return HasMany<ExamSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(ExamSession::class);
    }

    /** @return HasMany<ExamParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ExamParticipant::class);
    }

    /**
     * Snapshot soal ujian ini.
     *
     * Diurutkan `position` dan bukan `id`: nomor soal adalah bagian dari
     * soal ujian, dan urutan penulisannya di paket yang menentukan soal mana
     * yang pertama - bukan urutan barisnya masuk ke basis data.
     *
     * @return HasMany<ExamQuestion, $this>
     */
    public function soal(): HasMany
    {
        return $this->hasMany(ExamQuestion::class, 'exam_id')->orderBy('position');
    }
}
