<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Models\EventQuestionPackage;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Question\Services\PemasanganPaket;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Fase 4 - pemasangan paket soal ke mapel event. Wireframe plate 15.
 *
 * =====================================================================
 * Dua klaim yang berkas ini jaga
 * =====================================================================
 *
 *   1. DUA TABEL, SATU TRANSAKSI, DAN TIDAK PERNAH BERBEDA.
 *      `event_subjects.question_package_id` menjawab "paket mana yang
 *      berlaku sekarang", `event_question_packages` menjawab "siapa
 *      memasang apa, kapan". Test yang hanya memeriksa kolomnya akan lulus
 *      pada sistem yang tidak menulis riwayat sama sekali - dan sistem itu
 *      membuat pertanyaan "paket ini dipasang kemarin atau bulan lalu"
 *      tidak bisa dijawab siapa pun.
 *
 *   2. MEMASANG ULANG TIDAK MENGHAPUS RIWAYAT LAMA.
 *      Dan itu perbedaan antara riwayat dan kolom status: memasang paket B
 *      setelah paket A adalah DUA peristiwa, dan keduanya berhak punya
 *      barisnya. Testnya karena itu memeriksa `count() = 2` setelah dua
 *      pemasangan - bukan hanya bahwa paket terakhir benar.
 *
 * ---------------------------------------------------------------------
 * Paket Draft ditolak, dan itu sengaja
 * ---------------------------------------------------------------------
 * Status Siap adalah tanda dari GURU bahwa paketnya selesai - bukan tanda
 * bahwa ia berisi. Pemasangan yang menerima Draft membuat status itu hiasan:
 * paket setengah jadi terpasang, ujian lahir darinya, dan yang menemukan
 * soalnya kurang adalah pengawas di ruang ujian.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    $this->mapel = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MTK',
        'name' => 'Matematika',
    ]);

    $this->mapelLain = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'FIS',
        'name' => 'Fisika',
    ]);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'pasang.admin',
    ]);

    $this->guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'pasang.guru',
        'name' => 'Guru Penulis',
    ]);

    $this->event = SchoolEvent::factory()->untukTahun($this->tahun)->create([
        'school_id' => $this->sekolah->id,
    ]);

    $this->baris = EventSubject::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $this->event->id,
        'subject_id' => $this->mapel->id,
    ]);

    $this->barisLain = EventSubject::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $this->event->id,
        'subject_id' => $this->mapelLain->id,
    ]);
});

function masukPasang(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/** Paket siap berisi satu butir, untuk mapel tertentu. */
function paketSiap(User $guru, Subject $mapel, string $nama = 'Paket Siap'): QuestionPackage
{
    $paket = QuestionPackage::factory()->oleh($guru, $mapel)->siap()->create(['name' => $nama]);

    QuestionPackageItem::factory()->create([
        'school_id' => $paket->school_id,
        'question_package_id' => $paket->id,
        'question_id' => Question::factory()->oleh($guru, $mapel)
            ->berjenis(JenisSoal::PilihanGanda)->create()->id,
    ]);

    return $paket;
}

/* ============================================================ Pemasangan */

it('memasang paket ke mapel event dan menulis baris riwayatnya', function () {
    $paket = paketSiap($this->guru, $this->mapel, 'Paket MTK X');

    masukPasang($this, $this->admin)->put("/admin/events/{$this->event->id}/mapel/paket", [
        'baris_id' => $this->baris->id,
        'question_package_id' => $paket->id,
    ])->assertRedirect();

    expect($this->baris->fresh()->question_package_id)->toBe($paket->id);

    // Kolomnya saja tidak cukup - lihat klaim pertama berkas ini.
    $riwayat = EventQuestionPackage::query()->where('event_subject_id', $this->baris->id)->get();

    expect($riwayat)->toHaveCount(1)
        ->and($riwayat->first()->question_package_id)->toBe($paket->id)
        ->and($riwayat->first()->assigned_by)->toBe($this->admin->id)
        // Pemasangan pertama tidak menggantikan apa pun.
        ->and($riwayat->first()->replaced_package_id)->toBeNull();
});

it('memasang ulang tidak menghapus baris riwayat lama', function () {
    $pertama = paketSiap($this->guru, $this->mapel, 'Paket Pertama');
    $kedua = paketSiap($this->guru, $this->mapel, 'Paket Kedua');

    $pemasangan = app(PemasanganPaket::class);

    $pemasangan->pasang($this->baris->id, $pertama->id, $this->admin->id);
    $pemasangan->pasang($this->baris->id, $kedua->id, $this->admin->id);

    $riwayat = EventQuestionPackage::query()
        ->where('event_subject_id', $this->baris->id)
        ->orderBy('id')
        ->get();

    // DUA peristiwa, dua baris - bukan satu baris yang ditimpa.
    expect($riwayat)->toHaveCount(2)

        // Kolomnya menunjuk paket terakhir...
        ->and($this->baris->fresh()->question_package_id)->toBe($kedua->id)

        // ...dan baris kedua menyebut apa yang digantikannya, sehingga
        // urutan pemasangan bisa dibaca dari riwayatnya sendiri.
        ->and($riwayat[1]->replaced_package_id)->toBe($pertama->id);
});

it('melepas paket mengosongkan kolom tanpa menulis baris riwayat', function () {
    $paket = paketSiap($this->guru, $this->mapel);

    $pemasangan = app(PemasanganPaket::class);
    $pemasangan->pasang($this->baris->id, $paket->id, $this->admin->id);
    $pemasangan->pasang($this->baris->id, null, $this->admin->id);

    expect($this->baris->fresh()->question_package_id)->toBeNull()

        // Pelepasan TIDAK menulis baris: riwayat tanpa paket hanya memenuhi
        // tabel dengan kejadian yang tidak menjawab pertanyaan apa pun.
        ->and(EventQuestionPackage::query()->where('event_subject_id', $this->baris->id)->count())
        ->toBe(1);
});

it('menolak paket dari mata pelajaran lain', function () {
    $fisika = paketSiap($this->guru, $this->mapelLain, 'Paket Fisika');

    $respons = masukPasang($this, $this->admin)->put("/admin/events/{$this->event->id}/mapel/paket", [
        'baris_id' => $this->baris->id,
        'question_package_id' => $fisika->id,
    ]);

    $respons->assertSessionHasErrors('question_package_id');
    expect($this->baris->fresh()->question_package_id)->toBeNull();
});

it('menolak memasang paket yang masih berstatus draft', function () {
    $draft = QuestionPackage::factory()->oleh($this->guru, $this->mapel)->create([
        'name' => 'Paket Setengah Jadi',
    ]);

    QuestionPackageItem::factory()->create([
        'school_id' => $this->sekolah->id,
        'question_package_id' => $draft->id,
        'question_id' => Question::factory()->oleh($this->guru, $this->mapel)->create()->id,
    ]);

    $respons = masukPasang($this, $this->admin)->put("/admin/events/{$this->event->id}/mapel/paket", [
        'baris_id' => $this->baris->id,
        'question_package_id' => $draft->id,
    ]);

    $respons->assertSessionHasErrors('question_package_id');
    expect($this->baris->fresh()->question_package_id)->toBeNull();

    // Pesannya menyebut NAMA paketnya, bukan sekadar "paket tidak sah":
    // Admin yang harus menghubungi Gurunya perlu tahu paket mana.
    expect(session('errors')->first('question_package_id'))->toContain('Paket Setengah Jadi');
});

it('menolak baris mapel milik event lain', function () {
    $eventLain = SchoolEvent::factory()->untukTahun($this->tahun)->create([
        'school_id' => $this->sekolah->id,
    ]);

    $barisLain = EventSubject::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $eventLain->id,
        'subject_id' => $this->mapel->id,
    ]);

    $paket = paketSiap($this->guru, $this->mapel);

    // Keduanya sah sebagai baris dan keduanya milik sekolah yang sama.
    // Yang salah adalah PASANGANNYA - dan tanpa pemeriksaan di controller,
    // paket akan terpasang ke mapel event yang tidak disebut URL.
    masukPasang($this, $this->admin)->put("/admin/events/{$this->event->id}/mapel/paket", [
        'baris_id' => $barisLain->id,
        'question_package_id' => $paket->id,
    ])->assertNotFound();

    expect($barisLain->fresh()->question_package_id)->toBeNull();
});

it('menolak Guru memasang paket - pemasangan urusan Admin', function () {
    $paket = paketSiap($this->guru, $this->mapel);

    masukPasang($this, $this->guru)->put("/admin/events/{$this->event->id}/mapel/paket", [
        'baris_id' => $this->baris->id,
        'question_package_id' => $paket->id,
    ])->assertForbidden();

    expect($this->baris->fresh()->question_package_id)->toBeNull();
});

it('menolak memasang lewat event sekolah lain', function () {
    $lain = School::factory()->create();
    $tahunLain = AcademicYear::factory()->untukSekolah($lain)->aktif()->create();
    $eventAsing = SchoolEvent::factory()->untukTahun($tahunLain)->create(['school_id' => $lain->id]);
    $mapelAsing = Subject::factory()->untukSekolah($lain)->create(['code' => 'KIM', 'name' => 'Kimia']);
    $guruAsing = User::factory()->guru()->untukSekolah($lain)->create(['username' => 'pasang.luar']);

    $barisAsing = EventSubject::create([
        'school_id' => $lain->id,
        'event_id' => $eventAsing->id,
        'subject_id' => $mapelAsing->id,
    ]);

    $paketAsing = paketSiap($guruAsing, $mapelAsing);

    // 404 dari binding implisit pada SchoolEvent, sebelum satu baris pun
    // diperiksa. Itu sebabnya tidak ada pemeriksaan sekolah di controller.
    masukPasang($this, $this->admin)->put("/admin/events/{$eventAsing->id}/mapel/paket", [
        'baris_id' => $barisAsing->id,
        'question_package_id' => $paketAsing->id,
    ])->assertNotFound();
});

/* ============================================================= Laman 15 */

it('menampilkan keadaan paket tiap mapel di laman Admin', function () {
    $terpasang = paketSiap($this->guru, $this->mapel, 'Paket MTK X');

    app(PemasanganPaket::class)->pasang($this->baris->id, $terpasang->id, $this->admin->id);

    $respons = masukPasang($this, $this->admin)->get("/admin/events/{$this->event->id}/mapel");

    $respons->assertOk();
    $respons->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->component('Admin/Event/Mapel')
        ->has('baris', 2)
        // Barisnya diurutkan `code`, sehingga FIS mendahului MTK. Urutan itu
        // sengaja ditulis eksplisit di test ini supaya testnya gagal dengan
        // jelas bila urutannya berubah - bukan lolos karena kebetulan.
        ->where('baris.0.kode', 'FIS')
        ->where('baris.0.paket_status', 'belum')
        ->where('baris.0.paket', null)
        ->where('baris.1.kode', 'MTK')
        ->where('baris.1.paket_status', 'terpasang')
        ->where('baris.1.paket', 'Paket MTK X')
        ->where('baris.1.butir', 1)
    );
});

it('membedakan paket terpasang yang belum berisi butir', function () {
    // Paket kosong tidak bisa ditandai siap lewat service, sehingga
    // keadaannya dikarang langsung: satu-satunya cara paket kosong bisa
    // terpasang adalah bila butirnya dibuang SETELAH dipasang.
    $paket = paketSiap($this->guru, $this->mapel, 'Paket Dikosongkan');

    app(PemasanganPaket::class)->pasang($this->baris->id, $paket->id, $this->admin->id);

    QuestionPackageItem::query()->where('question_package_id', $paket->id)->delete();

    $respons = masukPasang($this, $this->admin)->get("/admin/events/{$this->event->id}/mapel");

    // Indeks 1: barisnya diurutkan `code`, sehingga MTK menyusul FIS.
    //
    // 'kosong', BUKAN 'terpasang' dan bukan 'belum': Admin yang melihat
    // 'belum' tahu ia harus memilih, sedangkan Admin yang melihat 'kosong'
    // sudah memilih dan yang harus ia hubungi adalah Gurunya.
    $respons->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->where('baris.1.kode', 'MTK')
        ->where('baris.1.paket_status', 'kosong')
        ->where('baris.1.butir', 0)
    );
});

/* ============================================================== Riwayat */

it('mengembalikan riwayat dan paket tersedia sebagai JSON', function () {
    $terpasang = paketSiap($this->guru, $this->mapel, 'Paket Terpasang');
    paketSiap($this->guru, $this->mapel, 'Paket Lain');
    // Paket Fisika TIDAK boleh muncul di daftar mapel Matematika.
    paketSiap($this->guru, $this->mapelLain, 'Paket Fisika');

    app(PemasanganPaket::class)->pasang($this->baris->id, $terpasang->id, $this->admin->id);

    $respons = masukPasang($this, $this->admin)
        ->getJson("/admin/events/{$this->event->id}/mapel/{$this->baris->id}/paket");

    $respons->assertOk()
        ->assertJsonCount(1, 'riwayat')
        ->assertJsonCount(2, 'tersedia')
        ->assertJsonPath('riwayat.0.paket', 'Paket Terpasang')
        ->assertJsonPath('riwayat.0.pemasang', $this->admin->name);
});

it('menolak riwayat mapel milik event lain', function () {
    $eventLain = SchoolEvent::factory()->untukTahun($this->tahun)->create([
        'school_id' => $this->sekolah->id,
    ]);

    $barisLain = EventSubject::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $eventLain->id,
        'subject_id' => $this->mapel->id,
    ]);

    masukPasang($this, $this->admin)
        ->getJson("/admin/events/{$this->event->id}/mapel/{$barisLain->id}/paket")
        ->assertNotFound();
});
