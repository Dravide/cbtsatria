<?php

use App\Domain\Attendance\Http\Controllers\PapanPublikController;
use App\Domain\Attendance\Http\Controllers\PintuRuangController;
use App\Domain\Attendance\Http\Controllers\Siswa\HadirSiswaController;
use App\Domain\Identity\Http\Controllers\Auth\LoginController;
use App\Domain\Identity\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\IdentitasController;
use Illuminate\Support\Facades\Route;

/*
|==========================================================================
| Rute bersama - pintu masuk, pintu ruang, dan tujuan setelah login
|==========================================================================
| Berkas ini memuat rute yang TIDAK dimiliki satu peran mana pun, lalu
| memuat berkas per peran dari `routes/`: admin, guru, siswa, pengawas.
|
| ---------------------------------------------------------------------
| Mengapa dipecah per peran, dan mengapa dengan `require`
| ---------------------------------------------------------------------
| Sebelum 2026-11-09 seluruhnya ada di sini, 800 baris, dan pertanyaan
| "alamat apa saja yang dibuka Admin" hanya bisa dijawab dengan membaca
| seluruhnya. Pemecahannya mengikuti satu garis yang sudah ada di aplikasi
| ini - `Role` - sehingga berkasnya menjawab pertanyaan itu dengan namanya
| sendiri.
|
| Yang TIDAK dipecah adalah middleware dan awalan: tiap berkas peran
| mendaftarkan grupnya sendiri (`auth`/`guru`/`siswa`/`pengawas`), bukan
| menerimanya dari sini. Grup yang dibuka di satu berkas lalu ditutup di
| berkas lain adalah cara paling mudah membuat rute yang tidak sengaja
| terbuka bagi peran yang salah - dan kegagalan itu tidak terlihat sampai
| ada orang yang menemukannya.
|
| Karena itu juga tidak ada `require` di dalam grup terbuka mana pun.
|
| `php artisan route:list` tetap membacanya sebagai satu daftar: pemecahan
| berkas tidak mengubah alamat mana pun.
*/

/*
|--------------------------------------------------------------------------
| Manifest aplikasi
|--------------------------------------------------------------------------
| Alamatnya SAMA dengan berkas statis yang dulu ada di `public/`, sehingga
| tag `<link rel="manifest">` tidak berubah dan peramban yang sudah memasang
| versi lama tetap menemukan berkasnya.
|
| Tanpa `auth`, dan itu wajib: manifest dibaca peramban SEBELUM siapa pun
| masuk, dan permintaan yang dialihkan ke `/login` diterima sebagai manifest
| yang rusak - lalu ikon layar beranda tidak pernah terpasang.
*/
Route::get('/site.webmanifest', [IdentitasController::class, 'manifest'])
    ->name('manifest');

/*
|--------------------------------------------------------------------------
| Pintu masuk
|--------------------------------------------------------------------------
| Satu form login untuk keempat peran. Tidak ada alamat login per peran, dan
| tidak ada kode sekolah atau NPSN di layar ini - username membawa identitas
| akun, dan akun membawa school_id. Rute mengikuti wireframe plate 01-02.
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    Route::get('/lupa-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Pintu ruang - SENGAJA di luar grup auth
|--------------------------------------------------------------------------
| Yang membukanya belum tentu punya akun, dan itu alasan keberadaannya:
| pengawas berdiri di depan pintu kelas, memindai kartu yang ditempel Admin
| di daun pintu, lalu mengetik enam angka.
|
| Dua rute ini juga TIDAK diberi default `tenant`, sehingga EnsureTenantContext
| tidak menolaknya dengan 404 - saat permintaan datang memang belum ada
| konteks sekolah. Konteksnya baru ditetapkan AksesPengawas, dari baris
| room_entry_links yang di-resolve slug ini.
|
| Slug di-resolve dengan `tanpaTenant()` di dalam controller, dan itu WAJIB -
| lihat catatan di PintuRuangController.
*/
Route::get('/p/{slug}', [PintuRuangController::class, 'tampilkan'])->name('pintu.ruang');

// `throttle:pintu-ruang` - PRD §19.2. Hanya POST-nya: yang ditebak adalah
// KODENYA, dan GET halaman pintu tidak menerima kode apa pun. Limiter-nya
// didefinisikan di AppServiceProvider dengan `->response()` yang mengembalikan
// flash `galat`, bukan halaman 429 - pengawas di depan pintu kelas tidak boleh
// menerima halaman galat telanjang. Lihat App\Support\PembatasLaju.
Route::post('/p/{slug}', [PintuRuangController::class, 'buka'])
    ->middleware('throttle:pintu-ruang')
    ->name('pintu.ruang.buka');

// Menyerahkan kembali hibah sesi. Di luar grup auth dengan alasan yang sama:
// yang memegangnya tidak punya akun untuk keluar darinya.
Route::post('/p/{slug}/keluar', [PintuRuangController::class, 'keluar'])->name('pintu.ruang.keluar');

/*
|--------------------------------------------------------------------------
| Papan publik pengawas - `/papan/{slug}`
|--------------------------------------------------------------------------
| Ditambahkan 2026-11-14. Menjawab "pengawas mana ada di ruang mana" untuk
| orang yang TIDAK punya akun: wali kelas, panitia, orang tua, pengawas
| ruang lain. Sampai sekarang pertanyaan itu hanya bisa dijawab dari konsol
| Admin setelah login.
|
| Di luar `auth`, dan isinya dibatasi di `PapanPengawas`: nama ruang, nama
| pengawas, jam. Tidak nama siswa, tidak nomor kursi, tidak token - halaman
| ini tayang untuk seluruh internet, dan tautannya ditempel di lorong.
|
| Slugnya milik SEKOLAH (`schools.papan_slug`) dan nullable. Sekolah yang
| tidak pernah menyalakan papannya tidak punya alamat sama sekali - rute
| yang tidak pernah lahir tidak bisa ditebak, dan itu lebih kuat daripada
| 403 yang justru membuktikan alamatnya ada.
|
| TIDAK diberi default `tenant`: pada permintaan ini belum ada pengguna,
| sehingga `SchoolResolver` mengembalikan null. Sekolahnya ditentukan oleh
| slug, di controller.
*/
Route::get('/papan/{slug}', [PapanPublikController::class, 'tampilkan'])->name('papan.publik');
Route::get('/papan/{slug}/pengawas/{supervisor}', [PapanPublikController::class, 'pengawas'])
    ->whereNumber('supervisor')
    ->name('papan.pengawas');

/*
|--------------------------------------------------------------------------
| Pindai presensi siswa - `/hadir/{kode}`
|--------------------------------------------------------------------------
| Alamat ini TERTANAM DI DALAM QR yang tampil di laman presensi pengawas
| (wireframe-presensi-qr.html plate A1-B6), dan itulah sebabnya ia tidak
| berada di dalam awalan `/siswa`:
|
|   - QR yang sudah dipindai kamera tidak bisa memilih awalan. Alamat yang
|     lebih panjang berarti QR yang lebih padat, dan itu justru merugikan
|     ponsel murah yang memindainya.
|   - Ia juga dimuat ulang berkali-kali oleh anak yang menunggu pengawas
|     (plate B5), sehingga alamat yang pendek lebih mudah dibaca ulang di
|     bilah alamat.
|
| Yang menjaganya tetap `auth`, bukan middleware `siswa`: akun itulah yang
| menentukan SIAPA yang dicatat, dan tamu dialihkan ke login lalu
| DIKEMBALIKAN ke sini lewat `redirect()->intended()`. Peran siswa diperiksa
| di controller - lihat HadirSiswaController.
|
| `throttle:pindai-presensi` hanya pada POST. Yang dibatasi adalah penulisan
| pengakuan; GET-nya justru diminta diulang oleh laman tunggu.
*/
Route::middleware('auth')->group(function () {
    Route::get('/hadir/{kode}', [HadirSiswaController::class, 'tampilkan'])->name('siswa.hadir');

    Route::post('/hadir/{kode}', [HadirSiswaController::class, 'akui'])
        ->middleware('throttle:pindai-presensi')
        ->name('siswa.hadir.akui');
});

/*
|--------------------------------------------------------------------------
| Tujuan setelah login
|--------------------------------------------------------------------------
| Satu rute dashboard yang bercabang pada peran, bukan empat rute yang
| dipilih di UI. Wireframe plate 01: "Setelah valid -> redirect sesuai role,
| bukan dipilih di UI."
*/
Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return redirect()->route('dashboard');
    });

    Route::get('/dashboard', function () {
        return redirect()->to(auth()->user()->role->dashboardPath());
    })->name('dashboard');
});

/*
|--------------------------------------------------------------------------
| Rute per peran
|--------------------------------------------------------------------------
| Urutannya mengikuti `Role`: admin, guru, siswa, pengawas. Masing-masing
| berkas mendaftarkan grup middleware-nya sendiri - lihat catatan di atas
| tentang mengapa grupnya tidak dibuka di sini.
*/
require __DIR__.'/admin.php';
require __DIR__.'/guru.php';
require __DIR__.'/siswa.php';
require __DIR__.'/pengawas.php';
