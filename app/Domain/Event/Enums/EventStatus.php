<?php

namespace App\Domain\Event\Enums;

/**
 * Tujuh status event - PRD §6.3.
 *
 * ---------------------------------------------------------------------
 * "berjalan", bukan "aktif"
 * ---------------------------------------------------------------------
 * Wireframe plate 10 menandaskannya, dan itu bukan pilihan kata. "Aktif"
 * sudah dipakai tahun ajaran dan semester (AcademicYear::is_active). Memakai
 * kata yang sama untuk status event membuat "event aktif" ambigu di
 * percakapan sehari-hari - "aktif" yang mana? - dan ambiguitas itu berakhir
 * pada Admin yang membuka tahun ajaran keliru saat menyiapkan ujian.
 *
 * Karena itu nilai yang disimpan basis data pun berbahasa Indonesia dan
 * bukan terjemahan: draft, persiapan, siap, berjalan, selesai, arsip, batal.
 * Yang membaca barisnya di basis data enam bulan dari sekarang tidak perlu
 * menerjemahkan apa pun.
 *
 * ---------------------------------------------------------------------
 * Graf transisi: default yang bisa ditolak
 * ---------------------------------------------------------------------
 * PRD §6.3 menetapkan TUJUH status tetapi TIDAK menetapkan graf transisinya,
 * dan blueprint menandai keputusan #8 (pembatalan & penjadwalan ulang) sebagai
 * penghambat Fase 3. PRD §24 menutup dengan "Keputusan yang belum ditetapkan
 * tidak boleh diasumsikan sebagai persetujuan final."
 *
 * Graf di bawah karena itu adalah USULAN, bukan kesepakatan:
 *
 *   Draft ⇄ Persiapan → Siap → Berjalan → Selesai → Arsip
 *              ↓          ↓        ↓          ↓
 *           Batal ───────┴────────┴──────────┘
 *
 * Dua konsekuensinya sengaja dibuat terlihat, tidak disembunyikan:
 *
 *   1. Siap TIDAK bisa kembali ke Persiapan. Menandai event siap terlalu
 *      dini karena itu hanya bisa dibatalkan lewat Batal. Ini titik yang
 *      paling mungkin ditolak, dan mengubahnya berarti menambah satu baris
 *      di TABEL_TRANSIKSI - bukan memburu controller.
 *
 *   2. Arsip dan Batal bersifat final. Arsip hanya dari Selesai, sehingga
 *      event yang dibatalkan tidak pernah bisa diarsipkan: ia berhenti
 *      sebagai catatan pembatalan, bukan tenggelam ke dalam arsip yang
 *      terbaca seperti "pernah selesai".
 *
 * Readiness gate (PRD §6.5) menggerbangi transisi ke Siap - lihat
 * EventReadiness. Graf ini menggerbangi sisanya.
 */
enum EventStatus: string
{
    case Draft = 'draft';
    case Preparation = 'persiapan';
    case Ready = 'siap';
    case Ongoing = 'berjalan';
    case Completed = 'selesai';
    case Archived = 'arsip';
    case Cancelled = 'batal';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Preparation => 'Persiapan',
            self::Ready => 'Siap',
            self::Ongoing => 'Berjalan',
            self::Completed => 'Selesai',
            self::Archived => 'Arsip',
            self::Cancelled => 'Batal',
        };
    }

    /**
     * Graf transisi.
     *
     * @return array<int, self>
     */
    public function tujuanYangBoleh(): array
    {
        return match ($this) {
            // Bolak-balik bebas: menyusun komponen adalah pekerjaan yang
            // maju-mundur, dan memaksa satu arah berarti Admin menahan diri
            // untuk menyimpan sampai semuanya sempurna.
            self::Draft => [self::Preparation, self::Cancelled],
            self::Preparation => [self::Draft, self::Ready, self::Cancelled],

            // Tanpa jalan kembali - lihat catatan di atas.
            self::Ready => [self::Ongoing, self::Cancelled],

            // Fase 5 akan memindahkan kedua transisi ini otomatis: ke
            // Berjalan saat sesi pertama dimulai, ke Selesai saat sesi
            // terakhir ditutup. Keduanya tetap ada di graf karena aturannya
            // sama, dan karena Fase 3 harus bisa menempuh siklus penuh lewat
            // UI untuk membuktikan gerbangnya bekerja.
            self::Ongoing => [self::Completed, self::Cancelled],
            self::Completed => [self::Archived],

            self::Archived, self::Cancelled => [],
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

    /**
     * Status yang dihitung sebagai "sedang berjalan" di daftar event.
     *
     * Dipakai tab plate 10. Draft, persiapan, dan siap tidak dihitung
     * berjalan meski ketiganya belum selesai - tab "Berjalan" menjawab
     * "apa yang sedang berlangsung sekarang", dan event yang belum punya
     * jadwal tidak sedang berlangsung.
     */
    public function sedangBerjalan(): bool
    {
        return $this === self::Ongoing;
    }

    /** @return array<int, self> */
    public static function belumSelesai(): array
    {
        return [self::Draft, self::Preparation, self::Ready, self::Ongoing];
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
