<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Models\RoomSignal;
use App\Domain\Attendance\Models\StudentAnswer;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Satria Inovasi']);
    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.inovasi',
    ]);
    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();
    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);
});

it('mencatat durasi per butir melalui denyut dan menerima paket darurat offline bertanda tangan HMAC', function () {
    $d = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $siswa = $d['siswa'][0];
    $peserta = $d['peserta'][0];
    $ruang = $d['ruang'][0];
    verifikasiHadir($this, $this->admin, $ruang, $peserta);

    masukSebagai($this, $siswa)
        ->post("/siswa/ujian/{$peserta->id}/gerbang", ['token' => $d['token']])
        ->assertRedirect();

    $attempt = ExamAttempt::query()->where('exam_participant_id', $peserta->id)->firstOrFail();
    $butir = AttemptQuestion::query()->where('exam_attempt_id', $attempt->id)->firstOrFail();

    // 1. Telemetri durasi per butir lewat denyut.
    $this->postJson("/siswa/ujian/{$peserta->id}/denyut", [
        'nomor' => 1,
        'durasi_butir' => [$butir->id => 35],
    ])->assertOk();

    expect($butir->refresh()->durasi_detik)->toBe(35);

    // 2. Paket darurat offline dengan HMAC sah diterima pengawas/admin.
    $secret = hash_hmac(
        'sha256',
        'qr-darurat|'.$attempt->id.'|'.$attempt->started_at?->toIso8601String(),
        (string) config('app.key'),
    );
    $payload = ['a' => $attempt->id, 'j' => [$butir->id => ['opsi' => ['B']]]];
    $sig = hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE), $secret);
    $paket = base64_encode(json_encode([...$payload, 's' => $sig]));

    masukSebagai($this, $this->admin)
        ->post("/pengawas/ruang/{$ruang->id}/qr-darurat", ['paket' => $paket])
        ->assertSessionHas('sukses');

    $jawaban = StudentAnswer::query()->where('attempt_question_id', $butir->id)->first();
    expect($jawaban?->answer_json)->toBe(['opsi' => ['B']])
        ->and($attempt->refresh()->status)->toBe(ExamAttempt::STATUS_DIKIRIM);

    // 3. Paket darurat terkompresi (Z1:deflate) juga diterima dengan validasi HMAC yang sama.
    $payloadZ = ['a' => $attempt->id, 'j' => [$butir->id => ['opsi' => ['C']]]];
    $sigZ = hash_hmac('sha256', json_encode($payloadZ, JSON_UNESCAPED_UNICODE), $secret);
    $paketZ = 'Z1:'.base64_encode(gzcompress(json_encode([...$payloadZ, 's' => $sigZ])));

    $this->post("/pengawas/ruang/{$ruang->id}/qr-darurat", ['paket' => $paketZ])
        ->assertSessionHas('sukses');

    expect($jawaban->refresh()->answer_json)->toBe(['opsi' => ['C']]);

    // 4. Paket dengan tanda tangan palsu ditolak.
    $palsu = base64_encode(json_encode([...$payload, 's' => 'tanda-tangan-palsu']));
    $this->post("/pengawas/ruang/{$ruang->id}/qr-darurat", ['paket' => $palsu])
        ->assertSessionHas('galat');
});

it('mengirim sinyal teknisi dari ruang pengawas dan menandainya selesai dari admin', function () {
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);
    $ruang = $d['ruang'][0];

    masukSebagai($this, $this->admin)
        ->post("/pengawas/ruang/{$ruang->id}/sinyal", [
            'jenis' => 'wifi_putus',
            'catatan' => 'Access point lab mati.',
        ])
        ->assertSessionHas('sukses');

    $sinyal = RoomSignal::query()->where('exam_session_room_id', $ruang->id)->firstOrFail();
    expect($sinyal->status)->toBe(RoomSignal::STATUS_AKTIF);

    $this->post("/admin/sinyal/{$sinyal->id}/selesai")
        ->assertSessionHas('sukses');

    expect($sinyal->refresh()->status)->toBe(RoomSignal::STATUS_DITANGANI);
});

it('merapikan dikte berita acara menjadi poin formal', function () {
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);
    $ruang = $d['ruang'][0];

    masukSebagai($this, $this->admin)
        ->postJson("/pengawas/ruang/{$ruang->id}/berita-acara/rapi-ai", [
            'teks' => 'ujian dimulai tepat waktu. satu siswa terlambat lima menit.',
        ])
        ->assertOk()
        ->assertJsonStructure(['catatan']);
});

it('mendeteksi radar anomali denah kursi untuk pelanggaran berdekatan', function () {
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);
    $ruang = $d['ruang'][0];
    $peserta = ExamParticipant::query()
        ->where('exam_session_room_id', $ruang->id)
        ->get();

    foreach ($peserta as $p) {
        $a = ExamAttempt::create([
            'school_id' => $this->sekolah->id,
            'exam_participant_id' => $p->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinutes(5),
            'deadline_at' => now()->addMinutes(55),
            'status' => ExamAttempt::STATUS_BERJALAN,
            'violation_count' => 1,
            'violation_worst_severity' => 2,
        ]);

        ExamViolation::create([
            'school_id' => $this->sekolah->id,
            'exam_attempt_id' => $a->id,
            'exam_participant_id' => $p->id,
            'violation_type' => 'pindah_tab',
            'severity' => 2,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    masukSebagai($this, $this->admin)
        ->get("/pengawas/ruang/{$ruang->id}/monitoring")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Pengawas/Monitoring')
            ->has('radar_anomali', 1)
            ->where('ruang.meja_per_baris', 4)
            ->where('ruang.kursi_per_meja', 2)
            ->has('kursi.0.nomor_soal')
        );
});

it('menyediakan klinik remedial mikro pasca-publikasi dan memeriksa jawaban latihan', function () {
    $d = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $siswa = $d['siswa'][0];
    $peserta = $d['peserta'][0];

    $d['event']->forceFill(['results_published_at' => now()])->save();

    $attempt = ExamAttempt::create([
        'school_id' => $this->sekolah->id,
        'exam_participant_id' => $peserta->id,
        'attempt_number' => 1,
        'started_at' => now()->subMinutes(30),
        'deadline_at' => now()->addMinutes(30),
        'submitted_at' => now()->subMinutes(5),
        'status' => ExamAttempt::STATUS_DIKIRIM,
    ]);

    $soal = ExamQuestion::query()->where('exam_id', $d['ujian']->id)->where('jenis', 'pilihan_ganda')->firstOrFail();
    $aq = AttemptQuestion::create([
        'school_id' => $this->sekolah->id,
        'exam_attempt_id' => $attempt->id,
        'exam_question_id' => $soal->id,
        'display_order' => 1,
    ]);

    StudentAnswer::create([
        'school_id' => $this->sekolah->id,
        'attempt_question_id' => $aq->id,
        'answer_json' => ['opsi' => ['Z']],
        'is_correct' => false,
        'score_awarded' => 0,
        'version' => 1,
        'saved_at' => now(),
    ]);

    masukSebagai($this, $siswa)
        ->get('/siswa/luang/remedial')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Siswa/Remedial')
            ->has('butir', 1)
        );

    $kunci = $soal->kunci_json['opsi'][0] ?? $soal->kunci_json['kunci'] ?? 'A';

    $this->postJson('/siswa/luang/remedial/cek', [
        'attempt_question_id' => $aq->id,
        'opsi' => $kunci,
    ])->assertOk()->assertJsonPath('benar', true);
});

it('membuat event simulasi gladi bersih terisolasi dari event sumber', function () {
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);
    $event = $d['event'];

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$event->id}/gladi-bersih")
        ->assertRedirect();

    $simulasi = SchoolEvent::query()->where('is_simulasi', true)->first();
    expect($simulasi)->not->toBeNull()
        ->and($simulasi->name)->toContain('[GLADI BERSIH]');
});

it('memperbarui status event otomatis menjadi berjalan saat masa mulai dan selesai saat melewati batas akhir', function () {
    $eventBerjalan = SchoolEvent::factory()->untukTahun($this->tahun)->create([
        'school_id' => $this->sekolah->id,
        'semester_id' => $this->semester->id,
        'status' => EventStatus::Draft,
        'start_at' => now()->subHour(),
        'end_at' => now()->addHours(2),
    ]);

    $eventSelesai = SchoolEvent::factory()->untukTahun($this->tahun)->create([
        'school_id' => $this->sekolah->id,
        'semester_id' => $this->semester->id,
        'status' => EventStatus::Ongoing,
        'start_at' => now()->subDays(2),
        'end_at' => now()->subHour(),
    ]);

    $eventBatal = SchoolEvent::factory()->untukTahun($this->tahun)->create([
        'school_id' => $this->sekolah->id,
        'semester_id' => $this->semester->id,
        'status' => EventStatus::Cancelled,
        'start_at' => now()->subHour(),
        'end_at' => now()->addHours(2),
    ]);

    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$eventBerjalan->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('event.status', 'berjalan')
        );

    $this->get("/admin/events/{$eventSelesai->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('event.status', 'selesai')
        );

    // Status batal tidak pernah disentuh otomasi.
    expect($eventBatal->refresh()->status)->toBe(EventStatus::Cancelled);

    // Command artisan juga berjalan sukses.
    $this->artisan('event:sinkron-status')->assertSuccessful();
});
