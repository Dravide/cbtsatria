<?php

/*
| Helper bersama untuk test.
|
| Berkas ini dulunya tinggal DI DALAM berkas test-nya sendiri - `masukSebagai`
| di ExamTest, `siapkanPresensi` di AttendanceTest, dan seterusnya. Fungsi PHP
| bersifat global, jadi selama suite dijalankan SELURUHNYA, helper itu saling
| terlihat dan semua hijau.
|
| Yang rusak adalah menjalankan satu berkas sendirian. `php artisan test
| tests/Feature/PenjadwalTest.php` menjawab "Call to undefined function
| siapkanUjianSiswa()" - bukan karena ada yang salah dengan PenjadwalTest,
| tetapi karena helper yang ia pakai kebetulan didefinisikan di berkas lain
| yang tidak ikut dimuat. Tujuh berkas terkena, dan itu menjebak justru saat
| orang paling butuh jawabannya: suite penuh hijau, sementara menunjuk satu
| berkas yang sedang diperiksa hasilnya galat yang menyesatkan.
|
| Karena itu helper-nya pindah ke sini. `tests/Pest.php` memuat seluruh isi
| direktori ini lebih dulu, jadi setiap berkas test melihatnya - dijalankan
| sendiri maupun bersama-sama.
*/

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\ClassEnrollment;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Models\EventClass;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Event\Services\EventParticipantService;
use App\Domain\Event\Services\EventRegistrar;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\PengawasService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Exam\Services\TokenSesi;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

function siapkanPresensi(
    School $sekolah,
    AcademicYear $tahun,
    Semester $semester,
    User $admin,
    array $namaRuang = ['R-01', 'R-02'],
    int $jumlahSiswa = 4,
    ?array $pengawasPerRuang = null,
    string $buka = '-1 hour',
    string $tutup = '+1 hour',
    string $akhir = '+2 hours',
): array {
    $event = app(EventRegistrar::class)->buat([
        'academic_year_id' => $tahun->id,
        'semester_id' => $semester->id,
        'name' => 'UAS Ganjil',
        'event_type' => 'Ujian akhir semester',
    ], $sekolah->id, $admin->id, []);

    $kelas = SchoolClass::factory()->create([
        'school_id' => $sekolah->id,
        'academic_year_id' => $tahun->id,
        'name' => 'X-1-'.$event->id,
    ]);

    $mapel = Subject::factory()->create([
        'school_id' => $sekolah->id,
        'code' => 'MTK-'.$event->id,
        'name' => 'Matematika '.$event->id,
    ]);

    EventClass::create(['school_id' => $sekolah->id, 'event_id' => $event->id, 'class_id' => $kelas->id]);
    EventSubject::create(['school_id' => $sekolah->id, 'event_id' => $event->id, 'subject_id' => $mapel->id]);

    $ruangEvent = collect();
    $perRuang = max(1, (int) ceil($jumlahSiswa / count($namaRuang)));

    foreach ($namaRuang as $kode) {
        $ruang = Room::factory()->create([
            'school_id' => $sekolah->id,
            'code' => $kode.'-'.$event->id,
            'name' => 'Ruang '.$kode,
            'capacity' => $perRuang,
        ]);

        $ruangEvent->push(EventRoom::create([
            'school_id' => $sekolah->id,
            'event_id' => $event->id,
            'room_id' => $ruang->id,
            'status' => 'aktif',
        ]));
    }

    $siswa = [];

    foreach (range(1, $jumlahSiswa) as $i) {
        $user = User::factory()->siswa()->untukSekolah($sekolah)->create(['name' => 'Siswa '.$i]);

        ClassEnrollment::create([
            'school_id' => $sekolah->id,
            'class_id' => $kelas->id,
            'student_id' => $user->id,
            'academic_year_id' => $tahun->id,
            'status' => 'aktif',
        ]);

        $siswa[] = $user;
    }

    app(EventParticipantService::class)->tarikDariKelas($event);

    $ujian = app(ExamRegistrar::class)->buat($event, [
        'subject_id' => $mapel->id,
        'title' => 'Ujian Akhir Semester Matematika',
        'exam_type' => 'Ujian akhir semester',
        'duration_minutes' => 90,
        // Terlambat boleh masuk - dan itu bukan detail: satu-satunya test yang
        // membedakan `hadir` dari `terlambat` di gerbang attempt membaca kolom
        // ini.
        'allow_late_entry' => true,
        'class_id' => [],
    ], $admin->id);

    $sesi = app(SesiService::class)->buat($ujian, [
        'session_number' => 1,
        'access_opens_at' => now()->modify($buka),
        'access_closes_at' => now()->modify($tutup),
        'hard_end_at' => now()->modify($akhir),
        'duration_minutes' => 90,
        'event_room_id' => $ruangEvent->pluck('id')->all(),
    ]);

    $penempatan = app(PenempatanService::class);
    $penempatan->tarikPeserta($ujian);
    $penempatan->sebar($ujian, $sesi);

    $ruang = $sesi->rooms()->orderBy('id')->get();

    foreach ($ruang as $i => $r) {
        $orang = $pengawasPerRuang[$i]
            ?? User::factory()->pengawas()->untukSekolah($sekolah)->create();

        EventCommitteeMember::create([
            'school_id' => $sekolah->id,
            'event_id' => $event->id,
            'user_id' => $orang->id,
            'name' => $orang->name,
            'position' => 'pengawas',
            'scope' => 'event',
            'status' => 'aktif',
        ]);

        app(PengawasService::class)->tugaskan($r, $orang, $admin->id);
    }

    app(TokenSesi::class)->terbitkan($sesi, $admin->id);

    // Draft -> Siap, lalu berhenti. Membuka sesinya adalah tindakan testnya
    // sendiri, bukan sesuatu yang dilakukan penyiapan data.
    $sesi->forceFill(['status' => StatusSesi::Siap])->save();

    return [
        'event' => $event->refresh(),
        'ujian' => $ujian,
        'sesi' => $sesi,
        'ruang' => $ruang,
        'siswa' => $siswa,
    ];
}

/** Ruang master yang dipakai satu ruang-sesi - kunci tautan pintu. */
function ruangMaster(ExamSessionRoom $ruang): Room
{
    return $ruang->eventRoom->room;
}
