<?php

namespace Database\Factories;

use App\Domain\Academic\Models\Subject;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Models\Question;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Urutan kunci di bawah ini MENENTUKAN: school_id ditulis lebih
            // dulu supaya closure berikutnya bisa membacanya. Closure dalam
            // definition() menerima atribut yang sudah dievaluasi SAMPAI kunci
            // itu saja - pola yang sama sudah dipakai ExamFactory, dan
            // alasannya sama: tanpa urutan ini yang datang adalah objek
            // factory, dan galatnya "could not be converted to string" dari
            // tempat yang tidak menjelaskan apa pun.
            'school_id' => School::factory(),
            'subject_id' => fn (array $a) => Subject::factory()
                ->untukSekolah(School::find($a['school_id']))->create()->id,
            'created_by' => fn (array $a) => User::factory()->guru()
                ->untukSekolah(School::find($a['school_id']))->create()->id,

            'code' => 'Q-'.fake()->unique()->numberBetween(1000, 99999),
            'title' => 'Soal '.fake()->words(3, true),
            'question_type' => JenisSoal::PilihanGanda,
            'difficulty' => 'sedang',
            'prompt_json' => ['teks' => '<p>'.fake()->sentence().'</p>'],
            'answer_key_json' => ['opsi' => ['a']],
            'scoring_config_json' => ['bobot' => 1],
            'explanation_json' => null,
            'status' => 'draft',
            'published_at' => null,
        ];
    }

    public function untukSekolah(School $school): static
    {
        return $this->state(fn () => ['school_id' => $school->id]);
    }

    /** Soal milik Guru tertentu, di sekolah dan mapel yang sama. */
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
        return $this->state(fn () => [
            'status' => 'siap',
            'published_at' => now(),
        ]);
    }

    public function arsip(): static
    {
        return $this->state(fn () => ['status' => 'arsip']);
    }

    /**
     * Soal dengan jenis dan kunci yang cocok satu sama lain.
     *
     * Dipakai seeder dan test snapshot: kunci yang tidak cocok jenisnya
     * ditolak SkemaJenisSoal, dan factory yang memberinya kombinasi
     * sembarangan akan gagal di tempat yang jauh dari penyebabnya.
     */
    public function berjenis(JenisSoal $jenis): static
    {
        return $this->state(fn () => match ($jenis) {
            JenisSoal::PilihanGanda => [
                'question_type' => $jenis,
                'answer_key_json' => ['opsi' => ['a']],
            ],
            JenisSoal::JawabanGanda => [
                'question_type' => $jenis,
                'answer_key_json' => ['opsi' => ['a', 'c']],
            ],
            JenisSoal::BenarSalah => [
                'question_type' => $jenis,
                'answer_key_json' => ['opsi' => ['b']],
            ],
            JenisSoal::IsianSingkat => [
                'question_type' => $jenis,
                'answer_key_json' => ['teks' => ['7', 'tujuh']],
            ],
            JenisSoal::Angka => [
                'question_type' => $jenis,
                'answer_key_json' => ['nilai' => 42, 'toleransi' => 0.01],
            ],
            JenisSoal::Menjodohkan => [
                'question_type' => $jenis,
                'answer_key_json' => ['pasangan' => ['k1' => 'c1', 'k2' => 'c2']],
            ],
            JenisSoal::Mengurutkan => [
                'question_type' => $jenis,
                'answer_key_json' => ['urutan' => ['c', 'a', 'b']],
            ],
            JenisSoal::Uraian => [
                'question_type' => $jenis,
                'answer_key_json' => null,
            ],
            JenisSoal::Media => [
                'question_type' => $jenis,
                'answer_key_json' => ['opsi' => ['a']],
                'scoring_config_json' => ['bobot' => 1, 'mekanisme' => 'pilihan'],
            ],
        });
    }
}
