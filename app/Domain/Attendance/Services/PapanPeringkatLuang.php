<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\LeisureGameScore;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * Skor permainan waktu luang: mencatat, dan membaca papan peringkatnya.
 *
 * =====================================================================
 * Kelas ini mencatat APA ADANYA; `PenjagaSkorLuar` yang memutuskan
 * =====================================================================
 * Kiriman yang ditolak tetap ditulis sebagai baris, dengan alasannya.
 * Memisahkan "memutuskan" dari "mencatat" membuat aturan penolakan bisa
 * diuji tanpa basis data, dan membuat laman Admin bisa menampilkan baris
 * yang ditolak - sesuatu yang mustahil bila kiriman palsu dibuang diam-diam.
 *
 * =====================================================================
 * Papan peringkat dibaca dengan SATU kueri, dan disaring di PHP
 * =====================================================================
 * "Rekor tertinggi per siswa" bukan agregat SQL biasa: memilih baris
 * dengan skor tertinggi per siswa berarti `GROUP BY student_id, MAX(score)`
 * lalu join balik untuk mendapat barisnya - dan pada tabel yang tumbuh
 * setiap jeda, join itu dijalankan di setiap pembukaan laman.
 *
 * Yang dipakai di sini: satu kueri berindeks yang mengurutkan skor tertinggi
 * lebih dulu, lalu pemilihan pertama-per-siswa di PHP. Hasilnya identik, dan
 * `limit` di kuerinya memotong pekerjaannya secara nyata - sepuluh baris
 * pertama selalu berasal dari paling banyak sepuluh siswa paling atas.
 *
 * Batas `AMBIL_MAKS` dipakai sebagai pengaman: satu anak yang bermain seratus
 * kali tidak boleh menghabiskan memori permintaan ini.
 */
final class PapanPeringkatLuang
{
    /**
     * Sepuluh baris pertama. Angka ini bukan preferensi desain - ia yang
     * membuat `limit` di bawah memotong pekerjaan ke sepuluh siswa, bukan
     * sekadar memotong hasil tampilan.
     */
    public const BARIS_TERATAS = 10;

    /** Pengaman: cukup untuk sepuluh siswa yang paling rajin bermain. */
    private const AMBIL_MAKS = 500;

    public function __construct(private readonly PenjagaSkorLuar $penjaga) {}

    /**
     * Mencatat satu kiriman, apa pun hasilnya.
     *
     * `$peserta` boleh null sejak 2026-11-15: skor milik ANAK, bukan milik
     * penempatan, dan siswa yang belum ditempatkan di ujian mana pun tetap
     * boleh bermain. Yang hilang tanpanya hanyalah dua kolom jejak
     * (`exam_participant_id` dan `class_id`) - dan papan peringkat kelasnya
     * lalu jatuh ke papan sekolah.
     *
     * @return LeisureGameScore baris yang tersimpan, diterima atau tidak.
     */
    public function catat(
        User $siswa,
        ?ExamParticipant $peserta,
        int $distance,
        int $durationMs,
        string $gameType = LeisureGameScore::GAME_LOMPAT,
    ): LeisureGameScore {
        $alasan = $this->penjaga->periksa($distance, $durationMs, $gameType);

        // Untuk hitung_cepat, `distance` kolom dipakai menyimpan skor poin.
        $score = $gameType === LeisureGameScore::GAME_HITUNG
            ? $this->penjaga->hitungSkorHitung($distance)
            : $this->penjaga->hitungSkor($distance);

        return LeisureGameScore::create([
            'school_id' => $siswa->school_id,
            'student_id' => $siswa->id,

            // Kelas saat skor ini dicetak, DISALIN - lihat catatan migrasi
            // tentang mengapa papan peringkat kelas tidak boleh membaca
            // kelas lewat join.
            'class_id' => $peserta?->pesertaEvent?->class_id,

            'exam_participant_id' => $peserta?->id,
            'game_type' => $gameType,
            'distance' => $distance,
            'duration_ms' => $durationMs,
            // Skor yang DIHITUNG di sini. Kiriman yang ditolak tetap diberi
            // angka: baris yang ditolak dengan skor nol terbaca sebagai
            // "kirimannya kosong", padahal ia ditolak karena mustahil.
            'score' => $score,
            'rejected_reason' => $alasan,
            'played_at' => now(),
        ]);
    }

    /**
     * Rekor tertinggi siswa ini untuk jenis permainan tertentu, atau null bila belum pernah diterima.
     */
    public function rekorSaya(User $siswa, string $gameType = LeisureGameScore::GAME_LOMPAT): ?int
    {
        $nilai = LeisureGameScore::query()
            ->where('student_id', $siswa->id)
            ->where('game_type', $gameType)
            ->whereNull('rejected_reason')
            ->max('score');

        return $nilai === null ? null : (int) $nilai;
    }

    /**
     * Peringkat siswa ini di kelasnya, dan berapa banyak pemain di sana.
     *
     * @return array{peringkat: int|null, pemain: int, rekor: int|null}
     */
    public function peringkatSaya(User $siswa, ?int $classId, string $gameType = LeisureGameScore::GAME_LOMPAT): array
    {
        $rekor = $this->rekorSaya($siswa, $gameType);

        if ($rekor === null) {
            return [
                'peringkat' => null,
                'pemain' => $this->jumlahPemain($siswa->school_id, $classId, $gameType),
                'rekor' => null,
            ];
        }

        // Berapa SISWA - bukan berapa BARIS - yang rekornya lebih tinggi.
        $diAtas = LeisureGameScore::query()
            ->where('school_id', $siswa->school_id)
            ->where('game_type', $gameType)
            ->when($classId !== null, fn ($q) => $q->where('class_id', $classId))
            ->whereNull('rejected_reason')
            ->where('student_id', '!=', $siswa->id)
            ->groupBy('student_id')
            ->havingRaw('MAX(score) > ?', [$rekor])
            ->get(['student_id'])
            ->count();

        return [
            'peringkat' => $diAtas + 1,
            'pemain' => $this->jumlahPemain($siswa->school_id, $classId, $gameType),
            'rekor' => $rekor,
        ];
    }

    /**
     * Sepuluh rekor tertinggi di satu kelas - satu baris per siswa.
     *
     * @return Collection<int, array{student_id: int, nama: string, kelas: string|null, score: int}>
     */
    public function papan(int $schoolId, ?int $classId, string $gameType = LeisureGameScore::GAME_LOMPAT, int $batas = self::BARIS_TERATAS): Collection
    {
        $baris = LeisureGameScore::query()
            ->where('school_id', $schoolId)
            ->where('game_type', $gameType)
            ->when($classId !== null, fn ($q) => $q->where('class_id', $classId))
            ->whereNull('rejected_reason')
            ->orderByDesc('score')
            ->orderBy('id')
            ->limit(self::AMBIL_MAKS)
            ->with('student:id,name')
            ->get()
            // Satu baris per siswa: kemunculan pertamanya adalah rekornya,
            // karena kuerinya sudah mengurutkan skor tertinggi lebih dulu.
            ->unique('student_id')
            ->take($batas)
            ->values();

        return $baris->map(fn (LeisureGameScore $b, int $i) => [
            'student_id' => $b->student_id,
            'nama' => $b->student?->name ?? 'Siswa',
            'score' => $b->score,
            'peringkat' => $i + 1,
        ]);
    }

    /** Berapa siswa yang punya skor diterima di kelas ini untuk jenis game ini. */
    public function jumlahPemain(int $schoolId, ?int $classId, string $gameType = LeisureGameScore::GAME_LOMPAT): int
    {
        return (int) LeisureGameScore::query()
            ->where('school_id', $schoolId)
            ->where('game_type', $gameType)
            ->when($classId !== null, fn ($q) => $q->where('class_id', $classId))
            ->whereNull('rejected_reason')
            ->distinct()
            ->count('student_id');
    }
}
