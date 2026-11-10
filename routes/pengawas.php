<?php

use App\Domain\Attendance\Http\Controllers\BeritaAcaraController;
use App\Domain\Attendance\Http\Controllers\BerkasLaporanController;
use App\Domain\Attendance\Http\Controllers\MonitoringController;
use App\Domain\Attendance\Http\Controllers\PelanggaranController;
use App\Domain\Attendance\Http\Controllers\PresensiController;
use App\Domain\Attendance\Http\Controllers\RuangTugasController;
use App\Domain\Attendance\Http\Controllers\TokenRuangController;
use Illuminate\Support\Facades\Route;

/*
|==========================================================================
| Portal pengawas - middleware `pengawas`, BUKAN `auth`
|==========================================================================
| Grup `auth` tidak bisa dipakai: pemegang tautan ruang tidak punya akun.
| AksesPengawas meloloskan permintaan bila SALAH SATU benar - ada kunci
| sesi `pengawas_ruang` yang masih sah dan mencakup {ruang} di rute, atau
| ada User berperan Pengawas dengan penugasan pada {ruang} itu, atau Admin.
|
| Rutenya memakai `/pengawas/...`, bukan `/supervisor/...` seperti PRD §20.1:
| seluruh laman aplikasi ini berbahasa Indonesia, dan
| `Role::Supervisor->dashboardPath()` sudah menunjuk /pengawas sejak Fase 1.
| Perbedaannya dicatat di sini supaya tidak menjadi pertanyaan kelak.
|
| Rute ruangnya selalu memuat {ruang}. Itu bukan kerapian: pengawas memegang
| lebih dari satu ruang, dan URL yang tidak menyebut ruang mana akan membuat
| tautan yang dikirim lewat pesan membuka ruang yang salah.
|
| ---------------------------------------------------------------------
| Portal ini memakai KERANGKA PONSEL, bukan kerangka admin
| ---------------------------------------------------------------------
| Pengawas berdiri di depan pintu ruang sambil memegang ponselnya, bukan
| duduk di depan laptop. Lihat catatan `KerangkaPonsel` dan wireframe
| plate 12-18: seluruh tata letaknya satu kolom.
*/

Route::middleware('pengawas')->prefix('pengawas')->name('pengawas.')->group(function () {
    Route::get('/', [RuangTugasController::class, 'index'])->name('dashboard');

    // Plate 12-13, 17
    Route::get('/ruang/{ruang}/presensi', [PresensiController::class, 'index'])->name('presensi');
    Route::get('/ruang/{ruang}/presensi/{peserta}', [PresensiController::class, 'satu'])->name('presensi.satu');
    Route::post('/ruang/{ruang}/presensi/verifikasi', [PresensiController::class, 'verifikasi'])->name('presensi.verifikasi');
    Route::post('/ruang/{ruang}/presensi/massal', [PresensiController::class, 'massal'])->name('presensi.massal');
    Route::get('/ruang/{ruang}/koreksi', [PresensiController::class, 'koreksi'])->name('koreksi');
    Route::post('/ruang/{ruang}/koreksi/{peserta}', [PresensiController::class, 'simpanKoreksi'])->name('koreksi.simpan');
    Route::post('/ruang/{ruang}/kembalikan/{peserta}', [PresensiController::class, 'kembalikan'])->name('kembalikan');

    // Plate 14
    Route::get('/ruang/{ruang}/token', [TokenRuangController::class, 'index'])->name('token');
    Route::post('/ruang/{ruang}/token/baca', [TokenRuangController::class, 'baca'])->name('token.baca');

    // Plate 15-16
    Route::get('/ruang/{ruang}/monitoring', [MonitoringController::class, 'index'])->name('monitoring');
    Route::post('/ruang/{ruang}/insiden', [MonitoringController::class, 'catatInsiden'])->name('insiden');
    Route::get('/ruang/{ruang}/siswa/{peserta}', [PelanggaranController::class, 'index'])->name('siswa');
    Route::post('/ruang/{ruang}/siswa/{peserta}/buka', [PelanggaranController::class, 'buka'])->name('siswa.buka');

    // Plate 18
    Route::get('/ruang/{ruang}/berita-acara', [BeritaAcaraController::class, 'index'])->name('berita-acara');
    Route::post('/ruang/{ruang}/berita-acara/foto', [BeritaAcaraController::class, 'unggahFoto'])->name('berita-acara.foto');
    Route::post('/ruang/{ruang}/berita-acara/ttd', [BeritaAcaraController::class, 'tandaTangan'])->name('berita-acara.ttd');
    Route::post('/ruang/{ruang}/berita-acara/catatan', [BeritaAcaraController::class, 'simpanCatatan'])->name('berita-acara.catatan');
    Route::post('/ruang/{ruang}/berita-acara/kirim', [BeritaAcaraController::class, 'kirim'])->name('berita-acara.kirim');
    Route::post('/ruang/{ruang}/berita-acara/buka', [BeritaAcaraController::class, 'bukaKembali'])->name('berita-acara.buka');
});

/*
|--------------------------------------------------------------------------
| Berkas berita acara - disk privat, dibaca lewat rute berwenang
|--------------------------------------------------------------------------
| TIDAK ada symlink `public/storage`, dan itu keputusan yang dikunci: foto
| ruang ujian adalah bukti, bukan aset publik. Satu-satunya jalan keluarnya
| adalah dua rute ini, dan keduanya memanggil KonteksRuang lagi - sehingga
| mencabut tautan ruang mencabut fotonya juga, tanpa kode tambahan.
|
| DUA rute, bukan satu: `session_report_files.id` dan
| `session_report_signatures.id` adalah dua ruang id yang berbeda, dan satu
| rute berarti id 7 harus ditebak milik tabel mana. Yang membedakan keduanya
| di sini adalah MODEL yang di-bind, bukan angka di dalam string.
|
| Alamatnya di luar awalan `/pengawas` karena ia bukan halaman, melainkan
| BERKAS - dan `{berkas}`/`{ttd}` di bawah `/pengawas/` akan berbagi ruang
| nama dengan rute ruang di atasnya.
*/
Route::middleware('pengawas')->group(function () {
    Route::get('/laporan/foto/{berkas}', [BerkasLaporanController::class, 'foto'])->name('laporan.foto');
    Route::get('/laporan/ttd/{ttd}', [BerkasLaporanController::class, 'ttd'])->name('laporan.ttd');
});
