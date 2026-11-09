<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

/**
 * `RefreshDatabase` dengan satu gerbang yang menolak berlari di luar basis
 * data uji.
 *
 * =====================================================================
 * Mengapa gerbang ini ada - dan apa yang sudah pernah terjadi
 * =====================================================================
 * Pada 06 Nov 2026 `php artisan test` MENGHAPUS basis data pengembangan.
 * Sebabnya berlapis, dan tiap lapis harus ditutup di sini karena lapis mana
 * pun bisa terulang:
 *
 *   1. `bootstrap/cache/config.php` ada. Konfigurasi yang di-cache MEMINTAS
 *      `phpunit.xml` sepenuhnya - seluruh `<env>` di dalamnya tidak pernah
 *      dibaca.
 *   2. Cache itu menyimpan `DB_PORT=3306`, `DB_DATABASE=cbt_satria`,
 *      `APP_ENV=local` - bukan `cbt_satria_test` di 3307 sebagaimana
 *      phpunit.xml.
 *   3. `RefreshDatabase` menjalankan `migrate:fresh` atas koneksi BAWAAN.
 *      Karena konfigurasinya menunjuk basis data pengembangan, perintah yang
 *      berjalan adalah `migrate:fresh` atas basis data pengembangan.
 *
 * Hasilnya: satu perintah uji menghapus sekolah, guru, mapel, kelas, tahun
 * ajaran, penugasan, konfigurasi AI, dan soal yang baru saja disusun lewat
 * layar. Tidak ada `log_bin`, tidak ada cadangan, tidak ada yang bisa
 * dipulihkan.
 *
 * ---------------------------------------------------------------------
 * Mengapa di trait, bukan di TestCase
 * ---------------------------------------------------------------------
 * Metode trait MENANG atas metode kelas induk. Menaruh
 * `beforeRefreshingDatabase()` di `Tests\TestCase` tidak berpengaruh apa pun
 * selama `RefreshDatabase` ikut dipakai - versi trait yang dijalankan. Trait
 * ini mendefinisikannya LANGSUNG, dan metode yang ditulis langsung di sebuah
 * trait menang atas metode yang diimpor dari trait lain.
 *
 * ---------------------------------------------------------------------
 * Mengapa berhenti, bukan membersihkan cache sendiri lalu lanjut
 * ---------------------------------------------------------------------
 * Melanjutkan berarti uji berjalan di atas basis data yang tidak pernah
 * dikonfigurasi untuknya, dan kekeliruan yang tidak terlihat adalah
 * kekeliruan yang tidak diperbaiki.
 */
trait RefreshDatabaseAman
{
    use RefreshDatabase;

    /**
     * Dipanggil `RefreshDatabase` SEBELUM `migrate:fresh`.
     */
    protected function beforeRefreshingDatabase()
    {
        $nama = $this->app['db']->connection()->getDatabaseName();

        if (str_ends_with($nama, '_test')) {
            return;
        }

        throw new RuntimeException(
            'Uji dihentikan: koneksinya menunjuk basis data "'.$nama.'", bukan basis data uji. '
            .'`RefreshDatabase` akan menjalankan `migrate:fresh` atas basis data itu dan '
            .'menghapus seluruh isinya. Sebab yang paling sering: `bootstrap/cache/config.php` '
            .'ada, sehingga konfigurasi `phpunit.xml` tidak dibaca dan koneksinya kembali ke '
            .'.env - yaitu basis data pengembangan. Jalankan `php artisan config:clear`, '
            .'lalu ulangi perintah ujinya.'
        );
    }
}
