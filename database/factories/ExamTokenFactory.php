<?php

namespace Database\Factories;

use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<ExamToken>
 */
class ExamTokenFactory extends Factory
{
    protected $model = ExamToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $kode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return [
            'exam_session_id' => ExamSession::factory(),

            // Urutannya menentukan - lihat catatan di ExamFactory.
            'school_id' => fn (array $a) => ExamSession::find($a['exam_session_id'])?->school_id,

            // Token bawaan harus MEMENUHI hash yang sah, bukan hash acak:
            // test yang memverifikasi token akan gagal dengan cara yang
            // membingungkan bila hash-nya tidak bisa dihitung ulang. Nilainya
            // dihitung dari APP_KEY yang sama seperti produksi.
            'token_hash' => $this->hash($kode),
            'token_encrypted' => Crypt::encryptString($kode),

            // Jendela terbuka lebar: dibuat untuk test yang tidak peduli
            // waktu. Test yang peduli memakai kedaluwarsa()/belumBerlaku().
            'valid_from' => now()->subHour(),
            'valid_until' => now()->addHours(6),
            'revoked_at' => null,
            'created_by' => null,
        ];
    }

    public function untukSesi(ExamSession $sesi): static
    {
        return $this->state(fn () => [
            'school_id' => $sesi->school_id,
            'exam_session_id' => $sesi->id,
        ]);
    }

    /**
     * Token dengan kode yang diketahui, supaya test bisa memverifikasinya.
     */
    public function kode(string $kode): static
    {
        return $this->state(fn () => [
            'token_hash' => $this->hash($kode),
            'token_encrypted' => Crypt::encryptString($kode),
        ]);
    }

    public function dicabut(): static
    {
        return $this->state(fn () => ['revoked_at' => now()->subMinute()]);
    }

    public function kedaluwarsa(): static
    {
        return $this->state(fn () => [
            'valid_from' => now()->subHours(4),
            'valid_until' => now()->subHour(),
        ]);
    }

    /** Belum masuk jendelanya - terbaca sebagai draft di plate 18. */
    public function belumBerlaku(): static
    {
        return $this->state(fn () => [
            'valid_from' => now()->addHour(),
            'valid_until' => now()->addHours(4),
        ]);
    }

    /**
     * Menyalin rumus TokenSesi::hash, dan itu disengaja meski tampak duplikasi.
     *
     * Memanggil TokenSesi dari sebuah factory berarti menyelesaikan layanan
     * dari container di dalam pembuatan data test - dan itu membuat test yang
     * seharusnya bisa gagal karena hash-nya salah menjadi test yang selalu
     * setuju dengan produksinya, apa pun isinya. Rumus yang ditulis terpisah
     * di sini membuat perbedaan algoritmanya terlihat sebagai kegagalan test,
     * yang memang tujuannya.
     */
    private function hash(string $kode): string
    {
        return hash_hmac('sha256', $kode, (string) config('app.key'));
    }
}
