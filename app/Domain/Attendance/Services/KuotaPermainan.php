<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\LeisureGameScore;
use Carbon\CarbonInterface;

/**
 * Batas sepuluh permainan per hari per siswa - permintaan pemilik produk
 * 2026-11-15.
 *
 * =====================================================================
 * Mengapa batasnya JUMLAH PERMAINAN, bukan jumlah skor yang tersimpan
 * =====================================================================
 * Keduanya mudah tertukar, dan yang salah berbahaya justru pada anak yang
 * paling rajin bermain. Kalau batasnya dihitung dari baris `leisure_game_scores`
 * yang berstatus diterima, maka:
 *
 *   anak yang tidak sengaja mengirim kiriman mustahil tidak dihukum  (bagus),
 *   tetapi anak yang bermain sepuluh kali dan sembilan di antaranya
 *   terbentur sebelum tiga detik akan mendapat sebelas, dua belas, dan
 *   seterusnya - karena kiriman yang ditolak tidak menambah hitungan.
 *
 * Yang dibatasi produk adalah "berapa kali ia boleh main", bukan "berapa kali
 * ia boleh menang". Karena itu yang dihitung adalah SELURUH baris hari itu,
 * diterima maupun ditolak.
 *
 * =====================================================================
 * Sehari dihitung dari jam server, bukan jam peramban
 * =====================================================================
 * Batas harian yang memakai jam perangkat bisa diakali dengan mengubah jam
 * ponsel - dan yang paling mudah melakukannya justru anak yang paling ingin
 * bermain. Batasnya dipotong pada tengah malam waktu aplikasi
 * (`APP_TIMEZONE`), sama dengan seluruh jendela sesi di sistem ini.
 *
 * =====================================================================
 * Yang dikembalikan adalah SISA, bukan boolean
 * =====================================================================
 * Layar perlu menyebut angkanya ("sisa 3 permainan hari ini"), dan panggung
 * perlu tahu apakah ia boleh dimulai sebelum anak menekan Mulai - bukan
 * sesudahnya, saat kirimannya sudah ditolak. Karena itu satu panggilan
 * mengembalikan kedua jawaban itu sekaligus.
 */
final class KuotaPermainan
{
    /** Sepuluh kali main per hari. */
    public const PER_HARI = 10;

    /**
     * Sisa kuota hari ini, dan apakah masih boleh bermain sekali lagi.
     *
     * @return array{terpakai: int, sisa: int, batas: int, boleh: bool}
     */
    public function hariIni(int $studentId, CarbonInterface $sekarang): array
    {
        $terpakai = $this->terpakai($studentId, $sekarang);

        return [
            'terpakai' => $terpakai,
            'sisa' => max(0, self::PER_HARI - $terpakai),
            'batas' => self::PER_HARI,
            'boleh' => $terpakai < self::PER_HARI,
        ];
    }

    /**
     * Berapa permainan yang sudah dimainkan hari ini - diterima maupun ditolak.
     *
     * Lihat catatan kelas ini tentang mengapa kiriman yang DITOLAK ikut
     * dihitung.
     */
    public function terpakai(int $studentId, CarbonInterface $sekarang): int
    {
        return (int) LeisureGameScore::query()
            ->where('student_id', $studentId)
            ->whereBetween('played_at', [
                $sekarang->copy()->startOfDay(),
                $sekarang->copy()->endOfDay(),
            ])
            ->count();
    }
}
