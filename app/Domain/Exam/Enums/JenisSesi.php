<?php

namespace App\Domain\Exam\Enums;

/**
 * Jenis satu sesi ujian - PRD §9.3, wireframe plate 16.
 *
 * ---------------------------------------------------------------------
 * Dua gelombang, satu ujian
 * ---------------------------------------------------------------------
 * Sesi utama adalah pelaksanaan biasa. Sesi susulan adalah gelombang
 * pengganti untuk siswa yang tidak hadir di sesi utama - sakit, izin, atau
 * bentrok ruang - dan tanggalnya TERPISAH dari pelaksanaan.
 *
 * Bedanya dengan `StatusSesi`: status menjawab "di mana sesi ini dalam
 * siklusnya" (draft, siap, berjalan, selesai, batal); jenis menjawab "sesi
 * ini gelombang yang mana". Sesi susulan melewati siklus status yang sama
 * persis - ia tidak punya status khusus, dan karena itu tidak ada satu pun
 * pemeriksaan status yang perlu diajari tentangnya.
 *
 * ---------------------------------------------------------------------
 * Mengapa enum, bukan boolean `is_susulan`
 * ---------------------------------------------------------------------
 * Boolean memaksa setiap pembaca menafsirkan `false` sebagai "utama", dan
 * tidak menyisakan tempat untuk jenis ketiga kelak (mis. sesi "khusus").
 * Kosakatanya berbahasa Indonesia dengan alasan yang sama seperti
 * `StatusSesi` dan `EventStatus`: yang membaca barisnya di basis data enam
 * bulan dari sekarang tidak perlu menerjemahkan apa pun.
 */
enum JenisSesi: string
{
    case Utama = 'utama';
    case Susulan = 'susulan';

    public function label(): string
    {
        return match ($this) {
            self::Utama => 'Utama',
            self::Susulan => 'Susulan',
        };
    }

    public function susulan(): bool
    {
        return $this === self::Susulan;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $pilihan = [];

        foreach (self::cases() as $case) {
            $pilihan[$case->value] = $case->label();
        }

        return $pilihan;
    }
}
