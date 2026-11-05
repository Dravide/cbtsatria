<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
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
