<?php

namespace Database\Factories;

use App\Domain\Admin\Enums\JenisImporMaster;
use App\Domain\Admin\Enums\StatusImporPengguna;
use App\Domain\Admin\Models\MasterImportBatch;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MasterImportBatch>
 */
class MasterImportBatchFactory extends Factory
{
    protected $model = MasterImportBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'user_id' => User::factory()->admin(),
            'jenis' => JenisImporMaster::Mapel->value,
            'academic_year_id' => null,
            'file_path' => 'impor-mapel/0/0/'.Str::random(40).'.csv',
            'nama_asli' => 'mapel.csv',
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

    public function ruang(): static
    {
        return $this->state(fn () => [
            'jenis' => JenisImporMaster::Ruang->value,
            'file_path' => 'impor-ruang/0/0/'.Str::random(40).'.csv',
            'nama_asli' => 'ruang.csv',
        ]);
    }

    public function kelas(): static
    {
        return $this->state(fn () => [
            'jenis' => JenisImporMaster::Kelas->value,
            'file_path' => 'impor-kelas/0/0/'.Str::random(40).'.csv',
            'nama_asli' => 'kelas.csv',
        ]);
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
