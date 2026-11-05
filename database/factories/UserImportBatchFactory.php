<?php

namespace Database\Factories;

use App\Domain\Admin\Enums\StatusImporPengguna;
use App\Domain\Admin\Models\UserImportBatch;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserImportBatch>
 */
class UserImportBatchFactory extends Factory
{
    protected $model = UserImportBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'user_id' => User::factory()->admin(),
            'file_path' => 'laporan/impor/0/0/'.Str::random(40).'.csv',
            'nama_asli' => 'pengguna.csv',
            'berkas_hash' => hash('sha256', Str::random(20)),
            'pratinjau_json' => null,
            'status' => StatusImporPengguna::Menunggu->value,
            'total_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'created_rows' => 0,
            'updated_rows' => 0,
            'error_report_path' => null,
            'imported_at' => null,
        ];
    }

    /** Berkas yang sudah dipratinjau, siap dikonfirmasi. */
    public function dipratinjau(): static
    {
        return $this->state(fn () => ['status' => StatusImporPengguna::Dipratinjau->value]);
    }

    public function untukSekolah(School $sekolah): static
    {
        return $this->state(fn () => ['school_id' => $sekolah->id]);
    }
}
