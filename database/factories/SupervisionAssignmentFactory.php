<?php

namespace Database\Factories;

use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupervisionAssignment>
 */
class SupervisionAssignmentFactory extends Factory
{
    protected $model = SupervisionAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_session_room_id' => ExamSessionRoom::factory(),

            // Urutannya menentukan - lihat catatan di ExamFactory.
            'school_id' => fn (array $a) => ExamSessionRoom::find($a['exam_session_room_id'])?->school_id,

            // Pengawasnya dibuat DI SEKOLAH YANG SAMA dengan ruangnya, bukan
            // User::factory() yang memilih sekolahnya sendiri. Composite FK
            // menuntut keduanya sepakat, dan galatnya 1452 tanpa menyebut
            // kolom mana yang salah.
            'supervisor_id' => fn (array $a) => User::factory()->pengawas()
                ->untukSekolah(School::find($a['school_id']))
                ->create()->id,

            'assigned_by' => null,

            // Null berarti "ikuti jendela sesi" - dan itu bukan kasus tepi
            // melainkan keadaan normal. Factory yang mengisinya akan membuat
            // setiap test menjalankan jalur yang jarang dipakai produksi.
            'start_at' => null,
            'end_at' => null,
            'status' => 'aktif',
        ];
    }

    public function untukRuangSesi(ExamSessionRoom $ruang): static
    {
        return $this->state(fn () => [
            'school_id' => $ruang->school_id,
            'exam_session_room_id' => $ruang->id,
        ]);
    }

    public function untukPengawas(User $pengawas): static
    {
        return $this->state(fn () => [
            'school_id' => $pengawas->school_id,
            'supervisor_id' => $pengawas->id,
        ]);
    }

    /**
     * Pengawas baru di sekolah yang sama - disingkat, karena hampir setiap
     * test yang menugaskan pengawas perlu membuat akunnya lebih dulu.
     */
    public function pengawasBaru(School $sekolah): static
    {
        return $this->state(fn () => [
            'supervisor_id' => User::factory()->pengawas()->untukSekolah($sekolah),
        ]);
    }

    public function jendela(\DateTimeInterface $mulai, \DateTimeInterface $selesai): static
    {
        return $this->state(fn () => [
            'start_at' => $mulai,
            'end_at' => $selesai,
        ]);
    }
}
