<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Memutus sesi lain milik satu akun.
 *
 * =====================================================================
 * Mengapa ini ada, padahal `logoutOtherDevices()` sudah ada di framework
 * =====================================================================
 * Karena `logoutOtherDevices()` TIDAK memutus apa pun di aplikasi ini.
 *
 * Ia bekerja berpasangan dengan middleware `AuthenticateSession`, yang
 * menyimpan hash sandi di sesi dan membandingkannya pada setiap permintaan.
 * `bootstrap/app.php` tidak memasang middleware itu, jadi `logoutOtherDevices()`
 * hanya menulis penanda lalu membakar satu event - dan sesi di perangkat lain
 * tetap hidup sampai kedaluwarsa sendiri.
 *
 * Memasang `AuthenticateSession` secara global bukan pilihan: ia memvalidasi
 * hash sandi di SETIAP permintaan SELURUH peran, yaitu perubahan perilaku
 * seluruh aplikasi demi satu laman. Menghapus barisnya dari tabel `sessions`
 * mengubah tepat satu hal.
 *
 * =====================================================================
 * Mengapa di App\Support, bukan di dalam domain
 * =====================================================================
 * Yang diurusnya adalah tabel framework (`sessions`) yang tidak dimiliki
 * modul mana pun - argumen yang sama yang sudah ditulis `PembatasLaju` untuk
 * dirinya sendiri. `App\Support\TabelQuery` berdiri dengan alasan yang sama.
 *
 * =====================================================================
 * Batas yang harus terbaca dari sini, bukan ditemukan lewat laporan
 * =====================================================================
 * Gerbang `session.driver === 'database'` di bawah WAJIB. Hanya driver
 * `database` yang menyimpan sesi di tabel ini; `file`, `redis`, `cookie`, dan
 * `array` menyimpannya di tempat lain, dan sebuah `DELETE` ke tabel yang tidak
 * dipakai tidak memutus apa pun sambil tampak berhasil.
 *
 * Akibat jujurnya: HARI INI `SESSION_DRIVER=database`, sehingga method ini
 * benar-benar memutus. Kalau kelak driver-nya berganti, method ini
 * mengembalikan 0 dan tidak ada sesi yang diputus - dan yang pertama tahu
 * adalah siswa yang sandinya diganti orang lain, bukan test.
 *
 * Perhatikan juga: `phpunit.xml` memakai driver `array`, jadi setiap test
 * yang menguji perilaku ini harus menyetel
 * `config(['session.driver' => 'database'])` lebih dulu.
 */
final class SesiPengguna
{
    /**
     * Hapus seluruh sesi akun ini KECUALI sesi yang sedang dipakai.
     *
     * @param  string  $sesiSekarang  id sesi yang harus bertahan - dibaca
     *                                SESUDAH `regenerate()`, karena regenerate
     *                                mengganti id-nya dan id yang lama sudah
     *                                tidak menunjuk baris mana pun.
     * @return int Jumlah baris yang terhapus. Nol berarti tidak ada yang
     *             diputus - baik karena memang sendirian, maupun karena
     *             driver sesinya bukan `database`.
     */
    public static function lupakanSesiLain(int $userId, string $sesiSekarang): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->where('id', '!=', $sesiSekarang)
            ->delete();
    }
}
