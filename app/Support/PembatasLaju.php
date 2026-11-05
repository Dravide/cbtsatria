<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Pembatas laju untuk dua kredensial enam angka - PRD §19.2 dan §19.5.
 *
 * =====================================================================
 * Yang dijaga, dan mengapa enam angka perlu penjaga
 * =====================================================================
 * Dua tempat di aplikasi ini meminta kode enam angka dari orang yang belum
 * tentu punya akun:
 *
 *   - pintu ruang (`POST /p/{slug}`), kode pengawas pada kartu yang ditempel
 *     di daun pintu;
 *   - gerbang ujian (`POST /siswa/ujian/{peserta}/gerbang`), token yang
 *     ditulis di papan tulis.
 *
 * Keduanya 10^6 kemungkinan, dan keduanya diperiksa dengan biaya yang mahal:
 * kode pengawas lewat `Hash::check` (~100 ms, bcrypt), token lewat satu kueri
 * berindeks. Tanpa pembatas, satu skrip dapat mencoba ribuan kali per menit -
 * pada bcrypt, justru percobaan itulah yang menghabiskan CPU server, dan
 * ujian yang sedang berjalan adalah korban pertamanya.
 *
 * =====================================================================
 * Dua limit berdampingan, dan keduanya diperlukan
 * =====================================================================
 * | Titik masuk | Kunci | Batas |
 * |---|---|---|
 * | pintu      | `slug + IP`  | 5/menit  |
 * | pintu      | `slug`       | 30/menit |
 * | gerbang    | `user_id`    | 5/menit  |
 * | gerbang    | `IP`         | 600/menit |
 *
 * Per-IP saja salah dan akan mengunci orang yang sah: satu ruang diawasi
 * beberapa pengawas di jaringan sekolah yang sama, dan **300 siswa memakai
 * token yang sama dari satu NAT**. Pembatas per-IP yang ketat akan memblokir
 * start serentak pada menit pertama ujian - tepat pada saat paling buruk.
 *
 * Per-kunci saja juga salah: tanpa pasangan IP, satu penebak menghabiskan
 * jatah SELURUH tautan pintu, dan pengawas yang sah di ruang lain ikut
 * tertutup. Dua limit berdampingan menutup keduanya.
 *
 * Plafon per-IP pada token (600/menit) sengaja longgar: ia bukan pembatas
 * keamanan melainkan penjaga penyalahgunaan, dan dengan start serentak 300
 * siswa ia tidak pernah tersentuh. Yang benar-benar menjaga token adalah
 * pembatas per siswa.
 *
 * =====================================================================
 * Yang SUNGGUH menjaga token bukan pembatas ini
 * =====================================================================
 * Perlu dicatat supaya batasnya tidak dibesar-besarkan: token disimpan
 * sebagai HMAC-SHA256 (lihat `TokenSesi`), sehingga pemeriksaannya satu
 * kueri berindeks, bukan pemindaian. Dan token hanya berlaku di dalam
 * jendela sesinya. Pembatas ini menutup satu hal yang tidak ditutup
 * keduanya: penebakan beruntun selama jendela itu masih terbuka.
 *
 * =====================================================================
 * Kunci ditulis di SATU tempat
 * =====================================================================
 * Nama limiter dan bentuk kuncinya dipakai oleh tiga pihak: definisi
 * `RateLimiter::for()` di `AppServiceProvider`, middleware pada rutenya, dan
 * testnya. Kunci yang disusun ulang di tempat lain adalah kunci yang
 * berbeda begitu salah satunya disunting.
 *
 * Kelas ini sengaja duduk di `App\Support`, bukan di dalam salah satu
 * domain: yang memakainya dua domain berbeda - Facility (pintu ruang) dan
 * Attendance (gerbang ujian) - dan menaruhnya di salah satunya berarti yang
 * lain mengimpornya lintas domain untuk sesuatu yang bukan modelnya.
 * `App\Support\TabelQuery` sudah lebih dulu berdiri dengan alasan yang sama.
 */
final class PembatasLaju
{
    /** Nama limiter untuk pintu ruang. */
    public const PINTU = 'pintu-ruang';

    /** Nama limiter untuk gerbang ujian siswa. */
    public const GERBANG = 'gerbang-ujian';

    /**
     * Percobaan kode pengawas per pasangan tautan + IP, per menit.
     *
     * Lima, dan bukan angka yang lebih longgar: pengawas mengetik kode ini
     * sekali, dari kartu yang ada di tangannya. Lima kesalahan dalam satu
     * menit berarti kartunya bukan untuk pintu itu.
     *
     * Pada 5 percobaan/menit, seluruh ruang 10^6 membutuhkan ~139 hari dari
     * satu alamat. Yang membuat angkanya jauh lebih pendek adalah plafon per
     * tautan di bawah - dan tautan pintu juga mati bersama sesinya.
     */
    public const PINTU_PER_PASANGAN = 5;

    /**
     * Percobaan kode pengawas per TAUTAN, per menit - seluruh IP digabung.
     *
     * Inilah batas yang tidak bisa dilewati dengan berpindah alamat: 30/menit
     * membuat seluruh 10^6 kemungkinan memakan ~23 hari, sementara tautan
     * pintu hanya berlaku 2 jam secara bawaan (`RoomEntryLinkService`).
     */
    public const PINTU_PER_TAUTAN = 30;

    /**
     * Percobaan token per siswa, per menit.
     *
     * Siswa mengetik token SEKALI - dari papan tulis, di awal ujian. Lima
     * kesalahan dalam satu menit hampir selalu berarti tokennya salah baca,
     * dan token memang boleh salah baca: yang terjadi adalah ia menunggu
     * satu menit lalu mengetiknya lagi.
     */
    public const GERBANG_PER_SISWA = 5;

    /**
     * Percobaan token per IP, per menit.
     *
     * Penjaga penyalahgunaan, bukan pembatas keamanan - lihat catatan kelas.
     * 600 dipilih supaya start serentak 300 siswa dari satu NAT sekolah masih
     * menyisakan dua kali lipat ruang, termasuk percobaan ulang.
     */
    public const GERBANG_PER_IP = 600;

    /** Pesan saat pasangan tautan + IP melampaui batasnya. */
    public const PESAN_PINTU = 'Terlalu banyak percobaan kode. Tunggu satu menit, lalu coba lagi.';

    /** Pesan saat tautannya sendiri melampaui batasnya. */
    public const PESAN_PINTU_TAUTAN = 'Tautan pintu ini menerima terlalu banyak percobaan. Tunggu satu menit.';

    /** Pesan saat satu siswa melampaui batas percobaan tokennya. */
    public const PESAN_GERBANG = 'Terlalu banyak percobaan token. Tunggu satu menit, lalu coba lagi.';

    /** Pesan saat satu jaringan melampaui batas percobaan tokennya. */
    public const PESAN_GERBANG_IP = 'Terlalu banyak percobaan token dari jaringan ini. Tunggu satu menit.';

    public static function kunciPintuPasangan(string $slug, string $ip): string
    {
        return "pintu:{$slug}|{$ip}";
    }

    public static function kunciPintuTautan(string $slug): string
    {
        return "pintu:{$slug}";
    }

    public static function kunciGerbangSiswa(int|string $userId): string
    {
        return "token:{$userId}";
    }

    public static function kunciGerbangIp(string $ip): string
    {
        return "token.ip:{$ip}";
    }

    /**
     * Bersihkan hitungan sebuah limit bernama SESUDAH kredensialnya cocok.
     *
     * ---------------------------------------------------------------------
     * Mengapa `md5`, dan mengapa itu bukan detail internal yang boleh
     * disalin sendiri
     * ---------------------------------------------------------------------
     * `ThrottleRequests` meng-hash kuncinya sebelum menyimpannya:
     *
     *     md5($limiterName.$limit->key)
     *
     * Akibatnya `RateLimiter::clear('pintu:r01-abcd|1.2.3.4')` - kunci
     * mentahnya - TIDAK membersihkan apa pun, dan gagalnya tidak berbunyi:
     * tidak ada galat, tidak ada pengecualian, hanya pengawas yang tetap
     * terkunci pada percobaan berikutnya. Perbedaan itu sudah dibuktikan di
     * sesi ini, bukan disimpulkan dari membaca kode.
     *
     * Hash-nya karena itu dihitung di SATU tempat - di sini - dan testnya
     * memanggil method ini, bukan menyusun ulang md5-nya. Kalau Laravel suatu
     * hari berhenti meng-hash, yang rusak hanyalah satu berkas ini, dan
     * testnya gagal di tempat yang menjelaskan sebabnya.
     *
     * Perhatikan juga: ini BERBEDA dari `AttemptLogin`, yang memakai
     * `RateLimiter` langsung tanpa middleware. Di sana kuncinya mentah, dan
     * `RateLimiter::clear()` memang bekerja apa adanya.
     */
    public static function bersihkan(string $namaLimiter, string $kunci): void
    {
        RateLimiter::clear(md5($namaLimiter.$kunci));
    }
}
