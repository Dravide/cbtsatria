<?php

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\PengaturanAi;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\PengaturanAiSekolah;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Konfigurasi penyedia AI - tab AI di laman Pengaturan Admin.
 *
 * =====================================================================
 * Yang dijaga berkas ini terutama adalah yang TIDAK terkirim
 * =====================================================================
 * Tiga test di sini tidak memeriksa bahwa sesuatu bekerja, melainkan bahwa
 * sesuatu tidak pernah muncul: kunci API di props Inertia, kunci API di
 * jawaban `uji()`, dan kunci API di log aktivitas. Kebocoran lewat jalur
 * seperti itu tidak menggagalkan apa pun yang terlihat - layar tetap benar,
 * uji koneksi tetap berhasil - sehingga tidak ada satu pun test "apakah
 * fiturnya jalan" yang akan menangkapnya.
 *
 * =====================================================================
 * Mengapa `SchoolResolver::forget()` pada setiap login test
 * =====================================================================
 * Resolver menyimpan id sekolah yang sudah ia temukan, termasuk `null` yang
 * ditemukan saat pembangunan di luar permintaan. Alasan lengkapnya di
 * docblock `ExamTest.php`; di sini cukup diingat bahwa tanpa `forget()` test
 * kedua dalam satu berkas melihat sekolah yang salah.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->lain = School::factory()->create();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.ai',
    ]);

    $this->guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'guru.ai',
    ]);

    $this->adminLain = User::factory()->admin()->untukSekolah($this->lain)->create([
        'username' => 'admin.lain.ai',
    ]);
});

function masukAi(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/** Isian tab AI yang sah. */
function isianAi(array $ganti = []): array
{
    return array_merge([
        'base_url' => 'https://api.contoh.test/v1',
        'model' => 'model-contoh',
        'api_key' => 'sk-contoh-rahasia-1234',
        'aktif' => true,
        'batas_token' => 1500,
    ], $ganti);
}

/* ------------------------------------------------------------------ Simpan */

it('menyimpan konfigurasi AI dari laman Admin', function () {
    masukAi($this, $this->admin)
        ->put('/admin/pengaturan/ai', isianAi())
        ->assertRedirect()
        ->assertSessionHas('sukses');

    $pengaturan = PengaturanAi::query()->where('school_id', $this->sekolah->id)->first();

    expect($pengaturan)->not->toBeNull()
        ->and($pengaturan->base_url)->toBe('https://api.contoh.test/v1')
        ->and($pengaturan->model)->toBe('model-contoh')
        ->and($pengaturan->aktif)->toBeTrue()
        ->and($pengaturan->batas_token)->toBe(1500)
        ->and($pengaturan->diperbarui_oleh)->toBe($this->admin->id);
});

it('menyimpan kunci API dalam bentuk terenkripsi', function () {
    masukAi($this, $this->admin)->put('/admin/pengaturan/ai', isianAi());

    $pengaturan = PengaturanAi::query()->where('school_id', $this->sekolah->id)->firstOrFail();

    // Yang dibuktikan bukan "kolomnya terisi" - sistem yang menyimpan kunci
    // sebagai teks biasa juga lulus pemeriksaan itu. Yang dibuktikan adalah
    // nilai mentah kolom BUKAN kuncinya, dan bahwa ia bisa dikembalikan.
    expect($pengaturan->api_key_encrypted)->not->toBe('sk-contoh-rahasia-1234')
        ->and($pengaturan->api_key_encrypted)->not->toContain('rahasia')
        ->and($pengaturan->kunciApi())->toBe('sk-contoh-rahasia-1234');
});

it('tidak mengirim kunci API ke peramban, hanya penandanya', function () {
    masukAi($this, $this->admin)->put('/admin/pengaturan/ai', isianAi());

    masukAi($this, $this->admin)
        ->get('/admin/pengaturan')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Pengaturan')
            ->where('ai.ada_kunci', true)
            // Empat huruf terakhir cukup untuk meyakinkan Admin bahwa yang
            // tersimpan memang kuncinya. Kunci penuhnya tidak pernah ada di
            // payload, jadi tidak ada yang bisa dibaca dari devtools.
            ->where('ai.petunjuk_kunci', PengaturanAi::PENANDA_KUNCI.'1234')
            ->where('ai.aktif', true)
            ->missing('ai.api_key')
            ->missing('ai.api_key_encrypted')
        );
});

it('memberi tahu bahwa kuncinya belum diisi saat sekolah belum mengatur AI', function () {
    masukAi($this, $this->admin)
        ->get('/admin/pengaturan')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ai.ada_kunci', false)
            ->where('ai.petunjuk_kunci', null)
            ->where('ai.aktif', false)
            ->where('ai.base_url', '')
            ->where('ai.model', '')
        );
});

it('mempertahankan kunci lama saat kolomnya dikosongkan', function () {
    masukAi($this, $this->admin)->put('/admin/pengaturan/ai', isianAi());

    // Bentuk permintaan yang paling sering terjadi: form dibuka kembali,
    // kolom kunci KOSONG (karena kuncinya tidak pernah dikirim ke peramban),
    // lalu Admin hanya memperbaiki nama model.
    masukAi($this, $this->admin)->put('/admin/pengaturan/ai', isianAi([
        'model' => 'model-baru',
        'api_key' => '',
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $pengaturan = PengaturanAi::query()->where('school_id', $this->sekolah->id)->firstOrFail();

    expect($pengaturan->model)->toBe('model-baru')
        ->and($pengaturan->kunciApi())->toBe('sk-contoh-rahasia-1234');
});

it('menolak menyalakan AI tanpa kunci', function () {
    masukAi($this, $this->admin)
        ->put('/admin/pengaturan/ai', isianAi(['api_key' => '', 'aktif' => true]))
        ->assertSessionHasErrors('api_key');

    expect(PengaturanAi::query()->where('school_id', $this->sekolah->id)->exists())->toBeFalse();
});

it('menolak base_url http ke alamat publik', function () {
    masukAi($this, $this->admin)
        ->put('/admin/pengaturan/ai', isianAi(['base_url' => 'http://api.contoh.test/v1']))
        ->assertSessionHasErrors('base_url');
});

it('meloloskan base_url http ke localhost untuk model yang berjalan di mesin sendiri', function () {
    masukAi($this, $this->admin)
        ->put('/admin/pengaturan/ai', isianAi(['base_url' => 'http://localhost:1234/v1']))
        ->assertSessionHasNoErrors();

    expect(PengaturanAi::query()->where('school_id', $this->sekolah->id)->value('base_url'))
        ->toBe('http://localhost:1234/v1');
});

it('menolak base_url yang menunjuk ke alamat jaringan dalam di luar lingkungan lokal', function () {
    // `testing` adalah lingkungan yang meloloskan alamat privat - lihat
    // UrlPenyedia. Yang diuji di sini adalah aturan untuk server sungguhan:
    // base_url adalah alamat yang SERVER hubungi, dan satu isian
    // 169.254.169.254 adalah skenario SSRF paling klasik.
    // Lingkungan diganti lewat `detectEnvironment()`, bukan `$this->app['env'] = ...`:
    // `Application` tidak membaca kunci itu dari container, jadi penugasan
    // langsung tidak berpengaruh pada `app()->environment()`.
    $this->app->detectEnvironment(fn () => 'production');

    try {
        // CSRF dimatikan khusus di test ini. `PreventRequestForgery`
        // meloloskan semua permintaan selama `env === 'testing'`, jadi
        // begitu lingkungan diganti ke production, middleware itu hidup dan
        // permintaan uji - yang memang tidak membawa token - berhenti di 419
        // SEBELUM controller pernah dijalankan. Yang diuji adalah UrlPenyedia,
        // bukan CSRF, jadi middleware itu dimatikan alih-alih dipalsukan.
        masukAi($this, $this->admin)
            ->withoutMiddleware(PreventRequestForgery::class)
            ->put('/admin/pengaturan/ai', isianAi(['base_url' => 'https://169.254.169.254/v1']))
            ->assertSessionHasErrors('base_url');
    } finally {
        $this->app->detectEnvironment(fn () => 'testing');
    }

    expect(PengaturanAi::query()->where('school_id', $this->sekolah->id)->exists())->toBeFalse();
});

it('mencatat perubahan pengaturan AI tanpa menyebut kuncinya', function () {
    masukAi($this, $this->admin)->put('/admin/pengaturan/ai', isianAi());

    $baris = ActivityLog::tanpaTenant()->orderByDesc('id')->first();

    expect($baris->action)->toBe('Mengubah pengaturan AI')
        ->and($baris->metadata_json)->toMatchArray([
            'base_url' => 'https://api.contoh.test/v1',
            'model' => 'model-contoh',
            'aktif' => true,
        ]);

    // Log dibaca lebih banyak orang daripada yang boleh melihat kunci itu -
    // termasuk lewat salinan basis data dan laporan galat.
    expect(json_encode($baris->metadata_json))->not->toContain('rahasia');
});

/* ------------------------------------------------------------------ Izin */

it('menolak Guru membuka dan mengubah pengaturan AI', function () {
    masukAi($this, $this->guru)->get('/admin/pengaturan')->assertForbidden();

    masukAi($this, $this->guru)
        ->put('/admin/pengaturan/ai', isianAi())
        ->assertForbidden();

    expect(PengaturanAi::query()->where('school_id', $this->sekolah->id)->exists())->toBeFalse();
});

it('menyimpan konfigurasi hanya untuk sekolah Admin yang menekan Simpan', function () {
    masukAi($this, $this->admin)->put('/admin/pengaturan/ai', isianAi());

    masukAi($this, $this->adminLain)->put('/admin/pengaturan/ai', isianAi([
        'base_url' => 'https://api.lain.test/v1',
        'model' => 'model-lain',
        'api_key' => 'sk-lain-9999',
    ]))->assertRedirect();

    // Dibaca lewat `tanpaTenant()`: `SchoolScope` menyaring ke sekolah yang
    // sedang login - di sini sekolah B - sehingga baris sekolah A tidak akan
    // pernah terlihat oleh query biasa, dan test akan gagal walau datanya benar.
    expect(PengaturanAi::tanpaTenant()->where('school_id', $this->sekolah->id)->value('model'))
        ->toBe('model-contoh')
        ->and(PengaturanAi::tanpaTenant()->where('school_id', $this->lain->id)->value('model'))
        ->toBe('model-lain');
});

/* ------------------------------------------------------------ Uji koneksi */

it('melaporkan sukses saat uji koneksi tersambung', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '{"ok":true}']]],
        ]),
    ]);

    masukAi($this, $this->admin)
        ->postJson('/admin/pengaturan/ai/uji', [
            'base_url' => 'https://api.contoh.test/v1',
            'model' => 'model-contoh',
            'api_key' => 'sk-contoh-rahasia-1234',
        ])
        ->assertOk()
        ->assertJson(['pesan' => 'Tersambung. Model model-contoh menjawab.']);
});

it('memakai kunci tersimpan bila kolom kunci dikosongkan saat menguji', function () {
    masukAi($this, $this->admin)->put('/admin/pengaturan/ai', isianAi());

    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '{"ok":true}']]],
        ]),
    ]);

    masukAi($this, $this->admin)
        ->postJson('/admin/pengaturan/ai/uji', [
            'base_url' => 'https://api.contoh.test/v1',
            'model' => 'model-contoh',
            'api_key' => '',
        ])
        ->assertOk();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-contoh-rahasia-1234'));
});

it('melaporkan kunci yang ditolak dalam bahasa Indonesia tanpa membocorkan kuncinya', function () {
    Http::fake([
        '*/chat/completions' => Http::response(
            ['error' => ['message' => 'Incorrect API key provided: sk-contoh-rahasia-1234']],
            401,
        ),
    ]);

    $jawaban = masukAi($this, $this->admin)
        ->postJson('/admin/pengaturan/ai/uji', [
            'base_url' => 'https://api.contoh.test/v1',
            'model' => 'model-contoh',
            'api_key' => 'sk-contoh-rahasia-1234',
        ])
        ->assertStatus(422);

    expect($jawaban->json('pesan'))->toContain('401')
        ->and(json_encode($jawaban->json()))->not->toContain('sk-contoh-rahasia-1234');
});

it('tidak menyimpan apa pun saat uji koneksi dijalankan', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '{"ok":true}']]],
        ]),
    ]);

    masukAi($this, $this->admin)
        ->postJson('/admin/pengaturan/ai/uji', [
            'base_url' => 'https://api.contoh.test/v1',
            'model' => 'model-contoh',
            'api_key' => 'sk-contoh-rahasia-1234',
        ])
        ->assertOk();

    // Yang diuji adalah isian di layar SEKARANG. Admin yang menguji lalu
    // membatalkan tidak boleh meninggalkan konfigurasi setengah jadi.
    expect(PengaturanAi::query()->where('school_id', $this->sekolah->id)->exists())->toBeFalse();
});

/* ---------------------------------------------------------------- Service */

it('mengosongkan kunci berarti mematikan AI bersamanya', function () {
    $service = app(PengaturanAiSekolah::class);

    $service->simpan((int) $this->sekolah->id, isianAi());

    expect($service->hapusKunci((int) $this->sekolah->id))->toBeTrue();

    $pengaturan = PengaturanAi::query()->where('school_id', $this->sekolah->id)->firstOrFail();

    expect($pengaturan->api_key_encrypted)->toBeNull()
        ->and($pengaturan->aktif)->toBeFalse()
        ->and($pengaturan->siap())->toBeFalse();
});

it('menganggap konfigurasi belum siap bila ciphertextnya rusak', function () {
    PengaturanAi::factory()->create([
        'school_id' => $this->sekolah->id,
        // Ciphertext yang tidak bisa didekripsi, mis. sesudah APP_KEY
        // diganti. Bagi pemanggilnya ini sama dengan "belum diatur", bukan
        // 500 - dan bukan alasan untuk memberi tahu siapa pun bahwa
        // ada yang rusak.
        'api_key_encrypted' => 'bukan-ciphertext-yang-sah',
        'aktif' => true,
    ]);

    $pengaturan = app(PengaturanAiSekolah::class)->ambil((int) $this->sekolah->id);

    expect($pengaturan->kunciApi())->toBeNull()
        ->and($pengaturan->petunjukKunci())->toBeNull();
});

it('mengembalikan kunci yang disimpan lewat service', function () {
    app(PengaturanAiSekolah::class)->simpan((int) $this->sekolah->id, isianAi(['api_key' => 'sk-abc']));

    $mentah = PengaturanAi::query()->where('school_id', $this->sekolah->id)->value('api_key_encrypted');

    expect(Crypt::decryptString($mentah))->toBe('sk-abc');
});
