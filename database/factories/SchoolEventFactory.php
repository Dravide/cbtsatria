<?php

namespace Database\Factories;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolEvent>
 */
class SchoolEventFactory extends Factory
{
    protected $model = SchoolEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // academic_year_id ditulis SEBELUM school_id, dan urutannya
            // menentukan. Closure di bawah menerima array atribut yang sudah
            // dievaluasi sampai kunci itu saja, jadi sekolahnya hanya bisa
            // diturunkan dari tahun ajaran bila tahun ajarannya lebih dulu.
            'academic_year_id' => AcademicYear::factory(),

            // Diturunkan dari tahun ajarannya, BUKAN dari School::factory()
            // sendiri. Composite FK di school_events menuntut school_id dan
            // academic_year_id berasal dari sekolah yang sama, sehingga dua
            // factory yang berdiri sendiri-sendiri menghasilkan baris yang
            // ditolak MySQL - dengan galat yang tidak menyebut kolom mana yang
            // salah. Bug ini nyata: `SchoolEvent::factory()->create()` tanpa
            // untukTahun() gagal dengan 1452 sampai baris ini diperbaiki.
            'school_id' => function (array $atribut) {
                return AcademicYear::find($atribut['academic_year_id'])?->school_id;
            },
            'semester_id' => null,
            'code' => 'EV-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->randomElement([
                'UAS Ganjil', 'PTS Ganjil', 'Try Out AKM', 'Ujian Praktik Kejuruan',
            ]),
            'event_type' => 'Ujian akhir semester',
            'description' => null,
            'start_at' => null,
            'end_at' => null,
            // Selalu draft. Event yang lahir langsung "siap" berarti
            // kesiapan tidak pernah diperiksa - dan seluruh gerbang di
            // EventStatusService menjadi hiasan.
            'status' => EventStatus::Draft,
            'violation_thresholds' => [
                'peringatkan' => 5,
                'tandai_pengawas' => 5,
                'kunci_ujian' => 5,
            ],
            'enforce_fullscreen' => true,
            'created_by' => null,
        ];
    }

    public function status(EventStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /**
     * Ambang yang berbeda per tingkat - konfigurasi normal, bukan pengecualian.
     */
    public function ambang(int $peringatkan, int $tandai, int $kunci): static
    {
        return $this->state(fn () => ['violation_thresholds' => [
            'peringatkan' => $peringatkan,
            'tandai_pengawas' => $tandai,
            'kunci_ujian' => $kunci,
        ]]);
    }

    public function untukSekolah(School $school): static
    {
        return $this->state(fn () => ['school_id' => $school->id]);
    }

    public function untukTahun(AcademicYear $tahun): static
    {
        return $this->state(fn () => [
            'school_id' => $tahun->school_id,
            'academic_year_id' => $tahun->id,
        ]);
    }
}
