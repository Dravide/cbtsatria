<?php

use App\Domain\Admin\Http\Controllers\AcademicYearController;
use App\Domain\Admin\Http\Controllers\ActivityLogController;
use App\Domain\Admin\Http\Controllers\DashboardController;
use App\Domain\Admin\Http\Controllers\IdentitasAplikasiController;
use App\Domain\Admin\Http\Controllers\KartuAkunController;
use App\Domain\Admin\Http\Controllers\KartuKelasController;
use App\Domain\Admin\Http\Controllers\MasterImportController;
use App\Domain\Admin\Http\Controllers\PengaturanAiController;
use App\Domain\Admin\Http\Controllers\PengaturanPapanController;
use App\Domain\Admin\Http\Controllers\RoomController;
use App\Domain\Admin\Http\Controllers\SchoolClassController;
use App\Domain\Admin\Http\Controllers\SchoolProfileController;
use App\Domain\Admin\Http\Controllers\SchoolSettingController;
use App\Domain\Admin\Http\Controllers\SubjectController;
use App\Domain\Admin\Http\Controllers\UserController;
use App\Domain\Admin\Http\Controllers\UserExportController;
use App\Domain\Admin\Http\Controllers\UserImportController;
use App\Domain\Attendance\Http\Controllers\PantauPublikController;
use App\Domain\Attendance\Http\Controllers\PemantauanEventController;
use App\Domain\Attendance\Http\Controllers\SinyalRuangAdminController;
use App\Domain\Event\Http\Controllers\EventAnnouncementController;
use App\Domain\Event\Http\Controllers\EventCommitteeController;
use App\Domain\Event\Http\Controllers\EventController;
use App\Domain\Event\Http\Controllers\EventNotifikasiController;
use App\Domain\Event\Http\Controllers\EventParticipantController;
use App\Domain\Event\Http\Controllers\EventSoalController;
use App\Domain\Event\Http\Controllers\EventSubjectController;
use App\Domain\Event\Http\Controllers\PemasanganPaketController;
use App\Domain\Exam\Http\Controllers\EventPeluncuranController;
use App\Domain\Exam\Http\Controllers\ExamJadwalController;
use App\Domain\Exam\Http\Controllers\ExamPenempatanController;
use App\Domain\Exam\Http\Controllers\ExamPengawasController;
use App\Domain\Exam\Http\Controllers\ExamTokenController;
use App\Domain\Grading\Http\Controllers\Admin\HasilController;
use App\Domain\Grading\Http\Controllers\Admin\LaporanController;
use App\Domain\Question\Http\Controllers\BankSoalAdminController;
use Illuminate\Support\Facades\Route;

// Semua rute di bawah ini memakai binding implisit pada model ter-scope tenant (id sekolah lain -> 404).
Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::post('/events/{event}/gladi-bersih', [EventController::class, 'gladiBersih'])->name('events.gladi-bersih');
    Route::post('/sinyal/{sinyal}/selesai', [SinyalRuangAdminController::class, 'selesaikan'])->name('sinyal.selesai');

    Route::get('/sekolah', [SchoolProfileController::class, 'edit'])->name('sekolah');
    Route::put('/sekolah', [SchoolProfileController::class, 'update']);

    // Logo dipisah dari form profil agar form teks tetap JSON (tidak dipaksa multipart).
    Route::post('/sekolah/logo', [SchoolProfileController::class, 'unggahLogo'])
        ->name('sekolah.logo');
    Route::delete('/sekolah/logo', [SchoolProfileController::class, 'hapusLogo'])
        ->name('sekolah.logo.hapus');

    Route::get('/pengaturan', [SchoolSettingController::class, 'edit'])->name('pengaturan');
    Route::put('/pengaturan', [SchoolSettingController::class, 'update']);

    // Dipisah dari /pengaturan karena form pengaturan menulis ulang kolom settings secara utuh.
    Route::put('/pengaturan/ai', [PengaturanAiController::class, 'update'])->name('pengaturan.ai');
    Route::post('/pengaturan/ai/uji', [PengaturanAiController::class, 'uji'])->name('pengaturan.ai.uji');

    // Log aktivitas bersifat baca-saja tanpa rute hapus.
    Route::get('/log', [ActivityLogController::class, 'index'])->name('log');

    Route::patch('/papan', [PengaturanPapanController::class, 'update'])->name('papan');
    Route::get('/papan/poster', [PengaturanPapanController::class, 'poster'])->name('papan.poster');

    Route::get('/sekolah/identitas', [IdentitasAplikasiController::class, 'edit'])->name('sekolah.identitas');
    Route::put('/sekolah/identitas', [IdentitasAplikasiController::class, 'update']);
    Route::post('/sekolah/identitas/ikon/{jenis}', [IdentitasAplikasiController::class, 'unggahIkon'])
        ->whereIn('jenis', ['favicon', 'ikon', 'apple', 'logo'])
        ->name('sekolah.identitas.ikon');
    Route::delete('/sekolah/identitas/ikon/{jenis}', [IdentitasAplikasiController::class, 'hapusIkon'])
        ->whereIn('jenis', ['favicon', 'ikon', 'apple', 'logo'])
        ->name('sekolah.identitas.ikon.hapus');

    Route::get('/pengguna', [UserController::class, 'index'])->name('pengguna');
    Route::post('/pengguna', [UserController::class, 'store']);
    Route::put('/pengguna/{pengguna}', [UserController::class, 'update']);
    Route::put('/pengguna/{pengguna}/username', [UserController::class, 'gantiUsername'])
        ->name('pengguna.username');
    Route::put('/pengguna/{pengguna}/kuota-ai', [UserController::class, 'ubahKuotaAi'])
        ->name('pengguna.kuota-ai');
    Route::get('/pengguna/kartu', [KartuAkunController::class, 'unduh'])->name('pengguna.kartu');
    Route::post('/pengguna/massal', [UserController::class, 'massal'])->name('pengguna.massal');
    Route::post('/pengguna/reset-password', [UserController::class, 'resetPassword'])
        ->name('pengguna.reset');
    Route::post('/pengguna/reset-kuota-ai', [UserController::class, 'resetKuotaAi'])
        ->name('pengguna.reset-kuota-ai');
    Route::put('/pengguna/pengawas/massal', [UserController::class, 'editMassalPengawas'])
        ->name('pengguna.pengawas.massal');
    Route::delete('/pengguna/pengawas', [UserController::class, 'hapusSemuaPengawas'])
        ->name('pengguna.hapus-pengawas');

    // Segmen statis (/impor/template) harus sebelum /impor/{batch}/laporan agar tidak di-bind sebagai {batch}.
    Route::get('/pengguna/impor', [UserImportController::class, 'index'])->name('pengguna.impor');
    Route::post('/pengguna/impor/unggah', [UserImportController::class, 'unggah'])->name('pengguna.impor.unggah');
    Route::get('/pengguna/impor/template', [UserImportController::class, 'template'])->name('pengguna.impor.template');
    Route::post('/pengguna/impor/{batch}/konfirmasi', [UserImportController::class, 'konfirmasi'])->name('pengguna.impor.konfirmasi');
    Route::delete('/pengguna/impor/{batch}', [UserImportController::class, 'batalkan'])->name('pengguna.impor.batal');
    Route::get('/pengguna/impor/{batch}/laporan', [UserImportController::class, 'laporan'])->name('pengguna.impor.laporan');

    Route::post('/pengguna/ekspor', [UserExportController::class, 'minta'])->name('pengguna.ekspor');
    Route::get('/pengguna/ekspor/{ekspor}/unduh', [UserExportController::class, 'unduh'])->name('pengguna.ekspor.unduh');

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

    Route::get('/kelas', [SchoolClassController::class, 'index'])->name('kelas');
    Route::get('/kelas/kartu', [KartuKelasController::class, 'unduh'])->name('kelas.kartu');
    Route::post('/kelas', [SchoolClassController::class, 'store']);
    Route::put('/kelas/{kelas}', [SchoolClassController::class, 'update']);
    Route::get('/kelas/{kelas}/anggota', [SchoolClassController::class, 'anggota'])->name('kelas.anggota');
    Route::put('/kelas/{kelas}/anggota', [SchoolClassController::class, 'simpanAnggota']);

    Route::patch('/kelas/{kelas}/pantau', [SchoolClassController::class, 'pantau'])
        ->name('kelas.pantau');
    Route::post('/kelas/{kelas}/kode-wali', [SchoolClassController::class, 'kodeWali'])
        ->name('kelas.kode-wali');

    // Berlaku hanya untuk satu tahun ajaran yang sedang dipilih, bukan seluruh sekolah.
    Route::post('/kelas/pantau-massal', [SchoolClassController::class, 'pantauMassal'])
        ->name('kelas.pantau-massal');

    // Bank soal baca-saja untuk Admin; controller tetap di modul Question agar batas domain terjaga.
    Route::get('/bank-soal', [BankSoalAdminController::class, 'index'])->name('bank-soal');

    Route::get('/mapel', [SubjectController::class, 'index'])->name('mapel');
    Route::post('/mapel', [SubjectController::class, 'store']);
    Route::put('/mapel/{mapel}', [SubjectController::class, 'update']);
    Route::post('/mapel/{mapel}/tugaskan', [SubjectController::class, 'tugaskan'])->name('mapel.tugaskan');
    Route::delete('/penugasan/{penugasan}', [SubjectController::class, 'batalkan'])->name('penugasan.batal');

    Route::get('/ruang', [RoomController::class, 'index'])->name('ruang');
    Route::post('/ruang', [RoomController::class, 'store']);
    // Segmen statis harus sebelum /ruang/{ruang} agar tidak di-bind sebagai id ruang.
    Route::get('/ruang/kartu', [RoomController::class, 'kartu'])->name('ruang.kartu');
    Route::post('/ruang/terbitkan-massal', [RoomController::class, 'terbitkanMassal'])->name('ruang.terbitkan-massal');
    Route::put('/ruang/kapasitas-massal', [RoomController::class, 'kapasitasMassal'])->name('ruang.kapasitas-massal');
    Route::put('/ruang/{ruang}', [RoomController::class, 'update']);
    Route::get('/ruang/{ruang}', [RoomController::class, 'show'])->name('ruang.detail');
    Route::post('/ruang/{ruang}/terbitkan', [RoomController::class, 'terbitkan'])->name('ruang.terbitkan');
    Route::post('/ruang/{ruang}/cabut', [RoomController::class, 'cabut'])->name('ruang.cabut');

    // Dipisah ke /impor/{jenis} agar tidak bertabrakan dengan /ruang/{ruang}; segmen statis tetap sebelum {batch}.
    Route::get('/impor/{jenis}', [MasterImportController::class, 'index'])->name('master.impor');
    Route::post('/impor/{jenis}/unggah', [MasterImportController::class, 'unggah'])->name('master.impor.unggah');
    Route::get('/impor/{jenis}/template', [MasterImportController::class, 'template'])->name('master.impor.template');
    Route::post('/impor/{jenis}/{batch}/konfirmasi', [MasterImportController::class, 'konfirmasi'])
        ->name('master.impor.konfirmasi');
    Route::get('/impor/{jenis}/{batch}/laporan', [MasterImportController::class, 'laporan'])
        ->name('master.impor.laporan');

    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::get('/events/create', [EventController::class, 'create'])->name('events.create');
    Route::post('/events', [EventController::class, 'store']);
    Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
    Route::get('/events/{event}/ubah', [EventController::class, 'edit'])->name('events.edit');
    Route::put('/events/{event}', [EventController::class, 'update']);
    Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.hapus');
    Route::post('/events/{event}/status', [EventController::class, 'ubahStatus'])->name('events.status');

    // Aksi massal peluncuran tidak menerima daftar id sesi dari client; penyaringan dilakukan di SesiService.
    Route::get('/events/{event}/peluncuran', [EventPeluncuranController::class, 'show'])
        ->name('events.peluncuran');
    Route::post('/events/{event}/peluncuran/siapkan', [EventPeluncuranController::class, 'siapkan'])
        ->name('events.peluncuran.siapkan');
    Route::post('/events/{event}/peluncuran/buka', [EventPeluncuranController::class, 'buka'])
        ->name('events.peluncuran.buka');

    Route::get('/events/{event}/pengumuman', [EventAnnouncementController::class, 'index'])
        ->name('events.pengumuman');
    Route::post('/events/{event}/pengumuman', [EventAnnouncementController::class, 'store'])
        ->name('events.pengumuman.store');
    Route::put('/events/{event}/pengumuman/{pengumuman}', [EventAnnouncementController::class, 'update'])
        ->name('events.pengumuman.update');
    Route::delete('/events/{event}/pengumuman/{pengumuman}', [EventAnnouncementController::class, 'destroy'])
        ->name('events.pengumuman.hapus');

    Route::get('/events/{event}/panitia', [EventCommitteeController::class, 'index'])->name('events.panitia');
    Route::post('/events/{event}/panitia', [EventCommitteeController::class, 'store']);
    Route::post('/events/{event}/panitia/massal', [EventCommitteeController::class, 'massal'])->name('events.panitia.massal');
    Route::post('/events/{event}/panitia/{panitia}/kode', [EventCommitteeController::class, 'terbitkanKode'])->name('events.panitia.kode');
    Route::put('/events/{event}/panitia/{panitia}', [EventCommitteeController::class, 'update']);
    Route::delete('/events/{event}/panitia/{panitia}', [EventCommitteeController::class, 'destroy']);

    Route::get('/events/{event}/panitia/ekspor', [EventCommitteeController::class, 'ekspor'])->name('events.panitia.ekspor');
    Route::get('/events/{event}/panitia/kartu', [EventCommitteeController::class, 'kartu'])->name('events.panitia.kartu');
    Route::post('/events/{event}/panitia/kode-massal', [EventCommitteeController::class, 'terbitkanKodeMassal'])->name('events.panitia.kode-massal');

    Route::get('/events/{event}/peserta', [EventParticipantController::class, 'index'])->name('events.peserta');
    Route::put('/events/{event}/peserta/kelas', [EventParticipantController::class, 'simpanKelas'])->name('events.peserta.kelas');
    Route::post('/events/{event}/peserta/tarik', [EventParticipantController::class, 'tarik'])->name('events.peserta.tarik');
    Route::post('/events/{event}/peserta/tambah', [EventParticipantController::class, 'tambah'])->name('events.peserta.tambah');
    Route::post('/events/{event}/peserta/massal', [EventParticipantController::class, 'massal'])->name('events.peserta.massal');
    Route::post('/events/{event}/peserta/perangkat', [EventParticipantController::class, 'perangkat'])
        ->name('events.peserta.perangkat');
    Route::put('/events/{event}/peserta/ruang', [EventParticipantController::class, 'simpanRuang'])->name('events.peserta.ruang');

    Route::get('/events/{event}/mapel', [EventSubjectController::class, 'index'])->name('events.mapel');
    Route::put('/events/{event}/mapel', [EventSubjectController::class, 'store']);

    // Harus sebelum /mapel/{mapel}/paket agar "soal" tidak di-bind sebagai {mapel}.
    Route::get('/events/{event}/mapel/soal', [EventSoalController::class, 'index'])
        ->name('events.soal');

    Route::put('/events/{event}/mapel/paket', [PemasanganPaketController::class, 'simpan'])
        ->name('events.mapel.paket');
    Route::get('/events/{event}/mapel/{mapel}/paket', [PemasanganPaketController::class, 'riwayat'])
        ->name('events.mapel.paket.riwayat');

    Route::get('/events/{event}/jadwal', [ExamJadwalController::class, 'index'])->name('events.jadwal');
    Route::get('/events/{event}/jadwal/pdf', [ExamJadwalController::class, 'pdf'])->name('events.jadwal.pdf');
    Route::get('/events/{event}/jadwal/{ujian}/sesi', [ExamJadwalController::class, 'sesi'])->name('events.jadwal.sesi');
    Route::post('/events/{event}/jadwal/deteksi', [ExamJadwalController::class, 'deteksi'])->name('events.jadwal.deteksi');

    Route::post('/events/{event}/ujian', [ExamJadwalController::class, 'simpanUjian'])->name('events.ujian.store');
    Route::put('/events/{event}/ujian/{ujian}', [ExamJadwalController::class, 'ubahUjian'])->name('events.ujian.update');
    Route::delete('/events/{event}/ujian/{ujian}', [ExamJadwalController::class, 'hapusUjian'])->name('events.ujian.hapus');

    Route::post('/events/{event}/ujian/{ujian}/sesi', [ExamJadwalController::class, 'simpanSesi'])->name('events.sesi.store');
    // Harus sebelum {sesi} agar segmen statis "susulan" tidak di-bind sebagai id sesi.
    Route::post('/events/{event}/ujian/{ujian}/sesi/susulan', [ExamJadwalController::class, 'simpanSesiSusulan'])->name('events.sesi.susulan');
    Route::put('/events/{event}/ujian/{ujian}/sesi/{sesi}', [ExamJadwalController::class, 'ubahSesi'])->name('events.sesi.update');
    Route::post('/events/{event}/ujian/{ujian}/sesi/{sesi}/siapkan', [ExamJadwalController::class, 'siapkanSesi'])->name('events.sesi.siapkan');
    Route::post('/events/{event}/ujian/{ujian}/sesi/{sesi}/mulai', [ExamJadwalController::class, 'mulaiSesi'])->name('events.sesi.mulai');
    Route::post('/events/{event}/ujian/{ujian}/sesi/{sesi}/tutup', [ExamJadwalController::class, 'tutupSesi'])->name('events.sesi.tutup');
    Route::post('/events/{event}/ujian/{ujian}/sesi/{sesi}/batal', [ExamJadwalController::class, 'batalSesi'])->name('events.sesi.batal');
    Route::post('/events/{event}/ujian/{ujian}/sesi/{sesi}/pindah-susulan', [ExamJadwalController::class, 'pindahSusulan'])->name('events.sesi.pindah-susulan');
    Route::delete('/events/{event}/ujian/{ujian}/sesi/{sesi}', [ExamJadwalController::class, 'hapusSesi'])->name('events.sesi.hapus');

    // Peta ruang berlaku untuk satu event; rute /sesi/{sesi} menerapkan peta event ke satu sesi sasaran.
    Route::get('/events/{event}/penempatan', [ExamPenempatanController::class, 'index'])->name('events.penempatan');
    Route::post('/events/{event}/penempatan', [ExamPenempatanController::class, 'sebar'])->name('events.penempatan.sebar');
    Route::post('/events/{event}/penempatan/kocok-ulang', [ExamPenempatanController::class, 'kocokUlang'])->name('events.penempatan.kocok-ulang');
    Route::post('/events/{event}/penempatan/kunci', [ExamPenempatanController::class, 'kunci'])->name('events.penempatan.kunci');
    Route::post('/events/{event}/penempatan/buka-kunci', [ExamPenempatanController::class, 'bukaKunci'])->name('events.penempatan.buka-kunci');
    Route::post('/events/{event}/penempatan/sesi/{sesi}', [ExamPenempatanController::class, 'pakaiSesi'])->name('events.penempatan.sesi');

    Route::get('/events/{event}/penempatan/denah', [ExamPenempatanController::class, 'denah'])->name('events.penempatan.denah');
    Route::get('/events/{event}/penempatan/denah/pdf', [ExamPenempatanController::class, 'denahPdf'])->name('events.penempatan.denah-pdf');
    Route::get('/events/{event}/penempatan/tablo', [ExamPenempatanController::class, 'tablo'])->name('events.penempatan.tablo');
    Route::get('/events/{event}/penempatan/daftar-peserta', [ExamPenempatanController::class, 'daftarPeserta'])->name('events.penempatan.daftar-peserta');

    Route::get('/events/{event}/pengawas', [ExamPengawasController::class, 'index'])->name('events.pengawas');
    Route::get('/events/{event}/pengawas/pdf', [ExamPengawasController::class, 'pdf'])->name('events.pengawas.pdf');
    Route::get('/events/{event}/pengawas/sesi/{sesi}', [ExamPengawasController::class, 'sesi'])->name('events.pengawas.sesi');
    Route::post('/events/{event}/pengawas/otomatis', [ExamPengawasController::class, 'otomatis'])->name('events.pengawas.otomatis');
    Route::post('/events/{event}/pengawas/reset', [ExamPengawasController::class, 'resetPengawasan'])->name('events.pengawas.reset');
    Route::post('/events/{event}/pengawas/ruang/{ruang}', [ExamPengawasController::class, 'tugaskan'])->name('events.pengawas.tugaskan');
    Route::delete('/events/{event}/pengawas/{tugas}', [ExamPengawasController::class, 'batalkan'])->name('events.pengawas.batal');
    Route::post('/events/{event}/pengawas/tukar', [ExamPengawasController::class, 'tukar'])
        ->name('events.pengawas.tukar');
    Route::post('/events/{event}/pengawas/aturan', [ExamPengawasController::class, 'simpanAturan'])
        ->name('events.pengawas.aturan');
    Route::post('/events/{event}/pengawas/pengecualian', [ExamPengawasController::class, 'togglePengecualian'])
        ->name('events.pengawas.pengecualian');
    Route::put('/events/{event}/pengawas/pengecualian/{pengecualian}', [ExamPengawasController::class, 'ubahPengecualian'])
        ->name('events.pengawas.pengecualian.update');
    Route::delete('/events/{event}/pengawas/pengecualian/{pengecualian}', [ExamPengawasController::class, 'hapusPengecualian'])
        ->name('events.pengawas.pengecualian.hapus');
    Route::post('/events/{event}/pengawas/kosongkan-hari', [ExamPengawasController::class, 'kosongkanHari'])
        ->name('events.pengawas.kosongkan_hari');
    Route::post('/events/{event}/pengawas/kuota', [ExamPengawasController::class, 'simpanKuota'])
        ->name('events.pengawas.kuota');
    Route::post('/events/{event}/pengawas/kuota-massal', [ExamPengawasController::class, 'simpanKuotaMassal'])
        ->name('events.pengawas.kuota_massal');
    Route::post('/events/{event}/pengawas/sesi/{sesi}/otomatis', [ExamPengawasController::class, 'otomatisSesi'])
        ->name('events.pengawas.sesi.otomatis');
    Route::post('/events/{event}/pengawas/sesi/{sesi}/kosongkan', [ExamPengawasController::class, 'kosongkanSesi'])
        ->name('events.pengawas.sesi.kosongkan');

    Route::get('/events/{event}/token', [ExamTokenController::class, 'index'])->name('events.token');
    Route::post('/events/{event}/token/sesi/{sesi}', [ExamTokenController::class, 'terbitkan'])->name('events.token.terbitkan');
    Route::post('/events/{event}/token/{token}/baca', [ExamTokenController::class, 'baca'])->name('events.token.baca');
    Route::post('/events/{event}/token/{token}/cabut', [ExamTokenController::class, 'cabut'])->name('events.token.cabut');

    Route::get('/events/{event}/presensi', [PemantauanEventController::class, 'presensi'])
        ->name('events.presensi');
    Route::get('/events/{event}/presensi/sesi/{sesi}', [PemantauanEventController::class, 'presensiSesi'])
        ->name('events.presensi.sesi');
    Route::get('/events/{event}/presensi/ruang', [PemantauanEventController::class, 'presensiRuang'])
        ->name('events.presensi.ruang');
    Route::post('/events/{event}/presensi/koreksi', [PemantauanEventController::class, 'koreksi'])
        ->name('events.presensi.koreksi');

    Route::get('/events/{event}/monitoring', [PemantauanEventController::class, 'monitoring'])
        ->name('events.monitoring');
    Route::get('/events/{event}/notifikasi', [EventNotifikasiController::class, 'index'])
        ->name('events.notifikasi');
    Route::get('/events/{event}/notifikasi/ringkas', [EventNotifikasiController::class, 'ringkas'])
        ->name('events.notifikasi.ringkas');
    Route::post('/events/{event}/notifikasi/baca-semua', [EventNotifikasiController::class, 'bacaSemua'])
        ->name('events.notifikasi.baca_semua');
    Route::post('/events/{event}/notifikasi/{notifikasi}/baca', [EventNotifikasiController::class, 'baca'])
        ->name('events.notifikasi.baca');

    Route::get('/events/{event}/pantau', [PantauPublikController::class, 'tampilkanEventAdmin'])
        ->name('events.pantau');
    Route::get('/events/{event}/pantau/denyut', [PantauPublikController::class, 'denyutEventAdmin'])
        ->name('events.pantau.denyut');
    Route::get('/events/{event}/pantau/ruang/{ruang}', [PantauPublikController::class, 'ruangEventAdmin'])
        ->whereNumber('ruang')
        ->name('events.pantau.ruang');

    Route::get('/events/{event}/monitoring/sesi/{sesi}', [PemantauanEventController::class, 'monitoringSesi'])
        ->name('events.monitoring.sesi');
    Route::get('/events/{event}/monitoring/ruang', [PemantauanEventController::class, 'monitoringRuangEvent'])
        ->name('events.monitoring.ruang-semua');
    Route::get('/events/{event}/monitoring/terkunci', [PemantauanEventController::class, 'terkunci'])
        ->name('events.monitoring.terkunci');

    // Harus sebelum /monitoring/ruang/{ruang} agar "tidak-hadir" tidak di-bind sebagai {ruang}.
    Route::get('/events/{event}/monitoring/tidak-hadir', [PemantauanEventController::class, 'rekapTidakHadir'])
        ->name('events.monitoring.tidak-hadir');

    Route::get('/events/{event}/monitoring/ruang/{ruang}', [PemantauanEventController::class, 'monitoringRuang'])
        ->name('events.monitoring.ruang');

    // {peserta} adalah id exam_participants, bukan id siswa.
    Route::post('/events/{event}/monitoring/peserta/{peserta}/buka', [PemantauanEventController::class, 'bukaKunci'])
        ->name('events.monitoring.buka');
    Route::post('/events/{event}/monitoring/peserta/{peserta}/reset', [PemantauanEventController::class, 'resetUjian'])
        ->name('events.monitoring.reset');
    Route::post('/events/{event}/monitoring/peserta/{peserta}/hentikan', [PemantauanEventController::class, 'blokirPeserta'])
        ->name('events.monitoring.hentikan');
    Route::post('/events/{event}/monitoring/peserta/{peserta}/pindah-ruang', [PemantauanEventController::class, 'pindahRuang'])
        ->name('events.monitoring.pindah-ruang');

    // Memakai segmen ruang/{ruang} agar tidak tertutup binding {peserta}.
    Route::post('/events/{event}/monitoring/ruang/{ruang}/reset-massal', [PemantauanEventController::class, 'resetMassal'])
        ->name('events.monitoring.reset-massal');

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
