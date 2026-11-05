<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Models\EssayRubricCriterion;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionMedia;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Fase 4 - pratinjau soal dan paket. Wireframe-peran.html plate 09.
 *
 * =====================================================================
 * Dua klaim, dan keduanya soal BENTUK PAYLOAD - bukan soal izin
 * =====================================================================
 * Izinnya sudah diuji di BankSoalTest dan PaketSoalTest; yang belum diuji di
 * sana adalah hal yang justru jadi alasan laman ini ada:
 *
 *   1. PRATINJAU BENAR-BENAR MENGIRIM ISI SOALNYA.
 *      Kalau `prompt_json` sampai ke peramban, tetapi kuncinya tidak, Guru
 *      tidak bisa memeriksa apa yang paling perlu diperiksa sebelum ujian
 *      berjalan. Testnya karena itu `->has('soal.kunci')`, bukan "lamannya
 *      200".
 *
 *   2. MEDIA DIKIRIM SEBAGAI TAUTAN, BUKAN SEBAGAI JALUR DISK.
 *      `storage_path` adalah nama acak 40 karakter di disk privat. Kalau ia
 *      yang sampai ke peramban, gambarnya tidak akan pernah tampil - dan yang
 *      terlihat bukan galat, melainkan gambar rusak. Testnya memeriksa bentuk
 *      `url`-nya, dan sekaligus bahwa tidak ada satu pun nilai di payload yang
 *      memuat jalur disknya.
 *
 * ---------------------------------------------------------------------
 * Inilah bedanya dengan `untukSiswa()`, dan test ini yang menjaganya
 * ---------------------------------------------------------------------
 * `ExamQuestion::untukSiswa()` membuang kunci, rubrik, dan pembahasan. Laman
 * ini TIDAK membuangnya, dan itu disengaja: yang membacanya adalah Guru
 * penyusunnya lewat rute ber-Gate, bukan siswa. Dua test dengan arah berlawanan
 * itu sengaja hidup berdampingan supaya perbedaannya tidak pernah melebur
 * menjadi satu - kalau kelak salah satunya "diperbaiki" agar sama dengan yang
 * lain, satu di antaranya pasti rusak.
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
        'username' => 'tinjau.a',
        'name' => 'Guru A',
    ]);

    $this->guruB = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'tinjau.b',
        'name' => 'Guru B',
    ]);

    // Guru A mengampu Matematika DAN Fisika; Guru B hanya Matematika.
    // Tanpa ketidaksimetrisan ini, "404 karena mapel berbeda" tidak bisa
    // dibedakan dari "404 karena Guru B tidak mengampu apa pun".
    foreach ([[$this->guruA, $this->mapel], [$this->guruA, $this->mapelLain], [$this->guruB, $this->mapel]] as [$guru, $mapel]) {
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

function masukTinjau(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/* ======================================================== Pratinjau soal */

it('mengirim isi soal lengkap termasuk kunci dan pembahasan', function () {
    $soal = Question::factory()->oleh($this->guruA, $this->mapel)->create([
        'title' => 'Himpunan penyelesaian',
        'question_type' => JenisSoal::PilihanGanda,
        'prompt_json' => ['teks' => '<p>Himpunan penyelesaian dari <b>2x + 5 = 17</b></p>'],
        'answer_key_json' => ['opsi' => ['a']],
        'scoring_config_json' => ['bobot' => 2],
        'explanation_json' => ['teks' => '<p>Kurangi lima dari kedua ruas.</p>'],
    ]);

    masukTinjau($this, $this->guruA)
        ->get("/guru/bank-soal/{$soal->id}/pratinjau")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Guru/BankSoal/Pratinjau')
            ->where('soal.judul', 'Himpunan penyelesaian')
            ->where('soal.jenis', 'pilihan_ganda')
            ->where('soal.prompt.teks', '<p>Himpunan penyelesaian dari <b>2x + 5 = 17</b></p>')
            // Kebalikan dari ExamQuestion::untukSiswa - lihat docblock berkas.
            ->has('soal.kunci.opsi')
            ->where('soal.skor.bobot', 2)
            ->where('soal.pembahasan.teks', '<p>Kurangi lima dari kedua ruas.</p>')
            ->where('bolehUbah', true)
        );
});

it('menandai kunci hanya pada pilihan yang benar', function () {
    $soal = Question::factory()->oleh($this->guruA, $this->mapel)->create([
        'question_type' => JenisSoal::PilihanGanda,
        'answer_key_json' => ['opsi' => ['c']],
    ]);

    foreach (['a', 'b', 'c', 'd'] as $i => $huruf) {
        $soal->options()->create([
            'school_id' => $this->sekolah->id,
            'option_key' => $huruf,
            'content_json' => ['teks' => "Pilihan {$huruf}"],
            'is_correct' => $huruf === 'c',
            'position' => $i,
        ]);
    }

    $respons = masukTinjau($this, $this->guruA)->get("/guru/bank-soal/{$soal->id}/pratinjau");

    $respons->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->has('soal.opsi', 4)
        ->where('soal.opsi.2.option_key', 'c')
        ->where('soal.opsi.2.is_correct', true)
        ->where('soal.opsi.0.is_correct', false)
    );
});

it('mengirim media sebagai tautan rute, bukan sebagai jalur disk', function () {
    $soal = Question::factory()->oleh($this->guruA, $this->mapel)->create();

    $jalur = 'soal/'.$this->sekolah->id.'/'.$soal->id.'/'.str_repeat('z', 40).'.png';
    Storage::disk('local')->put($jalur, 'PNG-PALSU');

    $media = QuestionMedia::create([
        'school_id' => $this->sekolah->id,
        'question_id' => $soal->id,
        'media_type' => 'gambar',
        'mime_type' => 'image/png',
        'storage_path' => $jalur,
        'nama_asli' => 'grafik.png',
        'ukuran' => 9,
        'metadata_json' => null,
    ]);

    $respons = masukTinjau($this, $this->guruA)->get("/guru/bank-soal/{$soal->id}/pratinjau");

    $respons->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->has('soal.media', 1)
        ->where('soal.media.0.id', $media->id)
        ->where('soal.media.0.nama', 'grafik.png')
        ->where('soal.media.0.gambar', true)
        ->where('soal.media.0.url', route('guru.media', $media->id))
    );

    // Jalur disknya TIDAK boleh muncul di mana pun. Diperiksa pada payload
    // mentah, bukan pada satu field: yang bocor biasanya bukan field yang
    // diingat, melainkan field yang lupa dibuang.
    expect(json_encode($respons->viewData('page')['props']))->not->toContain($jalur);
});

it('mengirim rubrik uraian beserta totalnya', function () {
    $soal = Question::factory()->oleh($this->guruA, $this->mapel)->create([
        'question_type' => JenisSoal::Uraian,
        'answer_key_json' => null,
    ]);

    foreach ([['Ketepatan konsep', 5], ['Kelengkapan langkah', 3], ['Kerapian', 2]] as $i => [$kriteria, $maks]) {
        EssayRubricCriterion::create([
            'school_id' => $this->sekolah->id,
            'question_id' => $soal->id,
            'criterion' => $kriteria,
            'description' => null,
            'max_score' => $maks,
            'position' => $i,
        ]);
    }

    masukTinjau($this, $this->guruA)
        ->get("/guru/bank-soal/{$soal->id}/pratinjau")
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->has('soal.rubrik', 3)
            ->where('soal.rubrik.0.criterion', 'Ketepatan konsep')
            ->where('soal.rubrik.0.max_score', 5)
            // Uraian tidak punya kunci - dan `null` yang dikirim apa adanya
            // lebih jujur daripada `{}` yang membuat layar merender kotak
            // "Kunci jawaban" kosong.
            ->where('soal.kunci', null)
        );
});

it('membuka pratinjau soal Guru lain tanpa tombol ubah', function () {
    $soal = Question::factory()->oleh($this->guruB, $this->mapel)->create();

    masukTinjau($this, $this->guruA)
        ->get("/guru/bank-soal/{$soal->id}/pratinjau")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Guru/BankSoal/Pratinjau')
            // Soalnya terbaca; yang tidak boleh adalah menyimpannya. Laman
            // yang menolak dibuka bagi pembaca yang sah akan membuat Guru
            // menebak-nebak izinnya sendiri.
            ->where('bolehUbah', false)
        );
});

it('mengembalikan 404 untuk pratinjau soal mapel yang tidak diampu', function () {
    $soal = Question::factory()->oleh($this->guruA, $this->mapelLain)->create();

    masukTinjau($this, $this->guruB)
        ->get("/guru/bank-soal/{$soal->id}/pratinjau")
        ->assertNotFound();
});

it('mengembalikan 404 untuk pratinjau soal sekolah lain', function () {
    $guruLain = User::factory()->guru()->untukSekolah($this->lain)->create();
    $mapelLain = Subject::factory()->untukSekolah($this->lain)->create(['code' => 'KIM']);
    $soal = Question::factory()->oleh($guruLain, $mapelLain)->create();

    masukTinjau($this, $this->guruA)
        ->get("/guru/bank-soal/{$soal->id}/pratinjau")
        ->assertNotFound();
});

/* ======================================================= Pratinjau paket */

/** Paket Matematika berisi tiga soal, milik Guru A. */
function paketTinjau(School $sekolah, User $guru, Subject $mapel): QuestionPackage
{
    $paket = QuestionPackage::factory()->oleh($guru, $mapel)->create(['name' => 'Paket MTK X']);

    foreach ([['a', 40], ['b', 30], ['c', 30]] as $posisi => [$huruf, $bobot]) {
        $soal = Question::factory()->oleh($guru, $mapel)->create([
            'school_id' => $sekolah->id,
            'question_type' => JenisSoal::PilihanGanda,
            'answer_key_json' => ['opsi' => [$huruf]],
            'scoring_config_json' => ['bobot' => $bobot],
        ]);

        QuestionPackageItem::create([
            'school_id' => $sekolah->id,
            'question_package_id' => $paket->id,
            'question_id' => $soal->id,
            'points_override' => null,
            'position' => $posisi,
        ]);
    }

    return $paket->fresh();
}

it('mengirim seluruh butir paket berurutan beserta nomor dan poinnya', function () {
    $paket = paketTinjau($this->sekolah, $this->guruA, $this->mapel);

    masukTinjau($this, $this->guruA)
        ->get("/guru/paket/{$paket->id}/pratinjau")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Guru/Paket/Pratinjau')
            ->where('paket.nama', 'Paket MTK X')
            ->where('paket.mapel', 'Matematika')
            ->has('butir', 3)
            // Nomor diberikan controller, bukan dihitung di layar: urutannya
            // milik paket, dan layar yang menghitungnya sendiri akan menomori
            // ulang butir yang tersaring.
            ->where('butir.0.nomor', 1)
            ->where('butir.1.nomor', 2)
            ->where('butir.2.nomor', 3)
            ->where('butir.0.poin', 40)
            ->where('butir.2.poin', 30)
            // Poin berasal dari bobot soalnya, karena `points_override` null.
            ->where('butir.0.poin_override', null)
        );
});

it('mengirim poin timpaan bila butirnya punya poin khusus', function () {
    $paket = QuestionPackage::factory()->oleh($this->guruA, $this->mapel)->create();

    $soal = Question::factory()->oleh($this->guruA, $this->mapel)->create([
        'school_id' => $this->sekolah->id,
        'scoring_config_json' => ['bobot' => 5],
    ]);

    QuestionPackageItem::create([
        'school_id' => $this->sekolah->id,
        'question_package_id' => $paket->id,
        'question_id' => $soal->id,
        'points_override' => 12,
        'position' => 0,
    ]);

    masukTinjau($this, $this->guruA)
        ->get("/guru/paket/{$paket->id}/pratinjau")
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            // Poin BERLAKU adalah timpaannya, dan timpaannya dikirim terpisah
            // supaya layar bisa mengatakan "poin khusus" alih-alih diam-diam
            // menampilkan angka yang berbeda dari bobot soalnya.
            ->where('butir.0.poin', 12)
            ->where('butir.0.poin_override', 12)
        );
});

it('membuka pratinjau paket Guru lain tanpa tombol ubah', function () {
    $paket = paketTinjau($this->sekolah, $this->guruA, $this->mapel);

    masukTinjau($this, $this->guruB)
        ->get("/guru/paket/{$paket->id}/pratinjau")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Guru/Paket/Pratinjau')
            ->where('paket.penulis', 'Guru A')
            ->where('bolehUbah', false)
        );
});

it('mengirim paket kosong tanpa butir, bukan 404', function () {
    $paket = QuestionPackage::factory()->oleh($this->guruA, $this->mapel)->create();

    masukTinjau($this, $this->guruA)
        ->get("/guru/paket/{$paket->id}/pratinjau")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->has('butir', 0)
        );
});

it('mengembalikan 404 untuk pratinjau paket mapel yang tidak diampu', function () {
    $paket = QuestionPackage::factory()->oleh($this->guruA, $this->mapelLain)->create();

    masukTinjau($this, $this->guruB)
        ->get("/guru/paket/{$paket->id}/pratinjau")
        ->assertNotFound();
});

it('mengembalikan 404 untuk pratinjau paket sekolah lain', function () {
    $guruLain = User::factory()->guru()->untukSekolah($this->lain)->create();
    $mapelLain = Subject::factory()->untukSekolah($this->lain)->create(['code' => 'KIM']);
    $paket = QuestionPackage::factory()->oleh($guruLain, $mapelLain)->create();

    masukTinjau($this, $this->guruA)
        ->get("/guru/paket/{$paket->id}/pratinjau")
        ->assertNotFound();
});

/* ============================================================ Pintu peran */

it('menolak tamu dan siswa di pintu pratinjau', function () {
    $soal = Question::factory()->oleh($this->guruA, $this->mapel)->create();

    $this->get("/guru/bank-soal/{$soal->id}/pratinjau")->assertRedirect('/login');

    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create();

    // 403, bukan 404: keberadaan /guru bukan rahasia. Tanpa middleware `guru`,
    // Siswa akan menerima kunci jawabannya sendiri di sini - dan pratinjau
    // inilah satu-satunya laman Fase 4 yang mengirim kunci ke peramban.
    masukTinjau($this, $siswa)
        ->get("/guru/bank-soal/{$soal->id}/pratinjau")
        ->assertForbidden();
});
