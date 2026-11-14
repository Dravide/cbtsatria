<?php

use App\Domain\Academic\Models\Subject;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Enums\StatusSoal;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionOption;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Tests\TestCase;

/**
 * Bank soal versi Admin - `/admin/bank-soal`, 2026-11-14.
 *
 * =====================================================================
 * Yang dijaga: Admin tidak pernah didorong ke konsol Guru
 * =====================================================================
 * Sampai laman ini ada, satu-satunya pintu ke bank soal adalah
 * `/guru/bank-soal` - konsol Guru dengan Builder, Impor, dan Generate AI,
 * yang ketiganya server TOLAK untuk Admin. Yang diuji di sini karena itu
 * bukan sekadar "lamannya terbuka", melainkan bahwa laman itu TIDAK membawa
 * satu pun izin tulis - dan bahwa soal yang dibuka panelnya benar-benar
 * lolos `view`.
 *
 * ---------------------------------------------------------------------
 * Panel pratinjau memakai `view`, bukan `bolehUbah`
 * ---------------------------------------------------------------------
 * Admin melihat seluruh sekolah (PRD §3.1), sehingga `view` selalu benar
 * untuknya. Yang tetap harus diuji adalah bahwa id soal yang tidak ada -
 * atau milik sekolah lain - TIDAK mengirim panel apa pun, karena laman yang
 * membuka panel dari id sembarang akan membocorkan isi soal lewat
 * `?soal=` yang ditebak.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'bank.admin',
    ]);

    $this->guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'bank.guru',
        'name' => 'Guru Penulis',
    ]);

    $this->mapel = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'INF',
        'name' => 'Informatika',
    ]);

    $this->soal = Question::factory()->oleh($this->guru, $this->mapel)
        ->berjenis(JenisSoal::PilihanGanda)
        ->create([
            'title' => 'Konversi 173 ke Biner',
            'code' => 'Q-1',
        ]);

    QuestionOption::create([
        'school_id' => $this->sekolah->id,
        'question_id' => $this->soal->id,
        'option_key' => 'a',
        'content_json' => ['teks' => 'Jawaban benar'],
        'is_correct' => true,
        'position' => 0,
    ]);
});

function masukBankAdmin(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

it('membuka bank soal versi Admin tanpa konsol Guru', function () {
    $props = masukBankAdmin($this, $this->admin)
        ->get('/admin/bank-soal')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/BankSoal'))
        ->viewData('page')['props'];

    expect($props['baris']['data'])->toHaveCount(1)
        ->and($props['baris']['data'][0]['judul'])->toBe('Konversi 173 ke Biner')
        // Cuplikannya teks telanjang, bukan markup - kolom itu dibaca mata.
        ->and($props['baris']['data'][0]['cuplikan'])->not->toContain('<');

    // Izin tulis TIDAK dikirim, karena laman ini tidak punya tombol tulis.
    expect($props)->not->toHaveKey('bolehUbah');
});

it('tidak mengirim panel pratinjau sebelum satu soal dipilih', function () {
    $props = masukBankAdmin($this, $this->admin)
        ->get('/admin/bank-soal')
        ->viewData('page')['props'];

    expect($props['terpilih'])->toBeNull();
});

it('mengirim panel pratinjau beserta kuncinya saat satu soal dibuka', function () {
    // Kunci IKUT - dan itu yang membedakannya dari payload siswa. Yang
    // membaca di sini adalah Admin yang menilai apakah paketnya layak
    // diujikan, dan pertanyaan "kuncinya ada atau tidak" tidak bisa dijawab
    // tanpa melihatnya.
    $props = masukBankAdmin($this, $this->admin)
        ->get("/admin/bank-soal?soal={$this->soal->id}")
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['terpilih'])->not->toBeNull()
        ->and($props['terpilih']['id'])->toBe($this->soal->id)
        ->and($props['terpilih']['kunci'])->not->toBeNull()
        ->and($props['terpilih']['opsi'])->toHaveCount(1)
        ->and($props['terpilih']['opsi'][0]['is_correct'])->toBeTrue()
        ->and($props['filter']['soal'])->toBe($this->soal->id);
});

it('tidak mengirim panel untuk soal yang tidak ada', function () {
    // Id sembarang TIDAK boleh membuka panel: laman yang membuka panel dari
    // id tebakan membocorkan isi soal lewat `?soal=`.
    $props = masukBankAdmin($this, $this->admin)
        ->get('/admin/bank-soal?soal=999999')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['terpilih'])->toBeNull()
        ->and($props['filter']['soal'])->toBeNull();
});

it('tidak membocorkan soal milik sekolah lain', function () {
    $lain = School::factory()->create();
    $guruLain = User::factory()->guru()->untukSekolah($lain)->create(['username' => 'bank.guru.lain']);
    $mapelLain = Subject::factory()->untukSekolah($lain)->create(['code' => 'FIS', 'name' => 'Fisika']);

    $soalLain = Question::factory()->oleh($guruLain, $mapelLain)
        ->berjenis(JenisSoal::PilihanGanda)
        ->create(['title' => 'SOAL SEKOLAH LAIN', 'code' => 'Q-X']);

    $props = masukBankAdmin($this, $this->admin)
        ->get("/admin/bank-soal?soal={$soalLain->id}")
        ->assertOk()
        ->viewData('page')['props'];

    // Scope tenant menyaringnya, sehingga yang dibaca `find()` adalah
    // "tidak ada" - dan itu memang jawaban yang benar.
    expect($props['terpilih'])->toBeNull()
        ->and(collect($props['baris']['data'])->pluck('judul')->all())
        ->not->toContain('SOAL SEKOLAH LAIN');
});

it('menyaring menurut mapel dan status', function () {
    Question::factory()->oleh($this->guru, $this->mapel)
        ->berjenis(JenisSoal::Uraian)
        ->create(['title' => 'Soal Uraian', 'code' => 'Q-2', 'status' => StatusSoal::Arsip->value]);

    $props = masukBankAdmin($this, $this->admin)
        ->get('/admin/bank-soal?status=arsip')
        ->viewData('page')['props'];

    expect($props['baris']['data'])->toHaveCount(1)
        ->and($props['baris']['data'][0]['status'])->toBe('arsip');
});

it('menolak peran selain Admin dan Guru', function () {
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create(['username' => 'bank.siswa']);

    masukBankAdmin($this, $siswa)
        ->get('/admin/bank-soal')
        ->assertForbidden();
});

it('tidak lagi menautkan laman soal event ke konsol Guru', function () {
    // Tombol "Buka bank soal" di laman soal per mapel dulu menunjuk
    // `/guru/bank-soal`, dan Admin mendarat di konsol orang lain. Yang
    // diuji di sini bukan tautannya di peramban - itu tipe di TSX - tetapi
    // bahwa ALAMAT Admin memang ada dan menjawab, sehingga tautan barunya
    // tidak menunjuk 404.
    masukBankAdmin($this, $this->admin)->get('/admin/bank-soal')->assertOk();
});
