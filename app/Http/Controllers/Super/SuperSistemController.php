<?php

namespace App\Http\Controllers\Super;

use App\Models\PlatformActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SuperSistemController
{
    public function index(): Response
    {
        $dbOk = true;
        $dbLatensiMs = null;

        try {
            $mulai = microtime(true);
            DB::select('SELECT 1');
            $dbLatensiMs = (int) round((microtime(true) - $mulai) * 1000);
        } catch (Throwable) {
            $dbOk = false;
        }

        $queuedJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(10)
                ->get(['id', 'uuid', 'queue', 'failed_at'])
                ->map(fn ($j) => [
                    'id' => (int) $j->id,
                    'uuid' => $j->uuid,
                    'queue' => $j->queue,
                    'failed_at' => (string) $j->failed_at,
                ])
                ->all()
            : [];

        $storageLinkAda = file_exists(public_path('storage'));
        $hotAda = file_exists(public_path('hot'));

        $diskBebasGb = null;
        $diskTotalGb = null;
        $bebas = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if ($bebas !== false && $total !== false && $total > 0) {
            $diskBebasGb = round($bebas / 1073741824, 1);
            $diskTotalGb = round($total / 1073741824, 1);
        }

        return Inertia::render('Super/Sistem', [
            'sistem' => [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'env' => app()->environment(),
                'db_ok' => $dbOk,
                'db_latensi_ms' => $dbLatensiMs,
                'db_driver' => DB::getDriverName(),
                'queued_jobs' => $queuedJobs,
                'failed_count' => count($failedJobs),
                'storage_link' => $storageLinkAda,
                'public_hot' => $hotAda,
                'disk_bebas_gb' => $diskBebasGb,
                'disk_total_gb' => $diskTotalGb,
            ],
            'failed_jobs' => $failedJobs,
        ]);
    }

    public function retryJobs(Request $request): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        PlatformActivityLog::catat('Menjalankan ulang semua failed jobs', $request->user());

        return back()->with('sukses', 'Semua job gagal telah dimasukkan kembali ke antrean.');
    }

    public function pangkasEkspor(Request $request): RedirectResponse
    {
        Artisan::call('laporan:pangkas-ekspor');
        Artisan::call('pengguna:pangkas-ekspor');

        PlatformActivityLog::catat('Menjalankan pemangkasan berkas ekspor lama', $request->user());

        return back()->with('sukses', 'Pemangkasan berkas ekspor lama selesai dijalankan.');
    }
}
