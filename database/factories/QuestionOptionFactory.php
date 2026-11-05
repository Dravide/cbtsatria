<?php

namespace Database\Factories;

use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionOption>
 */
class QuestionOptionFactory extends Factory
{
    protected $model = QuestionOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => fn (array $a) => Question::find($a['question_id'])?->school_id,
            'question_id' => Question::factory(),
            'option_key' => fake()->unique()->randomElement(['a', 'b', 'c', 'd', 'e']),
            'content_json' => ['teks' => fake()->sentence(3)],
            'is_correct' => false,
            'position' => 0,
        ];
    }

    public function kunci(): static
    {
        return $this->state(fn () => ['is_correct' => true]);
    }

    /**
     * Sekumpulan pilihan a-d untuk satu soal, dengan satu kunci.
     *
     * @return array<int, QuestionOption>
     */
    public static function empatPilihan(Question $soal, string $kunci = 'a'): array
    {
        $hasil = [];

        foreach (['a', 'b', 'c', 'd'] as $i => $huruf) {
            $hasil[] = QuestionOption::factory()->create([
                'school_id' => $soal->school_id,
                'question_id' => $soal->id,
                'option_key' => $huruf,
                'content_json' => ['teks' => 'Pilihan '.strtoupper($huruf)],
                'is_correct' => $huruf === $kunci,
                'position' => $i,
            ]);
        }

        return $hasil;
    }
}
