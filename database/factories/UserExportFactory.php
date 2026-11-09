<?php

namespace Database\Factories;

use App\Domain\Admin\Models\UserExport;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserExport>
 */
class UserExportFactory extends Factory
{
    protected $model = UserExport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'requested_by' => User::factory()->admin(),
            'format' => 'xlsx',
            'parameter_json' => ['role' => 'student', 'cari' => '', 'status' => ''],
            'status' => UserExport::STATUS_MENUNGGU,
            'total_baris' => null,
            'path' => null,
            'nama_berkas' => null,
            'ukuran' => null,
            'pesan_gagal' => null,
            'started_at' => null,
            'finished_at' => null,
            'created_at' => now(),
        ];
    }

    /** Ekspor yang sudah siap diunduh, berkasnya ada di disk privat. */
    public function selesai(): static
    {
        return $this->state(fn () => [
            'status' => UserExport::STATUS_SELESAI,
            'total_baris' => 3,
            'path' => 'pengguna/0/'.Str::random(20).'-pengguna.xlsx',
            'nama_berkas' => Str::random(20).'-pengguna.xlsx',
            'ukuran' => 4096,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public function untukSekolah(School $sekolah): static
    {
        return $this->state(fn () => ['school_id' => $sekolah->id]);
    }
}
