<?php

namespace App\Support\Cetak;

use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Facades\Storage;

/**
 * Kop untuk berkas yang DICETAK: nama sekolah, NPSN, dan logonya.
 *
 * ---------------------------------------------------------------------
 * Mengapa di Support, dan mengapa sebuah kelas
 * ---------------------------------------------------------------------
 * Kartu panitia dan kartu pintu ruang sama-sama mencetak kop yang sama, dan
 * keduanya hidup di modul berbeda (Event dan Facility/Admin). Menyalin
 * pembacaan logo ke dua tempat berarti dua salinan aturan "format apa yang
 * boleh, apa yang tidak" - dan salinan kedua selalu yang terlupakan saat
 * salah satunya diperbaiki.
 *
 * Kelas ini menyentuh model `School` (Tenancy). Itu disengaja dan aman:
 * `app/Support` adalah tempat komponen generik yang tidak termasuk modul mana
 * pun, dan peta modul tidak berlaku untuknya. Menaruhnya di Event berarti
 * Event menyentuh Tenancy untuk sesuatu yang juga dipakai Admin/Facility -
 * ketergantungan yang tidak berarti apa-apa tentang event.
 *
 * ---------------------------------------------------------------------
 * Logo dibaca sebagai DATA URI
 * ---------------------------------------------------------------------
 * DomPDF memuat gambar lokal lewat path filesystem, dan path yang benar di
 * mesin pengembang belum tentu benar di server. Data URI (`data:image/png;
 * base64,...`) menanam gambarnya di dalam HTML - tidak ada path, tidak ada
 * permintaan jaringan, dan `isRemoteEnabled` tetap boleh mati.
 */
final class KopSekolah
{
    /**
     * @return array{nama: string, npsn: string|null, logo: string|null}
     */
    public static function dari(?School $sekolah): array
    {
        return [
            'nama' => $sekolah?->name ?? 'Sekolah',
            'npsn' => $sekolah?->npsn,
            'logo' => self::logoDataUri($sekolah?->logo_path),
        ];
    }

    /**
     * Logo sebagai data URI, atau null bila tidak ada.
     *
     * SVG ditolak dengan sengaja: DomPDF tidak merendernya tanpa ekstensi
     * tambahan, dan logo SVG yang dipaksakan masuk akan muncul sebagai kotak
     * kosong pada kertas yang sudah tercetak - kegagalan yang baru ketahuan
     * setelah kertasnya terbuang.
     */
    private static function logoDataUri(?string $path): ?string
    {
        if ($path === null || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };

        if ($mime === null) {
            return null;
        }

        $isi = Storage::disk('public')->get($path);

        if ($isi === null) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($isi);
    }
}
