<?php

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Question\Models\QuestionImportBatch;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuestionImportBatch>
 */
class QuestionImportBatchFactory extends Factory
{
    protected $model = QuestionImportBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'teacher_id' => fn (array $a) => User::factory()->guru()
                ->untukSekolah(School::find($a['school_id']))->create()->id,
            'question_package_id' => null,
            'file_path' => 'impor/'.Str::random(40).'.xlsx',
            'nama_asli' => 'soal.xlsx',
            'berkas_hash' => hash('sha256', Str::random(40)),
            'pratinjau_json' => null,
            'status' => 'menunggu',
            'total_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'error_report_path' => null,
            'imported_at' => null,
        ];
    }

    public function untukGuru(User $guru): static
    {
        return $this->state(fn () => [
            'school_id' => $guru->school_id,
            'teacher_id' => $guru->id,
        ]);
    }
}
