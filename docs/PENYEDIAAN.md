# Penyediaan sekolah

Satu perintah mendaftarkan satu sekolah baru beserta Admin pertamanya, tahun ajaran aktif, dan dua semester. Perintah ini menggantikan "buat baris di `schools` lewat tinker lalu isi sisanya lewat layar" — yang sampai 2026-11-05 tidak pernah terdokumentasi, tidak teruji, dan tidak bisa diulang.

```bash
php artisan sekolah:daftarkan \
    --kode=SMKN1 \
    --nama="SMK Negeri 1 Sukamaju" \
    --npsn=20512345 \
    --zona=Asia/Jakarta \
    --alamat="Jl. Pendidikan No. 12, Kabupaten Bogor" \
    --admin-nama="Budi Santoso" \
    --admin-username=admin.smkn1
```

Keluarannya:

```
 INFO Sekolah didaftarkan.

 Sekolah ............ SMK Negeri 1 Sukamaju (id 2)
 Kode ............... SMKN1
 NPSN ............... 20512345
 Tahun ajaran ....... 2026/2027
 Admin .............. Budi Santoso — admin.smkn1

 Sandi Admin ........ xxxxxxxx
 WARN Sandi ini hanya tampil sekali dan tidak tersimpan dalam bentuk terbaca. Salin sekarang.
```

## Opsi

| Opsi | Wajib | Keterangan |
|---|---|---|
| `--kode` | ya | Kode internal, huruf/angka 2–20 karakter. Disimpan sebagai huruf besar. Dipakai sebagai awalan username bila NIS bentrok antar sekolah (`smkn1.2024001`). |
| `--nama` | ya | Nama sekolah. Muncul di kop berita acara dan laporan. |
| `--npsn` | ya | Tepat 8 angka, unik. **Tidak pernah bisa diubah lewat aplikasi** — `SchoolPolicy::ubahNpsn` mengembalikan `false`, dan kolomnya terkunci di plate 04. Salah di sini berarti sekolah harus didaftarkan ulang. |
| `--zona` | tidak | `Asia/Jakarta` (bawaan), `Asia/Makassar`, `Asia/Jayapura`. Menentukan jam mulai/berakhir sesi ujian. |
| `--alamat`, `--email`, `--telepon` | tidak | Boleh diisi belakangan lewat `/admin/sekolah`. |
| `--admin-nama` | ya | Nama Admin pertama. |
| `--admin-username` | ya | Huruf kecil, angka, titik, garis bawah, strip; 3–40 karakter. **Unik lintas platform**, bukan per sekolah. |
| `--sandi` | tidak | Kosongkan. Lihat bagian Sandi di bawah. |
| `--kering` | tidak | Periksa dan laporkan saja; tidak menulis apa pun. Jalankan ini lebih dulu. |

## Apa yang dibuat, dan apa yang sengaja tidak

**Dibuat:** satu baris `schools`; satu `users` ber-role Admin; satu tahun ajaran **aktif**; dua semester (Ganjil, Genap) dengan yang memuat hari ini menyala.

**Tidak dibuat:** kelas, mata pelajaran, ruang, dan master data lain. Itu diisi Admin lewat konsol (`/admin/kelas`, `/admin/mapel`, `/admin/ruang`). Mengisinya di sini berarti menebak struktur kurikulum sekolah yang belum tentu benar — dan data yang salah lebih mahal daripada data yang kosong: yang kosong terlihat dan akan diisi, yang salah tidak.

### Mengapa tahun ajaran ikut dibuat

Ini bagian yang paling mudah dikira berlebihan, dan justru yang paling menentukan. Tanpa satu pun tahun ajaran aktif:

- `EventController` menolak **setiap** pembuatan event (`academic_year_id` wajib saat membuat) — sehingga Admin baru tidak bisa membuat event apa pun, dan event adalah satu-satunya jalan masuk ke seluruh alur ujian;
- prop bersama `tahunAktif` mengembalikan `null`, sehingga bar atas konsol kehilangan label "TA 2026/2027" di setiap laman (wireframe plate 07).

Jadi perintah ini bukan "membuat sekolah lalu menyerahkan sisanya". Ia mengantar sekolah sampai pada titik layarnya benar-benar bisa dipakai.

Nama tahun ajarannya diturunkan dari **kalender Indonesia** — berganti tiap Juli — bukan tahun kalender. Sekolah yang didaftarkan Agustus 2026 mendapat `2026/2027`.

## Idempoten

Dijalankan dua kali dengan opsi yang sama, hasilnya sama: bukan galat, dan bukan sekolah kedua. Artinya per kunci:

| Kondisi | Yang terjadi |
|---|---|
| `--kode` sudah ada | Sekolah itu **dipakai**; kolomnya **tidak disentuh** |
| `--npsn` dipakai sekolah lain | Gagal |
| `--admin-username` dipakai akun sekolah lain | Gagal |
| `--admin-username` sudah ada di sekolah yang sama | Akun itu dipakai; **sandinya tidak diubah** |
| Tahun ajaran sudah ada | Dibiarkan — termasuk kalau yang aktif bukan tahun ini |

**Mengapa kolom dan sandi tidak ditimpa.** Di antara dua eksekusi, Admin mungkin sudah memperbaiki nama sekolahnya lewat plate 04 atau mengganti sandinya lewat `/admin/pengguna`. Perintah yang menimpanya berarti membatalkan pekerjaan itu diam-diam.

**Akibat yang berguna:** perintah ini juga **melengkapi** sekolah yang terlanjur dibuat manual tanpa tahun ajaran. Sekolah itu sudah ada, jadi kolomnya dibiarkan; tahun ajarannya yang kosong yang diisi.

## Sandi

Tanpa `--sandi`, sandi dibuat acak dan dicetak **sekali**. Jalur ini yang disarankan: opsi baris perintah tercatat di riwayat shell (`~/.bash_history`) dan terlihat di `ps` oleh pengguna lain di mesin yang sama. Sandi acak tidak pernah menyentuh dua tempat itu.

Yang tersimpan hanya hash-nya. Layar tidak bisa menampilkannya kembali — kalau hilang, ganti lewat `/admin/pengguna` (Admin) atau reset dari baris perintah.

## Setelah perintah ini

1. Masuk di `/login` dengan username Admin (bukan NPSN — layar login hanya menerima username).
2. **Ganti sandi** lewat `/admin/pengguna` bila tadi memakai `--sandi`.
3. Isi master data: kelas, mata pelajaran, ruang.
4. Periksa `/admin/pengaturan` — ambang pelanggaran, KKM, durasi token. Nilainya sudah terisi bawaan; laman ini hanya perlu disentuh bila sekolah ingin angka yang berbeda.
5. Buat event pertama. Sejak titik ini seluruh alur ujian terbuka.

## Yang belum ada

**Konsol lintas sekolah tidak ada, dan disengaja.** PRD §2.2 no.3 menolak role Super Admin, dan §2.3 menaruh "Role Super Admin di dalam aplikasi operasional" di luar cakupan MVP. `Role.php` hanya punya empat case.

Konsekuensinya: **mendaftarkan sekolah kedua berarti menjalankan perintah ini di server**, dengan akses shell. Itu memang bentuk "provisioning di luar aplikasi" yang dimaksud PRD §2.2 no.4 — bukan celah, tetapi juga bukan sesuatu yang bisa dilakukan orang tanpa akses server. Bila kebutuhan itu muncul, blueprint baris 753 sudah menetapkan bentuknya: pintu kedua yang terpisah pada alamatnya sendiri, bukan tab tambahan di konsol Admin sekolah.
