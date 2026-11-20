<?php

namespace App\Domain\Question\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Identity\Models\User;
use App\Support\PembatasLaju;
use App\Support\SesiPengguna;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan sandi mandiri konsol Guru (`/guru/sandi`).
 */
class AkunGuruController
{
    public function sandi(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Guru/Sandi', [
            'nama' => $user->name,
            'username' => $user->username,
            'sandi_bawaan' => Hash::check(User::SANDI_BAWAAN, (string) $user->password),
        ]);
    }

    public function gantiSandi(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $valid = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::min(8), 'confirmed', 'different:current_password'],
        ], [
            'current_password.current_password' => 'Sandi saat ini tidak cocok.',
            'password.different' => 'Sandi baru harus berbeda dari sandi saat ini.',
        ], [
            'current_password' => 'sandi saat ini',
            'password' => 'sandi baru',
        ]);

        $user = $request->user();

        $user->forceFill(['password' => $valid['password']])->save();

        PembatasLaju::bersihkan(PembatasLaju::SANDI, PembatasLaju::kunciSandi($user->id));

        $request->session()->regenerate();

        SesiPengguna::lupakanSesiLain((int) $user->id, (string) $request->session()->getId());

        $logger->log('Mengganti sandi sendiri', $user);

        return redirect()
            ->route('guru.dashboard')
            ->with('sukses', 'Sandi berhasil diganti.');
    }
}
