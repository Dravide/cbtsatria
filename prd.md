# PRODUCT REQUIREMENTS DOCUMENT (PRD)

# SCHOOL COMPUTER BASED TEST (CBT)

**Versi:** 0.3\
**Status:** Draft untuk tinjauan teknis\
**Tanggal:** 2 November 2026\
**Dokumen:** `prd.md`

------------------------------------------------------------------------

## 1. Ringkasan Produk

### 1.1 Nama Produk

**School CBT**, yaitu platform Computer Based Test untuk sekolah yang
mendukung pengelolaan beberapa sekolah dalam satu instalasi aplikasi.

### 1.2 Deskripsi Produk

School CBT merupakan aplikasi untuk mengelola penyelenggaraan ujian
sekolah secara terintegrasi. Sistem menggunakan pendekatan
**Event-Centric**, yaitu Admin membuat event kegiatan terlebih dahulu,
kemudian seluruh komponen pelaksanaan kegiatan mengikuti event tersebut.

Contoh event:

-   Ujian Akhir Semester Ganjil.
-   Ujian Akhir Semester Genap.
-   Penilaian Tengah Semester.
-   Ujian Sekolah.
-   Try Out.
-   Ujian Praktik.
-   Remedial.

Satu event dapat memuat beberapa kegiatan evaluasi, misalnya ujian
teori, ujian praktik, dan remedial. Setiap kegiatan evaluasi dapat
mempunyai mata pelajaran, kelas, paket soal, jadwal, sesi, ruang,
peserta, dan Pengawas masing-masing.

Sistem memiliki empat role utama:

1.  Admin.
2.  Guru.
3.  Siswa.
4.  Pengawas.

Sistem juga menerapkan mekanisme **Attendance-Gated Examination**. Siswa
hanya dapat memulai ujian setelah Pengawas yang bertugas di ruang
tersebut memverifikasi kehadiran siswa.

### 1.3 Visi Produk

Menyediakan platform ujian sekolah yang terintegrasi, aman, mudah
dikelola, dan mampu menjaga integritas proses ujian dari persiapan event
sampai publikasi hasil.

### 1.4 Tujuan Produk

1.  Mengelola beberapa sekolah dalam satu platform dengan isolasi data.
2.  Menjadikan event sebagai pusat seluruh aktivitas penyelenggaraan
    ujian.
3.  Menyediakan pengelolaan data akademik dan administrasi sekolah.
4.  Memudahkan Guru membuat bank soal dan paket soal.
5.  Mendukung sembilan kategori soal.
6.  Mendukung input soal secara manual dan melalui Excel.
7.  Memungkinkan siswa menggunakan komputer sekolah atau perangkat
    pribadi di lingkungan sekolah.
8.  Mewajibkan verifikasi presensi Pengawas sebelum siswa memulai ujian.
9.  Menyediakan token ujian.
10. Menyediakan monitoring dasar selama ujian.
11. Mengotomatisasi penilaian soal objektif.
12. Menyediakan koreksi essay oleh Guru.
13. Mendukung target 300 siswa aktif secara bersamaan pada tingkat
    platform.

------------------------------------------------------------------------

## 2. Ruang Lingkup dan Keputusan Produk

### 2.1 Keputusan yang Telah Ditetapkan

  -----------------------------------------------------------------------
  Parameter                           Keputusan
  ----------------------------------- -----------------------------------
  Cakupan platform                    Multi-school

  Model tenant                        Shared database, shared schema

  Identifikasi sekolah                Kode sekolah

  Cakupan Admin                       Satu sekolah

  Role                                Admin, Guru, Siswa, Pengawas

  Perangkat ujian                     Komputer dan BYOD

  Lokasi BYOD                         Di lingkungan sekolah

  Jenis soal                          Single, multiple, true/false, short
                                      answer, matching, ordering,
                                      numeric, essay, media

  Koreksi essay                       Guru

  Input soal                          Manual dan Excel

  Token ujian                         Wajib

  Pengawasan                          Basic monitoring

  Target concurrent                   300 siswa untuk seluruh platform

  Pola arsitektur                     Modular Monolith
  -----------------------------------------------------------------------

### 2.2 Asumsi Arsitektur

Asumsi berikut menjadi dasar rancangan awal dan dapat dikonfirmasi
sebelum implementasi:

1.  Setiap akun pengguna terdaftar pada satu sekolah.
2.  Username dapat sama pada sekolah berbeda karena login menggunakan
    kode sekolah.
3.  Tidak ada role Super Admin atau Platform Admin dalam aplikasi
    operasional.
4.  Pembuatan tenant/sekolah baru dilakukan melalui proses provisioning
    di luar aplikasi.
5.  Satu sekolah dapat memiliki beberapa Admin.
6.  Satu event dapat mencakup beberapa kegiatan evaluasi.
7.  Satu kegiatan evaluasi dapat memiliki beberapa sesi dan ruang.
8.  Satu ruang dapat memiliki lebih dari satu Pengawas.
9.  Bank soal merupakan aset sekolah yang dapat digunakan kembali sesuai
    hak akses Guru.
10. Paket soal dapat ditetapkan ke event melalui proses penugasan.
11. Presensi berlaku untuk setiap peserta pada setiap ujian/sesi, bukan
    sebagai presensi umum yang berlaku untuk seluruh event.
12. BYOD menggunakan browser dan tidak memerlukan aplikasi native pada
    MVP.

### 2.3 Di Luar Cakupan MVP

Fitur berikut tidak termasuk MVP:

-   Webcam proctoring.
-   Facial recognition.
-   AI proctoring.
-   Screen recording.
-   Browser lockdown.
-   Deteksi kecurangan berbasis AI.
-   Pelacakan GPS siswa.
-   Aplikasi Android/iOS native.
-   Multi-school membership untuk satu akun.
-   Integrasi SSO eksternal.
-   Role Super Admin di dalam aplikasi operasional.

------------------------------------------------------------------------

## 3. Pengguna dan Hak Akses

### 3.1 Admin

Admin mengelola seluruh operasional satu sekolah.

Fitur Admin:

-   Dashboard sekolah.
-   Profil sekolah.
-   Data Guru.
-   Data Siswa.
-   Data Pengawas.
-   Data kelas.
-   Data mata pelajaran.
-   Tahun ajaran dan semester.
-   Data ruang.
-   Kepanitiaan event.
-   Pembuatan dan pengelolaan event.
-   Penetapan kelas dan peserta event.
-   Penetapan mata pelajaran event.
-   Penugasan Guru.
-   Penetapan paket soal.
-   Pengaturan jadwal dan sesi ujian.
-   Pengaturan ruang.
-   Penugasan Pengawas.
-   Pengelolaan token.
-   Monitoring ujian.
-   Koreksi administratif presensi.
-   Publikasi hasil.
-   Laporan.
-   Activity log.
-   Pengaturan sekolah.

Admin tidak membuat atau mengubah isi bank soal Guru dalam alur
operasional normal.

### 3.2 Guru

Guru mengelola konten soal dan melakukan penilaian essay sesuai
penugasan.

Fitur Guru:

-   Dashboard Guru.
-   Bank soal.
-   Question Builder.
-   Question Editor.
-   Preview soal.
-   Import soal Excel.
-   Pengelolaan paket soal.
-   Riwayat penggunaan paket soal.
-   Daftar tugas koreksi essay.
-   Koreksi jawaban essay.
-   Pemberian skor dan feedback.

Guru hanya dapat mengakses mata pelajaran dan kelas sesuai penugasan
atau izin yang berlaku. Guru tidak mengelola akun pengguna, ruang,
jadwal, token, atau penugasan Pengawas.

### 3.3 Siswa

Siswa hanya mengakses proses pelaksanaan ujian dan hasil yang telah
dipublikasikan.

Fitur Siswa:

-   Dashboard siswa.
-   Jadwal ujian.
-   Detail ujian.
-   Informasi sesi dan ruang.
-   Status presensi.
-   Input token.
-   Instruksi ujian.
-   Halaman pengerjaan soal.
-   Autosave jawaban.
-   Navigasi soal.
-   Submit ujian.
-   Pemulihan sesi.
-   Riwayat ujian.
-   Hasil ujian yang telah dipublikasikan.

Siswa tidak dapat mengakses bank soal, kunci jawaban, pembahasan, nilai
siswa lain, atau fungsi administrasi.

### 3.4 Pengawas

Pengawas memiliki portal khusus berdasarkan penugasan sesi dan ruang.

Fitur Pengawas:

-   Dashboard Pengawas.
-   Daftar event yang ditugaskan.
-   Jadwal pengawasan.
-   Daftar ruang tugas.
-   Daftar peserta per ruang.
-   Verifikasi presensi.
-   Presensi individual.
-   Presensi massal.
-   Koreksi presensi sesuai kewenangan.
-   Monitoring status pengerjaan.
-   Monitoring koneksi.
-   Informasi token sesuai penugasan.
-   Incident report.
-   Riwayat aktivitas pengawasan.

Pengawas hanya dapat mengakses ruang yang menjadi tanggung jawabnya.
Pengawas tidak dapat mengedit soal, jawaban siswa, atau nilai.

### 3.5 Matriks Hak Akses

  --------------------------------------------------------------------------------------------
  Fitur                                Admin                Guru          Siswa       Pengawas
  --------------------- -------------------- ------------------- -------------- --------------
  Dashboard sesuai role                   Ya                  Ya             Ya             Ya

  Profil sekolah                      Kelola               Tidak          Tidak          Tidak

  Data                                Kelola               Tidak          Tidak          Tidak
  Guru/Siswa/Pengawas                                                           

  Kelas dan mata                      Kelola  Lihat sesuai tugas          Lihat          Lihat
  pelajaran                                                          seperlunya     seperlunya

  Ruang                               Kelola               Tidak          Lihat    Lihat ruang
                                                                     penempatan          tugas

  Event                               Kelola     Lihat penugasan   Lihat jadwal          Lihat
                                                                                     penugasan

  Bank soal               Administrasi akses              Kelola          Tidak          Tidak

  Import soal                          Tidak                  Ya          Tidak          Tidak

  Paket soal             Menetapkan ke event   Membuat/mengelola          Tidak          Tidak

  Jadwal ujian                        Kelola     Lihat penugasan          Lihat          Lihat
                                                                                     penugasan

  Token                               Kelola               Tidak       Validasi   Lihat sesuai
                                                                                     penugasan

  Presensi ujian          Monitoring/koreksi               Tidak   Lihat status  Kelola sesuai
                                                                                         ruang

  Pengerjaan ujian                Monitoring               Tidak             Ya     Monitoring

  Koreksi essay                        Tidak Ya sesuai penugasan          Tidak          Tidak

  Publikasi nilai                         Ya               Tidak    Lihat nilai          Tidak
                                                                        sendiri 

  Laporan                                 Ya      Koreksi sesuai  Hasil sendiri  Laporan ruang
                                                           tugas                
  --------------------------------------------------------------------------------------------

------------------------------------------------------------------------

## 4. Multi-Tenant dan Autentikasi

### 4.1 Model Tenant

Sistem menggunakan shared database dan shared schema. Semua sekolah
menggunakan struktur tabel yang sama, sedangkan `school_id` menjadi
identitas tenant.

Setiap data operasional harus terhubung dengan sekolah secara langsung
atau melalui relasi induk yang tervalidasi.

### 4.2 Login

Halaman login menyediakan:

-   Kode Sekolah.
-   Username.
-   Password.

Alur login:

1.  Pengguna memasukkan kode sekolah.
2.  Sistem mencari sekolah berdasarkan kode.
3.  Sistem memeriksa status sekolah.
4.  Sistem mencari akun berdasarkan kombinasi `school_id` dan username.
5.  Sistem memverifikasi password.
6.  Sistem memeriksa status akun.
7.  Sistem mengarahkan pengguna ke dashboard sesuai role.

### 4.3 Aturan Autentikasi

-   Kombinasi `school_id + username` harus unik.
-   Akun nonaktif tidak dapat login.
-   Password harus disimpan menggunakan password hashing yang aman.
-   Sistem menerapkan rate limiting pada login.
-   Sistem mencatat waktu dan aktivitas login.
-   Sistem menyediakan logout dan reset password.
-   Sistem tidak menerima `school_id` dari frontend sebagai sumber
    otorisasi.

### 4.4 Tenant Isolation

Backend harus memeriksa kepemilikan data pada setiap operasi baca, ubah,
dan hapus.

Kontrol tenant diterapkan melalui:

-   Middleware tenant.
-   Query scope.
-   Laravel Policy.
-   Form Request validation.
-   Service-layer authorization.
-   Foreign key dan composite foreign key jika sesuai.

Manipulasi URL atau ID tidak boleh memungkinkan pengguna mengakses data
sekolah lain.

------------------------------------------------------------------------

## 5. Master Data Sekolah

Master data sekolah berdiri di luar event agar sekolah tidak perlu
membuat ulang data yang sama untuk setiap kegiatan.

### 5.1 Profil Sekolah

Field utama:

-   Nama sekolah.
-   Kode sekolah.
-   NPSN.
-   Logo.
-   Alamat.
-   Email.
-   Nomor telepon.
-   Zona waktu.
-   Status.

### 5.2 Tahun Ajaran dan Semester

Admin mengelola tahun ajaran dan semester, termasuk tanggal mulai,
tanggal selesai, serta status aktif.

### 5.3 Kelas

Data kelas meliputi:

-   Nama kelas.
-   Tingkat.
-   Tahun ajaran.
-   Wali kelas.
-   Status.

### 5.4 Mata Pelajaran

Data mata pelajaran meliputi:

-   Kode mata pelajaran.
-   Nama mata pelajaran.
-   Kelompok mata pelajaran.
-   Tingkat kelas.
-   Status.

### 5.5 Penugasan Mengajar

Penugasan menghubungkan Guru, mata pelajaran, kelas, dan tahun ajaran.

Penugasan menjadi dasar pembatasan akses Guru terhadap soal dan tugas
koreksi.

### 5.6 Ruang

Data ruang meliputi:

-   Kode ruang.
-   Nama ruang.
-   Lokasi.
-   Kapasitas.
-   Jumlah perangkat.
-   Status.

Ruang fisik dapat digunakan kembali pada event berbeda. Alokasi ruang
untuk event tertentu disimpan secara terpisah.

### 5.7 Pengguna

Admin dapat menambah, mengubah, menonaktifkan, mengimpor, dan mengekspor
data Guru, Siswa, dan Pengawas.

------------------------------------------------------------------------

## 6. Event Management

### 6.1 Konsep Event-Centric

Event merupakan induk aktivitas kegiatan sekolah. Admin harus membuat
event terlebih dahulu sebelum menyusun komponen operasional kegiatan.

Contoh:

**Event:** Ujian Akhir Semester Ganjil 2026/2027.

Komponen event:

1.  Identitas event.
2.  Tahun ajaran dan semester.
3.  Kepanitiaan.
4.  Kelas peserta.
5.  Mata pelajaran.
6.  Peserta.
7.  Penugasan Guru.
8.  Paket soal.
9.  Jadwal ujian.
10. Sesi ujian.
11. Ruang ujian.
12. Penugasan Pengawas.
13. Token.
14. Presensi.
15. Monitoring.
16. Koreksi essay.
17. Hasil dan laporan.

### 6.2 Data Event

Field utama:

-   Kode event.
-   Nama event.
-   Jenis kegiatan.
-   Tahun ajaran.
-   Semester.
-   Deskripsi.
-   Tanggal mulai.
-   Tanggal selesai.
-   Status.
-   Pembuat event.

### 6.3 Status Event

  Status        Penjelasan
  ------------- --------------------------------
  Draft         Event baru dibuat
  Preparation   Admin melengkapi komponen
  Ready         Persiapan memenuhi persyaratan
  Ongoing       Kegiatan berlangsung
  Completed     Kegiatan selesai
  Archived      Event diarsipkan
  Cancelled     Event dibatalkan

### 6.4 Event Workspace

Setiap event mempunyai workspace khusus. Ketika Admin membuka event,
seluruh data operasional yang ditampilkan harus mengikuti event aktif.

Workspace menyediakan navigasi:

-   Ringkasan event.
-   Kepanitiaan.
-   Kelas dan peserta.
-   Mata pelajaran.
-   Penugasan Guru.
-   Paket soal.
-   Jadwal ujian.
-   Sesi dan ruang.
-   Pengawas.
-   Token.
-   Presensi.
-   Monitoring.
-   Koreksi.
-   Hasil.
-   Laporan.

### 6.5 Event Readiness

Sistem memvalidasi kesiapan event sebelum Admin mengubah status menjadi
Ready.

Persyaratan minimum:

-   Identitas event lengkap.
-   Tahun ajaran dan semester tersedia.
-   Kelas peserta ditetapkan.
-   Mata pelajaran ditetapkan.
-   Peserta terdaftar.
-   Jadwal ujian tersedia.
-   Paket soal ditetapkan pada ujian yang memerlukannya.
-   Sesi dan ruang tersedia.
-   Pengawas ditugaskan.
-   Token tersedia.
-   Tidak terdapat konflik jadwal atau kapasitas yang belum
    diselesaikan.

### 6.6 Event Committee

Admin dapat membuat kepanitiaan untuk setiap event.

Data anggota:

-   Nama.
-   Jabatan.
-   Pengguna terkait jika anggota memiliki akun.
-   Kontak.
-   Keterangan.

Keanggotaan panitia tidak menjadi role autentikasi baru.

### 6.7 Event Classes dan Participants

Admin menentukan kelas yang mengikuti event. Sistem dapat menghasilkan
daftar peserta dari keanggotaan kelas aktif.

Admin dapat mengecualikan atau menambahkan peserta secara individual
sesuai kebijakan sekolah.

Sistem harus memeriksa:

-   Siswa berasal dari sekolah yang sama.
-   Siswa terdaftar pada kelas yang dipilih.
-   Siswa tidak terdaftar ganda.
-   Status siswa aktif.

### 6.8 Event Subjects dan Teacher Assignments

Admin memilih mata pelajaran yang termasuk dalam event dan menentukan
Guru yang bertanggung jawab jika diperlukan.

Guru hanya memperoleh akses ke tugas yang secara eksplisit diberikan
kepadanya.

### 6.9 Event Rooms

Admin mengalokasikan ruang master ke event. Sistem memeriksa kapasitas
dan benturan penggunaan ruang pada waktu yang sama.

------------------------------------------------------------------------

## 7. Question Management

### 7.1 Bank Soal

Guru membuat bank soal berdasarkan mata pelajaran dan tingkat/kelas yang
menjadi kewenangannya.

Informasi soal:

-   Kode soal.
-   Judul internal.
-   Mata pelajaran.
-   Tingkat/kelas.
-   Jenis soal.
-   Tingkat kesulitan.
-   Pertanyaan.
-   Kunci jawaban.
-   Bobot.
-   Pembahasan.
-   Media.
-   Status.

Status soal:

-   Draft.
-   Published.
-   Archived.

### 7.2 Jenis Soal

Sistem mendukung sembilan kategori berikut.

#### A. Single Choice

Pilihan ganda dengan satu jawaban benar. Sistem melakukan penilaian
otomatis.

#### B. Multiple Answers

Pilihan ganda dengan beberapa jawaban benar. Sistem mendukung
konfigurasi skor parsial. Aturan default adalah skor penuh hanya jika
seluruh jawaban benar dipilih dan tidak ada jawaban salah yang dipilih.

#### C. True/False

Pernyataan dengan pilihan benar atau salah. Sistem melakukan penilaian
otomatis.

#### D. Short Answer

Jawaban singkat dengan pencocokan kunci jawaban dan alternatif jawaban.
Sistem dapat menormalisasi huruf kapital dan spasi sesuai konfigurasi.

#### E. Matching

Siswa memasangkan item dari dua kelompok. Sistem menilai pasangan yang
benar.

#### F. Ordering

Siswa mengurutkan item sesuai urutan yang ditentukan Guru.

#### G. Numeric

Jawaban numerik dengan konfigurasi toleransi dan satuan jika diperlukan.

#### H. Essay

Jawaban uraian yang Guru nilai secara manual. Soal dapat mempunyai
rubrik, skor maksimal, dan feedback.

#### I. Media

Soal berbasis media yang mendukung gambar, audio, atau video. Media
dapat menjadi stimulus pertanyaan maupun pilihan jawaban. Mekanisme
jawaban dapat menggunakan pilihan, isian, atau uraian sesuai konfigurasi
soal.

### 7.3 Question Editor

Editor mendukung:

-   Bold, italic, underline.
-   Subscript dan superscript.
-   Daftar bernomor dan bullet.
-   Tabel.
-   Gambar.
-   Audio.
-   Video.
-   Persamaan matematika.

Konten disimpan dalam format terstruktur, misalnya JSON, agar format
dapat dimuat dan diedit kembali.

### 7.4 Question Package

Guru dapat mengelompokkan beberapa soal ke dalam paket.

Informasi paket:

-   Nama paket.
-   Mata pelajaran.
-   Tingkat kelas.
-   Deskripsi.
-   Guru pembuat.
-   Daftar soal.
-   Urutan soal.
-   Bobot.
-   Total nilai.
-   Status.

Paket soal dapat digunakan kembali jika Guru dan sekolah mempunyai hak
akses yang sesuai. Penugasan paket ke event dicatat secara eksplisit.

### 7.5 Question Snapshot

Ketika paket soal ditetapkan pada ujian, sistem membuat snapshot soal.

Snapshot mencakup:

-   Pertanyaan.
-   Pilihan jawaban.
-   Item Matching dan Ordering.
-   Kunci jawaban.
-   Bobot.
-   Konfigurasi penilaian.
-   Rubrik.
-   Referensi media.
-   Urutan soal.

Perubahan pada bank soal asli tidak boleh mengubah ujian yang sudah
dipublikasikan atau dijadwalkan.

Kunci jawaban hanya boleh diakses backend untuk proses penilaian dan
tidak boleh dikirim ke browser siswa.

------------------------------------------------------------------------

## 8. Import Soal Excel

### 8.1 Metode Import

Guru dapat mengunggah template `.xlsx`.

Template awal terdiri atas:

1.  `Questions`: identitas soal, jenis, pertanyaan, bobot, tingkat
    kesulitan, pembahasan.
2.  `Options`: pilihan jawaban, kode pilihan, posisi, dan kunci jawaban.
3.  `Items`: item Matching dan Ordering.
4.  `Rubrics`: kriteria dan bobot penilaian essay.

### 8.2 Alur Import

1.  Guru mengunduh template.
2.  Guru mengisi template.
3.  Guru mengunggah file.
4.  Sistem membaca struktur.
5.  Sistem memvalidasi data.
6.  Sistem menampilkan preview.
7.  Sistem menampilkan kesalahan berdasarkan sheet dan nomor baris.
8.  Guru memperbaiki data jika diperlukan.
9.  Guru mengonfirmasi import.
10. Sistem menyimpan data valid.

Import harus menggunakan proses yang aman dan tidak boleh membuat data
ganda ketika Guru mengirim ulang file yang sama tanpa konfirmasi.

Media diunggah melalui Question Editor pada MVP. Import massal file
media tidak menjadi kebutuhan awal.

------------------------------------------------------------------------

## 9. Examination Management

### 9.1 Struktur Ujian

Sistem membedakan:

-   **Event:** induk kegiatan sekolah.
-   **Event Activity:** kegiatan evaluasi dalam event, misalnya teori,
    praktik, atau remedial.
-   **Exam:** ujian untuk mata pelajaran dan target kelas tertentu.
-   **Exam Session:** gelombang atau sesi pelaksanaan.
-   **Exam Session Room:** ruang yang digunakan dalam sesi.
-   **Exam Participant:** siswa yang ditetapkan mengikuti ujian.
-   **Exam Attempt:** satu proses pengerjaan ujian oleh siswa.

### 9.2 Pembuatan Exam

Admin menentukan:

-   Event.
-   Event activity.
-   Nama ujian.
-   Jenis ujian.
-   Mata pelajaran.
-   Target kelas.
-   Paket soal.
-   Durasi.
-   Maksimal percobaan.
-   Pengacakan soal.
-   Pengacakan pilihan jawaban.
-   Kebijakan navigasi.
-   Kebijakan keterlambatan.
-   Publikasi hasil.

### 9.3 Exam Session

Setiap sesi mempunyai:

-   Nomor sesi.
-   Waktu pembukaan akses.
-   Batas akhir memulai.
-   Batas akhir mutlak.
-   Durasi.
-   Daftar ruang.
-   Token.
-   Status.

Satu exam dapat mempunyai beberapa sesi dan setiap sesi dapat mempunyai
beberapa ruang.

### 9.4 Exam Session Room

Admin memilih ruang yang sudah dialokasikan pada event.

Sistem memeriksa:

-   Kapasitas ruang.
-   Jumlah peserta.
-   Benturan penggunaan ruang.
-   Status ruang.
-   Kesesuaian event.

### 9.5 Exam Participants

Peserta ditetapkan berdasarkan peserta event dan target kelas ujian.

Sistem memeriksa duplikasi peserta, kesesuaian sekolah, sesi, dan ruang.

Satu siswa hanya memiliki satu penempatan aktif untuk satu ujian,
kecuali Admin mengubah penempatan melalui prosedur yang tercatat.

### 9.6 Supervisor Assignment

Admin menetapkan Pengawas berdasarkan sesi dan ruang.

Aturan:

-   Satu ruang dapat memiliki beberapa Pengawas.
-   Satu Pengawas dapat memiliki beberapa penugasan selama tidak
    bentrok.
-   Pengawas hanya dapat mengakses ruang yang ditugaskan.
-   Setiap penugasan mempunyai waktu mulai dan selesai.
-   Sistem harus memeriksa benturan penugasan.

### 9.7 Token

Token wajib digunakan sebelum siswa memulai ujian.

Ketentuan:

-   Token dibuat secara acak.
-   Token terhubung dengan sesi ujian.
-   Token memiliki masa berlaku.
-   Token dapat diganti atau dicabut oleh Admin.
-   Pengawas dapat melihat token sesuai penugasan.
-   Sistem menerapkan rate limiting.
-   Sistem mencatat penggunaan dan kegagalan validasi token.

Rekomendasi awal adalah satu token bersama untuk satu sesi. Apabila
sekolah membutuhkan token berbeda per ruang, desain dapat diperluas
dengan menambahkan cakupan token pada ruang.

Token disimpan dalam bentuk hash untuk validasi. Jika sistem perlu
menampilkan kembali token kepada Admin atau Pengawas yang berwenang,
nilai tampilan disimpan dalam bentuk terenkripsi.

------------------------------------------------------------------------

## 10. Supervisor Portal dan Attendance-Gated Examination

### 10.1 Prinsip Utama

Presensi Pengawas merupakan syarat wajib untuk memulai ujian.

Siswa tidak dapat membuat exam attempt jika belum memperoleh verifikasi
kehadiran yang sah.

### 10.2 Dashboard Pengawas

Dashboard menampilkan:

-   Event yang ditugaskan.
-   Jadwal pengawasan.
-   Mata pelajaran.
-   Sesi.
-   Ruang.
-   Jumlah peserta.
-   Jumlah hadir.
-   Jumlah belum dipresensi.
-   Jumlah tidak hadir.
-   Jumlah terlambat.
-   Jumlah sedang mengerjakan.
-   Jumlah sudah submit.
-   Status koneksi peserta.

### 10.3 Daftar Ruang

Pengawas hanya melihat ruang yang terdapat dalam
`supervision_assignments` miliknya.

Saat memilih ruang, sistem menampilkan:

-   Identitas event.
-   Jadwal ujian.
-   Mata pelajaran.
-   Sesi.
-   Daftar peserta.
-   NIS.
-   Kelas.
-   Status presensi.
-   Status pengerjaan.

### 10.4 Attendance Status

  Status    Arti                         Hak memulai ujian
  --------- ---------------------------- ---------------------
  Pending   Belum diverifikasi           Ditolak
  Present   Hadir dan diverifikasi       Diizinkan
  Late      Terlambat dan diverifikasi   Mengikuti kebijakan
  Absent    Tidak hadir                  Ditolak
  Excused   Izin                         Ditolak

Status awal setiap peserta adalah `pending`.

Status `late` hanya memberikan akses jika konfigurasi ujian mengizinkan
keterlambatan dan batas waktu keterlambatan belum terlampaui.

### 10.5 Attendance Verification

Pengawas dapat:

-   Melakukan presensi individual.
-   Melakukan presensi massal.
-   Menandai hadir.
-   Menandai terlambat.
-   Menandai tidak hadir.
-   Menandai izin.
-   Mencari peserta.
-   Melihat riwayat presensi.

Setiap perubahan harus mencatat:

-   Peserta.
-   Event.
-   Ujian.
-   Sesi.
-   Ruang.
-   Status sebelum perubahan.
-   Status setelah perubahan.
-   Identitas Pengawas.
-   Waktu perubahan.
-   Alasan jika diperlukan.

Jika terdapat beberapa Pengawas dalam satu ruang, mereka dapat mengakses
daftar presensi yang sama. Sistem mencatat Pengawas yang melakukan
setiap tindakan.

### 10.6 Automatic Attendance Record Creation

Ketika Admin menetapkan peserta ke sesi dan ruang, sistem otomatis
membuat `attendance_record` dengan status `pending`.

Pengawas tidak perlu membuat daftar presensi secara manual.

### 10.7 Attendance Correction

Sebelum siswa memulai ujian, Pengawas dapat melakukan koreksi sesuai
kewenangannya.

Setelah attempt dimulai, perubahan presensi memerlukan prosedur khusus.
Admin dapat melakukan koreksi administratif dengan alasan dan audit log.

Sistem tidak boleh menghapus riwayat perubahan presensi.

### 10.8 Student Access Validation

Sebelum membuat attempt, backend harus memeriksa:

1.  Akun siswa aktif.
2.  Sekolah sesuai.
3.  Siswa terdaftar pada event.
4.  Siswa terdaftar sebagai peserta ujian.
5.  Penempatan sesi dan ruang valid.
6.  Presensi berstatus `present`, atau `late` yang diizinkan.
7.  Presensi diverifikasi oleh Pengawas yang mempunyai penugasan pada
    ruang tersebut.
8.  Sesi masih menerima peserta.
9.  Token valid.
10. Jumlah attempt belum melebihi batas.
11. Tidak ada attempt aktif lain yang bertentangan.

Validasi dilakukan kembali pada endpoint start exam. Pemeriksaan pada
halaman frontend saja tidak cukup.

### 10.9 Pesan untuk Siswa yang Belum Dipresensi

Jika siswa belum dipresensi, sistem menampilkan:

**Akses Ujian Belum Tersedia**

Anda belum mendapatkan verifikasi kehadiran dari Pengawas ruang ujian.
Silakan menghubungi Pengawas yang bertugas untuk melakukan verifikasi
sebelum memulai ujian.

### 10.10 Attendance Rule

Presensi berlaku untuk setiap peserta pada setiap ujian/sesi.

Presensi pada ujian Matematika tidak otomatis membuat siswa dianggap
hadir pada ujian Bahasa Indonesia, meskipun keduanya berada dalam event
yang sama.

------------------------------------------------------------------------

## 11. Student Examination Engine

### 11.1 Dashboard Siswa

Dashboard menampilkan:

-   Jadwal mendatang.
-   Ujian yang dapat diikuti.
-   Status presensi.
-   Ujian sedang berlangsung.
-   Ujian selesai.
-   Hasil yang dipublikasikan.

### 11.2 Examination Interface

Halaman pengerjaan menyediakan:

-   Identitas siswa.
-   Nama event.
-   Nama ujian.
-   Mata pelajaran.
-   Sesi dan ruang.
-   Countdown timer.
-   Nomor soal.
-   Konten pertanyaan.
-   Pilihan jawaban atau area input.
-   Navigasi soal.
-   Penanda review.
-   Status jawaban.
-   Tombol submit.

### 11.3 Navigation

Siswa dapat berpindah soal sesuai konfigurasi ujian:

-   Next.
-   Previous.
-   Navigasi langsung.
-   Mark for Review.
-   Indikator terjawab.
-   Indikator belum terjawab.

### 11.4 Autosave

Sistem menyimpan perubahan jawaban ke server.

Ketentuan:

-   Pilihan jawaban disimpan saat pilihan berubah.
-   Jawaban teks dan essay menggunakan debounce.
-   Sistem menampilkan status penyimpanan.
-   Sistem mengonfirmasi jawaban hanya setelah server berhasil
    menyimpan.
-   Sistem mencegah request ganda menghasilkan data yang tidak
    konsisten.
-   Sistem menggunakan versioning atau mekanisme optimistic concurrency
    untuk mengelola perubahan jawaban.

### 11.5 Server-Side Timer

Server menjadi sumber waktu utama.

Saat attempt dibuat, sistem menyimpan:

-   `started_at`.
-   `deadline_at`.

Rumus:

`deadline_at = MIN(started_at + duration, hard_end_at)`

Jam perangkat siswa tidak boleh menentukan waktu akhir ujian.

### 11.6 Session Recovery

Jika koneksi terputus:

1.  Attempt tetap tersimpan.
2.  Siswa dapat login kembali.
3.  Sistem memeriksa status attempt.
4.  Sistem mengambil jawaban yang telah tersimpan.
5.  Siswa dapat melanjutkan jika deadline belum tercapai.
6.  Sistem mencatat perubahan perangkat atau sesi jika diperlukan.

MVP menerapkan satu attempt aktif untuk satu peserta. Perpindahan
perangkat mengikuti kebijakan pemulihan yang ditetapkan sekolah.

### 11.7 Submit

Sebelum submit, sistem menampilkan ringkasan:

-   Total soal.
-   Terjawab.
-   Belum terjawab.
-   Ditandai untuk review.

Setelah konfirmasi:

-   Jawaban dikunci.
-   `submitted_at` dicatat.
-   Attempt berubah menjadi submitted.
-   Penilaian otomatis dijalankan.
-   Jawaban essay masuk antrean koreksi.
-   Hasil ditandai final atau menunggu koreksi.

### 11.8 Automatic Submission

Ketika deadline tercapai, sistem menutup attempt dan melakukan automatic
submission.

Proses harus idempotent agar request berulang tidak membuat hasil ganda.

------------------------------------------------------------------------

## 12. Basic Monitoring

### 12.1 Monitoring Pengawas

Pengawas dapat memantau:

-   Belum memulai.
-   Sedang mengerjakan.
-   Sudah submit.
-   Koneksi aktif.
-   Koneksi terputus.
-   Gangguan teknis.
-   Insiden pengawasan.

### 12.2 Heartbeat

Konfigurasi awal yang direkomendasikan:

-   Heartbeat setiap 30 detik.
-   Status disconnected setelah 90 detik tanpa heartbeat.
-   Dashboard diperbarui secara berkala.

Nilai tersebut harus diuji dan disesuaikan melalui load testing.

### 12.3 Incident Report

Pengawas dapat melaporkan:

-   Gangguan perangkat.
-   Gangguan jaringan.
-   Kesalahan identitas.
-   Masalah token.
-   Pelanggaran tata tertib.
-   Gangguan teknis lain.

Setiap laporan mencatat waktu, ruang, peserta jika terkait, Pengawas,
deskripsi, dan status penyelesaian.

### 12.4 Batasan Monitoring MVP

MVP tidak menyediakan webcam, facial recognition, screen recording, AI
proctoring, browser lockdown, atau GPS tracking.

------------------------------------------------------------------------

## 13. Grading dan Result

### 13.1 Automatic Scoring

Sistem melakukan penilaian otomatis untuk:

-   Single Choice.
-   Multiple Answers.
-   True/False.
-   Short Answer.
-   Matching.
-   Ordering.
-   Numeric.

### 13.2 Manual Essay Grading

Guru mengoreksi essay pada ujian yang menugaskannya sebagai penilai.

Alur:

1.  Siswa submit.
2.  Sistem menyimpan jawaban.
3.  Jawaban essay masuk antrean koreksi.
4.  Guru membuka daftar koreksi.
5.  Guru membaca soal dan jawaban.
6.  Guru memberikan skor.
7.  Guru memberikan feedback.
8.  Guru menyimpan hasil koreksi.
9.  Sistem memperbarui hasil ujian.

### 13.3 Essay Rubric

Guru dapat menentukan kriteria penilaian, deskripsi, skor maksimal, dan
urutan kriteria.

Rubrik bersifat opsional.

### 13.4 Result Calculation

Rumus nilai:

`Nilai Akhir = (Total Skor Diperoleh / Total Skor Maksimal) x 100`

Sistem menyimpan:

-   Automatic score.
-   Essay score.
-   Total score.
-   Maximum score.
-   Final percentage.
-   Grading status.

Status koreksi:

-   Not Graded.
-   Partially Graded.
-   Fully Graded.
-   Published.

Hasil yang masih mempunyai essay belum terkoreksi tidak boleh dianggap
final.

### 13.5 Publication

Admin menentukan waktu publikasi nilai.

Siswa hanya dapat melihat nilai miliknya setelah hasil dipublikasikan.

------------------------------------------------------------------------

## 14. Reporting

Admin dapat melihat laporan berdasarkan event, kegiatan, ujian, mata
pelajaran, kelas, sesi, dan ruang.

Laporan mencakup:

-   Daftar peserta.
-   Presensi.
-   Peserta tidak hadir.
-   Peserta belum memulai.
-   Peserta sedang mengerjakan.
-   Peserta sudah submit.
-   Gangguan koneksi.
-   Incident report.
-   Status koreksi essay.
-   Nilai per siswa.
-   Nilai per kelas.
-   Distribusi nilai.
-   Rekap hasil event.

Format ekspor:

-   XLSX.
-   CSV.
-   PDF.

Siswa hanya dapat mengakses laporan hasil miliknya.

------------------------------------------------------------------------

## 15. System Architecture

### 15.1 Architecture Pattern

Sistem menggunakan **Modular Monolith**.

Seluruh business logic berada dalam satu aplikasi Laravel, tetapi modul
dipisahkan berdasarkan domain.

Pendekatan ini dipilih karena lebih sederhana untuk dikembangkan dan
dioperasikan pada tahap awal dibandingkan Microservices.

### 15.2 Technology Stack

  Layer             Teknologi
  ----------------- ----------------------------------------------------
  Backend           Laravel
  Frontend          React
  SPA Integration   Inertia.js 3
  UI Components     shadcn/ui
  Styling           Tailwind CSS
  Build Tool        Vite
  Database          MySQL atau PostgreSQL, finalisasi belum ditetapkan
  Cache             Redis
  Queue             Laravel Queue dengan Redis
  File Storage      Local atau S3-compatible storage
  Monitoring        Application logs dan error monitoring

### 15.3 Application Modules

1.  Tenancy.
2.  Identity.
3.  Academic Management.
4.  User Management.
5.  Event Management.
6.  Committee Management.
7.  Question Bank.
8.  Question Import.
9.  Question Package.
10. Examination Management.
11. Exam Session.
12. Exam Attempt.
13. Supervision.
14. Attendance.
15. Grading.
16. Result.
17. Reporting.
18. Notification.
19. Audit Log.

### 15.4 Request Flow

1.  Browser mengirim request.
2.  Laravel memproses middleware.
3.  Sistem memeriksa autentikasi.
4.  Sistem memeriksa tenant.
5.  Sistem memeriksa role dan permission.
6.  Controller memanggil service atau action.
7.  Business logic dijalankan.
8.  Model berinteraksi dengan database.
9.  Laravel mengembalikan response Inertia atau JSON sesuai kebutuhan.

### 15.5 Suggested Folder Structure

``` text
app/
├── Domain/
│   ├── Tenancy/
│   ├── Identity/
│   ├── Academic/
│   ├── UserManagement/
│   ├── Events/
│   ├── QuestionBank/
│   ├── QuestionImport/
│   ├── QuestionPackage/
│   ├── Examination/
│   ├── ExamAttempt/
│   ├── Supervision/
│   ├── Attendance/
│   ├── Grading/
│   └── Reporting/
├── Http/
│   ├── Controllers/
│   │   ├── Admin/
│   │   ├── Teacher/
│   │   ├── Student/
│   │   └── Supervisor/
│   ├── Middleware/
│   └── Requests/
├── Models/
├── Policies/
├── Services/
├── Actions/
├── Jobs/
└── Notifications/

resources/js/
├── components/
│   ├── ui/
│   ├── forms/
│   ├── tables/
│   ├── questions/
│   └── examination/
├── layouts/
│   ├── admin-layout.jsx
│   ├── teacher-layout.jsx
│   ├── student-layout.jsx
│   └── supervisor-layout.jsx
├── pages/
│   ├── admin/
│   ├── teacher/
│   ├── student/
│   └── supervisor/
├── hooks/
├── lib/
└── types/
```

------------------------------------------------------------------------

## 16. Database Design

### 16.1 Database Principles

1.  Semua tabel tenant mempunyai `school_id` secara langsung atau
    melalui relasi induk yang tervalidasi.
2.  Gunakan foreign key.
3.  Gunakan composite foreign key untuk menjaga konsistensi tenant jika
    memungkinkan.
4.  Gunakan unique constraint untuk mencegah data ganda.
5.  Gunakan index gabungan untuk query tenant dan pencarian.
6.  Gunakan transaksi database pada proses kritis.
7.  Gunakan JSON untuk konten soal yang fleksibel.
8.  Jangan mengirim kunci jawaban ke browser siswa.
9.  Simpan waktu ujian berdasarkan zona waktu sekolah dan gunakan
    standar waktu yang konsisten pada backend.
10. Gunakan soft delete hanya untuk data yang memang memerlukan
    pemulihan dan tidak sedang terikat pada integritas transaksi.

### 16.2 Tenant dan Authentication

#### `schools`

-   id
-   code
-   name
-   npsn
-   logo_path
-   address
-   email
-   phone
-   timezone
-   status
-   created_at
-   updated_at

#### `users`

-   id
-   school_id
-   role
-   name
-   username
-   email
-   phone
-   password
-   is_active
-   last_login_at
-   created_at
-   updated_at
-   deleted_at

Unique:

-   `school_id + username`.
-   Email unik per sekolah jika email digunakan sebagai identitas login
    atau kontak unik.

#### `teacher_profiles`

-   id
-   school_id
-   user_id
-   nip
-   gender
-   address

#### `student_profiles`

-   id
-   school_id
-   user_id
-   nis
-   nisn
-   gender
-   address

#### `supervisor_profiles`

-   id
-   school_id
-   user_id
-   staff_code
-   address

Setiap profile mempunyai relasi one-to-one dengan users.

### 16.3 Academic Management

#### `academic_years`

-   id
-   school_id
-   name
-   start_date
-   end_date
-   is_active

#### `semesters`

-   id
-   school_id
-   academic_year_id
-   name
-   start_date
-   end_date

#### `classes`

-   id
-   school_id
-   academic_year_id
-   name
-   grade_level
-   homeroom_teacher_id
-   status

#### `class_enrollments`

-   id
-   school_id
-   class_id
-   student_id
-   academic_year_id
-   enrollment_date
-   status

#### `subjects`

-   id
-   school_id
-   code
-   name
-   category
-   grade_level
-   status

#### `teaching_assignments`

-   id
-   school_id
-   academic_year_id
-   teacher_id
-   subject_id
-   class_id

#### `rooms`

-   id
-   school_id
-   code
-   name
-   location
-   capacity
-   device_count
-   status

### 16.4 Event Management

#### `school_events`

-   id
-   school_id
-   academic_year_id
-   semester_id
-   code
-   name
-   event_type
-   description
-   start_at
-   end_at
-   status
-   created_by
-   created_at
-   updated_at

#### `event_activities`

-   id
-   school_id
-   event_id
-   code
-   name
-   activity_type
-   description
-   start_at
-   end_at
-   status

Contoh activity type: theory, practical, remedial, tryout.

#### `event_committee_members`

-   id
-   school_id
-   event_id
-   user_id nullable
-   name
-   position
-   email
-   phone

#### `event_classes`

-   id
-   school_id
-   event_id
-   class_id

Unique: `event_id + class_id`.

#### `event_subjects`

-   id
-   school_id
-   event_id
-   subject_id

Unique: `event_id + subject_id`.

#### `event_participants`

-   id
-   school_id
-   event_id
-   student_id
-   class_id
-   status
-   registered_at

Unique: `event_id + student_id`.

#### `event_rooms`

-   id
-   school_id
-   event_id
-   room_id
-   capacity_override
-   status

Unique: `event_id + room_id`.

#### `event_teacher_assignments`

-   id
-   school_id
-   event_id
-   teacher_id
-   subject_id
-   class_id
-   activity_id nullable

### 16.5 Question Management

#### `questions`

-   id
-   school_id
-   created_by
-   subject_id
-   code
-   title
-   question_type
-   difficulty
-   prompt_json
-   answer_key_json
-   scoring_config_json
-   explanation_json
-   status
-   published_at

#### `question_options`

-   id
-   school_id
-   question_id
-   option_key
-   content_json
-   is_correct
-   position

#### `question_items`

-   id
-   school_id
-   question_id
-   item_group
-   item_key
-   content_json
-   correct_match_key
-   correct_position
-   position

#### `question_media`

-   id
-   school_id
-   question_id
-   option_id nullable
-   media_type
-   mime_type
-   storage_path
-   metadata_json

#### `essay_rubric_criteria`

-   id
-   school_id
-   question_id
-   criterion
-   description
-   max_score
-   position

#### `question_packages`

-   id
-   school_id
-   created_by
-   subject_id
-   name
-   grade_level
-   description
-   status

#### `question_package_items`

-   id
-   school_id
-   question_package_id
-   question_id
-   points_override
-   position

Unique: `question_package_id + question_id`.

#### `event_question_packages`

-   id
-   school_id
-   event_id
-   event_activity_id nullable
-   question_package_id
-   assigned_by
-   status

#### `question_import_batches`

-   id
-   school_id
-   teacher_id
-   file_path
-   status
-   total_rows
-   success_rows
-   failed_rows
-   error_report_path
-   imported_at

### 16.6 Examination Management

#### `exams`

-   id
-   school_id
-   event_id
-   event_activity_id
-   subject_id
-   question_package_id
-   created_by
-   code
-   title
-   exam_type
-   instructions
-   duration_minutes
-   max_attempts
-   shuffle_questions
-   shuffle_options
-   allow_back_navigation
-   allow_late_entry
-   late_entry_deadline
-   status

#### `exam_classes`

-   id
-   school_id
-   exam_id
-   class_id

#### `exam_questions`

-   id
-   school_id
-   exam_id
-   source_question_id
-   question_type
-   prompt_snapshot_json
-   options_snapshot_json
-   items_snapshot_json
-   answer_key_snapshot_json
-   scoring_snapshot_json
-   rubric_snapshot_json
-   media_snapshot_json
-   max_score
-   position

#### `exam_sessions`

-   id
-   school_id
-   exam_id
-   session_number
-   access_opens_at
-   access_closes_at
-   hard_end_at
-   duration_minutes
-   status

#### `exam_session_rooms`

-   id
-   school_id
-   exam_session_id
-   event_room_id
-   capacity_override

#### `exam_tokens`

-   id
-   school_id
-   exam_session_id
-   token_hash
-   token_encrypted
-   valid_from
-   valid_until
-   revoked_at
-   created_by

#### `exam_participants`

-   id
-   school_id
-   exam_id
-   event_participant_id
-   exam_session_id
-   exam_session_room_id
-   student_id
-   assigned_at
-   status

Unique: `exam_id + student_id`.

#### `supervision_assignments`

-   id
-   school_id
-   exam_session_room_id
-   supervisor_id
-   assigned_by
-   status

#### `exam_graders`

-   id
-   school_id
-   exam_id
-   teacher_id

### 16.7 Attendance

#### `attendance_records`

-   id
-   school_id
-   exam_participant_id
-   attendance_status
-   verified_by
-   verified_at
-   notes
-   created_at
-   updated_at

Unique: `exam_participant_id`.

Status:

-   pending
-   present
-   late
-   absent
-   excused

Sistem otomatis membuat record dengan status pending ketika peserta
ditetapkan ke sesi dan ruang.

#### `attendance_logs`

-   id
-   school_id
-   attendance_record_id
-   changed_by
-   old_status
-   new_status
-   reason
-   created_at

Riwayat perubahan tidak boleh dihapus melalui antarmuka operasional.

### 16.8 Examination Execution

#### `exam_attempts`

-   id
-   school_id
-   exam_participant_id
-   attempt_number
-   started_at
-   deadline_at
-   submitted_at
-   last_heartbeat_at
-   device_type
-   ip_address
-   user_agent
-   status

Unique: `exam_participant_id + attempt_number`.

#### `attempt_questions`

-   id
-   school_id
-   exam_attempt_id
-   exam_question_id
-   display_order
-   option_order_json
-   item_order_json

Unique: `exam_attempt_id + exam_question_id`.

#### `student_answers`

-   id
-   school_id
-   attempt_question_id
-   answer_json
-   is_correct
-   score_awarded
-   saved_at
-   version

Unique: `attempt_question_id`.

### 16.9 Grading dan Result

#### `essay_gradings`

-   id
-   school_id
-   student_answer_id
-   teacher_id
-   score
-   feedback
-   graded_at
-   status

#### `exam_results`

-   id
-   school_id
-   exam_attempt_id
-   automatic_score
-   essay_score
-   total_score
-   maximum_score
-   final_percentage
-   grading_status
-   published_at

Unique: `exam_attempt_id`.

### 16.10 Monitoring dan Supporting Data

#### `exam_incidents`

-   id
-   school_id
-   exam_session_room_id
-   exam_participant_id nullable
-   supervisor_id
-   incident_type
-   description
-   occurred_at
-   status

#### `notifications`

-   id
-   school_id
-   user_id
-   type
-   data_json
-   read_at

#### `activity_logs`

-   id
-   school_id
-   user_id
-   action
-   entity_type
-   entity_id
-   metadata_json
-   ip_address
-   created_at

------------------------------------------------------------------------

## 17. Relasi Utama Database

1.  Satu School mempunyai banyak Users.
2.  Satu User mempunyai satu profile sesuai role.
3.  Satu School mempunyai banyak Academic Years.
4.  Satu Academic Year mempunyai banyak Semesters dan Classes.
5.  Satu Class mempunyai banyak Students melalui Class Enrollments.
6.  Satu Teacher dapat mempunyai banyak Teaching Assignments.
7.  Satu School mempunyai banyak School Events.
8.  Satu Event mempunyai banyak Event Activities.
9.  Satu Event mempunyai banyak Event Classes dan Event Subjects.
10. Satu Event mempunyai banyak Event Participants.
11. Satu Event dapat mengalokasikan beberapa Rooms.
12. Satu Event dapat mempunyai banyak Committee Members.
13. Satu Event dapat menerima beberapa Question Packages.
14. Satu Event mempunyai banyak Exams.
15. Satu Exam mempunyai banyak Exam Questions sebagai snapshot.
16. Satu Exam mempunyai banyak Exam Sessions.
17. Satu Exam Session mempunyai banyak Exam Session Rooms.
18. Satu Exam Session Room dapat mempunyai beberapa Supervisor
    Assignments.
19. Satu Exam Participant mempunyai satu Attendance Record untuk ujian
    tersebut.
20. Satu Attendance Record mempunyai banyak Attendance Logs.
21. Satu Exam Participant dapat mempunyai beberapa Attempts sesuai batas
    percobaan.
22. Satu Attempt mempunyai banyak Attempt Questions.
23. Satu Attempt Question mempunyai satu Student Answer.
24. Satu Student Answer dapat mempunyai satu Essay Grading jika jenis
    soalnya essay.
25. Satu Attempt menghasilkan satu Exam Result.

------------------------------------------------------------------------

## 18. ERD Konseptual

``` mermaid
erDiagram
    SCHOOLS ||--o{ USERS : owns
    USERS ||--o| TEACHER_PROFILES : has
    USERS ||--o| STUDENT_PROFILES : has
    USERS ||--o| SUPERVISOR_PROFILES : has

    SCHOOLS ||--o{ SCHOOL_EVENTS : organizes
    SCHOOL_EVENTS ||--o{ EVENT_ACTIVITIES : contains
    SCHOOL_EVENTS ||--o{ EVENT_CLASSES : includes
    SCHOOL_EVENTS ||--o{ EVENT_SUBJECTS : includes
    SCHOOL_EVENTS ||--o{ EVENT_PARTICIPANTS : registers
    SCHOOL_EVENTS ||--o{ EVENT_ROOMS : allocates

    CLASSES ||--o{ EVENT_CLASSES : selected
    SUBJECTS ||--o{ EVENT_SUBJECTS : selected
    STUDENT_PROFILES ||--o{ EVENT_PARTICIPANTS : participates
    ROOMS ||--o{ EVENT_ROOMS : selected

    SCHOOL_EVENTS ||--o{ EXAMS : contains
    EVENT_ACTIVITIES ||--o{ EXAMS : groups
    EXAMS ||--o{ EXAM_QUESTIONS : snapshots
    EXAMS ||--o{ EXAM_SESSIONS : schedules

    EXAM_SESSIONS ||--o{ EXAM_SESSION_ROOMS : uses
    EVENT_ROOMS ||--o{ EXAM_SESSION_ROOMS : assigned
    EXAM_SESSION_ROOMS ||--o{ SUPERVISION_ASSIGNMENTS : supervised
    SUPERVISOR_PROFILES ||--o{ SUPERVISION_ASSIGNMENTS : assigned

    EXAM_SESSIONS ||--o{ EXAM_PARTICIPANTS : contains
    EXAM_SESSION_ROOMS ||--o{ EXAM_PARTICIPANTS : seats
    STUDENT_PROFILES ||--o{ EXAM_PARTICIPANTS : takes

    EXAM_PARTICIPANTS ||--|| ATTENDANCE_RECORDS : has
    ATTENDANCE_RECORDS ||--o{ ATTENDANCE_LOGS : history

    EXAM_PARTICIPANTS ||--o{ EXAM_ATTEMPTS : attempts
    EXAM_ATTEMPTS ||--o{ ATTEMPT_QUESTIONS : contains
    ATTEMPT_QUESTIONS ||--o| STUDENT_ANSWERS : answered
    STUDENT_ANSWERS ||--o| ESSAY_GRADINGS : graded
    EXAM_ATTEMPTS ||--o| EXAM_RESULTS : produces
```

Diagram ini merupakan gambaran konseptual. Implementasi migration harus
menetapkan seluruh foreign key, unique constraint, index, dan aturan
tenant isolation.

------------------------------------------------------------------------

## 19. Non-Functional Requirements

### 19.1 Security

-   Authentication dan authorization berbasis Laravel.
-   Role-Based Access Control.
-   Tenant isolation.
-   Laravel Policies.
-   Password hashing.
-   CSRF protection.
-   Input validation.
-   Rate limiting.
-   Secure session management.
-   HTTPS.
-   Audit log.
-   Protected media storage.
-   Kunci jawaban tidak boleh dikirim ke browser siswa.

### 19.2 Data Integrity

-   Gunakan transaksi database untuk pembuatan attempt.
-   Gunakan row locking untuk mencegah attempt ganda.
-   Presensi harus terhubung dengan peserta dan penugasan ruang yang
    valid.
-   Jawaban yang telah dikonfirmasi tersimpan tidak boleh hilang.
-   Submit harus idempotent.
-   Hasil ujian tidak boleh dibuat ganda.
-   Snapshot soal harus tetap konsisten.

### 19.3 Performance

Target awal:

-   300 siswa aktif secara bersamaan pada tingkat platform.
-   P95 penyimpanan jawaban maksimal 2 detik pada lingkungan pengujian
    yang disepakati.
-   P95 pemuatan halaman operasional maksimal 3 detik pada lingkungan
    pengujian yang disepakati.
-   Tidak ada kehilangan jawaban yang telah dikonfirmasi tersimpan.
-   Proses submit tetap konsisten.
-   Import dan ekspor berjalan melalui queue jika membutuhkan waktu
    lama.

Target ini harus diverifikasi melalui load testing.

### 19.4 Reliability

Sistem menyediakan:

-   Autosave.
-   Server-side timer.
-   Session recovery.
-   Automatic submission.
-   Database transaction.
-   Backup.
-   Activity logging.
-   Error monitoring.

### 19.5 Scalability

Redis dapat digunakan untuk:

-   Cache.
-   Queue.
-   Rate limiting.
-   Session storage jika diperlukan.

Laravel Queue digunakan untuk:

-   Import Excel.
-   Ekspor laporan.
-   Notifikasi.
-   Pemrosesan administratif yang membutuhkan waktu lama.

Penyimpanan jawaban tetap harus memperoleh konfirmasi database secara
langsung.

### 19.6 Monitoring Architecture

MVP menggunakan polling dan heartbeat. WebSocket atau Laravel Reverb
dapat dipertimbangkan ketika kebutuhan monitoring real-time meningkat.

------------------------------------------------------------------------

## 20. API dan Route Specification

### 20.1 Supervisor

  Method   Endpoint                                Fungsi
  -------- --------------------------------------- -----------------------
  GET      `/supervisor/dashboard`                 Dashboard Pengawas
  GET      `/supervisor/events`                    Event yang ditugaskan
  GET      `/supervisor/assignments`               Daftar penugasan
  GET      `/supervisor/rooms/{id}`                Detail ruang
  GET      `/supervisor/rooms/{id}/attendance`     Daftar presensi
  POST     `/supervisor/attendance/{id}/verify`    Verifikasi presensi
  POST     `/supervisor/attendance/bulk-verify`    Presensi massal
  POST     `/supervisor/attendance/{id}/correct`   Koreksi presensi
  GET      `/supervisor/attendance/{id}/logs`      Riwayat presensi

### 20.2 Student

  Method   Endpoint                                        Fungsi
  -------- ----------------------------------------------- -----------------------
  GET      `/student/exams`                                Jadwal ujian
  GET      `/student/exams/{id}`                           Detail ujian
  GET      `/student/exams/{id}/eligibility`               Pemeriksaan kelayakan
  POST     `/student/exams/{id}/validate-token`            Validasi token
  POST     `/student/exams/{id}/start`                     Memulai ujian
  PUT      `/student/attempts/{id}/answers/{questionId}`   Simpan jawaban
  POST     `/student/attempts/{id}/submit`                 Submit ujian

Endpoint `start` wajib memvalidasi ulang presensi, token, jadwal, dan
status peserta di backend.

### 20.3 Admin

Route Admin mencakup:

-   `/admin/events`
-   `/admin/events/{event}`
-   `/admin/events/{event}/activities`
-   `/admin/events/{event}/participants`
-   `/admin/events/{event}/subjects`
-   `/admin/events/{event}/rooms`
-   `/admin/events/{event}/exams`
-   `/admin/events/{event}/supervisors`
-   `/admin/events/{event}/reports`

Route detail dapat disesuaikan ketika rancangan UI dan route Laravel
difinalisasi.

------------------------------------------------------------------------

## 21. Acceptance Criteria

### 21.1 Multi-Tenant

1.  Beberapa sekolah dapat menggunakan satu instalasi.
2.  Setiap sekolah memiliki kode login sendiri.
3.  Data sekolah terisolasi.
4.  Manipulasi ID tidak memungkinkan akses lintas sekolah.

### 21.2 Event

1.  Admin dapat membuat event.
2.  Event dapat memiliki beberapa kegiatan evaluasi.
3.  Admin dapat menetapkan kelas, mata pelajaran, dan peserta.
4.  Admin dapat mengalokasikan ruang.
5.  Admin dapat menetapkan Guru dan Pengawas.
6.  Seluruh jadwal dan laporan dapat difilter berdasarkan event.
7.  Event tidak dapat berstatus Ready jika persyaratan wajib belum
    terpenuhi.

### 21.3 Question Management

1.  Guru dapat membuat sembilan kategori soal.
2.  Guru dapat mengedit dan melihat preview.
3.  Guru dapat mengunggah soal melalui Excel.
4.  Sistem memvalidasi template dan menampilkan kesalahan.
5.  Paket soal dapat ditetapkan ke event.
6.  Snapshot menjaga konsistensi ujian.

### 21.4 Attendance

1.  Pengawas mempunyai portal khusus.
2.  Pengawas hanya melihat ruang penugasannya.
3.  Sistem otomatis membuat presensi pending setelah penempatan peserta.
4.  Pengawas dapat melakukan presensi individual dan massal.
5.  Sistem mencatat identitas dan waktu verifikasi.
6.  Sistem menyimpan riwayat perubahan presensi.
7.  Siswa yang belum dipresensi tidak dapat memulai ujian.
8.  Siswa berstatus present dapat melanjutkan ke validasi token.
9.  Siswa berstatus late hanya dapat mengikuti ujian jika kebijakan
    mengizinkan.
10. Pengawas tidak dapat memverifikasi peserta dari ruang lain.

### 21.5 Examination Engine

1.  Siswa dapat mengikuti ujian melalui browser.
2.  Sistem menggunakan server-side timer.
3.  Sistem menyimpan jawaban otomatis.
4.  Sistem memulihkan sesi setelah koneksi terputus.
5.  Sistem mengumpulkan jawaban saat waktu habis.
6.  Sistem mencegah attempt ganda yang tidak sah.
7.  Sistem tidak mengirim kunci jawaban ke browser siswa.

### 21.6 Grading dan Reporting

1.  Sistem menilai soal objektif secara otomatis.
2.  Guru dapat mengoreksi essay.
3.  Sistem menghitung nilai akhir.
4.  Sistem membedakan hasil final dan belum final.
5.  Admin dapat memublikasikan hasil.
6.  Siswa hanya melihat hasil sendiri.
7.  Admin dapat mengekspor laporan.

------------------------------------------------------------------------

## 22. Development Roadmap

### Phase 1: Foundation

-   Laravel setup.
-   React dan Inertia.js 3.
-   shadcn/ui.
-   Database setup.
-   Authentication.
-   Multi-tenant middleware.
-   Role authorization.
-   Audit log dasar.

### Phase 2: Master Data

-   Profil sekolah.
-   Guru.
-   Siswa.
-   Pengawas.
-   Kelas.
-   Mata pelajaran.
-   Tahun ajaran.
-   Semester.
-   Ruang.
-   Penugasan mengajar.

### Phase 3: Event Management

-   School events.
-   Event activities.
-   Event workspace.
-   Kepanitiaan.
-   Kelas dan peserta.
-   Mata pelajaran.
-   Alokasi ruang.
-   Penugasan Guru.
-   Event readiness.

### Phase 4: Question Management

-   Question Builder.
-   Bank soal.
-   Sembilan kategori soal.
-   Multimedia.
-   Question Package.
-   Excel Import.
-   Snapshot.

### Phase 5: Examination Management

-   Exam.
-   Exam Sessions.
-   Exam Rooms.
-   Exam Participants.
-   Supervisor Assignments.
-   Token.
-   Validasi kapasitas dan konflik jadwal.

### Phase 6: Supervisor Portal dan Attendance

-   Dashboard Pengawas.
-   Daftar penugasan.
-   Daftar peserta per ruang.
-   Presensi individual.
-   Presensi massal.
-   Attendance Logs.
-   Attendance-gated access.

### Phase 7: Student Exam Engine

-   Dashboard siswa.
-   Eligibility check.
-   Token validation.
-   Examination interface.
-   Timer.
-   Autosave.
-   Session recovery.
-   Submit.

### Phase 8: Grading dan Reporting

-   Automatic scoring.
-   Essay grading.
-   Result calculation.
-   Publication.
-   Event reports.
-   Export Excel/PDF.

### Phase 9: Quality Assurance dan Deployment

-   Security testing.
-   Tenant isolation testing.
-   Attendance authorization testing.
-   Functional testing.
-   Load testing 300 concurrent students.
-   Backup and recovery testing.
-   Production deployment.

------------------------------------------------------------------------

## 23. Risiko dan Mitigasi

  -----------------------------------------------------------------------
  Risiko                  Dampak                  Mitigasi
  ----------------------- ----------------------- -----------------------
  Akses lintas sekolah    Kebocoran data          Tenant middleware,
                                                  policies, composite
                                                  constraints

  Presensi dimanipulasi   Ujian tanpa verifikasi  Backend validation dan
                                                  audit log

  Attempt ganda           Jawaban dan nilai tidak Transaction, row
                          konsisten               locking, unique
                                                  constraint

  Koneksi terputus        Jawaban hilang          Autosave dan session
                                                  recovery

  Timer perangkat         Durasi ujian tidak      Server-side timer
  dimanipulasi            akurat                  

  Kunci jawaban terekspos Integritas ujian        Pisahkan response siswa
                          terganggu               dan data penilaian

  Ruang melebihi          Gangguan pelaksanaan    Capacity validation
  kapasitas                                       

  Pengawas bentrok jadwal Kekurangan petugas      Conflict validation

  Beban akses bersamaan   Sistem lambat           Redis, indexing, queue,
                                                  load testing

  Perubahan soal setelah  Soal ujian berubah      Question snapshot
  penjadwalan                                     
  -----------------------------------------------------------------------

------------------------------------------------------------------------

## 24. Open Technical Decisions

Keputusan berikut belum ditetapkan dan perlu dikonfirmasi sebelum
implementasi final:

1.  Database utama: MySQL atau PostgreSQL.
2.  Kebijakan perangkat: satu perangkat aktif per attempt atau
    perpindahan perangkat diperbolehkan.
3.  Ujian ulang: hanya Admin, Pengawas dengan kewenangan terbatas, atau
    otomatis berdasarkan konfigurasi.
4.  Reset attempt: memerlukan persetujuan Admin atau dapat dilakukan
    Pengawas dengan audit log.
5.  Berbagi bank soal: pribadi, bersama, atau keduanya.
6.  Token: satu token per sesi atau token berbeda per ruang.
7.  Kebijakan siswa terlambat: batas waktu dan mekanisme persetujuan.
8.  Kebijakan pembatalan dan penjadwalan ulang event.
9.  Kebijakan publikasi nilai per ujian atau per event.
10. Kebijakan penguncian dan pembukaan kembali hasil koreksi.

Keputusan yang belum ditetapkan tidak boleh diasumsikan sebagai
persetujuan final.

------------------------------------------------------------------------

## 25. Final Product Direction

School CBT menggunakan arsitektur Multi-Tenant Modular Monolith berbasis
Laravel, React, Inertia.js 3, dan shadcn/ui.

Event menjadi pusat penyelenggaraan kegiatan sekolah. Admin membuat
event terlebih dahulu, kemudian mengelola kelas, peserta, mata
pelajaran, paket soal, jadwal, ruang, Pengawas, token, presensi, dan
hasil melalui Event Workspace.

Sistem menerapkan Attendance-Gated Examination. Pengawas melakukan
verifikasi kehadiran berdasarkan ruang yang menjadi penugasannya. Siswa
hanya dapat memulai ujian setelah memperoleh status kehadiran yang sah,
melewati validasi token, dan memenuhi seluruh ketentuan ujian.

Tiga prinsip utama pengembangan:

1.  **Data isolation:** setiap sekolah hanya dapat mengakses data
    miliknya.
2.  **Examination integrity:** presensi, token, timer, jawaban, dan
    penilaian harus tervalidasi di backend.
3.  **Event consistency:** seluruh komponen kegiatan harus mengikuti
    event yang menjadi induknya.

**Dokumen:** School CBT PRD\
**Versi:** 0.3\
**Status:** Draft untuk tinjauan teknis\
**Dokumen lanjutan:** Database Migration Specification, Eloquent Models,
Relationships, Policies, dan Application Architecture.
