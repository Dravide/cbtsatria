# Beban dan kapasitas — Fase 9

Berkas ini memuat **hasil uji beban apa adanya**, **prosedur 300 konkuren** yang wajib dijalankan di server target sebelum peluncuran, dan **tujuh kegagalan yang ditemukan saat uji itu dikerjakan** — semuanya dicatat karena masing-masing terbaca seperti masalah kapasitas padahal bukan, dan karena prosedur ini akan dijalankan orang lain di server lain.

---

## 1. Target yang mengikat

`prd.md` §19.3:

| Skenario | Konkuren | P95 |
|---|---|---|
| Penyimpanan jawaban | 300 siswa | ≤ 2 detik |
| Pemuatan halaman operasional | 300 siswa | ≤ 3 detik |

Dua angkanya **berbeda**, dan itu bukan salah ketik: halaman operasional memuat papan monitoring yang jauh lebih berat daripada satu penyimpanan jawaban. Menyamakan keduanya menjadi 2 detik akan menghasilkan laporan yang menyatakan gagal padahal lulus — dan laporan yang salah lebih buruk daripada tidak ada laporan, karena ia mengirim orang mengejar penyebab yang tidak ada.

Angka itu **belum dibuktikan**. Bagian 3 menjelaskan mengapa ia tidak bisa dibuktikan di mesin ini, dan apa yang harus dilakukan di server target.

---

## 2. Mengapa mesin pengembangan tidak bisa membuktikannya

Uji beban Fase 9 dijalankan di mesin Windows pengembangan dengan `php artisan serve`.

**`php artisan serve` adalah server PHP satu-utas.** Ia menangani satu permintaan pada satu waktu; permintaan kedua menunggu yang pertama selesai. Yang diukur karenanya bukan throughput, melainkan **biaya satu permintaan** — dan pada konkurensi berapa pun di atas satu, yang tumbuh adalah antreannya, bukan bebannya.

Mesinnya juga dipakai bersama proyek lain, dan basis datanya adalah MySQL 8.4.3 pada port 3307 yang sama-sama dipakai pengembangan.

Kesimpulan yang jujur: **setiap angka dari mesin ini adalah batas atas biaya per-permintaan, bukan kapasitas server.** Menyatakan lulus P95 dari sini akan menjadi bukti palsu, dan bukti palsu tentang kapasitas adalah bukti yang paling mahal — ia baru terbantah pada pagi pertama ujian sungguhan, saat tiga ratus siswa menekan tombol mulai bersamaan.

---

## 3. Prosedur 300 konkuren di server target

Dijalankan di VPS Linux dengan Nginx + php-fpm, sesudah `docs/PELUNCURAN.md` selesai diikuti.

### 3.1 Persiapan

```bash
cd /var/www/cbt

# Muat lingkungan server target (jangan menuliskan DB_* di sini: hanya nama
# basis data bebannya yang perlu ditimpa).
export CBT_BASE=https://cbt.sekolah.sch.id

# Basis data uji yang berisi salinan data ujian sungguhan. JANGAN
# menjalankan uji beban pada basis data produksi: yang ditulis uji ini
# adalah attempt dan jawaban sungguhan.
DB_DATABASE=cbt_beban php artisan migrate --seed --force

# 300 akun siswa + penempatan + presensi terverifikasi + token.
# Nama basis datanya diperiksa seeder ini; lihat catatan di bawah.
DB_DATABASE=cbt_beban CBT_BEBAN_SISWA=300 php artisan db:seed --class=SeederBeban --force
```

`SeederBeban` mencetak token sesinya dan menulis daftar 300 username ke `storage/app/private/beban-akun.txt`. **Catat tokennya** — ia berubah setiap kali seeder dijalankan, dan token yang basi menghasilkan 300 penolakan gerbang yang terbaca sebagai masalah kapasitas.

**Kapasitas kursi dinaikkan seeder ini bila perlu, dan itu wajib pada 300.** Sekolah contoh punya dua ruang berkapasitas 32 kursi — 64 kursi — dan `PenempatanService::sebar()` memeriksa kapasitas **sebelum** menulis satu baris pun: pada 300 peserta ia menolak seluruhnya dengan "Kapasitas sesi S-1 hanya 64 kursi untuk 300 peserta". Kegagalannya bersih, tetapi uji bebannya tidak pernah berjalan. Seeder sekarang menaikkan `capacity_override` setiap ruang sesi sampai totalnya memuat seluruh peserta, dibagi rata antar ruang, dan **mencetak** perubahan itu (`Kapasitas sesi S-1 dinaikkan: 64 -> 312 kursi`). Angka kursinya dibaca dari keluaran itu saat menafsirkan hasilnya.

**Jalankan ujinya segera sesudah menyiapkan.** Seeder itu menggeser jendela sesinya ke `now()`, dan jendela itu tidak menunggu: uji yang dijalankan beberapa jam sesudahnya akan gagal seluruhnya dengan `KelayakanUjian` menjawab "Sesi ini sudah berakhir" — dengan benar. Bila itu terjadi, jalankan ulang seeder (ia menggeser jendela **dan** menerbitkan token baru) lalu ulangi dengan token yang baru dicetak itu.

Hal yang sama berlaku untuk **kesempatan ujian**: jalan kedua atas basis data yang sudah pernah dipakai uji beban akan menemukan attempt `dikirim` dari jalan pertama dan menolak setiap peniru dengan "Anda sudah memakai seluruh kesempatan ujian ini" — gejalanya juga 302 pada `/kerjakan`, tidak dapat dibedakan dari jendela yang tertutup. Seeder menghapus attempt milik akun `beban*` lebih dulu, jadi menjalankannya ulang sudah cukup; rinciannya di bagian 5(g).

**Dan singkirkan `public/hot` sementara, lalu kembalikan sesudahnya.** Berkas itu menandai dev server Vite sedang berjalan, dan selama ia ada paket Inertia mengira SSR hidup lalu mengirim setiap halaman ke endpoint SSR yang tidak ada — ~1,4 detik per halaman, tepat pada halaman operasional yang sedang diukur. Sejak `.env` memuat `INERTIA_SSR_ENABLED=false` biaya itu hilang, tetapi menyingkirkan berkasnya tetap benar untuk uji beban: yang diukur seharusnya jalur produksi, dan di produksi berkas itu tidak ada. Di server target langkah ini tidak berlaku — `public/hot` di sana memang tidak boleh ada (`docs/PELUNCURAN.md` memeriksanya pasca-deploy).

### 3.2 Menjalankan

```bash
CBT_TOKEN=<kode dari keluaran SeederBeban> \
CBT_SISWA=300 \
CBT_PUTARAN=10 \
CBT_ADMIN=admin \
CBT_PAPAN="/admin/events/1/monitoring,/admin/events/1/presensi,/admin/laporan" \
node scripts/cbt-uji-beban.mjs
```

Daftar akunnya dibaca otomatis dari `storage/app/private/beban-akun.txt`; `CBT_SISWA_DAFTAR` boleh dipakai untuk menimpanya.

Di mesin pengembangan, `CBT_BASE=http://127.0.0.1:8782` dan konkurensinya kecil — lihat bagian 4.

### 3.3 Yang dibaca dari keluarannya

Skripnya melaporkan p50/p95/p99 untuk dua skenario yang PRD §19.3 sebut, beserta jumlah galat, dan menandai setiap baris `OK` atau `LEWAT` terhadap sasarannya masing-masing.

Kode keluarnya juga bisa dibaca mesin, dan masing-masing menuntut tindakan yang berbeda:

| Kode | Arti | Tindakan |
|---|---|---|
| 0 | Selesai, tidak ada galat, p95 di bawah sasaran | — |
| 1 | Ada permintaan yang gagal | Ukurannya **tidak sah**; jelaskan dan perbaiki galatnya dulu (bagian 5) |
| 2 | Tidak bisa dijalankan (tidak ada token, daftar akun kurang, dll.) | Perbaiki persiapan di 3.1 |
| 3 | Selesai tanpa galat, tetapi p95 lewat sasaran **pada konkurensi ≥ 300** | Remediasi 3.4, satu langkah pada satu waktu |

**Kode 1 diperiksa lebih dulu, dan itu urutan yang penting.** Jalan 300 yang pertama menghasilkan 145 galat **dan** p95 lewat sasaran sekaligus; vonis yang keluar hanya "p95 lewat sasaran". Itu kesimpulan yang salah — dari 775 sampel yang tersisa, tidak satu pun diambil saat server benar-benar melayani tiga ratus peniru. Pengukuran yang tidak sah tidak boleh menghasilkan vonis kapasitas, apa pun angka p95-nya.

Ambang vonis itu bisa disetel lewat `CBT_KONKUREN_MENGIKAT` bila server target menyepakati lingkungan uji dengan angka berbeda — tapi bawaannya tetap 300, dan menurunkannya **bukan** cara membuat uji ini lulus: yang berubah hanya apakah skripnya berani menjatuhkan vonis, bukan apakah p95-nya memenuhi sasaran.

Yang menentukan lulus tidaknya adalah **p95 pada konkurensi 300**, bukan rata-ratanya: rata-rata yang memenuhi target sementara p95-nya tidak berarti sebagian siswa menunggu jauh lebih lama daripada yang lain, dan merekalah yang menekan tombol dua kali.

Kode 3 **hanya** keluar pada `CBT_SISWA >= 300`. Di bawah itu, p95 yang lewat sasaran adalah keadaan yang diharapkan pada `php artisan serve` satu-utas (bagian 2), dan menjadikannya kegagalan berarti setiap jalan pengembangan berakhir merah tanpa ada yang salah. Pada konkurensi kecil skripnya mencetak catatan, bukan penilaian lulus/tidak.

Galat sambungan dilaporkan dengan sebabnya (`login/0 (ECONNREFUSED)`), bukan sebagai status 0 telanjang — pada uji beban, "aplikasinya menolak" dan "soketnya ditutup" adalah dua kesimpulan yang berlawanan.

### 3.4 Kalau tidak lulus

Urutannya dari yang paling murah:

1. **Naikkan `pm.max_children` di pool php-fpm.** Ini penyebab paling umum, dan gejalanya khas: p50 bagus, p99 buruk, dan `php-fpm.log` memuat `server reached pm.max_children`.
2. **Aktifkan Redis** (`docs/PELUNCURAN.md` bagian Redis). Sesi dan cache pindah dari MySQL, dan itu melepas koneksi basis data untuk jalur jawaban.
3. **Tambahkan worker `queue:work`** bila ekspor laporan berjalan bersamaan dengan ujian.
4. Baru sesudah itu menambah RAM/CPU server.

### 3.5 Mengakhiri — membersihkan peniru

Setelah angka dicatat, penirunya dibersihkan dengan mode yang sama di skrip yang sama:

```
CBT_BERSIHKAN=1 DB_DATABASE=cbt_beban CBT_BASE=http://127.0.0.1:8782 \
  node scripts/cbt-uji-beban.mjs
```

Tanpa `CBT_SISWA` dan `CBT_PUTARAN`: mode ini **tidak menjalankan uji apa pun**, hanya menghapus 300 akun `beban*` beserta seluruh turunannya lalu keluar. Akun contoh dari `DatabaseSeeder` tidak disentuh.

Hapus, bukan sekadar tinggalkan, karena aturan (g) berbalik arah: fixture yang tertinggal membuat seeder berikutnya berjalan di atas puing, dan puing itu terbaca sebagai kegagalan uji — attempt lama memakan jatah kesempatan, gejalanya `kerjakan/302`, penyebabnya bukan yang tertulis di layar. Basis data beban yang sudah selesai dipakai tidak punya nilai yang lebih besar daripada keadaan bersih.

Tiga hal yang membuatnya aman dibandingkan `DROP DATABASE`:

- **Nama basis data diperiksa lebih dulu.** Harus berakhiran `_beban`; nama lain ditolak dengan kode keluar 2. Itu pengaman terakhir bila `DB_DATABASE` salah ketik atau terwarisi dari shell lain.
- **Dihapus fisik (`forceDelete`), bukan lunak.** `User` memakai `SoftDeletes`, jadi `delete()` hanya mengisi `deleted_at`: barisnya tetap ada, indeks unik `username` tetap terpegang, dan seeder berikutnya gagal dengan galat duplikat — puing dengan bentuk lain. Sebagian besar anak tabelnya menurun lewat `cascadeOnDelete` pada `student_id`/`exam_participant_id`: `exam_participants` → `exam_attempts`
  → `attempt_questions` → `student_answers` → `essay_gradings`, ditambah `attendance_records`, `class_enrollments`, `event_participants`, dan `student_profiles`. Dua yang **tidak** menurun dengan sendirinya dihapus eksplisit: `activity_logs` (`SET NULL` — tanpa itu barisnya tertinggal tanpa pemilik) dan `exam_incidents` (`RESTRICT` — bila ada insiden untuk peniru, penghapusannya gagal dan berkata begitu; itu memang disengaja, insiden adalah catatan yang dibaca orang).
- **Keluarannya diperiksa, bukan dipercaya.** Hitungan diambil dari basis data yang dimaksud sebelum dan sesudah, dan `non_beban_tersisa` menuntut satu jawaban: apakah saya baru saja menghapus data sekolah sungguhan.

Keluaran pada mesin pengembangan (2026-11-05):

```
sebelum: akun=300 peserta=300 attempt=0
sesudah: akun=0 akun_dihapus=300 log_dihapus=1262 non_beban_tersisa=19
```

Sembilan belas itu akun contoh `DatabaseSeeder` — jumlah yang sama seperti sebelum uji beban disiapkan. Fixture dibuat lagi kapan saja dengan `SeederBeban`.


---

## 4. Hasil pada mesin pengembangan

Mesin: Windows 11, PHP 8.3.27, MySQL 8.4.3 pada port 3307, `php artisan serve` (satu utas), basis data uji `cbt_beban` terpisah, 40 akun peniru. Keluaran mentahnya ada di `docs/bukti/` — setiap baris di bawah disalin dari berkas itu.

Penyimpanan jawaban:

| Konkurensi | n | min | p50 | p95 | maks | p95 vs 2000 ms |
|---|---|---|---|---|---|---|
| 1 (dasar) | 5 | 130 ms | 155 ms | 170 ms | 170 ms | OK |
| 20 | 100 | 2994 ms | 3311 ms | 3740 ms | 3870 ms | LEWAT |
| 40 | 200 | 5673 ms | 7236 ms | 7842 ms | 8308 ms | LEWAT |

Papan operasional (`/admin/events/1/monitoring`, `/admin/events/1/presensi`, `/admin/laporan`, 5 putaran masing-masing):

| Konkurensi | n | min | p50 | p95 | maks | p95 vs 3000 ms |
|---|---|---|---|---|---|---|
| 1 | 15 | 129 ms | 207 ms | 387 ms | 387 ms | OK |
| 20 | 15 | 138 ms | 218 ms | 399 ms | 399 ms | OK |
| 40 | 15 | 164 ms | 231 ms | 475 ms | 475 ms | OK |

Papan operasional sengaja diukur pada tiap konkurensi, bukan sekali: ia dimuat berurutan SESUDAH seluruh peniru selesai, jadi ia menjawab pertanyaan yang berbeda dari penyimpanan jawaban — berapa biaya satu halaman terberat ketika server sudah sepi. Jawabannya sama di ketiganya (~200-230 ms), dan itu memang yang diharapkan: yang mengubah biaya halaman itu adalah apakah server sedang melayani seseorang, bukan berapa banyak yang baru saja dilayani.

**Pada 300 peniru, server satu-utanya menyerah menampung sambungan** — 131 dari 300 login ditolak di lapisan soket (`login/0 (ECONNREFUSED)`), ditambah 5 `baca-peserta` dan 1 `kerjakan` yang bernasib sama — sehingga angka p95-nya **tidak mengukur aplikasi di bawah beban** dan sengaja tidak ditabelkan di sini. Rinciannya di bagian 5(e), keluaran mentahnya di `docs/bukti/uji-beban-05-konkurensi-300.txt`. Itu justru bukti terkuat bahwa angka 300 harus diambil di server target.

Satu angka dari jalan 300 itu tetap berguna dan layak dicatat, justru karena ia **tidak** terpengaruh oleh penolakan sambungan: **papan operasional keluar di 504 ms** (p95, n=15), dan itu diukur setelah seluruh peniru selesai pada server yang sudah sepi. Ia memuat papan monitoring lintas ruang untuk 300 peserta terdaftar — empat kali beban konkurensi 40 — dan tetap di bawah seperlima sasarannya.

**Yang benar-benar dibuktikan angka ini, dan yang tidak.**

Yang dibuktikan: **biaya satu permintaan penyimpanan jawaban adalah ~150 ms** pada konkurensi 1, dan **halaman operasional ~200-230 ms** — dua-duanya jauh di bawah sasarannya, dan keduanya milik jalur yang sungguh dipakai: login nyata, token nyata, butir yang dibaca dari halaman kerjakan siswa itu sendiri.

Yang **tidak** dibuktikan: apa pun tentang 300 konkuren. Perhatikan bahwa p50 naik dari 155 ms (konkurensi 1) ke 3311 ms (konkurensi 20) — **dua puluh satu kali lipat untuk dua puluh kali konkurensi**. Itu tanda satu-utas, bukan tanda aplikasi yang mahal: seluruh permintaan berbaris di belakang satu proses, dan yang terukur adalah panjang antreannya. Pada 40 konkurensi polanya berlanjut.

**Halaman operasional justru angka yang paling berguna di sini**: ia dimuat berurutan setelah seluruh peniru selesai, pada server yang sudah sepi, dan keluar di bawah 500 ms pada ketiga konkurensi dengan nol galat. Itu batas biaya per-permintaan untuk halaman terberat di aplikasi ini — dan halaman itu yang memuat papan monitoring lintas ruang, jalur yang di Fase 9 diperbaiki dari N+1 menjadi jumlah kueri tetap.

**Nol galat HTTP** pada ketiga jalannya. Itu bukan bukti kapasitas — ia hanya berarti tidak ada yang gagal pada konkurensi yang diuji.

**Angka pada tabel ini berbeda dari catatan pertama sesi ini**, karena ketiganya dijalankan ulang setelah dua koreksi. Yang pertama ambang halaman operasional — dari 2 detik menjadi 3 detik sesuai `prd.md` §19.3. Yang kedua jauh lebih besar dan dijelaskan di bagian 5(f): `public/hot` yang tertinggal membuat setiap halaman Inertia membayar ~1,4 detik percobaan SSR yang tidak pernah ada. Sebelum koreksi itu, papan operasional terbaca 652-1700 ms; sesudahnya 200-400 ms. Penyimpanan jawaban hampir tidak terpengaruh (ia memang balasan JSON, bukan halaman), dan itu sebabnya kekeliruan ini bisa bertahan lama tanpa ketahuan: yang paling lambat justru jalur yang tidak diukur uji beban.

---

## 5. Kegagalan yang ditemukan, dan mengapa dicatat

Tujuh, dan **tidak satu pun di antaranya masalah kapasitas** — padahal ketujuhnya, di layar, terbaca seperti kapasitas yang kurang atau seperti aplikasi yang lambat. Dipertahankan di sini karena prosedur ini akan dijalankan orang lain di server lain, dan masing-masing akan tampak sama meyakinkannya di sana.

Dua yang terakhir — (f) dan (g) — muncul saat keluaran uji beban disiapkan untuk diserahkan, bukan saat ujinya dijalankan. Keduanya menemukan **bug sungguhan**: (f) di aplikasi, (g) di seeder yang menyiapkan ujinya. Tidak satu pun akan ketahuan dari satu jalan uji beban yang langsung berhasil — keduanya menuntut jalan kedua.

Keluaran (a) dan (b) ada di `docs/bukti/uji-beban-00-*.txt`, keluaran (e) di `docs/bukti/uji-beban-05-konkurensi-300.txt` — semuanya disimpan apa adanya meski gagal. Uji beban yang tidak pernah menyimpan kegagalannya tidak bisa dibedakan dari uji beban yang tidak pernah mencapai server.

**(a) Server pengembangan menunjuk basis data yang salah.** Peniru yang disiapkan di `cbt_beban` menjalankan login terhadap server pada 8781, yang melayani `cbt_satria`. Hasilnya: 20 permintaan gagal `baca-peserta/302` — login **berhasil** (302 ke `/siswa`), tetapi halaman jadwal mengalihkan kembali ke `/login` karena sesinya milik basis data yang tidak punya akun itu. Tidak ada galat HTTP sama sekali. Perbaikannya: jalankan server kedua yang menunjuk basis data bebannya (di sini port 8782), atau jalankan uji beban terhadap server yang memang melayani `cbt_beban`.

**(b) Jendela sesi sudah tertutup.** Sesi disemai dengan jendela `now()-30 menit` sampai `now()+2 jam`, dan uji beban baru dijalankan beberapa jam sesudahnya — pada pukul 11:16 UTC, sementara `hard_end_at`-nya 07:16. Hasilnya: 20 permintaan gagal `kerjakan/302` — login berhasil, gerbang juga 302, tetapi `/kerjakan` mengalihkan kembali ke gerbang karena `KelayakanUjian` menjawab "Sesi ini sudah berakhir pukul 07:16" — dengan benar. `SeederBeban` sekarang menggeser jendela sesinya ke `now()` dan menerbitkan ulang tokennya (karena `valid_until` token mengikuti `hard_end_at` sesinya), sehingga uji cukup dijalankan segera sesudah menyiapkan.

**(c) Bentuk isian jawaban yang salah.** Rancangan pertama skripnya mengirim `{ jawaban: {...} }`; `JawabanService::simpan()` membaca kunci **`isi`**. Nama yang salah akan tersimpan sebagai jawaban kosong **tanpa galat**: permintaannya tetap 200 dan waktunya tetap terukur — jadi angkanya bagus dan artinya salah. Skripnya sekarang membaca `jenis` setiap butir dan menyusun `isi` menurut jenisnya (`opsi` untuk pilihan ganda, `nilai` untuk angka, `pasangan`, `urutan`, `teks`).

Ketiganya — (a) sampai (c) — juga alasan `SeederBeban` ada sebagai kelas tersendiri: fixture uji beban menuntut keadaan yang **seragam** (semua peserta terverifikasi, semua punya kursi, belum ada yang mulai), sementara `DatabaseSeeder` sengaja membuat keadaan yang **beragam** untuk halaman contoh. Salah satu peserta yang izin akan muncul di laporan sebagai satu galat gerbang, dan satu galat sudah cukup untuk membuat laporannya tidak bisa dipercaya.

**(d) Kursinya kurang, dan kegagalannya bersih sehingga mudah terlewat.** Sekolah contoh punya dua ruang berkapasitas 32 kursi — 64 kursi — sementara prosedur 3.1 menyuruh menyemai 300 siswa. `PenempatanService::sebar()` memeriksa kapasitas **sebelum** menulis satu baris pun dan menolak seluruhnya dengan satu baris di antara keluaran seeding yang panjang:

```
Kapasitas sesi S-1 hanya 64 kursi untuk 300 peserta.
```

Gagalnya benar dan bersih — tidak ada penempatan setengah jadi. Tetapi uji beban yang mengikat kriteria keluar Fase 9 berhenti di langkah pertamanya, dan yang tersisa di layar adalah peringatan yang terbaca seperti keluhan administratif. `SeederBeban` sekarang menaikkan `capacity_override` tiap ruang sesi sampai totalnya memuat seluruh peserta (`Kapasitas sesi S-1 dinaikkan: 64 -> 312 kursi`), dan `tests/Feature/SeederBebanTest.php` menguncinya. **Yang dikunci test itu justru yang penting**: jalan keluarnya harus penyediaan kursi, bukan pelonggaran pemeriksaan di `sebar()` — yang terakhir bekerja juga, dan itu bahayanya, karena ia membuka penempatan berlebih di jalur Admin sungguhan.

**(e) Server satu-utas menolak sambungan, dan itu bukan aplikasinya yang menolak.** Pada jalan 300, 131 dari 300 login gagal dengan `login/0 (ECONNREFUSED)`, ditambah 5 `baca-peserta/0` dan 1 `kerjakan/0` yang bernasib sama. `artisan serve` menangani satu permintaan pada satu waktu dengan backlog soket yang kecil; peniru ke-132 dan seterusnya tidak mendapat sambungan sama sekali — mereka tidak pernah sampai ke aplikasi. Menaikkan konkurensi lebih jauh tidak mengubah apa pun kecuali jumlah yang ditolak.

Perhatikan yang **tidak** ada di daftar kegagalan itu: `kerjakan/302`. Jalan 300 yang pertama menghasilkan 143 galat dengan campuran yang berbeda — 135 `login/0`, 2 `baca-peserta/0`, dan 6 `kerjakan/302` — dan enam `kerjakan/302` itulah sisa dari jendela sesi yang sudah menyempit. Jalan terakhir bersih dari itu, karena seeder menggeser jendelanya dan membersihkan attempt lama (butir g). Artinya seluruh 137 kegagalan sekarang murni di lapisan soket, dan tidak satu pun berasal dari aplikasi yang menolak permintaan yang sah.

Temuan ini juga membuka dua kekurangan di skripnya, dan keduanya diperbaiki: kegagalan sambungan dulu **melempar** dari `fetch()` di luar `try` sehingga yang muncul adalah tumpukan panggilan Node dan temuannya hilang sama sekali (sekarang dikembalikan sebagai status 0 beserta sebabnya); dan vonis "p95 lewat sasaran" dulu keluar **meski** ada 143 permintaan gagal — yang berarti vonis kapasitas diambil dari pengukuran yang tidak sah, karena sisa sampelnya tidak diambil saat server benar-benar melayani 300 peniru. Sekarang galat diperiksa lebih dulu: ada galat → `TIDAK SAH` (kode 1), dan vonis lulus/tidak baru keluar bila seluruh permintaannya terjawab. Jalan 300 yang terakhir keluar dengan kode 1 persis seperti yang seharusnya.

**Itulah sebabnya angka 300 di bagian 6 tetap wajib diambil di server target.** Di sini yang terukur pada 300 adalah titik di mana `artisan serve` menyerah menampung sambungan, bukan titik di mana aplikasinya mulai lambat.

**(f) `public/hot` yang tertinggal membuat setiap halaman Inertia menunggu SSR yang tidak ada — dan itu bug aplikasi, bukan bug ujinya.** Ini satu-satunya temuan di bagian ini yang memperbaiki kode, dan gejalanya muncul justru saat angka uji beban disiapkan untuk diserahkan: papan operasional terbaca ~1700 ms, padahal pada jalan sebelumnya 652 ms, tanpa satu pun perubahan pada halaman itu.

Menelusurinya sampai dasar memakan beberapa percobaan, dan tiga pengukuran itulah yang mempersempitnya:

- `/up` 17 ms sementara `/login` 1400 ms — **bootstrap aplikasi sehat**, jadi biayanya ada di jalur halaman, bukan di kerangka Laravel.
- Permintaan Inertia dengan `X-Inertia: true` (yang melewati Blade) juga 130 ms walau `public/hot` ada — jadi biayanya ada di **render Blade**, tepatnya `@vite`.
- `/login` 1400 ms ketika `public/hot` berisi URL, tetapi **130 ms ketika berkasnya dikosongkan** dan ketika berkasnya tidak ada. Isi berkasnya yang menentukan, bukan keberadaannya.

Sebabnya ada di paket Inertia, bukan di aplikasi ini. `config/inertia.php` tidak ada di repositori ini, sehingga bawaannya yang berlaku — dan bawaannya adalah `ssr.enabled = true`. `Inertia\Ssr\HttpGateway::dispatch()` membaca `Vite::isRunningHot()`: selama `public/hot` ada, ia menyimpulkan "SSR berjalan di dev server Vite" dan mengirim **setiap halaman sebagai POST ke `{VITE_URL}/__inertia_ssr`**. Endpoint itu tidak ada — aplikasi ini tidak punya SSR sama sekali: tidak ada `resources/js/ssr.tsx`, tidak ada skrip `build:ssr`, tidak ada `bootstrap/ssr/`. Jadi setiap halaman menunggu permintaan itu gagal sebelum jatuh kembali ke render sisi klien.

Perbaikannya satu baris di `.env` dan `.env.example`: `INERTIA_SSR_ENABLED=false` — pernyataan tegas bahwa aplikasi ini tidak memakai SSR. Sesudahnya `/login` 140 ms **dengan `public/hot` tetap ada**, dan papan operasional kembali ke 200-400 ms.

Dua hal tentang temuan ini yang membuatnya layak ditulis panjang:

**Ia tidak akan pernah terasa di produksi.** Di sana `public/hot` tidak ada (lihat `docs/PELUNCURAN.md`: `test -e public/hot` adalah salah satu pemeriksaan pasca-deploy), sehingga `HttpGateway` berhenti lebih awal dan tidak membayar apa pun. Artinya yang dirugikan hanya pengembangan — tetapi di sana ia membebani **setiap pemuatan halaman**, dan justru pada halaman terberat yang sedang diukur.

**Ia ditemukan karena angkanya berubah tanpa sebab.** Kalau uji beban hanya dijalankan sekali dan angkanya langsung ditulis ke dokumen, kekeliruan ini akan ikut terarsip sebagai hasil, dan orang di server target akan mengejar `pm.max_children` untuk masalah yang tidak ada di sana. Selisih 652 ms terhadap 1700 ms pada halaman yang tidak diubah adalah satu-satunya petunjuk yang muncul, dan ia muncul karena dua kalinya diukur.

**(g) Seeder menjanjikan "belum ada yang mulai", tetapi tidak menjaganya — dan gejalanya sama persis dengan (b).** Dijalankan dua kali, `SeederBeban` meninggalkan satu attempt `dikirim` per peniru dari jalan pertama. Jalan kedua menemukan attempt itu, menghitungnya sebagai kesempatan yang sudah terpakai (batasnya 1 untuk ujian contoh), lalu `KelayakanUjian` menolak dengan "Anda sudah memakai seluruh kesempatan ujian ini (1×)".

Gejalanya di uji beban adalah **302 pada `/kerjakan` untuk setiap peniru** — tidak dapat dibedakan dari jendela sesi yang sudah tertutup (b), yang ditangani seeder ini dengan menggeser jendela. Dua penyebab berbeda yang bergejala sama itulah yang menghabiskan waktu paling banyak di sesi ini: pengecekannya berturut-turut menunjuk basis data yang salah, jendela yang tertutup, dan baru akhirnya kesempatan yang habis.

`SeederBeban` sekarang menghapus attempt milik akun `beban*` lebih dulu — dan hanya milik akun itu, karena peserta contoh dari `DatabaseSeeder` justru perlu tetap punya attempt untuk mengisi halaman hasil dan riwayat pelanggaran di layar contoh. Penghapusannya menurun berantai lewat foreign key ke `attempt_questions` → `student_answers` → `essay_gradings`, lalu ke `exam_results` dan `exam_violations`, sehingga peserta kembali ke "belum mulai" seutuhnya tanpa menyentuh penempatan atau presensinya. `tests/Feature/SeederBebanTest.php` menguncinya: menjalankan seeder dua kali harus membuat gerbangnya kembali terbuka, dan itu diperiksa lewat `KelayakanUjian` — bukan sekadar lewat jumlah baris attempt.

**Ini juga alasan bagian 3.1 menegaskan "jalankan ujinya segera sesudah menyiapkan".** Peringatan itu ditulis untuk jendela sesi, tetapi berlaku sama untuk kesempatan ujian: keduanya dikembalikan ke keadaan terbuka oleh seeder, dan keduanya tidak menunggu.

---

## 6. Yang harus dilakukan di server target sebelum peluncuran

1. Jalankan prosedur 3.1–3.2 dengan `CBT_SISWA=300`.
2. Catat p95 kedua skenario di berkas ini, apa adanya. Bila skripnya keluar **1**, galatnya harus dijelaskan lebih dulu — angka p95 dari jalan yang bergalat tidak sah.
3. Bila skripnya keluar **3** (p95 ≤ sasaran tidak terpenuhi: 2000 ms untuk simpan jawaban, 3000 ms untuk halaman operasional), jalankan urutan 3.4 **satu langkah pada satu waktu** dan ulangi ujinya. Mengubah `pm.max_children` dan mengaktifkan Redis sekaligus membuat penyebabnya tidak bisa diketahui.
4. Baru sesudah lulus, jalankan drill pemulihan `scripts/backup/cbt-uji-pulih.sh` terhadap basis data uji di server itu — lihat `docs/PELUNCURAN.md`.
5. Bersihkan penirunya dengan `CBT_BERSIHKAN=1` (bagian 3.5) — 300 akun uji beban yang tertinggal akan muncul di laporan sebagai peserta sungguhan.
