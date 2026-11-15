<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\Subject;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\LeisureGameScore;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Permainan waktu luang - wireframe-waktu-luang.html.
 *
 * =====================================================================
 * Yang paling menentukan di berkas ini: KAPAN pintunya TERTUTUP
 * =====================================================================
 * Fitur ini gagal dengan cara yang tidak berbunyi. Permainan yang bisa
 * dibuka sebelum ujian pertama - atau yang tetap berjalan sesudah sesi
 * berikutnya dibuka - tidak menimbulkan galat apa pun; ia hanya menjadi
 * jalan keluar dari ruang ujian, dan tidak ada satu pun tangkapan layar yang
 * memperlihatkannya.
 *
 * Karena itu separuh berkas ini membuktikan TIDAK ADANYA akses:
 *
 *   - tanpa attempt yang sudah ditutup, `/siswa/luang` mengalihkan;
 *   - setelah sesi berikutnya dibuka, GET mengalihkan dan POST menjawab 409;
 *   - tab "Main" tidak dikirim ke tabbar saat jedanya tidak ada.
 *
 * =====================================================================
 * Skor diperiksa server, dan itu diuji dari DUA arah
 * =====================================================================
 * (a) Kiriman yang mustahil TETAP TERSIMPAN sebagai baris ditolak - bukan
 *     dibuang. Baris itulah satu-satunya jawaban yang bisa diberikan kepada
 *     Admin yang bertanya mengapa seorang anak tidak ada di papan.
 * (b) Skor yang tersimpan adalah hasil hitung SERVER dari `distance` dan
 *     `duration_ms`. Kiriman yang mengaku berjarak jauh dalam waktu mustahil
 *     tersimpan dengan alasan penolakannya.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.luang',
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

/**
 * Ujian dengan DUA sesi: yang pertama sudah dikumpulkan, yang kedua baru
 * dibuka satu jam lagi.
 *
 * ---------------------------------------------------------------------
 * Mengapa sesi kedua harus ujian LAIN, bukan sesi kedua di ujian yang sama
 * ---------------------------------------------------------------------
 * `PenempatanService::sebar()` menempatkan setiap peserta pada SATU sesi, dan
 * `unique(exam_id, student_id)` melarang penempatan kedua di ujian yang sama.
 * Jadi "sesi berikutnya" di dunia nyata selalu berasal dari ujian berikutnya -
 * Matematika lalu Bahasa Indonesia - dan menyiapkannya di sini dengan cara
 * yang sama membuat tes ini menguji bentuk data yang benar-benar ada.
 *
 * @return array<string, mixed>
 */
function siapkanJeda(object $test): array
{
    $data = siapkanUjianSiswa($test->sekolah, $test->tahun, $test->semester, $test->admin);

    $peserta = $data['peserta'][0];

    verifikasiHadir($test, $data['pengawas'], $data['ruang'][0], $peserta);

    mulaiAttempt($test, $data['siswa'][0], $peserta, $data['token']);

    // Attempt ditutup lewat kolomnya: yang diuji di sini jendela waktu
    // luangnya, bukan transisi attempt - dan transisi itu sudah diuji di
    // SelesaiSiswaTest.
    ExamAttempt::query()
        ->where('exam_participant_id', $peserta->id)
        ->update([
            'status' => ExamAttempt::STATUS_DIKIRIM,
            'submitted_at' => now(),
        ]);

    // Sesi yang tadi dikerjakan ditutup jendelanya: anak sudah selesai, dan
    // sesi berikutnya yang menentukan kapan jedanya berakhir.
    $data['sesi']->forceFill(['hard_end_at' => now()->subMinute()])->save();

    $berikut = sesiBerikut($test, $data['event']);

    return $data + [
        'orang' => $peserta,
        'sesi_berikut' => $berikut,
    ];
}

/**
 * Ujian kedua di event yang sama, sesinya dibuka satu jam lagi, dengan siswa
 * yang sama ditempatkan di sana.
 */
function sesiBerikut(object $test, $event): ExamSession
{
    $mapel = Subject::factory()->create([
        'school_id' => $test->sekolah->id,
        'code' => 'BIN-'.$event->id,
        'name' => 'Bahasa Indonesia '.$event->id,
    ]);

    EventSubject::create([
        'school_id' => $test->sekolah->id,
        'event_id' => $event->id,
        'subject_id' => $mapel->id,
    ]);

    $paket = paketUntukUjian($test->admin, $mapel);

    $ujian = app(ExamRegistrar::class)->buat($event, [
        'subject_id' => $mapel->id,
        'question_package_id' => $paket->id,
        'title' => 'Ujian Akhir Semester Bahasa Indonesia',
        'exam_type' => 'Ujian akhir semester',
        'duration_minutes' => 90,
        'allow_late_entry' => false,
        'class_id' => [],
    ], $test->admin->id);

    $ruangEvent = EventRoom::query()->where('event_id', $event->id)->firstOrFail();

    $sesi = app(SesiService::class)->buat($ujian, [
        'session_number' => 1,
        'access_opens_at' => now()->addHour(),
        'access_closes_at' => now()->addHours(2),
        'hard_end_at' => now()->addHours(3),
        'duration_minutes' => 90,
        'event_room_id' => [$ruangEvent->id],
    ]);

    $penempatan = app(PenempatanService::class);
    $penempatan->tarikPeserta($ujian);
    $penempatan->sebar($ujian, $sesi);

    return $sesi;
}

/* ================================================== Pintu yang tertutup */

it('mengalihkan dari laman permainan bila belum ada attempt yang ditutup', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    // Belum ada attempt sama sekali - ini keadaan pagi hari, sebelum ujian
    // pertama. Menawarkan permainan di sini adalah menawarkan jalan keluar
    // sebelum ujiannya dimulai.
    masukUji($this, $data['siswa'][0])
        ->get('/siswa/luang')
        ->assertRedirect('/siswa');
});

it('tidak mengirim tab Main ke tabbar beranda sebelum ada jeda', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $props = masukUji($this, $data['siswa'][0])
        ->get('/siswa')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['luang'])->toBeFalse();
});

it('menutup pintunya kembali begitu sesi berikutnya dibuka', function () {
    $data = siapkanJeda($this);

    $this->travel(2)->hours();

    // Sesi yang tadinya satu jam lagi kini sudah lewat jam bukanya.
    masukUji($this, $data['siswa'][0])
        ->get('/siswa/luang')
        ->assertRedirect('/siswa');

    // Dan kiriman yang datang terlambat pun ditolak SERVER, bukan hanya
    // disembunyikan peramban. Inilah yang membuat "permainan menutup
    // dirinya" berlaku juga pada tab yang ditahan terbuka.
    masukUji($this, $data['siswa'][0])
        ->postJson('/siswa/luang/skor', ['distance' => 400, 'duration_ms' => 20_000])
        ->assertStatus(409);
});

/* ==================================================== Pintu yang terbuka */

it('membuka laman permainan dan tab Main saat attempt sudah ditutup', function () {
    $data = siapkanJeda($this);

    $props = masukUji($this, $data['siswa'][0])
        ->get('/siswa/luang')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['jendela']['berakhir_pada'])->not->toBeNull();
    expect($props['peringkat']['pemain'])->toBe(0);

    $beranda = masukUji($this, $data['siswa'][0])
        ->get('/siswa')
        ->assertOk()
        ->viewData('page')['props'];

    expect($beranda['luang'])->toBeTrue();
});

/* ===================================================== Penjagaan skornya */

it('menyimpan hasil hitung server, bukan angka yang dikirim peramban', function () {
    $data = siapkanJeda($this);

    // 3.000 satuan dalam 60 detik = 50 per detik. Di bawah batas 160 yang
    // dijaga `PenjagaSkorLuar`, jadi ia diterima.
    $jawab = masukUji($this, $data['siswa'][0])
        ->postJson('/siswa/luang/skor', ['distance' => 3_000, 'duration_ms' => 60_000])
        ->assertOk()
        ->json();

    expect($jawab['diterima'])->toBeTrue();
    expect($jawab['score'])->toBe(3_000);
    expect($jawab['peringkat'])->toBe(1);

    $baris = LeisureGameScore::query()->firstOrFail();

    expect($baris->score)->toBe(3_000);
    expect($baris->rejected_reason)->toBeNull();
});

it('menolak kiriman yang mustahil, tetapi tetap menyimpannya sebagai baris', function () {
    $data = siapkanJeda($this);

    // 9.400 satuan dalam 12 detik = 783 per detik, jauh di atas batas.
    $jawab = masukUji($this, $data['siswa'][0])
        ->postJson('/siswa/luang/skor', ['distance' => 9_400, 'duration_ms' => 12_000])
        ->assertOk()
        ->json();

    expect($jawab['diterima'])->toBeFalse();

    // Barisnya ADA, dan itulah intinya: kiriman palsu yang dibuang diam-diam
    // tidak bisa ditunjukkan kepada siapa pun.
    $baris = LeisureGameScore::query()->firstOrFail();

    expect($baris->rejected_reason)->toBe(LeisureGameScore::TOLAK_TIDAK_SESUAI);

    // Tidak masuk papan peringkat, dan tidak dihitung sebagai pemain.
    expect($jawab['rekor'])->toBeNull();
    expect($jawab['peringkat'])->toBeNull();
});

it('menolak durasi yang terlalu pendek untuk jarak berapa pun', function () {
    $data = siapkanJeda($this);

    masukUji($this, $data['siswa'][0])
        ->postJson('/siswa/luang/skor', ['distance' => 10, 'duration_ms' => 400])
        ->assertOk();

    expect(LeisureGameScore::query()->firstOrFail()->rejected_reason)
        ->toBe(LeisureGameScore::TOLAK_TERLALU_CEPAT);
});

/* ================================================== Papan peringkatnya */

it('mengurutkan papan dari rekor tertinggi dan hanya sekali per siswa', function () {
    $data = siapkanJeda($this);

    $siswa = $data['siswa'][0];

    // Empat kiriman dari anak yang sama: yang tertinggi yang dihitung, dan
    // ia muncul SEKALI di papan.
    foreach ([100, 900, 400, 700] as $jarak) {
        masukUji($this, $siswa)
            ->postJson('/siswa/luang/skor', ['distance' => $jarak, 'duration_ms' => 30_000])
            ->assertOk();
    }

    $props = masukUji($this, $siswa)
        ->get('/siswa/luang/peringkat')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['papan'])->toHaveCount(1);
    expect($props['papan'][0]['score'])->toBe(900);
    expect($props['ringkas']['rekor'])->toBe(900);
    expect($props['ringkas']['peringkat'])->toBe(1);
});

it('menghitung peringkat dari seluruh pemain, bukan hanya sepuluh teratas', function () {
    $data = siapkanJeda($this);

    // Kelas yang dipakai papan peringkat: dibaca dari penempatan anak yang
    // diuji, supaya tiga belas baris buatan di bawah berada di PAPAN YANG
    // SAMA dengannya. Tanpa itu test ini akan lulus sambil membandingkan dua
    // papan yang berbeda.
    $kelasId = $data['orang']->pesertaEvent?->class_id;

    // Tiga belas anak lain dengan rekor lebih tinggi. Anak yang diuji berada
    // di peringkat keempat belas - di luar sepuluh teratas, dan justru di
    // situlah peringkat yang dihitung dari daftar terpotong akan berbohong.
    foreach (range(1, 13) as $i) {
        LeisureGameScore::create([
            'school_id' => $this->sekolah->id,
            'student_id' => User::factory()->siswa()->untukSekolah($this->sekolah)->create()->id,
            'class_id' => $kelasId,
            'exam_participant_id' => null,
            'distance' => 1_000 + $i * 100,
            'duration_ms' => 60_000,
            'score' => 1_000 + $i * 100,
            'played_at' => now(),
        ]);
    }

    masukUji($this, $data['siswa'][0])
        ->postJson('/siswa/luang/skor', ['distance' => 500, 'duration_ms' => 30_000])
        ->assertOk();

    $props = masukUji($this, $data['siswa'][0])
        ->get('/siswa/luang/peringkat')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['papan'])->toHaveCount(10);
    expect($props['ringkas']['peringkat'])->toBe(14);
    expect($props['ringkas']['pemain'])->toBe(14);

    // Baris sendiri TIDAK ada di sepuluh teratas, dan laman menerima datanya
    // untuk ditempel di bawah daftar.
    expect($props['saya']['score'])->toBe(500);
    expect($props['saya']['peringkat'])->toBe(14);
});
