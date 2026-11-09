<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\KehadiranService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\PenerbitKodePengawas;
use App\Domain\Facility\Services\RoomEntryLinkService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Inertia\Testing\AssertableInertia;

/**
 * Kode pengawas - milik ORANG, terlihat selalu, dan menentukan SIAPA yang masuk.
 *
 * =====================================================================
 * Berkas ini menjaga dua keputusan sekaligus, dan keduanya berbentuk data
 * =====================================================================
 * Blueprint `:995` sudah menuliskannya sejak awal:
 *
 *   "Tautan unik per ruang ... menentukan ruang mana yang dibuka; ia
 *    panjang, sulit ditebak, dan aman ditempel di pintu karena sendirian
 *    tidak membuka apa pun. Setelah tautan dibuka, pengawas mengetik kode
 *    pengawas enam angka untuk ruang itu; kode menentukan siapa yang masuk."
 *
 * Sampai 2026-11-07 kolomnya ada di `room_entry_links`, sehingga kalimat
 * kedua itu mustahil: kode yang menempel pada RUANG menentukan ruang, bukan
 * orang - dan karena ia tercetak pada kartu yang sama dengan tautannya,
 * memfoto kartu di daun pintu sudah cukup untuk masuk.
 *
 * Dua keputusan pemilik produk membentuk berkas ini:
 *
 *   1. Kode itu MILIK ORANG, dan hanya berlaku di ruang-sesi tempat orangnya
 *      ditugaskan. Kodenya MENENTUKAN ORANG - namanya sampai ke laman
 *      pengawas dan ke baris presensi yang ia tandatangani.
 *   2. Kode itu SELALU TERLIHAT di laman Kepanitiaan, karena dicetak menjadi
 *      kartu pengawas. Karena itu ia disimpan TERENKRIPSI (bisa dibaca
 *      kembali), bukan sebagai hash satu arah - dan "Terbitkan ulang"
 *      MENGGANTI kode yang lama, bukan menerbitkan yang pertama.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.kode',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
    ]);
});

/** Panitia berjabatan pengawas dengan akun, pada satu event. */
function panitiaPengawas(SchoolEvent $event, School $sekolah, array $ubah = []): EventCommitteeMember
{
    $orang = User::factory()->pengawas()->untukSekolah($sekolah)->create();

    return EventCommitteeMember::create(array_merge([
        'school_id' => $sekolah->id,
        'event_id' => $event->id,
        'user_id' => $orang->id,
        'name' => $orang->name,
        'position' => 'pengawas',
        'scope' => 'event',
        'status' => 'aktif',
    ], $ubah));
}

/* =====================================================================
 | Simpanan & penerbitan - laman Kepanitiaan
 |===================================================================== */

it('menyimpan kode TERENKRIPSI, bukan sebagai teks biasa', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $panitia = $data['event']->committee()->whereNotNull('user_id')->firstOrFail();
    $panitia->refresh();

    $mentah = $panitia->getAttributes()['code'];

    // Terbaca lewat cast `encrypted`...
    expect($panitia->code)->toMatch('/^\d{6}$/')
        // ...tetapi yang benar-benar tersimpan di basis data BUKAN enam angka
        // itu. Kalau baris ini lolos dengan kode terbaca, berarti kredensialnya
        // ada di dalam basis data - dan seluruh doktrinnya menjadi hiasan.
        ->and($mentah)->not->toBe($panitia->code)
        ->and($mentah)->not->toContain($panitia->code);
});

it('terbitkan() tidak mengganti kode yang sudah ada - kode itu STABIL', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $panitia = $data['event']->committee()->whereNotNull('user_id')->firstOrFail();
    $kode = $panitia->code;

    // Memanggil terbitkan() lagi - seperti yang dilakukan seeder atau
    // pembuatan massal - TIDAK boleh mengganti kode: kartu yang sudah
    // tercetak di meja pengawas harus tetap berlaku.
    expect(app(PenerbitKodePengawas::class)->terbitkan($panitia->refresh()))->toBe($kode)
        ->and($panitia->refresh()->code)->toBe($kode);
});

it('tombol terbitkan ulang mengganti kode, dan kode lama berhenti berlaku', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $ruang = $data['ruang'][0];
    $panitia = $data['event']->committee()->whereNotNull('user_id')->firstOrFail();
    $lama = $panitia->code;

    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($ruang), berlakuJam: 4);

    // Kode lama memang berlaku sebelum diganti - tanpa langkah ini testnya
    // akan hijau bahkan bila penerbitan pertamanya tidak pernah bekerja.
    $this->post("/p/{$tautan->slug}", ['kode' => $lama])
        ->assertRedirect(route('pengawas.dashboard'));

    $this->actingAs($this->admin)
        ->post("/admin/events/{$data['event']->id}/panitia/{$panitia->id}/kode")
        ->assertRedirect()
        ->assertSessionHas('sukses');

    $baru = $panitia->refresh()->code;

    expect($baru)->not->toBe($lama)
        ->and($baru)->toMatch('/^\d{6}$/');

    // Pesannya menyebut kode lama mati - kalau tidak, Admin yang menekan
    // tombolnya karena kartunya bocor akan mengira ada dua kode yang hidup.
    expect(session('sukses'))->toContain('Kode lama berhenti berlaku');

    // Kode lama kini ditolak, kode baru membuka.
    $this->post("/p/{$tautan->slug}", ['kode' => $lama])
        ->assertRedirect()
        ->assertSessionHas('galat');

    $this->post("/p/{$tautan->slug}", ['kode' => $baru])
        ->assertRedirect(route('pengawas.dashboard'));
});

it('mendaftarkan kode untuk pengawas baru saat ditambahkan, supaya kartunya bisa langsung dicetak', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $orang = User::factory()->pengawas()->untukSekolah($this->sekolah)->create(['name' => 'Pengawas Baru']);

    $this->actingAs($this->admin)
        ->post("/admin/events/{$data['event']->id}/panitia", [
            'name' => $orang->name,
            'position' => 'pengawas',
            'user_id' => $orang->id,
            'scope' => 'event',
        ])
        ->assertSessionHas('sukses');

    $panitia = EventCommitteeMember::query()
        ->where('event_id', $data['event']->id)
        ->where('user_id', $orang->id)
        ->firstOrFail();

    // Kodenya terbit tanpa klik terpisah, dan terbaca sebagai enam angka.
    expect($panitia->code)->toMatch('/^\d{6}$/')
        ->and($panitia->code_issued_at)->not->toBeNull();
});

it('menampilkan kode pengawas di laman Kepanitiaan, bukan menyembunyikannya', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $kode = $data['kode'][$data['ruang'][0]->id];

    $this->actingAs($this->admin)
        ->get("/admin/events/{$data['event']->id}/panitia")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Panitia')
            ->where('panitia.0.punya_kode', true)
            // Kodenya SENDIRI dikirim, karena ia selalu tampil di tabel.
            ->where('panitia.0.kode', $kode)
            ->whereNot('panitia.0.kode_terbit', null)
            // Tidak ada hash untuk dikirim - dan memang tidak ada hash lagi.
            ->missing('panitia.0.code_hash')
        );
});

it('menolak panitia tanpa akun, dengan alasan yang menyebut sebabnya', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $tanpaAkun = EventCommitteeMember::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $data['event']->id,
        'user_id' => null,
        'name' => 'Perwakilan Komite',
        'position' => 'pengawas',
        'scope' => 'event',
        'status' => 'aktif',
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/events/{$data['event']->id}/panitia/{$tanpaAkun->id}/kode")
        ->assertSessionHas('galat');

    // Sebabnya disebut, bukan sekadar "tidak boleh": tanpa akun, orangnya
    // tidak bisa ditugaskan ke ruang mana pun, sehingga kodenya tidak akan
    // pernah punya pintu - dan Admin yang mengira sebaliknya baru tahu pada
    // hari-H.
    expect(session('galat'))->toContain('belum punya akun')
        ->and($tanpaAkun->refresh()->code)->toBeNull();
});

it('menolak jabatan selain pengawas', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $ketua = panitiaPengawas($data['event'], $this->sekolah, [
        'position' => 'ketua',
        'name' => 'Ketua Panitia',
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/events/{$data['event']->id}/panitia/{$ketua->id}/kode")
        ->assertSessionHas('galat');

    expect(session('galat'))->toContain('bukan pengawas')
        ->and($ketua->refresh()->code)->toBeNull();
});

it('menolak panitia nonaktif', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $nonaktif = panitiaPengawas($data['event'], $this->sekolah, [
        'status' => 'nonaktif',
        'name' => 'Pengawas Nonaktif',
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/events/{$data['event']->id}/panitia/{$nonaktif->id}/kode")
        ->assertSessionHas('galat');

    expect($nonaktif->refresh()->code)->toBeNull();
});

it('menolak panitia milik event lain meski sekolahnya sama', function () {
    $satu = SchoolEvent::factory()->untukTahun($this->tahun)->create(['school_id' => $this->sekolah->id]);
    $lainDiSekolahSama = SchoolEvent::factory()->untukTahun($this->tahun)->create([
        'school_id' => $this->sekolah->id,
    ]);

    $panitia = panitiaPengawas($lainDiSekolahSama, $this->sekolah, ['name' => 'Pengawas Event Lain']);

    // Binding implisit mencari di seluruh sekolah, sehingga id ini lolos
    // tanpa pemeriksaan event_id. 404 - bukan 403 - karena bagi event ini
    // barisnya memang tidak ada.
    $this->actingAs($this->admin)
        ->post("/admin/events/{$satu->id}/panitia/{$panitia->id}/kode")
        ->assertNotFound();

    expect($panitia->refresh()->code)->toBeNull();
});

it('menolak Admin sekolah lain', function () {
    $lain = School::factory()->create();
    $adminLain = User::factory()->admin()->untukSekolah($lain)->create();
    $tahunLain = AcademicYear::factory()->untukSekolah($lain)->aktif()->create(['name' => '2026/2027']);

    $eventLain = SchoolEvent::factory()->untukTahun($tahunLain)->create(['school_id' => $lain->id]);
    $sini = SchoolEvent::factory()->untukTahun($this->tahun)->create(['school_id' => $this->sekolah->id]);
    $panitia = panitiaPengawas($eventLain, $lain, ['name' => 'Pengawas Sekolah Lain']);

    // Scope tenant sudah menolaknya di pintu masuk: bagi Admin ini, event
    // sekolah pertama tidak ada - dan 404 adalah jawaban yang benar untuk
    // keduanya sekaligus.
    $this->actingAs($adminLain)
        ->post("/admin/events/{$sini->id}/panitia/{$panitia->id}/kode")
        ->assertNotFound();
});

/* =====================================================================
 | Pencocokan - pintu ruang
 |===================================================================== */

it('kode pengawas yang ditugaskan membuka pintunya, dan namanya ikut sampai', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $ruang = $data['ruang'][0];
    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($ruang), berlakuJam: 4);

    $pengawas = $ruang->supervisors()->firstOrFail()->supervisor;

    $this->post("/p/{$tautan->slug}", ['kode' => $data['kode'][$ruang->id]])
        ->assertRedirect(route('pengawas.dashboard'));

    expect($tautan->fresh()->use_count)->toBe(1);

    // Tetap TAMU bagi framework: identitas di sini adalah hasil satu kali
    // pencocokan kredensial, bukan sesi terautentikasi.
    $this->assertGuest();

    // Tetapi identitasnya ADA, dan itulah yang mengubah laman pengawas dari
    // "ruang mana" menjadi "siapa".
    expect(KonteksRuang::penggunaSesi()?->id)->toBe($pengawas->id);

    $this->get('/pengawas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Pengawas/RuangTugas')
            ->where('pengawas', $pengawas->name)
        );
});

it('menolak kode pengawas yang TIDAK ditugaskan di ruang itu', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01', 'R-02'], 4);

    [$ruangR01, $ruangR02] = [$data['ruang'][0], $data['ruang'][1]];

    $tautanR01 = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($ruangR01), berlakuJam: 4);

    // Kodenya SAH - baru diterbitkan dan cocok dengan baris kepanitiaan
    // pengawas R-02. Yang tidak ada adalah PENUGASANNYA di R-01, dan itulah
    // yang menolaknya.
    //
    // Perhatikan bahwa kandidatnya disaring lebih dulu: kode ini tidak pernah
    // sampai ke perbandingan sama sekali. Kalau penyaringannya hilang,
    // jawabannya akan sama hari ini - dan berbeda pada hari ketika pengawas
    // sebuah ruang jumlahnya banyak.
    $this->post("/p/{$tautanR01->slug}", ['kode' => $data['kode'][$ruangR02->id]])
        ->assertRedirect()
        ->assertSessionHas('galat', 'Kode pengawas tidak cocok. Periksa kembali enam angkanya.');

    expect($tautanR01->fresh()->use_count)->toBe(0);
    $this->assertGuest();
    $this->get('/pengawas')->assertRedirect(route('login'));
});

it('menolak kode pengawas pada tautan yang sama setelah penugasannya dicabut', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $ruang = $data['ruang'][0];
    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($ruang), berlakuJam: 4);

    // Penugasan dilepas Admin. Kodenya masih hidup di baris kepanitiaannya -
    // yang berubah hanya haknya masuk ke ruang INI.
    $ruang->supervisors()->delete();

    $this->post("/p/{$tautan->slug}", ['kode' => $data['kode'][$ruang->id]])
        ->assertRedirect()
        ->assertSessionHas('galat');

    expect($tautan->fresh()->use_count)->toBe(0);
    $this->get('/pengawas')->assertRedirect(route('login'));
});

it('kode salah tidak menaikkan pemakaian tautannya', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);

    $this->post("/p/{$tautan->slug}", ['kode' => '000000'])
        ->assertRedirect()
        ->assertSessionHas('galat');

    expect($tautan->fresh()->use_count)->toBe(0);
});

/* =====================================================================
 | Akibatnya: presensi menyebut ORANGNYA
 |===================================================================== */

it('mencatat presensi yang ditandatangani lewat pintu atas nama pengawas itu', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $ruang = $data['ruang'][0];
    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($ruang), berlakuJam: 4);

    $this->post("/p/{$tautan->slug}", ['kode' => $data['kode'][$ruang->id]])
        ->assertRedirect(route('pengawas.dashboard'));

    $pengawas = KonteksRuang::penggunaSesi();

    expect($pengawas)->not->toBeNull();

    $record = AttendanceRecord::query()
        ->whereIn('exam_participant_id', $ruang->participants()->pluck('id'))
        ->firstOrFail();

    // Lewat jalur yang SUNGGUH dipakai pengawas - bukan `forceFill`, yang
    // akan membuktikan kolomnya bisa diisi, bukan bahwa pengisinya tahu
    // siapa dirinya.
    app(KehadiranService::class)->catat(
        $record,
        StatusPresensi::Hadir,
        KonteksRuang::dari($ruang),
    );

    $record->refresh();

    // Inilah yang berubah 2026-11-07, dan inilah yang diminta pemilik
    // produk: dua kolom yang dulu selalu null bagi pemegang tautan kini
    // menyebut orangnya.
    expect($record->verified_by)->toBe($pengawas->id)
        ->and($record->pelaku_label)->toBe($pengawas->name);

    // Dan laman presensinya menyebut nama yang sama, bukan "R-01 (tautan
    // ruang)".
    $this->get("/pengawas/ruang/{$ruang->id}/presensi/{$record->exam_participant_id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('diverifikasi_oleh', $pengawas->name)
        );
});

it('mempertahankan label ruang bila AKUN pengawasnya sudah tidak ada lagi', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $ruang = $data['ruang'][0];
    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($ruang), berlakuJam: 4);

    $penggunaId = $ruang->supervisors()->firstOrFail()->supervisor_id;

    $this->post("/p/{$tautan->slug}", ['kode' => $data['kode'][$ruang->id]])
        ->assertRedirect(route('pengawas.dashboard'));

    // Akunnya hilang di tengah sesi. Hibak sesinya TIDAK ikut mati - dan
    // justru itu sebabnya fallback-nya ada: satu baris di tabel lain yang
    // hilang tidak boleh membuat laman presensi 404 bagi pengawas yang
    // sedang berdiri di depan 32 siswa.
    EventCommitteeMember::query()->where('user_id', $penggunaId)->delete();
    User::query()->whereKey($penggunaId)->delete();

    $konteks = KonteksRuang::dari($ruang);

    // Log tidak boleh meledak: label ruang tetap jawaban yang benar, dan
    // itulah satu-satunya jalur yang masih memakainya.
    expect($konteks->pelakuLabel())->toBe($ruang->eventRoom->room->code.' (tautan ruang)');

    $this->get("/pengawas/ruang/{$ruang->id}/presensi")->assertOk();
});

it('membuka pintu dicatat di log dengan nama pengawasnya', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $ruang = $data['ruang'][0];
    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($ruang), berlakuJam: 4);

    $pengawas = $ruang->supervisors()->firstOrFail()->supervisor;

    $this->post("/p/{$tautan->slug}", ['kode' => $data['kode'][$ruang->id]])
        ->assertRedirect(route('pengawas.dashboard'));

    $log = ActivityLog::query()
        ->where('action', 'Membuka pintu ruang')
        ->latest('id')
        ->firstOrFail();

    // Namanya ikut ke log, dan itu bukan tambahan yang kosmetik: sebelum
    // 2026-11-07 baris ini harus berbunyi "R-01 (tautan ruang)" karena
    // memang tidak ada orang yang bisa disebut.
    expect($log->metadata_json['pengawas'])->toBe($pengawas->name);
});
