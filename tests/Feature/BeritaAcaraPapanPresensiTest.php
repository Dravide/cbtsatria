<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\SessionReport;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ringkasan berita acara pada papan presensi Admin.
 *
 * =====================================================================
 * Dua bentuk kunci, dan yang salah adalah yang paling mudah ditulis
 * =====================================================================
 * Kartu di laman `/admin/events/{event}/presensi` berkunci RUANG EVENT;
 * berita acara berkunci RUANG-SESI (`(school_id, exam_session_room_id)`).
 * Satu ruang event bisa memuat beberapa ruang-sesi, sehingga bentuk yang
 * benar untuk laporan di kartu itu DAFTAR - bukan satu nilai. Test yang
 * hanya punya satu sesi tidak akan pernah membedakan keduanya.
 *
 * =====================================================================
 * Yang dijaga: papan ini MEMBACA dan tidak MEMBUAT
 * =====================================================================
 * `LaporanSesiService::untuk()` memakai `firstOrCreate`: laman pengawas
 * membuat draf hanya dengan dibuka, dan itu memang disengaja di sana.
 * Laman Admin yang memakainya akan membuat draf untuk setiap ruang setiap
 * kali papan disegarkan - dan kabar "pengawas sudah membuka berita
 * acaranya" berhenti berarti apa pun. Test di bawah memeriksa jumlah baris
 * `session_reports` SESUDAH laman dibuka, bukan sesudahnya dikira-kira.
 *
 * =====================================================================
 * Admin tidak menandatangani, dan itu tidak diuji ulang di sini
 * =====================================================================
 * Gerbangnya ada di `AttendancePolicy::tandatangani()`, di
 * `BeritaAcaraController::tandaTangan()`, dan di prop `boleh_ttd` - ketiganya
 * sudah diuji Fase 6. Yang diuji di sini hanya bahwa papan presensi TIDAK
 * menambahkan jalan keempat: tidak ada aksi menulis di payload-nya.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.berita.acara',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);
});

/** Jumlah kueri yang dijalankan satu permintaan GET. */
function hitungKueriBeritaAcara(TestCase $test, string $url): int
{
    $kueri = 0;

    DB::listen(function () use (&$kueri) {
        $kueri++;
    });

    masukUji($test, $test->admin)->get($url)->assertOk();

    return $kueri;
}

/** Props halaman presensi yang benar-benar dikirim ke peramban. */
function propsPresensi(TestCase $test, string $url): array
{
    return masukUji($test, $test->admin)->get($url)->viewData('page')['props'];
}

it('mengirim berita acara yang SUDAH ada beserta kekurangannya', function () {
    $d = siapkanKocok($this->admin, $this->tahun);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$d['event']->id}/penempatan", ['benih' => 'BERITA1'])
        ->assertSessionMissing('galat');

    $ruang = $d['ruang'][0];

    // Draf dibuat lewat jalur yang SUNGGUH membuatnya - lamannya dibuka.
    // `forceFill` akan membuktikan papan Admin membaca kolom, bukan bahwa
    // draf yang dibaca adalah draf yang benar-benar ada.
    masukUji($this, $this->admin)
        ->get("/pengawas/ruang/{$ruang->id}/berita-acara")
        ->assertOk();

    $laporan = SessionReport::query()
        ->where('exam_session_room_id', $ruang->id)
        ->sole();

    expect($laporan->final())->toBeFalse();

    $props = propsPresensi($this, "/admin/events/{$d['event']->id}/presensi");

    $baris = collect($props['baris'])->firstWhere(
        fn (array $b) => collect($b['berita_acara'])->contains('ruang_sesi_id', (int) $ruang->id),
    );

    expect($baris)->not->toBeNull();

    $l = collect($baris['berita_acara'])->firstWhere('ruang_sesi_id', (int) $ruang->id);

    expect($l['dibuka'])->toBeTrue()
        ->and($l['final'])->toBeFalse()
        ->and($l['selesai'])->toBeFalse()
        ->and($l['foto'])->toBe(0)
        ->and($l['foto_sesudah'])->toBeFalse()
        ->and($l['ttd_pengawas'])->toBeFalse()
        // Daftar kekurangannya datang dari `SessionReport::kekuranganFinal()`,
        // bukan disusun ulang di controller: aturan yang hidup di dua tempat
        // akan berbeda diam-diam, dan yang berbeda berupa laporan yang tampak
        // selesai padahal belum.
        ->and($l['kurang'])->toBe($laporan->kekuranganFinal())
        // Alamatnya RELATIF, bukan absolut. Host absolut yang ditanam di
        // payload akan salah begitu aplikasinya dibuka lewat domain lain
        // (dev `127.0.0.1:8781`, produksi di balik proxy), dan tautan yang
        // salah host adalah tautan yang membuka laman lain.
        ->and($l['url'])->toBe('/pengawas/ruang/'.$ruang->id.'/berita-acara');
});

it('menandai ruang yang berita acaranya belum dibuka TANPA membuat draf', function () {
    $d = siapkanKocok($this->admin, $this->tahun);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$d['event']->id}/penempatan", ['benih' => 'BERITA2'])
        ->assertSessionMissing('galat');

    // Prasyaratnya dibuktikan lebih dulu: belum satu pun draf ada. Tanpa
    // ini, pemeriksaan di bawah bisa hijau karena fixture-nya keliru.
    expect(SessionReport::query()->count())->toBe(0);

    $props = propsPresensi($this, "/admin/events/{$d['event']->id}/presensi");

    foreach ($props['baris'] as $b) {
        foreach ($b['berita_acara'] as $l) {
            expect($l['dibuka'])->toBeFalse()
                // `kurang` kosong untuk ruang yang belum dibuka, dan itu
                // bukan kelalaian: tidak ada yang kurang pada dokumen yang
                // belum ada.
                ->and($l['kurang'])->toBe([])
                ->and($l['selesai'])->toBeFalse();
        }
    }

    // INTI test ini: membuka papan Admin tidak menulis satu baris pun.
    // `LaporanSesiService::untuk()` memakai `firstOrCreate`, sehingga
    // satu pemanggilan yang keliru di sini menghasilkan 33 draf kosong
    // setiap kali papan disegarkan.
    expect(SessionReport::query()->count())->toBe(0);

    // Angkanya ikut: seluruh ruang yang punya sesi dihitung belum selesai.
    expect($props['angka']['berita_acara_belum'])->toBe($props['angka']['ruang']);
});

it('mengirim BEBERAPA berita acara saat satu ruang event dipakai dua sesi', function () {
    // Bentuk yang membedakan kunci ruang-EVENT dari kunci ruang-SESI. Satu
    // ujian tambahan di ruang event yang sama menghasilkan ruang-sesi kedua,
    // sehingga kartunya harus membawa dua baris berita acara.
    $d = siapkanKocok($this->admin, $this->tahun);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$d['event']->id}/penempatan", ['benih' => 'BERITA3'])
        ->assertSessionMissing('galat');

    [$ujian, $sesi] = ujianTambahan($this->admin, $d['event'], 'KEDUA');

    $ruangKedua = ExamSessionRoom::query()
        ->where('exam_session_id', $sesi->id)
        ->orderBy('id')
        ->get();

    expect($ruangKedua)->not->toBeEmpty();

    // Satu ruang event, dua ruang-sesi - dibuktikan dari datanya, bukan
    // diasumsikan, karena `ujianTambahan()` memakai SELURUH ruang event.
    expect($ruangKedua->first()->event_room_id)->toBe($d['ruang'][0]->event_room_id);

    $props = propsPresensi($this, "/admin/events/{$d['event']->id}/presensi");

    $baris = collect($props['baris'])->firstWhere('ruang_id', (int) $d['ruang'][0]->event_room_id);

    expect($baris)->not->toBeNull()
        ->and($baris['berita_acara'])->toHaveCount(2);

    // Kedua barisnya menunjuk ruang-sesi yang BERBEDA, dan masing-masing
    // punya alamatnya sendiri - dua sesi adalah dua dokumen, bukan satu
    // dokumen dua kali.
    $id = collect($baris['berita_acara'])->pluck('ruang_sesi_id');

    expect($id->unique())->toHaveCount(2)
        ->and(collect($baris['berita_acara'])->pluck('url')->unique())->toHaveCount(2);

    // Dan ujian kedua benar-benar milik event yang sama - kalau tidak,
    // `ujianTambahan()` yang diuji, bukan papannya.
    expect((int) $ujian->event_id)->toBe((int) $d['event']->id);
});

it('tidak menambah kueri saat ruangnya ditambah - berita acara ikut terbaca sekaligus', function () {
    // Sifat yang sama dengan `PemantauanQueryTest`: menambah ruang tidak
    // menambah kueri. Berita acara dibaca SATU kali untuk seluruh event,
    // dengan berkas dan tanda tangannya dimuat sekaligus - bukan satu
    // kueri per ruang.
    $kecil = siapkanKocok($this->admin, $this->tahun, 3, 5, 4, 2);

    $besar = siapkanKocok($this->admin, $this->tahun, 6, 10, 4, 4);

    foreach ([$kecil, $besar] as $i => $d) {
        masukSebagai($this, $this->admin)
            ->post("/admin/events/{$d['event']->id}/penempatan", ['benih' => 'BERITAKUERI'.$i])
            ->assertSessionMissing('galat');

        // Draf dibuat untuk SELURUH ruang-sesi, supaya yang diukur adalah
        // laman dengan berita acara - bukan laman yang berkas dan tanda
        // tangannya tidak pernah dimuat.
        foreach ($d['ruang'] as $ruang) {
            masukUji($this, $this->admin)
                ->get("/pengawas/ruang/{$ruang->id}/berita-acara")
                ->assertOk();
        }
    }

    $kueriKecil = hitungKueriBeritaAcara($this, "/admin/events/{$kecil['event']->id}/presensi");

    // `forget()` wajib: tanpa itu `SchoolResolver` memakai hasil resolusi
    // permintaan sebelumnya, dan yang diukur bukan lagi dua laman yang sama.
    app(SchoolResolver::class)->forget();

    $kueriBesar = hitungKueriBeritaAcara($this, "/admin/events/{$besar['event']->id}/presensi");

    expect($kueriBesar - $kueriKecil)->toBe(0);
});

it('menghitung berita acara yang sudah final sebagai selesai', function () {
    $d = siapkanKocok($this->admin, $this->tahun);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$d['event']->id}/penempatan", ['benih' => 'BERITA4'])
        ->assertSessionMissing('galat');

    $ruang = $d['ruang'][0];

    masukUji($this, $this->admin)
        ->get("/pengawas/ruang/{$ruang->id}/berita-acara")
        ->assertOk();

    // Final-nya ditulis langsung: jalan normalnya menuntut foto dan tanda
    // tangan, dan keduanya sudah diuji Fase 6 di berkasnya sendiri.
    // Mengulanginya di sini hanya akan menggandakan test itu - yang diuji
    // di sini adalah bahwa papan membaca `status`, bukan bahwa tombol
    // kirimnya bekerja.
    SessionReport::query()
        ->where('exam_session_room_id', $ruang->id)
        ->update([
            'status' => SessionReport::STATUS_FINAL,
            'submitted_at' => now(),
        ]);

    $props = propsPresensi($this, "/admin/events/{$d['event']->id}/presensi");

    $baris = collect($props['baris'])->firstWhere(
        fn (array $b) => collect($b['berita_acara'])->contains('ruang_sesi_id', (int) $ruang->id),
    );

    $l = collect($baris['berita_acara'])->firstWhere('ruang_sesi_id', (int) $ruang->id);

    expect($l['final'])->toBeTrue()
        ->and($l['selesai'])->toBeTrue()
        ->and($l['dikirim_pada'])->not->toBeNull();

    // Satu ruang dari dua sudah selesai - angka "belum" menghitung SATU
    // ruang, bukan nol: ruang kedua masih belum dibuka.
    expect($props['angka']['berita_acara_belum'])->toBe($props['angka']['ruang'] - 1);
});

it('menampilkan jalur lama dengan satu berita acara per kartu ruang-sesi', function () {
    // Event yang belum pernah dikocok tidak punya peta, sehingga lamannya
    // jatuh ke `presensiPerRuangSesi()` - dan di sana SATU kartu SUDAH satu
    // ruang-sesi. Bentuk `berita_acara` tetap daftar, karena React membacanya
    // dengan satu bentuk; yang berbeda hanya panjangnya.
    $data = siapkanPresensi($this->sekolah, $this->tahun, semesterBeritaAcara($this), $this->admin);

    $ruang = $data['ruang'][0];

    masukUji($this, $this->admin)
        ->get("/pengawas/ruang/{$ruang->id}/berita-acara")
        ->assertOk();

    $props = propsPresensi($this, "/admin/events/{$data['event']->id}/presensi");

    expect($props['baris'])->not->toBeEmpty();

    foreach ($props['baris'] as $b) {
        expect($b['berita_acara'])->toHaveCount(1);

        $l = $b['berita_acara'][0];

        expect($l)->toHaveKeys([
            'ruang_sesi_id', 'pengawas', 'url', 'dibuka', 'final',
            'dikirim_pada', 'foto', 'foto_sesudah', 'ttd_pengawas',
            'ttd_oleh', 'kurang', 'selesai',
        ]);
    }

    $barisRuang = collect($props['baris'])->firstWhere('ruang_id', (int) $ruang->id);

    expect($barisRuang['berita_acara'][0]['dibuka'])->toBeTrue();

    // Kehadirannya tidak berubah oleh pembacaan berita acara: presensi dan
    // berita acara adalah dua catatan yang berbeda atas ruangan yang sama.
    expect(AttendanceRecord::query()->count())->toBe(
        ExamParticipant::query()->whereNotNull('exam_session_room_id')->count(),
    );
});

/** Semester untuk `siapkanPresensi()`; dibuat di sini supaya `beforeEach` tetap ringkas. */
function semesterBeritaAcara(TestCase $test): Semester
{
    return Semester::factory()->create([
        'school_id' => $test->sekolah->id,
        'academic_year_id' => $test->tahun->id,
        'name' => 'Ganjil',
    ]);
}
