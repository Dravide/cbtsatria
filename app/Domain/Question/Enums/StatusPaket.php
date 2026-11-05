<?php

namespace App\Domain\Question\Enums;

/**
 * Keadaan satu paket soal.
 *
 * ---------------------------------------------------------------------
 * Mengapa hanya dua keadaan, sementara soal punya tiga
 * ---------------------------------------------------------------------
 * Paket tidak punya `arsip` yang terpisah dari `draft`, dan itu bukan
 * kelalaian. Soal perlu `arsip` karena ia hidup lama dan dipakai berkali-kali
 * lintas ujian; paket adalah KEMASAN untuk satu ujian tertentu, dan paket yang
 * tidak jadi dipakai cukup dibiarkan `draft`.
 *
 * Menambahkan `arsip` di sini berarti menambahkan keadaan yang tidak bisa
 * dijelaskan bedanya kepada Guru: "apa bedanya paket arsip dan paket draf
 * yang tidak jadi dipakai?" - dan pertanyaan yang tidak punya jawaban adalah
 * keadaan yang tidak seharusnya ada.
 *
 * ---------------------------------------------------------------------
 * `siap` menuntut paket PUNYA butir
 * ---------------------------------------------------------------------
 * Paket kosong berstatus `siap` adalah kemungkinan yang harus ditutup, dan
 * yang menutupnya adalah PaketSoalService: tanpa butir, satu-satunya keadaan
 * yang sah adalah `draft`. Snapshot menolak paket kosong juga (§5 rencana),
 * sehingga paket kosong yang lolos sampai ke pemasangan akan berhenti di
 * sana dengan pesan yang terlambat.
 */
enum StatusPaket: string
{
    case Draft = 'draft';
    case Siap = 'siap';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Siap => 'Siap',
        };
    }
}
