<?php

namespace App\Domain\Attendance\Enums;

/**
 * Status kehadiran satu peserta pada satu penempatan - PRD §10.1.
 *
 * ---------------------------------------------------------------------
 * `pending` adalah keadaan yang benar, dan bukan kelalaian
 * ---------------------------------------------------------------------
 * Seluruh peserta lahir `pending`, bukan `tidak_hadir`. Perbedaan itu
 * menentukan apakah sistem ini bisa dipercaya: bila defaultnya
 * `tidak_hadir`, setiap siswa tercatat membolos sejak penempatannya dibuat
 * - dan yang membacanya (orang tua, wali kelas, laporan) tidak punya cara
 * membedakan "belum diverifikasi" dari "tidak datang". Dua keadaan yang
 * sangat berbeda, dan menggabungkannya menjadi satu nilai berarti laporan
 * yang menuduh.
 *
 * ---------------------------------------------------------------------
 * `terlambat` adalah keadaan tersendiri, bukan nama lain `hadir`
 * ---------------------------------------------------------------------
 * Seorang siswa yang masuk pukul 07:45 untuk ujian yang dibuka 07:30 memang
 * hadir dan memang terlambat. Menyimpannya sebagai `hadir` membuang fakta
 * kedua; menyimpannya sebagai `tidak_hadir` membuang yang pertama. Karena
 * itu ia berdiri sendiri - dan plate 13 memang menampilkan keduanya sebagai
 * chip yang berbeda.
 */
enum StatusPresensi: string
{
    case Pending = 'pending';
    case Hadir = 'hadir';
    case Terlambat = 'terlambat';
    case TidakHadir = 'tidak_hadir';
    case Izin = 'izin';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Belum diverifikasi',
            self::Hadir => 'Hadir',
            self::Terlambat => 'Terlambat',
            self::TidakHadir => 'Tidak hadir',
            self::Izin => 'Izin',
        };
    }

    /** Label pendek untuk chip di dalam baris kursi. */
    public function pendek(): string
    {
        return match ($this) {
            self::Pending => 'belum',
            self::Hadir => 'hadir',
            self::Terlambat => 'terlambat',
            self::TidakHadir => 'tidak hadir',
            self::Izin => 'izin',
        };
    }

    /**
     * Nada chip. `pending` memakai `mute`, bukan `warn`.
     *
     * Sama alasannya dengan StatusToken::nada(): peserta yang belum
     * diverifikasi adalah keadaan normal satu menit setelah gerbang dibuka.
     * Memberinya warna peringatan berarti seluruh ruang terlihat bermasalah
     * di setiap penyegaran sampai pengawas selesai memanggil satu per satu.
     */
    public function nada(): string
    {
        return match ($this) {
            self::Hadir => 'ok',
            self::Terlambat => 'info',
            self::TidakHadir, self::Izin => 'warn',
            self::Pending => 'mute',
        };
    }

    /**
     * Apakah status ini membuka gerbang attempt - PRD §10.4, blueprint :1413.
     *
     * ---------------------------------------------------------------------
     * Mengapa aturannya diletakkan di sini sejak Fase 6
     * ---------------------------------------------------------------------
     * Blueprint :1413 memerintahkan hal yang tepat: "Jangan menunggu Fase 7
     * untuk menguji attendance gating. Buat test penolakannya sejak Fase 6
     * agar integrasi presensi-attempt tidak jadi kejutan di akhir."
     *
     * Endpoint `start` milik Fase 7. Yang dikunci di sini adalah ATURANNYA,
     * supaya Fase 7 tidak menemukan aturan baru - dan supaya perubahan
     * aturannya kelak menjadi perubahan satu method yang punya testnya
     * sendiri, bukan perubahan yang tersembunyi di dalam controller.
     *
     * `terlambat` hanya membuka gerbang bila kebijakannya mengizinkan, dan
     * itu keputusan ujian (`exams.allow_late_entry`) - bukan keputusan
     * fungsi ini. Karena itu ia menerima saklarnya, bukan membacanya
     * sendiri: fungsi yang membaca basis data tidak bisa diuji tanpa basis
     * data, dan aturan sekecil ini justru yang paling perlu diuji sendirian.
     */
    public function membukaGerbang(bool $izinkanTerlambat = false): bool
    {
        return match ($this) {
            self::Hadir => true,
            self::Terlambat => $izinkanTerlambat,
            self::Pending, self::TidakHadir, self::Izin => false,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
