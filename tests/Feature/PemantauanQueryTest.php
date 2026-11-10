<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamIncident;
use App\Domain\Attendance\Services\InsidenService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Papan monitoring Admin tidak boleh tumbuh linier terhadap jumlah ruang.
 *
 * =====================================================================
 * Yang diuji SELISIHNYA, bukan angka ajaibnya
 * =====================================================================
 * Test yang menulis `expect($kueri)->toBe(14)` akan merah setiap kali ada
 * satu `->with()` yang ditambahkan - dan yang dilakukannya bukan menangkap
 * N+1, melainkan memaksa orang memperbarui angkanya. Angka yang harus
 * diperbarui setiap kali kode disunting adalah angka yang pada akhirnya
 * diperbarui tanpa dibaca.
 *
 * Yang kekal adalah SIFATNYA: menambah ruang tidak menambah kueri. Karena
 * itu yang diukur adalah selisih antara dua laman dengan jumlah ruang
 * berbeda, dan selisihnya harus nol.
 *
 * =====================================================================
 * Kedua papan diuji, karena keduanya punya jalur yang berbeda
 * =====================================================================
 * `presensi()` menghitung rekap dari baris `attendance_records` yang sudah
 * dimuat; `monitoring()` menghitung dari `exam_attempts`. Keduanya
 * memanggil `jumlahTerbukaPerRuang()` yang sama, tetapi rekapnya tidak -
 * dan memperbaiki yang satu sambil meninggalkan yang lain adalah pekerjaan
 * yang setengah selesai, pada dua laman yang dibuka Admin berdampingan.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.pemantauan',
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

/** Jumlah kueri yang dijalankan satu permintaan GET. */
function hitungKueri(TestCase $test, string $url): int
{
    $kueri = 0;

    DB::listen(function () use (&$kueri) {
        $kueri++;
    });

    masukUji($test, $test->admin)->get($url)->assertOk();

    return $kueri;
}

it('tidak menambah kueri saat ruangnya ditambah - papan monitoring', function () {
    // Dua laman dengan event yang berbeda: satu berruang empat, satu berruang
    // delapan. Selisih kuerinya harus nol.
    $kecil = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['A-1', 'A-2', 'A-3', 'A-4'],
        2,
    );

    $besar = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['B-1', 'B-2', 'B-3', 'B-4', 'B-5', 'B-6', 'B-7', 'B-8'],
        2,
    );

    $kueriKecil = hitungKueri($this, "/admin/events/{$kecil['event']->id}/monitoring");

    // `forget()` wajib: tanpa itu `SchoolResolver` memakai hasil resolusi
    // permintaan sebelumnya, dan yang diukur bukan lagi dua laman yang sama.
    app(SchoolResolver::class)->forget();

    $kueriBesar = hitungKueri($this, "/admin/events/{$besar['event']->id}/monitoring");

    // Nol, bukan "kurang dari lima". N+1 atas jumlah ruang akan menghasilkan
    // selisih delapan di sini.
    expect($kueriBesar - $kueriKecil)->toBe(0);
});

it('tidak menambah kueri saat ruangnya ditambah - papan presensi', function () {
    // `siapkanPresensi()` TIDAK menyimpan kocokan benih, sehingga laman
    // presensi jatuh ke bentuk lamanya (per ruang-sesi, dari baris tersebar).
    // Bentuk per-ruang-event diuji selisihnya sendiri di
    // `DenahKursiTest`/`PresensiRuangEventTest` - dan keduanya harus nol.
    $kecil = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['A-1', 'A-2', 'A-3', 'A-4'],
        2,
    );

    $besar = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['B-1', 'B-2', 'B-3', 'B-4', 'B-5', 'B-6', 'B-7', 'B-8'],
        2,
    );

    $kueriKecil = hitungKueri($this, "/admin/events/{$kecil['event']->id}/presensi");

    app(SchoolResolver::class)->forget();

    $kueriBesar = hitungKueri($this, "/admin/events/{$besar['event']->id}/presensi");

    expect($kueriBesar - $kueriKecil)->toBe(0);
});

it('menghitung insiden terbuka banyak ruang dalam satu kueri', function () {
    $data = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['A-1', 'A-2', 'A-3'],
        2,
    );

    // `ruang` adalah koleksi ExamSessionRoom dari penyiapannya - dan itu
    // memang yang dibutuhkan `KonteksRuang::dari()` serta service insiden.
    // `ruangMaster()` mengembalikan `Room` (ruang FISIK), yang merupakan
    // model lain di domain lain.
    $ruang = $data['ruang'];

    $layanan = app(InsidenService::class);

    // Admin masuk sebagai pelakunya. `KonteksRuang::dari()` menolak ruang
    // tanpa tautan pintu dan tanpa pengguna terautentikasi - lihat `:105`
    // di sana - sehingga insiden tidak bisa dicatat oleh "tidak ada
    // siapa-siapa". Insiden yang tidak punya pelaku adalah insiden yang
    // tidak bisa ditanyakan siapa yang melaporkannya.
    masukUji($this, $this->admin);

    // Satu insiden terbuka di ruang pertama, satu di ruang ketiga, dan satu
    // insiden yang sudah DITANGANI di ruang kedua - yang terakhir itu harus
    // tidak terhitung.
    $terbuka = $layanan->catat(
        $ruang[0],
        KonteksRuang::dari($ruang[0]),
        'lain',
        'Kursi rusak di baris belakang.',
    );

    $layanan->catat(
        $ruang[1],
        KonteksRuang::dari($ruang[1]),
        'lain',
        'Sudah ditangani pengawas.',
    );

    $layanan->catat(
        $ruang[2],
        KonteksRuang::dari($ruang[2]),
        'lain',
        'Lampu mati di sudut kanan.',
    );

    // Insiden ruang kedua ditangani - dan angka terbukanya harus kembali nol
    // untuk ruang itu. `jumlahTerbukaPerRuang()` menyaring `status`, bukan
    // hanya menghitung barisnya.
    $ditangani = ExamIncident::query()
        ->where('exam_session_room_id', $ruang[1]->id)
        ->firstOrFail();

    $layanan->tangani($ditangani);

    // Koleksi ruangnya dimuat DI LUAR jendela penghitungan: yang diukur
    // adalah kueri yang dilakukan `jumlahTerbukaPerRuang()` sendiri, bukan
    // kueri yang menyiapkan argumennya.
    $semuaRuang = ExamSessionRoom::query()->whereIn('id', $ruang->pluck('id'))->get();

    $kueri = 0;
    DB::listen(function () use (&$kueri) {
        $kueri++;
    });

    $peta = $layanan->jumlahTerbukaPerRuang($semuaRuang);

    // SATU kueri, bukan tiga. Inilah inti perubahan Fase 9 di service ini.
    expect($kueri)->toBe(1)
        ->and($peta[$ruang[0]->id] ?? 0)->toBe(1)
        // Ruang kedua tidak muncul sama sekali - `?? 0` di pemanggil yang
        // menutupnya, dan itu memang bentuk yang diinginkan: ruang tanpa
        // insiden terbuka tidak perlu satu baris pun di hasilnya.
        ->and($peta[$ruang[1]->id] ?? 0)->toBe(0)
        ->and($peta[$ruang[2]->id] ?? 0)->toBe(1);

    // `terbuka` dipakai sekali supaya variabelnya tidak jadi kode mati bila
    // pemeriksaan di atas berubah.
    expect($terbuka->status)->toBe(ExamIncident::STATUS_TERBUKA);
});

it('mengembalikan koleksi kosong tanpa menyentuh basis data saat tidak ada ruang', function () {
    $kueri = 0;
    DB::listen(function () use (&$kueri) {
        $kueri++;
    });

    $peta = app(InsidenService::class)->jumlahTerbukaPerRuang([]);

    expect($peta)->toBeEmpty()
        ->and($kueri)->toBe(0);
});

/* =====================================================================
 | Daftar per peserta - ditambahkan 2026-11-09, dipindah ke laman sendiri
 | 2026-11-09
 |===================================================================== */

/**
 * Props halaman Inertia yang benar-benar dikirim ke peramban.
 *
 * Sama bentuknya dengan `propsPintu()` di PintuRuangTest, dan alasannya
 * sama: yang diuji di sini adalah APA YANG SAMPAI ke laman, bukan apa yang
 * dikembalikan satu method controller. Bentuk payload-lah yang menentukan
 * apa yang bisa dibaca Admin.
 *
 * @return array<string, mixed>
 */
function propsPapan(TestCase $test, User $admin, string $url): array
{
    $respon = masukUji($test, $admin)->get($url);

    $respon->assertOk();

    return $respon->viewData('page')['props'];
}

it('mengirim daftar per peserta, lengkap dengan nomor soal dari denyutnya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $peserta = $data['peserta'][0];
    $lain = $data['peserta'][1];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $lain);

    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/denyut", [
            'perangkat' => 'id-peramban-1',
            'nomor' => 3,
        ])
        ->assertOk();

    $props = propsPapan(
        $this,
        $this->admin,
        "/admin/events/{$data['event']->id}/monitoring/ruang/{$data['ruang'][0]->id}",
    );

    // Laman rincian adalah SATU ruang, dan bentuk payload-nya mengatakannya:
    // tidak ada `baris`, tidak ada angka lintas ruang. Yang ada adalah
    // ruangnya sendiri beserta seluruh anaknya.
    expect($props)->not->toHaveKey('baris')
        ->and($props['ruang']['kode'])->not->toBeNull()
        ->and($props['anak'])->toHaveCount(2);

    $anak = collect($props['anak'])->keyBy('peserta_id');

    // Berurut nama, dan itu urutan yang sama dengan papan pengawas: Admin
    // mencari satu anak yang dilaporkan bermasalah, dan ia tahu namanya -
    // bukan nomor kursinya.
    $nama = collect($props['anak'])->pluck('nama')->all();

    expect($nama)->toBe(collect($nama)->sort()->values()->all());

    // Nomor kursi ikut, dihitung `NomorKursi` - aturan yang sama dengan
    // papan pengawas, denah, dan tablo. Ia yang disebutkan Admin saat
    // menghubungi pengawas ruangnya, karena nomor peserta tidak tertulis di
    // mana pun yang bisa dilihat pengawas.
    expect(collect($props['anak'])->pluck('kursi')->filter()->sort()->values()->all())->toBe([1, 2]);

    // Anak yang berdenyut: nomornya ikut, beserta jam denyutnya. Jam itu
    // bukan hiasan - ia satu-satunya yang memberi tahu pembacanya bahwa
    // nomor itu bisa tertinggal sampai tiga puluh detik.
    expect($anak[$peserta->id]['nomor'])->toBe(3)
        ->and($anak[$peserta->id]['denyut'])->not->toBeNull()
        ->and($anak[$peserta->id]['status_label'])->toBe('Mengerjakan')
        ->and($anak[$peserta->id]['terhubung'])->toBeTrue();

    // Anak yang belum membuka gerbang: null, dan BUKAN nol maupun satu.
    // Pembedaan itulah yang membuat Admin tahu harus menanyakan siapa ke
    // pengawas ruangnya.
    expect($anak[$lain->id]['nomor'])->toBeNull()
        ->and($anak[$lain->id]['denyut'])->toBeNull()
        ->and($anak[$lain->id]['status_label'])->toBe('Belum mulai');
});

/* =====================================================================
 | Detail soal - ditambahkan 2026-11-09
 |===================================================================== */

it('mengirim soal mana yang sudah dan belum dikerjakan tiap anak', function () {
    // Kolom "Sampai nomor" menjawab apa yang SEDANG DILIHAT anak, dan itu
    // isyarat dari peramban. Yang diuji di sini adalah pertanyaan yang
    // berbeda: soal MANA yang jawabannya benar-benar tersimpan. Keduanya
    // bisa berbeda jauh pada anak yang sama, dan perbedaan itulah gunanya.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $peserta = $data['peserta'][0];
    $lain = $data['peserta'][1];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $lain);

    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    $butir = AttemptQuestion::query()
        ->where('exam_attempt_id', attemptBerjalan($peserta)->id)
        ->orderBy('display_order')
        ->get()
        ->keyBy('display_order');

    // Nomor 1 dan 3 dijawab; nomor 2 dibiarkan; nomor 4 ditandai ragu TANPA
    // dijawab - dan itu keadaan yang nyata: tombol Ragu bisa ditekan sebelum
    // anaknya menulis apa pun.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/butir/{$butir[1]->id}", ['isi' => ['opsi' => ['a']]])
        ->assertOk();

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/butir/{$butir[3]->id}", ['isi' => ['teks' => 'lima']])
        ->assertOk();

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/butir/{$butir[4]->id}/tandai", ['ditandai' => true])
        ->assertOk();

    $props = propsPapan(
        $this,
        $this->admin,
        "/admin/events/{$data['event']->id}/monitoring/ruang/{$data['ruang'][0]->id}",
    );

    $anak = collect($props['anak'])->keyBy('peserta_id');

    expect($anak[$peserta->id]['dikerjakan'])->toBe([1, 3])
        ->and($anak[$peserta->id]['belum'])->toBe([2, 4])
        ->and($anak[$peserta->id]['ditandai'])->toBe([4]);

    // Anak yang belum membuka ujiannya: NULL, dan itu BUKAN array kosong.
    // Array kosong terbaca "tidak ada satu pun soal yang belum dikerjakan",
    // dan itu kebalikan dari keadaannya.
    expect($anak[$lain->id]['dikerjakan'])->toBeNull()
        ->and($anak[$lain->id]['belum'])->toBeNull()
        ->and($anak[$lain->id]['ditandai'])->toBeNull()
        ->and($anak[$lain->id]['butir'])->toBeNull();

    // Peta nomor-yang-dilihat-anak ke soal ujiannya. Inilah satu-satunya
    // perantara antara rincian per anak dan statistik per SOAL: pada ujian
    // yang mengacak soal, nomor 1 anak ini menunjuk soal lain daripada
    // nomor 1 anak di kursi sebelahnya, sehingga menyambungkannya lewat
    // nomor akan menempelkan statistik yang salah.
    $butirAnak = collect($anak[$peserta->id]['butir']);

    expect($butirAnak)->toHaveCount(4)
        ->and($butirAnak->pluck('nomor')->all())->toBe([1, 2, 3, 4])
        ->and($butirAnak[0]['dijawab'])->toBeTrue()
        ->and($butirAnak[1]['dijawab'])->toBeFalse()
        ->and($butirAnak[3]['ditandai'])->toBeTrue();

    // `soal_id` harus menunjuk baris yang benar-benar ada di `soal` -
    // itulah yang dipakai laman untuk mencari statistiknya. Id yang tidak
    // ketemu akan membuat modalnya menampilkan "soal tidak lagi ada di
    // paket" untuk soal yang sebenarnya masih ada.
    $idSoal = collect($props['soal'])->pluck('id')->all();

    expect($butirAnak->pluck('soal_id')->unique()->sort()->values()->all())
        ->toBe(collect($idSoal)->sort()->values()->all());

    // Statistik per butir: satu baris per SOAL, dan jumlahnya empat - bukan
    // delapan. Pada ujian yang mengacak soal, satu nomor menunjuk soal yang
    // berbeda di tiap attempt; yang dijumlahkan di sini adalah soalnya.
    expect($props['soal'])->toHaveCount(4)
        ->and($props['angka_soal']['soal'])->toBe(4)
        ->and($props['angka_soal']['sudah'])->toBe(2)
        ->and($props['angka_soal']['belum'])->toBe(2)
        ->and($props['angka_soal']['ditandai'])->toBe(1)
        ->and($props['angka_soal']['selesai_semua'])->toBe(0)
        // Dua anak di ruang ini, satu sudah punya attempt.
        ->and($props['angka_soal']['belum_mulai'])->toBe(1)
        ->and($props['angka_soal']['peserta'])->toBe(1);

    $perSoal = collect($props['soal'])->keyBy('nomor');

    // Nomor 1 dijawab oleh satu-satunya anak yang membuka ujiannya, dan
    // BELUM ADA yang dinilai: `is_correct` diisi penilaian otomatis, yang
    // berjalan sesudah ujiannya berakhir. Nol di sini berarti "belum
    // dinilai", bukan "tidak ada yang benar" - dan itulah sebabnya ketiganya
    // null, bukan nol.
    expect($perSoal[1]['sudah'])->toBe(1)
        ->and($perSoal[1]['dinilai'])->toBe(0)
        ->and($perSoal[1]['benar'])->toBeNull()
        ->and($perSoal[1]['salah'])->toBeNull()
        ->and($perSoal[2]['sudah'])->toBe(0)
        ->and($perSoal[2]['belum'])->toBe(1);

    // Uraian tidak pernah dinilai mesin, jadi kolomnya tetap null walau
    // jawabannya sudah ada.
    $uraian = collect($props['soal'])->firstWhere('jenis', 'uraian');

    expect($uraian)->not->toBeNull()
        ->and($uraian['otomatis'])->toBeFalse()
        ->and($uraian['benar'])->toBeNull()
        ->and($uraian['salah'])->toBeNull();

    // Nama jenisnya ikut, supaya laman tidak perlu peta terjemahan sendiri -
    // peta kedua adalah tempat kedua yang bisa lupa diperbarui saat jenis
    // kesepuluh ditambahkan.
    expect($perSoal[1]['jenis_pendek'])->not->toBeNull()
        ->and($perSoal[1]['otomatis'])->toBeTrue();
});

it('tidak menambah kueri saat pesertanya ditambah - rincian ruang', function () {
    // Yang tumbuh di laman rincian bukan jumlah ruang, melainkan jumlah ANAK
    // di dalamnya - dan itu justru bentuk N+1 yang paling mudah terjadi:
    // menghitung progres per anak tampak wajar, dan hasilnya satu laman
    // ruang berkapasitas tiga puluh lima menjalankan tujuh puluh kueri.
    $kecil = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin, [], 2);
    $besar = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin, [], 6);

    $kueriKecil = hitungKueri(
        $this,
        "/admin/events/{$kecil['event']->id}/monitoring/ruang/{$kecil['ruang'][0]->id}",
    );

    app(SchoolResolver::class)->forget();

    $kueriBesar = hitungKueri(
        $this,
        "/admin/events/{$besar['event']->id}/monitoring/ruang/{$besar['ruang'][0]->id}",
    );

    expect($kueriBesar - $kueriKecil)->toBe(0);
});

it('membiarkan ruang tanpa peserta sebagai daftar kosong, bukan menolaknya', function () {
    // Ruang yang belum ditempati tetap harus bisa dibuka Admin yang
    // menyiapkan hari ujian - ia perlu melihat bahwa ruangnya kosong, bukan
    // menerima 404 yang membedakan "kosong" dari "tidak ada".
    //
    // Satu siswa untuk dua ruang, bukan nol siswa: `siapkanPresensi()` dengan
    // jumlah nol punya `range(1, 0)` yang justru menghasilkan DUA siswa -
    // jebakan yang sudah pernah menggigit di berkas lain. Satu siswa membuat
    // ruang kedua benar-benar kosong lewat jalur pengisian yang biasa.
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['A-1', 'A-2'], 1);

    $papan = propsPapan($this, $this->admin, "/admin/events/{$data['event']->id}/monitoring");

    $terisi = collect($papan['baris'])->firstWhere('peserta', 1);
    $kosong = collect($papan['baris'])->firstWhere('peserta', 0);

    // Papan lintas ruang TIDAK lagi membawa daftar anaknya: itu yang
    // dipindahkan ke laman rincian, dan payload-nya yang membuktikannya.
    expect($papan['baris'])->toHaveCount(2)
        ->and($papan['baris'][0])->not->toHaveKey('anak')
        ->and($papan['ruang_url'])->toBe("/admin/events/{$data['event']->id}/monitoring/ruang");

    $propsKosong = propsPapan(
        $this,
        $this->admin,
        "/admin/events/{$data['event']->id}/monitoring/ruang/{$kosong['ruang_id']}",
    );

    expect($propsKosong['anak'])->toBe([])
        ->and($propsKosong['angka']['peserta'])->toBe(0);

    $propsTerisi = propsPapan(
        $this,
        $this->admin,
        "/admin/events/{$data['event']->id}/monitoring/ruang/{$terisi['ruang_id']}",
    );

    expect($propsTerisi['anak'])->toHaveCount(1);
});

it('menolak ruang yang bukan milik event ini, meski sekolahnya sama', function () {
    // Binding implisit hanya menjamin ruangnya milik SEKOLAH ini. Tanpa
    // syarat kedua, Admin yang membuka event A mengubah satu angka di URL
    // dan membaca daftar anak event B - nama, NIS, dan nomor soal mereka
    // sekaligus. Yang diuji di sini adalah payload-nya tidak pernah sampai,
    // bukan sekadar statusnya.
    $a = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['A-1', 'A-2'], 1);
    $b = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['B-1', 'B-2'], 1);

    masukUji($this, $this->admin)
        ->get("/admin/events/{$a['event']->id}/monitoring/ruang/{$b['ruang'][0]->id}")
        ->assertNotFound();
});
