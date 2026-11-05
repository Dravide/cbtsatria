# CBT Satria — ujian berbasis komputer untuk sekolah

Aplikasi CBT multi-sekolah: bank soal, paket ujian, penempatan peserta, presensi pengawas, mesin ujian siswa, penilaian, dan pelaporan. Satu instalasi melayani banyak sekolah dengan data yang terpisah.

- **Panduan peluncuran** (VPS, Nginx, php-fpm, HTTPS, antrean, penjadwal, Redis, cadangan): [`docs/PELUNCURAN.md`](docs/PELUNCURAN.md)
- **Penyediaan sekolah** (mendaftarkan sekolah baru beserta Admin pertamanya): [`docs/PENYEDIAAN.md`](docs/PENYEDIAAN.md)
- **Beban dan kapasitas** (hasil uji beban, prosedur 300 konkuren): [`docs/BEBAN.md`](docs/BEBAN.md)
- **Bukti drill pemulihan**: [`docs/bukti/uji-pulih.txt`](docs/bukti/uji-pulih.txt)

## Persyaratan

| | Versi |
|---|---|
| PHP | 8.3+ dengan ekstensi `mbstring`, `xml`, `gd`, `zip`, `intl`, `bcmath` |
| Composer | 2.x |
| Node | 20+ |
| MySQL | 8.x |

## Menjalankan di mesin pengembangan

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

# Sesuaikan DB_* di .env, lalu:
php artisan migrate
php artisan db:seed

# Dua proses: server aplikasi dan Vite.
php artisan serve            # port dari SERVER_PORT (bawaan contoh: 8781)
npm run dev                  # port 5179
```

Data contoh dibuat oleh `database/seeders/DatabaseSeeder.php`, yang mencetak akun-akun percobaan saat selesai dijalankan.

## Perintah yang sering dipakai

```bash
php artisan test                        # seluruh test (Pest)
php artisan test --filter=NamaTest      # test tertentu
vendor/bin/pint                         # format PHP
npx tsc --noEmit                        # periksa tipe TypeScript
npm run build                           # bangun aset produksi

php artisan schedule:list               # tugas terjadwal
php artisan schedule:work               # jalankan penjadwal tanpa cron (dev)
php artisan queue:work                  # worker antrean (ekspor laporan)
```

## Empat hal yang mudah menjebak

**Penjadwal tidak berjalan sendiri.** `ujian:tutup-kedaluwarsa` berjalan tiap menit dan itulah yang menutup ujian siswa yang menutup tabnya. Di mesin dev jalankan `php artisan schedule:work`; di server ada satu baris cron di `docs/PELUNCURAN.md` §8. Tanpa keduanya, ujian menggantung tanpa galat apa pun.

**Ekspor laporan menuntut worker antrean.** Tanpanya, ekspor tetap berstatus `menunggu` selamanya. Jalankan `php artisan queue:work`.

**`public/hot` menandai dev server Vite — dan paket Inertia membaca penanda itu sebagai "SSR hidup".** Selama berkas itu ada, setiap halaman Inertia menunggu endpoint SSR yang tidak pernah ada: ~1,4 detik per halaman. `.env` memuat `INERTIA_SSR_ENABLED=false` yang menutupnya; jangan dihapus. Di produksi berkas `public/hot` memang tidak boleh ada, dan `docs/PELUNCURAN.md` §10 memeriksanya. Latarnya di `docs/BEBAN.md` §5(f).

**Logo sekolah menuntut `php artisan storage:link`.** Ia satu-satunya berkas di disk publik — supaya tampil di `/p/{slug}`, halaman pintu ruang yang dibuka tanpa login. Tanpa symlink itu, unggahnya tetap berhasil dan kolomnya tetap terisi; yang rusak hanya gambarnya, dan ia 404 tanpa satu pun galat di log. Media soal, berita acara, dan ekspor tetap di `storage/app/private`; jangan pernah menulis berkas ujian ke disk publik.

## Struktur

```
app/Domain/          modular monolith: Tenancy, Identity, Admin, Event,
                     Exam, Attendance, Question, Grading
app/Console/         perintah terjadwal (penutup attempt, pangkas ekspor)
                     dan penyediaan sekolah (`sekolah:daftarkan`)
app/Support/         pembatas laju, konteks sekolah
resources/js/        React + Inertia
scripts/             harness headless (cek UI) dan uji beban
scripts/backup/      cadangan, pemulihan, drill pemulihan
docs/                peluncuran, penyediaan sekolah, beban, bukti
```

Batas antar modul ditegakkan `tests/Architecture/DomainBoundariesTest.php`. Impor lintas domain di dalam `app/Domain/` akan menggagalkan suite.

## Menambah sekolah

Satu perintah, satu sekolah — sekolah, Admin pertama, tahun ajaran aktif, dan dua semester:

```bash
php artisan sekolah:daftarkan --kode=SMKN1 --nama="SMK Negeri 1" --npsn=20512345 \
    --admin-nama="Budi Santoso" --admin-username=admin.smkn1
```

Jalankan dengan `--kering` lebih dulu untuk melihat apa yang akan dibuat. Perintahnya idempoten: dijalankan dua kali, kolom sekolah dan sandi Admin yang sudah ada tidak ditimpa. Selengkapnya di `docs/PENYEDIAAN.md`.
