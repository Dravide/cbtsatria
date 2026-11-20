<?php

use App\Domain\Attendance\Http\Middleware\AksesPengawas;
use App\Domain\Identity\Http\Middleware\AksesGuru;
use App\Domain\Identity\Http\Middleware\AksesSiswa;
use App\Domain\Tenancy\Http\Middleware\EnsureTenantContext;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // HandleInertiaRequests ditambahkan ke grup web, bukan di-append
        // sebagai middleware global, supaya ia ikut punya sesi.
        $middleware->web(append: [
            HandleInertiaRequests::class,
            EnsureTenantContext::class,
        ]);

        // Portal pengawas punya DUA pintu - akun dan tautan ruang - sehingga
        // middleware('auth') tidak bisa dipakai: pemegang tautan tidak punya
        // akun. Lihat AksesPengawas untuk kedua jalurnya.
        //
        // `guru` berbeda: ia memang menuntut akun, tetapi TIDAK cukup dengan
        // `auth`. Siswa yang mengetik /guru/bank-soal di peramban punya akun
        // yang sah, dan tanpa alias ini ia akan menerima daftar soal ujiannya
        // sendiri. Lihat AksesGuru.
        //
        // `siswa` adalah cerminnya, dan dibutuhkan karena alasan yang sama
        // dari arah sebaliknya: Pengawas yang mengetik /siswa punya akun yang
        // sah, dan tanpa gerbang ini ia akan menerima laman siswa yang
        // kepalanya membaca `pengawasRuang` - lalu menampilkan R-01 di atas
        // jadwal ujian seorang anak. Lihat AksesSiswa.
        $middleware->alias([
            'pengawas' => AksesPengawas::class,
            'guru' => AksesGuru::class,
            'siswa' => AksesSiswa::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $status = $response->getStatusCode();

            if ($status === 419) {
                return back()->with('galat', 'Sesi halaman kedaluwarsa. Silakan coba lagi.');
            }

            if (in_array($status, [403, 404, 503], true) || ($status === 500 && ! app()->hasDebugModeEnabled())) {
                return Inertia::render('Galat', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
