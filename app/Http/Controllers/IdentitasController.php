<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Support\IdentitasAplikasi;
use Illuminate\Http\JsonResponse;

/**
 * Manifest aplikasi yang bisa dipasang ke layar beranda.
 *
 * =====================================================================
 * Mengapa disajikan server, bukan berkas statis di `public/`
 * =====================================================================
 * `public/site.webmanifest` tidak bisa memuat nama dan ikon milik sekolah
 * yang Admin unggah: berkas statis tidak tahu siapa peminta, dan nama produk
 * yang tercetak di sana akan menjadi nama yang dipasang di layar beranda
 * setiap ponsel siswa - termasuk sekolah yang sudah mengganti identitasnya.
 *
 * Rutenya didaftarkan di `routes/web.php` pada alamat yang SAMA dengan
 * berkas lamanya, sehingga tag `<link rel="manifest">` tidak berubah dan
 * tidak ada peramban yang sudah terlanjur memasang versi statis yang perlu
 * ditangani.
 *
 * `Cache-Control: no-cache` dipakai karena manifest di-cache agresif oleh
 * peramban: tanpa itu, Admin yang mengganti ikon tetap melihat ikon lama
 * berhari-hari dan menyimpulkan penyimpanannya gagal.
 */
class IdentitasController
{
    public function manifest(): JsonResponse
    {
        $identitas = IdentitasAplikasi::nilai();

        return response()
            ->json([
                'name' => $identitas['nama'],
                'short_name' => $identitas['nama_pendek'],
                'description' => $identitas['deskripsi'],
                'lang' => 'id',
                'dir' => 'ltr',
                'start_url' => '/',
                'scope' => '/',
                'display' => 'standalone',
                'orientation' => 'portrait-primary',
                'background_color' => '#eceff4',
                'theme_color' => $identitas['warna'],
                'icons' => $identitas['ikon'],
            ])
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }
}
