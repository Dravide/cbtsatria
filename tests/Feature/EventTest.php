<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\ClassEnrollment;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventClass;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\EventParticipantService;
use App\Domain\Event\Services\EventReadiness;
use App\Domain\Event\Services\EventRegistrar;
use App\Domain\Event\Services\EventStatusService;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamToken;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\PengawasService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Exam\Services\TokenSesi;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Question\Services\PemasanganPaket;
use App\Domain\Tenancy\Models\School;
use Inertia\Testing\AssertableInertia;

/**
 * Event Workspace Fase 3 - wireframe plate 10-15.
 *
 * Empat hal yang diperiksa di sini, dan setiap satunya menjaga satu aturan
 * yang kegagalannya diam-diam:
 *
 *   1. Isolasi tenant. Event sekolah lain tidak terlihat, tidak bisa dibuka,
 *      dan yang paling penting: URL-nya berakhir 404, bukan 403.
 *   2. Graf status. Transisi yang tidak ada di graf ditolak service, bukan
 *      ditolak di controller masing-masing.
 *   3. Gerbang kesiapan. Event yang belum lengkap TIDAK BISA menjadi siap -
 *      bahkan bila requestnya datang langsung, tanpa menyentuh tombol.
 *   4. Peserta manual. Menarik ulang daftar kelas tidak menghapus pengecualian
 *      yang dibuat Admin dengan niat.
 *
 * Yang keempat adalah yang paling mudah rusak tanpa terlihat: kegagalannya
 * baru muncul sebagai "peserta yang saya tambahkan tadi hilang" beberapa
 * minggu kemudian, dan tidak ada galat yang menunjuk ke sebabnya.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->lain = School::factory()->create();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.event',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
    ]);

    $this->event = SchoolEvent::factory()
        ->untukTahun($this->tahun)
        ->create(['school_id' => $this->sekolah->id]);
});

/** Siapkan event sampai lulus gerbang kesiapan. */
function lengkapi(SchoolEvent $event): SchoolEvent
{
    // Dua kelas, bukan satu. Satu kelas membuat "kelas dikeluarkan dari event"
    // tidak bisa diuji sama sekali: melepas kelas terakhir berarti tidak ada
    // lagi yang bisa ditarik, dan pemeriksaannya berhenti di galat yang salah.
    //
    // Namanya ditulis eksplisit karena unique(school_id, academic_year_id,
    // name) menolak dua kelas bernama sama - dan factory-nya memang
    // membangkitkan nama dari daftar pendek yang berulang.
    $kelas = collect(['X-1', 'X-2'])->map(fn (string $nama) => SchoolClass::factory()->create([
        'school_id' => $event->school_id,
        'academic_year_id' => $event->academic_year_id,
        'name' => $nama,
    ]));

    $mapel = Subject::factory()->create(['school_id' => $event->school_id]);
    $ruang = Room::factory()->create(['school_id' => $event->school_id, 'capacity' => 40]);

    foreach ($kelas as $k) {
        EventClass::create([
            'school_id' => $event->school_id,
            'event_id' => $event->id,
            'class_id' => $k->id,
        ]);
    }

    $barisMapel = EventSubject::create([
        'school_id' => $event->school_id,
        'event_id' => $event->id,
        'subject_id' => $mapel->id,
    ]);

    // Paket soal dipasang ke mapel event, bukan ke ujiannya - lihat docblock
    // EventReadiness::paketSoal() tentang mengapa tingkatnya di sana.
    //
    // Dipasang lewat SERVICE, bukan dengan menulis kolomnya langsung: menulis
    // langsung berarti test mengarang jawaban atas pertanyaan "paket mana yang
    // berlaku", dan jawabannya tidak lagi melewati pintu yang sama dengan yang
    // dilewati Admin - termasuk penulisan baris riwayatnya.
    //
    // Harus lewat service pula supaya paketnya berstatus Siap: paket yang
    // dipasang langsung ke kolom bisa berstatus Draft, dan gerbang kesiapan
    // tidak pernah melihat keadaan yang mungkin terjadi di produksi.
    $sekolahPemasang = School::find($event->school_id);

    $guruPenulis = User::factory()->guru()->untukSekolah($sekolahPemasang)->create();

    $paket = QuestionPackage::factory()->oleh($guruPenulis, $mapel)->siap()->create([
        'name' => 'Paket '.$mapel->name,
    ]);

    QuestionPackageItem::factory()->create([
        'school_id' => $event->school_id,
        'question_package_id' => $paket->id,
        'question_id' => Question::factory()->oleh($guruPenulis, $mapel)->create()->id,
    ]);

    // Admin yang memasang, bukan Guru: PemasanganPaket selalu dipanggil
    // controller yang sudah menjalankan EventPolicy::update.
    $adminPemasang = User::factory()->admin()->untukSekolah($sekolahPemasang)->create();

    app(PemasanganPaket::class)->pasang(
        (int) $barisMapel->id,
        (int) $paket->id,
        (int) $adminPemasang->id,
    );

    EventRoom::create([
        'school_id' => $event->school_id,
        'event_id' => $event->id,
        'room_id' => $ruang->id,
        'status' => 'aktif',
    ]);

    $sekolah = School::find($event->school_id);

    foreach ($kelas as $k) {
        $siswa = User::factory()->siswa()->untukSekolah($sekolah)->create();

        ClassEnrollment::create([
            'school_id' => $event->school_id,
            'class_id' => $k->id,
            'student_id' => $siswa->id,
            'academic_year_id' => $event->academic_year_id,
            'status' => 'aktif',
        ]);
    }

    // Peserta ditarik lewat service, bukan ditulis langsung ke tabel. Menulis
    // langsung berarti test mengarang jawaban atas pertanyaan "siapa yang
    // ikut", dan jawabannya tidak lagi melewati pemeriksaan yang sama dengan
    // yang dilewati Admin.
    app(EventParticipantService::class)->tarikDariKelas($event);

    EventCommitteeMember::create([
        'school_id' => $event->school_id,
        'event_id' => $event->id,
        'name' => 'Sri Handayani',
        'position' => 'pengawas',
        'scope' => 'event',
        'status' => 'aktif',
    ]);

    // Ujian, sesi, ruang-sesi, penugasan, dan token dibangun SEBELUM event
    // bisa mencapai Siap, dan itu perubahan yang disengaja pada Fase 5.
    // Gerbang kesiapan berhenti menunggu: butir jadwal memeriksa sesi yang
    // benar-benar punya ruang dan bebas konflik, butir pengawas memeriksa
    // penugasan ruang-sesi - bukan daftar panitia - dan butir token memeriksa
    // token yang masih dalam jendela berlakunya.
    //
    // Membangunnya lewat service, bukan menulis langsung ke tabel: menulis
    // langsung berarti test mengarang jawaban atas pertanyaan "apakah jadwal
    // ini bisa dijalankan", dan jawabannya tidak lagi melewati pemeriksaan
    // yang sama dengan yang dilewati Admin.
    $pengawas = User::factory()->untukSekolah($sekolah)->create([
        'role' => Role::Supervisor,
        'name' => 'Ani Mulyani',
    ]);

    $ujian = app(ExamRegistrar::class)->buat($event, [
        'subject_id' => $mapel->id,
        'title' => 'UAS '.$mapel->name,
        'exam_type' => 'Ujian akhir semester',
        'duration_minutes' => 60,
        'class_id' => $kelas->pluck('id')->all(),
    ], $event->created_by ?? User::factory()->admin()->untukSekolah($sekolah)->create()->id);

    $sesi = app(SesiService::class)->buat($ujian, [
        'access_opens_at' => '2026-12-01 07:30:00',
        'access_closes_at' => '2026-12-01 09:00:00',
        'hard_end_at' => '2026-12-01 09:30:00',
        'event_room_id' => [$event->rooms()->first()->id],
    ]);

    // Peserta ditarik ke ujian, lalu disebar ke ruang sesinya. Tanpa langkah
    // ini butir kapasitas gagal karena ada peserta yang belum punya ruang -
    // dan peserta yang belum punya ruang adalah siswa yang akan berdiri di
    // depan pintu tanpa namanya ada di daftar pengawas.
    $penempatan = app(PenempatanService::class);
    $penempatan->tarikPeserta($ujian);
    $penempatan->sebar($ujian, $sesi);

    foreach ($sesi->rooms()->get() as $ruangSesi) {
        app(PengawasService::class)->tugaskan($ruangSesi, $pengawas);
    }

    app(TokenSesi::class)->terbitkan($sesi);

    // TokenSesi menetapkan jendela berlaku dari jendela SESI, sehingga token
    // yang terbit untuk sesi Desember terbaca "belum berlaku" hari ini - dan
    // butir token menolaknya. Jendelanya karena itu digeser ke sekarang,
    // bersama sesinya, supaya event yang "lengkap" memang bisa berjalan.
    $sesi->forceFill([
        'access_opens_at' => now()->subMinutes(30),
        'access_closes_at' => now()->addHour(),
        'hard_end_at' => now()->addHours(2),
    ])->save();

    ExamToken::query()
        ->where('exam_session_id', $sesi->id)
        ->update([
            'valid_from' => now()->subMinutes(30),
            'valid_until' => now()->addHours(2),
        ]);

    // Sesi disimpan sebagai Siap, bukan Draft: draft berarti jendelanya masih
    // boleh diubah, dan event yang menandai dirinya siap dengan sesi yang
    // masih bisa digeser adalah event yang jadwalnya belum final.
    $sesi->forceFill(['status' => StatusSesi::Siap])->save();

    // Periode wajib lengkap - butir identitas memeriksanya.
    $event->forceFill([
        'event_type' => 'Ujian akhir semester',
        'start_at' => '2026-12-01 07:30:00',
        'end_at' => '2026-12-12 12:00:00',
    ])->save();

    return $event->refresh();
}

/* --------------------------------------------------------- Isolasi tenant */

it('tidak menampilkan event sekolah lain di daftar', function () {
    $tahunLain = AcademicYear::factory()->untukSekolah($this->lain)->create();

    SchoolEvent::factory()->untukTahun($tahunLain)->create(['name' => 'UAS Sekolah Lain']);

    $this->actingAs($this->admin)
        ->get('/admin/events')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Index')
            ->has('baris.data', 1)
            ->where('baris.data.0.nama', $this->event->name)
        );
});

it('membalas 404 - bukan 403 - untuk event sekolah lain', function () {
    $tahunLain = AcademicYear::factory()->untukSekolah($this->lain)->create();

    $asing = SchoolEvent::factory()->untukTahun($tahunLain)->create();

    // 404 dan bukan 403 adalah bagian dari aturan, bukan selera: 403
    // membuktikan barisnya ada, dan itu sudah membocorkan satu bit tentang
    // sekolah lain.
    $this->actingAs($this->admin)->get("/admin/events/{$asing->id}")->assertNotFound();
    $this->actingAs($this->admin)->get("/admin/events/{$asing->id}/panitia")->assertNotFound();
    $this->actingAs($this->admin)->get("/admin/events/{$asing->id}/peserta")->assertNotFound();
    $this->actingAs($this->admin)->get("/admin/events/{$asing->id}/mapel")->assertNotFound();
});

it('menolak panitia milik event lain meski sekolahnya sama', function () {
    $lainDiSekolahSama = SchoolEvent::factory()->untukTahun($this->tahun)->create([
        'school_id' => $this->sekolah->id,
    ]);

    $panitia = EventCommitteeMember::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $lainDiSekolahSama->id,
        'name' => 'Budi Santoso',
        'position' => 'pengawas',
        'scope' => 'event',
        'status' => 'aktif',
    ]);

    // Binding implisit mencari di seluruh sekolah, sehingga id ini lolos
    // tanpa pemeriksaan event_id - dan panitia event B akan berubah lewat URL
    // event A. Scope tenant tidak menolongnya; sekolahnya memang sama.
    $this->actingAs($this->admin)
        ->put("/admin/events/{$this->event->id}/panitia/{$panitia->id}", [
            'name' => 'Diubah',
            'position' => 'ketua',
            'scope' => 'event',
            'status' => 'aktif',
        ])
        ->assertNotFound();

    expect($panitia->refresh()->name)->toBe('Budi Santoso');
});

/* -------------------------------------------------------------- Graf status */

it('menolak transisi yang tidak ada di graf', function () {
    $service = app(EventStatusService::class);
    $event = $this->event->refresh();

    // Draft tidak bisa langsung ke Berjalan - tanpa melewati Siap.
    expect($event->status)->toBe(EventStatus::Draft)
        ->and($event->status->bolehPindahKe(EventStatus::Ongoing))->toBeFalse();

    $service->pindah($event, EventStatus::Ongoing);
})->throws(DomainException::class);

it('menjadikan arsip dan batal sebagai status final', function () {
    $service = app(EventStatusService::class);

    $batal = $this->event->refresh();
    $service->pindah($batal, EventStatus::Cancelled);

    expect($batal->refresh()->status->final())->toBeTrue();

    // Tidak ada jalan keluar dari final - bahkan kembali ke draft.
    $service->pindah($batal->refresh(), EventStatus::Draft);
})->throws(DomainException::class);

it('menolak event belum lengkap menjadi siap', function () {
    $service = app(EventStatusService::class);
    $event = $this->event->refresh();

    // Draft → Persiapan dulu, karena Draft → Siap memang tidak ada di graf -
    // dan test ini bukan tentang itu.
    $service->pindah($event, EventStatus::Preparation);

    // Event masih kosong: tidak ada kelas, peserta, ruang, pengawas.
    $service->pindah($event->refresh(), EventStatus::Ready);
})->throws(DomainException::class, 'belum siap');

it('mengizinkan event lengkap menjadi siap', function () {
    $event = lengkapi($this->event);

    $service = app(EventStatusService::class);
    $service->pindah($event, EventStatus::Preparation);
    $service->pindah($event->refresh(), EventStatus::Ready);

    expect($event->refresh()->status)->toBe(EventStatus::Ready);
});

it('melaporkan kesiapan penuh tanpa satu butir pun yang menunggu', function () {
    $laporan = app(EventReadiness::class)->periksa(lengkapi($this->event));

    expect($laporan['lulus'])->toBeTrue()
        ->and($laporan['gagal'])->toBe(0)

        // NOL yang menunggu, dan itu perubahan yang disengaja pada Fase 4.
        // Fase 3 menunggu tiga - jadwal, paket soal, dan token; Fase 5
        // memeriksa jadwal dan token sungguhan, dan Fase 4 menutup yang
        // terakhir: paket soal kini dibaca dari `event_subjects` dan
        // dihitung butirnya.
        //
        // Keadaan "menunggu" itu sendiri TIDAK dihapus dari gerbang ini. Ia
        // tetap ada untuk butir berikutnya yang lahir sebelum komponennya -
        // dan menghapusnya berarti butir seperti itu kembali dilaporkan
        // sebagai kegagalan yang tidak bisa diperbaiki siapa pun.
        ->and($laporan['menunggu'])->toBe(0)
        ->and($laporan['siap'])->toBe($laporan['total']);

    $butir = collect($laporan['butir']);

    expect($laporan['butir'])->toHaveCount(11)
        ->and($butir->firstWhere('kunci', 'paket')['keadaan'])->toBe('ok')
        // Butir yang lulus tetap membawa angkanya: "ok" tanpa angka tidak
        // memberi tahu Admin apa pun tentang apa yang sudah ia kerjakan.
        ->and($butir->firstWhere('kunci', 'paket')['keterangan'])->toBe('1 butir');
});

it('menolak event yang mapelnya belum dipasangi paket', function () {
    $event = lengkapi($this->event);

    // Admin yang tercatat melepas, dan itu bukan hiasan: FK-nya
    // `restrictOnDelete` pada pasangan (school_id, assigned_by), sehingga
    // id pengguna yang tidak ada di sekolah itu ditolak basis data.
    $adminPemasang = User::factory()->admin()->untukSekolah($this->sekolah)->create();

    // Paketnya dilepas lagi, dan eventnya kembali gagal - inilah penegak
    // "ujian harus punya soal" yang selama Fase 3-5 hanya bisa melaporkan
    // "menunggu".
    app(PemasanganPaket::class)->pasang(
        (int) $event->subjects()->first()->id,
        null,
        (int) $adminPemasang->id,
    );

    $laporan = app(EventReadiness::class)->periksa($event->refresh());

    expect($laporan['lulus'])->toBeFalse()
        ->and($laporan['gagal'])->toBe(1);

    $paket = collect($laporan['butir'])->firstWhere('kunci', 'paket');

    expect($paket['keadaan'])->toBe('belum')
        // Pesannya menyebut MAPEL nya, bukan sekadar "belum ada paket":
        // Admin yang harus memilih paket perlu tahu untuk mapel mana.
        ->and($paket['keterangan'])->toContain('Belum ada paket soal untuk:')
        ->and($paket['perbaikan'])->toContain('/mapel');
});

it('membedakan paket yang terpasang tetapi belum berisi butir', function () {
    $event = lengkapi($this->event);

    $baris = $event->subjects()->first();

    // Butirnya dibuang SETELAH dipasang - satu-satunya cara paket kosong bisa
    // terpasang, karena `PemasanganPaketController` menolak Draft dan service
    // menolak paket kosong ditandai siap.
    QuestionPackageItem::query()
        ->where('question_package_id', $baris->question_package_id)
        ->delete();

    $paket = collect(app(EventReadiness::class)->periksa($event->refresh())['butir'])
        ->firstWhere('kunci', 'paket');

    // Pesan yang BERBEDA dari "belum ada paket": yang pertama menuntut Admin
    // memilih, yang kedua menuntut ia menghubungi Gurunya. Pesan yang
    // menyatukan keduanya membuat Admin mencari paket yang sudah ia pilih.
    expect($paket['keadaan'])->toBe('belum')
        ->and($paket['keterangan'])->toContain('belum berisi butir');
});

/* ------------------------------------------------------- Peserta: sumber */

it('menandai peserta kelas yang keluar tanpa menghapusnya', function () {
    $service = app(EventParticipantService::class);
    $event = lengkapi($this->event);

    expect($service->tarikDariKelas($event)['total'])->toBe(2);

    // Satu kelas keluar dari event - bukan seluruhnya, karena melepas
    // kelas terakhir berarti tidak ada lagi yang bisa ditarik dan
    // pemeriksaannya berhenti di galat yang salah.
    $keluar = $event->classes()->first();
    $keluar->delete();

    $kedua = $service->tarikDariKelas($event);

    // Pesertanya ditandai dikecualikan, bukan dihapus - barisnya adalah
    // riwayat, dan sekolah yang mengecualikan tujuh siswa karena ikut lomba
    // perlu melihat angkanya tujuh.
    expect($kedua)->toBe(['baru' => 0, 'keluar' => 1, 'total' => 1])
        ->and(EventParticipant::where('event_id', $event->id)->count())->toBe(2)
        ->and(EventParticipant::where('event_id', $event->id)
            ->where('status', EventParticipant::STATUS_DIKECUALIKAN)->count())->toBe(1);
});

it('tidak menyentuh peserta manual saat peserta kelas ditarik ulang', function () {
    $service = app(EventParticipantService::class);
    $event = lengkapi($this->event);

    $manual = User::factory()->siswa()->untukSekolah($this->sekolah)->create();
    StudentProfile::create([
        'school_id' => $this->sekolah->id,
        'user_id' => $manual->id,
        'nis' => '2024999',
    ]);

    $service->tambahManual($event, $manual->id);

    // Kelas keluar, lalu ditarik ulang. Peserta manual adalah pengecualian
    // yang dibuat Admin dengan niat - menghapusnya berarti Admin mengulang
    // pekerjaannya setiap kali daftar kelas berubah, tanpa tahu mengapa.
    $event->classes()->first()->delete();
    $service->tarikDariKelas($event);

    $tetap = EventParticipant::where('event_id', $event->id)
        ->where('student_id', $manual->id)
        ->first();

    expect($tetap)->not->toBeNull()
        ->and($tetap->status)->toBe(EventParticipant::STATUS_TERDAFTAR)
        ->and($tetap->source)->toBe(EventParticipant::SUMBER_MANUAL);
});

it('menolak menarik peserta bila belum ada kelas', function () {
    app(EventParticipantService::class)
        ->tarikDariKelas($this->event);
})->throws(DomainException::class);

/* -------------------------------------------------------------- Halaman */

it('membuka kelima laman event dengan prop yang benar', function () {
    $event = lengkapi($this->event);

    $this->actingAs($this->admin)
        ->get("/admin/events/{$event->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Show')
            ->where('event.kode', $event->code)
            ->has('kesiapan.butir', 11)
            ->has('langkah')
            ->where('angka.peserta', 2)
        );

    $this->actingAs($this->admin)
        ->get("/admin/events/{$event->id}/panitia")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Panitia')
            ->has('panitia', 1)
            ->has('ruang')
            ->where('jabatan', ['ketua', 'koordinator', 'pengawas', 'anggota'])
        );

    $this->actingAs($this->admin)
        ->get("/admin/events/{$event->id}/peserta")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Peserta')
            ->where('angka.kelas', 2)
            ->where('angka.peserta', 2)
        );

    $this->actingAs($this->admin)
        ->get("/admin/events/{$event->id}/mapel")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Mapel')
            // `lengkapi()` memasang paket supaya gerbang kesiapan lulus,
            // sehingga jawabannya di sini 'terpasang' - dibaca dari
            // `event_subjects.question_package_id`, bukan konstanta. Ketiga
            // keadaannya diuji tersendiri di PemasanganPaketTest.
            ->where('baris.0.paket_status', 'terpasang')
            ->where('baris.0.paket', 'Paket '.$event->subjects()->first()->subject->name)
            ->where('baris.0.butir', 1)
        );
});

it('membuka form buat dan ubah event', function () {
    $this->actingAs($this->admin)
        ->get('/admin/events/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Form')
            ->where('event', null)
            ->has('bawaan')
            ->has('tahun')
        );

    $this->actingAs($this->admin)
        ->get("/admin/events/{$this->event->id}/ubah")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Form')
            ->where('event.kode', $this->event->code)
            ->where('event.ambang_peringatkan', 5)
        );
});

it('membuat event lewat form, selalu sebagai draft', function () {
    $this->actingAs($this->admin)
        ->post('/admin/events', [
            'name' => 'UAS Susulan',
            'event_type' => 'Ujian akhir semester',
            'academic_year_id' => $this->tahun->id,
            'semester_id' => $this->semester->id,
        ])
        ->assertRedirect();

    $baru = SchoolEvent::where('name', 'UAS Susulan')->firstOrFail();

    // Draft, meski formnya tidak punya field status sama sekali.
    expect($baru->status)->toBe(EventStatus::Draft)
        ->and($baru->code)->toStartWith('EV-2026-')
        ->and($baru->violation_thresholds)->toHaveKeys([
            'peringatkan', 'tandai_pengawas', 'kunci_ujian',
        ]);
});

it('menolak ambang yang urutannya tidak menaik', function () {
    $this->actingAs($this->admin)
        ->post('/admin/events', [
            'name' => 'UAS Terbalik',
            'academic_year_id' => $this->tahun->id,
            'ambang_peringatkan' => 7,
            'ambang_tandai_pengawas' => 5,
            'ambang_kunci_ujian' => 3,
        ])
        ->assertSessionHasErrors('ambang_kunci_ujian');
});

it('tidak mengubah ambang saat event disimpan tanpa menyentuhnya', function () {
    $event = $this->event->refresh();
    $awal = $event->violation_thresholds;

    $this->actingAs($this->admin)->put("/admin/events/{$event->id}", [
        'name' => 'Nama Baru',
    ])->assertRedirect();

    // Ambang adalah nilai milik event sejak ia dibuat. Kalau setiap
    // penyimpanan menyalinnya ulang dari pengaturan sekolah, kalimat plate 23
    // - "event yang sudah dibuat menyimpan nilainya sendiri" - jadi bohong.
    expect($event->refresh()->violation_thresholds)->toBe($awal)
        ->and($event->name)->toBe('Nama Baru');
});

it('menerjemahkan kesiapan yang gagal menjadi pesan, bukan 500', function () {
    $this->actingAs($this->admin)
        ->from("/admin/events/{$this->event->id}")
        ->post("/admin/events/{$this->event->id}/status", ['status' => 'siap'])
        ->assertRedirect("/admin/events/{$this->event->id}")
        ->assertSessionHas('galat');

    expect($this->event->refresh()->status)->toBe(EventStatus::Draft);
});

it('membuat kode event berurutan, bukan dari jumlah baris', function () {
    $registrar = app(EventRegistrar::class);

    $pertama = $registrar->kodeBerikutnya($this->tahun);
    expect($pertama)->toBe('EV-2026-001');

    SchoolEvent::factory()->untukTahun($this->tahun)->create([
        'school_id' => $this->sekolah->id,
        'code' => 'EV-2026-007',
    ]);

    // Dari kode TERTINGGI: event yang dihapus menurunkan jumlah baris, dan
    // kode berikutnya akan menabrak kode yang masih dipakai.
    expect($registrar->kodeBerikutnya($this->tahun))->toBe('EV-2026-008');
});
