<?php

namespace App\Domain\Exam\Enums;

/**
 * Status satu sesi ujian.
 *
 * Berbeda dari EventStatus, dan perbedaannya disengaja: event punya tujuh
 * status karena ia menampung seluruh kegiatan sekolah (termasuk arsip dan
 * pembatalan), sementara sesi adalah jendela waktu - ia dibuka, berjalan,
 * lalu ditutup. Menyalin ketujuh status event ke sini berarti memberi sesi
 * keadaan "arsip" yang tidak berarti apa-apa.
 *
 * Kosakatanya tetap bahasa Indonesia dan bukan terjemahan, dengan alasan
 * yang sama seperti EventStatus: yang membaca barisnya di basis data enam
 * bulan dari sekarang tidak perlu menerjemahkan apa pun.
 */
enum StatusSesi: string
{
    case Draft = 'draft';
    case Siap = 'siap';
    case Berjalan = 'berjalan';
    case Selesai = 'selesai';
    case Batal = 'batal';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Siap => 'Siap',
            self::Berjalan => 'Berjalan',
            self::Selesai => 'Selesai',
            self::Batal => 'Batal',
        };
    }

    /**
     * Graf transisi sesi.
     *
     * Lebih longgar daripada graf event, dan itu disengaja: jadwal ujian
     * berubah pada hari-H - ruang dipakai kegiatan lain, listrik mati, satu
     * sesi digeser satu jam. Sesi yang sudah Siap boleh kembali ke Draft
     * supaya Admin bisa memperbaiki jendelanya tanpa membatalkan sesinya.
     *
     * @return array<int, self>
     */
    public function tujuanYangBoleh(): array
    {
        return match ($this) {
            self::Draft => [self::Siap, self::Batal],
            self::Siap => [self::Draft, self::Berjalan, self::Batal],
            self::Berjalan => [self::Selesai, self::Batal],

            // Selesai dan Batal bersifat final. Sesi yang sudah selesai tidak
            // bisa dibuka lagi karena peserta sudah mengerjakan; membukanya
            // kembali berarti mengubah apa yang sudah terjadi.
            self::Selesai, self::Batal => [],
        };
    }

    public function bolehPindahKe(self $tujuan): bool
    {
        return in_array($tujuan, $this->tujuanYangBoleh(), true);
    }

    public function final(): bool
    {
        return $this->tujuanYangBoleh() === [];
    }

    public function aktif(): bool
    {
        return $this === self::Berjalan;
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
