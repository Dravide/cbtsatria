<?php

use App\Http\Controllers\Super\SuperAuditController;
use App\Http\Controllers\Super\SuperDashboardController;
use App\Http\Controllers\Super\SuperGalatController;
use App\Http\Controllers\Super\SuperImpersonasiController;
use App\Http\Controllers\Super\SuperPengumumanController;
use App\Http\Controllers\Super\SuperSekolahController;
use App\Http\Controllers\Super\SuperSistemController;
use Illuminate\Support\Facades\Route;

// Di luar middleware super_admin karena saat impersonasi pengguna aktif adalah Admin sekolah tujuan.
Route::post('/super/impersonasi/keluar', [SuperImpersonasiController::class, 'keluar'])
    ->middleware('auth')
    ->name('super.impersonasi.keluar');

Route::middleware(['auth', 'super_admin'])
    ->prefix('super')
    ->name('super.')
    ->group(function () {
        Route::get('/', [SuperDashboardController::class, 'index'])->name('dashboard');

        Route::get('/sekolah', [SuperSekolahController::class, 'index'])->name('sekolah.index');
        Route::post('/sekolah', [SuperSekolahController::class, 'store'])->name('sekolah.store');
        Route::get('/sekolah/{sekolah}', [SuperSekolahController::class, 'show'])
            ->whereNumber('sekolah')
            ->name('sekolah.show');
        Route::patch('/sekolah/{sekolah}', [SuperSekolahController::class, 'update'])
            ->whereNumber('sekolah')
            ->name('sekolah.update');
        Route::post('/sekolah/{sekolah}/admin', [SuperSekolahController::class, 'tambahAdmin'])
            ->whereNumber('sekolah')
            ->name('sekolah.admin.store');
        Route::post('/sekolah/{sekolah}/admin/{user}/reset-sandi', [SuperSekolahController::class, 'resetSandiAdmin'])
            ->whereNumber(['sekolah', 'user'])
            ->name('sekolah.admin.reset');
        Route::post('/sekolah/{sekolah}/impersonasi', [SuperImpersonasiController::class, 'masuk'])
            ->whereNumber('sekolah')
            ->name('sekolah.impersonasi');

        Route::get('/sistem', [SuperSistemController::class, 'index'])->name('sistem.index');
        Route::post('/sistem/retry-jobs', [SuperSistemController::class, 'retryJobs'])->name('sistem.retry');
        Route::post('/sistem/pangkas-ekspor', [SuperSistemController::class, 'pangkasEkspor'])->name('sistem.pangkas');

        Route::get('/pengumuman', [SuperPengumumanController::class, 'index'])->name('pengumuman.index');
        Route::post('/pengumuman', [SuperPengumumanController::class, 'store'])->name('pengumuman.store');
        Route::delete('/pengumuman/{pengumuman}', [SuperPengumumanController::class, 'destroy'])
            ->whereNumber('pengumuman')
            ->name('pengumuman.destroy');

        Route::get('/audit', [SuperAuditController::class, 'index'])->name('audit.index');

        Route::get('/galat', [SuperGalatController::class, 'index'])->name('galat.index');
        Route::post('/galat/pangkas', [SuperGalatController::class, 'prune'])->name('galat.prune');
        Route::get('/galat/{galat}', [SuperGalatController::class, 'show'])
            ->whereNumber('galat')
            ->name('galat.show');
        Route::post('/galat/{galat}/selesai', [SuperGalatController::class, 'resolve'])
            ->whereNumber('galat')
            ->name('galat.resolve');
    });
