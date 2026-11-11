<?php

namespace Database\Factories;

use App\Domain\Academic\Models\Subject;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Services\PaketSoalService;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuestionPackage>
 */
class QuestionPackageFactory extends Factory
{
    protected $model = QuestionPackage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'subject_id' => fn (array $a) => Subject::factory()
                ->untukSekolah(School::find($a['school_id']))->create()->id,
            'created_by' => fn (array $a) => User::factory()->guru()
                ->untukSekolah(School::find($a['school_id']))->create()->id,
            'name' => 'Paket '.Str::upper(Str::random(4)),
            // Kode dibangkitkan lewat `PaketSoalService::kodeBaru()` juga,
            // bukan dihitung di sini: pabrik yang punya penomoran sendiri
            // akan menghasilkan `P-6-1` yang bertabrakan dengan paket
            // sungguhan di sekolah yang sama, dan tabrakannya baru terlihat
            // saat test membuat paket kedua.
            'code' => fn (array $a) => app(PaketSoalService::class)
                ->kodeBaru((int) $a['school_id'], (int) $a['subject_id']),
            'grade_level' => 'X',
            'description' => null,
            'status' => 'draft',
        ];
    }

    public function oleh(User $guru, Subject $mapel): static
    {
        return $this->state(fn () => [
            'school_id' => $guru->school_id,
            'created_by' => $guru->id,
            'subject_id' => $mapel->id,
        ]);
    }

    public function siap(): static
    {
        return $this->state(fn () => ['status' => 'siap']);
    }
}
