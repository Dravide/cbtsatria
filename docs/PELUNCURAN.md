# Peluncuran — VPS Linux, Nginx, php-fpm

Panduan ini mengantar aplikasi dari `git`/arsip ke satu VPS yang melayani ujian sungguhan. Target yang dipilih: **VPS Linux (Ubuntu 24.04 LTS), Nginx + php-fpm 8.3, MySQL 8.4 di mesin yang sama.**

Urutannya sengaja begini: **Nginx dan php-fpm disiapkan lebih dulu, aplikasi terakhir.** Alasannya bukan kerapian — setiap kali aplikasi diletakkan lebih dulu, `/up` diuji pada server yang belum bisa melayani apa pun, dan galat yang muncul menyesatkan.

> **Satu symlink, dan hanya satu:** `public/storage` → `storage/app/public`, dibuat oleh `php artisan storage:link` (langkah 6). Isinya **hanya logo sekolah** — berkas yang memang harus tampil di `/p/{slug}`, halaman pintu ruang yang dibuka pengawas tanpa login, dan yang tercetak di kop surat.
>
> Media soal, berita acara, dan ekspor **tidak** ikut ke sana. Ketiganya tinggal di `storage/app/private` dan **hanya** keluar lewat controller yang memeriksa hak akses (`MediaSoalController`, `BerkasLaporanController`). Yang harus dijaga karena itu bukan "jangan pernah membuat symlink", melainkan: **tidak ada berkas ujian yang boleh ditulis ke disk `public`.** Symlink-nya membuka seluruh isi `storage/app/public` ke dunia, jadi direktori itu hanya boleh berisi logo.

---

## 1. Paket sistem

```bash
sudo apt update
sudo apt install -y nginx mysql-server redis-server certbot python3-certbot-nginx \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
    php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath
```

Ekstensi yang wajib ada dan mudah terlupa:

| Ekstensi | Dipakai oleh |
|---|---|
| `php8.3-mbstring`, `php8.3-xml`, `php8.3-gd` | `phpoffice/phpspreadsheet` (impor soal, ekspor laporan) |
| `php8.3-bcmath` | perhitungan nilai |
| `php8.3-zip` | impor XLSX |
| `php8.3-intl` | format tanggal/waktu Indonesia |

MySQL di mesin yang sama dengan aplikasi **hanya boleh** bila VPS-nya punya RAM 2 GB ke atas dan `innodb_buffer_pool_size` disetel (`/etc/mysql/mysql.conf.d/mysqld.cnf`):

```ini
innodb_buffer_pool_size = 1G
max_connections         = 300
```

`max_connections` 300 karena uji beban PRD §19.3 menuntut 300 siswa bersamaan; bawaannya 151 dan akan habis sebelum php-fpm habis.

---

## 2. Pengguna dan direktori

```bash
sudo adduser --disabled-login --gecos "" cbt
sudo mkdir -p /var/www/cbt
sudo chown -R cbt:www-data /var/www/cbt
```

Aplikasi berjalan sebagai `cbt`, Nginx membaca `public/` sebagai `www-data`. Grup `www-data` dipakai supaya berkas yang ditulis aplikasi (media soal, ekspor) tetap bisa dibaca Nginx untuk penyajian lewat controller — controller-nya membaca berkas dari disk, bukan dari webroot, tetapi mengunduh berkas yang dimiliki `cbt` dengan mode `0600` juga akan gagal.

---

## 3. Nginx — `/etc/nginx/sites-available/cbt`

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name cbt.sekolah.sch.id;

    # Diisi certbot pada langkah 8.
    location /.well-known/acme-challenge/ { root /var/www/html; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name cbt.sekolah.sch.id;

    root /var/www/cbt/public;
    index index.php;

    # Dibuat certbot pada langkah 8. Kedua baris ini WAJIB ada sebelum
    # `nginx -t` dijalankan pada server baru - tanpa certbot, Nginx menolak
    # mulai karena berkasnya belum ada.
    ssl_certificate     /etc/letsencrypt/live/cbt.sekolah.sch.id/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cbt.sekolah.sch.id/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    # --- Batas unggahan ---------------------------------------------------
    # Media soal (gambar/audio) dan impor XLSX lewat sini. Bawaan Nginx 1 MB
    # dan galatnya adalah 413 yang TIDAK sampai ke Laravel, sehingga tidak
    # muncul di log aplikasi sama sekali - guru hanya melihat "gagal".
    client_max_body_size 64M;

    # --- Header keamanan --------------------------------------------------
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options        "SAMEORIGIN" always;
    add_header Referrer-Policy        "strict-origin-when-cross-origin" always;
    # HSTS hanya setelah HTTPS terbukti benar - lihat catatan di langkah 8.
    # add_header Strict-Transport-Security "max-age=31536000" always;

    # --- Berkas statis ----------------------------------------------------
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    location /favicon.ico { access_log off; log_not_found off; }

    # --- Berkas di disk publik (SYMLINK ke storage/app/public) ------------
    # Isinya HANYA logo sekolah. Logonya boleh SVG, dan SVG adalah XML yang
    # bisa memuat <script>. Dibuka sebagai dokumen ia berjalan di ORIGIN
    # APLIKASI INI - Content-Type dari ekstensi tidak menolong. Yang menutup
    # celahnya adalah CSP di bawah: gambar tetap tampil di <img>, tetapi
    # logikanya tidak bisa berjalan.
    location ^~ /storage/ {
        add_header Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; img-src data:" always;
        add_header X-Content-Type-Options nosniff always;
        expires 7d;
        access_log off;
    }

    # --- Aplikasi ---------------------------------------------------------
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;

        fastcgi_read_timeout 120s;

        # JANGAN menyetel `fastcgi_param` apa pun di sini. `snippets/fastcgi-php.conf`
        # sudah menyusun SCRIPT_FILENAME dan PATH_INFO dengan benar; menimpanya
        # (mis. dengan `$document_root$fastcgi_script_name`) adalah penyebab
        # klasik "Access denied" pada Nginx + Laravel.
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

Aktifkan dan uji:

```bash
sudo ln -s /etc/nginx/sites-available/cbt /etc/nginx/sites-enabled/cbt
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

---

## 4. php-fpm — `/etc/php/8.3/fpm/pool.d/cbt.conf`

Ini penyetelan yang paling menentukan hasil uji beban, dan yang paling sering dibiarkan bawaan.

```ini
[cbt]
user = cbt
group = www-data

listen = /run/php/php8.3-fpm.sock
listen.owner = www-data
listen.group = www-data

; --- Jumlah proses -------------------------------------------------------
; Satu proses PHP melayani satu permintaan pada satu waktu. Dengan 300 siswa
; bersamaan, kurang dari ~40 proses berarti permintaan mengantre dan p95 naik
; meskipun tiap permintaan hanya butuh 80 ms.
;
; Rumusnya dari RAM: (RAM tersedia - MySQL - Redis) / memori per proses PHP.
; Aplikasi ini memakai ~64 MB per proses saat menangani laporan XLSX.
; Pada VPS 4 GB: (4096 - 1024 - 128) / 64 = ~46.
pm = dynamic
pm.max_children      = 48
pm.start_servers     = 8
pm.min_spare_servers = 8
pm.max_spare_servers = 16

; Anak yang menganggur lebih lama dari ini dimatikan. Menjaga anak tetap
; hidup saat malam tidak ada gunanya.
pm.max_requests = 500

; Proses lambat dicatat ke log - inilah yang membaca gejalanya saat uji beban
; gagal dan `pm.max_children` benar.
request_slowlog_timeout = 5s
slowlog = /var/log/php8.3-fpm-slow.log
request_terminate_timeout = 120s

; Penjadwal menuntut timezone yang sama dengan aplikasi. Aplikasi ini memakai
; UTC (config/app.php), dan semua jendela sesi disimpan dalam UTC.
php_admin_value[date.timezone] = UTC
php_admin_value[memory_limit]  = 256M
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size]       = 64M
php_admin_value[opcache.enable]      = 1
php_admin_value[opcache.validate_timestamps] = 0
```

`opcache.validate_timestamps = 0` mempercepat secara nyata, tetapi **berarti setiap penyebaran wajib `sudo systemctl reload php8.3-fpm`.** Tanpa reload, kode lama yang jalan dan penyebaran terlihat seperti tidak terjadi.

```bash
sudo systemctl restart php8.3-fpm
```

---

## 5. `.env` produksi

Salin dari `.env.example` lalu ubah. **Yang wajib berbeda dari contoh:**

```ini
APP_NAME="CBT Satria"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cbt.sekolah.sch.id
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cbt_satria
DB_USERNAME=cbt
DB_PASSWORD=<sandi acak>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_LIFETIME=120

CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=smtp
MAIL_HOST=<smtp sekolah>
MAIL_PORT=587
MAIL_ENCRYPTION=tls

# Aplikasi ini tidak memakai SSR. Lihat catatan di bawah.
INERTIA_SSR_ENABLED=false
```

Catatan yang tidak boleh dilewati:

- **`APP_DEBUG=false`.** Dengan `true`, setiap galat 500 menampilkan `.env` beserta kredensialnya ke layar siswa.
- **`APP_URL` harus `https://`.** Berkas `build` di halaman Inertia dan tautan pintu pengawas memakai nilai ini; `http://` menghasilkan tautan yang dialihkan, dan pada sebagian peramban seluler itu menghentikan unggahan foto berita acara.
- **`SESSION_SECURE_COOKIE=true`** hanya setelah HTTPS benar-benar hidup. Menyetelnya sebelum itu membuat **tidak ada yang bisa login** — dan gejalanya adalah formulir login yang terus kembali ke halaman login tanpa galat.
- **`APP_KEY`** disalin dari `.env` lama bila memindahkan instalasi, atau dibuat sekali dengan `php artisan key:generate`. **Jangan pernah membuat ulang `APP_KEY` pada instalasi berjalan** — kolom terenkripsi dan seluruh sesi menjadi tidak terbaca.
- **`DB_PORT=3306`** di server, bukan 3307. Port 3307 adalah keanehan mesin pengembangan.
- **`INERTIA_SSR_ENABLED=false`** sudah ada di `.env.example`, dan tetap ditulis di sini karena bawaannya `true`. Di server ini tidak akan terasa — `public/hot` tidak ada, sehingga paket Inertia berhenti lebih awal dan tidak membayar apa pun. Ia disetel tegas supaya **tidak ada** keadaan, termasuk `public/hot` yang keliru tersalin, yang membuat aplikasi ini menunggu endpoint SSR yang tidak pernah ada. Latarnya di `docs/BEBAN.md` §5(f).

Izin berkas:

```bash
cd /var/www/cbt
sudo chown cbt:www-data .env
sudo chmod 640 .env
sudo chown -R cbt:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

---

## 6. Penyebaran

Urutan ini dijalankan sebagai `cbt`, dari `/var/www/cbt`. Yang **urutan**nya penting diberi catatan; sisanya rutin.

```bash
sudo -u cbt -i
cd /var/www/cbt

# 1. Dependensi PHP tanpa paket pengembangan.
composer install --no-dev --optimize-autoloader --no-interaction

# 2. Aset frontend - dibangun DI SERVER ini, bukan disalin dari mesin dev.
npm ci
npm run build

# 3. Skema basis data. `migrate`, BUKAN `migrate:fresh` - yang terakhir
#    menghapus seluruh data ujian yang sedang berjalan.
php artisan migrate --force

# 4. Symlink disk publik. WAJIB - tanpa ini logo sekolah 404 walaupun
#    kolom `logo_path`-nya terisi. Yang ada di dalamnya hanya logo:
#    media soal, berita acara, dan ekspor tetap di storage/app/private.
php artisan storage:link

# 5. Cache konfigurasi/rute/view.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 6. Muat ulang worker antrean supaya memakai kode baru (lihat langkah 7).
php artisan queue:restart

# 7. Pendaftaran sekolah pertama - HANYA pada penyebaran pertama.
#
#    JANGAN jalankan `db:seed --class=DatabaseSeeder` di produksi: seeder itu
#    membuat satu sekolah CONTOH lengkap dengan 19 akun percobaan dan sandi
#    "password". Di mesin dev ia berguna (laman-laman Fase 2 baru bisa dinilai
#    setelah diisi); di server ia berarti satu sekolah palsu di daftar.
php artisan sekolah:daftarkan \
    --kode=SMKN1 \
    --nama="SMK Negeri 1 Sukamaju" \
    --npsn=20512345 \
    --admin-nama="Budi Santoso" \
    --admin-username=admin.smkn1
```

Sandi Admin dicetak **sekali** dan tidak tersimpan dalam bentuk terbaca. Salin sebelum terminalnya ditutup. Bila hilang, ganti lewat `/admin/pengguna` sesudah login — akun itu sudah ada, jadi menjalankan perintahnya lagi tidak akan mengubah sandinya.

Selengkapnya — opsi, arti idempotennya, dan apa yang sengaja tidak dibuat — di `docs/PENYEDIAAN.md`.

**Yang mudah salah:**

- `npm run build` **harus** dijalankan di server, karena Vite menanamkan `APP_URL` saat build. Aset yang dibangun di mesin dev dengan `http://127.0.0.1:5179` akan menghasilkan halaman yang mencoba memuat Hot Module Replacement dari port yang tidak ada.
- `php artisan config:cache` menangkap `.env` **saat itu**. Setiap perubahan `.env` sesudahnya menuntut `php artisan config:cache` lagi — mengubah `.env` tanpa itu akan tampak seperti perubahan yang tidak berpengaruh apa-apa.
- Pada penyebaran berikutnya, jalankan langkah 1–6 lalu **`sudo systemctl reload php8.3-fpm`** (karena `opcache.validate_timestamps=0`).
- `php artisan storage:link` hanya perlu **sekali**, pada penyebaran pertama. Symlink-nya bertahan antar-penyebaran. Tanpa symlink itu, unggah logo tetap berhasil dan `logo_path` tetap terisi — yang rusak hanya gambarnya, dan ia 404 tanpa galat di log. Kalau logo tampil sebagai kotak rusak di server, periksa itu lebih dulu.

---

## 7. Worker antrean — systemd

Ekspor laporan, dan pekerjaan latar lain, berjalan di worker. **Tanpa unit ini, ekspor laporan menggantung selamanya berstatus `menunggu`** dan tidak ada galat yang menjelaskannya.

`/etc/systemd/system/cbt-queue.service`:

```ini
[Unit]
Description=CBT - queue worker
After=network.target mysql.service

[Service]
Type=simple
User=cbt
Group=www-data
WorkingDirectory=/var/www/cbt
Restart=always
RestartSec=5

; `--max-time` membuat worker berhenti sendiri setiap jam dan dijalankan ulang
; oleh Restart=always. Ini menutup kebocoran memori pustaka PHP yang lambat
; (terutama pembuat XLSX/PDF) - tanpa itu worker yang hidup berhari-hari
; perlahan memakan seluruh RAM VPS.
ExecStart=/usr/bin/php8.3 artisan queue:work --sleep=3 --tries=3 --max-time=3600

; SIGTERM pada `queue:restart` dan pada `systemctl stop` - worker
; menyelesaikan job yang sedang jalan lalu berhenti.
KillSignal=SIGTERM
TimeoutStopSec=90

StandardOutput=append:/var/log/cbt-queue.log
StandardError=append:/var/log/cbt-queue.log

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now cbt-queue
sudo systemctl status cbt-queue
```

`--tries=3` dan bukan lebih: ekspor laporan yang gagal tiga kali hampir selalu gagal karena sebab yang tidak akan hilang (berkas terlalu besar, data rusak), dan mencoba selamanya berarti antrean penuh oleh satu laporan yang tidak akan pernah selesai.

---

## 8. Penjadwal — satu baris cron

```bash
sudo crontab -u cbt -e
```

```cron
* * * * * cd /var/www/cbt && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1
```

Yang dijalankan perintah itu (`routes/console.php`):

| Tugas | Jadwal | Kalau tidak jalan |
|---|---|---|
| `ujian:tutup-kedaluwarsa` | tiap menit | Siswa yang menutup tab tetap `berjalan` selamanya, nilainya tidak pernah muncul, dan papan monitoring menampilkan anak yang sebenarnya sudah selesai sebagai baris yang hilang. |
| `laporan:pangkas-ekspor` | 03:00 harian | Ekspor lama menumpuk di disk sampai penuh, dan disk penuh menghentikan penyimpanan jawaban. |
| `session:garbage-collect` | 03:30 harian | Tabel `sessions` tumbuh tanpa batas. |
| `queue:prune-failed` | mingguan | Tabel `failed_jobs` tumbuh tanpa batas. |

Periksa sekali sesudah dipasang:

```bash
sudo -u cbt php /var/www/cbt/artisan schedule:list
```

Keluaran kosong atau galat berarti cron belum benar, dan **tidak ada galat lain yang akan muncul** — penjadwal Laravel gagal dengan diam.

---

## 9. HTTPS — certbot

**Sebelum certbot, pastikan DNS `cbt.sekolah.sch.id` sudah menunjuk ke IP VPS ini.** Certbot memvalidasi dengan mengambil berkas dari server itu, jadi DNS yang belum menyebar akan gagal dengan pesan yang menyebut masalah jaringan, bukan masalah DNS.

```bash
sudo certbot --nginx -d cbt.sekolah.sch.id
sudo systemctl list-timers | grep certbot   # pembaruan otomatis
```

Sesudah HTTPS hidup:

```bash
# Baru sekarang, dan hanya sekarang:
sed -i 's/^APP_URL=.*/APP_URL=https:\/\/cbt.sekolah.sch.id/' /var/www/cbt/.env
sed -i 's/^SESSION_SECURE_COOKIE=.*/SESSION_SECURE_COOKIE=true/' /var/www/cbt/.env
sudo -u cbt php /var/www/cbt/artisan config:cache
sudo systemctl reload php8.3-fpm
```

HSTS (`Strict-Transport-Security`) diaktifkan **setelah** satu minggu berjalan tanpa insiden; ia tidak bisa dibatalkan dengan cepat pada peramban yang sudah menerimanya.

---

## 10. Pemeriksaan sesudah penyebaran

```bash
# 1. Kesehatan aplikasi.
curl -sf https://cbt.sekolah.sch.id/up && echo OK

# 2. Halaman login benar-benar dirender (bukan 500 yang terlihat seperti 200).
curl -s https://cbt.sekolah.sch.id/login | grep -o 'data-page="app"' | head -1

# 3. Aset terbangun: tidak boleh ada "localhost:5179" atau "127.0.0.1:5179".
grep -rl "5179" public/build 2>/dev/null && echo "GAGAL: aset dibangun di mesin dev"
test -e public/hot && echo "GAGAL: public/hot tersisa - setiap halaman Inertia akan menunggu SSR yang tidak ada (~1,4 detik), dan asetnya dilayani dev server yang tidak jalan"

# 4. Disk publik terpasang, dan hanya berisi logo. Tanpa symlink ini,
#    unggah logo berhasil tetapi gambarnya 404 - tanpa satu pun galat.
test -e public/storage || echo "GAGAL: jalankan php artisan storage:link"
ls storage/app/public

# 5. Log.
tail -n 50 storage/logs/laravel.log
sudo journalctl -u cbt-queue -n 50
```

`/up` hanya memeriksa bahwa aplikasi bisa boot. Ia **tidak** memeriksa basis data, disk, atau antrean — jadi `curl -sf /up` yang lulus tidak berarti ujian bisa berjalan. Empat pemeriksaan berikutnya yang menutup itu.

Satu ujian sungguhan dari akun siswa percobaan, sampai jawaban tersimpan, adalah pemeriksaan terakhir yang berarti. Tidak ada penggantinya.

---

## 11. Redis — disiapkan, belum diaktifkan

**Kode aplikasi tidak perlu diubah untuk mengaktifkan Redis.** `config/database.php`, `config/cache.php`, `config/queue.php`, dan `config/session.php` semuanya sudah membaca `env()`; aplikasi ini menguji keduanya, dan yang berjalan hari ini adalah `database`.

Redis belum diaktifkan karena pada satu server dengan ~300 siswa, `database` sudah cukup — dan menambahkan Redis menambah satu titik gagal, satu proses untuk dipantau, dan satu cara lagi kehilangan sesi saat ia mati. Yang mengubah kalkulasi itu adalah uji beban (lihat `docs/BEBAN.md` §3.4): bila p95 gagal **dan** `pm.max_children` sudah benar, Redis adalah langkah berikutnya.

### Kalau nanti diaktifkan

**1. Pasang ekstensi PHP-nya lebih dulu — ini yang paling sering dilupakan.**

```bash
sudo apt install -y php8.3-redis
sudo systemctl restart php8.3-fpm
php -m | grep -i redis        # harus mencetak "redis"
```

`REDIS_CLIENT=phpredis` adalah bawaan `.env.example`, tetapi ekstensi `phpredis` **tidak** ikut terpasang bersama PHP di banyak image. Tanpa ekstensi itu, mengaktifkan Redis menghasilkan galat `Class "Redis" not found` **pada setiap permintaan** — bukan hanya pada permintaan yang memakai cache.

Bila yang terpasang `predis` (pustaka PHP murni) alih-alih ekstensinya, ubah `REDIS_CLIENT=predis` dan tambahkan `composer require predis/predis`. Keduanya bekerja; yang tidak bekerja adalah menulis `phpredis` sementara yang terpasang `predis`.

**2. Ubah `.env`:**

```ini
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

**3. Terapkan, dalam satu langkah tanpa jeda:**

```bash
cd /var/www/cbt
php artisan config:cache
php artisan queue:restart          # worker wajib mati: ia memegang koneksi lama
sudo systemctl reload php8.3-fpm   # sesi lama tidak dikenali sesudah ini
```

**4. Yang berubah bagi pengguna:** **semua orang logout sekali**, karena sesi lama ada di tabel `sessions` dan sesudah ini dibaca dari Redis. Lakukan di luar jam ujian. Siswa yang sedang mengerjakan saat pergantian akan kehilangan sesinya dan harus login ulang — jawabannya sendiri aman, karena tersimpan di basis data, bukan di sesi.

**5. Kebalikan (memutar balik) juga bekerja**, dengan urutan terbalik dan logout sekali lagi.

### Yang tidak berubah

Tidak ada satu baris pun kode yang perlu disunting. Tidak ada migrasi. Tidak ada perintah Artisan yang hanya berlaku untuk Redis. Itu memang tujuannya: keputusan "siapkan, jangan aktifkan" hanya berarti bila mengaktifkannya nanti benar-benar murah.

---

## 12. Cadangan dan pemulihan

Skripnya ada di `scripts/backup/`, dan cara memakainya ada di kepala berkas masing-masing:

| Berkas | Guna |
|---|---|
| `cbt-backup.sh` | Dump basis data (`--single-transaction`, tanpa mengunci tabel), `storage/app/private`, dan `.env` terpisah. |
| `cbt-pulih.sh` | Memulihkan satu arsip ke basis data yang **disebutkan eksplisit**; menolak target yang namanya tidak berakhiran `_test`/`_pulih`. |
| `cbt-uji-pulih.sh` | Drill: memulihkan ke basis data scratch, membandingkan jumlah baris tabel kunci, mencetak `PASS`/`FAIL`. |

Pemasangan:

```bash
sudo -u cbt mkdir -p /var/backups/cbt
sudo crontab -u cbt -e
```

```cron
# Cadangan harian pukul 02:00, simpan 14 hari.
0 2 * * * cd /var/www/cbt && ./scripts/backup/cbt-backup.sh >> /var/log/cbt-backup.log 2>&1
# Drill pemulihan bulanan - cadangan yang tidak pernah dipulihkan bukan cadangan.
0 3 1 * * cd /var/www/cbt && ./scripts/backup/cbt-uji-pulih.sh >> /var/log/cbt-pulih.log 2>&1
```

**Arsip cadangan harus disalin keluar dari VPS ini** (`rsync`, `rclone`, atau penyimpanan objek sekolah). Cadangan yang hanya ada di mesin yang sama tidak melindungi dari hal yang paling mungkin menghapusnya: mesin itu sendiri.

Kriteria keluar Fase 9 menuntut drill itu **dibuktikan minimal sekali**, dan buktinya ada di `docs/bukti/uji-pulih.txt` — keluaran drill yang dijalankan terhadap basis data throwaway, apa adanya.

---

## 13. Daftar periksa peluncuran

Sebelum sekolah pertama memakai sistem ini:

- [ ] `/up` menjawab 200 di `https://`
- [ ] Login berhasil, dan sesudah itu `storage/logs/laravel.log` tetap kosong
- [ ] `public/hot` **tidak ada**; tidak ada `5179` di `public/build`
- [ ] `php artisan schedule:list` mencetak keempat tugas
- [ ] `systemctl status cbt-queue` → `active (running)`
- [ ] Ekspor laporan dari Admin selesai (bukan menggantung `menunggu`)
- [ ] Unggah satu media soal 10 MB berhasil (membuktikan `client_max_body_size` dan `upload_max_filesize`)
- [ ] `public/storage` ada, dan logo sekolah tampil di `/admin/sekolah` sesudah diunggah
- [ ] `storage/app/public` hanya berisi `logos/` — tidak ada berkas ujian yang bocor ke disk publik
- [ ] `sekolah:daftarkan` dijalankan untuk sekolah sungguhan, dan **tidak ada sekolah contoh** di `/admin` (`db:seed` tidak pernah dijalankan di server ini)
- [ ] Admin sekolah mengganti sandi awalnya lewat `/admin/pengguna`
- [ ] Satu siswa percobaan menyelesaikan satu ujian dari login sampai jawaban tersimpan
- [ ] Drill pemulihan dijalankan di server ini dan mencetak `PASS`
- [ ] Uji beban 300 konkuren dijalankan (`docs/BEBAN.md` §3) dan p95 tercatat
- [ ] Cadangan harian terjadwal, dan arsipnya disalin keluar VPS
- [ ] `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `APP_URL=https://`

Butir yang belum dicentang adalah risiko yang belum diketahui, bukan sesuatu yang "nanti ketahuan sendiri".
