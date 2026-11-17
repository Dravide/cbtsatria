<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\SessionReport;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Papan pantau publik - `/pantau/{slug}`, tanpa login.
 *
 * =====================================================================
 * Yang dijaga, dan mengapa bukan sekadar "lamannya terbuka"
 * =====================================================================
 * Alamat ini di luar `auth`. Apa pun yang lolos ke payload-nya tayang untuk
 * seluruh internet, dan tautan yang tertempel di lorong bisa difoto siapa
 * pun yang lewat. Tiga kelompok test:
 *
 *   1. BATAS ISI - payload tidak memuat nama siswa, NIS, kursi, kelas,
 *      token, atau isi berita acara. Diuji dengan memeriksa isi payload
 *      secara harfiah, bukan dengan memeriksa ada tidaknya satu kolom:
 *      kolom yang lupa disaring akan lolos dari pemeriksaan yang terakhir.
 *
 *   2. ANGKA - mengerjakan/selesai/terputus/terkunci dihitung dari
 *      `exam_attempts`, BUKAN dari presensi. Peserta yang hadir tetapi belum
 *      menekan Mulai adalah "belum mulai", bukan "mengerjakan".
 *
 *   3. TIGA KEADAAN - berjalan, menunggu, selesai. Ketiganya dibedakan
 *      karena papan yang menyala "langsung" pada sesi yang sudah ditutup
 *      adalah janji bahwa angkanya bergerak padahal tidak.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create([
        'name' => 'SMP Negeri 1 Cipanas',
        'papan_slug' => 'smk1-uas-ganjil',
    ]);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.pantau',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();
    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);
});

/** Props halaman pantau - dipakai dua jalur (Inertia dan JSON). */
function potretPantau(TestCase $test, string $slug = 'smk1-uas-ganjil'): array
{
    $respons = $test->get("/pantau/{$slug}");

    $respons->assertOk();

    return $respons->viewData('page')['props']['potret'];
}

it('membuka papan tanpa login, dan mengirim keadaan berjalan', function () {
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 4);

    // Sesi di `siapkanPresensi` sudah `siap`, bukan `berjalan`. Papan
    // menjawab "menunggu" untuk keadaan itu - dan itu jawaban yang benar:
    // tidak ada yang sedang mengerjakan sebelum gerbangnya dibuka.
    masukSebagai($this, $this->admin)
        ->get('/pantau/smk1-uas-ganjil')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Publik/Pantau')
            ->where('slug', 'smk1-uas-ganjil')
            ->where('potret.ada', true)
            ->where('potret.keadaan', 'menunggu')
            ->where('potret.sekolah', 'SMP Negeri 1 Cipanas')
            ->has('potret.ruang', 1)
            ->has('denyut_url')
            ->has('papan_url')
        );
});

it('TIDAK pernah mengirim nama siswa, NIS, kursi, kelas, atau token', function () {
    // Inti batasnya. Pemeriksaannya mencari NAMA SISWA dan NIS di seluruh
    // payload sebagai teks - bukan memeriksa ada tidaknya satu kolom yang
    // dikenal, karena kolom yang lupa disaring justru yang tidak dikenal
    // pemeriksaannya.
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 4);

    $nama = $d['siswa'][0]->name;
    $siswa = $d['siswa'][0];
    $nis = $siswa->studentProfile?->nis ?? '';

    $isi = json_encode(potretPantau($this));

    expect($isi)->not->toContain($nama)
        ->and($isi)->not->toContain('student_id')
        ->and($isi)->not->toContain('nis')
        ->and($isi)->not->toContain('kursi')
        ->and($isi)->not->toContain('token');

    if ($nis !== '') {
        expect($isi)->not->toContain($nis);
    }
});

it('menolak 404 untuk slug yang tidak ada, dalam JSON untuk denyut', function () {
    // Denyut dipanggil `fetch` di latar belakang; HTML 404 akan gagal
    // diurai tanpa satu pun pesan yang bisa dibaca halamannya.
    $this->get('/pantau/tidak-ada-sama-sekali')->assertNotFound();

    $this->getJson('/pantau/tidak-ada-sama-sekali/denyut')->assertNotFound();
});

it('menolak sekolah yang belum menyalakan papannya', function () {
    // `papan_slug` nullable: sekolah yang belum menyalakan papannya tidak
    // punya alamat sama sekali. Rute yang tidak pernah lahir tidak bisa
    // ditebak - dan itu lebih kuat daripada 403 yang membuktikan alamatnya
    // ada.
    $lain = School::factory()->create(['name' => 'SMP Lain', 'papan_slug' => null]);

    $this->get('/pantau/null-pun-tidak-bisa')->assertNotFound();
    $this->getJson('/pantau/null-pun-tidak-bisa/denyut')->assertNotFound();
});

it('menghitung mengerjakan dan selesai dari pengerjaan, bukan dari presensi', function () {
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 4);

    $peserta = $d['ruang'][0]->participants()->orderBy('id')->get();

    // Satu peserta mengerjakan, satu sudah mengumpulkan, dua belum mulai -
    // dan keempatnya sudah DIPRESENSI hadir oleh `siapkanPresensi`. Kalau
    // papan membaca presensi, keempatnya akan terbaca sama.
    ExamAttempt::query()->create([
        'school_id' => $this->sekolah->id,
        'exam_participant_id' => $peserta[0]->id,
        'attempt_number' => 1,
        'started_at' => now()->subMinutes(10),
        'deadline_at' => now()->addMinutes(80),
        'status' => ExamAttempt::STATUS_BERJALAN,
        'last_seen_at' => now(),
        'last_heartbeat_at' => now(),
        'violation_count' => 0,
        'violation_worst_severity' => 0,
    ]);

    ExamAttempt::query()->create([
        'school_id' => $this->sekolah->id,
        'exam_participant_id' => $peserta[1]->id,
        'attempt_number' => 1,
        'started_at' => now()->subMinutes(80),
        'deadline_at' => now()->addMinutes(10),
        'submitted_at' => now()->subMinutes(5),
        'status' => ExamAttempt::STATUS_DIKIRIM,
        'violation_count' => 0,
        'violation_worst_severity' => 0,
    ]);

    $potret = potretPantau($this);

    expect($potret['angka']['peserta'])->toBe(4)
        ->and($potret['angka']['mengerjakan'])->toBe(1)
        ->and($potret['angka']['selesai'])->toBe(1)
        ->and($potret['angka']['belum_mulai'])->toBe(2);
});

it('tidak menghitung peserta yang belum mulai sebagai terputus', function () {
    // "Terputus" adalah kesimpulan dari jam kabar terakhir. Peserta yang
    // belum mulai belum punya jam kabar sama sekali - dan menghitungnya
    // sebagai terputus membuat sesi yang belum dibuka terbaca sebagai sesi
    // yang seluruh jaringannya putus.
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 4);

    $peserta = $d['ruang'][0]->participants()->orderBy('id')->get();

    // Satu mengerjakan dan sudah lama tanpa kabar: ini TERPUTUS.
    ExamAttempt::query()->create([
        'school_id' => $this->sekolah->id,
        'exam_participant_id' => $peserta[0]->id,
        'attempt_number' => 1,
        'started_at' => now()->subMinutes(30),
        'deadline_at' => now()->addMinutes(60),
        'status' => ExamAttempt::STATUS_BERJALAN,
        'last_seen_at' => now()->subMinutes(5),
        'last_heartbeat_at' => now()->subMinutes(5),
        'violation_count' => 0,
        'violation_worst_severity' => 0,
    ]);

    $potret = potretPantau($this);

    // Satu, bukan empat.
    expect($potret['angka']['terputus'])->toBe(1);
});

it('menyebut keadaan laporan per ruang tanpa membocorkan isinya', function () {
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 4);

    $ruang = $d['ruang'][0];

    // Belum dibuka pengawasnya.
    expect(potretPantau($this)['ruang'][0]['laporan'])->toBe('belum');

    // Draf kosong - dibuka, belum lengkap.
    SessionReport::query()->create([
        'school_id' => $this->sekolah->id,
        'exam_session_room_id' => $ruang->id,
        'status' => SessionReport::STATUS_DRAFT,
        'catatan' => 'Rahasia catatan yang tidak boleh tayang di papan publik.',
    ]);

    $potret = potretPantau($this);

    expect($potret['ruang'][0]['laporan'])->toBe('draf');

    // Isi berita acara TIDAK ikut - hanya keadaannya.
    expect(json_encode($potret))->not->toContain('Rahasia catatan');
});

it('membedakan keadaan berjalan, menunggu, dan selesai', function () {
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    // MENUNGGU: sesi `siap`, belum berjalan.
    expect(potretPantau($this)['keadaan'])->toBe('menunggu');

    // BERJALAN.
    $d['sesi']->forceFill(['status' => 'berjalan'])->save();

    app(SchoolResolver::class)->forget();

    expect(potretPantau($this)['keadaan'])->toBe('berjalan');

    // SELESAI: jendelanya sudah lewat, meski statusnya belum ditutup
    // `ujian:tutup-sesi`. Papan yang menyala "langsung" di sini adalah
    // janji palsu.
    $d['sesi']->forceFill([
        'status' => 'berjalan',
        'access_opens_at' => now()->subHours(3),
        'access_closes_at' => now()->subHours(1),
        'hard_end_at' => now()->subMinute(),
    ])->save();

    app(SchoolResolver::class)->forget();

    expect(potretPantau($this)['keadaan'])->toBe('selesai');
});

it('menjawab denyut dengan potret yang SAMA dengan halaman', function () {
    // Satu method menyusun keduanya. Dua tempat yang menyusun angka ini
    // adalah dua tempat yang suatu hari berbeda - dan perbedaannya akan
    // terlihat sebagai angka yang melompat tiap lima detik tanpa sebab.
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 4);

    $halaman = potretPantau($this);

    $json = $this->getJson('/pantau/smk1-uas-ganjil/denyut')
        ->assertOk()
        ->json();

    expect($json['keadaan'])->toBe($halaman['keadaan'])
        ->and($json['angka'])->toBe($halaman['angka'])
        ->and($json['sesi']['id'])->toBe($halaman['sesi']['id'])
        ->and(count($json['ruang']))->toBe(count($halaman['ruang']));
});

it('tidak mengirim nama pengguna atau email pengawas, hanya namanya', function () {
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 4);

    $pengawas = User::query()->where('school_id', $this->sekolah->id)->where('role', 'pengawas')->first();

    // `muatan()` di `PapanPengawas` memilih `supervisor:id,name` saja, dan
    // papan ini mengikuti aturan yang sama. Username dan email pengguna
    // adalah kebocoran yang tidak dibutuhkan fitur ini.
    $isi = json_encode(potretPantau($this));

    expect($isi)->not->toContain('username')
        ->and($isi)->not->toContain('email')
        ->and($isi)->not->toContain('@');
});
