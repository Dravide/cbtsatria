<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\ClassEnrollment;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventClass;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\EventParticipantService;
use App\Domain\Event\Services\EventReadiness;
use App\Domain\Event\Services\EventRegistrar;
use App\Domain\Event\Services\EventStatusService;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Enums\StatusToken;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\ExamToken;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\KonflikJadwal;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\PengawasService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Exam\Services\TokenSesi;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Question\Services\PemasanganPaket;
use App\Domain\Tenancy\Models\School;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/**
 * Fase 5 - Manajemen ujian. Wireframe plate 16-18, PRD §9.2-§9.7.
 *
 * ---------------------------------------------------------------------
 * Apa yang dijaga berkas ini, dan mengapa bukan sekadar "fitur jalan"
 * ---------------------------------------------------------------------
 * Kriteria selesai blueprint Fase 5 berbunyi: "satu ujian dengan 3 sesi x 8
 * ruang x 300 peserta tersusun tanpa peserta ganda, tanpa ruang melebihi
 * kapasitas, dan tanpa pengawas bentrok."
 *
 * Ketiga kalimat terakhir itu adalah KALIMAT NEGATIF - "tanpa X" - dan itulah
 * yang menentukan bentuk test di sini. Test yang membuktikan penolakan lebih
 * penting daripada test yang membuktikan kebahagiaan, karena yang pertama
 * adalah satu-satunya yang membuktikan sistemnya benar-benar memeriksa.
 * Sistem yang tidak memeriksa apa pun akan lulus setiap test "bahagia".
 *
 * Karena itu setiap aturan kritis di bawah punya test yang MEMBUKTIKAN
 * PENOLAKANNYA, sesuai gate kualitas blueprint: "Setiap aturan kritis punya
 * test yang membuktikan penolakannya."
 *
 * ---------------------------------------------------------------------
 * Empat kelompok
 * ---------------------------------------------------------------------
 *   1. Kriteria blueprint - nol temuan pada jadwal yang benar, dan setiap
 *      temuan muncul saat jadwalnya memang salah.
 *   2. Isolasi tenant - 404, bukan 403, di setiap route ter-bind.
 *   3. Jalur tulis - token, jendela sesi, penempatan, penugasan.
 *   4. Gerbang kesiapan - menolak Siap selama masih ada benturan.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->lain = School::factory()->create();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.ujian',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
    ]);

    $this->event = siapkanEvent($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $this->ruang = $this->event->rooms()->orderBy('id')->get();
    $this->mapel = $this->event->subjects()->first()->subject;
});

/**
 * Satu event lengkap sampai jadwal: kelas, mapel, ruang, dan peserta.
 *
 * Sengaja berhenti SEBELUM ujian dibuat. Test yang menguji jalur tulis ujian
 * harus mulai dari keadaan yang belum punya ujian - kalau helper ini membuatnya
 * lebih jauh, test "menambah ujian" akan mulai dari event yang sudah punya
 * ujian, dan jumlah ujiannya tidak lagi membuktikan apa pun.
 */
function siapkanEvent(
    School $sekolah,
    AcademicYear $tahun,
    ?Semester $semester,
    User $admin,
    int $jumlahRuang = 2,
    int $jumlahSiswa = 4,
): SchoolEvent {
    $event = app(EventRegistrar::class)->buat([
        'academic_year_id' => $tahun->id,
        'semester_id' => $semester?->id,
        'name' => 'UAS Ganjil',
        'event_type' => 'Ujian akhir semester',
        'start_at' => '2026-12-01 07:30:00',
        'end_at' => '2026-12-12 12:00:00',
    ], $sekolah->id, $admin->id, []);

    // Nama kelas dan kode ruang memuat id eventnya, dan itu bukan hiasan.
    // unique(school_id, academic_year_id, name) pada `classes` - dan
    // kemungkinan yang sama pada `rooms` - berarti dua event di sekolah yang
    // sama pada tahun ajaran yang sama tidak boleh punya kelas bernama sama.
    // Tanpa akhiran ini, setiap test yang menyiapkan event KEDUA di sekolah
    // yang sama gagal dengan pelanggaran integritas yang tidak menyebut
    // sebabnya sama sekali.
    $kelas = SchoolClass::factory()->create([
        'school_id' => $sekolah->id,
        'academic_year_id' => $tahun->id,
        'name' => 'X-1-'.$event->id,
    ]);

    $mapel = Subject::factory()->create([
        'school_id' => $sekolah->id,
        'code' => 'MPL-'.$event->id,
        'name' => 'Mapel '.$event->id,
    ]);

    EventClass::create([
        'school_id' => $sekolah->id,
        'event_id' => $event->id,
        'class_id' => $kelas->id,
    ]);

    EventSubject::create([
        'school_id' => $sekolah->id,
        'event_id' => $event->id,
        'subject_id' => $mapel->id,
    ]);

    // Kapasitas 40 per ruang: cukup untuk seluruh siswa di setiap test, supaya
    // test yang bukan tentang kapasitas tidak gagal karena kapasitas.
    foreach (range(1, $jumlahRuang) as $i) {
        $ruang = Room::factory()->create([
            'school_id' => $sekolah->id,
            'code' => 'R-'.$event->id.'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'capacity' => 40,
        ]);

        EventRoom::create([
            'school_id' => $sekolah->id,
            'event_id' => $event->id,
            'room_id' => $ruang->id,
            'status' => 'aktif',
        ]);
    }

    foreach (range(1, $jumlahSiswa) as $i) {
        $siswa = User::factory()->siswa()->untukSekolah($sekolah)->create();

        ClassEnrollment::create([
            'school_id' => $sekolah->id,
            'class_id' => $kelas->id,
            'student_id' => $siswa->id,
            'academic_year_id' => $tahun->id,
            'status' => 'aktif',
        ]);
    }

    app(EventParticipantService::class)->tarikDariKelas($event);

    return $event->refresh();
}

/**
 * Ujian + satu sesi + ruang sesi + penempatan + penugasan + token.
 *
 * @param  array<int, int>|null  $ruangEventId
 * @return array{ujian: Exam, sesi: ExamSession, ruang: Collection<int, ExamSessionRoom>, pengawas: User}
 */
function jadwalkan(
    SchoolEvent $event,
    Subject $mapel,
    User $admin,
    ?User $pengawas = null,
    ?array $ruangEventId = null,
    string $buka = '2026-12-01 07:30:00',
    string $tutup = '2026-12-01 09:00:00',
    string $akhir = '2026-12-01 09:30:00',
): array {
    $pengawas ??= User::factory()->pengawas()->untukSekolah(School::find($event->school_id))->create();

    EventCommitteeMember::create([
        'school_id' => $event->school_id,
        'event_id' => $event->id,
        'user_id' => $pengawas->id,
        'name' => $pengawas->name,
        'position' => 'pengawas',
        'scope' => 'event',
        'status' => 'aktif',
    ]);

    $ujian = app(ExamRegistrar::class)->buat($event, [
        'subject_id' => $mapel->id,
        'title' => 'UAS '.$mapel->name,
        'exam_type' => 'Ujian akhir semester',
        'duration_minutes' => 90,
        'class_id' => [],
    ], $admin->id);

    $sesi = app(SesiService::class)->buat($ujian, [
        'access_opens_at' => $buka,
        'access_closes_at' => $tutup,
        'hard_end_at' => $akhir,
        'event_room_id' => $ruangEventId ?? $event->rooms()->pluck('id')->all(),
    ]);

    $penempatan = app(PenempatanService::class);
    $penempatan->tarikPeserta($ujian);
    $penempatan->sebar($ujian, $sesi);

    $ruang = $sesi->rooms()->orderBy('id')->get();

    tugaskanTiapRuang($ruang, $event, $admin, $pengawas);

    app(TokenSesi::class)->terbitkan($sesi, $admin->id);

    return ['ujian' => $ujian, 'sesi' => $sesi, 'ruang' => $ruang, 'pengawas' => $pengawas];
}

/**
 * Satu pengawas per ruang, dan pengawas PERTAMA tetap orang yang diberikan.
 *
 * Satu per ruang bukan pilihan gaya: satu orang tidak bisa berdiri di dua
 * ruang pada jendela yang sama, dan PengawasService menolaknya - sehingga
 * ruang kedua yang diberi pengawas yang sama membuat test gagal dengan
 * ValidationException alih-alih menguji apa yang seharusnya diuji.
 *
 * Yang pertama dipertahankan supaya test bisa menamai seseorang dan
 * mencarinya kembali; sisanya dibuat baru di sekolah yang sama.
 *
 * @param  Illuminate\Support\Collection<int, ExamSessionRoom>  $ruang
 */
function tugaskanTiapRuang(
    Illuminate\Support\Collection $ruang,
    SchoolEvent $event,
    User $admin,
    ?User $pertama = null,
): void {
    foreach ($ruang as $i => $r) {
        if ($i === 0 && $pertama !== null) {
            app(PengawasService::class)->tugaskan($r, $pertama, $admin->id);

            continue;
        }

        $orang = User::factory()->pengawas()
            ->untukSekolah(School::find($event->school_id))
            ->create();

        EventCommitteeMember::create([
            'school_id' => $event->school_id,
            'event_id' => $event->id,
            'user_id' => $orang->id,
            'name' => $orang->name,
            'position' => 'pengawas',
            'scope' => 'event',
            'status' => 'aktif',
        ]);

        app(PengawasService::class)->tugaskan($r, $orang, $admin->id);
    }
}

/* ------------------------------------------------- Kriteria blueprint Fase 5 */

it('menyusun satu ujian tiga sesi tanpa satu pun temuan', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    // Sesi 2 dan 3 memakai ruang yang sama pada HARI yang berbeda. Itu
    // penyusunan jadwal yang benar - dan justru itu yang harus dibuktikan:
    // pemeriksaan benturan yang membandingkan ruang saja, tanpa melihat
    // jendelanya, akan melaporkan tiga sesi ini sebagai bentrok.
    foreach ([2, 3] as $nomor) {
        $hari = '2026-12-0'.$nomor;

        $sesi = app(SesiService::class)->buat($data['ujian'], [
            'session_number' => $nomor,
            'access_opens_at' => $hari.' 07:30:00',
            'access_closes_at' => $hari.' 09:00:00',
            'hard_end_at' => $hari.' 09:30:00',
            'event_room_id' => $this->ruang->pluck('id')->all(),
        ]);

        app(PenempatanService::class)->sebar($data['ujian'], $sesi);

        // Pengawas per sesi, bukan orang yang sama sepanjang tiga hari.
        // Jendelanya berbeda hari sehingga satu orang SEBENARNYA boleh
        // mengawas ketiganya - tetapi memakai orang yang sama berarti test
        // ini tidak pernah membuktikan bahwa penugasannya menempel pada
        // ruang-SESI, dan bukan pada ruangnya saja.
        tugaskanTiapRuang($sesi->rooms()->orderBy('id')->get(), $this->event, $this->admin);
    }

    $temuan = app(KonflikJadwal::class)->periksa($this->event->refresh());

    expect($temuan)->toBe([])
        ->and($data['ujian']->sessions()->count())->toBe(3);
});

it('menolak peserta ganda di satu ujian', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    // Menarik ulang TIDAK boleh menggandakan: unique(exam_id, student_id)
    // menahannya di basis data, dan tarikPeserta memakai updateOrCreate -
    // bukan create.
    $lagi = app(PenempatanService::class)->tarikPeserta($data['ujian']);

    $baris = ExamParticipant::query()->where('exam_id', $data['ujian']->id)->get();

    expect($lagi)->toBe(0)
        ->and($baris->count())->toBe($this->event->participants()->count());
});

it('melaporkan ruang yang melebihi kapasitas', function () {
    // Satu ruang berkapasitas dua kursi untuk empat peserta. Penyebarannya
    // ditolak SEBELUM satu baris pun ditulis - lihat PenempatanService.
    $ruangKecil = $this->ruang->first();
    $ruangKecil->forceFill(['capacity_override' => 2])->save();

    $temuan = null;

    try {
        jadwalkan($this->event, $this->mapel, $this->admin, ruangEventId: [$ruangKecil->id]);
    } catch (DomainException $e) {
        $temuan = $e->getMessage();
    }

    expect($temuan)->toContain('kursi');
});

it('melaporkan pengawas yang ditugaskan di dua ruang bersamaan', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    // Sesi kedua menumpang jendela sesi pertama di ruang yang BERBEDA. Satu
    // orang tidak bisa berdiri di dua ruang sekaligus, dan KonflikJadwal
    // memeriksanya lewat jendela penugasan - yang null berarti "ikuti sesi".
    $sesi2 = app(SesiService::class)->buat($data['ujian'], [
        'session_number' => 2,
        'access_opens_at' => '2026-12-01 08:00:00',
        'access_closes_at' => '2026-12-01 09:30:00',
        'hard_end_at' => '2026-12-01 10:00:00',
        'event_room_id' => [$this->ruang->last()->id],
    ]);

    $ruangSesi2 = $sesi2->rooms()->first();
    $temuan = null;

    try {
        app(PengawasService::class)->tugaskan($ruangSesi2, $data['pengawas'], $this->admin->id);
    } catch (ValidationException $e) {
        $temuan = $e->validator->errors()->first();
    }

    // Ditolak SAAT ditugaskan, bukan hanya dilaporkan kemudian - dan itu
    // bagian yang membedakan pencegahan dari laporan.
    expect($temuan)->toContain('ruang lain pada jam yang sama')
        ->and(SupervisionAssignment::query()->where('supervisor_id', $data['pengawas']->id)->count())->toBe(1);

    // Sesudah penugasan yang bentrok dilewati, pemeriksaannya masih menemukan
    // benturan itu bila barisnya ditulis langsung - jaring kedua.
    $asesmen = $ruangSesi2->supervisors()->create([
        'school_id' => $this->sekolah->id,
        'supervisor_id' => $data['pengawas']->id,
        'status' => 'aktif',
    ]);

    expect($asesmen->exists)->toBeTrue();

    $jenis = collect(app(KonflikJadwal::class)->periksa($this->event->refresh()))
        ->where('jenis', 'benturan-pengawas');

    expect($jenis)->not->toBeEmpty();
});

it('melaporkan ruang yang dipakai dua sesi pada jam yang sama', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    // Jendela yang BERTUMPANG di ruang yang SAMA. Inilah satu-satunya bentuk
    // benturan yang benar-benar benturan ruang.
    $sesi2 = app(SesiService::class)->buat($data['ujian'], [
        'session_number' => 2,
        'access_opens_at' => '2026-12-01 08:00:00',
        'access_closes_at' => '2026-12-01 09:30:00',
        'hard_end_at' => '2026-12-01 10:00:00',
        'event_room_id' => $this->ruang->pluck('id')->all(),
    ]);

    $temuan = collect(app(KonflikJadwal::class)->periksa($this->event->refresh()))
        ->where('jenis', 'benturan-ruang');

    expect($temuan)->not->toBeEmpty()
        ->and($temuan->first()['pesan'])->toContain('sudah dipakai');

    // Temuannya menempel pada SESI, bukan hanya pada event: itulah yang
    // membuat barisnya bisa ditandai di tabel plate 16.
    expect($temuan->first()['sesi_id'])->not->toBeNull()
        ->and($temuan->contains(fn (array $t) => $t['sesi_id'] === $sesi2->id))->toBeTrue();
});

it('menerima dua sesi yang jendelanya bersentuhan tepat di batas', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    // Sesi kedua dibuka TEPAT ketika sesi pertama berakhir. Perbandingannya
    // ketat (< bukan <=), karena menandai ini sebagai benturan berarti
    // menghukum sekolah yang memakai ruangnya efisien.
    app(SesiService::class)->buat($data['ujian'], [
        'session_number' => 2,
        'access_opens_at' => '2026-12-01 09:30:00',
        'access_closes_at' => '2026-12-01 11:00:00',
        'hard_end_at' => '2026-12-01 11:30:00',
        'event_room_id' => $this->ruang->pluck('id')->all(),
    ]);

    $benturan = collect(app(KonflikJadwal::class)->periksa($this->event->refresh()))
        ->where('jenis', 'benturan-ruang');

    expect($benturan)->toBeEmpty();
});

/* --------------------------------------------------------- Isolasi tenant */

it('membalas 404 untuk ujian milik sekolah lain', function () {
    $tahunLain = AcademicYear::factory()->untukSekolah($this->lain)->create();
    $semesterLain = Semester::factory()->create([
        'school_id' => $this->lain->id,
        'academic_year_id' => $tahunLain->id,
    ]);
    $adminLain = User::factory()->admin()->untukSekolah($this->lain)->create();
    $eventLain = siapkanEvent($this->lain, $tahunLain, $semesterLain, $adminLain);
    $data = jadwalkan($eventLain, $eventLain->subjects()->first()->subject, $adminLain);

    // 404 dan bukan 403: 403 membuktikan barisnya ada, dan itu sudah
    // membocorkan satu bit tentang sekolah lain.
    masukSebagai($this, $this->admin)->get("/admin/events/{$eventLain->id}/jadwal")
        ->assertNotFound();

    masukSebagai($this, $this->admin)->get("/admin/events/{$eventLain->id}/pengawas")
        ->assertNotFound();

    masukSebagai($this, $this->admin)->get("/admin/events/{$eventLain->id}/token")
        ->assertNotFound();

    masukSebagai($this, $this->admin)->put("/admin/events/{$eventLain->id}/ujian/{$data['ujian']->id}", ['title' => 'Dirusak'])
        ->assertNotFound();

    expect($data['ujian']->refresh()->title)->not->toBe('Dirusak');
});

it('menolak ujian milik event lain di sekolah yang sama', function () {
    // Scope tenant TIDAK menutup ini: sekolahnya memang sama. Yang menutupnya
    // adalah abort_unless di controller, dan tanpa test ini pemeriksaan itu
    // bisa hilang tanpa satu pun test gagal.
    $lain = siapkanEvent($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $data = jadwalkan($lain, $lain->subjects()->first()->subject, $this->admin);

    masukSebagai($this, $this->admin)->put("/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}", [
        'title' => 'Dirusak',
        'duration_minutes' => 60,
    ])
        ->assertNotFound();

    masukSebagai($this, $this->admin)->post("/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}/sesi/{$data['sesi']->id}/mulai")
        ->assertNotFound();

    expect($data['ujian']->refresh()->title)->not->toBe('Dirusak');
});

it('menolak sesi milik ujian lain di URL ujian yang sama', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);
    $lain = jadwalkan($this->event, $this->mapel, $this->admin);

    // Kedua sesi ada di event yang sama, sehingga pastikanMilikEvent lolos -
    // yang menangkapnya adalah pastikanSesiMilikUjian.
    masukSebagai($this, $this->admin)->post("/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}/sesi/{$lain['sesi']->id}/mulai")
        ->assertNotFound();
});

it('menolak token milik event lain', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);
    $lain = siapkanEvent($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $lainData = jadwalkan($lain, $lain->subjects()->first()->subject, $this->admin);

    $token = ExamToken::query()->where('exam_session_id', $lainData['sesi']->id)->first();

    masukSebagai($this, $this->admin)->post("/admin/events/{$this->event->id}/token/{$token->id}/cabut")
        ->assertNotFound();

    expect($token->refresh()->revoked_at)->toBeNull();
});

it('menolak ruang-sesi milik event lain saat menugaskan pengawas', function () {
    $lain = siapkanEvent($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $lainData = jadwalkan($lain, $lain->subjects()->first()->subject, $this->admin);

    masukSebagai($this, $this->admin)->post("/admin/events/{$this->event->id}/pengawas/ruang/{$lainData['ruang']->first()->id}", [
        'supervisor_id' => $this->admin->id,
    ])
        ->assertNotFound();
});

/* -------------------------------------------------------------- Token §9.7 */

it('menerbitkan satu token per sesi dan mencabut yang lama', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    $pertama = ExamToken::query()->where('exam_session_id', $data['sesi']->id)->first();

    expect($pertama->revoked_at)->toBeNull();

    $kedua = app(TokenSesi::class)->terbitkan($data['sesi'], $this->admin->id);

    // Keputusan §24 #6. Menerbitkan ulang MENCABUT yang lama: tanpa itu,
    // token yang sudah difoto siswa tetap berlaku, dan tidak ada yang tahu
    // berapa banyak token sah yang beredar untuk satu sesi.
    expect($pertama->refresh()->revoked_at)->not->toBeNull()
        ->and($kedua['token']->revoked_at)->toBeNull()
        ->and(ExamToken::query()->where('exam_session_id', $data['sesi']->id)->whereNull('revoked_at')->count())->toBe(1);
});

it('menolak token di luar jendela berlakunya', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    $token = ExamToken::query()->where('exam_session_id', $data['sesi']->id)->first();

    // Diterbitkan untuk sesi Desember, dan hari ini bukan Desember - sehingga
    // tokennya belum masuk jendelanya.
    expect($token->berlaku())->toBeFalse()
        ->and($token->status(false))->toBe(StatusToken::Draft)
        ->and(app(TokenSesi::class)->bacaBerwenang($token, $this->admin))->toBeNull();

    masukSebagai($this, $this->admin)->post("/admin/events/{$this->event->id}/token/{$token->id}/baca")
        ->assertRedirect();

    // Tidak ada baris riwayat yang ditulis: pembacaan yang DITOLAK bukan
    // pembukaan, dan mencatatnya akan membuat riwayat plate 18 memuat
    // kejadian yang tidak pernah terjadi.
    expect($token->views()->count())->toBe(0);
});

it('mencatat setiap pembacaan token yang berwenang', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    $token = ExamToken::query()->where('exam_session_id', $data['sesi']->id)->first();

    $token->forceFill([
        'valid_from' => now()->subMinutes(10),
        'valid_until' => now()->addHour(),
    ])->save();

    masukSebagai($this, $this->admin)->post("/admin/events/{$this->event->id}/token/{$token->id}/baca")
        ->assertRedirect()
        ->assertSessionHas('kode_token');

    // Anotasi plate 18: "setiap pembukaan token dicatat: kalau token bocor,
    // asalnya bisa ditelusuri ke satu akun."
    expect($token->views()->count())->toBe(1)
        ->and($token->views()->first()->user_id)->toBe($this->admin->id)
        ->and($token->status(true))->toBe(StatusToken::Dibuka);
});

it('tidak memengaruhi token sesi lain saat satu token dicabut', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    $sesi2 = app(SesiService::class)->buat($data['ujian'], [
        'session_number' => 2,
        'access_opens_at' => '2026-12-02 07:30:00',
        'access_closes_at' => '2026-12-02 09:00:00',
        'hard_end_at' => '2026-12-02 09:30:00',
        'event_room_id' => $this->ruang->pluck('id')->all(),
    ]);

    // Token hanya bisa terbit untuk sesi yang setiap ruangnya sudah diawasi
    // (lihat TokenSesi) - jadi pengawasnya ditugaskan lebih dulu.
    tugaskanTiapRuang($sesi2->rooms()->orderBy('id')->get(), $this->event, $this->admin);

    $token2 = app(TokenSesi::class)->terbitkan($sesi2, $this->admin->id)['token'];

    $token1 = ExamToken::query()->where('exam_session_id', $data['sesi']->id)->whereNull('revoked_at')->first();

    masukSebagai($this, $this->admin)->post("/admin/events/{$this->event->id}/token/{$token1->id}/cabut")
        ->assertRedirect();

    expect($token1->refresh()->revoked_at)->not->toBeNull()
        ->and($token2->refresh()->revoked_at)->toBeNull();
});

it('menolak menerbitkan token untuk sesi yang belum punya pengawas', function () {
    // Sesi dibangun tanpa penugasan sama sekali. Token yang terbit untuk ruang
    // tanpa pengawas adalah token yang tidak ada yang memverifikasi - dan ia
    // terbit tanpa satu pun tanda bahwa ada yang kurang.
    $ujian = app(ExamRegistrar::class)->buat($this->event, [
        'subject_id' => $this->mapel->id,
        'title' => 'Tanpa Pengawas',
        'duration_minutes' => 60,
        'class_id' => [],
    ], $this->admin->id);

    $sesi = app(SesiService::class)->buat($ujian, [
        'access_opens_at' => '2026-12-01 07:30:00',
        'access_closes_at' => '2026-12-01 09:00:00',
        'hard_end_at' => '2026-12-01 09:30:00',
        'event_room_id' => [$this->ruang->first()->id],
    ]);

    $pesan = null;

    try {
        app(TokenSesi::class)->terbitkan($sesi, $this->admin->id);
    } catch (DomainException $e) {
        $pesan = $e->getMessage();
    }

    expect($pesan)->toContain('belum punya pengawas')
        ->and(ExamToken::query()->where('exam_session_id', $sesi->id)->count())->toBe(0);
});

/* ------------------------------------------------------------- Sesi §9.3 */

it('menolak sesi yang batas mulainya mendahului pembukaannya', function () {
    $ujian = app(ExamRegistrar::class)->buat($this->event, [
        'subject_id' => $this->mapel->id,
        'title' => 'Jendela Terbalik',
        'duration_minutes' => 60,
        'class_id' => [],
    ], $this->admin->id);

    // Diperiksa di SesiService, bukan hanya di Form Request: seeder,
    // penggandaan jadwal, dan impor juga membuat sesi, dan ketiganya tidak
    // melewati Form Request.
    expect(fn () => app(SesiService::class)->buat($ujian, [
        'access_opens_at' => '2026-12-01 09:00:00',
        'access_closes_at' => '2026-12-01 07:30:00',
        'hard_end_at' => '2026-12-01 09:30:00',
    ]))->toThrow(ValidationException::class);
});

it('menolak batas akhir mutlak yang lebih awal dari batas mulai', function () {
    $ujian = app(ExamRegistrar::class)->buat($this->event, [
        'subject_id' => $this->mapel->id,
        'title' => 'Akhir Terlalu Awal',
        'duration_minutes' => 60,
        'class_id' => [],
    ], $this->admin->id);

    // Yang paling mudah terlewat: jendela masuknya masih terbuka ketika
    // ujiannya sudah harus berhenti, dan siswa yang memakainya kehilangan
    // waktu mengerjakan tanpa ada yang tahu mengapa.
    expect(fn () => app(SesiService::class)->buat($ujian, [
        'access_opens_at' => '2026-12-01 07:30:00',
        'access_closes_at' => '2026-12-01 10:00:00',
        'hard_end_at' => '2026-12-01 09:00:00',
    ]))->toThrow(ValidationException::class);
});

it('menolak membuka sesi yang ruangnya belum punya pengawas', function () {
    $ujian = app(ExamRegistrar::class)->buat($this->event, [
        'subject_id' => $this->mapel->id,
        'title' => 'Belum Diawasi',
        'duration_minutes' => 60,
        'class_id' => [],
    ], $this->admin->id);

    $sesi = app(SesiService::class)->buat($ujian, [
        'access_opens_at' => '2026-12-01 07:30:00',
        'access_closes_at' => '2026-12-01 09:00:00',
        'hard_end_at' => '2026-12-01 09:30:00',
        'event_room_id' => [$this->ruang->first()->id],
    ]);

    $sesi->forceFill(['status' => StatusSesi::Siap])->save();

    expect(fn () => app(SesiService::class)->mulai($sesi))
        ->toThrow(DomainException::class);

    expect($sesi->refresh()->status)->toBe(StatusSesi::Siap);
});

/* ------------------------------------------------- Siapkan sesi (2026-11-07) */

it('menyiapkan sesi lalu membukanya', function () {
    // Test ini yang membuktikan bugnya tertutup dari ujung ke ujung, bukan
    // sekadar bahwa methodnya ada. Sebelum `siapkan()`, langkah kedua di sini
    // SELALU ditolak: sesi yang lahir dari laman jadwal berstatus Draft, dan
    // graf StatusSesi melarang Draft -> Berjalan.
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Draft);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}/sesi/{$data['sesi']->id}/siapkan")
        ->assertSessionHasNoErrors();

    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Siap);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}/sesi/{$data['sesi']->id}/mulai")
        ->assertSessionHasNoErrors();

    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Berjalan);
});

it('menolak menyiapkan sesi yang sudah berjalan', function () {
    // Graf menolak Berjalan -> Siap, dan penolakannya harus muncul sebagai
    // pesan yang bisa dibaca Admin - bukan 500.
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    app(SesiService::class)->siapkan($data['sesi']);
    app(SesiService::class)->mulai($data['sesi']);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}/sesi/{$data['sesi']->id}/siapkan")
        ->assertSessionHas('galat');

    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Berjalan);
});

it('menolak sesi milik ujian lain lewat rute siapkan', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);
    $lain = jadwalkan($this->event, $this->mapel, $this->admin);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}/sesi/{$lain['sesi']->id}/siapkan")
        ->assertNotFound();

    expect($lain['sesi']->refresh()->status)->toBe(StatusSesi::Draft);
});

it('tidak lagi mengirim status ujian ke panel', function () {
    // `exams.status` dijatuhkan 2026-11-07 (migrasi 0016_01_01_000030). Kalau
    // propnya muncul lagi di sini, berarti ada yang menambahkan kolomnya
    // kembali - dan chip yang selalu berbunyi "draft" ikut kembali bersamanya.
    jadwalkan($this->event, $this->mapel, $this->admin);

    masukSebagai($this, $this->admin)->get("/admin/events/{$this->event->id}/jadwal")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Jadwal')
            ->missing('ujian.0.status')
            ->has('ujian.0.sesi')
        );
});

it('mewarisi paket yang sudah terpasang di mapel saat ujian dibuat', function () {
    // Cacat 07 Nov 2026, dan bentuknya satu baris yang tidak ada.
    //
    // Admin memasang paket di plate 15 lewat `PemasanganPaket`, lalu membuat
    // ujiannya di plate 16 untuk mapel YANG SAMA. Paket itu sudah diketahui
    // pada saat ujiannya lahir - tetapi `simpanUjian()` tidak pernah
    // mengirimkan `question_package_id` (field itu tidak ada di daftar
    // validasinya), sehingga setiap ujian lahir dengan paket null, snapshot
    // `exam_questions` kosong, dan anak yang membuka gerbangnya menerima
    // "Ujian ini tidak punya butir soal. Hubungi Admin sebelum memulai." -
    // di ruang ujian, pada hari ujian.
    $sekolah = School::find($this->event->school_id);
    $guru = User::factory()->guru()->untukSekolah($sekolah)->create();

    $paket = QuestionPackage::factory()->oleh($guru, $this->mapel)->siap()->create([
        'name' => 'Paket '.$this->mapel->name,
    ]);

    QuestionPackageItem::factory()->create([
        'school_id' => $this->event->school_id,
        'question_package_id' => $paket->id,
        'question_id' => Question::factory()->oleh($guru, $this->mapel)->create()->id,
    ]);

    $baris = $this->event->subjects()->where('subject_id', $this->mapel->id)->firstOrFail();

    app(PemasanganPaket::class)->pasang((int) $baris->id, $paket->id, $this->admin->id);

    masukSebagai($this, $this->admin)->post("/admin/events/{$this->event->id}/ujian", [
        'subject_id' => $this->mapel->id,
        'title' => 'ASAS '.$this->mapel->name,
        'duration_minutes' => 90,
        'class_id' => [],
    ])->assertSessionHasNoErrors();

    $ujian = Exam::query()->orderByDesc('id')->firstOrFail();

    expect($ujian->question_package_id)->toBe($paket->id)
        // Dan yang menentukan bagi siswa: SNAPSHOTNYA benar-benar tertulis.
        // Paket yang tercatat di kolom tetapi tidak dibekukan menghasilkan
        // gerbang yang menerima ujiannya dan pengerjaan yang kosong.
        ->and(ExamQuestion::query()->where('exam_id', $ujian->id)->count())->toBe(1);

    // Panel jadwal membacanya sebagai "sudah dibekukan" - dan itu yang
    // mengunci mapelnya dari penggantian.
    masukSebagai($this, $this->admin)->get("/admin/events/{$this->event->id}/jadwal")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Jadwal')
            ->where('ujian.0.ada_paket', true)
        );
});

it('membekukan paket yang dipasang sesudah ujiannya lahir, saat sesinya disiapkan', function () {
    // Urutan kerja yang docblock `ExamRegistrar::bekukanPaket()` janjikan
    // sendiri: "Admin menyusun jadwal lebih dulu, dan paketnya dipasang
    // Gurunya belakangan." Janji itu tidak punya pelaksananya sampai
    // 07 Nov 2026 - `bekukanPaket()` hanya dipanggil `buat()`, sekali, pada
    // saat paketnya belum ada. Ujian seperti itu tetap kosong selamanya,
    // walau paketnya kemudian dipasang, dan kegagalannya baru muncul di
    // depan siswa.
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    expect($data['ujian']->question_package_id)->toBeNull()
        ->and(ExamQuestion::query()->where('exam_id', $data['ujian']->id)->count())->toBe(0);

    $sekolah = School::find($this->event->school_id);
    $guru = User::factory()->guru()->untukSekolah($sekolah)->create();

    $paket = QuestionPackage::factory()->oleh($guru, $this->mapel)->siap()->create([
        'name' => 'Paket '.$this->mapel->name,
    ]);

    foreach (range(1, 3) as $i) {
        QuestionPackageItem::factory()->create([
            'school_id' => $this->event->school_id,
            'question_package_id' => $paket->id,
            'question_id' => Question::factory()->oleh($guru, $this->mapel)->create()->id,
            'position' => $i,
        ]);
    }

    $baris = $this->event->subjects()->where('subject_id', $this->mapel->id)->firstOrFail();

    // Dipasang SESUDAH ujian dan sesinya ada.
    app(PemasanganPaket::class)->pasang((int) $baris->id, $paket->id, $this->admin->id);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}/sesi/{$data['sesi']->id}/siapkan")
        ->assertSessionHasNoErrors();

    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Siap)
        ->and($data['ujian']->refresh()->question_package_id)->toBe($paket->id)
        ->and(ExamQuestion::query()->where('exam_id', $data['ujian']->id)->count())->toBe(3);
});

it('menolak menyiapkan sesi ketika paket mapelnya belum berisi butir soal', function () {
    // Paket kosong adalah ujian yang bisa dijadwalkan, ditempati 300 siswa,
    // dan baru ketahuan kosong saat tokennya dibuka. `PembekuPaket` sudah
    // menolaknya dengan ValidationException sejak Fase 4 - yang belum ada
    // sampai 07 Nov 2026 adalah jalur yang MEMANGGILNYA sesudah ujian lahir,
    // sehingga penolakan itu tidak pernah sampai ke Admin yang menyiapkan
    // sesinya.
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    $sekolah = School::find($this->event->school_id);
    $guru = User::factory()->guru()->untukSekolah($sekolah)->create();

    $kosong = QuestionPackage::factory()->oleh($guru, $this->mapel)->siap()->create([
        'name' => 'Paket Kosong',
    ]);

    $baris = $this->event->subjects()->where('subject_id', $this->mapel->id)->firstOrFail();

    app(PemasanganPaket::class)->pasang((int) $baris->id, $kosong->id, $this->admin->id);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}/sesi/{$data['sesi']->id}/siapkan")
        ->assertSessionHasErrors('question_package_id');

    // Dan sesinya TETAP Draft. Penolakannya terjadi sebelum statusnya
    // berpindah - sesi yang berstatus Siap dengan snapshot kosong adalah sesi
    // yang dilaporkan siap dijalankan padahal tidak.
    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Draft)
        ->and(ExamQuestion::query()->where('exam_id', $data['ujian']->id)->count())->toBe(0);
});

/* --------------------------------------------------------- Penempatan §9.5 */

it('menolak menyebar peserta ketika kursinya kurang', function () {
    $kecil = $this->ruang->first();
    $kecil->forceFill(['capacity_override' => 1])->save();

    $ujian = app(ExamRegistrar::class)->buat($this->event, [
        'subject_id' => $this->mapel->id,
        'title' => 'Kursi Kurang',
        'duration_minutes' => 60,
        'class_id' => [],
    ], $this->admin->id);

    $sesi = app(SesiService::class)->buat($ujian, [
        'access_opens_at' => '2026-12-01 07:30:00',
        'access_closes_at' => '2026-12-01 09:00:00',
        'hard_end_at' => '2026-12-01 09:30:00',
        'event_room_id' => [$kecil->id],
    ]);

    app(PenempatanService::class)->tarikPeserta($ujian);

    expect(fn () => app(PenempatanService::class)->sebar($ujian, $sesi))
        ->toThrow(DomainException::class);

    // Penolakan terjadi SEBELUM satu baris pun ditulis, sehingga keadaan
    // setengah jadi tidak pernah ada - tidak ada siswa yang punya ruang
    // sementara sisanya menggantung.
    expect(ExamParticipant::query()->whereNotNull('exam_session_room_id')->count())->toBe(0);
});

/* ----------------------------------------------- Gerbang kesiapan §6.5 */

it('menolak menandai event siap selama masih ada benturan ruang', function () {
    // Fase 3 hanya memeriksa kelengkapan; Fase 5 memeriksa jadwal yang
    // SEBENARNYA. Sebuah event dengan dua sesi yang bertumpang di ruang yang
    // sama BUKAN event yang siap, betapa pun lengkap daftar kelas dan mapelnya.
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    $sesi2 = app(SesiService::class)->buat($data['ujian'], [
        'session_number' => 2,
        'access_opens_at' => '2026-12-01 08:00:00',
        'access_closes_at' => '2026-12-01 09:30:00',
        'hard_end_at' => '2026-12-01 10:00:00',
        'event_room_id' => $this->ruang->pluck('id')->all(),
    ]);

    app(PenempatanService::class)->sebar($data['ujian'], $sesi2);

    foreach ($sesi2->rooms()->orderBy('id')->get() as $i => $r) {
        app(PengawasService::class)->tugaskan(
            $r,
            User::factory()->pengawas()->untukSekolah($this->sekolah)->create(),
            $this->admin->id,
        );
    }

    $data['sesi']->forceFill([
        'access_opens_at' => now()->subMinutes(30),
        'access_closes_at' => now()->addHour(),
        'hard_end_at' => now()->addHours(2),
    ])->save();

    $sesi2->forceFill([
        'access_opens_at' => now()->addMinutes(15),
        'access_closes_at' => now()->addHours(2),
        'hard_end_at' => now()->addHours(3),
    ])->save();

    ExamToken::query()
        ->whereIn('exam_session_id', [$data['sesi']->id, $sesi2->id])
        ->update(['valid_from' => now()->subMinutes(30), 'valid_until' => now()->addHours(3)]);

    foreach ([$data['sesi'], $sesi2] as $s) {
        $s->forceFill(['status' => StatusSesi::Siap])->save();
    }

    $status = app(EventStatusService::class);
    $status->pindah($this->event, EventStatus::Preparation);

    $pesan = null;

    try {
        $status->pindah($this->event->refresh(), EventStatus::Ready);
    } catch (DomainException $e) {
        $pesan = $e->getMessage();
    }

    expect($pesan)->not->toBeNull()
        ->and($this->event->refresh()->status)->toBe(EventStatus::Preparation);
});

/* ----------------------------------------------------------- Halaman */

it('merender ketiga laman ujian dengan datanya', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    masukSebagai($this, $this->admin)->get("/admin/events/{$this->event->id}/jadwal")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Jadwal')
            ->has('baris', 1)
            ->has('validasi', 4)
            ->where('angka.ujian', 1)
            ->where('angka.sesi', 1)
        );

    masukSebagai($this, $this->admin)->get("/admin/events/{$this->event->id}/pengawas")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Pengawas')
            ->has('baris', 2)
            ->where('angka.tanpa_pengawas', 0)
            ->where('angka.ditugaskan', 2)
        );

    masukSebagai($this, $this->admin)->get("/admin/events/{$this->event->id}/token")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Token')
            ->has('baris', 1)
            ->where('angka.bertoken', 1)
            ->where('angka.dibuka', 0)
        );
});

it('mengirim nada chip token dari server, bukan dari nadaStatus()', function () {
    jadwalkan($this->event, $this->mapel, $this->admin);

    // Token sesi Desember dibaca hari ini: belum masuk jendelanya, sehingga
    // statusnya `draft`. nadaStatus() di ui.tsx kebetulan menjatuhkan `draft`
    // ke mute - benar secara kebetulan - tetapi `dibuka` juga jatuh ke mute,
    // dan itu salah. Karena itu nadanya datang dari StatusToken::nada().
    masukSebagai($this, $this->admin)->get("/admin/events/{$this->event->id}/token")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('baris.0.status', StatusToken::Draft->value)
            ->where('baris.0.status_nada', StatusToken::Draft->nada())
        );
});

it('menghitung butir kesiapan ujian secara nyata', function () {
    $admin = $this->admin;
    $sekolah = $this->sekolah;

    // Tanpa jadwal sama sekali: keempat butir ujian gagal.
    $laporan = app(EventReadiness::class)->periksa($this->event);

    $ujianButir = collect($laporan['butir'])->keyBy('kunci');

    expect($ujianButir['jadwal']['keadaan'])->toBe('belum')
        ->and($ujianButir['pengawas']['keadaan'])->toBe('belum')
        ->and($ujianButir['token']['keadaan'])->toBe('belum')
        ->and($ujianButir['kapasitas']['keadaan'])->toBe('belum')

        // Paket soal MENOLAK sejak Fase 4: bank soal sudah ada, sehingga
        // butir ini pindah dari 'menunggu' ke 'belum' - satu baris, persis
        // seperti yang dijanjikan docblock EventReadiness. Mapelnya sudah
        // ditetapkan tetapi belum dipasangi paket, jadi jawabannya 'belum'
        // dan pesannya menyebut mapel mana yang menunggu.
        ->and($ujianButir['paket']['keadaan'])->toBe('belum')
        ->and($ujianButir['paket']['keterangan'])->toContain('Belum ada paket soal untuk:');

    $data = jadwalkan($this->event, $this->mapel, $admin);

    $data['sesi']->forceFill([
        'access_opens_at' => now()->subMinutes(30),
        'access_closes_at' => now()->addHour(),
        'hard_end_at' => now()->addHours(2),
        'status' => StatusSesi::Siap,
    ])->save();

    ExamToken::query()
        ->where('exam_session_id', $data['sesi']->id)
        ->update(['valid_from' => now()->subMinutes(30), 'valid_until' => now()->addHours(2)]);

    // Dipasang lewat service yang sama dengan yang dipakai Admin di plate 15,
    // bukan dengan menulis kolomnya: menulis langsung melewati penulisan
    // riwayat, dan gate yang hijau karena kolom yang dikarang test bukan gate
    // yang hijau di produksi.
    $sekolah = School::find($this->event->school_id);
    $guruPenulis = User::factory()->guru()->untukSekolah($sekolah)->create();

    $paket = QuestionPackage::factory()->oleh($guruPenulis, $this->mapel)->siap()->create([
        'name' => 'Paket '.$this->mapel->name,
    ]);

    QuestionPackageItem::factory()->create([
        'school_id' => $this->event->school_id,
        'question_package_id' => $paket->id,
        'question_id' => Question::factory()->oleh($guruPenulis, $this->mapel)->create()->id,
    ]);

    app(PemasanganPaket::class)->pasang(
        (int) $this->event->subjects()->first()->id,
        (int) $paket->id,
        (int) $admin->id,
    );

    $laporan = app(EventReadiness::class)->periksa($this->event->refresh());
    $ujianButir = collect($laporan['butir'])->keyBy('kunci');

    // Kelimanya ok, dan nol butir yang menunggu. Sejak Fase 4 tidak ada lagi
    // butir kesiapan yang menggantung pada komponen yang belum dibangun.
    expect($ujianButir['jadwal']['keadaan'])->toBe('ok')
        ->and($ujianButir['pengawas']['keadaan'])->toBe('ok')
        ->and($ujianButir['token']['keadaan'])->toBe('ok')
        ->and($ujianButir['kapasitas']['keadaan'])->toBe('ok')
        ->and($ujianButir['paket']['keadaan'])->toBe('ok')
        ->and($laporan['menunggu'])->toBe(0)
        ->and($laporan['gagal'])->toBe(0);
});

/* ------------------------------------------- Ubah ujian (panel plate 16) */

it('mengubah ujian dari laman jadwal', function () {
    // Ujian lahir di laman ini, jadi salah ketik durasinya harus bisa
    // ditambal di laman ini juga. Tanpa jalur ubah, satu-satunya jalan
    // adalah menghapus ujiannya - dan menghapusnya berarti kehilangan
    // sesinya, penempatannya, dan tokennya.
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    $kelas = SchoolClass::query()
        ->where('id', $this->event->classes()->pluck('class_id'))
        ->first();

    masukSebagai($this, $this->admin)->put(
        "/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}",
        [
            'subject_id' => $this->mapel->id,
            'title' => 'UAS Matematika Revisi',
            'exam_type' => 'Try out',
            'instructions' => 'Baca soal dengan teliti.',
            'duration_minutes' => 120,
            'max_attempts' => 3,
            'class_id' => [$kelas->id],
            'shuffle_questions' => true,
            'allow_back_navigation' => false,
        ],
    )->assertRedirect();

    $ujian = $data['ujian']->refresh();

    expect($ujian->title)->toBe('UAS Matematika Revisi')
        ->and($ujian->exam_type)->toBe('Try out')
        ->and($ujian->instructions)->toBe('Baca soal dengan teliti.')
        ->and($ujian->duration_minutes)->toBe(120)
        ->and($ujian->max_attempts)->toBe(3)
        ->and($ujian->shuffle_questions)->toBeTrue()
        ->and($ujian->allow_back_navigation)->toBeFalse()
        ->and($ujian->classes()->pluck('class_id')->all())->toBe([$kelas->id]);
});

it('mengirim nilai ujian untuk panel ubah', function () {
    // Panel ubah memuat seluruh nilainya dari props. Tanpa `mapel_id`,
    // `jenis`, dan `kelas_id`, formnya terbuka dengan mapel pertama di
    // daftar dan centang kelas yang KOSONG - dan menyimpan durasi saja akan
    // menghapus seluruh kelas sasaran ujiannya tanpa satu pun galat, karena
    // daftar kosong berarti "seluruh peserta event".
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    $kelas = SchoolClass::query()
        ->where('id', $this->event->classes()->pluck('class_id'))
        ->first();

    app(ExamRegistrar::class)->perbarui($data['ujian'], [
        'exam_type' => 'Try out',
        'instructions' => 'Kerjakan sendiri.',
        'max_attempts' => 2,
        'shuffle_questions' => true,
        'allow_back_navigation' => false,
        'class_id' => [$kelas->id],
    ]);

    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$this->event->id}/jadwal")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Jadwal')
            ->where('ujian.0.mapel_id', $this->mapel->id)
            ->where('ujian.0.jenis', 'Try out')
            ->where('ujian.0.instruksi', 'Kerjakan sendiri.')
            ->where('ujian.0.percobaan', 2)
            ->where('ujian.0.acak_soal', true)
            ->where('ujian.0.boleh_kembali', false)
            ->where('ujian.0.kelas_id', [$kelas->id])
            ->where('ujian.0.ada_paket', false)
        );
});

it('menolak mapel yang tidak ditetapkan di event ini saat ujian diubah', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    // Mapel sekolah yang sama, tetapi tidak ditetapkan di event ini. Scope
    // tenant tidak menutupnya - yang menutupnya pemeriksaan di controller,
    // dan ujian yang mapelnya di luar event tidak muncul di laporan mana pun.
    $asing = Subject::factory()->create(['school_id' => $this->event->school_id]);

    masukSebagai($this, $this->admin)->put(
        "/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}",
        [
            'subject_id' => $asing->id,
            'title' => 'UAS',
            'duration_minutes' => 60,
        ],
    )->assertSessionHasErrors('subject_id');

    expect($data['ujian']->refresh()->subject_id)->toBe($this->mapel->id);
});

it('mengunci mapel ujian yang soalnya sudah dibekukan', function () {
    // Snapshot soal berasal dari mapel yang lama. Ujian berlabel Fisika yang
    // isinya soal Matematika tidak terlihat di layar mana pun - ia baru
    // ketahuan saat siswa membuka soal pertama di ruang ujian.
    $sekolah = School::find($this->event->school_id);
    $guru = User::factory()->guru()->untukSekolah($sekolah)->create();

    $paket = QuestionPackage::factory()->oleh($guru, $this->mapel)->siap()->create([
        'name' => 'Paket '.$this->mapel->name,
    ]);

    QuestionPackageItem::factory()->create([
        'school_id' => $this->event->school_id,
        'question_package_id' => $paket->id,
        'question_id' => Question::factory()->oleh($guru, $this->mapel)->create()->id,
    ]);

    $ujian = app(ExamRegistrar::class)->buat($this->event, [
        'subject_id' => $this->mapel->id,
        'title' => 'UAS '.$this->mapel->name,
        'duration_minutes' => 60,
        'question_package_id' => $paket->id,
        'class_id' => [],
    ], $this->admin->id);

    expect($ujian->question_package_id)->not->toBeNull();

    $lain = Subject::factory()->create(['school_id' => $sekolah->id]);

    EventSubject::create([
        'school_id' => $sekolah->id,
        'event_id' => $this->event->id,
        'subject_id' => $lain->id,
    ]);

    masukSebagai($this, $this->admin)->put(
        "/admin/events/{$this->event->id}/ujian/{$ujian->id}",
        [
            'subject_id' => $lain->id,
            'title' => 'UAS',
            'duration_minutes' => 60,
        ],
    )->assertSessionHasErrors('subject_id');

    expect($ujian->refresh()->subject_id)->toBe($this->mapel->id);

    // Yang lain tetap bisa disimpan: yang dikunci mapelnya saja, bukan
    // ujiannya. Judul dan durasi masih perlu bisa diperbaiki.
    masukSebagai($this, $this->admin)->put(
        "/admin/events/{$this->event->id}/ujian/{$ujian->id}",
        [
            'subject_id' => $this->mapel->id,
            'title' => 'UAS Matematika (revisi)',
            'duration_minutes' => 100,
        ],
    )->assertRedirect()->assertSessionHasNoErrors();

    expect($ujian->refresh()->title)->toBe('UAS Matematika (revisi)')
        ->and($ujian->duration_minutes)->toBe(100)
        ->and($ujian->question_package_id)->toBe($paket->id);
});

it('menolak Guru mengubah ujian', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);
    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create();

    masukSebagai($this, $guru)->put(
        "/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}",
        [
            'subject_id' => $this->mapel->id,
            'title' => 'Dirusak',
            'duration_minutes' => 60,
        ],
    )->assertForbidden();

    expect($data['ujian']->refresh()->title)->not->toBe('Dirusak');
});

/* ------------------------------------------- Ubah sesi (panel plate 16) */

it('mengubah jendela waktu dan ruang sebuah sesi', function () {
    // Sesi punya tiga waktu yang berbeda-beda artinya (ExamSession), dan
    // panel "Ubah" menyunting ketiganya sekaligus. Kalau salah satu tidak
    // ikut tersimpan, jadwalnya berubah diam-diam tanpa satu pun galat.
    $data = jadwalkan($this->event, $this->mapel, $this->admin);
    $ruangPertama = $data['ruang']->first();

    masukSebagai($this, $this->admin)->put(
        "/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}/sesi/{$data['sesi']->id}",
        [
            'access_opens_at' => '2026-12-02 08:00',
            'access_closes_at' => '2026-12-02 09:30',
            'hard_end_at' => '2026-12-02 10:00',
            'event_room_id' => [$ruangPertama->event_room_id],
        ],
    )->assertRedirect();

    $sesi = $data['sesi']->refresh();

    expect($sesi->access_opens_at->format('Y-m-d H:i'))->toBe('2026-12-02 08:00')
        ->and($sesi->access_closes_at->format('Y-m-d H:i'))->toBe('2026-12-02 09:30')
        ->and($sesi->hard_end_at->format('Y-m-d H:i'))->toBe('2026-12-02 10:00')
        ->and($sesi->rooms()->pluck('event_room_id')->all())->toBe([$ruangPertama->event_room_id]);
});

it('mengirim nilai mentah jendela sesi untuk panel ubah', function () {
    // Panel ubah memuat ulang jendelanya dari props. Tanpa nilai `*_iso`,
    // formnya terbuka KOSONG pada sesi yang sudah dijadwalkan - dan form
    // kosong terbaca seperti "jadwalnya hilang". Bentuknya `Y-m-d\TH:i`,
    // bentuk yang diminta `datetime-local`.
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$this->event->id}/jadwal")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Event/Jadwal')
            ->where('baris.0.buka_iso', '2026-12-01T07:30')
            ->where('baris.0.tutup_iso', '2026-12-01T09:00')
            ->where('baris.0.akhir_iso', '2026-12-01T09:30')
            ->has('baris.0.ruang_id')
        );
});

it('menolak jendela sesi yang urutannya terbalik', function () {
    // Urutannya diperiksa SesiService, bukan form. Panel ubah mengirim tiga
    // waktu apa adanya, dan jendela yang terbalik harus ditolak dengan pesan
    // di fieldnya - bukan tersimpan lalu baru ketahuan saat sesinya dibuka.
    $data = jadwalkan($this->event, $this->mapel, $this->admin);

    masukSebagai($this, $this->admin)->put(
        "/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}/sesi/{$data['sesi']->id}",
        [
            'access_opens_at' => '2026-12-02 10:00',
            'access_closes_at' => '2026-12-02 09:00',
            'hard_end_at' => '2026-12-02 09:30',
        ],
    )->assertSessionHasErrors('access_closes_at');

    expect($data['sesi']->refresh()->access_opens_at->format('Y-m-d H:i'))->toBe('2026-12-01 07:30');
});

it('menolak sesi milik ujian lain saat diubah', function () {
    // Sama dengan jalur `mulai`/`tutup`: yang menangkapnya adalah
    // pastikanSesiMilikUjian, bukan scope tenant - sekolahnya sama.
    $data = jadwalkan($this->event, $this->mapel, $this->admin);
    $lain = jadwalkan($this->event, $this->mapel, $this->admin);

    masukSebagai($this, $this->admin)->put(
        "/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}/sesi/{$lain['sesi']->id}",
        [
            'access_opens_at' => '2026-12-02 08:00',
            'access_closes_at' => '2026-12-02 09:00',
            'hard_end_at' => '2026-12-02 09:30',
        ],
    )->assertNotFound();
});

it('menolak Guru mengubah sesi', function () {
    $data = jadwalkan($this->event, $this->mapel, $this->admin);
    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create();

    masukSebagai($this, $guru)->put(
        "/admin/events/{$this->event->id}/ujian/{$data['ujian']->id}/sesi/{$data['sesi']->id}",
        [
            'access_opens_at' => '2026-12-02 08:00',
            'access_closes_at' => '2026-12-02 09:00',
            'hard_end_at' => '2026-12-02 09:30',
        ],
    )->assertForbidden();
});

/* ---------------------------------------- Pengawas 1 atau 2 per ruang §9.6 */

/**
 * Satu sesi tanpa penugasan, plus sejumlah panitia berjabatan pengawas.
 *
 * Dipisahkan dari `jadwalkan()` karena test di bawah justru menguji APA YANG
 * DILAKUKAN penugasan otomatis terhadap ruang yang masih kosong - memakai
 * helper yang sudah menugaskan satu orang tiap ruang akan menyembunyikan
 * seluruh perilaku yang ingin dibuktikan.
 *
 * @return array{sesi: ExamSession, ruang: Collection<int, ExamSessionRoom>, calon: array<int, User>}
 */
function siapkanCalon(SchoolEvent $event, Subject $mapel, User $admin, int $jumlahCalon): array
{
    $ujian = app(ExamRegistrar::class)->buat($event, [
        'subject_id' => $mapel->id,
        'title' => 'Otomatis '.$jumlahCalon,
        'duration_minutes' => 60,
        'class_id' => [],
    ], $admin->id);

    $sesi = app(SesiService::class)->buat($ujian, [
        'access_opens_at' => '2026-12-01 07:30:00',
        'access_closes_at' => '2026-12-01 09:00:00',
        'hard_end_at' => '2026-12-01 09:30:00',
        'event_room_id' => $event->rooms()->pluck('id')->all(),
    ]);

    $calon = [];

    for ($i = 0; $i < $jumlahCalon; $i++) {
        $orang = User::factory()->pengawas()->untukSekolah(School::find($event->school_id))->create();

        EventCommitteeMember::create([
            'school_id' => $event->school_id,
            'event_id' => $event->id,
            'user_id' => $orang->id,
            'name' => $orang->name,
            'position' => 'pengawas',
            'scope' => 'event',
            'status' => 'aktif',
        ]);

        $calon[] = $orang;
    }

    return ['sesi' => $sesi, 'ruang' => $sesi->rooms()->orderBy('id')->get(), 'calon' => $calon];
}

it('membagi satu pengawas per ruang lebih dulu ketika calonnya kurang', function () {
    // Dua ruang, dua calon. Versi yang mengisi tiap ruang sampai DUA orang
    // sebelum pindah akan menaruh keduanya di R-01 dan meninggalkan R-02
    // KOSONG - dan ruang tanpa pengawas adalah ruang yang tidak bisa dibuka.
    // Tombol yang gunanya menolong justru membuat separuh ujian tidak jalan.
    $data = siapkanCalon($this->event, $this->mapel, $this->admin, 2);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$this->event->id}/pengawas/otomatis")
        ->assertRedirect();

    $jumlah = $data['ruang']->map(fn ($r) => $r->supervisors()->count());

    expect($jumlah->all())->toBe([1, 1])
        ->and($jumlah->min())->toBe(1);
});

it('menambah pengawas kedua setelah setiap ruang punya satu', function () {
    // Empat calon, dua ruang: putaran kedua baru berjalan setelah putaran
    // pertama selesai - jadi dua orang di tiap ruang, bukan tiga di R-01.
    $data = siapkanCalon($this->event, $this->mapel, $this->admin, 4);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$this->event->id}/pengawas/otomatis")
        ->assertRedirect();

    expect($data['ruang']->map(fn ($r) => $r->supervisors()->count())->all())->toBe([2, 2]);
});

it('membuka sesi yang setiap ruangnya baru punya satu pengawas', function () {
    // PRD §9.6: "satu ruang dapat memiliki beberapa Pengawas". Satu orang
    // sudah sah, jadi sesinya HARUS bisa dibuka. Kalau gerbang kesiapan
    // memakai angka saran (dua), sekolah dengan 24 ruang dan 26 pengawas tidak
    // akan pernah bisa memulai ujiannya - dan tidak ada satu pesan pun yang
    // menjelaskan bahwa yang salah adalah angkanya, bukan susunannya.
    $data = siapkanCalon($this->event, $this->mapel, $this->admin, 2);

    foreach ($data['ruang'] as $i => $r) {
        app(PengawasService::class)->tugaskan($r, $data['calon'][$i], $this->admin->id);
    }

    $data['sesi']->forceFill(['status' => StatusSesi::Siap])->save();

    app(SesiService::class)->mulai($data['sesi']);

    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Berjalan);
});

it('mengirim saran sebagai saran, bukan sebagai perhatian', function () {
    // Nama propnya ikut menentukan perilakunya: apa pun yang bernama
    // "perhatian" akan dirender kuning di layar, dan layar yang menandai
    // keadaan yang sah sebagai peringatan membuat peringatan yang
    // benar-benar penting ikut diabaikan.
    jadwalkan($this->event, $this->mapel, $this->admin);

    masukSebagai($this, $this->admin)->get("/admin/events/{$this->event->id}/pengawas")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Pengawas')
            ->where('angka.minimal_per_ruang', 1)
            ->where('angka.saran_per_ruang', 2)
            ->has('saran', 2)
            ->missing('perhatian')
        );
});
