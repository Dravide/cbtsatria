<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penjaga konsol Super Admin (`/super/*`).
 *
 * Hanya akun dengan `users.is_super_admin = true` yang diizinkan masuk.
 */
class AksesSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $pengguna = Auth::user();

        if ($pengguna === null) {
            return redirect()->route('login');
        }

        if (! $pengguna->isSuperAdmin()) {
            abort(403, 'Konsol ini hanya untuk Super Admin platform.');
        }

        return $next($request);
    }
}
