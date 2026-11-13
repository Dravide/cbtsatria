<?php

use App\Domain\Admin\Http\Controllers\AcademicYearController;
use App\Domain\Admin\Http\Controllers\ActivityLogController;
use App\Domain\Admin\Http\Controllers\DashboardController;
use App\Domain\Admin\Http\Controllers\IdentitasAplikasiController;
use App\Domain\Admin\Http\Controllers\KartuAkunController;
use App\Domain\Admin\Http\Controllers\MasterImportController;
use App\Domain\Admin\Http\Controllers\PengaturanAiController;
use App\Domain\Admin\Http\Controllers\RoomController;
use App\Domain\Admin\Http\Controllers\SchoolClassController;
use App\Domain\Admin\Http\Controllers\SchoolProfileController;
use App\Domain\Admin\Http\Controllers\SchoolSettingController;
use App\Domain\Admin\Http\Controllers\SubjectController;
use App\Domain\Admin\Http\Controllers\UserController;
use App\Domain\Admin\Http\Controllers\UserExportController;
use App\Domain\Admin\Http\Controllers\UserImportController;
use App\Domain\Attendance\Http\Controllers\PemantauanEventController;
use App\Domain\Event\Http\Controllers\EventAnnouncementController;
use App\Domain\Event\Http\Controllers\EventCommitteeController;
use App\Domain\Event\Http\Controllers\EventController;
use App\Domain\Event\Http\Controllers\EventParticipantController;
use App\Domain\Event\Http\Controllers\EventSubjectController;
use App\Domain\Event\Http\Controllers\PemasanganPaketController;
use App\Domain\Exam\Http\Controllers\ExamJadwalController;
use App\Domain\Exam\Http\Controllers\ExamPenempatanController;
use App\Domain\Exam\Http\Controllers\ExamPengawasController;
use App\Domain\Exam\Http\Controllers\ExamTokenController;
use App\Domain\Grading\Http\Controllers\Admin\HasilController;
use App\Domain\Grading\Http\Controllers\Admin\LaporanController;
use Illuminate\Support\Facades\Route;

/*
|==========================================================================
| Konsol Admin - seluruh alamat di bawah /admin
|==========================================================================
| Berkas ini adalah kelanjutan `routes/web.php`, bukan berkas yang berdiri
| sendiri: ia di-`require` dari sana, DI DALAM grup middleware `web` yang
| sudah dibuka - sehingga seluruh rute di sini ikut mendapat sesi, Inertia,
| dan konteks tenant tanpa perlu mendaftarkannya lagi.
|
| ---------------------------------------------------------------------
| Mengapa admin dipisah, dan admin yang pertama
| ---------------------------------------------------------------------
| Ia bukan satu peran di antara empat; ia sekitar dua pertiga dari seluruh
| rute aplikasi ini (plate 04-24), dan ia satu-satunya peran yang rutenya
| tersebar di enam modul domain - master data, event, ujian, presensi,
| penilaian, laporan. Berkas campuran sepanjang 800 baris membuat
| pertanyaan "alamat apa saja yang dibuka Admin" hanya bisa dijawab dengan
| membaca seluruhnya.
|
| ---------------------------------------------------------------------
| SEMUA route di bawah ini memakai binding implisit pada model ter-scope
| tenant
| ---------------------------------------------------------------------
| Konsekuensinya bukan hiasan: id milik sekolah lain TIDAK DITEMUKAN,
| sehingga pengguna menerima 404 - bukan 403 yang akan membuktikan barisnya
| ada. Itu sebabnya tidak ada satu pun route di sini yang menerima id
| sekolah, dan tidak ada controller yang memanggil find() manual.
*/

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    // 04 - profil sekolah. Tidak ada {id} di URL: sekolahnya adalah
    // sekolah pengguna yang sedang masuk, dan tidak ada bentuk request
    // yang bisa menyebut sekolah lain.
    Route::get('/sekolah', [SchoolProfileController::class, 'edit'])->name('sekolah');
    Route::put('/sekolah', [SchoolProfileController::class, 'update']);

    // Logo dipisah dari form profil karena ia BERKAS, bukan teks: form
    // profil dikirim sebagai JSON, dan mencampur berkas ke dalamnya
    // memaksa SELURUH form jadi multipart - termasuk saat Admin hanya
    // mengubah alamat. Dua rute juga membuat tombol "Hapus logo" punya
    // tujuan yang jelas.
    Route::post('/sekolah/logo', [SchoolProfileController::class, 'unggahLogo'])
        ->name('sekolah.logo');
    Route::delete('/sekolah/logo', [SchoolProfileController::class, 'hapusLogo'])
        ->name('sekolah.logo.hapus');

    // 23 - pengaturan. Nilainya bawaan untuk event BARU.
    Route::get('/pengaturan', [SchoolSettingController::class, 'edit'])->name('pengaturan');
    Route::put('/pengaturan', [SchoolSettingController::class, 'update']);

    // Tab AI - penyedia model bahasa untuk Generate soal di bank soal.
    // Alamatnya lebih spesifik daripada `/pengaturan` dan metodenya
    // berbeda, sehingga tidak membayangi apa pun. Isinya terpisah karena
    // form Pengaturan menulis kolom `settings` secara UTUH - lihat
    // docblock PengaturanAiController.
    Route::put('/pengaturan/ai', [PengaturanAiController::class, 'update'])->name('pengaturan.ai');
    Route::post('/pengaturan/ai/uji', [PengaturanAiController::class, 'uji'])->name('pengaturan.ai.uji');

    // 24 - log aktivitas. Hanya baca; tidak ada route hapus, selamanya.
    Route::get('/log', [ActivityLogController::class, 'index'])->name('log');

    /*
    |----------------------------------------------------------------------
    | 04b - identitas aplikasi: nama, deskripsi, warna, ikon
    |----------------------------------------------------------------------
    | Dua rute ikon didaftarkan SEBELUM `{jenis}`, dengan alasan yang sama
    | seperti blok ruang: segmen statis yang didaftarkan belakangan akan
    | dicoba sebagai `{jenis}` lebih dulu dan menerima 404 dari `abort_unless`
    | di controller - bukan halaman yang dimaksud.
    |
    | `PUT /sekolah` di atas tidak membayangi apa pun di sini: metodenya
    | berbeda, dan `/sekolah/identitas` hanya punya GET dan PUT.
    */
    Route::get('/sekolah/identitas', [IdentitasAplikasiController::class, 'edit'])->name('sekolah.identitas');
    Route::put('/sekolah/identitas', [IdentitasAplikasiController::class, 'update']);
    Route::post('/sekolah/identitas/ikon/{jenis}', [IdentitasAplikasiController::class, 'unggahIkon'])
        ->whereIn('jenis', ['favicon', 'ikon', 'apple'])
        ->name('sekolah.identitas.ikon');
    Route::delete('/sekolah/identitas/ikon/{jenis}', [IdentitasAplikasiController::class, 'hapusIkon'])
        ->whereIn('jenis', ['favicon', 'ikon', 'apple'])
        ->name('sekolah.identitas.ikon.hapus');

    // 05 - pengguna
    Route::get('/pengguna', [UserController::class, 'index'])->name('pengguna');
    Route::post('/pengguna', [UserController::class, 'store']);
    Route::put('/pengguna/{pengguna}', [UserController::class, 'update']);
    // Ganti username berdiri sendiri, bukan kolom di form Ubah: ia
    // tertempel di kartu login, dan satu salah ketik yang tidak disadari
    // langsung mengunci siswa pada hari ujian. Lihat docblock methodnya.
    Route::put('/pengguna/{pengguna}/username', [UserController::class, 'gantiUsername'])
        ->name('pengguna.username');
    // Kartu akun guru. Segmen statis, jadi ia tidak membayangi
    // `PUT /pengguna/{pengguna}` di atas (metodenya `GET`, dan tidak ada
    // `GET /pengguna/{pengguna}` sama sekali).
    //
    // Satu id menghasilkan satu PDF, banyak id menghasilkan ZIP - lihat
    // docblock `KartuAkunController`. Idnya lewat query, bukan segmen,
    // karena jumlahnya bisa ratusan dan URL yang memuat ratusan id tidak
    // bisa dipakai ulang untuk daftar yang lain.
    Route::get('/pengguna/kartu', [KartuAkunController::class, 'unduh'])->name('pengguna.kartu');
    Route::post('/pengguna/massal', [UserController::class, 'massal'])->name('pengguna.massal');
    Route::post('/pengguna/reset-password', [UserController::class, 'resetPassword'])
        ->name('pengguna.reset');

    /*
    |----------------------------------------------------------------------
    | 05b - impor & ekspor pengguna - PRD §5.7
    |----------------------------------------------------------------------
    | Seluruh segmen STATIS didaftarkan sebelum `{batch}` dan `{ekspor}`.
    | Yang menentukan urutannya: `/pengguna/impor/template` harus
    | mendahului `/pengguna/impor/{batch}/laporan`, karena `{batch}` akan
    | mencoba mencocokkan "template" lebih dulu - dan Admin yang menekan
    | "Unduh template" menerima 404 yang tidak menjelaskan apa pun.
    |
    | Jebakan yang sama sudah didokumentasikan untuk importer soal di
    | blok plate 04, dan `php artisan route:list --path=admin/pengguna`
    | adalah cara memeriksanya sebelum ada pengguna yang menerima 404.
    |
    | `PUT /pengguna/{pengguna}` di atas TIDAK membayangi apa pun di sini:
    | metodenya berbeda, dan tidak ada `GET`/`POST /pengguna/{pengguna}`.
    */
    Route::get('/pengguna/impor', [UserImportController::class, 'index'])->name('pengguna.impor');
    Route::post('/pengguna/impor/unggah', [UserImportController::class, 'unggah'])->name('pengguna.impor.unggah');
    Route::get('/pengguna/impor/template', [UserImportController::class, 'template'])->name('pengguna.impor.template');
    Route::post('/pengguna/impor/{batch}/konfirmasi', [UserImportController::class, 'konfirmasi'])->name('pengguna.impor.konfirmasi');
    Route::delete('/pengguna/impor/{batch}', [UserImportController::class, 'batalkan'])->name('pengguna.impor.batal');
    Route::get('/pengguna/impor/{batch}/laporan', [UserImportController::class, 'laporan'])->name('pengguna.impor.laporan');

    // 05c - ekspor pengguna. TIDAK ada `GET /pengguna/ekspor`: riwayatnya
    // dikirim sebagai prop dari `UserController@index`, karena pertanyaan
    // "berkas saya jadi belum?" muncul tepat setelah tombolnya ditekan -
    // dan jawabannya harus ada di laman yang sedang ia lihat.
    //
    // `{ekspor}` aman dari bayangan `impor/*` di atas: seluruh segmen
    // statis di sini didaftarkan lebih dulu, dan tidak ada nama yang
    // bertabrakan di antara keduanya.
    Route::post('/pengguna/ekspor', [UserExportController::class, 'minta'])->name('pengguna.ekspor');
    Route::get('/pengguna/ekspor/{ekspor}/unduh', [UserExportController::class, 'unduh'])->name('pengguna.ekspor.unduh');

    // 06 - tahun ajaran & semester
    Route::get('/tahun-ajaran', [AcademicYearController::class, 'index'])->name('tahun-ajaran');
    Route::post('/tahun-ajaran', [AcademicYearController::class, 'store']);
    Route::put('/tahun-ajaran/{tahunAjaran}', [AcademicYearController::class, 'update']);
    Route::post('/tahun-ajaran/{tahunAjaran}/aktifkan', [AcademicYearController::class, 'aktifkan'])
        ->name('tahun-ajaran.aktifkan');
    Route::post('/tahun-ajaran/{tahunAjaran}/tutup', [AcademicYearController::class, 'tutup'])
        ->name('tahun-ajaran.tutup');
    Route::post('/tahun-ajaran/{tahunAjaran}/semester', [AcademicYearController::class, 'storeSemester'])
        ->name('semester.store');
    Route::post('/semester/{semester}/aktifkan', [AcademicYearController::class, 'aktifkanSemester'])
        ->name('semester.aktifkan');

    // 07 - kelas. Pemilih TA dikirim sebagai query ?ta=, bukan sebagai
    // bagian dari path: berganti TA berarti berganti daftar, dan URL
    // yang berubah adalah cara paling jujur menyatakannya.
    Route::get('/kelas', [SchoolClassController::class, 'index'])->name('kelas');
    Route::post('/kelas', [SchoolClassController::class, 'store']);
    Route::put('/kelas/{kelas}', [SchoolClassController::class, 'update']);
    Route::get('/kelas/{kelas}/anggota', [SchoolClassController::class, 'anggota'])->name('kelas.anggota');
    Route::put('/kelas/{kelas}/anggota', [SchoolClassController::class, 'simpanAnggota']);

    // 08 - mata pelajaran + penugasan mengajar
    Route::get('/mapel', [SubjectController::class, 'index'])->name('mapel');
    Route::post('/mapel', [SubjectController::class, 'store']);
    Route::put('/mapel/{mapel}', [SubjectController::class, 'update']);
    Route::post('/mapel/{mapel}/tugaskan', [SubjectController::class, 'tugaskan'])->name('mapel.tugaskan');
    Route::delete('/penugasan/{penugasan}', [SubjectController::class, 'batalkan'])->name('penugasan.batal');

    // 09 - ruang + rincian (tautan pintu & kode pengawas)
    Route::get('/ruang', [RoomController::class, 'index'])->name('ruang');
    Route::post('/ruang', [RoomController::class, 'store']);
    /*
    |----------------------------------------------------------------------
    | Tiga rute massal didaftarkan SEBELUM `/ruang/{ruang}`
    |----------------------------------------------------------------------
    | Tanpa itu, "kartu" dan "terbitkan-massal" dicoba sebagai id `{ruang}`
    | lebih dulu - dan hasilnya bukan halaman kartu melainkan 404 dari route
    | model binding, yang tidak menjelaskan apa pun. Jebakan yang sama sudah
    | dicatat untuk importer; `php artisan route:list --path=admin/ruang`
    | yang memeriksanya.
    */
    Route::get('/ruang/kartu', [RoomController::class, 'kartu'])->name('ruang.kartu');
    Route::post('/ruang/terbitkan-massal', [RoomController::class, 'terbitkanMassal'])->name('ruang.terbitkan-massal');
    Route::put('/ruang/kapasitas-massal', [RoomController::class, 'kapasitasMassal'])->name('ruang.kapasitas-massal');
    Route::put('/ruang/{ruang}', [RoomController::class, 'update']);
    Route::get('/ruang/{ruang}', [RoomController::class, 'show'])->name('ruang.detail');
    Route::post('/ruang/{ruang}/terbitkan', [RoomController::class, 'terbitkan'])->name('ruang.terbitkan');
    Route::post('/ruang/{ruang}/cabut', [RoomController::class, 'cabut'])->name('ruang.cabut');

    /*
    |----------------------------------------------------------------------
    | Impor master data - mata pelajaran dan ruang
    |----------------------------------------------------------------------
    | Satu blok untuk dua jenis, dan `{jenis}` ada di URL karena itu
    | satu-satunya hal yang membedakan keduanya. Jenisnya divalidasi
    | menjadi enum SEBELUM satu baris pun dibaca, sehingga URL yang
    | menyebut jenis tidak dikenal menerima 404.
    |
    | Didaftarkan di SATU tempat, bukan di bawah `/mapel` dan `/ruang`
    | masing-masing: dua blok kembar adalah dua tempat yang harus tetap
    | sepakat soal urutan rute - dan yang kedua selalu yang terlupakan
    | saat salah satunya ditambah.
    |
    | Jalurnya `/impor/{jenis}`, bukan `/mapel/impor` dan `/ruang/impor`,
    | dan itu bukan pilihan gaya: `PUT /ruang/{ruang}` sudah ada, dan
    | `GET /ruang/impor` akan bertabrakan dengannya pada hari seseorang
    | menambahkan rute itu. `/impor/*` tidak berbagi satu pun segmen
    | dengan rute yang sudah terdaftar.
    |
    | URUTANNYA tetap dijaga seperti importer lain: seluruh segmen
    | statis (`unggah`, `template`) didaftarkan SEBELUM rute yang memuat
    | `{batch}`. Di blok ini keduanya kebetulan tidak bertabrakan -
    | `/impor/{jenis}/template` punya tiga segmen dan
    | `/impor/{jenis}/{batch}/laporan` punya empat - tetapi
    | `php artisan route:list --path=admin/impor` tetap yang memeriksanya,
    | bukan ingatan siapa pun.
    */
    Route::get('/impor/{jenis}', [MasterImportController::class, 'index'])->name('master.impor');
    Route::post('/impor/{jenis}/unggah', [MasterImportController::class, 'unggah'])->name('master.impor.unggah');
    Route::get('/impor/{jenis}/template', [MasterImportController::class, 'template'])->name('master.impor.template');
    Route::post('/impor/{jenis}/{batch}/konfirmasi', [MasterImportController::class, 'konfirmasi'])
        ->name('master.impor.konfirmasi');
    Route::get('/impor/{jenis}/{batch}/laporan', [MasterImportController::class, 'laporan'])
        ->name('master.impor.laporan');

    /*
    |----------------------------------------------------------------------
    | 10-15 Event Workspace
    |----------------------------------------------------------------------
    | Rute event bersarang di bawah /admin/events, dan id event selalu
    | ada di URL. Ia tidak bisa dihilangkan: laman-laman ini berbicara
    | tentang SATU event, dan URL yang tidak menyebutnya akan membuat
    | tautan yang dibagikan ke rekan kerja membuka event yang salah.
    |
    | Sesi tetap menyimpan event yang terakhir dibuka, tetapi itu untuk
    | sidebar - bukan sebagai pengganti id di URL. Lihat catatan di
    | EventController tentang mengapa keduanya dibutuhkan.
    */
    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::get('/events/create', [EventController::class, 'create'])->name('events.create');
    Route::post('/events', [EventController::class, 'store']);
    Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
    Route::get('/events/{event}/ubah', [EventController::class, 'edit'])->name('events.edit');
    Route::put('/events/{event}', [EventController::class, 'update']);
    // Hapus hanya untuk event draft yang belum punya ujian - lihat docblock
    // `EventController::destroy()`. Rutenya tetap ada untuk seluruh event dan
    // penolakannya berupa kalimat, bukan 404: Admin yang membacanya di daftar
    // harus tahu APA yang harus dilakukan, dan tombol yang hilang tanpa
    // keterangan hanya membuatnya menebak.
    Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.hapus');
    Route::post('/events/{event}/status', [EventController::class, 'ubahStatus'])->name('events.status');

    /*
    |----------------------------------------------------------------------
    | Papan pengumuman event - ditambahkan 2026-11-14
    |----------------------------------------------------------------------
    | Satu-satunya tulisan di konsol Admin yang dibaca SISWA, bukan tentang
    | siswa. Rutenya di bawah event karena pengumuman tidak bermakna di luar
    | eventnya, dan tidak ada rute daftar lintas event: pertanyaan "apa yang
    | perlu dibaca siswa" selalu tentang satu kegiatan.
    */
    Route::get('/events/{event}/pengumuman', [EventAnnouncementController::class, 'index'])
        ->name('events.pengumuman');
    Route::post('/events/{event}/pengumuman', [EventAnnouncementController::class, 'store'])
        ->name('events.pengumuman.store');
    Route::put('/events/{event}/pengumuman/{pengumuman}', [EventAnnouncementController::class, 'update'])
        ->name('events.pengumuman.update');
    Route::delete('/events/{event}/pengumuman/{pengumuman}', [EventAnnouncementController::class, 'destroy'])
        ->name('events.pengumuman.hapus');

    // 13 - kepanitiaan
    Route::get('/events/{event}/panitia', [EventCommitteeController::class, 'index'])->name('events.panitia');
    Route::post('/events/{event}/panitia', [EventCommitteeController::class, 'store']);
    Route::post('/events/{event}/panitia/massal', [EventCommitteeController::class, 'massal'])->name('events.panitia.massal');
    // Tiga segmen, jadi tidak pernah bertabrakan dengan `{panitia}` di
    // bawahnya - dan urutannya sengaja sesudah `massal`, supaya kedua
    // rute tambahan itu terbaca berdampingan.
    Route::post('/events/{event}/panitia/{panitia}/kode', [EventCommitteeController::class, 'terbitkanKode'])->name('events.panitia.kode');
    Route::put('/events/{event}/panitia/{panitia}', [EventCommitteeController::class, 'update']);
    Route::delete('/events/{event}/panitia/{panitia}', [EventCommitteeController::class, 'destroy']);

    /*
    |----------------------------------------------------------------------
    | 13b - unduhan & terbitan massal dari laman kepanitiaan
    |----------------------------------------------------------------------
    | Semuanya menempel pada EVENT, bukan pada satu baris panitia, dan
    | karena itu didaftarkan SESUDAH `{panitia}` - menaruhnya di atas membuat
    | "ekspor" terbaca seolah-olah ia nama panitia.
    |
    | Dua yang GET tidak mengubah apa pun yang bisa dibatalkan, dan tautan
    | yang bisa dibuka di tab baru, disimpan, lalu dipakai lagi besok, lebih
    | berguna daripada tombol yang harus ditekan ulang setiap kali kartunya
    | hilang. Yang POST menerbitkan kode, dan ia hanya menyentuh baris yang
    | BELUM punya kode - kartu yang sudah dicetak tidak boleh mati olehnya.
    */
    Route::get('/events/{event}/panitia/ekspor', [EventCommitteeController::class, 'ekspor'])->name('events.panitia.ekspor');
    Route::get('/events/{event}/panitia/kartu', [EventCommitteeController::class, 'kartu'])->name('events.panitia.kartu');
    Route::post('/events/{event}/panitia/kode-massal', [EventCommitteeController::class, 'terbitkanKodeMassal'])->name('events.panitia.kode-massal');

    // 14 - kelas & peserta
    Route::get('/events/{event}/peserta', [EventParticipantController::class, 'index'])->name('events.peserta');
    Route::put('/events/{event}/peserta/kelas', [EventParticipantController::class, 'simpanKelas'])->name('events.peserta.kelas');
    Route::post('/events/{event}/peserta/tarik', [EventParticipantController::class, 'tarik'])->name('events.peserta.tarik');
    Route::post('/events/{event}/peserta/tambah', [EventParticipantController::class, 'tambah'])->name('events.peserta.tambah');
    Route::post('/events/{event}/peserta/massal', [EventParticipantController::class, 'massal'])->name('events.peserta.massal');
    Route::put('/events/{event}/peserta/ruang', [EventParticipantController::class, 'simpanRuang'])->name('events.peserta.ruang');

    // 15 - mata pelajaran & paket soal. Pemasangan paket punya rutenya
    // sendiri, bukan menyatu dengan PUT /mapel: menetapkan mapel event
    // dan memasang paket ke salah satunya adalah dua keputusan berbeda,
    // dan yang kedua menulis baris riwayat yang menyebut siapa dan kapan.
    Route::get('/events/{event}/mapel', [EventSubjectController::class, 'index'])->name('events.mapel');
    Route::put('/events/{event}/mapel', [EventSubjectController::class, 'store']);
    Route::put('/events/{event}/mapel/paket', [PemasanganPaketController::class, 'simpan'])
        ->name('events.mapel.paket');
    Route::get('/events/{event}/mapel/{mapel}/paket', [PemasanganPaketController::class, 'riwayat'])
        ->name('events.mapel.paket.riwayat');

    // 16 - jadwal & sesi. Ujian lahir di sini karena sesi tidak bisa ada
    // tanpa ujiannya, dan plate 16 mengelompokkan tabelnya per ujian.
    Route::get('/events/{event}/jadwal', [ExamJadwalController::class, 'index'])->name('events.jadwal');
    Route::post('/events/{event}/jadwal/deteksi', [ExamJadwalController::class, 'deteksi'])->name('events.jadwal.deteksi');

    Route::post('/events/{event}/ujian', [ExamJadwalController::class, 'simpanUjian'])->name('events.ujian.store');
    Route::put('/events/{event}/ujian/{ujian}', [ExamJadwalController::class, 'ubahUjian'])->name('events.ujian.update');
    // Ujian yang belum pernah berjalan boleh dihapus beserta sesinya - lihat
    // `ExamJadwalController::hapusUjian()`. Sesi yang sudah berjalan menolak.
    Route::delete('/events/{event}/ujian/{ujian}', [ExamJadwalController::class, 'hapusUjian'])->name('events.ujian.hapus');

    Route::post('/events/{event}/ujian/{ujian}/sesi', [ExamJadwalController::class, 'simpanSesi'])->name('events.sesi.store');
    Route::put('/events/{event}/ujian/{ujian}/sesi/{sesi}', [ExamJadwalController::class, 'ubahSesi'])->name('events.sesi.update');
    // Graf StatusSesi mewajibkan Draft -> Siap -> Berjalan, dan sampai
    // 2026-11-07 tidak ada satu pun rute yang bisa memindahkan sesi ke
    // Siap. Akibatnya "Buka" SELALU ditolak untuk sesi yang dibuat lewat
    // laman jadwal. Rute ini penghasilnya - lihat `SesiService::siapkan()`.
    Route::post('/events/{event}/ujian/{ujian}/sesi/{sesi}/siapkan', [ExamJadwalController::class, 'siapkanSesi'])->name('events.sesi.siapkan');
    Route::post('/events/{event}/ujian/{ujian}/sesi/{sesi}/mulai', [ExamJadwalController::class, 'mulaiSesi'])->name('events.sesi.mulai');
    Route::post('/events/{event}/ujian/{ujian}/sesi/{sesi}/tutup', [ExamJadwalController::class, 'tutupSesi'])->name('events.sesi.tutup');
    // `StatusSesi::Batal` ada di enum dan grafnya sejak Fase 5, dan lima
    // pembaca sudah menanganinya - tetapi sampai sekarang tidak ada satu pun
    // penulisnya. Sesi yang ruangnya berisi berita acara final tidak bisa
    // DIHAPUS (RESTRICT, dan penolakannya menyuruh membatalkan), jadi tanpa
    // rute ini satu sesi yang batal terselenggara tidak punya jalan keluar
    // sama sekali. Lihat `SesiService::batalkan()`.
    Route::post('/events/{event}/ujian/{ujian}/sesi/{sesi}/batal', [ExamJadwalController::class, 'batalSesi'])->name('events.sesi.batal');
    Route::delete('/events/{event}/ujian/{ujian}/sesi/{sesi}', [ExamJadwalController::class, 'hapusSesi'])->name('events.sesi.hapus');

    // 16b - penempatan peserta ke ruang (wireframe plate 16b, PRD §9.5).
    // Laman tersendiri, bukan tombol di laman jadwal: keputusan yang
    // diambil di sini - siapa duduk di ruang mana - dicetak dan ditempel
    // di pintu, dan sampai sekarang tidak ada satu pun layar yang
    // memperlihatkannya SEBELUM ia berlaku.
    //
    // Sampai 2026-11-09 ada rute kedua, `POST /jadwal/sebar`, yang menyebar
    // per sesi tanpa pratinjau. Ia tidak pernah punya pemanggil - tak satu
    // pun tombol di `Jadwal.tsx` menembaknya - dan semantiknya per-sesi,
    // persis yang dibalik pada 07 Nov 2026. Rutenya dihapus, bukan
    // dibiarkan sebagai "jalur cepat": dua jalan menuju kursi yang sama
    // berarti dua susunan yang bisa berselisih, dan yang salah baru
    // ketahuan saat siswa berdiri di depan pintu.
    //
    // Keduanya rute EVENT, tanpa `{sesi}` di lintasannya. Peta ruangnya
    // berlaku sepanjang event, jadi sesi bukan bagian dari alamatnya -
    // dan rute yang menyebut sesi akan menyiratkan peta yang berbeda per
    // sesi, persis yang dibalik pada 07 Nov 2026.
    //
    // PENGECUALIAN SATU-SATUNYA adalah rute SESI di bawah, yang menyebut
    // `{sesi}` pada lintasannya. `{sesi}` di sana BUKAN alamat peta - peta
    // ruangnya tetap satu untuk seluruh event (itulah yang dibalik pada
    // 07 Nov 2026) - ia SASARAN terapan: peta event yang sama, dipakai ke
    // satu sesi itu saja. "Pakai penempatan" di tabel kelayakan adalah jalan
    // keluar untuk sesi yang belum bisa disebar bersama seluruh event.
    Route::get('/events/{event}/penempatan', [ExamPenempatanController::class, 'index'])->name('events.penempatan');
    Route::post('/events/{event}/penempatan', [ExamPenempatanController::class, 'sebar'])->name('events.penempatan.sebar');
    Route::post('/events/{event}/penempatan/kocok-ulang', [ExamPenempatanController::class, 'kocokUlang'])->name('events.penempatan.kocok-ulang');
    Route::post('/events/{event}/penempatan/sesi/{sesi}', [ExamPenempatanController::class, 'pakaiSesi'])->name('events.penempatan.sesi');

    // 16c - denah tempat duduk (wireframe plate 16c). Menyusul rute
    // penempatan, dan urutannya mengikat: `/penempatan/denah` harus
    // didaftarkan SESUDAH `/penempatan`, karena Laravel mencocokkan rute
    // berurutan dan `denah` bukan parameter dari rute di atasnya.
    //
    // Laman ini menghitung ulang kocokan dari benih tersimpan, sama dengan
    // laman Penempatan - lihat ExamPenempatanController::denah().
    Route::get('/events/{event}/penempatan/denah', [ExamPenempatanController::class, 'denah'])->name('events.penempatan.denah');

    // 16c-kertas - denah yang sama, sebagai PDF untuk ditempel di meja.
    // Rutenya terpisah, bukan `?pdf=1`, karena yang menentukan bentuk
    // keluarannya adalah jenis responsnya: satu laman Inertia, satu berkas.
    // Dipisah juga supaya tautannya bisa dikirim ke panitia tanpa membawa
    // pilihan tampilan apa pun.
    Route::get('/events/{event}/penempatan/denah/pdf', [ExamPenempatanController::class, 'denahPdf'])->name('events.penempatan.denah-pdf');

    // 16d - tablo daftar hadir per ruang, sebagai PDF (wireframe plate 16d).
    // Satu rute untuk satu ruang maupun seluruh ruang: yang membedakannya
    // hanya `?ruang=`, dan dua rute akan berarti dua tempat yang harus
    // diperbaiki bila bentuk tabelnya berubah.
    Route::get('/events/{event}/penempatan/tablo', [ExamPenempatanController::class, 'tablo'])->name('events.penempatan.tablo');

    // 17 - pengawas & penempatan ruang. Barisnya ruang-SESI, bukan ruang:
    // pengawas sesi pagi belum tentu pengawas sesi siang.
    Route::get('/events/{event}/pengawas', [ExamPengawasController::class, 'index'])->name('events.pengawas');
    Route::post('/events/{event}/pengawas/otomatis', [ExamPengawasController::class, 'otomatis'])->name('events.pengawas.otomatis');
    Route::post('/events/{event}/pengawas/ruang/{ruang}', [ExamPengawasController::class, 'tugaskan'])->name('events.pengawas.tugaskan');
    Route::delete('/events/{event}/pengawas/{tugas}', [ExamPengawasController::class, 'batalkan'])->name('events.pengawas.batal');

    // 18 - token ujian. Pembacaan token MENULIS satu baris riwayat; lihat
    // ExamTokenController.
    Route::get('/events/{event}/token', [ExamTokenController::class, 'index'])->name('events.token');
    Route::post('/events/{event}/token/sesi/{sesi}', [ExamTokenController::class, 'terbitkan'])->name('events.token.terbitkan');
    Route::post('/events/{event}/token/{token}/baca', [ExamTokenController::class, 'baca'])->name('events.token.baca');
    Route::post('/events/{event}/token/{token}/cabut', [ExamTokenController::class, 'cabut'])->name('events.token.cabut');

    /*
    |----------------------------------------------------------------------
    | 19-20 Presensi & monitoring - Fase 6
    |----------------------------------------------------------------------
    | Dua laman ini adalah kolom "Presensi: Monitoring atau koreksi"
    | milik PRD §3.5, dan keduanya BERBEDA dari laman pengawas di
    | /pengawas/... - pertanyaannya bukan pertanyaan yang sama. Barisnya
    | ruang-sesi, bukan kursi; lihat PemantauanEventController.
    */
    Route::get('/events/{event}/presensi', [PemantauanEventController::class, 'presensi'])
        ->name('events.presensi');
    Route::post('/events/{event}/presensi/koreksi', [PemantauanEventController::class, 'koreksi'])
        ->name('events.presensi.koreksi');

    Route::get('/events/{event}/monitoring', [PemantauanEventController::class, 'monitoring'])
        ->name('events.monitoring');

    // 20b - keadaan per peserta SATU ruang, di laman sendiri.
    //
    // Didaftarkan SESUDAH `/monitoring` karena Laravel mencocokkan berurutan -
    // polanya sama dengan `/penempatan/denah` di atas. Tanpa urutan itu,
    // `/monitoring/ruang/9` tetap cocok dengan `/monitoring` lebih dulu dan
    // Admin menerima papan lintas ruang saat ia meminta satu ruang.
    //
    // Lamannya dibuka di TAB BARU dari tombol "Lihat peserta", dan itu
    // keputusan yang sudah dipertimbangkan: papan lintas ruang disegarkan
    // terus selama ujian berjalan, dan membawa daftar seribu anak di
    // payload-nya berarti setiap penyegaran mengirim ulang daftar yang belum
    // tentu dibuka. Tab tersendiri juga membuat Admin yang menelusuri satu
    // anak tidak kehilangan tempatnya di rekap.
    Route::get('/events/{event}/monitoring/ruang/{ruang}', [PemantauanEventController::class, 'monitoringRuang'])
        ->name('events.monitoring.ruang');

    // Buka kunci dari papan monitoring. Sampai 2026-11-11 satu-satunya jalan
    // membuka attempt terkunci adalah tautan ruang pengawas, dan tautan itu
    // 404 untuk siswa yang sudah berpindah ke sesi berikutnya - justru anak
    // yang paling perlu dibuka. Admin yang sedang memantau melihatnya
    // terkunci tanpa satu pun tombol.
    //
    // `{peserta}` di sini adalah id `exam_participants`, bukan id siswa:
    // satu anak punya satu baris peserta per ujian, dan yang terkunci adalah
    // pengerjaannya, bukan akunnya.
    Route::post('/events/{event}/monitoring/peserta/{peserta}/buka', [PemantauanEventController::class, 'bukaKunci'])
        ->name('events.monitoring.buka');

    // Reset dan hentikan manual - dua tindakan Admin atas satu pengerjaan,
    // keduanya dari papan yang sama dengan `buka` di atas.
    //
    // Reset membatalkan attempt terakhir dan menghapus nilainya, sehingga
    // anak mengulang dari soal pertama lewat gerbang biasa. "Hentikan"
    // mengunci attempt dengan pesan yang ditulis Admin, dan pesan itu yang
    // dibaca siswa di plate 25 - lihat `PengendaliPengerjaan`.
    //
    // Keduanya menuntut kalimat dari Admin, dan keduanya tercatat di
    // `activity_logs`: satu menghapus nilai seorang anak, yang lain
    // menghentikan ujian yang sedang ia kerjakan.
    Route::post('/events/{event}/monitoring/peserta/{peserta}/reset', [PemantauanEventController::class, 'resetUjian'])
        ->name('events.monitoring.reset');
    Route::post('/events/{event}/monitoring/peserta/{peserta}/hentikan', [PemantauanEventController::class, 'blokirPeserta'])
        ->name('events.monitoring.hentikan');

    // Reset SELURUH peserta satu ruang - ditambahkan 2026-11-14. Alamatnya
    // memakai `ruang` sebagai segmen statis sesudah `peserta/...`, bukan
    // `peserta/massal`: `{peserta}` adalah model binding, dan segmen statis
    // yang didaftarkan sesudahnya tidak akan pernah tercapai.
    Route::post('/events/{event}/monitoring/ruang/{ruang}/reset-massal', [PemantauanEventController::class, 'resetMassal'])
        ->name('events.monitoring.reset-massal');

    /*
    |----------------------------------------------------------------------
    | 21-22 Publikasi hasil & laporan - Fase 8
    |----------------------------------------------------------------------
    | Plate 21 mempublikasikan SATU event, bukan satu ujian, sehingga
    | alamatnya memuat {event} dan tidak memuat {ujian}. Enam aksinya
    | terpisah karena masing-masing menulis fakta yang berbeda: terbit
    | menandai hasil terlihat siswa, tarik mencabutnya (dan membuka kunci
    | koreksi), buka-koreksi membuka kunci TANPA menarik publikasi
    | (blueprint keputusan #10), umumkan mengirim pemberitahuan,
    | tampilan mengatur dua kotak centang, hitung-ulang memperbaiki angka
    | setelah kunci soal dibetulkan.
    |
    | Laporan memakai event AKTIF dari sesi, bukan {event} di URL: plate
    | 22 menulis "Event: UAS Ganjil" sebagai keterangan, dan Admin yang
    | berpindah event melakukannya lewat sidebar.
    |
    | `/laporan/berkas/{ekspor}` didaftarkan sebelum `/laporan` yang
    | berisi segmen dinamis, dan namanya sengaja memakai `berkas` seperti
    | rute media di grup guru: satu segmen telanjang di bawah `/admin`
    | akan bertabrakan dengan setiap halaman baru.
    */
    Route::get('/events/{event}/hasil', [HasilController::class, 'index'])->name('events.hasil');
    Route::post('/events/{event}/hasil/terbitkan', [HasilController::class, 'terbitkan'])
        ->name('events.hasil.terbitkan');
    Route::post('/events/{event}/hasil/tarik', [HasilController::class, 'tarik'])
        ->name('events.hasil.tarik');
    Route::post('/events/{event}/hasil/buka-koreksi', [HasilController::class, 'bukaKoreksi'])
        ->name('events.hasil.buka-koreksi');
    Route::post('/events/{event}/hasil/kunci-koreksi', [HasilController::class, 'kunciKoreksi'])
        ->name('events.hasil.kunci-koreksi');
    Route::post('/events/{event}/hasil/umumkan', [HasilController::class, 'umumkan'])
        ->name('events.hasil.umumkan');
    Route::post('/events/{event}/hasil/tampilan', [HasilController::class, 'aturTampilan'])
        ->name('events.hasil.tampilan');
    Route::post('/events/{event}/hasil/hitung-ulang', [HasilController::class, 'hitungUlang'])
        ->name('events.hasil.hitung-ulang');

    Route::get('/laporan', [LaporanController::class, 'index'])->name('laporan');
    Route::post('/laporan', [LaporanController::class, 'mintaEkspor'])->name('laporan.ekspor');
    Route::get('/laporan/berkas/{ekspor}', [LaporanController::class, 'unduh'])->name('laporan.unduh');

    Route::get('/', DashboardController::class)->name('dashboard');
});
