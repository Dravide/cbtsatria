<?php

namespace App\Domain\Grading\Services;

use App\Domain\Attendance\Models\StudentAnswer;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Grading\Models\EssayGrading;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menulis koreksi uraian - PRD §13.3, wireframe plate 06.
 *
 * =====================================================================
 * Satu-satunya penulis `essay_gradings`, dan karena itu yang menjaga rulenya
 * =====================================================================
 * Dua aturan yang tidak bisa ditegakkan constraint basis data:
 *
 *   1. `score` SAMA DENGAN jumlah `criteria_json`. Poin total yang berbeda
 *      dari rinciannya adalah poin total yang tidak bisa
 *      dipertanggungjawabkan saat siswa bertanya "kurang di mana".
 *   2. `score` tidak boleh melebihi bobot butir. Rubrik yang maksimalnya
 *      lebih besar dari bobotnya adalah rubrik yang salah susun, dan
 *      membiarkannya berarti ujian yang totalnya melebihi 100.
 *
 * Keduanya diperiksa di sini, di dalam transaksi yang sama dengan penulisan.
 * Memeriksanya di Form Request berarti dua tempat yang bisa berbeda pendapat
 * tentang pemanggilan langsung dari seeder atau test.
 *
 * =====================================================================
 * Kunci koreksi diperiksa di sini, bukan hanya di UI
 * =====================================================================
 * Blueprint keputusan #10: koreksi dikunci begitu hasil dipublikasikan, dan
 * Admin boleh membukanya kembali dengan alasan yang tercatat. Menegakkannya
 * hanya dengan menonaktifkan tombol berarti satu permintaan langsung bisa
 * menimpa nilai yang sudah dibaca 300 siswa.
 *
 * Admin yang membuka kunci lewat `AdministrasiKoreksi` mencatat alasannya ke
 * `activity_logs`; service ini menolak koreksi selama `gradings_locked`
 * masih true, siapa pun pemanggilnya.
 */
class PenilaiUraian
{
    /** Batas panjang catatan untuk siswa - kolom `feedback` bertipe text. */
    public const BATAS_FEEDBACK = 2000;

    public function __construct(
        private readonly PenghitungHasil $penghitung,
        private readonly ActivityLogger $log,
    ) {}

    /**
     * Simpan koreksi satu jawaban uraian.
     *
     * @param  array<int, array{kriteria: string, poin: float|int|string}>  $kriteria
     *
     * @throws ValidationException
     */
    public function koreksi(
        StudentAnswer $jawaban,
        User $guru,
        array $kriteria,
        ?string $feedback = null,
    ): EssayGrading {
        $butir = $jawaban->butir;
        $soal = $butir?->soal;

        if ($soal === null || ! $soal->perluKoreksiManual()) {
            throw ValidationException::withMessages([
                'jawaban' => 'Jawaban ini bukan jawaban uraian; nilainya dihitung otomatis.',
            ]);
        }

        $attempt = $butir?->attempt;

        if ($attempt === null) {
            throw ValidationException::withMessages([
                'jawaban' => 'Jawaban ini tidak terhubung ke pengerjaan mana pun.',
            ]);
        }

        $event = $attempt->penempatan?->exam?->event;

        $this->pastikanTidakTerkunci($event);

        $bobot = $soal->bobotBerlaku();
        $baris = $this->rapikanKriteria($kriteria, $bobot);

        $skor = round(array_sum(array_column($baris, 'poin')), 2);

        if ($skor > $bobot + 0.01) {
            throw ValidationException::withMessages([
                'kriteria' => "Poin total {$skor} melebihi bobot butir ({$bobot}).",
            ]);
        }

        $feedback = $this->rapikanFeedback($feedback);

        $koreksi = DB::transaction(function () use ($jawaban, $guru, $baris, $skor, $feedback) {
            $ada = EssayGrading::query()
                ->where('student_answer_id', $jawaban->id)
                ->lockForUpdate()
                ->first() ?? new EssayGrading;

            $ada->forceFill([
                'school_id' => $jawaban->school_id,
                'student_answer_id' => $jawaban->id,
                'teacher_id' => $guru->id,
                'score' => $skor,
                'criteria_json' => $baris,
                'feedback' => $feedback,
                'status' => EssayGrading::STATUS_FINAL,
                'graded_at' => now(),
            ])->save();

            // `is_correct` uraian tidak pernah diisi. Kolom itu boolean, dan
            // jawaban uraian tidak benar atau salah - ia bernilai sebagian.
            // Menyetel true/false di sini akan membuat laporan soal yang
            // membaca kolom itu menghitung uraian sebagai objektif.
            $jawaban->forceFill(['score_awarded' => $skor])->save();

            return $ada;
        });

        $this->penghitung->hitungUlang($attempt);

        $this->log->log('koreksi_uraian', $koreksi, [
            'student_answer_id' => $jawaban->id,
            'exam_attempt_id' => $attempt->id,
            'score' => $skor,
        ]);

        return $koreksi;
    }

    /**
     * Hapus koreksi satu jawaban - jawaban kembali "belum dinilai".
     *
     * Ada karena Guru memang salah mengoreksi, dan satu-satunya alternatif
     * tanpa method ini adalah mengoreksi ulang dengan skor 0 - yang bukan
     * hal yang sama: 0 berarti "dinilai, tidak dapat poin", dan hasilnya
     * menjadi final padahal seharusnya menunggu.
     */
    public function batalkan(StudentAnswer $jawaban, User $guru): void
    {
        $attempt = $jawaban->butir?->attempt;

        if ($attempt === null) {
            return;
        }

        $this->pastikanTidakTerkunci($attempt->penempatan?->exam?->event);

        $terhapus = DB::transaction(function () use ($jawaban) {
            $ada = EssayGrading::query()
                ->where('student_answer_id', $jawaban->id)
                ->lockForUpdate()
                ->first();

            if ($ada === null) {
                return false;
            }

            $ada->delete();

            $jawaban->forceFill(['score_awarded' => null])->save();

            return true;
        });

        if (! $terhapus) {
            return;
        }

        $this->penghitung->hitungUlang($attempt);

        $this->log->log('koreksi_uraian_dibatalkan', $jawaban, [
            'exam_attempt_id' => $attempt->id,
            'oleh' => $guru->id,
        ]);
    }

    /**
     * Tolak koreksi selama kuncinya belum dibuka Admin.
     *
     * Pesannya menyebut siapa yang bisa membukanya. Pesan "terkunci" tanpa
     * jalan keluar membuat Guru menanyakan hal yang sama ke Admin lewat
     * WhatsApp - dan jawabannya memang harus disebutkan di sini.
     */
    private function pastikanTidakTerkunci(?SchoolEvent $event): void
    {
        if ($event !== null && $event->koreksiTerkunci()) {
            throw ValidationException::withMessages([
                'koreksi' => 'Koreksi sedang dikunci karena hasilnya sudah dipublikasikan. Minta Admin membukanya kembali.',
            ]);
        }
    }

    /**
     * Rapikan dan validasi rincian per kriteria.
     *
     * Kriteria yang poinnya 0 DIBUANG dari rincian - bukan disimpan sebagai
     * nol. Checklist di plate 06 dicentang atau tidak; yang tidak dicentang
     * bukan baris berpoin nol, melainkan baris yang tidak ada. Menyimpannya
     * membuat rincian koreksi penuh dengan kriteria yang tidak dipakai.
     *
     * @param  array<int, array{kriteria: string, poin: float|int|string}>  $kriteria
     * @return list<array{kriteria: string, poin: float}>
     */
    private function rapikanKriteria(array $kriteria, float $bobot): array
    {
        $hasil = [];

        foreach ($kriteria as $satu) {
            $nama = trim((string) ($satu['kriteria'] ?? ''));
            $poin = (float) ($satu['poin'] ?? 0);

            if ($nama === '') {
                continue;
            }

            if ($poin < 0) {
                throw ValidationException::withMessages([
                    'kriteria' => "Poin untuk kriteria \"{$nama}\" tidak boleh negatif.",
                ]);
            }

            if ($poin > $bobot + 0.01) {
                throw ValidationException::withMessages([
                    'kriteria' => "Poin untuk kriteria \"{$nama}\" melebihi bobot butir ({$bobot}).",
                ]);
            }

            if ($poin === 0.0) {
                continue;
            }

            $hasil[] = ['kriteria' => $nama, 'poin' => round($poin, 2)];
        }

        // Koreksi dengan nol kriteria berpoin BUKAN jawaban kosong - ia
        // jawaban yang dinilai dan tidak mendapat poin. Barisnya tetap harus
        // ada, karena `pending_essays` hanya berkurang saat barisnya ada.
        // Tanpa baris, Guru tidak akan pernah bisa menyatakan "jawaban ini
        // tidak dapat poin" dan hasilnya tidak akan pernah final.

        return $hasil;
    }

    /** Catatan untuk siswa, dipotong ke batas kolomnya. */
    private function rapikanFeedback(?string $feedback): ?string
    {
        if ($feedback === null) {
            return null;
        }

        $feedback = trim($feedback);

        if ($feedback === '') {
            return null;
        }

        return mb_substr($feedback, 0, self::BATAS_FEEDBACK, 'UTF-8');
    }
}
