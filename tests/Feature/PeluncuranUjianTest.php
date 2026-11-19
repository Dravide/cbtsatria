<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Services\PengawasService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\Auth;

/**
 * Peluncuran ujian - status EVENT mengikuti sesinya, dan tidak selalu.
 *
 * =====================================================================
 * Yang dibuktikan di sini adalah yang TIDAK terjadi
 * =====================================================================
 * `SesiService::mulai()` memanggil `pindahkanEvent(Ongoing)`, dan pembaca
 * kodenya wajar menyimpulkan event selalu ikut berpindah. Yang sebenarnya
 * terjadi: `pindahkanEvent()` mengembalikan lebih awal bila graf status tidak
 * memuat jalur itu, TANPA satu pun pesan.
 *
 * Karena itu test di bawah memeriksa `$event->refresh()->status` sesudah
 * `mulai()` - bukan status sesinya. Status sesi akan tetap hijau meski event
 * tertinggal di `Draft`, dan itulah bentuk jebakannya: yang berjalan benar
 * menutupi yang berjalan diam-diam.
 *
 * =====================================================================
 * `siapkanUjianSiswa()` TIDAK dipakai apa adanya
 * =====================================================================
 * Helper bersama itu memanggil `mulai()` lalu - di `PenjadwalTest` - pemanggil
 * memaksa `forceFill(['status' => EventStatus::Ongoing])`. Pemaksaan itulah
 * yang menyamarkan bug ini selama berbulan-bulan: seluruh test penutupan sesi
 * berangkat dari event yang sudah `Berjalan`, sehingga jalur "siapa yang
 * memindahkannya" tidak pernah diuji.
 *
 * Di sini eventnya dibiarkan pada status yang dihasilkan jalur nyata, dan
 * yang diperiksa adalah apa yang benar-benar terjadi sesudahnya.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1 Cipanas']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.peluncuran',
    ]);

    $this->tahun = AcademicYear::factory()->create([
        'school_id' => $this->sekolah->id,
        'is_active' => true,
    ]);

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->admin);
});

/**
 * Fixture ujian, dengan status event DIBIARKAN apa adanya.
 *
 * Tipenya sengaja `object`, bukan `Tests\TestCase`: di Pest, `$this` di dalam
 * test bukan instance `TestCase` melainkan `P\Tests\Feature\…` yang
 * membungkusnya, sehingga anotasi `TestCase` justru memicu TypeError di sini.
 */
function siapkanPeluncuran(object $test, ?EventStatus $statusEvent = null): array
{
    Auth::logout();
    app(SchoolResolver::class)->forget();

    $data = siapkanUjianSiswa(
        $test->sekolah,
        $test->tahun,
        $test->semester,
        $test->admin,
    );

    // Helper bersama meninggalkan event pada status yang dihasilkan
    // `EventRegistrar::buat()` - yaitu Draft. Itu justru keadaan yang dicari.
    if ($statusEvent !== null) {
        $data['event']->forceFill(['status' => $statusEvent])->save();
        $data['event']->refresh();
    }

    return $data;
}

/**
 * Bug 2026-11-19, dan bentuk penyelesaiannya.
 *
 * Sampai hari itu dua test di bawah berbunyi "membiarkan event di Draft" dan
 * HIJAU - mereka mengunci kerusakan sebagai perilaku. `mulai()` membuka sesi
 * pada event Draft, `pindahkanEvent()` menelan kegagalannya, dan eventnya
 * tertinggal. Test yang hijau pada kerusakan lebih buruk daripada tidak ada
 * test: ia membuat keadaan itu terlihat disengaja.
 *
 * Bentuk penyelesaiannya BUKAN menolak pembukaan sesi. Percobaan pertama
 * sesi ini menolak, dan 36 test pecah - semuanya karena alasan yang sama:
 * siswa yang sudah duduk di depan pintu tidak boleh ditahan oleh keadaan
 * administratif yang pengawas di ruangan itu tidak bisa perbaiki. Hari-H
 * adalah momen terburuk untuk menolak.
 *
 * Jadi yang berubah: sesinya tetap terbuka, DAN ketertinggalan eventnya
 * sekarang punya jawaban yang bisa dibaca - `eventTertinggal()`. Tidak ada
 * yang berubah pada graf status; yang hilang hanyalah kesunyiannya.
 */
it('membuka sesi pada event Draft, dan menamai event yang tertinggal', function () {
    $data = siapkanPeluncuran($this);

    // Prasyarat keadaan: fixture tidak memaksa status apa pun.
    expect($data['event']->status)->toBe(EventStatus::Draft)
        ->and($data['sesi']->status)->toBe(StatusSesi::Siap);

    app(SesiService::class)->mulai($data['sesi']);

    // Sesi terbuka - pintu benar-benar menerima siswa. Ini TIDAK ditolak,
    // dan itu keputusan.
    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Berjalan);

    // Eventnya tertinggal, dan sekarang keadaan itu BISA DIBACA. Sebelum
    // 2026-11-19 pertanyaan ini tidak punya jawaban selain membandingkan
    // sendiri dua baris basis data.
    expect($data['event']->refresh()->status)->toBe(EventStatus::Draft)
        ->and(app(SesiService::class)->eventTertinggal($data['sesi']))
        ->toBe(EventStatus::Ongoing);
});

it('menyusun kalimat yang menyebut jalan keluarnya, bukan sekadar keadaannya', function () {
    $data = siapkanPeluncuran($this);

    app(SesiService::class)->mulai($data['sesi']);

    $pesan = app(SesiService::class)->pesanEventTertinggal($data['sesi']->refresh());

    // Kalimatnya harus menyebut APA yang harus dikerjakan. "Event masih
    // Draft" saja akan membuat Admin mencari-cari di jadwal, padahal
    // tombolnya ada di laman Event.
    expect($pesan)->toContain('Tandai event Siap')
        ->and($pesan)->toContain('Draft');
});

it('tidak menamai ketertinggalan pada event yang sudah Siap', function () {
    $data = siapkanPeluncuran($this, EventStatus::Ready);

    app(SesiService::class)->mulai($data['sesi']);

    // Jalur normal: tidak ada yang tertinggal, jadi tidak ada yang dilaporkan.
    expect(app(SesiService::class)->eventTertinggal($data['sesi']->refresh()))->toBeNull();
});

it('tidak menamai ketertinggalan pada sesi yang belum dibuka', function () {
    $data = siapkanPeluncuran($this);

    // Sesi Siap pada event Draft adalah keadaan NORMAL - Admin masih boleh
    // memindahkan eventnya sendiri. Melaporkannya sebagai ketertinggalan
    // akan menyalakan peringatan pada pekerjaan yang belum selesai.
    expect(app(SesiService::class)->eventTertinggal($data['sesi']))->toBeNull();
});

it('menolak menutup sesi pada event yang tertinggal di Draft', function () {
    // Keadaan yang tidak bisa lagi lahir dari `mulai()` - tetapi BISA lahir
    // dari baris data lama yang dibuat sebelum penjagaan ini ada. Test ini
    // memastikan `tutup()` juga tidak diam-diam meninggalkan event di Draft.
    $data = siapkanPeluncuran($this, EventStatus::Ongoing);

    $data['event']->forceFill(['status' => EventStatus::Draft])->save();
    $data['sesi']->forceFill(['status' => StatusSesi::Berjalan])->save();

    app(SesiService::class)->tutup($data['sesi']->refresh());

    // `tutup()` tidak diperiksa `pastikanEventBisaBerjalan()` - yang dijaga
    // adalah pembukaan. Jadi sesinya menutup, dan eventnya tetap Draft.
    // Itu DITERIMA: menolak menutup sesi yang siswanya sudah mengerjakan
    // berarti menahan nilai mereka.
    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Selesai)
        ->and($data['event']->refresh()->status)->toBe(EventStatus::Draft);
});

it('membuka sesi dan memindahkan event yang sudah Siap', function () {
    $data = siapkanPeluncuran($this, EventStatus::Ready);

    app(SesiService::class)->mulai($data['sesi']);

    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Berjalan)
        ->and($data['event']->refresh()->status)->toBe(EventStatus::Ongoing);
});

it('memindahkan event ke Selesai bila sesi terakhir ditutup dari Siap', function () {
    $data = siapkanPeluncuran($this, EventStatus::Ready);

    app(SesiService::class)->mulai($data['sesi']);
    app(SesiService::class)->tutup($data['sesi']);

    expect($data['event']->refresh()->status)->toBe(EventStatus::Completed);
});

it('tidak menempuh Draft maupun Persiapan menuju Berjalan', function () {
    // Graf statusnya sendiri: dua keadaan ini sama-sama menolak `Ongoing`.
    // Ditulis sebagai test, bukan sebagai komentar, karena ia adalah ALASAN
    // mengapa "Tandai Siap" wajib - dan alasan yang hanya hidup di komentar
    // akan hilang begitu seseorang menyunting grafnya. Bila kelak ada yang
    // menambahkan `Draft => Ongoing` untuk "mempermudah", test ini berbunyi
    // dan percakapannya terjadi sebelum kodenya, bukan sesudahnya.
    expect(EventStatus::Draft->bolehPindahKe(EventStatus::Ongoing))->toBeFalse()
        ->and(EventStatus::Preparation->bolehPindahKe(EventStatus::Ongoing))->toBeFalse()
        ->and(EventStatus::Ready->bolehPindahKe(EventStatus::Ongoing))->toBeTrue();
});

/* ------------------------------------------------------------- Massal */

/**
 * Sesi kedua pada ujian yang sama, pada event yang sama.
 *
 * Dibuat lewat `SesiService::buat()` - jalur yang dipakai Admin - lalu
 * dibiarkan `Draft`, supaya `siapkanBanyak()` punya dua baris untuk
 * dikerjakan.
 *
 * Pengawasnya ditugaskan di sini, dan itu bukan pelengkap: `mulai()` menolak
 * ruang tanpa pengawas, jadi sesi tanpa pengawas tidak akan pernah masuk
 * `mulaiBanyak()` sebagai baris yang BERHASIL. Test yang lupa menugaskannya
 * akan membaca penolakan itu sebagai kegagalan aksi massal.
 */
function sesiKeduaPeluncuran(object $test, array $data): ExamSession
{
    // Id ruang-EVENT dibaca dari ruang-sesi yang sudah ada, bukan dari
    // `$data['ruang']`: helper bersama mengembalikan koleksi baris
    // `exam_session_rooms`, dan kolomnya bernama `event_room_id` pada relasi -
    // bukan sebagai atribut langsung yang bisa di-pluck dari koleksinya.
    $idRuang = $data['ruang']->map(fn ($r) => (int) $r->event_room_id)->all();

    $sesi = app(SesiService::class)->buat($data['ujian'], [
        'session_number' => 2,
        'access_opens_at' => now()->modify('+4 hours'),
        'access_closes_at' => now()->modify('+6 hours'),
        'hard_end_at' => now()->modify('+7 hours'),
        'duration_minutes' => 90,
        'event_room_id' => $idRuang,
    ]);

    foreach ($sesi->rooms()->with('supervisors')->get() as $ruang) {
        if ($ruang->supervisors->isEmpty()) {
            app(PengawasService::class)->tugaskan($ruang, $data['pengawas'], $test->admin->id);
        }
    }

    return $sesi;
}

it('menyiapkan banyak sesi sekaligus, dan melewati yang sudah Siap', function () {
    $data = siapkanPeluncuran($this);

    // Fixture sudah menyiapkan sesi pertama; yang kedua lahir Draft.
    $kedua = sesiKeduaPeluncuran($this, $data);

    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Siap)
        ->and($kedua->refresh()->status)->toBe(StatusSesi::Draft);

    $hasil = app(SesiService::class)->siapkanBanyak([$data['sesi'], $kedua]);

    // Yang sudah Siap DILEWATI, bukan dilaporkan gagal: menekan tombol ini
    // pada jadwal setengah jadi adalah menyelesaikan pekerjaan, bukan
    // mengulanginya.
    expect($hasil['berhasil'])->toBe(1)
        ->and($hasil['dilewati'])->toBe(1)
        ->and($hasil['gagal'])->toBe([])
        ->and($kedua->refresh()->status)->toBe(StatusSesi::Siap);
});

it('membuka banyak sesi sekaligus, dan melewati yang sudah Berjalan', function () {
    $data = siapkanPeluncuran($this, EventStatus::Ready);

    $kedua = sesiKeduaPeluncuran($this, $data);
    app(SesiService::class)->siapkan($kedua);

    $hasil = app(SesiService::class)->mulaiBanyak([$data['sesi'], $kedua]);

    expect($hasil['berhasil'])->toBe(2)
        ->and($hasil['gagal'])->toBe([])
        ->and($data['sesi']->refresh()->status)->toBe(StatusSesi::Berjalan)
        ->and($kedua->refresh()->status)->toBe(StatusSesi::Berjalan);
});

it('satu sesi yang ditolak tidak membatalkan sesi lain yang sah', function () {
    $data = siapkanPeluncuran($this, EventStatus::Ready);

    $kedua = sesiKeduaPeluncuran($this, $data);
    app(SesiService::class)->siapkan($kedua);

    // Ruang sesi kedua kehilangan pengawasnya - satu-satunya penolakan yang
    // lazim di hari-H, dan ia milik SATU ruang, bukan seluruh jadwal.
    $kedua->rooms()->firstOrFail()->supervisors()->delete();

    $hasil = app(SesiService::class)->mulaiBanyak([$data['sesi'], $kedua]);

    // Yang pertama tetap terbuka. Membatalkannya demi yang kedua berarti
    // siswa di ruang pertama ikut tertahan oleh masalah ruang lain.
    expect($hasil['berhasil'])->toBe(1)
        ->and($hasil['gagal'])->toHaveCount(1)
        ->and($hasil['gagal'][0]['pesan'])->toContain('belum punya pengawas')
        ->and($data['sesi']->refresh()->status)->toBe(StatusSesi::Berjalan)
        ->and($kedua->refresh()->status)->toBe(StatusSesi::Siap);
});

it('melaporkan event tertinggal SEKALI walau banyak sesi yang membukanya', function () {
    $data = siapkanPeluncuran($this);

    $kedua = sesiKeduaPeluncuran($this, $data);
    app(SesiService::class)->siapkan($kedua);

    $hasil = app(SesiService::class)->mulaiBanyak([$data['sesi'], $kedua]);

    // Dua sesi terbuka pada event Draft: keduanya berhasil, dan sebabnya
    // satu. Dua belas salinan kalimat yang sama berhenti dibaca.
    expect($hasil['berhasil'])->toBe(2)
        ->and($hasil['tertinggal'])->toHaveCount(1)
        ->and($hasil['tertinggal'][0])->toContain('Tandai event Siap');
});

/* ------------------------------------------------------- Laman peluncuran */

it('merender laman peluncuran dengan empat langkah berurutan', function () {
    $data = siapkanPeluncuran($this);

    $this->actingAs($this->admin)
        ->get("/admin/events/{$data['event']->id}/peluncuran")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Event/Peluncuran')
            ->has('langkah', 4)
            ->where('langkah.0.nomor', 1)
            ->where('langkah.1.nomor', 2)
            ->where('langkah.2.nomor', 3)
            ->where('langkah.3.nomor', 4)
            ->has('sesi')
            ->has('langkah_status'),
        );
});

it('menandai langkah terakhir TERTAHAN selama syarat kesiapan belum lulus', function () {
    $data = siapkanPeluncuran($this);

    $this->actingAs($this->admin)
        ->get("/admin/events/{$data['event']->id}/peluncuran")
        ->assertInertia(fn ($page) => $page
            // Fixture ini tidak memasang paket ke mapel event, jadi
            // gerbangnya memang belum lulus dan "Tandai Siap" belum boleh
            // ditawarkan.
            ->where('langkah.3.keadaan', 'tertahan'),
        );
});

it('menyiapkan sesi draft lewat aksi massal', function () {
    $data = siapkanPeluncuran($this);
    $kedua = sesiKeduaPeluncuran($this, $data);

    expect($kedua->refresh()->status)->toBe(StatusSesi::Draft);

    $this->actingAs($this->admin)
        ->post("/admin/events/{$data['event']->id}/peluncuran/siapkan")
        ->assertRedirect()
        ->assertSessionHas('sukses');

    // Sesi pertama sudah Siap sejak fixture, jadi ia DILEWATI - bukan
    // dilaporkan gagal.
    expect($kedua->refresh()->status)->toBe(StatusSesi::Siap);
});

it('membuka sesi siap lewat aksi massal, dan menyebut event yang tertinggal', function () {
    $data = siapkanPeluncuran($this);

    // Event sengaja DIBIARKAN Draft: inilah jalur yang dulu meninggalkan
    // event tertinggal tanpa satu pun pesan.
    $this->actingAs($this->admin)
        ->post("/admin/events/{$data['event']->id}/peluncuran/buka")
        ->assertRedirect()
        ->assertSessionHas('sukses');

    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Berjalan)
        ->and($data['event']->refresh()->status)->toBe(EventStatus::Draft);

    // Dan pesannya menyebut apa yang harus dikerjakan Admin berikutnya.
    $pesan = session('sukses');

    expect($pesan)->toContain('dibuka')
        ->and($pesan)->toContain('Tandai event Siap');
});

it('tidak menemukan event sekolah lain, dan tidak mengubah apa pun', function () {
    $data = siapkanPeluncuran($this);

    // Admin BUKAN dari sekolah event ini - ia dari sekolah lain. Binding
    // memakai `withoutGlobalScopes` di scope tenant, jadi yang menolak
    // adalah scope sekolah: bagi Admin itu, barisnya tidak ada (404), dan
    // itu jawaban yang benar - keberadaan event sekolah lain bukan urusan
    // mereka.
    $sekolahLain = School::factory()->create(['name' => 'Sekolah Lain']);

    $lain = User::factory()->admin()->untukSekolah($sekolahLain)
        ->create(['username' => 'admin.lain.peluncuran']);

    $sebelum = $data['sesi']->refresh()->status;

    // WAJIB: `SchoolResolver` menyimpan hasil per-permintaan, dan selama
    // fixture berjalan ia sudah ter-resolve ke `null` (tidak ada yang
    // terautentikasi). `null` berarti "jangan saring" - jadi tanpa
    // `forget()` di sini, scope tenant tetap mati dan test ini akan
    // melaporkan 200 seolah tidak ada isolasi sama sekali.
    $this->actingAs($lain);
    app(SchoolResolver::class)->forget();

    $this->get("/admin/events/{$data['event']->id}/peluncuran")->assertNotFound();

    $this->post("/admin/events/{$data['event']->id}/peluncuran/siapkan")->assertNotFound();

    // Dan sesinya memang tidak tersentuh.
    expect($data['sesi']->refresh()->status)->toBe($sebelum);
});
