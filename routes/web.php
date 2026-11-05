<?php

use App\Domain\Admin\Http\Controllers\AcademicYearController;
use App\Domain\Admin\Http\Controllers\ActivityLogController;
use App\Domain\Admin\Http\Controllers\DashboardController;
use App\Domain\Admin\Http\Controllers\RoomController;
use App\Domain\Admin\Http\Controllers\SchoolClassController;
use App\Domain\Admin\Http\Controllers\SchoolProfileController;
use App\Domain\Admin\Http\Controllers\SchoolSettingController;
use App\Domain\Admin\Http\Controllers\SubjectController;
use App\Domain\Admin\Http\Controllers\UserController;
use App\Domain\Admin\Http\Controllers\UserImportController;
use App\Domain\Attendance\Http\Controllers\BeritaAcaraController;
use App\Domain\Attendance\Http\Controllers\BerkasLaporanController;
use App\Domain\Attendance\Http\Controllers\MonitoringController;
use App\Domain\Attendance\Http\Controllers\PelanggaranController;
use App\Domain\Attendance\Http\Controllers\PemantauanEventController;
use App\Domain\Attendance\Http\Controllers\PintuRuangController;
use App\Domain\Attendance\Http\Controllers\PresensiController;
use App\Domain\Attendance\Http\Controllers\RuangTugasController;
use App\Domain\Attendance\Http\Controllers\Siswa\GerbangSiswaController;
use App\Domain\Attendance\Http\Controllers\Siswa\JadwalSiswaController;
use App\Domain\Attendance\Http\Controllers\Siswa\KerjakanSiswaController;
use App\Domain\Attendance\Http\Controllers\Siswa\NilaiSiswaController;
use App\Domain\Attendance\Http\Controllers\Siswa\PelanggaranSiswaController;
use App\Domain\Attendance\Http\Controllers\Siswa\SelesaiSiswaController;
use App\Domain\Attendance\Http\Controllers\Siswa\TerkunciSiswaController;
use App\Domain\Attendance\Http\Controllers\TokenRuangController;
use App\Domain\Event\Http\Controllers\EventCommitteeController;
use App\Domain\Event\Http\Controllers\EventController;
use App\Domain\Event\Http\Controllers\EventParticipantController;
use App\Domain\Event\Http\Controllers\EventSubjectController;
use App\Domain\Event\Http\Controllers\PemasanganPaketController;
use App\Domain\Exam\Http\Controllers\ExamJadwalController;
use App\Domain\Exam\Http\Controllers\ExamMediaSiswaController;
use App\Domain\Exam\Http\Controllers\ExamPengawasController;
use App\Domain\Exam\Http\Controllers\ExamTokenController;
use App\Domain\Grading\Http\Controllers\Admin\HasilController;
use App\Domain\Grading\Http\Controllers\Admin\LaporanController;
use App\Domain\Grading\Http\Controllers\Guru\AnalisisController;
use App\Domain\Grading\Http\Controllers\Guru\KoreksiController;
use App\Domain\Grading\Http\Controllers\Guru\NilaiController;
use App\Domain\Identity\Http\Controllers\Auth\LoginController;
use App\Domain\Identity\Http\Controllers\Auth\PasswordResetLinkController;
use App\Domain\Question\Http\Controllers\DashboardGuruController;
use App\Domain\Question\Http\Controllers\MediaSoalController;
use App\Domain\Question\Http\Controllers\PratinjauController;
use App\Domain\Question\Http\Controllers\QuestionController;
use App\Domain\Question\Http\Controllers\QuestionImportController;
use App\Domain\Question\Http\Controllers\QuestionPackageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pintu masuk
|--------------------------------------------------------------------------
| Satu form login untuk keempat peran. Tidak ada alamat login per peran, dan
| tidak ada kode sekolah atau NPSN di layar ini - username membawa identitas
| akun, dan akun membawa school_id. Rute mengikuti wireframe plate 01-02.
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    Route::get('/lupa-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Pintu ruang - SENGAJA di luar grup auth
|--------------------------------------------------------------------------
| Yang membukanya belum tentu punya akun, dan itu alasan keberadaannya:
| pengawas berdiri di depan pintu kelas, memindai kartu yang ditempel Admin
| di daun pintu, lalu mengetik enam angka.
|
| Dua rute ini juga TIDAK diberi default `tenant`, sehingga EnsureTenantContext
| tidak menolaknya dengan 404 - saat permintaan datang memang belum ada
| konteks sekolah. Konteksnya baru ditetapkan AksesPengawas, dari baris
| room_entry_links yang di-resolve slug ini.
|
| Slug di-resolve dengan `tanpaTenant()` di dalam controller, dan itu WAJIB -
| lihat catatan di PintuRuangController.
*/
Route::get('/p/{slug}', [PintuRuangController::class, 'tampilkan'])->name('pintu.ruang');

// `throttle:pintu-ruang` - PRD §19.2. Hanya POST-nya: yang ditebak adalah
// KODENYA, dan GET halaman pintu tidak menerima kode apa pun. Limiter-nya
// didefinisikan di AppServiceProvider dengan `->response()` yang mengembalikan
// flash `galat`, bukan halaman 429 - pengawas di depan pintu kelas tidak boleh
// menerima halaman galat telanjang. Lihat App\Support\PembatasLaju.
Route::post('/p/{slug}', [PintuRuangController::class, 'buka'])
    ->middleware('throttle:pintu-ruang')
    ->name('pintu.ruang.buka');

// Menyerahkan kembali hibah sesi. Di luar grup auth dengan alasan yang sama:
// yang memegangnya tidak punya akun untuk keluar darinya.
Route::post('/p/{slug}/keluar', [PintuRuangController::class, 'keluar'])->name('pintu.ruang.keluar');

/*
|--------------------------------------------------------------------------
| Tujuan setelah login
|--------------------------------------------------------------------------
| Satu rute dashboard yang bercabang pada peran, bukan empat rute yang
| dipilih di UI. Wireframe plate 01: "Setelah valid -> redirect sesuai role,
| bukan dipilih di UI."
*/
Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return redirect()->route('dashboard');
    });

    Route::get('/dashboard', function () {
        return redirect()->to(auth()->user()->role->dashboardPath());
    })->name('dashboard');

    Route::get('/admin', DashboardController::class)
        ->name('admin.dashboard');

    /*
    |----------------------------------------------------------------------
    | Master data - wireframe plate 04-09, 23, 24
    |----------------------------------------------------------------------
    | Semua route di bawah ini memakai binding implisit pada model yang
    | ter-scope tenant. Konsekuensinya bukan hiasan: id milik sekolah lain
    | TIDAK DITEMUKAN, sehingga pengguna menerima 404 - bukan 403 yang akan
    | membuktikan barisnya ada. Itu sebabnya tidak ada satu pun route di sini
    | yang menerima id sekolah, dan tidak ada controller yang memanggil
    | find() manual.
    */
    Route::prefix('admin')->name('admin.')->group(function () {
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

        // 24 - log aktivitas. Hanya baca; tidak ada route hapus, selamanya.
        Route::get('/log', [ActivityLogController::class, 'index'])->name('log');

        // 05 - pengguna
        Route::get('/pengguna', [UserController::class, 'index'])->name('pengguna');
        Route::post('/pengguna', [UserController::class, 'store']);
        Route::put('/pengguna/{pengguna}', [UserController::class, 'update']);
        Route::post('/pengguna/massal', [UserController::class, 'massal'])->name('pengguna.massal');
        Route::post('/pengguna/reset-password', [UserController::class, 'resetPassword'])
            ->name('pengguna.reset');

        /*
        |------------------------------------------------------------------
        | 05b - impor & ekspor pengguna - PRD §5.7
        |------------------------------------------------------------------
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
        Route::get('/pengguna/impor/{batch}/laporan', [UserImportController::class, 'laporan'])->name('pengguna.impor.laporan');

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
        Route::put('/ruang/{ruang}', [RoomController::class, 'update']);
        Route::get('/ruang/{ruang}', [RoomController::class, 'show'])->name('ruang.detail');
        Route::post('/ruang/{ruang}/terbitkan', [RoomController::class, 'terbitkan'])->name('ruang.terbitkan');
        Route::post('/ruang/{ruang}/cabut', [RoomController::class, 'cabut'])->name('ruang.cabut');

        /*
        |------------------------------------------------------------------
        | 10-15 Event Workspace
        |------------------------------------------------------------------
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
        Route::post('/events/{event}/status', [EventController::class, 'ubahStatus'])->name('events.status');

        // 13 - kepanitiaan
        Route::get('/events/{event}/panitia', [EventCommitteeController::class, 'index'])->name('events.panitia');
        Route::post('/events/{event}/panitia', [EventCommitteeController::class, 'store']);
        Route::put('/events/{event}/panitia/{panitia}', [EventCommitteeController::class, 'update']);
        Route::delete('/events/{event}/panitia/{panitia}', [EventCommitteeController::class, 'destroy']);

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
        Route::post('/events/{event}/jadwal/sebar', [ExamJadwalController::class, 'sebar'])->name('events.jadwal.sebar');

        Route::post('/events/{event}/ujian', [ExamJadwalController::class, 'simpanUjian'])->name('events.ujian.store');
        Route::put('/events/{event}/ujian/{ujian}', [ExamJadwalController::class, 'ubahUjian'])->name('events.ujian.update');

        Route::post('/events/{event}/ujian/{ujian}/sesi', [ExamJadwalController::class, 'simpanSesi'])->name('events.sesi.store');
        Route::put('/events/{event}/ujian/{ujian}/sesi/{sesi}', [ExamJadwalController::class, 'ubahSesi'])->name('events.sesi.update');
        Route::post('/events/{event}/ujian/{ujian}/sesi/{sesi}/mulai', [ExamJadwalController::class, 'mulaiSesi'])->name('events.sesi.mulai');
        Route::post('/events/{event}/ujian/{ujian}/sesi/{sesi}/tutup', [ExamJadwalController::class, 'tutupSesi'])->name('events.sesi.tutup');
        Route::delete('/events/{event}/ujian/{ujian}/sesi/{sesi}', [ExamJadwalController::class, 'hapusSesi'])->name('events.sesi.hapus');

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
        |------------------------------------------------------------------
        | 19-20 Presensi & monitoring - Fase 6
        |------------------------------------------------------------------
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

        /*
        |------------------------------------------------------------------
        | 21-22 Publikasi hasil & laporan - Fase 8
        |------------------------------------------------------------------
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
    });

    // Kerangka peran lain - diisi pada fasenya masing-masing.
});

/*
|--------------------------------------------------------------------------
| Konsol siswa - middleware `siswa`, BUKAN `auth` saja
|--------------------------------------------------------------------------
| Alasan yang sama dengan `guru`, dari arah sebaliknya: Pengawas yang
| mengetik /siswa punya akun yang SAH, dan tanpa gerbang ini ia akan
| menerima laman siswa - sementara `KerangkaPonsel` membaca `pengawasRuang`
| dari shared props dan akan menampilkan kode ruangnya di atas jadwal ujian
| seorang anak.
|
| AksesSiswa menolak Admin, Guru, dan Pengawas dengan 403. 403 dan bukan 404:
| keberadaan /siswa bukan rahasia. Yang dijaga di sini adalah PERANNYA;
| kepemilikan baris dijaga terpisah, dan jawabannya 404 - lihat SiswaDasar.
|
| Seluruh alamatnya memuat {peserta}, id `exam_participants`. Itu disengaja:
| guru mengirim tautan gerbang lewat pesan ("buka ini"), dan tautan yang
| tidak menyebut penempatan mana akan membuka penempatan yang salah begitu
| seorang siswa punya lebih dari satu ujian di event yang sama.
|
| Rute `{peserta}` di-bind implisit dan TIDAK dipersempit per-siswa di sini -
| pemeriksaannya ada di SiswaDasar::milikSaya(), di setiap controller, karena
| route model binding tidak punya akses ke `student_id` tanpa menggandakan
| kueri di setiap rute.
*/
Route::middleware(['auth', 'siswa'])->prefix('siswa')->name('siswa.')->group(function () {
    // Plate 19 - jadwal. Beranda konsol siswa.
    Route::get('/', [JadwalSiswaController::class, 'index'])->name('dashboard');
    Route::get('/jadwal', [JadwalSiswaController::class, 'index'])->name('jadwal');

    // Plate 20-21 - gerbang. SATU alamat untuk dua laman: yang membedakannya
    // keadaan presensinya, bukan URL-nya. Lihat GerbangSiswaController.
    Route::get('/ujian/{peserta}/gerbang', [GerbangSiswaController::class, 'tampilkan'])->name('gerbang');

    // `throttle:gerbang-ujian` - PRD §19.2. Yang ditebak di sini adalah TOKEN
    // enam angka, dan token itu ditulis di papan tulis - jadi ia terlihat oleh
    // siapa pun di ruangan itu. Pembatasnya per SISWA (5/menit), bukan per
    // penempatan, supaya anak yang salah baca tidak menghukum kursi sebelahnya
    // yang memakai token yang sama. Lihat App\Support\PembatasLaju.
    Route::post('/ujian/{peserta}/gerbang', [GerbangSiswaController::class, 'mulai'])
        ->middleware('throttle:gerbang-ujian')
        ->name('mulai');

    // Plate 22-23 - pengerjaan. Rute tulisnya menjawab JSON, bukan redirect:
    // autosave berjalan di latar belakang. Lihat KerjakanSiswaController.
    Route::get('/ujian/{peserta}/kerjakan', [KerjakanSiswaController::class, 'tampilkan'])->name('kerjakan');
    Route::post('/ujian/{peserta}/butir/{butir}', [KerjakanSiswaController::class, 'simpan'])->name('butir.simpan');
    Route::post('/ujian/{peserta}/butir/{butir}/tandai', [KerjakanSiswaController::class, 'tandai'])->name('butir.tandai');
    Route::post('/ujian/{peserta}/denyut', [KerjakanSiswaController::class, 'denyut'])->name('denyut');

    // Plate 24 - laporan pelanggaran dari peramban siswa. Jenisnya TIDAK
    // dipercaya: seluruh keputusan dibuat `PencatatPelanggaran`, termasuk
    // bahwa tingkat 0 tidak pernah menjadi baris. Lihat docblock endpoint-nya.
    Route::post('/ujian/{peserta}/lapor', [KerjakanSiswaController::class, 'laporkan'])->name('lapor');

    // Media soal yang dibaca peserta. Rutenya memuat {soal} - id snapshot -
    // dan bukan sekadar {media}, supaya berkas dari soal yang belum diujikan
    // tidak bisa dibaca dengan menebak nomor idnya. Dijaga
    // ExamMediaSiswaController, bukan QuestionPolicy: Siswa tidak dan tidak
    // akan pernah punya TeachingAssignment.
    Route::get('/ujian/{peserta}/berkas/{soal}/{media}', [ExamMediaSiswaController::class, 'tampilkan'])->name('berkas');

    // Plate 25 - terkunci. Laman ini TIDAK membaca ulang pelanggarannya lewat
    // websocket; ia disegarkan sendiri, sama seperti gerbang.
    Route::get('/ujian/{peserta}/terkunci', [TerkunciSiswaController::class, 'tampilkan'])->name('terkunci');

    // Plate 26-27 - konfirmasi dan sesudah dikumpulkan, satu alamat.
    Route::get('/ujian/{peserta}/selesai', [SelesaiSiswaController::class, 'tampilkan'])->name('selesai');
    Route::post('/ujian/{peserta}/selesai', [SelesaiSiswaController::class, 'kumpulkan'])->name('kumpulkan');

    // Plate 28-29 - nilai dan riwayat pelanggaran sendiri.
    Route::get('/nilai', [NilaiSiswaController::class, 'index'])->name('nilai');
    Route::get('/pelanggaran', [PelanggaranSiswaController::class, 'index'])->name('pelanggaran');
});

/*
|--------------------------------------------------------------------------
| Konsol Guru - middleware `guru`, BUKAN `auth` saja
|--------------------------------------------------------------------------
| `auth` saja tidak cukup, dan itu yang paling mudah terlewat: Siswa yang
| mengetik /guru/bank-soal di peramban punya akun yang SAH, dan tanpa gerbang
| ini ia akan menerima daftar soal ujiannya sendiri - lengkap dengan kuncinya
| di laman builder.
|
| AksesGuru meloloskan Admin dan Guru, menolak Pengawas dan Siswa dengan 403.
| 403 dan bukan 404: keberadaan /guru bukan rahasia - setiap Guru tahu
| menunya ada - sehingga yang ditolak adalah AKSESNYA, bukan keberadaannya.
|
| Bank soal berdiri di luar event (wireframe plate 02), sehingga tidak satu
| pun rute di bawah ini memuat id event. Yang membatasi cakupan Guru adalah
| TeachingAssignment di dalam QuestionPolicy, bukan URL.
*/
Route::middleware(['auth', 'guru'])->prefix('guru')->name('guru.')->group(function () {
    // Plate 01
    Route::get('/', DashboardGuruController::class)->name('dashboard');

    // Plate 02 - daftar. POST-nya satu rute dengan GET-nya: yang membedakan
    // keduanya method, dan nama rute terpisah untuk tujuan yang sama berarti
    // dua nama yang harus diingat pemanggilnya.
    Route::get('/bank-soal', [QuestionController::class, 'index'])->name('bank-soal');
    Route::post('/bank-soal', [QuestionController::class, 'store']);

    // Plate 03 - builder. `/baru` didaftarkan SEBELUM `/{soal}/ubah`, dan
    // urutannya menentukan: tanpa itu "baru" dicoba sebagai {soal} lebih dulu.
    Route::get('/bank-soal/baru', [QuestionController::class, 'create'])->name('bank-soal.baru');

    /*
    |----------------------------------------------------------------------
    | Plate 04 - impor bertahap
    |----------------------------------------------------------------------
    | Seluruh blok ini didaftarkan SEBELUM `/bank-soal/{soal}/...`, dengan
    | alasan yang sama seperti `/bank-soal/baru` di atas: rute dinamis
    | mencoba mencocokkan segmen apa pun, dan "impor" adalah segmen yang
    | sah-sah saja sebagai id soal bagi pencocoknya. Yang membuatnya tidak
    | sekadar teori: `/bank-soal/impor/template` akan dicoba sebagai
    | `{soal}/ubah` lebih dulu bila urutannya dibalik - dan Guru yang
    | menekan "Unduh template" menerima 404 yang tidak menjelaskan apa pun.
    |
    | Urutan DI DALAM blok ini juga menentukan: `/impor/template` harus
    | mendahului `/impor/{batch}/laporan`, supaya "template" tidak dicoba
    | sebagai id batch.
    */
    Route::get('/bank-soal/impor', [QuestionImportController::class, 'index'])->name('impor');
    Route::post('/bank-soal/impor/unggah', [QuestionImportController::class, 'unggah'])->name('impor.unggah');
    Route::get('/bank-soal/impor/template', [QuestionImportController::class, 'template'])->name('impor.template');
    Route::post('/bank-soal/impor/{batch}/konfirmasi', [QuestionImportController::class, 'konfirmasi'])->name('impor.konfirmasi');
    Route::get('/bank-soal/impor/{batch}/laporan', [QuestionImportController::class, 'laporan'])->name('impor.laporan');

    Route::get('/bank-soal/{soal}/ubah', [QuestionController::class, 'edit'])->name('bank-soal.ubah');
    Route::put('/bank-soal/{soal}', [QuestionController::class, 'update']);
    Route::post('/bank-soal/{soal}/duplikat', [QuestionController::class, 'duplikat'])->name('bank-soal.duplikat');
    Route::post('/bank-soal/{soal}/arsip', [QuestionController::class, 'arsip'])->name('bank-soal.arsip');

    /*
    |----------------------------------------------------------------------
    | Pratinjau - plate 09, dan satu rute berkas
    |----------------------------------------------------------------------
    | Ketiganya diletakkan SESUDAH seluruh rute statis dan dinamis lain, dan
    | itu bukan kerapian: `{soal}` mencocokkan segmen apa pun, sehingga
    | `/bank-soal/impor/pratinjau` akan dicoba sebagai id soal bila urutannya
    | dibalik - persis masalah yang sudah dijelaskan di blok impor di atas.
    |
    | `/{media}` sengaja diberi awalan `/berkas`, bukan `/media`: satu segmen
    | telanjang di bawah `/guru` akan bertabrakan dengan setiap halaman
    | tingkat atas yang ditambahkan Fase 8 nanti, dan benturan pertama baru
    | ketahuan setelah menu itu terpasang.
    |
    | Berkasnya tetap di disk privat dengan nama acak - rute ini satu-satunya
    | jalan keluarnya, dan ia memanggil Gate seperti rute lain.
    */
    Route::get('/bank-soal/{soal}/pratinjau', [PratinjauController::class, 'soal'])->name('bank-soal.pratinjau');
    Route::get('/berkas/{media}', [MediaSoalController::class, 'tampilkan'])->name('media');

    /*
    |----------------------------------------------------------------------
    | Plate 05 - paket soal
    |----------------------------------------------------------------------
    | `/paket` didaftarkan sebelum `/paket/{paket}`; dengan pola yang sama
    | seperti `/bank-soal/baru` di atas.
    |
    | Rute butir bersarang di bawah paketnya, dan itu bukan kerapian: id butir
    | unik lintas paket, sehingga `PUT /paket/butir/{butir}` akan menyunting
    | butir paket Guru lain lewat URL paket sendiri. Controller memeriksa
    | kepemilikannya lagi - sarang URL saja tidak cukup, karena keduanya
    | sah-sah saja sebagai baris.
    */
    Route::get('/paket', [QuestionPackageController::class, 'index'])->name('paket');
    Route::post('/paket', [QuestionPackageController::class, 'store']);
    Route::get('/paket/{paket}', [QuestionPackageController::class, 'show'])->name('paket.detail');
    Route::put('/paket/{paket}', [QuestionPackageController::class, 'update']);
    Route::post('/paket/{paket}/butir', [QuestionPackageController::class, 'tambahButir'])->name('paket.butir');
    Route::put('/paket/{paket}/urutan', [QuestionPackageController::class, 'susunUlang'])->name('paket.urutan');
    Route::put('/paket/{paket}/butir/{butir}', [QuestionPackageController::class, 'ubahButir'])->name('paket.butir.ubah');
    Route::delete('/paket/{paket}/butir/{butir}', [QuestionPackageController::class, 'buangButir'])->name('paket.butir.buang');
    Route::get('/paket/{paket}/pratinjau', [PratinjauController::class, 'paket'])->name('paket.pratinjau');

    /*
    |----------------------------------------------------------------------
    | Plate 06-08 - penilaian, Fase 8
    |----------------------------------------------------------------------
    | Tiga laman, tiga pertanyaan yang berbeda: plate 06 "uraian mana yang
    | belum dikoreksi", plate 07 "berapa nilai kelas ini", plate 08 "soal
    | mana yang bermasalah". Ketiganya membaca `exam_results` dan
    | `question_analyses` hasil hitungan `PenghitungHasil` /
    | `PenghitungAnalisis`, bukan menghitung ulang di setiap pembacaan -
    | alasan yang sama seperti plate 21 dan 28.
    |
    | `/koreksi/jawaban/...` didaftarkan SEBELUM `/koreksi/{ujian}`, dan
    | urutannya menentukan: tanpa itu "jawaban" dicoba sebagai id ujian lebih
    | dulu, dan tombol "Koreksi" di daftar antrean membuka 404.
    */
    Route::get('/koreksi', [KoreksiController::class, 'index'])->name('koreksi');
    Route::get('/koreksi/jawaban/{jawaban}', [KoreksiController::class, 'jawaban'])->name('koreksi.jawaban');
    Route::post('/koreksi/jawaban/{jawaban}', [KoreksiController::class, 'simpan'])->name('koreksi.simpan');
    Route::delete('/koreksi/jawaban/{jawaban}', [KoreksiController::class, 'batalkan'])->name('koreksi.batal');
    Route::get('/koreksi/{ujian}', [KoreksiController::class, 'ujian'])->name('koreksi.ujian');

    Route::get('/nilai', [NilaiController::class, 'index'])->name('nilai');
    Route::get('/nilai/{ujian}', [NilaiController::class, 'ujian'])->name('nilai.ujian');

    Route::get('/analisis', [AnalisisController::class, 'index'])->name('analisis');
    Route::post('/analisis/{ujian}/hitung-ulang', [AnalisisController::class, 'hitungUlang'])
        ->name('analisis.hitung-ulang');
    Route::get('/analisis/{ujian}', [AnalisisController::class, 'ujian'])->name('analisis.ujian');
});

/*
|--------------------------------------------------------------------------
| Portal pengawas - middleware `pengawas`, BUKAN `auth`
|--------------------------------------------------------------------------
| Grup `auth` tidak bisa dipakai: pemegang tautan ruang tidak punya akun.
| AksesPengawas meloloskan permintaan bila SALAH SATU benar - ada kunci
| sesi `pengawas_ruang` yang masih sah dan mencakup {ruang} di rute, atau
| ada User berperan Pengawas dengan penugasan pada {ruang} itu, atau Admin.
|
| Rutenya memakai `/pengawas/...`, bukan `/supervisor/...` seperti PRD §20.1:
| seluruh laman aplikasi ini berbahasa Indonesia, dan
| `Role::Supervisor->dashboardPath()` sudah menunjuk /pengawas sejak Fase 1.
| Perbedaannya dicatat di sini supaya tidak menjadi pertanyaan kelak.
|
| Rute ruangnya selalu memuat {ruang}. Itu bukan kerapian: pengawas memegang
| lebih dari satu ruang, dan URL yang tidak menyebut ruang mana akan membuat
| tautan yang dikirim lewat pesan membuka ruang yang salah.
*/
Route::middleware('pengawas')->prefix('pengawas')->name('pengawas.')->group(function () {
    Route::get('/', [RuangTugasController::class, 'index'])->name('dashboard');

    // Plate 12-13, 17
    Route::get('/ruang/{ruang}/presensi', [PresensiController::class, 'index'])->name('presensi');
    Route::get('/ruang/{ruang}/presensi/{peserta}', [PresensiController::class, 'satu'])->name('presensi.satu');
    Route::post('/ruang/{ruang}/presensi/verifikasi', [PresensiController::class, 'verifikasi'])->name('presensi.verifikasi');
    Route::post('/ruang/{ruang}/presensi/massal', [PresensiController::class, 'massal'])->name('presensi.massal');
    Route::get('/ruang/{ruang}/koreksi', [PresensiController::class, 'koreksi'])->name('koreksi');
    Route::post('/ruang/{ruang}/koreksi/{peserta}', [PresensiController::class, 'simpanKoreksi'])->name('koreksi.simpan');
    Route::post('/ruang/{ruang}/kembalikan/{peserta}', [PresensiController::class, 'kembalikan'])->name('kembalikan');

    // Plate 14
    Route::get('/ruang/{ruang}/token', [TokenRuangController::class, 'index'])->name('token');
    Route::post('/ruang/{ruang}/token/baca', [TokenRuangController::class, 'baca'])->name('token.baca');

    // Plate 15-16
    Route::get('/ruang/{ruang}/monitoring', [MonitoringController::class, 'index'])->name('monitoring');
    Route::post('/ruang/{ruang}/insiden', [MonitoringController::class, 'catatInsiden'])->name('insiden');
    Route::get('/ruang/{ruang}/siswa/{peserta}', [PelanggaranController::class, 'index'])->name('siswa');
    Route::post('/ruang/{ruang}/siswa/{peserta}/buka', [PelanggaranController::class, 'buka'])->name('siswa.buka');

    // Plate 18
    Route::get('/ruang/{ruang}/berita-acara', [BeritaAcaraController::class, 'index'])->name('berita-acara');
    Route::post('/ruang/{ruang}/berita-acara/foto', [BeritaAcaraController::class, 'unggahFoto'])->name('berita-acara.foto');
    Route::post('/ruang/{ruang}/berita-acara/ttd', [BeritaAcaraController::class, 'tandaTangan'])->name('berita-acara.ttd');
    Route::post('/ruang/{ruang}/berita-acara/catatan', [BeritaAcaraController::class, 'simpanCatatan'])->name('berita-acara.catatan');
    Route::post('/ruang/{ruang}/berita-acara/kirim', [BeritaAcaraController::class, 'kirim'])->name('berita-acara.kirim');
    Route::post('/ruang/{ruang}/berita-acara/buka', [BeritaAcaraController::class, 'bukaKembali'])->name('berita-acara.buka');
});

/*
|--------------------------------------------------------------------------
| Berkas berita acara - disk privat, dibaca lewat rute berwenang
|--------------------------------------------------------------------------
| TIDAK ada symlink `public/storage`, dan itu keputusan yang dikunci: foto
| ruang ujian adalah bukti, bukan aset publik. Satu-satunya jalan keluarnya
| adalah dua rute ini, dan keduanya memanggil KonteksRuang lagi - sehingga
| mencabut tautan ruang mencabut fotonya juga, tanpa kode tambahan.
|
| DUA rute, bukan satu: `session_report_files.id` dan
| `session_report_signatures.id` adalah dua ruang id yang berbeda, dan satu
| rute berarti id 7 harus ditebak milik tabel mana. Yang membedakan keduanya
| di sini adalah MODEL yang di-bind, bukan angka di dalam string.
*/
Route::middleware('pengawas')->group(function () {
    Route::get('/laporan/foto/{berkas}', [BerkasLaporanController::class, 'foto'])->name('laporan.foto');
    Route::get('/laporan/ttd/{ttd}', [BerkasLaporanController::class, 'ttd'])->name('laporan.ttd');
});
