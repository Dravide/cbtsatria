<?php

use App\Domain\Attendance\Http\Controllers\BeritaAcaraController;
use App\Domain\Attendance\Http\Controllers\BerkasLaporanController;
use App\Domain\Attendance\Http\Controllers\MonitoringController;
use App\Domain\Attendance\Http\Controllers\PelanggaranController;
use App\Domain\Attendance\Http\Controllers\PresensiController;
use App\Domain\Attendance\Http\Controllers\RuangTugasController;
use App\Domain\Attendance\Http\Controllers\TokenRuangController;
use Illuminate\Support\Facades\Route;

// Memakai middleware pengawas (bukan auth) karena pemegang tautan pintu ruang tidak punya akun.
Route::middleware('pengawas')->prefix('pengawas')->name('pengawas.')->group(function () {
    Route::get('/', [RuangTugasController::class, 'index'])->name('dashboard');

    Route::get('/ruang/{ruang}/presensi', [PresensiController::class, 'index'])->name('presensi');

    // Harus di atas presensi/{peserta} agar "pengakuan" tidak di-bind sebagai {peserta}.
    Route::get('/ruang/{ruang}/presensi/pengakuan', [PresensiController::class, 'pengakuanJson'])->name('presensi.pengakuan');

    Route::get('/ruang/{ruang}/presensi/{peserta}', [PresensiController::class, 'satu'])->name('presensi.satu');
    Route::post('/ruang/{ruang}/presensi/{peserta}/pindah-ruang', [PresensiController::class, 'pindahRuang'])->name('presensi.pindah-ruang');
    Route::post('/ruang/{ruang}/presensi/verifikasi', [PresensiController::class, 'verifikasi'])->name('presensi.verifikasi');
    Route::post('/ruang/{ruang}/presensi/massal', [PresensiController::class, 'massal'])->name('presensi.massal');

    Route::post('/ruang/{ruang}/presensi/pindai', [PresensiController::class, 'rotasiPindai'])->name('presensi.pindai');
    Route::post('/ruang/{ruang}/presensi/konfirmasi', [PresensiController::class, 'konfirmasi'])->name('presensi.konfirmasi');
    Route::get('/ruang/{ruang}/koreksi', [PresensiController::class, 'koreksi'])->name('koreksi');
    Route::post('/ruang/{ruang}/koreksi/{peserta}', [PresensiController::class, 'simpanKoreksi'])->name('koreksi.simpan');
    Route::post('/ruang/{ruang}/kembalikan/{peserta}', [PresensiController::class, 'kembalikan'])->name('kembalikan');

    Route::get('/ruang/{ruang}/token', [TokenRuangController::class, 'index'])->name('token');
    Route::post('/ruang/{ruang}/token/baca', [TokenRuangController::class, 'baca'])->name('token.baca');

    Route::get('/ruang/{ruang}/monitoring', [MonitoringController::class, 'index'])->name('monitoring');
    Route::post('/ruang/{ruang}/insiden', [MonitoringController::class, 'catatInsiden'])->name('insiden');
    Route::post('/ruang/{ruang}/sinyal', [MonitoringController::class, 'kirimSinyal'])->name('sinyal');
    Route::post('/ruang/{ruang}/qr-darurat', [MonitoringController::class, 'terimaQrDarurat'])->name('qr-darurat');
    Route::get('/ruang/{ruang}/siswa/{peserta}', [PelanggaranController::class, 'index'])->name('siswa');
    Route::post('/ruang/{ruang}/siswa/{peserta}/buka', [PelanggaranController::class, 'buka'])->name('siswa.buka');

    Route::get('/ruang/{ruang}/berita-acara', [BeritaAcaraController::class, 'index'])->name('berita-acara');
    Route::post('/ruang/{ruang}/berita-acara/foto', [BeritaAcaraController::class, 'unggahFoto'])->name('berita-acara.foto');
    Route::post('/ruang/{ruang}/berita-acara/ttd', [BeritaAcaraController::class, 'tandaTangan'])->name('berita-acara.ttd');
    Route::post('/ruang/{ruang}/berita-acara/catatan', [BeritaAcaraController::class, 'simpanCatatan'])->name('berita-acara.catatan');
    Route::post('/ruang/{ruang}/berita-acara/rapi-ai', [BeritaAcaraController::class, 'rapiAi'])->name('berita-acara.rapi-ai');
    Route::post('/ruang/{ruang}/berita-acara/kirim', [BeritaAcaraController::class, 'kirim'])->name('berita-acara.kirim');
    Route::post('/ruang/{ruang}/berita-acara/buka', [BeritaAcaraController::class, 'bukaKembali'])->name('berita-acara.buka');
});

// Berkas berita acara di disk privat (tanpa symlink publik); otorisasi ruang diperiksa di controller.
Route::middleware('pengawas')->group(function () {
    Route::get('/laporan/foto/{berkas}', [BerkasLaporanController::class, 'foto'])->name('laporan.foto');
    Route::get('/laporan/ttd/{ttd}', [BerkasLaporanController::class, 'ttd'])->name('laporan.ttd');
});
