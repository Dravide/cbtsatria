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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 4 - pembaca berkas media soal. Wireframe plate 03.
 *
 * =====================================================================
 * Mengapa berkasnya ada di disk PRIVAT, dan test ini yang membuktikannya
 * =====================================================================
 * Bank soal adalah aset sekolah: satu mapel bisa punya 300 soal, dan hanya 40
 * di antaranya masuk ujian. Berkas soal yang bisa dibaca siapa pun yang menebak
 * namanya berarti seluruh bank soal satu sekolah bocor lewat gambarnya - bukan
 * lewat soalnya, yang toh akan dilihat siswa.
 *
 * Karena itu tesnya bukan "berkasnya bisa dibuka". Tesnya adalah MATRIKS
 * PENOLAKAN: empat jalan masuk, dan empat-empatnya berakhir 404:
 *
 *   1. Guru dari mapel lain            - soalnya memang tidak ada baginya
 *   2. Guru sekolah lain               - SchoolScope pada binding implisit
 *   3. Baris media yang berkasnya hilang dari disk
 *   4. Baris media tanpa soal (yatim)
 *
 * ---------------------------------------------------------------------
 * Empat-empatnya 404, tidak satu pun 403
 * ---------------------------------------------------------------------
 * Dan itu bukan kerapian. Bank soal kini bersama, sehingga "tidak ada" dan
 * "bukan milikmu" adalah dua kalimat yang benar-benar berbeda bagi Guru - dan
 * 403 pada berkas yang tadi ia lihat di pratinjau akan membuatnya mengira
 * gambarnya rusak, lalu melaporkan kerusakan yang tidak ada.
 *
 * Jawaban 403 di lantai ini justru satuan informasi: ia memberi tahu penebak
 * bahwa barisnya ADA. Empat-empatnya karena itu 404, seragam.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->lain = School::factory()->create();

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    $this->mapel = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MTK',
        'name' => 'Matematika',
    ]);

    // Mapel yang TIDAK diampu Guru B - pembanding yang membuat "mapel yang
    // tidak diampu" bisa dibedakan dari "mapelnya tidak ada".
    $this->mapelLain = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'FIS',
        'name' => 'Fisika',
    ]);

    $this->kelas = SchoolClass::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);

    $this->guruA = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'media.a',
        'name' => 'Guru A',
    ]);

    $this->guruB = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'media.b',
        'name' => 'Guru B',
    ]);

    foreach ([[$this->guruA, $this->mapel], [$this->guruA, $this->mapelLain]] as [$guru, $mapel]) {
        TeachingAssignment::factory()->create([
            'school_id' => $this->sekolah->id,
            'academic_year_id' => $this->tahun->id,
            'teacher_id' => $guru->id,
            'subject_id' => $mapel->id,
            'class_id' => $this->kelas->id,
        ]);
    }

    // Disk `local` diganti disk palsu. Controller-nya membaca `local` secara
    // harfiah, dan itulah yang ingin diuji: berkasnya memang harus berada di
    // disk privat, bukan di disk `public` yang punya symlink.
    Storage::fake('local');
});

function masukMedia(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/**
 * Satu soal bergambar beserta berkasnya di disk.
 *
 * Nama di disk sengaja acak - meniru `Str::random(40)` yang dipakai service
 * unggah - supaya test tidak lulus karena kebetulan namanya bisa ditebak.
 */
function soalBergambar(School $sekolah, User $penulis, Subject $mapel, string $isi = 'PNG-PALSU'): QuestionMedia
{
    $soal = Question::factory()->oleh($penulis, $mapel)->create([
        'school_id' => $sekolah->id,
    ]);

    $jalur = 'soal/'.$sekolah->id.'/'.$soal->id.'/'.str_repeat('a', 40).'.png';
    Storage::disk('local')->put($jalur, $isi);

    return QuestionMedia::create([
        'school_id' => $sekolah->id,
        'question_id' => $soal->id,
        'media_type' => 'gambar',
        'mime_type' => 'image/png',
        'storage_path' => $jalur,
        'nama_asli' => 'grafik-fungsi.png',
        'ukuran' => strlen($isi),
        'metadata_json' => ['judul' => 'Grafik'],
    ]);
}

/* ============================================================== Bahagia */

it('mengirim berkas media kepada Guru yang mengampu mapelnya', function () {
    $media = soalBergambar($this->sekolah, $this->guruB, $this->mapel);

    $respons = masukMedia($this, $this->guruA)->get("/guru/berkas/{$media->id}");

    $respons->assertOk();
    $respons->assertHeader('content-type', 'image/png');
    expect($respons->streamedContent())->toBe('PNG-PALSU');
});

it('memakai nama asli berkas sebagai nama unduhan, bukan nama acak di disk', function () {
    $media = soalBergambar($this->sekolah, $this->guruA, $this->mapel);

    $respons = masukMedia($this, $this->guruA)->get("/guru/berkas/{$media->id}");

    // `inline`, bukan `attachment`: yang membuka pratinjau ingin MELIHAT
    // gambarnya. Memaksa unduhan akan mengotori folder unduhan setiap kali
    // satu soal dibuka.
    $respons->assertHeader('content-disposition', 'inline; filename=grafik-fungsi.png');
});

it('membuka berkas media yang dipakai bersama lewat soal Guru lain', function () {
    // Bank soal adalah aset sekolah: Guru A memakai soal Guru B di paketnya,
    // dan saat pratinjau ia harus bisa MELIHAT gambarnya. Media yang hanya
    // terbaca penulisnya akan membuat paket bersama menampilkan gambar rusak.
    $media = soalBergambar($this->sekolah, $this->guruB, $this->mapel);

    masukMedia($this, $this->guruA)
        ->get("/guru/berkas/{$media->id}")
        ->assertOk();
});

/* ============================================================ Penolakan */

it('mengembalikan 404 kepada Guru yang mapelnya berbeda', function () {
    // Guru B tidak mengampu apa pun - soalnya memang tidak ada baginya.
    $media = soalBergambar($this->sekolah, $this->guruA, $this->mapel);

    masukMedia($this, $this->guruB)
        ->get("/guru/berkas/{$media->id}")
        ->assertNotFound();
});

it('mengembalikan 404 untuk media sekolah lain', function () {
    $guruLain = User::factory()->guru()->untukSekolah($this->lain)->create();

    $mapelLain = Subject::factory()->untukSekolah($this->lain)->create(['code' => 'KIM']);
    $media = soalBergambar($this->lain, $guruLain, $mapelLain);

    // Binding implisit berjalan lewat SchoolScope, sehingga barisnya tidak
    // pernah ditemukan - bukan ditemukan lalu ditolak.
    masukMedia($this, $this->guruA)
        ->get("/guru/berkas/{$media->id}")
        ->assertNotFound();
});

it('mengembalikan 404 bila berkasnya sudah tidak ada di penyimpanan', function () {
    $media = soalBergambar($this->sekolah, $this->guruA, $this->mapel);

    // Keadaan yang benar-benar bisa terjadi: pemulihan cadangan yang tidak
    // menyertakan berkasnya, atau pembersihan manual. 500 untuk keadaan itu
    // tidak memberi tahu siapa pun apa yang sebenarnya terjadi.
    Storage::disk('local')->delete($media->storage_path);

    masukMedia($this, $this->guruA)
        ->get("/guru/berkas/{$media->id}")
        ->assertNotFound();
});

it('mengembalikan 404 untuk media yatim yang soalnya sudah hilang', function () {
    $media = soalBergambar($this->sekolah, $this->guruA, $this->mapel);

    // Soal dihapus tanpa memakai jalur biasa - barisnya bisa tertinggal.
    Question::withoutEvents(fn () => Question::query()->whereKey($media->question_id)->delete());

    masukMedia($this, $this->guruA)
        ->get("/guru/berkas/{$media->id}")
        ->assertNotFound();
});

it('menolak tamu dan siswa di pintu berkas', function () {
    $media = soalBergambar($this->sekolah, $this->guruA, $this->mapel);

    $this->get("/guru/berkas/{$media->id}")->assertRedirect('/login');

    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create();

    masukMedia($this, $siswa)
        ->get("/guru/berkas/{$media->id}")
        ->assertForbidden();
});
