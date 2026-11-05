<?php

namespace App\Domain\Identity\Enums;

/**
 * Empat peran. Tidak ada peran kelima, dan kepanitiaan bukan salah satunya -
 * PRD §6.6 menegaskan keanggotaan panitia tidak menjadi role autentikasi baru.
 */
enum Role: string
{
    case Admin = 'admin';
    case Teacher = 'teacher';
    case Student = 'student';
    case Supervisor = 'supervisor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Teacher => 'Guru',
            self::Student => 'Siswa',
            self::Supervisor => 'Pengawas',
        };
    }

    /**
     * Tujuan setelah login.
     *
     * Redirect ditentukan peran, bukan dipilih di UI - wireframe plate 01:
     * "Role hanya menentukan halaman tujuan setelah autentikasi berhasil."
     */
    public function dashboardPath(): string
    {
        return match ($this) {
            self::Admin => '/admin',
            self::Teacher => '/guru',
            self::Student => '/siswa',
            self::Supervisor => '/pengawas',
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
