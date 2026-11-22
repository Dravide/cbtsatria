<?php

namespace App\Http\Controllers\Super;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use App\Models\PlatformActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Mode dukung (Impersonate) ber-audit dengan batas waktu 30 menit.
 */
class SuperImpersonasiController
{
    public const SESI_KUNCI = 'impersonasi_super';

    public const DURASI_MENIT = 30;

    public function masuk(Request $request, int $sekolah): RedirectResponse
    {
        $super = $request->user();
        $model = School::query()->findOrFail($sekolah);

        $data = $request->validate([
            'alasan' => ['required', 'string', 'min:5', 'max:240'],
        ], [], ['alasan' => 'alasan dukungan']);

        $admin = User::query()
            ->withoutGlobalScopes()
            ->where('school_id', $model->id)
            ->where('role', Role::Admin->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($admin === null) {
            return back()->with('galat', 'Sekolah ini tidak memiliki akun Admin aktif untuk dimasuki.');
        }

        PlatformActivityLog::catat(
            'Memulai mode dukung (impersonate)',
            $super,
            $model,
            [
                'target_admin' => $admin->username,
                'alasan' => trim($data['alasan']),
            ],
        );

        $request->session()->put(self::SESI_KUNCI, [
            'super_id' => $super->id,
            'school_id' => $model->id,
            'sekolah' => $model->name,
            'admin_username' => $admin->username,
            'alasan' => trim($data['alasan']),
            'sampai' => now()->addMinutes(self::DURASI_MENIT)->getTimestamp(),
        ]);

        Auth::login($admin);
        $request->session()->put('school_id', $model->id);
        app(SchoolResolver::class)->forget();

        return redirect('/admin')
            ->with('sukses', "Masuk ke mode dukung {$model->name} sebagai {$admin->username}.");
    }

    public function keluar(Request $request): RedirectResponse
    {
        $info = $request->session()->pull(self::SESI_KUNCI);

        if (! is_array($info) || ! isset($info['super_id'])) {
            return redirect('/dashboard');
        }

        $super = User::query()
            ->withoutGlobalScopes()
            ->whereKey((int) $info['super_id'])
            ->where('is_super_admin', true)
            ->first();

        if ($super === null) {
            Auth::logout();

            return redirect('/login');
        }

        $sekolah = isset($info['school_id'])
            ? School::query()->find((int) $info['school_id'])
            : null;

        Auth::login($super);
        $request->session()->put('school_id', $super->school_id);
        app(SchoolResolver::class)->forget();

        PlatformActivityLog::catat(
            'Mengakhiri mode dukung (impersonate)',
            $super,
            $sekolah,
            ['target_admin' => $info['admin_username'] ?? null],
        );

        return redirect()
            ->route('super.dashboard')
            ->with('sukses', 'Kembali ke konsol Super Admin.');
    }
}
