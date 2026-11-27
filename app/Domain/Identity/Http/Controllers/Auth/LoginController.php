<?php

namespace App\Domain\Identity\Http\Controllers\Auth;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Identity\Actions\AttemptLogin;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Http\Requests\LoginRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        $intended = (string) $request->session()->get('url.intended', '');

        $user = $attempt->execute(
            $request->string('username')->toString(),
            $request->string('password')->toString(),
            $request->boolean('remember'),
        );

        if ($user->role === Role::Student && ! $this->datangDariKios($intended) && $this->harusMasukLewatKios($user)) {
            $this->auth->guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $this->resolver->forget();

            throw ValidationException::withMessages([
                'username' => 'Akun Anda terdaftar sebagai penghuni ruang PC/kios. Silakan masuk melalui perangkat PC kios di ruang ujian Anda.',
            ]);
        }

        $request->session()->regenerate();

        // Login berhasil berarti school_id baru diketahui. Resolver sudah
        // menyimpan hasil "null" dari saat sebelum autentikasi, jadi ia
        // harus dilupakan - kalau tidak, scope tenant tetap no-op untuk
        // sisa permintaan ini.
        $this->resolver->forget();
        $request->session()->put('school_id', $user->school_id);

        return redirect()->intended($user->role->dashboardPath());
    }

    private function datangDariKios(string $intended): bool
    {
        if ($intended === '') {
            return false;
        }

        $path = (string) parse_url($intended, PHP_URL_PATH);

        return preg_match('#^/lab/[^/]+/\d+(/gerbang)?$#', $path) === 1;
    }

    /**
     * Siswa yang terdaftar sebagai penghuni aktif di ruang ber-PC (bukan mode HP darurat)
     * wajib masuk melalui tautan kios PC, bukan laman /login biasa.
     */
    private function harusMasukLewatKios(User $user): bool
    {
        return DB::table('exam_participants as ep')
            ->join('exam_session_rooms as esr', 'esr.id', '=', 'ep.exam_session_room_id')
            ->join('exam_sessions as es', 'es.id', '=', 'esr.exam_session_id')
            ->join('event_rooms as er', 'er.id', '=', 'esr.event_room_id')
            ->join('rooms as r', 'r.id', '=', 'er.room_id')
            ->where('ep.student_id', $user->id)
            ->where('ep.status', 'terdaftar')
            ->where(function ($q): void {
                $q->whereNull('ep.emergency_device_mode')
                    ->orWhere('ep.emergency_device_mode', '!=', 'hp');
            })
            ->where('r.device_count', '>', 0)
            ->where('es.hard_end_at', '>=', now())
            ->exists();
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
