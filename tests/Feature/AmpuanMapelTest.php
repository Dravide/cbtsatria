<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Services\AmpuanMapel;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * "Guru ini mengampu mapel itu atau tidak" - satu tempat, dua pemakai.
 *
 * =====================================================================
 * Mengapa berkas ini ada padahal perilakunya sudah diuji di dua tempat lain
 * =====================================================================
 * Aturan ampuan dipakai jalur simpan soal (`QuestionController`) dan jalur
 * Generate AI (`SoalAiController`). Keduanya punya test-nya sendiri, tetapi
 * keduanya menguji MAKSUD ("soal ini ditolak"), bukan ATURANNYA - dan justru
 * aturan itulah yang dipakai bersama.
 *
 * Yang dijaga di sini adalah bagian yang tidak pernah tampak di layar:
 * Admin dilewatkan, Guru dilewatkan hanya bila ada `TeachingAssignment` yang
 * benar-benar cocok, dan penolakannya 422 - bukan 403, karena yang salah
 * adalah nilai yang dikirim, bukan izinnya.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    $this->mapel = Subject::factory()->untukSekolah($this->sekolah)->create(['code' => 'MTK']);
    $this->mapelLain = Subject::factory()->untukSekolah($this->sekolah)->create(['code' => 'FIS']);

    $this->kelas = SchoolClass::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);

    $this->guru = User::factory()->guru()->untukSekolah($this->sekolah)->create();
    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create();

    TeachingAssignment::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'teacher_id' => $this->guru->id,
        'subject_id' => $this->mapel->id,
        'class_id' => $this->kelas->id,
    ]);
});

function masukAmpuan(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

it('meloloskan Guru yang mengampu mapelnya', function () {
    expect(fn () => app(AmpuanMapel::class)
        ->pastikan($this->guru, (int) $this->mapel->id))
        ->not->toThrow(HttpException::class);
});

it('menolak Guru yang tidak mengampu mapelnya dengan 422', function () {
    try {
        app(AmpuanMapel::class)
            ->pastikan($this->guru, (int) $this->mapelLain->id);

        $this->fail('Guru yang tidak mengampu seharusnya ditolak.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422)
            ->and($e->getMessage())->toBe('Anda tidak mengampu mata pelajaran tersebut.');
    }
});

it('meloloskan Admin tanpa memeriksa ampuan', function () {
    // PRD §3.1 memberi Admin "lihat semua". Gerbang MENULIS tetap
    // QuestionPolicy::create(), yang menolak Admin di tempat lain - jadi
    // melewatinya di sini bukan berarti Admin bisa menyimpan soal.
    expect(fn () => app(AmpuanMapel::class)
        ->pastikan($this->admin, (int) $this->mapelLain->id))
        ->not->toThrow(HttpException::class);
});

it('tetap menegakkan ampuan saat menyimpan soal lewat form builder', function () {
    // Ekstraksi ke service tidak boleh mengubah perilaku jalur lama.
    masukAmpuan($this, $this->guru)
        ->post('/guru/bank-soal', [
            'subject_id' => $this->mapelLain->id,
            'title' => 'Soal mapel lain',
            'question_type' => 'pilihan_ganda',
            'difficulty' => 'sedang',
            'status' => 'draft',
            'prompt_json' => ['teks' => '<p>Pertanyaan</p>'],
            'answer_key_json' => ['opsi' => ['a']],
            'scoring_config_json' => ['bobot' => 2],
            'opsi' => [
                ['option_key' => 'a', 'content_json' => ['teks' => '1'], 'position' => 0],
                ['option_key' => 'b', 'content_json' => ['teks' => '2'], 'position' => 1],
            ],
        ])
        ->assertStatus(422);

    expect(Question::query()->count())->toBe(0);
});
