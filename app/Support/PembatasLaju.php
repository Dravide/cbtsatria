<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Pembatas laju untuk tiga titik masuk berkredensial - PRD §19.2 dan §19.5.
 *
 * =====================================================================
 * Yang dijaga, dan mengapa enam angka perlu penjaga
 * =====================================================================
 * Tiga tempat di aplikasi ini meminta kredensial dari orang yang belum tentu
 * punya akun - atau sudah punya, tetapi tidak tentu yang mengetiknya:
 *
 *   - pintu ruang (`POST /p/{slug}`), kode pengawas enam angka yang dipegang
 *     pengawasnya sendiri - diterbitkan di laman Kepanitiaan sejak
 *     2026-11-07, ketika kodenya berhenti menempel di kartu pintu;
 *   - gerbang ujian (`POST /siswa/ujian/{peserta}/gerbang`), token yang
 *     ditulis di papan tulis;
 *   - ganti sandi (`PUT /siswa/akun/sandi`), sandi lama yang diketik ulang.
 *
 * Dua yang pertama 10^6 kemungkinan; yang ketiga bukan enam angka, melainkan
 * sandi yang sudah diketahui pemiliknya sendiri. Ketiganya tetap perlu
 * dibatasi: kode pengawas diperiksa dengan `hash_equals` atas kode
 * terenkripsi (murah, tetapi 10^6 tebakan tetap harus dipersempit), token
 * lewat satu kueri berindeks, dan sandi lama lewat `Hash::check` (~100 ms,
 * bcrypt). Tanpa pembatas, satu skrip dapat mencoba ribuan kali per menit -
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
 * | sandi      | `user_id`    | 5/menit  |
 *
 * Kunci pintu tetap `slug + IP` meski kodenya tidak lagi milik ruang: yang
 * dibatasi adalah percobaan pada satu PINTU, dan yang mengetiknya berdiri di
 * depan pintu itu. Orangnya tidak dipakai sebagai kunci karena ia belum
 * diketahui sebelum percobaannya berhasil - kunci yang menuntut hasil dari
 * hal yang sedang dijaganya tidak bisa dipakai.
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
 * Ganti sandi sengaja TANPA pasangan per-IP. Argumen NAT-nya sama persis
 * dengan gerbang, dan pelakunya sudah tersahihkan - akun, bukan alamat.
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
 * domain: yang memakainya tiga domain berbeda - Facility (pintu ruang),
 * Attendance (gerbang ujian dan ganti sandi), serta Question (Generate AI) -
 * dan menaruhnya di salah satunya berarti yang lain mengimpornya lintas
 * domain untuk sesuatu yang bukan modelnya. `App\Support\TabelQuery` sudah
 * lebih dulu berdiri dengan alasan yang sama.
 */
final class PembatasLaju
{
    /** Nama limiter untuk pintu ruang. */
    public const PINTU = 'pintu-ruang';

    /** Nama limiter untuk gerbang ujian siswa. */
    public const GERBANG = 'gerbang-ujian';

    /** Nama limiter untuk Generate AI di builder soal. */
    public const AI = 'ai-soal';

    /** Nama limiter untuk usulan koreksi AI di laman koreksi uraian. */
    public const AI_KOREKSI = 'ai-koreksi';

    /** Nama limiter untuk ganti sandi mandiri. */
    public const SANDI = 'ganti-sandi';

    /** Nama limiter untuk laman pindai presensi `/hadir/{kode}`. */
    public const PINDAI = 'pindai-presensi';

    /** Nama limiter untuk kiriman skor permainan waktu luang. */
    public const LUANG = 'luang-skor';

    /** Nama limiter untuk laporan pelanggaran peramban siswa. */
    public const LAPOR = 'lapor-pelanggaran';

    /** Nama limiter untuk pencarian ruang & kursi publik `/ruang/{slug}`. */
    public const RUANG = 'ruang-publik';

    /** Percobaan cari ruang per IP, per menit. */
    public const RUANG_PER_IP = 20;

    /** Pesan saat pencarian ruang melampaui batas. */
    public const PESAN_RUANG = 'Terlalu banyak pencarian dari jaringan ini. Tunggu satu menit, lalu coba lagi.';

    /**
     * Permintaan AI per Guru, per menit.
     *
     * Sepuluh, dan angkanya dipilih dari cara laman itu dipakai: Guru menekan
     * Generate, membaca hasilnya, menyunting, lalu menekan lagi untuk soal
     * berikutnya. Sepuluh per menit sudah lebih cepat daripada membaca satu
     * soal. Yang tersisa di atasnya bukan pemakaian wajar melainkan klik
     * ganda, skrip, atau tab yang tertinggal menembak berulang.
     *
     * Ini pembatas UANG, bukan pembatas keamanan: setiap permintaan ditagih
     * ke akun penyedia milik sekolah.
     */
    public const AI_PER_GURU = 10;

    /** Pesan saat seorang Guru melampaui batas permintaan AI-nya. */
    public const PESAN_AI = 'Terlalu banyak permintaan AI. Tunggu satu menit, lalu coba lagi.';

    /**
     * Usulan koreksi AI per Guru, per menit.
     *
     * Lebih tinggi dari `AI_PER_GURU` karena bentuk pemakaiannya berbeda:
     * satu klik "Koreksi dengan AI" mengirim SATU jawaban, dan Guru yang
     * mengoreksi tiga puluh jawaban berturut-turut menekannya tiga puluh kali.
     * Batas sepuluh per menit akan menghadangnya di jawaban kesebelas.
     *
     * Dua puluh tetap membatasi yang perlu dibatasi: klik ganda, skrip, dan
     * tab yang tertinggal. Ini pembatas UANG juga - setiap usulan ditagih ke
     * akun penyedia milik sekolah.
     */
    public const AI_KOREKSI_PER_GURU = 20;

    /**
     * Percobaan kode pengawas per pasangan tautan + IP, per menit.
     *
     * Lima, dan bukan angka yang lebih longgar: pengawas mengetik kode ini
     * sekali, dan lima kesalahan dalam satu menit berarti ia salah membaca
     * kartunya sendiri, atau tautan yang dibukanya bukan untuk ruang tempat
     * ia berdiri.
     *
     * Sebelum 2026-11-07 alasan yang ditulis di sini berbeda - "kartunya
     * bukan untuk pintu itu", karena kodenya tercetak pada kartu yang sama
     * dengan tautannya. Itu tidak lagi berlaku: kartu pintu kini hanya
     * memuat tautannya, dan kodenya dipegang orangnya.
     *
     * Angkanya TIDAK berubah karenanya, karena yang menentukan angka ini
     * bukan asal kodenya melainkan ruangnya: satu percobaan berarti
     * membandingkan kode terhadap satu-dua kandidat - lihat
     * `PencocokKodePengawas`, yang menyaring kandidatnya lewat penugasan
     * ruang sehingga jumlahnya tidak tumbuh seiring jumlah pengawas sekolah.
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

    /**
     * Percobaan ganti sandi per AKUN, per menit.
     *
     * Lima, dan alasannya sama dengan `GERBANG_PER_SISWA`: pemilik sandi
     * mengetiknya dengan benar pada percobaan pertama atau kedua, dan lima
     * kesalahan dalam satu menit berarti yang mengetik bukan pemiliknya.
     *
     * ---------------------------------------------------------------------
     * Mengapa ini ada, padahal pelakunya sudah tersahihkan
     * ---------------------------------------------------------------------
     * Justru itu skenarionya: sesi yang dicuri dipakai MEMPELAJARI sandi
     * korban, supaya pencurinya bisa masuk lagi setelah sesi lamanya mati.
     * Tanpa pembatas, satu sesi yang dicuri adalah oracle bcrypt tanpa batas
     * - dan setiap tebakan menagih ~100 ms CPU server ujian.
     *
     * Yang membedakannya dari pintu dan gerbang cuma satu: penebaknya sudah
     * melewati login. Batasnya tetap perlu.
     */
    public const SANDI_PER_SISWA = 5;

    /** Pesan saat satu akun melampaui batas percobaan ganti sandinya. */
    public const PESAN_SANDI = 'Terlalu banyak percobaan. Tunggu satu menit, lalu coba lagi.';

    /**
     * Permintaan pindai per IP, per menit.
     *
     * Tiga ratus, dan angka itu dihitung dalam PERMINTAAN, bukan dalam
     * SISWA - itulah yang membuat perkiraan "32 pindai" salah.
     *
     * Satu siswa menghasilkan DUA permintaan: satu saat laman `/hadir/{kode}`
     * dibuka, satu saat tombol "Saya hadir di ruang ini" ditekan. Satu ruang
     * berisi 32 anak karena itu 64 permintaan dalam beberapa detik, dan satu
     * NAT sekolah biasanya melayani lebih dari satu ruang ujian sekaligus.
     * Dua ratus akan menolak ruang ketiga tepat pada menit pertama.
     *
     * ---------------------------------------------------------------------
     * Mengapa hanya per-IP, tanpa pasangan per-kode atau per-ruang
     * ---------------------------------------------------------------------
     * Berbeda dari pintu ruang, yang dijaga di sini BUKAN angka enam digit
     * - kodenya enam belas karakter dari alfabet tiga puluh satu, sehingga
     * menebaknya bukan risiko yang bisa dijaga pembatas laju. Yang tersisa
     * hanyalah satu risiko: satu alamat yang menembakkan tebakan beruntun ke
     * `/hadir/{apa saja}`. Pembatas per-IP menutupnya tanpa perlu kunci
     * kedua.
     *
     * Kunci per-ruang TIDAK bisa dipakai, dan ini penting: satu ruang
     * memang menerima puluhan permintaan dalam hitungan detik, dan pembatas
     * per-ruang akan menolak anak-anak yang justru sedang mengantre di depan
     * pintu.
     */
    public const PINDAI_PER_IP = 300;

    /** Pesan saat satu jaringan melampaui batas percobaan pindainya. */
    public const PESAN_PINDAI = 'Terlalu banyak pindai dari jaringan ini. Tunggu satu menit, lalu coba lagi.';

    /**
     * Kiriman skor permainan per SISWA, per menit.
     *
     * ---------------------------------------------------------------------
     * Enam, dan angkanya dipilih dari cara permainannya dimainkan
     * ---------------------------------------------------------------------
     * Satu permainan yang jujur berlangsung belasan sampai ratusan detik, dan
     * setiap permainan menghasilkan SATU kiriman. Enam per menit sudah jauh
     * lebih cepat daripada bermain sungguhan - ia hanya tercapai oleh anak
     * yang terbentur seketika lalu menekan "Main lagi" berulang, dan itu pun
     * masih muat.
     *
     * Yang benar-benar dijaga bukan kredensial melainkan tabel yang tumbuh
     * sepanjang jeda: tanpa batas, satu peramban dapat menulis ribuan baris
     * dalam satu menit, dan papan peringkat yang dipenuhi kiriman satu orang
     * berhenti bermakna bagi seluruh kelas.
     *
     * Kuncinya per AKUN, bukan per IP - alasannya sama dengan `kunciAi()`:
     * satu sekolah keluar lewat satu NAT, dan yang memiliki skornya adalah
     * akun.
     */
    public const LUANG_PER_SISWA = 6;

    /** Pesan saat kiriman skor melampaui batas. */
    public const PESAN_LUANG = 'Terlalu banyak kiriman skor. Tunggu satu menit, lalu coba lagi.';

    /** Nama limiter untuk denyut keadaan lomba dua pemain. */
    public const LOMBA = 'luang-lomba';

    /**
     * Denyut lomba per SISWA, per menit.
     *
     * ---------------------------------------------------------------------
     * Seratus, dan NOL KELONGGARAN adalah bug yang ditulis ulang di sini
     * ---------------------------------------------------------------------
     * Batas pertama (empat puluh) sama persis dengan kebutuhan satu layar:
     * satu denyut tiap 1,5 detik berarti empat puluh permintaan per menit.
     * Batas yang sama dengan kebutuhan berarti SETIAP lomba menyentuh
     * plafonnya, dan satu percobaan ulang, satu tab yang tersambung lagi,
     * atau satu permintaan yang datang lebih cepat karena jamnya bergeser
     * langsung mendapat 429 - yang di layar tampak seperti lomba yang diam.
     *
     * Seratus memberi ruang dua setengah kali lipat. Tab yang tertinggal di
     * latar belakang tetap bisa melewatinya (tab tersembunyi memang tetap
     * memanggil), dan itulah yang perlu dijaga - tetapi yang dijaga bukan
     * lomba yang SAH.
     *
     * Kuncinya per AKUN, bukan per IP: seluruh sekolah keluar lewat satu NAT,
     * dan membatasi per IP berarti satu anak yang lupa menutup tab memblokir
     * seluruh lab.
     */
    public const LOMBA_PER_SISWA = 100;

    /** Pesan saat denyut lomba melampaui batas - dibaca kode, bukan orang. */
    public const PESAN_LOMBA = 'Lomba ini terlalu sering dimuat ulang. Tunggu sebentar, lalu segarkan.';

    /** Nama limiter untuk denyut papan pantau publik `/pantau/{slug}/denyut`. */
    public const PANTAU = 'pantau-publik';

    /**
     * Denyut papan pantau per IP, per menit.
     *
     * ---------------------------------------------------------------------
     * Enam puluh, dan angkanya berasal dari frekuensi halaman itu sendiri
     * ---------------------------------------------------------------------
     * Halaman memanggil denyut tiap LIMA detik, jadi satu layar yang terbuka
     * memakai dua belas permintaan per menit. Enam puluh berarti lima layar
     * di satu jaringan - dan itu memang keadaannya: papan ini dibuka di
     * lorong, beberapa orang sekaligus, sering dari satu Wi-Fi sekolah.
     *
     * Yang dijaga bukan kredensial melainkan beban: alamat ini di luar
     * login, ia menyentuh `exam_attempts` dan `session_reports`, dan tanpa
     * batas satu skrip sederhana menjadikannya alat pembebanan atas basis
     * data sekolah - tepat saat seluruh sekolah sedang mengerjakan ujian.
     *
     * Kuncinya per IP, BUKAN per slug: slug adalah alamat yang dibagikan,
     * dan membatasi per slug berarti satu orang yang membuka lima tab
     * memblokir seluruh sekolah yang membaca papan yang sama.
     */
    public const PANTAU_PER_IP = 60;

    /** Pesan saat denyut melampaui batas - dibaca halaman, bukan orang. */
    public const PESAN_PANTAU = 'Papan ini terlalu sering dimuat ulang dari jaringan ini. Tunggu satu menit, lalu segarkan.';

    /** Nama limiter untuk membuka kode kelas wali - `wireframe-pantau-wali.html`. */
    public const WALI = 'wali-kelas';

    /**
     * Percobaan kode kelas wali per IP, per menit.
     *
     * Lima, sama dengan pintu ruang dan gerbang ujian: orang yang memegang
     * kodenya mengetik benar pada percobaan pertama atau kedua, dan lima
     * kesalahan dalam satu menit berarti yang mengetik bukan pemegangnya.
     *
     * ---------------------------------------------------------------------
     * Mengapa per IP saja tidak cukup, dan pembatas kedua ada
     * ---------------------------------------------------------------------
     * Enam angka = satu juta kemungkinan, dan pada lima percobaan per menit
     * satu alamat IP butuh 138 hari. Tetapi penyerang tidak memakai satu IP.
     * Yang lebih murah lagi: kode itu berlaku untuk SELURUH kelas sekaligus,
     * jadi satu tebakan benar membuka tiga puluh anak.
     *
     * Karena itu ada batas kedua per (slug, kelas). Angkanya jauh lebih
     * longgar - dua puluh per menit, sebab satu kelas memang sah dicoba
     * oleh banyak wali murid sekaligus dari jaringan sekolah yang sama -
     * tetapi ia menutup satu hal yang tidak bisa ditutup batas per-IP:
     * penebakan dari banyak IP untuk SATU kelas.
     */
    public const WALI_PER_IP = 5;

    /** Percobaan kode per (slug, kelas), per menit - lihat `WALI_PER_IP`. */
    public const WALI_PER_KELAS = 20;

    /** Pesan saat satu jaringan melampaui batas percobaan kodenya. */
    public const PESAN_WALI = 'Terlalu banyak percobaan kode. Tunggu satu menit, lalu coba lagi.';

    /** Pesan saat satu KELAS menerima terlalu banyak percobaan. */
    public const PESAN_WALI_KELAS = 'Kelas ini menerima terlalu banyak percobaan kode. Tunggu satu menit, lalu minta kode baru ke wali kelas.';

    /** Nama limiter untuk masuk kios lab `/lab/{slug}/{nomor}`. */
    public const KIOS = 'kios-lab';

    /**
     * Percobaan masuk kios per AKUN, per menit.
     *
     * ---------------------------------------------------------------------
     * Sepuluh, dan yang dijaga bukan kredensial
     * ---------------------------------------------------------------------
     * Kios tidak menebak sandi: anak sudah login sebelum menekan tombolnya.
     * Yang dibatasi adalah percobaan berulang pada kursi yang salah - anak
     * yang mengetik nomor PC temannya berulang kali, atau skrip yang
     * mencoba semua nomor di satu ruang.
     *
     * Sepuluh per menit jauh lebih longgar daripada pemakaian wajar: satu
     * anak salah duduk sekali, membaca pesannya, lalu pindah - dan itu dua
     * percobaan. Yang tersisa di atasnya bukan pemakaian, melainkan
     * penebakan.
     *
     * Kuncinya per AKUN, bukan per IP: seluruh lab keluar lewat satu NAT,
     * dan membatasi per IP berarti satu anak yang salah kursi memblokir
     * tiga puluh lima anak lain di ruangan yang sama.
     */
    public const KIOS_PER_SISWA = 10;

    /** Pesan saat percobaan kios melampaui batas. */
    public const PESAN_KIOS = 'Terlalu banyak percobaan kursi. Panggil pengawas ruang.';

    /** Batas laporan pelanggaran per siswa, per menit. */
    public const LAPOR_PER_SISWA = 60;

    /** Pesan saat laporan pelanggaran melampaui batas. */
    public const PESAN_LAPOR = 'Terlalu banyak laporan dalam satu menit.';

    public static function kunciLapor(int|string $userId): string
    {
        return "lapor:{$userId}";
    }

    public static function kunciKios(int|string $userId): string
    {
        return "kios:{$userId}";
    }

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
     * Kunci pembatas pindai presensi: per IP, tanpa pasangan per-ruang.
     *
     * Lihat `PINDAI_PER_IP` tentang mengapa per-ruang justru salah di sini.
     */
    public static function kunciPindaiIp(string $ip): string
    {
        return "pindai.ip:{$ip}";
    }

    /**
     * Kunci pembatas AI: per GURU, bukan per IP.
     *
     * Satu ruang guru memakai satu NAT sekolah, sehingga pembatas per-IP
     * akan membuat dua puluh Guru berbagi satu jatah - dan yang pertama
     * menyusun soal menghabiskan kuota seluruh ruangan. Yang membelanjakan
     * uang sekolah adalah AKUN, jadi akun pula yang dibatasi.
     */
    public static function kunciAi(int|string $userId): string
    {
        return "ai:{$userId}";
    }

    /**
     * Kunci pembatas ganti sandi: per AKUN, bukan per IP.
     *
     * Argumennya sama dengan `kunciAi()` dan dengan gerbang: seluruh sekolah
     * keluar lewat satu NAT. Yang memiliki sandi adalah akun.
     */
    public static function kunciSandi(int|string $userId): string
    {
        return "sandi:{$userId}";
    }

    /** Kunci pembatas kiriman skor: per AKUN - lihat `LUANG_PER_SISWA`. */
    public static function kunciLuang(int|string $userId): string
    {
        return "luang:{$userId}";
    }

    /** Kunci pembatas denyut lomba: per AKUN - lihat `LOMBA_PER_SISWA`. */
    public static function kunciLomba(int|string $userId): string
    {
        return "lomba:{$userId}";
    }

    /**
     * Kunci percobaan kode wali per IP.
     *
     * Slug sekolah ikut di dalamnya, dan itu bukan kerapian: satu sekolah
     * yang papannya ramai tidak boleh menghabiskan jatah sekolah lain dari
     * jaringan yang sama - dan di lab komputer, "jaringan yang sama" berarti
     * seluruh kabupaten.
     */
    public static function kunciWaliIp(string $slug, string $ip): string
    {
        return "wali-ip:{$slug}|{$ip}";
    }

    /**
     * Kunci percobaan kode wali per KELAS.
     *
     * Menutup satu hal yang tidak bisa ditutup batas per-IP: penebakan dari
     * banyak IP untuk satu kelas. Lihat `WALI_PER_IP`.
     */
    public static function kunciWaliKelas(string $slug, int|string $kelasId): string
    {
        return "wali-kelas:{$slug}|{$kelasId}";
    }

    /** Kunci pencarian ruang & kursi publik per IP + slug sekolah. */
    public static function kunciRuangIp(string $slug, string $ip): string
    {
        return "ruang-ip:{$slug}|{$ip}";
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
