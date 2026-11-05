<?php

namespace App\Domain\Identity\Http\Controllers\Auth;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Lupa password.
 *
 * Fase 1 tidak mengirim surel. Wireframe plate 02 menentukan bentuk
 * lamannya: sebuah kartu yang menyebut Admin sekolah secara eksplisit,
 * karena siswa tidak selalu punya email dan reset untuk mereka dilakukan
 * Admin lewat laman Pengguna - bukan lewat surel yang tidak akan sampai.
 *
 * Membangun alur surel sekarang berarti membangun sesuatu yang tidak bisa
 * diuji sampai Fase 2 punya laman Pengguna dan pengiriman yang nyata.
 */
class PasswordResetLinkController
{
    public function create(): Response
    {
        return Inertia::render('Auth/LupaPassword');
    }
}
