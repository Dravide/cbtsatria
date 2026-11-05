<?php

namespace Database\Factories;

use App\Domain\Exam\Models\ExamToken;
use App\Domain\Exam\Models\ExamTokenView;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamTokenView>
 */
class ExamTokenViewFactory extends Factory
{
    protected $model = ExamTokenView::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_token_id' => ExamToken::factory(),

            // Urutannya menentukan - lihat catatan di ExamFactory.
            'school_id' => fn (array $a) => ExamToken::find($a['exam_token_id'])?->school_id,

            'user_id' => null,
            'room_id' => null,
            'viewed_at' => now(),
            'jenis' => 'dibuka',
        ];
    }

    public function untukToken(ExamToken $token): static
    {
        return $this->state(fn () => [
            'school_id' => $token->school_id,
            'exam_token_id' => $token->id,
        ]);
    }
}
