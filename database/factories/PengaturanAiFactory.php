<?php

namespace Database\Factories;

use App\Domain\Tenancy\Models\PengaturanAi;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<PengaturanAi>
 */
class PengaturanAiFactory extends Factory
{
    protected $model = PengaturanAi::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'base_url' => 'https://api.contoh.test/v1',
            'model' => 'model-contoh',
            'api_key_encrypted' => Crypt::encryptString('kunci-contoh-1234'),
            'aktif' => true,
            'batas_token' => 1500,
            'diperbarui_oleh' => null,
        ];
    }

    /** Belum diatur sama sekali - keadaan sekolah yang baru dipasang. */
    public function kosong(): static
    {
        return $this->state(fn () => [
            'base_url' => null,
            'model' => null,
            'api_key_encrypted' => null,
            'aktif' => false,
        ]);
    }

    /** Terisi lengkap tetapi dimatikan Admin. */
    public function nonaktif(): static
    {
        return $this->state(fn () => ['aktif' => false]);
    }
}
