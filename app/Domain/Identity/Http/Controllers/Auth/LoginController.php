<?php

namespace App\Domain\Identity\Http\Controllers\Auth;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Identity\Actions\AttemptLogin;
use App\Domain\Identity\Http\Requests\LoginRequest;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LoginController
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly SchoolResolver $resolver,
        private readonly ActivityLogger $logger,
    ) {}

    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request, AttemptLogin $attempt): RedirectResponse
    {
        $user = $attempt->execute(
            $request->string('username')->toString(),
            $request->string('password')->toString(),
            $request->boolean('remember'),
        );

        $request->session()->regenerate();

        // Login berhasil berarti school_id baru diketahui. Resolver sudah
        // menyimpan hasil "null" dari saat sebelum autentikasi, jadi ia
        // harus dilupakan - kalau tidak, scope tenant tetap no-op untuk
        // sisa permintaan ini.
        $this->resolver->forget();
        $request->session()->put('school_id', $user->school_id);

        return redirect()->intended($user->role->dashboardPath());
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user) {
            $this->logger->log('Logout', $user);
        }

        $this->auth->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
