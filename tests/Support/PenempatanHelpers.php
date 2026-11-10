<?php

/*
| Helper penempatan kursi - dulu tinggal DI DALAM PenempatanKocokTest.php.
|
| Alasannya sama dengan berkas lain di direktori ini: fungsi PHP bersifat
| global, sehingga helper yang ditulis di dalam satu berkas test terlihat oleh
| berkas lain SELAMA suite dijalankan seluruhnya, lalu hilang begitu berkasnya
| dijalankan sendirian. DenahTest dan TabloTest memakai `siapkanKocok()` -
| tanpa pemindahan ini keduanya hanya lulus saat seluruh suite berjalan.
|
| `siapkanKocok()` adalah fixture yang tepat untuk denah dan tablo, dan itu
| bukan kebetulan: kelasnya dua dengan ukuran sengaja BEDA (3 dan 5), jadi
| kedelapan peserta benar-benar terbagi ke dua ruang berkapasitas empat kursi.
| Fixture lain menghasilkan ruang dua kursi atau satu ruang saja, dan denah
| yang hanya punya satu ruang tidak membuktikan apa pun tentang pemilihan
| ruang.
*/

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\ClassEnrollment;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Models\EventClass;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\EventParticipantService;
use App\Domain\Event\Services\EventRegistrar;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\PenempatanKocok;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Collection;

/**
 * Tarik lima angka dari generator acak global.
 *
 * Dipakai untuk membuktikan generatornya benar-benar dikembalikan sesudah
 * mengocok - lihat test "mengembalikan generator acak".
 *
 * @return array<int, int>
 */
function tarikanAcak(int $jumlah): array
{
    $nilai = [];

    for ($i = 0; $i < $jumlah; $i++) {
        $nilai[] = mt_rand();
    }

    return $nilai;
}

/**
 * Event dua kelas, dua ruang, satu ujian, satu sesi - siap dikocok.
 *
 * Kapasitasnya dipasang di ruang EVENT, bukan di ruang sesi, dan itu disengaja:
 * angka itulah yang membentuk peta event. Kapasitas sesi hanya boleh menimpanya
 * bila sesi itu memang lebih sempit, dan test tersendiri yang menguji keadaan
 * itu.
 *
 * @param  int  $kapasitasPerRuang  Kapasitas tiap ruang event, lewat capacity_override.
 * @param  int  $jumlahRuang  Berapa ruang yang dibuat untuk eventnya.
 * @return array{
 *     event: SchoolEvent,
 *     ujian: Exam,
 *     sesi: ExamSession,
 *     ruang: Collection<int, ExamSessionRoom>,
 *     kelas: array<string, int>
 * }
 */
function siapkanKocok(
    User $admin,
    AcademicYear $tahun,
    int $ukuranX = 3,
    int $ukuranXI = 5,
    int $kapasitasPerRuang = 4,
    int $jumlahRuang = 2,
): array {
    $sekolah = School::find($admin->school_id);

    $event = app(EventRegistrar::class)->buat([
        'academic_year_id' => $tahun->id,
        'semester_id' => null,
        'name' => 'UAS Campuran',
        'event_type' => 'Ujian akhir semester',
        'start_at' => '2026-12-01 07:30:00',
        'end_at' => '2026-12-12 12:00:00',
    ], $sekolah->id, $admin->id, []);

    $mapel = Subject::factory()->create([
        'school_id' => $sekolah->id,
        'code' => 'MPL-'.$event->id,
        'name' => 'Mapel '.$event->id,
    ]);

    EventSubject::create([
        'school_id' => $sekolah->id,
        'event_id' => $event->id,
        'subject_id' => $mapel->id,
    ]);

    $kelas = [];

    foreach (['X-1' => $ukuranX, 'XI-A' => $ukuranXI] as $nama => $jumlah) {
        $idKelas = SchoolClass::factory()->create([
            'school_id' => $sekolah->id,
            'academic_year_id' => $tahun->id,
            'name' => $nama.'-'.$event->id,
            'grade_level' => explode('-', $nama)[0],
        ])->id;

        $kelas[$nama] = $idKelas;

        EventClass::create([
            'school_id' => $sekolah->id,
            'event_id' => $event->id,
            'class_id' => $idKelas,
        ]);

        foreach (range(1, $jumlah) as $_) {
            $siswa = User::factory()->siswa()->untukSekolah($sekolah)->create();

            ClassEnrollment::create([
                'school_id' => $sekolah->id,
                'class_id' => $idKelas,
                'student_id' => $siswa->id,
                'academic_year_id' => $tahun->id,
                'status' => 'aktif',
            ]);
        }
    }

    // Dihitung dengan `for`, BUKAN `range(1, $jumlahRuang)`: `range(1, 0)`
    // mengembalikan `[1, 0]`, sehingga "event tanpa ruang" justru mendapat DUA
    // ruang - dan test yang bermaksud menguji event kosong akan menguji event
    // yang lengkap.
    for ($i = 1; $i <= $jumlahRuang; $i++) {
        $ruang = Room::factory()->create([
            'school_id' => $sekolah->id,
            'code' => 'R-'.$event->id.'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'capacity' => 40,
        ]);

        EventRoom::create([
            'school_id' => $sekolah->id,
            'event_id' => $event->id,
            'room_id' => $ruang->id,
            // Kapasitas event yang menentukan pembagiannya. Bila hanya
            // `room.capacity` yang dipakai, dua ruang berkapasitas 40 untuk 8
            // peserta berarti satu ruang terisi penuh dan satu ruang kosong -
            // dan pemeriksaan campuran tidak membuktikan apa pun.
            'capacity_override' => $kapasitasPerRuang,
            'status' => 'aktif',
        ]);
    }

    app(EventParticipantService::class)->tarikDariKelas($event->refresh());

    $ujian = app(ExamRegistrar::class)->buat($event->refresh(), [
        'subject_id' => $mapel->id,
        'title' => 'UAS Campuran',
        'exam_type' => 'Ujian akhir semester',
        'duration_minutes' => 90,
        'class_id' => [],
    ], $admin->id);

    $sesi = app(SesiService::class)->buat($ujian, [
        'access_opens_at' => '2026-12-01 07:30:00',
        'access_closes_at' => '2026-12-01 09:00:00',
        'hard_end_at' => '2026-12-01 09:30:00',
        'event_room_id' => $event->rooms()->pluck('id')->all(),
    ]);

    $ruang = $sesi->rooms()->orderBy('id')->get();

    app(PenempatanService::class)->tarikPeserta($ujian);

    return [
        'event' => $event,
        'ujian' => $ujian,
        'sesi' => $sesi,
        'ruang' => $ruang->map(fn (ExamSessionRoom $r) => $r->refresh())->values(),
        'kelas' => $kelas,
    ];
}

/** URL laman penempatan satu event. */
function jalurPenempatan(SchoolEvent $event): string
{
    return "/admin/events/{$event->id}/penempatan";
}

/**
 * Ujian KEDUA di event yang sama, beserta sesinya - satu mapel tambahan.
 *
 * Dipakai untuk menguji "satu peta, banyak mapel". Sesi kedua pada ujian yang
 * SAMA bukan bentuk yang bisa disebar (lihat test penolakannya), jadi bentuk
 * yang dipakai untuk menguji dua sesi sekaligus adalah dua mapel.
 *
 * @param  array<int, int>|null  $ruangEvent  null = seluruh ruang event.
 * @return array{0: Exam, 1: ExamSession}
 */
function ujianTambahan(
    User $admin,
    SchoolEvent $event,
    string $nama,
    ?array $ruangEvent = null,
): array {
    $mapel = Subject::factory()->create([
        'school_id' => $event->school_id,
        'code' => 'MPLT-'.$nama.'-'.$event->id,
        'name' => 'Mapel '.$nama.' '.$event->id,
    ]);

    // Boleh atau tidaknya mapel ini dipakai ujian dibaca dari daftar mapel
    // event, bukan dari tabel mapel sekolah.
    EventSubject::create([
        'school_id' => $event->school_id,
        'event_id' => $event->id,
        'subject_id' => $mapel->id,
    ]);

    $ujian = app(ExamRegistrar::class)->buat($event->refresh(), [
        'subject_id' => $mapel->id,
        'title' => 'UAS '.$nama,
        'exam_type' => 'Ujian akhir semester',
        'duration_minutes' => 90,
        'class_id' => [],
    ], $admin->id);

    $sesi = app(SesiService::class)->buat($ujian, [
        'access_opens_at' => '2026-12-02 07:30:00',
        'access_closes_at' => '2026-12-02 09:00:00',
        'hard_end_at' => '2026-12-02 09:30:00',
        'event_room_id' => $ruangEvent ?? $event->rooms()->pluck('id')->all(),
    ]);

    app(PenempatanService::class)->tarikPeserta($ujian);

    return [$ujian, $sesi];
}

/**
 * Ujian KEDUA yang hanya diikuti SATU kelas, beserta sesinya.
 *
 * Dipakai untuk menguji selisih antara "penghuni ruang pada peta event" dan
 * "peserta yang benar-benar ikut ujian ini". Selisih itu ADA pada setiap event
 * yang ujiannya tidak diikuti seluruh kelas - dan itulah bentuk yang paling
 * umum di sekolah - tetapi tidak satu pun fixture lain di direktori ini
 * menghasilkannya, karena semuanya membuat ujian untuk seluruh peserta event.
 */
function ujianSatuKelas(User $admin, SchoolEvent $event, int $classId, string $nama): ExamSession
{
    $mapel = Subject::factory()->create([
        'school_id' => $event->school_id,
        'code' => 'MPLS-'.$nama.'-'.$event->id,
        'name' => 'Mapel '.$nama.' '.$event->id,
    ]);

    EventSubject::create([
        'school_id' => $event->school_id,
        'event_id' => $event->id,
        'subject_id' => $mapel->id,
    ]);

    $ujian = app(ExamRegistrar::class)->buat($event->refresh(), [
        'subject_id' => $mapel->id,
        'title' => 'UAS '.$nama,
        'exam_type' => 'Ujian akhir semester',
        'duration_minutes' => 90,
        // Satu kelas, bukan seluruh peserta event.
        'class_id' => [$classId],
    ], $admin->id);

    $sesi = app(SesiService::class)->buat($ujian, [
        'access_opens_at' => '2026-12-03 07:30:00',
        'access_closes_at' => '2026-12-03 09:00:00',
        'hard_end_at' => '2026-12-03 09:30:00',
        'event_room_id' => $event->rooms()->pluck('id')->all(),
    ]);

    app(PenempatanService::class)->tarikPeserta($ujian);

    return $sesi;
}

/**
 * Peta `exam_participant_id => exam_session_room_id` yang TERSIMPAN.
 *
 * Kuncinya diurutkan sebelum dikembalikan, dan itu bukan kerapian. Tanpa
 * pengurutan, perbandingannya ikut menguji URUTAN KUNCI array - dan urutan itu
 * berbeda antara peta yang dibangun ruang-per-ruang (hasil kocokan) dan peta
 * yang dibaca dari basis data (menurut id penempatan). Dua peta yang isinya
 * sama persis akan dilaporkan berbeda, dan yang gagal bukan kodenya melainkan
 * cara test ini membandingkannya.
 *
 * @return array<int, int>
 */
function petaTersimpan(Exam $ujian): array
{
    $peta = ExamParticipant::query()
        ->where('exam_id', $ujian->id)
        ->pluck('exam_session_room_id', 'id')
        ->all();

    ksort($peta);

    return $peta;
}

/**
 * Peta `exam_participant_id => event_room_id` dari hasil kocokan.
 *
 * Yang dibandingkan di sini adalah peta EVENT, sebab itulah yang dihitung
 * `PenempatanKocok`. Penerjemahannya ke ruang SESI diuji terpisah, supaya
 * kegagalan pada salah satunya tidak tersembunyi di balik yang lain.
 *
 * @param  array<int, array<int, ExamParticipant>>  $perRuang
 * @return array<int, int>
 */
function petaKocokanEvent(array $perRuang): array
{
    $peta = [];

    foreach ($perRuang as $idRuang => $anggota) {
        foreach ($anggota as $p) {
            $peta[$p->id] = $idRuang;
        }
    }

    ksort($peta);

    return $peta;
}

/**
 * Peta yang SEHARUSNYA tersimpan pada satu sesi.
 *
 * @param  array<int, array<int, ExamParticipant>>  $perRuang
 * @return array<int, int>
 */
function petaKocokanSesi(
    PenempatanKocok $kocok,
    SchoolEvent $event,
    ExamSession $sesi,
    array $perRuang,
): array {
    $peta = $kocok->petaSesi($event, $sesi, $perRuang)['peta'];

    ksort($peta);

    return $peta;
}
