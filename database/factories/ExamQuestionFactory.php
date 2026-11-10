<?php

namespace Database\Factories;

use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamQuestion>
 */
class ExamQuestionFactory extends Factory
{
    protected $model = ExamQuestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Urutannya MENENTUKAN: exam_id lebih dulu supaya closure
            // berikutnya bisa membacanya. Closure dalam definition() menerima
            // atribut yang sudah dievaluasi sampai kunci itu saja - pola yang
            // sama dipakai ExamFactory dan QuestionFactory.
            'exam_id' => Exam::factory(),
            'school_id' => fn (array $a) => Exam::withoutGlobalScopes()->find($a['exam_id'])?->school_id,

            'position' => 1,
            'bobot' => 1,
            'question_id' => null,
            'kode' => 'Q-'.fake()->unique()->numberBetween(1000, 99999),
            'jenis' => JenisSoal::PilihanGanda,
            'tingkat' => 'sedang',
            'judul' => 'Soal '.fake()->words(3, true),

            // Snapshot default berbentuk pilihan ganda yang lengkap. Test yang
            // butuh bentuk lain memakai `berjenis()`, karena snapshot yang
            // setengah terisi akan lulus test yang seharusnya gagal.
            'soal_json' => ['teks' => '<p>'.fake()->sentence().'</p>'],
            'opsi_json' => [
                ['kunci' => 'a', 'teks' => '<p>Pilihan pertama</p>'],
                ['kunci' => 'b', 'teks' => '<p>Pilihan kedua</p>'],
            ],
            'butir_json' => null,
            'kunci_json' => ['opsi' => ['a']],
            'rubrik_json' => null,
            'skor_json' => ['bobot' => 1],
            'pembahasan_json' => ['teks' => '<p>Karena itu jawabannya.</p>'],
            'media_json' => null,
            'dibekukan_pada' => now(),
        ];
    }

    public function untukUjian(Exam $ujian): static
    {
        return $this->state(fn () => [
            'exam_id' => $ujian->id,
            'school_id' => $ujian->school_id,
        ]);
    }

    /** Snapshot yang berasal dari soal tertentu - untuk menguji penelusuran. */
    public function dari(Question $soal): static
    {
        return $this->state(fn () => [
            'school_id' => $soal->school_id,
            'question_id' => $soal->id,
            'kode' => $soal->code,
            'jenis' => $soal->question_type,
            'judul' => $soal->title,
            'soal_json' => $soal->prompt_json,
            'kunci_json' => $soal->answer_key_json,
            'skor_json' => $soal->scoring_config_json,
            'pembahasan_json' => $soal->explanation_json,
        ]);
    }

    /**
     * Bentuk snapshot yang cocok dengan jenisnya.
     *
     * Uraian mendapat rubrik, jodohkan mendapat butir, dan kunci tiap jenis
     * mengikuti SkemaJenisSoal. Seeder memakai ini untuk membangun paket
     * berisi kesepuluh jenis tanpa snapshot yang saling bertentangan.
     */
    public function berjenis(JenisSoal $jenis): static
    {
        return $this->state(fn () => match ($jenis) {
            JenisSoal::PilihanGanda => [
                'jenis' => $jenis,
                'kunci_json' => ['opsi' => ['a']],
            ],
            JenisSoal::JawabanGanda => [
                'jenis' => $jenis,
                'kunci_json' => ['opsi' => ['a', 'b']],
            ],
            JenisSoal::BenarSalah => [
                'jenis' => $jenis,
                'opsi_json' => [
                    ['kunci' => 'b', 'teks' => '<p>Benar</p>'],
                    ['kunci' => 's', 'teks' => '<p>Salah</p>'],
                ],
                'kunci_json' => ['opsi' => ['b']],
            ],
            JenisSoal::IsianSingkat => [
                'jenis' => $jenis,
                'opsi_json' => null,
                'kunci_json' => ['teks' => ['7', 'tujuh']],
            ],
            JenisSoal::IsianRumpang => [
                'jenis' => $jenis,
                'opsi_json' => null,
                // `soal_json` ikut ditimpa: snapshot tanpa penanda tidak bisa
                // ditampilkan sebagai soal rumpang, dan kuncinya pun tidak
                // akan sepadan dengan teksnya.
                'soal_json' => ['teks' => '<p>Gaya tarik bumi disebut {{1}} dan satuannya {{2}}.</p>'],
                'kunci_json' => ['rumpang' => [['gravitasi', 'gaya gravitasi'], ['newton', 'N']]],
            ],
            JenisSoal::Angka => [
                'jenis' => $jenis,
                'opsi_json' => null,
                'kunci_json' => ['nilai' => 42, 'toleransi' => 0.01, 'satuan' => 'cm'],
            ],
            JenisSoal::Menjodohkan => [
                'jenis' => $jenis,
                'opsi_json' => null,
                'butir_json' => [
                    ['kunci' => 'k1', 'grup' => 'kiri', 'teks' => '<p>Ibu kota Jepang</p>'],
                    ['kunci' => 'k2', 'grup' => 'kiri', 'teks' => '<p>Ibu kota Prancis</p>'],
                    ['kunci' => 'c2', 'grup' => 'kanan', 'teks' => '<p>Paris</p>'],
                    ['kunci' => 'c1', 'grup' => 'kanan', 'teks' => '<p>Tokyo</p>'],
                ],
                'kunci_json' => ['pasangan' => ['k1' => 'c1', 'k2' => 'c2']],
            ],
            JenisSoal::Mengurutkan => [
                'jenis' => $jenis,
                'opsi_json' => null,
                'butir_json' => [
                    ['kunci' => 'a', 'grup' => 'butir', 'teks' => '<p>Langkah kedua</p>'],
                    ['kunci' => 'b', 'grup' => 'butir', 'teks' => '<p>Langkah ketiga</p>'],
                    ['kunci' => 'c', 'grup' => 'butir', 'teks' => '<p>Langkah pertama</p>'],
                ],
                'kunci_json' => ['urutan' => ['c', 'a', 'b']],
            ],
            JenisSoal::Uraian => [
                'jenis' => $jenis,
                'opsi_json' => null,
                'kunci_json' => null,
                'rubrik_json' => [
                    ['kriteria' => 'Ketepatan konsep', 'maks' => 5],
                    ['kriteria' => 'Kelengkapan langkah', 'maks' => 5],
                ],
            ],
            JenisSoal::Media => [
                'jenis' => $jenis,
                'kunci_json' => ['opsi' => ['a']],
                'skor_json' => ['bobot' => 1, 'mekanisme' => 'pilihan'],
                'media_json' => [
                    ['tipe' => 'audio', 'nama' => 'kutipan.mp3'],
                ],
            ],
        });
    }
}
