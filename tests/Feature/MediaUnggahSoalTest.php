<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionMedia;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 4, diperluas - unggah media soal (gambar/audio/video) pada SOAL dan
 * pada PILIHAN JAWABAN, sampai layar siswa.
 *
 * =====================================================================
 * Yang dibuktikan berkas ini, dan urutannya menentukan
 * =====================================================================
 * Alur yang diuji adalah alur yang dipakai Guru, bukan potongannya:
 *
 *   UNGGAH (draf, belum ada soal) -> SIMPAN soal -> MEDIA TERTAUT
 *
 * Yang paling mudah terlewat: memeriksa bahwa setelah soal disunting, gambar
 * pilihan jawaban TIDAK hilang. `question_media.option_id` memakai cascade ke
 * `question_options`, dan penyuntingan menghapus-lalu-menulis-ulang opsi -
 * tanpa `lepasMediaOpsi()`, setiap penyuntingan membuang gambar diam-diam.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->lain = School::factory()->create();

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    $this->mapel = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MTK',
        'name' => 'Matematika',
    ]);

    $this->mapelLain = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'FIS',
        'name' => 'Fisika',
    ]);

    $this->kelas = SchoolClass::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);

    $this->guruA = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'unggah.a',
        'name' => 'Guru A',
    ]);

    $this->guruB = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'unggah.b',
        'name' => 'Guru B',
    ]);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'unggah.admin',
    ]);

    foreach ([[$this->guruA, $this->mapel], [$this->guruB, $this->mapel], [$this->guruA, $this->mapelLain]] as [$guru, $mapel]) {
        TeachingAssignment::factory()->create([
            'school_id' => $this->sekolah->id,
            'academic_year_id' => $this->tahun->id,
            'teacher_id' => $guru->id,
            'subject_id' => $mapel->id,
            'class_id' => $this->kelas->id,
        ]);
    }

    Storage::fake('local');
});

function masukUnggah(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/** Payload soal pilihan ganda sah, siap disimpan. */
function payloadPg(array $ganti = []): array
{
    return array_merge([
        'subject_id' => null,
        'code' => null,
        'title' => 'Soal bergambar',
        'question_type' => 'pilihan_ganda',
        'difficulty' => 'sedang',
        'status' => 'draft',
        'prompt_json' => ['teks' => '<p>Manakah grafik yang benar?</p>'],
        'answer_key_json' => ['opsi' => ['a']],
        'scoring_config_json' => ['bobot' => 1],
        'opsi' => [
            ['option_key' => 'a', 'content_json' => ['teks' => 'Grafik A'], 'position' => 0],
            ['option_key' => 'b', 'content_json' => ['teks' => 'Grafik B'], 'position' => 1],
        ],
    ], $ganti);
}

/* ============================================================ Unggah */

it('mengunggah gambar untuk soal baru sebagai media draf', function () {
    $respons = masukUnggah($this, $this->guruA)->post('/guru/bank-soal/media', [
        'berkas' => UploadedFile::fake()->image('grafik.png', 40, 40),
    ]);

    $respons->assertCreated();
    $data = $respons->json('media');

    expect($data['gambar'])->toBeTrue();
    expect($data['tipe'])->toBe('gambar');

    $baris = QuestionMedia::tanpaTenant()->findOrFail($data['id']);

    // Draf: soal belum ada, jadi question_id NULL.
    expect($baris->question_id)->toBeNull();
    expect($baris->school_id)->toBe($this->sekolah->id);
    expect($baris->metadata_json['diunggah_oleh'])->toBe($this->guruA->id);

    // Berkas benar-benar ada di disk lokal, bukan disk publik.
    Storage::disk('local')->assertExists($baris->storage_path);
});

it('menautkan media draf ke soal saat soal baru disimpan', function () {
    $unggah = masukUnggah($this, $this->guruA)->post('/guru/bank-soal/media', [
        'berkas' => UploadedFile::fake()->image('grafik.png', 40, 40),
    ])->json('media');

    masukUnggah($this, $this->guruA)
        ->post('/guru/bank-soal', payloadPg([
            'subject_id' => $this->mapel->id,
            'media_draf' => [$unggah['id']],
        ]))
        ->assertRedirect();

    $soal = Question::tanpaTenant()->where('created_by', $this->guruA->id)->firstOrFail();
    $soalId = $soal->id;

    $media = QuestionMedia::tanpaTenant()->findOrFail($unggah['id']);

    expect($media->question_id)->toBe($soalId);
    expect($media->option_id)->toBeNull();
});

it('menautkan gambar ke pilihan jawaban yang tepat saat soal baru disimpan', function () {
    $unggah = masukUnggah($this, $this->guruA)->post('/guru/bank-soal/media', [
        'berkas' => UploadedFile::fake()->image('opsi-b.png', 40, 40),
        'option_key' => 'b',
    ])->json('media');

    masukUnggah($this, $this->guruA)
        ->post('/guru/bank-soal', payloadPg([
            'subject_id' => $this->mapel->id,
            'media_opsi_draf' => [$unggah['id']],
        ]))
        ->assertRedirect();

    $soal = Question::tanpaTenant()->where('created_by', $this->guruA->id)->firstOrFail();
    $opsiB = $soal->options()->where('option_key', 'b')->firstOrFail();

    $media = QuestionMedia::tanpaTenant()->findOrFail($unggah['id']);

    expect($media->question_id)->toBe($soal->id);
    expect($media->option_id)->toBe($opsiB->id);
});

/* ============================================================ Izin */

it('menolak unggahan dari Admin yang tidak boleh menyunting soal', function () {
    // Admin boleh melihat soal, tetapi TIDAK menyunting isinya (PRD §3.1).
    // Menempel gambar ke pilihan jawaban adalah menyunting isi.
    masukUnggah($this, $this->admin)
        ->post('/guru/bank-soal/media', [
            'berkas' => UploadedFile::fake()->image('x.png', 20, 20),
        ])
        ->assertForbidden();
});

it('menolak unggahan ke soal milik Guru lain', function () {
    $soal = Question::factory()->oleh($this->guruB, $this->mapel)->create();

    masukUnggah($this, $this->guruA)
        ->post('/guru/bank-soal/media', [
            'berkas' => UploadedFile::fake()->image('x.png', 20, 20),
            'soal_id' => $soal->id,
        ])
        ->assertForbidden();
});

it('menolak berkas yang bukan gambar/audio/video', function () {
    // `Accept: application/json` disertakan karena endpoint ini memang
    // menjawab JSON - builder memanggilnya lewat `fetch`. Tanpa header itu,
    // ValidationException dirender sebagai redirect 302 (perilaku web biasa),
    // bukan 422 yang diterima klien sungguhan.
    masukUnggah($this, $this->guruA)
        ->post('/guru/bank-soal/media', [
            'berkas' => UploadedFile::fake()->create('dokumen.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);
});

/* ============================================================ Hapus */

it('menghapus media draf milik sendiri beserta berkasnya', function () {
    $unggah = masukUnggah($this, $this->guruA)->post('/guru/bank-soal/media', [
        'berkas' => UploadedFile::fake()->image('buang.png', 20, 20),
    ])->json('media');

    $baris = QuestionMedia::tanpaTenant()->findOrFail($unggah['id']);
    $jalur = $baris->storage_path;

    masukUnggah($this, $this->guruA)
        ->delete("/guru/bank-soal/media/{$unggah['id']}")
        ->assertOk();

    expect(QuestionMedia::tanpaTenant()->find($unggah['id']))->toBeNull();
    Storage::disk('local')->assertMissing($jalur);
});

it('menolak menghapus media draf milik Guru lain', function () {
    $unggah = masukUnggah($this, $this->guruA)->post('/guru/bank-soal/media', [
        'berkas' => UploadedFile::fake()->image('milik-a.png', 20, 20),
    ])->json('media');

    masukUnggah($this, $this->guruB)
        ->delete("/guru/bank-soal/media/{$unggah['id']}")
        ->assertForbidden();

    expect(QuestionMedia::tanpaTenant()->find($unggah['id']))->not->toBeNull();
});

/* ============================================================ Sunting */

it('mempertahankan gambar pilihan jawaban saat soal disunting', function () {
    // Inilah test yang menangkap cascade `question_media.option_id`: tanpa
    // lepasMediaOpsi()/tautkanUlangMediaOpsi(), menyunting soal menghapus
    // baris opsi lama, cascade menghapus medianya, dan gambar lenyap.
    $soal = Question::factory()->oleh($this->guruA, $this->mapel)->create();
    $opsiA = $soal->options()->create([
        'school_id' => $this->sekolah->id,
        'option_key' => 'a',
        'content_json' => ['teks' => 'A'],
        'is_correct' => true,
        'position' => 0,
    ]);

    $media = QuestionMedia::create([
        'school_id' => $this->sekolah->id,
        'question_id' => $soal->id,
        'option_id' => $opsiA->id,
        'media_type' => 'gambar',
        'mime_type' => 'image/png',
        'storage_path' => 'soal/'.$this->sekolah->id.'/'.$soal->id.'/opsi-a.png',
        'nama_asli' => 'opsi-a.png',
        'ukuran' => 100,
        'metadata_json' => ['diunggah_oleh' => $this->guruA->id],
    ]);

    masukUnggah($this, $this->guruA)
        ->put("/guru/bank-soal/{$soal->id}", payloadPg([
            'subject_id' => $this->mapel->id,
            'title' => 'Judul diubah',
        ]))
        ->assertRedirect();

    $media->refresh();
    $opsiBaru = Question::tanpaTenant()->find($soal->id)->options()->where('option_key', 'a')->firstOrFail();

    expect(QuestionMedia::tanpaTenant()->find($media->id))->not->toBeNull();
    expect($media->option_id)->toBe($opsiBaru->id);
});
