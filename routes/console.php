<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Penjadwal - Fase 9
|--------------------------------------------------------------------------
|
| Satu baris cron di server menjalankan seluruh daftar ini:
|
|     * * * * * cd /var/www/cbt && php artisan schedule:run >> /dev/null 2>&1
|
| Tanpa baris itu, SEMUA tugas di bawah tidak pernah berjalan - dan tidak ada
| satu pun galat yang muncul untuk memberitahukannya. Larangan yang sama
| berlaku untuk `queue:work`: lihat docs/PELUNCURAN.md.
|
| `withoutOverlapping()` dipakai di setiap tugas yang MENULIS, dan bukan
| `onOneServer()`. Yang terakhir menuntut penyimpanan kunci terpusat (Redis,
| database, atau memcached) yang berlaku lintas server - sedangkan keputusan
| Fase 9 adalah Redis disiapkan tetapi belum diaktifkan. Menuliskan
| `onOneServer()` sekarang berarti tugas yang gagal karena kuncinya tidak
| bisa diambil, dan gagalnya berbunyi sebagai pengecualian di log setiap
| menit. `withoutOverlapping()` bekerja dengan cache store apa pun yang
| sedang dipakai, dan cukup untuk satu server aplikasi.
|
| `withoutOverlapping()` penting secara khusus untuk `ujian:tutup-kedaluwarsa`:
| ia berjalan tiap MENIT, dan setiap eksekusinya mengubah baris pada tabel
| yang sedang ditulis siswa. Dua eksekusi yang tumpang tindih pada attempt
| yang sama berarti dua pemanggilan `tutupBilaKedaluwarsa()` - dan meski
| method itu idempotent (pemeriksaan `status !== berjalan`), penilaiannya
| tidak perlu dijalankan dua kali.
*/

// ---------------------------------------------------------------------------
// Penutup attempt kedaluwarsa - setiap menit
// ---------------------------------------------------------------------------
// `everyMinute()` karena yang dikejar bukan jadwal, melainkan kenyataan:
// `deadline_at` terlampaui terus-menerus sejak detik pertama ujian dimulai.
// Inilah yang membuat nilai seorang siswa muncul tanpa ia menyentuh apa pun -
// dan siswa yang menutup tabnya adalah yang paling membutuhkannya, karena
// tidak ada lagi permintaan yang datang dari perambaninya.
//
// Barisnya keluar dari filter sesudah ditutup, sehingga perintah yang sama
// pada menit berikutnya tidak menemukan apa pun. Yang tersisa hanya
// pemeriksaan indeks `attempt_kedaluwarsa_idx` - murah, dan itu memang
// tujuannya.
Schedule::command('ujian:tutup-kedaluwarsa')
    ->everyMinute()
    ->withoutOverlapping();

// ---------------------------------------------------------------------------
// Penutup sesi kedaluwarsa - setiap menit, ditambahkan 2026-11-14
// ---------------------------------------------------------------------------
// Satu tingkat DI ATAS perintah sebelumnya, dan keduanya dibutuhkan.
//
// `ujian:tutup-kedaluwarsa` menutup ATTEMPT; ia tidak menyentuh status
// sesinya. Sesi yang `hard_end_at`-nya lewat karena itu tetap "berjalan"
// sampai Admin menekan Tutup di laman jadwal - dan bila ia lupa, tab
// Berjalan di `/admin/events` terus memuat event yang ujiannya sudah
// selesai berhari-hari lalu. Tab itu menjawab "apa yang masih perlu saya
// kerjakan", dan jawaban itulah yang menjadi salah.
//
// Urutannya sengaja SESUDAH penutup attempt di berkas ini: sesi yang
// attempt-nya masih menggantung sebaiknya dinilai lebih dulu, dan keduanya
// berjalan pada menit yang sama tanpa saling menunggu (keduanya punya
// `withoutOverlapping()` sendiri).
Schedule::command('ujian:tutup-sesi')
    ->everyMinute()
    ->withoutOverlapping();

// ---------------------------------------------------------------------------
// Pangkas ekspor lama - setiap malam pukul 03:00
// ---------------------------------------------------------------------------
// Dini hari karena ia menghapus berkas: kalau ada Admin yang sedang mengunduh
// laporan, pukul tiga pagi adalah saat paling kecil kemungkinannya. Laporan
// yang lebih tua dari 30 hari hampir pasti tidak lagi dibutuhkan - dan
// kalau ternyata dibutuhkan, ia bisa dibuat ulang dari data yang masih ada
// (laporan adalah TURUNAN, bukan sumber).
Schedule::command('laporan:pangkas-ekspor')
    ->dailyAt('03:00')
    ->withoutOverlapping();

// ---------------------------------------------------------------------------
// Pangkas ekspor pengguna lama - setiap malam pukul 03:15
// ---------------------------------------------------------------------------
// Perintah KEDUA, bukan satu perintah untuk keduanya - lihat catatan di
// `PangkasEksporPengguna`. Yang penting di sini adalah ia ADA: ekspor pengguna
// meninggalkan berkas sebesar ekspor laporan, dan berkas itu tinggal di disk
// privat yang sama dengan berita acara.
//
// Pukul 03:15, lima belas menit sesudah pemangkasan laporan, supaya keduanya
// tidak berebut I/O di malam yang sama.
Schedule::command('pengguna:pangkas-ekspor')
    ->dailyAt('03:15')
    ->withoutOverlapping();

// ---------------------------------------------------------------------------
// Pangkas media soal draf - setiap malam pukul 03:20
// ---------------------------------------------------------------------------
// Gambar yang diunggah sebelum soalnya disimpan (draf) menumpuk di disk privat
// bila peramban ditutup sebelum Guru menekan Simpan. Lihat `PangkasMediaDraf`
// untuk mengapa batasnya 24 jam dan bukan satu jam.
//
// Pukul 03:20, di antara kedua pemangkasan ekspor, supaya ketiganya tidak
// berebut I/O pada malam yang sama.
Schedule::command('soal:pangkas-media-draf')
    ->dailyAt('03:20')
    ->withoutOverlapping();

// ---------------------------------------------------------------------------
// Sesi kedaluwarsa - setiap malam pukul 03:30
// ---------------------------------------------------------------------------// Tabel `sessions` tumbuh satu baris per peramban yang pernah membuka
// aplikasi, dan ratusan siswa yang lulus tidak pernah kembali untuk menutup
// sesinya. Bawaan Laravel juga memangkasnya lewat undian di tengah permintaan
// (2 dari 100), tetapi itu berarti pemangkasan terjadi pada request siswa yang
// kebetulan kena undian - dan pada ujian berjalan, itu pekerjaan yang tidak
// perlu.
//
// Setengah jam sesudah pemangkasan ekspor supaya keduanya tidak berebut I/O.
Schedule::command('session:garbage-collect')
    ->dailyAt('03:30');

// ---------------------------------------------------------------------------
// Bersihkan job gagal - setiap minggu
// ---------------------------------------------------------------------------
// `--hours=168` = tujuh hari. Job yang gagal disimpan lengkap dengan
// payloadnya, dan payload ekspor laporan memuat parameter penyaringnya. Yang
// lebih tua dari seminggu hampir pasti sudah ditangani Admin - atau memang
// tidak akan ditangani sama sekali, dan menahannya lebih lama hanya menambah
// baris di `failed_jobs`.
Schedule::command('queue:prune-failed --hours=168')->weekly();

/*
| CATATAN: `cache:prune-stale-tags` SENGAJA TIDAK dijadwalkan meski perintah
| itu ada di aplikasi ini. Bantuan perintahnya sendiri berbunyi "(Redis only)",
| dan Redis belum diaktifkan pada mesin ini maupun pada rencana peluncuran
| awal. Menjadwalkannya sekarang berarti satu tugas yang gagal diam-diam
| setiap malam - dan kegagalan yang tidak berbunyi adalah kegagalan yang tidak
| pernah diperbaiki. Penjadwalannya masuk ke docs/PELUNCURAN.md sebagai
| langkah yang menyertai pengaktifan Redis, bukan sebelumnya.
*/
