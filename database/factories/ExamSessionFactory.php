<?php

namespace Database\Factories;

use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamSession>
 */
class ExamSessionFactory extends Factory
{
    protected $model = ExamSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Jendela bawaan: satu jam, dengan batas mulai 45 menit sesudah
        // dibuka dan batas mutlak 30 menit sesudahnya. Ketiganya sengaja
        // BERBEDA - test yang memakai satu waktu untuk ketiganya tidak
        // membuktikan apa pun tentang urutannya.
        $buka = now()->addHour()->startOfHour();

        return [
            'exam_id' => Exam::factory(),

            // Urutannya menentukan - lihat catatan di ExamFactory.
            'school_id' => fn (array $a) => Exam::find($a['exam_id'])?->school_id,

            // Selalu 1, dan bukan penghitung otomatis.
            //
            // Menghitungnya menuntut salah satu dari dua hal yang keduanya
            // lebih buruk: keadaan static yang bertahan lintas test, atau
            // query `max(session_number)` di dalam definition() - dan query
            // itu tetap salah selama barisnya belum tersimpan. Yang
            // benar-benar butuh nomor berbeda menyebutkannya lewat nomor().
            'session_number' => 1,

            'access_opens_at' => $buka,
            'access_closes_at' => (clone $buka)->addMinutes(45),
            'hard_end_at' => (clone $buka)->addMinutes(90),
            'duration_minutes' => null,
            'status' => StatusSesi::Draft,
        ];
    }

    public function untukUjian(Exam $ujian): static
    {
        return $this->state(fn () => [
            'school_id' => $ujian->school_id,
            'exam_id' => $ujian->id,
        ]);
    }

    public function nomor(int $nomor): static
    {
        return $this->state(fn () => ['session_number' => $nomor]);
    }

    public function status(StatusSesi $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /**
     * Jendela yang ditulis utuh, karena test yang menggeser waktu hampir
     * selalu menggeser ketiganya sekaligus.
     */
    public function jendela(\DateTimeInterface $buka, \DateTimeInterface $tutup, \DateTimeInterface $akhir): static
    {
        return $this->state(fn () => [
            'access_opens_at' => $buka,
            'access_closes_at' => $tutup,
            'hard_end_at' => $akhir,
        ]);
    }
}
