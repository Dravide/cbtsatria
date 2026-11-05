<?php

namespace Database\Factories;

use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionItem>
 */
class QuestionItemFactory extends Factory
{
    protected $model = QuestionItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => fn (array $a) => Question::find($a['question_id'])?->school_id,
            'question_id' => Question::factory(),
            'item_group' => 'kiri',
            'item_key' => 'k'.fake()->unique()->numberBetween(1, 9999),
            'content_json' => ['teks' => fake()->sentence(3)],
            'correct_match_key' => null,
            'correct_position' => null,
            'position' => 0,
        ];
    }

    public function kanan(?string $pasangan = null): static
    {
        return $this->state(fn () => [
            'item_group' => 'kanan',
            'correct_match_key' => $pasangan,
        ]);
    }
}
