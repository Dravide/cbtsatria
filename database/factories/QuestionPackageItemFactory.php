<?php

namespace Database\Factories;

use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionPackageItem>
 */
class QuestionPackageItemFactory extends Factory
{
    protected $model = QuestionPackageItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => fn (array $a) => QuestionPackage::find($a['question_package_id'])?->school_id,
            'question_package_id' => QuestionPackage::factory(),
            'question_id' => Question::factory(),
            // NULL, bukan 0 - lihat catatan di model QuestionPackageItem:
            // null berarti "pakai bobot soalnya", 0 berarti "tidak bernilai".
            'points_override' => null,
            'position' => 0,
        ];
    }
}
