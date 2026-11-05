<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Grading\Jobs\BuatEksporLaporan;
use App\Domain\Grading\Models\ReportExport;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Ekspor laporan SEBAGAI JOB - celah "nol test antrean" Fase 9.
 *
 * =====================================================================
 * Mengapa berkas tersendiri, padahal ekspornya sudah diuji
 * =====================================================================
 * `PenilaianHasilTest` menguji hasil akhir ekspor: berkasnya ada, isinya
 * benar, gagalnya tercatat. Semuanya lewat jalur yang berakhir di ANTREAN
 * `sync` - sehingga di seluruh berkas itu, `BuatEksporLaporan` yang diantre
 * dan `BuatEksporLaporan` yang dijalankan langsung adalah hal yang sama.
 *
 * Padahal keduanya bisa berbeda, dan yang membedakannya adalah justru
 * janji plate 22: "berkas besar: disiapkan di latar belakang". Selama
 * `QUEUE_CONNECTION=sync`, aplikasi yang benar-benar mengerjakan ekspornya
 * DI DALAM permintaan HTTP akan melewati setiap test Fase 8 tanpa satu pun
 * kegagalan - dan Admin yang menunggu laporan 1.284 baris akan menatap
 * tombol berputar, persis yang hendak dicegah.
 *
 * `Queue::fake()` yang membuat perbedaan itu terlihat: ia MENIMPA `sync`,
 * sehingga pekerjaan yang seharusnya dilempar tidak akan dikerjakan. Karena
 * itu test di bawah tidak hanya memeriksa "job terkirim", melainkan juga
 * bahwa berkasnya BELUM ada dan barisnya masih `menunggu` - dan dua
 * pemeriksaan terakhir itulah yang gagal bila pekerjaannya dikerjakan
 * langsung.
 *
 * =====================================================================
 * Kenapa `assertPushed` dengan pemeriksaan id
 * =====================================================================
 * `Queue::assertPushed(BuatEksporLaporan::class)` akan lulus juga bila job
 * yang diantre menunjuk baris yang salah - dan job dengan id yang salah
 * adalah job yang tidak menemukan apa pun, berhenti diam-diam, dan
 * meninggalkan ekspor berstatus `menunggu` selamanya. Karena itu id-nya
 * diperiksa, bukan hanya kelasnya.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.ekspor',
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

it('melempar pekerjaan ekspor ke antrean, bukan mengerjakannya dalam permintaan', function () {
    Storage::fake('local');
    Queue::fake();

    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $event = $data['event']->refresh();

    masukUji($this, $this->admin)
        ->withSession(['event_aktif' => $event->id])
        ->post('/admin/laporan', ['jenis' => 'nilai', 'format' => 'csv'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $ekspor = ReportExport::query()->orderByDesc('id')->firstOrFail();

    // Job-nya benar-benar menunjuk baris ini.
    Queue::assertPushed(
        BuatEksporLaporan::class,
        fn (BuatEksporLaporan $job) => $job->eksporId === $ekspor->id,
    );

    // Dan pekerjaannya BELUM dikerjakan. Ketiga baris di bawah adalah inti
    // berkas ini: pada aplikasi yang menyiapkan berkasnya langsung di dalam
    // `mintaEkspor()`, statusnya sudah `selesai` dan berkasnya sudah ada -
    // sementara test Fase 8 yang berjalan pada antrean `sync` tidak akan
    // pernah bisa membedakannya.
    expect($ekspor->status)->toBe(ReportExport::STATUS_MENUNGGU)
        ->and($ekspor->path)->toBeNull()
        ->and($ekspor->finished_at)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('menyimpan barisnya sebelum job dilempar - job yang berjalan lebih dulu menemukannya', function () {
    Storage::fake('local');
    Queue::fake();

    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $event = $data['event']->refresh();

    // Inilah yang diuji dengan cara yang tidak bisa dilakukan oleh
    // `assertPushed` sendirian: pada saat jobnya DILEMPAR, barisnya sudah
    // ada. Kalau `dispatch()` dipindahkan ke atas `save()` - atau bloknya
    // kelak dibungkus `DB::transaction()` tanpa `->afterCommit()` - worker
    // bisa membaca lebih dulu daripada commit, dan `handle()` akan
    // mengembalikan lebih awal karena barisnya belum ada.
    //
    // Yang memeriksanya adalah closure job pertama yang dijalankan SESUDAH
    // permintaannya selesai: ia membaca basis data, bukan menghafal id.
    masukUji($this, $this->admin)
        ->withSession(['event_aktif' => $event->id])
        ->post('/admin/laporan', ['jenis' => 'nilai', 'format' => 'csv'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Queue::assertPushed(BuatEksporLaporan::class, function (BuatEksporLaporan $job) {
        return ReportExport::query()->withoutGlobalScopes()->find($job->eksporId) !== null;
    });
});

it('mengubah barisnya menjadi selesai begitu jobnya benar-benar dijalankan', function () {
    Storage::fake('local');

    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $event = $data['event']->refresh();

    // Tanpa `Queue::fake()`: antreannya `sync`, jadi jobnya berjalan di sini.
    masukUji($this, $this->admin)
        ->withSession(['event_aktif' => $event->id])
        ->post('/admin/laporan', ['jenis' => 'nilai', 'format' => 'csv'])
        ->assertRedirect();

    $ekspor = ReportExport::query()->orderByDesc('id')->firstOrFail();

    // Pasangan dari test pertama: pekerjaan yang sama, dikerjakan - dan
    // karena itu hasilnya harus berbeda. Dua test ini bersama-sama
    // membuktikan bahwa pembedaannya memang karena antreannya, bukan karena
    // ekspornya kebetulan gagal.
    expect($ekspor->status)->toBe(ReportExport::STATUS_SELESAI)
        ->and($ekspor->path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($ekspor->path))->toBeTrue();
});

it('tidak mengerjakan apa pun bila jobnya menemukan ekspor yang sudah selesai', function () {
    Storage::fake('local');

    $ekspor = new ReportExport;
    $ekspor->forceFill([
        'school_id' => $this->sekolah->id,
        'requested_by' => $this->admin->id,
        'jenis' => 'nilai',
        'format' => 'csv',
        'parameter_json' => ['event_id' => 999999],
        'status' => ReportExport::STATUS_SELESAI,
        'nama_berkas' => 'sudah-ada-nilai.csv',
        'path' => 'laporan/sudah-ada-nilai.csv',
        'created_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ])->save();

    Storage::disk('local')->put('laporan/sudah-ada-nilai.csv', 'isi lama');

    // Job yang sama bisa terkirim dua kali - antrean tidak menjanjikan
    // sebaliknya, dan `tries = 1` hanya membatasi percobaan ULANG, bukan
    // pengiriman ganda. Pemeriksaan status di awal `handle()` membuat
    // pengiriman kedua tidak menimpa berkas yang sudah diunduh Admin.
    (new BuatEksporLaporan($ekspor->id))->handle();

    expect(Storage::disk('local')->get('laporan/sudah-ada-nilai.csv'))->toBe('isi lama')
        ->and($ekspor->fresh()->nama_berkas)->toBe('sudah-ada-nilai.csv');
});

it('berhenti diam bila barisnya sudah tidak ada, bukan melempar', function () {
    Storage::fake('local');

    // Baris yang dihapus pemangkas ekspor (`laporan:pangkas-ekspor`) masih
    // bisa punya job yang tertinggal di antrean. Job yang melempar akan
    // masuk `failed_jobs` dan memanggil `failed()` - yang menulis ke baris
    // yang sudah tidak ada, sehingga galatnya berlipat tanpa satu pun
    // gunanya.
    (new BuatEksporLaporan(999999))->handle();

    expect(true)->toBeTrue();
});
