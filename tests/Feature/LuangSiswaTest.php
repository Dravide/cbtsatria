<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\Subject;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\LeisureGameScore;
use App\Domain\Attendance\Services\KuotaPermainan;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Support\PembatasLaju;

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

/* ------------------------------------------------- Pintu yang TERTUTUP */

it('menutup pintunya selama ujian masih dikerjakan', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    // Inilah SATU-SATUNYA larangan yang tersisa sejak 2026-11-15: attempt
    // yang belum ditutup. Bermain di tengah ujian adalah jalan keluar dari
    // layar yang justru dipantau sistem.
    masukUji($this, $data['siswa'][0])
        ->get('/siswa/luang')
        ->assertRedirect('/siswa');

    $props = masukUji($this, $data['siswa'][0])
        ->get('/siswa')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['luang'])->toBeFalse();
});

it('menutup pintunya saat attempt terkunci, karena ujiannya belum selesai', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    // Terkunci bukan selesai: anaknya masih punya laman terkunci yang bisa
    // ia buka kembali lewat pengawas.
    ExamAttempt::query()
        ->where('exam_participant_id', $peserta->id)
        ->update(['status' => ExamAttempt::STATUS_DIKUNCI]);

    masukUji($this, $data['siswa'][0])
        ->get('/siswa/luang')
        ->assertRedirect('/siswa');
});

/* -------------------------------------------------- Pintu yang TERBUKA */

it('membuka lamannya untuk siswa yang tidak punya ujian sama sekali', function () {
    // Perubahan aturan 2026-11-15: permainan tidak lagi mensyaratkan
    // penempatan ujian. Siswa yang kelasnya tidak ikut ujian - atau yang
    // penempatannya belum dibuat - tetap melihat ikonnya dan tetap bisa
    // bermain.
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create();

    masukUji($this, $siswa)
        ->get('/siswa/luang')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Siswa/Luang'));

    $props = masukUji($this, $siswa)
        ->get('/siswa')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['luang'])->toBeTrue();
});

it('membuka lamannya untuk siswa yang baru mengumpulkan ujian terakhirnya', function () {
    $data = siapkanJeda($this);

    // Seluruh sesi yang ada sudah lewat: tidak ada ujian berikutnya lagi.
    // Sebelum 2026-11-15 keadaan ini menutup pintunya, dan tidak ada satu pun
    // kalimat di layar yang menjelaskan mengapa.
    $data['sesi']->forceFill(['hard_end_at' => now()->subMinute()])->save();
    ExamSession::query()
        ->whereKeyNot($data['sesi']->id)
        ->update(['hard_end_at' => now()->subMinute()]);

    masukUji($this, $data['siswa'][0])
        ->get('/siswa/luang')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Siswa/Luang'));
});

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

it('menerima permainan singkat yang jujur - regresi kalibrasi 2026-11-15', function () {
    $data = siapkanJeda($this);

    // Angka ini DIAMBIL DARI DATA SUNGGUHAN, bukan dikarang: sebuah
    // permainan yang berhenti karena terbentur rintangan pertama
    // menghasilkan 221 satuan dalam 592 ms. Nilai ambang yang pertama
    // (3.000 ms dan 160 satuan/detik) menolaknya, dan pemainnya tidak
    // melakukan apa pun yang salah.
    $jawab = masukUji($this, $data['siswa'][0])
        ->postJson('/siswa/luang/skor', ['distance' => 221, 'duration_ms' => 592])
        ->assertOk()
        ->json();

    expect($jawab['diterima'])->toBeTrue();

    // Dan permainan yang lebih panjang tetapi masih wajar juga diterima.
    expect(LeisureGameScore::query()->firstOrFail()->rejected_reason)->toBeNull();
});

it('menyimpan hasil hitung server, bukan angka yang dikirim peramban', function () {
    $data = siapkanJeda($this);

    // 3.000 satuan dalam 60 detik = 50 per detik. Jauh di bawah batas 900
    // yang dijaga `PenjagaSkorLuar`, jadi ia diterima.
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

    // 9.400 satuan dalam 12 detik = 783 per detik... di bawah 900. Angka
    // yang benar-benar mustahil harus LEBIH jauh dari itu: 60.000 satuan
    // dalam 12 detik = 5.000 per detik.
    $jawab = masukUji($this, $data['siswa'][0])
        ->postJson('/siswa/luang/skor', ['distance' => 60_000, 'duration_ms' => 12_000])
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
        ->postJson('/siswa/luang/skor', ['distance' => 10, 'duration_ms' => 100])
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

/* ==================================================== Kuota hariannya */

it('mengirim sisa kuota harian bersama lamannya', function () {
    $data = siapkanJeda($this);

    $props = masukUji($this, $data['siswa'][0])
        ->get('/siswa/luang')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['kuota'])->toBe([
        'terpakai' => 0,
        'sisa' => KuotaPermainan::PER_HARI,
        'batas' => KuotaPermainan::PER_HARI,
        'boleh' => true,
    ]);
});

it('menerima kiriman sampai kuotanya habis, lalu menolaknya dengan 429', function () {
    $data = siapkanJeda($this);
    $siswa = $data['siswa'][0];

    // Sepuluh kiriman wajar - semuanya diterima, dan sisanya turun satu per
    // satu.
    //
    // Pembatas laju (6/menit) dibersihkan setiap putaran, dan itu BUKAN
    // kelonggaran yang menyembunyikan sesuatu: yang diuji di sini kuota
    // hariannya, bukan pembatas lajunya. Keduanya menjaga hal yang berbeda -
    // `throttle` menjaga tabel dari kiriman beruntun, kuota menjaga anak dari
    // bermain sepanjang hari. Membiarkan pembatas lajunya menyala berarti
    // tes ini sebenarnya menguji `throttle`, dan kuota hariannya tidak pernah
    // sampai ke permintaan kesebelas.
    foreach (range(1, KuotaPermainan::PER_HARI) as $i) {
        PembatasLaju::bersihkan(PembatasLaju::LUANG, PembatasLaju::kunciLuang($siswa->id));

        $jawab = masukUji($this, $siswa)
            ->postJson('/siswa/luang/skor', ['distance' => 300, 'duration_ms' => 30_000])
            ->assertOk()
            ->json();

        expect($jawab['diterima'])->toBeTrue()
            ->and($jawab['kuota']['sisa'])->toBe(KuotaPermainan::PER_HARI - $i);
    }

    // Yang kesebelas ditolak, dan TIDAK ditulis sebagai baris - kalau ia
    // ikut tersimpan, ia menambah hitungan dan anak itu terkunci selamanya
    // di hari yang sama.
    PembatasLaju::bersihkan(PembatasLaju::LUANG, PembatasLaju::kunciLuang($siswa->id));

    masukUji($this, $siswa)
        ->postJson('/siswa/luang/skor', ['distance' => 300, 'duration_ms' => 30_000])
        ->assertStatus(429);

    expect(LeisureGameScore::query()->count())->toBe(KuotaPermainan::PER_HARI);
});

it('menghitung kiriman yang DITOLAK sebagai bagian kuota', function () {
    $data = siapkanJeda($this);
    $siswa = $data['siswa'][0];

    // Yang dibatasi adalah berapa kali ia boleh MAIN, bukan berapa kali ia
    // boleh menang. Kalau kiriman yang ditolak tidak dihitung, anak yang
    // sepuluh kali terbentur seketika bisa main tanpa batas.
    masukUji($this, $siswa)
        ->postJson('/siswa/luang/skor', ['distance' => 9_400, 'duration_ms' => 300])
        ->assertOk()
        ->assertJsonPath('diterima', false);

    $props = masukUji($this, $siswa)
        ->get('/siswa/luang')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['kuota']['terpakai'])->toBe(1);
    expect($props['kuota']['sisa'])->toBe(KuotaPermainan::PER_HARI - 1);
});

it('membuka kembali kuotanya pada hari berikutnya', function () {
    $data = siapkanJeda($this);
    $siswa = $data['siswa'][0];

    foreach (range(1, KuotaPermainan::PER_HARI) as $i) {
        PembatasLaju::bersihkan(PembatasLaju::LUANG, PembatasLaju::kunciLuang($siswa->id));

        masukUji($this, $siswa)
            ->postJson('/siswa/luang/skor', ['distance' => 300, 'duration_ms' => 30_000])
            ->assertOk();
    }

    PembatasLaju::bersihkan(PembatasLaju::LUANG, PembatasLaju::kunciLuang($siswa->id));

    masukUji($this, $siswa)
        ->postJson('/siswa/luang/skor', ['distance' => 300, 'duration_ms' => 30_000])
        ->assertStatus(429);

    // Besok. Sesi yang dipakai `siapkanJeda` masih terbuka, jadi jedanya
    // tetap ada - dan kuotanya kembali penuh.
    $this->travel(1)->day();

    PembatasLaju::bersihkan(PembatasLaju::LUANG, PembatasLaju::kunciLuang($siswa->id));

    masukUji($this, $siswa)
        ->postJson('/siswa/luang/skor', ['distance' => 300, 'duration_ms' => 30_000])
        ->assertOk();
});
