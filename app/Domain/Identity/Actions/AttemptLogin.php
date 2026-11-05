<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya jalur login.
 *
 * ---------------------------------------------------------------------
 * is_active di dalam larik kredensial
 * ---------------------------------------------------------------------
 * Menaruh 'is_active' => true di dalam larik yang diserahkan ke attempt()
 * berarti akun nonaktif gagal lewat JALUR YANG SAMA dengan password salah.
 * Kalau sebaliknya - attempt dulu, lalu periksa status akun - akan ada
 * cabang kedua, dan cabang kedua itulah yang cenderung melahirkan pesan
 * berbeda ("akun Anda dinonaktifkan") yang membocorkan bahwa akunnya ada.
 *
 * ---------------------------------------------------------------------
 * Satu pesan untuk semua kegagalan
 * ---------------------------------------------------------------------
 * Username tidak ada, password salah, dan akun nonaktif mengembalikan
 * pesan serta status identik. Wireframe plate 01 menuliskannya: "Username
 * tidak ada atau password salah - pesannya sama, supaya tidak bisa dipakai
 * menebak akun mana yang ada."
 */
class AttemptLogin
{
    /** Kegagalan sebelum dikunci. Cocok dengan teks plate 01: "Gagal 3x: rate limit 60s". */
    public const MAX_ATTEMPTS = 3;

    public const DECAY_SECONDS = 60;

    public const PESAN_GAGAL = 'Username tidak ada atau password salah.';

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @return User Pengguna yang berhasil login.
     *
     * @throws ValidationException
     */
    public function execute(string $username, string $password, bool $remember = false): User
    {
        $this->pastikanTidakTerkunci($username);

        $berhasil = $this->auth->guard()->attempt(
            [
                'username' => $username,
                'password' => $password,
                'is_active' => true,
            ],
            $remember,
        );

        if (! $berhasil) {
            RateLimiter::hit($this->kunci($username), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'username' => self::PESAN_GAGAL,
            ]);
        }

        /** @var User $user */
        $user = $this->auth->guard()->user();

        RateLimiter::clear($this->kunci($username));

        $user->forceFill(['last_login_at' => now()])->save();

        $this->logger->log('Login', $user);

        return $user;
    }

    /**
     * @throws ValidationException
     */
    private function pastikanTidakTerkunci(string $username): void
    {
        if (! RateLimiter::tooManyAttempts($this->kunci($username), self::MAX_ATTEMPTS)) {
            return;
        }

        $detik = RateLimiter::availableIn($this->kunci($username));

        throw ValidationException::withMessages([
            'username' => "Terlalu banyak percobaan. Coba lagi dalam {$detik} detik.",
        ]);
    }

    /**
     * Kunci pembatas laju: username + IP.
     *
     * Keduanya, bukan salah satu. Per-username saja membuat satu penyerang
     * bisa mengunci akun orang lain dari jarak jauh hanya dengan menebak
     * passwordnya salah berkali-kali. Per-IP saja membuat satu IP bersama
     * (laboratorium sekolah, NAT) memakan jatah seluruh penggunanya.
     */
    private function kunci(string $username): string
    {
        return 'login:'.mb_strtolower($username).'|'.request()->ip();
    }
}
