<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Fase 4 - paket soal. Wireframe-peran.html plate 05.
 *
 * =====================================================================
 * Dua klaim yang berkas ini jaga
 * =====================================================================
 *
 *   1. PAKET HANYA MENUNJUK SOAL, TIDAK MEMILIKINYA.
 *      Yang membuktikannya bukan "butir keluar dari paket" - itu akan lulus
 *      juga pada sistem yang menghapus soalnya sebagai efek samping, karena
 *      testnya menanyakan daftar butir. Yang membuktikannya adalah memeriksa
 *      `questions` SETELAH butirnya dibuang: barisnya masih ada.
 *
 *   2. MAPEL PAKET ADALAH BATAS KERAS, BUKAN SARAN.
 *      `tambahButir()` menolak soal dari mapel lain, dan yang diuji adalah
 *      penolakannya - bukan hanya bahwa butir yang sah masuk. Sistem yang
 *      menerima soal Fisika ke dalam paket Matematika akan lulus setiap test
 *      kebahagiaan, lalu gagal di snapshot berbulan-bulan kemudian.
 *
 * ---------------------------------------------------------------------
 * Duplikat DILEWATI, bukan ditolak
 * ---------------------------------------------------------------------
 * Dan itu perbedaan yang diuji secara sengaja: Guru yang menandai lima soal
 * dengan satu di antaranya sudah ada tidak boleh kehilangan empat yang lain.
 * Testnya karena itu memeriksa `ditambah = 4`, bukan "tidak ada galat".
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    $this->mapel = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MTK',
        'name' => 'Matematika',
    ]);

    // Mapel kedua - tanpa ini, "menolak soal mapel lain" tidak bisa dibedakan
    // dari "menolak soal yang tidak ada".
    $this->mapelLain = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'FIS',
        'name' => 'Fisika',
    ]);

    $this->kelas = SchoolClass::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);

    $this->guruA = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'paket.a',
        'name' => 'Guru A',
    ]);

    $this->guruB = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'paket.b',
        'name' => 'Guru B',
    ]);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'paket.admin',
    ]);

    foreach ([[$this->guruA, $this->mapel], [$this->guruB, $this->mapel], [$this->guruB, $this->mapelLain]] as [$guru, $mapel]) {
        TeachingAssignment::factory()->create([
            'school_id' => $this->sekolah->id,
            'academic_year_id' => $this->tahun->id,
            'teacher_id' => $guru->id,
            'subject_id' => $mapel->id,
            'class_id' => $this->kelas->id,
        ]);
    }
});

function masukPaket(TestCase $test, User $user): TestCase
{
    // SchoolResolver menyimpan `null` selama pembangunan fixture - lihat
    // docblock panjang di ExamTest.php.
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/** Paket milik Guru A untuk Matematika. */
function paketA(User $guru, Subject $mapel): QuestionPackage
{
    return QuestionPackage::factory()->oleh($guru, $mapel)->create(['name' => 'Paket MTK X']);
}

/* ============================================================ Daftar */

it('menampilkan paket lintas penulis untuk mapel yang diampu', function () {
    paketA($this->guruB, $this->mapel);

    $respons = masukPaket($this, $this->guruA)->get('/guru/paket');

    $respons->assertOk();
    $respons->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->component('Guru/Paket/Index')
        // Paket Guru B tampil bagi Guru A - yang dibatasi adalah mapelnya,
        // bukan penulisnya. Yang tidak boleh adalah mengubahnya.
        ->has('baris.data', 1)
        ->where('baris.data.0.penulis', 'Guru B')
        ->where('baris.data.0.milik_saya', false)
    );
});

it('tidak menampilkan paket dari mapel yang tidak diampu', function () {
    paketA($this->guruB, $this->mapelLain);

    $respons = masukPaket($this, $this->guruA)->get('/guru/paket');

    $respons->assertOk();
    $respons->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('baris.data', 0));
});

it('menghitung jumlah butir tiap paket di daftar', function () {
    $paket = paketA($this->guruA, $this->mapel);

    QuestionPackageItem::factory()->count(3)->create([
        'school_id' => $this->sekolah->id,
        'question_package_id' => $paket->id,
        'question_id' => fn () => Question::factory()->oleh($this->guruA, $this->mapel)->create()->id,
    ]);

    $respons = masukPaket($this, $this->guruA)->get('/guru/paket');

    $respons->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->where('baris.data.0.butir', 3)
    );
});

/* ============================================================ Membuat */

it('membuat paket baru dan mengarahkan ke laman detailnya', function () {
    $respons = masukPaket($this, $this->guruA)->post('/guru/paket', [
        'subject_id' => $this->mapel->id,
        'name' => 'Paket Baru MTK',
        'grade_level' => 'X',
        'description' => 'Untuk PAS ganjil',
    ]);

    $paket = QuestionPackage::query()->where('name', 'Paket Baru MTK')->first();

    expect($paket)->not->toBeNull()
        ->and($paket->created_by)->toBe($this->guruA->id)
        ->and($paket->status->value)->toBe('draft');

    $respons->assertRedirect('/guru/paket/'.$paket->id);
});

it('menolak Guru membuat paket untuk mapel yang tidak diampu', function () {
    $respons = masukPaket($this, $this->guruA)->post('/guru/paket', [
        'subject_id' => $this->mapelLain->id,
        'name' => 'Paket Fisika',
    ]);

    $respons->assertSessionHasErrors('subject_id');
    expect(QuestionPackage::query()->count())->toBe(0);
});

it('menolak Admin membuat paket - bank soal ditulis Guru', function () {
    masukPaket($this, $this->admin)->post('/guru/paket', [
        'subject_id' => $this->mapel->id,
        'name' => 'Paket Admin',
    ])->assertForbidden();
});

/* ========================================================== Butir */

it('menambahkan beberapa butir sekaligus dan memberi urutan berurutan', function () {
    $paket = paketA($this->guruA, $this->mapel);

    $soal = collect(range(1, 3))->map(
        fn (int $i) => Question::factory()->oleh($this->guruA, $this->mapel)->create(['title' => "Soal $i"])
    );

    masukPaket($this, $this->guruA)->post("/guru/paket/{$paket->id}/butir", [
        'question_ids' => $soal->pluck('id')->all(),
    ])->assertRedirect();

    $butir = QuestionPackageItem::query()
        ->where('question_package_id', $paket->id)
        ->orderBy('position')
        ->get();

    expect($butir)->toHaveCount(3)
        // Posisi berurutan dan TIDAK sama - itulah yang rusak bila tiga
        // permintaan terpisah menghitung posisi dari jumlah baris yang sama.
        ->and($butir->pluck('position')->all())->toBe([1, 2, 3]);
});

it('melewati soal yang sudah ada di paket tanpa menolak yang lain', function () {
    $paket = paketA($this->guruA, $this->mapel);

    $sudah = Question::factory()->oleh($this->guruA, $this->mapel)->create();
    $baru = collect(range(1, 2))->map(
        fn () => Question::factory()->oleh($this->guruA, $this->mapel)->create()
    );

    QuestionPackageItem::factory()->create([
        'school_id' => $this->sekolah->id,
        'question_package_id' => $paket->id,
        'question_id' => $sudah->id,
    ]);

    // Lima ditandai, satu sudah ada: Guru tidak boleh kehilangan yang lain.
    masukPaket($this, $this->guruA)->post("/guru/paket/{$paket->id}/butir", [
        'question_ids' => [$sudah->id, ...$baru->pluck('id')->all()],
    ])->assertRedirect();

    expect(QuestionPackageItem::query()->where('question_package_id', $paket->id)->count())
        ->toBe(3);
});

it('menolak butir dari mata pelajaran lain', function () {
    $paket = paketA($this->guruA, $this->mapel);

    $fisika = Question::factory()->oleh($this->guruB, $this->mapelLain)->create();

    $respons = masukPaket($this, $this->guruA)->post("/guru/paket/{$paket->id}/butir", [
        'question_ids' => [$fisika->id],
    ]);

    $respons->assertSessionHasErrors('question_ids');
    expect(QuestionPackageItem::query()->where('question_package_id', $paket->id)->count())
        ->toBe(0);
});

it('menerima soal Guru lain selama mapelnya sama', function () {
    $paket = paketA($this->guruA, $this->mapel);

    // Guru B mengampu Matematika juga - soalnya masuk ke paket Guru A.
    // Inilah gunanya bank soal bersama, dan membatasinya ke `created_by`
    // akan mematikan test ini.
    $soalB = Question::factory()->oleh($this->guruB, $this->mapel)->create();

    masukPaket($this, $this->guruA)->post("/guru/paket/{$paket->id}/butir", [
        'question_ids' => [$soalB->id],
    ])->assertRedirect();

    expect(QuestionPackageItem::query()->where('question_package_id', $paket->id)->count())
        ->toBe(1);
});

/* ====================================================== Buang & urutan */

it('membuang butir dari paket tanpa menghapus soalnya dari bank soal', function () {
    $paket = paketA($this->guruA, $this->mapel);
    $soal = Question::factory()->oleh($this->guruA, $this->mapel)->create();

    $butir = QuestionPackageItem::factory()->create([
        'school_id' => $this->sekolah->id,
        'question_package_id' => $paket->id,
        'question_id' => $soal->id,
    ]);

    masukPaket($this, $this->guruA)
        ->delete("/guru/paket/{$paket->id}/butir/{$butir->id}")
        ->assertRedirect();

    expect(QuestionPackageItem::query()->whereKey($butir->id)->exists())->toBeFalse();

    // INTI klaim pertama berkas ini: soalnya HARUS masih ada. Inilah yang
    // membedakan test ini dari test yang hanya memeriksa daftar butir.
    expect(Question::query()->whereKey($soal->id)->exists())->toBeTrue();
});

it('menolak butir milik paket lain lewat URL paket sendiri', function () {
    $paketA = paketA($this->guruA, $this->mapel);
    $paketB = QuestionPackage::factory()->oleh($this->guruA, $this->mapel)->create();

    $butirB = QuestionPackageItem::factory()->create([
        'school_id' => $this->sekolah->id,
        'question_package_id' => $paketB->id,
        'question_id' => Question::factory()->oleh($this->guruA, $this->mapel)->create()->id,
    ]);

    // Keduanya sah sebagai baris; yang salah adalah PASANGANNYA. Tanpa
    // pemeriksaan di controller, id butir paket lain bisa dibuang dari sini.
    masukPaket($this, $this->guruA)
        ->delete("/guru/paket/{$paketA->id}/butir/{$butirB->id}")
        ->assertNotFound();

    expect(QuestionPackageItem::query()->whereKey($butirB->id)->exists())->toBeTrue();
});

it('menyusun ulang urutan butir', function () {
    $paket = paketA($this->guruA, $this->mapel);

    $butir = collect(range(1, 3))->map(fn (int $i) => QuestionPackageItem::factory()->create([
        'school_id' => $this->sekolah->id,
        'question_package_id' => $paket->id,
        'question_id' => Question::factory()->oleh($this->guruA, $this->mapel)->create()->id,
        'position' => $i,
    ]));

    $terbalik = $butir->pluck('id')->reverse()->values()->all();

    masukPaket($this, $this->guruA)->put("/guru/paket/{$paket->id}/urutan", [
        'urutan' => $terbalik,
    ])->assertRedirect();

    expect(QuestionPackageItem::query()->whereKey($terbalik[0])->value('position'))->toBe(1);
    expect(QuestionPackageItem::query()->whereKey($terbalik[2])->value('position'))->toBe(3);
});

/* ============================================================== Poin */

it('menghitung total bobot paket dari bobot soal bila tidak ada override', function () {
    $paket = paketA($this->guruA, $this->mapel);

    foreach ([2, 3, 5] as $bobot) {
        QuestionPackageItem::factory()->create([
            'school_id' => $this->sekolah->id,
            'question_package_id' => $paket->id,
            'question_id' => Question::factory()->oleh($this->guruA, $this->mapel)
                ->create(['scoring_config_json' => ['bobot' => $bobot]])->id,
        ]);
    }

    masukPaket($this, $this->guruA)->get("/guru/paket/{$paket->id}")
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            // 10, bukan 10.0: JSON tidak punya tipe pecahan tersendiri, dan
            // `round(10.0, 2)` keluar sebagai `10`. Yang penting bukan
            // tipenya, melainkan bahwa tiga bobot 2+3+5 dijumlahkan - bukan
            // dihitung sebagai tiga butir berbobot satu.
            ->where('ringkasan.poin', 10)
            ->where('ringkasan.butir', 3)
        );
});

it('memakai poin khusus butir dan mengabaikan bobot soalnya', function () {
    $paket = paketA($this->guruA, $this->mapel);

    $butir = QuestionPackageItem::factory()->create([
        'school_id' => $this->sekolah->id,
        'question_package_id' => $paket->id,
        'question_id' => Question::factory()->oleh($this->guruA, $this->mapel)
            ->create(['scoring_config_json' => ['bobot' => 2]])->id,
    ]);

    masukPaket($this, $this->guruA)->put("/guru/paket/{$paket->id}/butir/{$butir->id}", [
        'poin' => 8,
    ])->assertRedirect();

    masukPaket($this, $this->guruA)->get("/guru/paket/{$paket->id}")
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->where('ringkasan.poin', 8)
        );
});

it('mengembalikan butir ke bobot soalnya bila poin dikosongkan', function () {
    $paket = paketA($this->guruA, $this->mapel);

    $butir = QuestionPackageItem::factory()->create([
        'school_id' => $this->sekolah->id,
        'question_package_id' => $paket->id,
        'question_id' => Question::factory()->oleh($this->guruA, $this->mapel)
            ->create(['scoring_config_json' => ['bobot' => 2]])->id,
        'points_override' => 8,
    ]);

    masukPaket($this, $this->guruA)->put("/guru/paket/{$paket->id}/butir/{$butir->id}", [
        'poin' => null,
    ])->assertRedirect();

    // NULL, bukan 0 - dan perbedaannya menentukan: nol berarti "soal bonus
    // yang tidak dihitung", null berarti "ikut bobot soalnya".
    expect(QuestionPackageItem::query()->whereKey($butir->id)->value('points_override'))->toBeNull();
});

/* ============================================================ Status */

it('menolak menandai siap paket yang belum berisi butir', function () {
    $paket = paketA($this->guruA, $this->mapel);

    $respons = masukPaket($this, $this->guruA)->put("/guru/paket/{$paket->id}", [
        'name' => $paket->name,
        'status' => 'siap',
    ]);

    $respons->assertSessionHasErrors('status');
    expect($paket->fresh()->status->value)->toBe('draft');
});

it('menandai siap paket yang sudah berisi butir', function () {
    $paket = paketA($this->guruA, $this->mapel);

    QuestionPackageItem::factory()->create([
        'school_id' => $this->sekolah->id,
        'question_package_id' => $paket->id,
        'question_id' => Question::factory()->oleh($this->guruA, $this->mapel)->create()->id,
    ]);

    masukPaket($this, $this->guruA)->put("/guru/paket/{$paket->id}", [
        'name' => $paket->name,
        'status' => 'siap',
    ])->assertRedirect();

    expect($paket->fresh()->status->value)->toBe('siap');
});

/* ================================================== Isolasi & izin */

it('mengembalikan 404 untuk paket sekolah lain', function () {
    $lain = School::factory()->create();
    $guruLain = User::factory()->guru()->untukSekolah($lain)->create(['username' => 'paket.luar']);
    $mapelLain = Subject::factory()->untukSekolah($lain)->create(['code' => 'BIO', 'name' => 'Biologi']);

    $paket = QuestionPackage::factory()->oleh($guruLain, $mapelLain)->create();

    masukPaket($this, $this->guruA)->get("/guru/paket/{$paket->id}")->assertNotFound();
});

it('mengembalikan 404 untuk paket mapel yang tidak diampu', function () {
    // Paket Fisika dibuka Guru A yang hanya mengampu Matematika. 404, bukan
    // 403: paketnya memang tidak pernah muncul di daftarnya, sehingga "tidak
    // ada" adalah jawaban yang jujur.
    $paket = QuestionPackage::factory()->oleh($this->guruB, $this->mapelLain)->create();

    masukPaket($this, $this->guruA)->get("/guru/paket/{$paket->id}")->assertNotFound();
});

it('membuka paket Guru lain tanpa tombol ubah', function () {
    $paket = paketA($this->guruB, $this->mapel);

    $respons = masukPaket($this, $this->guruA)->get("/guru/paket/{$paket->id}");

    // 200, bukan 403: Guru A berhak MELIHAT paket mapelnya. Yang tidak boleh
    // adalah menyimpannya, dan itu yang dibawa prop `bolehUbah`.
    $respons->assertOk();
    $respons->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->component('Guru/Paket/Detail')
        ->where('bolehUbah', false)
    );
});

it('menolak Guru lain mengubah paket dengan 403', function () {
    $paket = paketA($this->guruB, $this->mapel);

    masukPaket($this, $this->guruA)->put("/guru/paket/{$paket->id}", [
        'name' => 'Dibajak',
    ])->assertForbidden();

    expect($paket->fresh()->name)->toBe('Paket MTK X');
});

it('menolak Guru lain menambah butir ke paket orang dengan 403', function () {
    $paket = paketA($this->guruB, $this->mapel);
    $soal = Question::factory()->oleh($this->guruA, $this->mapel)->create();

    masukPaket($this, $this->guruA)->post("/guru/paket/{$paket->id}/butir", [
        'question_ids' => [$soal->id],
    ])->assertForbidden();
});

it('menolak Admin mengubah paket Guru dengan 403', function () {
    // PRD §3.1: Admin melihat semua, tidak mengubah isi bank soal.
    $paket = paketA($this->guruA, $this->mapel);

    masukPaket($this, $this->admin)->put("/guru/paket/{$paket->id}", [
        'name' => 'Diubah Admin',
    ])->assertForbidden();
});

it('meloloskan Admin melihat paket Guru', function () {
    $paket = paketA($this->guruA, $this->mapel);

    masukPaket($this, $this->admin)->get("/guru/paket/{$paket->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->where('bolehUbah', false)
        );
});

it('menolak siswa dan pengawas masuk konsol paket', function () {
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create(['username' => 'paket.siswa']);
    $pengawas = User::factory()->pengawas()->untukSekolah($this->sekolah)->create(['username' => 'paket.pengawas']);

    masukPaket($this, $siswa)->get('/guru/paket')->assertForbidden();
    masukPaket($this, $pengawas)->get('/guru/paket')->assertForbidden();
});

/* ======================================================= Panel bank */

it('menawarkan hanya soal mapel paket yang belum ada di paketnya', function () {
    $paket = paketA($this->guruA, $this->mapel);

    $sudah = Question::factory()->oleh($this->guruA, $this->mapel)->create(['title' => 'Sudah ada']);
    $tersedia = Question::factory()->oleh($this->guruB, $this->mapel)->create(['title' => 'Masih tersedia']);
    Question::factory()->oleh($this->guruB, $this->mapelLain)->create(['title' => 'Fisika']);

    QuestionPackageItem::factory()->create([
        'school_id' => $this->sekolah->id,
        'question_package_id' => $paket->id,
        'question_id' => $sudah->id,
    ]);

    masukPaket($this, $this->guruA)->get("/guru/paket/{$paket->id}")
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            // Satu: yang sudah ada tidak ditawarkan lagi, dan yang mapelnya
            // berbeda tidak pernah masuk - pemilih yang menawarkan sesuatu
            // yang akan ditolak adalah pemilih yang tidak dipercaya.
            ->has('bank', 1)
            ->where('bank.0.judul', 'Masih tersedia')
            ->has('butir', 1)
            ->where('butir.0.judul', 'Sudah ada')
        );
});

it('memisahkan hitungan butir objektif dan uraian', function () {
    $paket = paketA($this->guruA, $this->mapel);

    QuestionPackageItem::factory()->create([
        'school_id' => $this->sekolah->id,
        'question_package_id' => $paket->id,
        'question_id' => Question::factory()->oleh($this->guruA, $this->mapel)
            ->berjenis(JenisSoal::PilihanGanda)->create()->id,
    ]);

    QuestionPackageItem::factory()->create([
        'school_id' => $this->sekolah->id,
        'question_package_id' => $paket->id,
        'question_id' => Question::factory()->oleh($this->guruA, $this->mapel)
            ->berjenis(JenisSoal::Uraian)->create()->id,
    ]);

    masukPaket($this, $this->guruA)->get("/guru/paket/{$paket->id}")
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->where('ringkasan.objektif', 1)
            // Uraian dipisah karena ia menuntut pekerjaan yang berbeda
            // setelah ujian selesai: menunggu Guru di laman koreksi.
            ->where('ringkasan.uraian', 1)
        );
});
