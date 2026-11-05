<?php

namespace App\Domain\Tenancy\Http\Middleware;

use App\Domain\Tenancy\Services\SchoolResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Konteks tenant untuk permintaan ini.
 *
 * Middleware ini TIDAK menyaring query - itu tugas SchoolScope. Tugasnya
 * hanya dua:
 *
 *   1. Setelah login, simpan school_id ke sesi supaya konteksnya bertahan
 *      dan bisa diaudit tanpa membaca ulang user.
 *   2. Tolak dengan 404 bila route menandai butuh tenant tapi konteksnya
 *      kosong - mis. sesi kedaluwarsa di tengah jalan, atau user tanpa
 *      school_id karena data yang rusak.
 *
 * 404, bukan 403: permintaan tanpa konteks tenant tidak berhak tahu apakah
 * yang diminta ada. Alasannya sama dengan lintas tenant - 403 membocorkan
 * keberadaan data.
 */
class EnsureTenantContext
{
    public function __construct(private readonly SchoolResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->school_id !== null && ! $request->session()->has('school_id')) {
            $request->session()->put('school_id', $user->school_id);
        }

        // Sesi menyimpan school_id pada saat login. Bila user yang
        // terautentikasi kini membawa school_id berbeda, sesi itu basi -
        // resolusinya diperbarui, bukan dipakai apa adanya.
        if ($user?->school_id !== null && $request->session()->get('school_id') !== $user->school_id) {
            $request->session()->put('school_id', $user->school_id);
            $this->resolver->forget();
        }

        if ($request->route()?->defaults['tenant'] ?? false) {
            if (! $this->resolver->hasContext()) {
                abort(404);
            }
        }

        return $next($request);
    }
}
