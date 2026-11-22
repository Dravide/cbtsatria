<?php

namespace App\Console\Commands;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Buat atau promosikan akun Super Admin platform.
 */
class BuatSuperAdmin extends Command
{
    protected $signature = 'super:buat
                            {--username=superadmin : Username Super Admin}
                            {--nama=Super Admin Platform : Nama tampilan}
                            {--sandi= : Sandi awal (kosongkan untuk acak)}';

    protected $description = 'Buat atau promosikan akun menjadi Super Admin platform (/super)';

    public function handle(): int
    {
        $username = trim((string) $this->option('username'));
        $nama = trim((string) $this->option('nama'));
        $sandiInput = $this->option('sandi');

        $pengguna = User::query()
            ->withoutGlobalScopes()
            ->where('username', $username)
            ->first();

        if ($pengguna !== null) {
            $pengguna->forceFill([
                'is_super_admin' => true,
                'is_active' => true,
            ])->save();

            if (is_string($sandiInput) && $sandiInput !== '') {
                $pengguna->forceFill(['password' => $sandiInput])->save();
            }

            $this->info("Akun [{$username}] dipromosikan menjadi Super Admin.");

            return self::SUCCESS;
        }

        $sekolah = School::query()->orderBy('id')->first();

        if ($sekolah === null) {
            $this->error('Belum ada sekolah di database. Jalankan seeder atau `sekolah:daftarkan` terlebih dahulu.');

            return self::FAILURE;
        }

        $sandi = is_string($sandiInput) && $sandiInput !== ''
            ? $sandiInput
            : Str::password(14, symbols: false);

        User::query()->withoutGlobalScopes()->create([
            'school_id' => $sekolah->id,
            'role' => Role::Admin,
            'name' => $nama,
            'username' => $username,
            'password' => $sandi,
            'is_active' => true,
        ])->forceFill(['is_super_admin' => true])->save();

        $this->info("Super Admin [{$username}] berhasil dibuat.");
        $this->line("Sandi: {$sandi}");

        return self::SUCCESS;
    }
}
