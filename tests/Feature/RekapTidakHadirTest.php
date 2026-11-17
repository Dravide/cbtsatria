<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Rekap "tidak hadir" - nama, ruang, dan kelas.
 *
 * =====================================================================
 * Yang dijaga, dan mengapa bukan sekadar "lamannya terbuka"
 * =====================================================================
 * Rekap ini dipakai wali kelas, guru BK, dan Admin yang menyiapkan ujian
 * susulan. Dua kesalahan yang paling mahal di sini:
 *
 *   1. Memasukkan siswa `pending` sebagai "tidak hadir" - menuduh anak yang
 *      mungkin sedang duduk di kursinya, hanya karena pengawasnya belum
 *      sampai ke namanya.
 *   2. Menggabungkan `izin` ke dalam satu kolom "tidak hadir" - membuat wali
 *      kelas menelepon orang tua yang anaknya sudah berpamitan.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.tidak.hadir',
    ]);
    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();
    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);

    $this->d = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['R-01'],
        4,
    );
});

/** Tandai presensi satu peserta pada status tertentu. */
function tandai(ExamParticipant $peserta, StatusPresensi $status): void
{
    AttendanceRecord::query()
        ->where('exam_participant_id', $peserta->id)
        ->update([
            'attendance_status' => $status->value,
            'verified_at' => now(),
            'pelaku_label' => 'R-01 (uji)',
        ]);
}

it('mendaftar siswa yang tidak hadir beserta ruang dan kelasnya', function () {
    $peserta = $this->d['ruang'][0]->participants()->with('pesertaEvent.kelas')->firstOrFail();

    tandai($peserta, StatusPresensi::TidakHadir);

    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$this->d['event']->id}/monitoring/tidak-hadir")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Event/MonitoringTidakHadir')
            ->has('baris', 1)
            ->where('baris.0.peserta_id', $peserta->id)
            ->where('baris.0.status', 'tidak_hadir')
            ->where('baris.0.status_label', 'Tidak hadir')
            // RUANG dan KELAS adalah inti permintaan rekap ini. Kode ruangnya
            // berakhiran id event - itulah yang membedakan ruang event ini dari
            // ruang event lain yang kebetulan juga bernomor R-01.
            ->where('baris.0.ruang', 'R-01-'.$this->d['event']->id)
            ->where('baris.0.kelas', fn ($k) => is_string($k) && $k !== '')
            ->where('angka.tidak_hadir', 1)
            ->where('angka.izin', 0)
        );
});

it('TIDAK memasukkan siswa yang belum dipresensi sebagai tidak hadir', function () {
    // Inti rekap ini. Siswa `pending` masih mungkin duduk di kursinya -
    // memasukkannya berarti menuduh anak yang pengawasnya belum sampai ke
    // namanya. Rekap terbentuk dari presensi yang SUDAH DIVERIFIKASI.
    $peserta = $this->d['ruang'][0]->participants()->firstOrFail();

    tandai($peserta, StatusPresensi::TidakHadir);

    // Tiga peserta lain dibiarkan `pending`.
    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$this->d['event']->id}/monitoring/tidak-hadir")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Event/MonitoringTidakHadir')
            // Hanya SATU - bukan empat.
            ->has('baris', 1)
            ->where('angka.tidak_hadir', 1)
        );
});

it('memisahkan izin dari tidak hadir, karena keduanya menuntut tindakan berbeda', function () {
    $semua = $this->d['ruang'][0]->participants()->get();

    tandai($semua[0], StatusPresensi::TidakHadir);
    tandai($semua[1], StatusPresensi::Izin);

    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$this->d['event']->id}/monitoring/tidak-hadir")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Event/MonitoringTidakHadir')
            ->has('baris', 2)
            ->where('angka.tidak_hadir', 1)
            ->where('angka.izin', 1)
        );
});

it('mengirim ringkasan per kelas untuk wali kelas', function () {
    $semua = $this->d['ruang'][0]->participants()->get();

    tandai($semua[0], StatusPresensi::TidakHadir);
    tandai($semua[1], StatusPresensi::TidakHadir);

    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$this->d['event']->id}/monitoring/tidak-hadir")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Event/MonitoringTidakHadir')
            ->has('per_kelas', 1)
            ->where('per_kelas.0.tidak_hadir', 2)
            ->where('per_kelas.0.izin', 0)
            ->where('per_kelas.0.total', 2)
        );
});

it('menampilkan laman rekap yang kosong tanpa galat', function () {
    // Event yang belum punya satu pun presensi terverifikasi. Laman harus
    // terbuka dan mengatakan alasannya, bukan menampilkan tabel kosong.
    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$this->d['event']->id}/monitoring/tidak-hadir")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Event/MonitoringTidakHadir')
            ->has('baris', 0)
            ->where('angka.tidak_hadir', 0)
            ->where('angka.izin', 0)
        );
});

it('menolak membuka rekap event milik sekolah lain', function () {
    $lain = School::factory()->create();
    $adminLain = User::factory()->admin()->untukSekolah($lain)->create();
    $tahunLain = AcademicYear::factory()->untukSekolah($lain)->aktif()->create();
    $semesterLain = Semester::factory()->create([
        'school_id' => $lain->id,
        'academic_year_id' => $tahunLain->id,
    ]);

    $dLain = siapkanPresensi($lain, $tahunLain, $semesterLain, $adminLain, ['R-01'], 2);

    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$dLain['event']->id}/monitoring/tidak-hadir")
        ->assertNotFound();
});
