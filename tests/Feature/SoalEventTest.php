<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Services\PembekuPaket;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Enums\StatusSoal;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Tests\TestCase;

/**
 * Laman SOAL per mapel - `/admin/events/{event}/mapel/soal`, 2026-11-14.
 *
 * =====================================================================
 * Yang dijaga di sini bukan tampilannya, melainkan ASAL angkanya
 * =====================================================================
 * Laman ini menampilkan daftar soal yang akan dikerjakan siswa. Kalau ia
 * membaca sumber yang salah, ia tetap tampil rapi - dan yang membacanya
 * adalah Admin yang menyetujui paket. Karena itu setiap test memeriksa DARI
 * MANA barisnya datang:
 *
 *   snapshot ada     -> yang tampil `exam_questions` (snapshot), BUKAN bank
 *                       soal hidup. Bank soal berubah sesudah pembekuan tidak
 *                       sampai ke ujian; laman yang membaca bank soal akan
 *                       menampilkan soal yang tidak dikerjakan siapa pun.
 *   snapshot tidak   -> yang tampil paket hidup, dengan `keadaan` yang
 *                       menyebutnya.
 *
 * ---------------------------------------------------------------------
 * `basi` hanya bisa diuji dengan waktu
 * ---------------------------------------------------------------------
 * Ia membandingkan `questions.updated_at` dengan
 * `exam_questions.dibekukan_pada`. Memalsukan salah satunya lewat
 * `forceFill` akan menguji perbandingan tanggal terhadap keadaan yang tidak
 * pernah terjadi; `travel()` menggeser `now()` untuk seluruh aplikasi, jadi
 * yang diuji adalah jalur yang sama dengan yang berjalan di server.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'soal.admin',
    ]);

    $this->guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'soal.guru',
    ]);

    $this->mapel = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'INF',
        'name' => 'Informatika',
    ]);

    $this->event = SchoolEvent::factory()->untukTahun($this->tahun)->create([
        'school_id' => $this->sekolah->id,
    ]);

    $this->baris = EventSubject::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $this->event->id,
        'subject_id' => $this->mapel->id,
    ]);
});

function masukSoal(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/** Paket berisi dua butir: satu pilihan ganda, satu uraian. */
function paketDuaButir(User $guru, Subject $mapel, string $nama = 'Paket Soal'): QuestionPackage
{
    $paket = QuestionPackage::factory()->oleh($guru, $mapel)->siap()->create(['name' => $nama]);

    $pg = Question::factory()->oleh($guru, $mapel)->berjenis(JenisSoal::PilihanGanda)->create([
        'title' => 'Konversi 173 ke Biner',
        'code' => 'Q-PG-1',
        'difficulty' => 'sulit',
    ]);

    $uraian = Question::factory()->oleh($guru, $mapel)->berjenis(JenisSoal::Uraian)->create([
        'title' => 'Analisis Two Complement',
        'code' => 'Q-UR-1',
        'difficulty' => 'sedang',
        'scoring_config_json' => ['bobot' => 10],
    ]);

    foreach ([$pg, $uraian] as $i => $soal) {
        QuestionPackageItem::factory()->create([
            'school_id' => $paket->school_id,
            'question_package_id' => $paket->id,
            'question_id' => $soal->id,
            'position' => $i + 1,
        ]);
    }

    return $paket;
}

/* ==================================================== Asal barisnya */

it('menampilkan isi paket hidup saat ujiannya belum dibekukan', function () {
    $paket = paketDuaButir($this->guru, $this->mapel, 'Paket INF 9');

    $this->baris->forceFill(['question_package_id' => $paket->id])->save();

    $props = masukSoal($this, $this->admin)
        ->get("/admin/events/{$this->event->id}/mapel/soal")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Event/Soal'))
        ->viewData('page')['props'];

    expect($props['kelompok'])->toHaveCount(1);

    $k = $props['kelompok'][0];

    expect($k['keadaan'])->toBe('belum_dibekukan')
        ->and($k['mapel']['nama'])->toBe('Informatika')
        ->and($k['paket']['nama'])->toBe('Paket INF 9')
        ->and($k['baris'])->toHaveCount(2)
        ->and($k['butir'])->toBe(2)
        // Urutan barisnya urutan PAKET, bukan abjad judul. Dua butir ini
        // berjudul "Analisis..." dan "Konversi...", sehingga urutan abjad
        // akan membaliknya.
        ->and($k['baris'][0]['judul'])->toBe('Konversi 173 ke Biner')
        ->and($k['baris'][1]['judul'])->toBe('Analisis Two Complement')
        // Bobot BERLAKU dari butir paket, bukan dari soalnya.
        ->and($k['baris'][1]['poin'])->toBe(10.0)
        // Uraian ditandai, karena ia satu-satunya yang menahan publikasi.
        ->and($k['baris'][1]['perlu_koreksi'])->toBeTrue()
        ->and($k['uraian'])->toBe(1);
});

it('membaca SNAPSHOT, bukan bank soal, saat ujiannya sudah dibekukan', function () {
    $paket = paketDuaButir($this->guru, $this->mapel);
    $this->baris->forceFill(['question_package_id' => $paket->id])->save();

    // Satu ujian dengan paket itu, lalu dibekukan - jalur yang sama dengan
    // `ExamRegistrar::buat()`.
    $ujian = Exam::factory()->untukEvent($this->event)->create([
        'subject_id' => $this->mapel->id,
        'question_package_id' => $paket->id,
    ]);

    app(PembekuPaket::class)->bekukan($ujian);

    // Satu menit SESUDAH pembekuan. `travel()` dipakai, bukan `forceFill`
    // atas salah satu tanggalnya: yang dibandingkan adalah dua timestamp
    // yang kedua-duanya ditulis oleh jalur produksi, dan memalsukan salah
    // satunya menguji perbandingan terhadap keadaan yang tidak pernah ada.
    // Tanpa pergeseran ini keduanya jatuh pada detik yang sama, dan
    // perbandingan `>` tidak pernah bisa benar.
    $this->travel(1)->minutes();

    // Bank soal diubah SESUDAH pembekuan. Snapshot yang benar tetap memuat
    // judul lama - dan laman yang membaca bank soal akan menampilkan yang
    // baru, yaitu soal yang tidak dikerjakan siapa pun.
    Question::query()->where('code', 'Q-PG-1')->update(['title' => 'JUDUL BARU DI BANK SOAL']);

    $props = masukSoal($this, $this->admin)
        ->get("/admin/events/{$this->event->id}/mapel/soal")
        ->assertOk()
        ->viewData('page')['props'];

    $k = $props['kelompok'][0];

    expect($k['keadaan'])->toBe('terpasang')
        ->and($k['baris'][0]['judul'])->toBe('Konversi 173 ke Biner')
        // Dan karena bank soalnya berubah sesudah dibekukan, barisnya
        // ditandai basi.
        ->and($k['baris'][0]['basi'])->toBeTrue()
        ->and($props['angka']['basi'])->toBe(1);
});

it('tidak menandai basi bila bank soal tidak berubah sesudah dibekukan', function () {
    $paket = paketDuaButir($this->guru, $this->mapel);
    $this->baris->forceFill(['question_package_id' => $paket->id])->save();

    $ujian = Exam::factory()->untukEvent($this->event)->create([
        'subject_id' => $this->mapel->id,
        'question_package_id' => $paket->id,
    ]);

    app(PembekuPaket::class)->bekukan($ujian);

    $props = masukSoal($this, $this->admin)
        ->get("/admin/events/{$this->event->id}/mapel/soal")
        ->viewData('page')['props'];

    expect($props['angka']['basi'])->toBe(0)
        ->and($props['kelompok'][0]['basi'])->toBe(0);
});

/* ==================================================== Keadaan paket */

it('membedakan mapel tanpa paket dari paket kosong', function () {
    // Satu mapel tanpa paket, satu mapel dengan paket kosong. Keduanya
    // menuntut tindakan BERBEDA: yang pertama Admin harus memilih, yang
    // kedua ia sudah memilih dan yang dihubungi Gurunya.
    $fisika = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'FIS',
        'name' => 'Fisika',
    ]);

    $barisFisika = EventSubject::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $this->event->id,
        'subject_id' => $fisika->id,
    ]);

    $kosong = QuestionPackage::factory()->oleh($this->guru, $fisika)->siap()->create(['name' => 'Paket Kosong']);
    $barisFisika->forceFill(['question_package_id' => $kosong->id])->save();

    $props = masukSoal($this, $this->admin)
        ->get("/admin/events/{$this->event->id}/mapel/soal")
        ->viewData('page')['props'];

    $perKode = collect($props['kelompok'])->keyBy(fn (array $k) => $k['mapel']['kode']);

    expect($perKode['INF']['keadaan'])->toBe('belum')
        ->and($perKode['INF']['baris'])->toBeEmpty()
        ->and($perKode['FIS']['keadaan'])->toBe('kosong')
        ->and($perKode['FIS']['paket']['nama'])->toBe('Paket Kosong');
});

/* ==================================================== Keterangan per soal */

it('mengirim keterangan yang membuat butir bisa dinilai layak', function () {
    $paket = paketDuaButir($this->guru, $this->mapel);
    $this->baris->forceFill(['question_package_id' => $paket->id])->save();

    $props = masukSoal($this, $this->admin)
        ->get("/admin/events/{$this->event->id}/mapel/soal")
        ->viewData('page')['props'];

    $pg = $props['kelompok'][0]['baris'][0];

    // Kuncinya ada pada soal pilihan ganda yang dibuat factory, dan laman
    // memakai itu untuk menandai "K".
    expect($pg)->toHaveKeys([
        'nomor', 'kode', 'judul', 'jenis', 'jenis_label', 'jenis_pendek',
        'tingkat', 'poin', 'perlu_koreksi', 'status',
        'punya_kunci', 'jumlah_rumpang', 'punya_media', 'punya_rubrik',
        'punya_pembahasan', 'diarsipkan', 'basi',
    ]);
});

it('menandai soal yang diarsipkan tetapi masih dipakai paket ini', function () {
    $paket = paketDuaButir($this->guru, $this->mapel);
    $this->baris->forceFill(['question_package_id' => $paket->id])->save();

    Question::query()->where('code', 'Q-PG-1')->update(['status' => StatusSoal::Arsip->value]);

    $props = masukSoal($this, $this->admin)
        ->get("/admin/events/{$this->event->id}/mapel/soal")
        ->viewData('page')['props'];

    expect($props['kelompok'][0]['baris'][0]['diarsipkan'])->toBeTrue()
        ->and($props['angka']['diarsipkan'])->toBe(1)
        // Arsip BUKAN basi, dan keduanya tidak boleh tertukar: yang pertama
        // tidak menuntut apa pun (arsip berarti "tidak dipakai untuk ujian
        // berikutnya"), yang kedua menuntut keputusan.
        ->and($props['angka']['basi'])->toBe(0);
});

/* ==================================================== Penyaring */

it('menyaring baris tetapi TIDAK menyusutkan angka di atas laman', function () {
    $paket = paketDuaButir($this->guru, $this->mapel);
    $this->baris->forceFill(['question_package_id' => $paket->id])->save();

    $props = masukSoal($this, $this->admin)
        ->get("/admin/events/{$this->event->id}/mapel/soal?cari=Konversi")
        ->assertOk()
        ->viewData('page')['props'];

    $k = $props['kelompok'][0];

    // Baris dan kepala kelompok menyusut - keduanya menjawab "isi blok ini".
    expect($k['baris'])->toHaveCount(1)
        ->and($k['butir'])->toBe(1)
        // Angka di ATAS laman tidak: ia menjawab "seberapa besar event ini",
        // dan angka yang berubah saat Admin mencari satu kata berhenti
        // menjawab pertanyaan itu.
        ->and($props['angka']['butir'])->toBe(2)
        ->and($props['angka']['mapel'])->toBe(1);
});

it('membuang kelompok yang seluruh barisnya tersaring keluar', function () {
    $paket = paketDuaButir($this->guru, $this->mapel);
    $this->baris->forceFill(['question_package_id' => $paket->id])->save();

    $props = masukSoal($this, $this->admin)
        ->get("/admin/events/{$this->event->id}/mapel/soal?cari=tidak-ada-yang-cocok")
        ->viewData('page')['props'];

    // Kelompok kosong yang tetap tampil terbaca sebagai "mapel ini tidak
    // punya soal", padahal yang terjadi adalah "tidak ada yang cocok".
    expect($props['kelompok'])->toBeEmpty()
        ->and($props['angka']['butir'])->toBe(2);
});

it('menyaring menurut jenis soal', function () {
    $paket = paketDuaButir($this->guru, $this->mapel);
    $this->baris->forceFill(['question_package_id' => $paket->id])->save();

    $props = masukSoal($this, $this->admin)
        ->get("/admin/events/{$this->event->id}/mapel/soal?jenis=uraian")
        ->viewData('page')['props'];

    expect($props['kelompok'][0]['baris'])->toHaveCount(1)
        ->and($props['kelompok'][0]['baris'][0]['jenis'])->toBe('uraian')
        // Pilihan jenisnya dibaca dari SELURUH baris, bukan dari yang
        // tersaring: pilihan yang hilang begitu ia dipakai adalah pilihan
        // yang tidak bisa dibatalkan.
        ->and($props['pilihan']['jenis'])->toHaveKey('pilihan_ganda');
});

/* ==================================================== Gerbang */

it('menolak peran selain Admin', function () {
    masukSoal($this, $this->guru)
        ->get("/admin/events/{$this->event->id}/mapel/soal")
        ->assertForbidden();
});

it('menyembunyikan event milik sekolah lain', function () {
    $lain = School::factory()->create();
    $adminLain = User::factory()->admin()->untukSekolah($lain)->create([
        'username' => 'soal.admin.lain',
    ]);

    masukSoal($this, $adminLain)
        ->get("/admin/events/{$this->event->id}/mapel/soal")
        ->assertNotFound();
});
