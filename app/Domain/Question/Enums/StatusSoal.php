<?php

namespace App\Domain\Question\Enums;

/**
 * Keadaan satu soal di bank soal.
 *
 * ---------------------------------------------------------------------
 * Mengapa `siap` bukan `published`, meski kolomnya `published_at`
 * ---------------------------------------------------------------------
 * PRD §16.5 menamai kolomnya `published_at`, dan nama kolom itu dipertahankan
 * apa adanya - menggantinya berarti menyimpang dari PRD tanpa alasan yang
 * cukup kuat. Tetapi nilai statusnya berbahasa Indonesia seperti seluruh enum
 * lain di aplikasi ini, dan artinya di sini bukan "terbit ke publik".
 *
 * Bank soal adalah ASET SEKOLAH (keputusan PRD §24 #5). Tidak ada seorang pun
 * di luar sekolah yang melihatnya, dan `siap` berarti "sudah boleh dipakai
 * Guru lain di paketnya" - bukan "sudah terbit".
 *
 * ---------------------------------------------------------------------
 * `arsip` bukan `draft`, dan bedanya menentukan
 * ---------------------------------------------------------------------
 * Soal `draft` masih disunting penulisnya. Soal `arsip` sudah tidak dipakai
 * lagi tetapi TIDAK dihapus, dan itu penting: soal yang pernah dipakai ujian
 * masih dirujuk snapshot serta laporan nilai, dan menghapusnya akan memutus
 * penelusuran "soal ini dari mana". Mengarsipkan adalah cara Guru
 * membereskan bank soalnya tanpa menghapus riwayat orang lain.
 */
enum StatusSoal: string
{
    case Draft = 'draft';
    case Siap = 'siap';
    case Arsip = 'arsip';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Siap => 'Siap',
            self::Arsip => 'Arsip',
        };
    }

    /**
     * @return array<int, array{nilai: string, label: string}>
     */
    public static function untukFilter(): array
    {
        return array_map(fn (self $s) => [
            'nilai' => $s->value,
            'label' => $s->label(),
        ], self::cases());
    }
}
