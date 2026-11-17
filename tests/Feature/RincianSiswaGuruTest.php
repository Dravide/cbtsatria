<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\StudentAnswer;
use App\Domain\Event\Models\EventClass;
use App\Domain\Exam\Models\Exam;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Tenancy\Models\School;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Rincian satu siswa di laman nilai Guru - soal, jawaban, dan linimasa.
 *
 * =====================================================================
 * Yang dijaga: linimasa tidak MENGARANG
 * =====================================================================
 * Mesin ujian tidak mencatat setiap klik. Yang bertanggal hanya
 * `started_at`, `student_answers.saved_at` (penyimpanan TERAKHIR per
 * butir), dan `submitted_at`. Test di sini memeriksa bahwa linimasa
 * dibangun dari ketiganya - dan bahwa satu butir yang disimpan dua kali
 * muncul SEKALI, dengan jam simpanan terbarunya.
 *
 * Test yang mengharapkan dua kejadian untuk satu butir akan lulus hanya
 * pada sistem yang menyimpan log per klik, dan sistem itu belum ada.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.rincian',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();
    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);
});

/** Guru yang mengampu mapel ujian. */
function guruRincian(Exam $ujian): User
{
    $kelas = EventClass::query()->where('event_id', $ujian->event_id)->firstOrFail();
    $event = $ujian->event()->firstOrFail();

    $guru = User::factory()->guru()->untukSekolah(School::find($ujian->school_id))->create([
        'username' => 'guru.rincian.'.$ujian->id,
    ]);

    TeachingAssignment::factory()->create([
        'school_id' => $ujian->school_id,
        'academic_year_id' => $event->academic_year_id,
        'teacher_id' => $guru->id,
        'subject_id' => $ujian->subject_id,
        'class_id' => $kelas->class_id,
    ]);

    return $guru;
}

/** Jawab seluruh butir satu attempt, lalu kumpulkan. */
function kerjakanRincian(TestCase $test, array $data, int $i): ExamAttempt
{
    $peserta = $data['peserta'][$i];

    verifikasiHadir($test, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($test, $data['siswa'][$i], $peserta, $data['token']);

    $butir = AttemptQuestion::query()
        ->where('exam_attempt_id', attemptBerjalan($peserta)->id)
        ->with('soal')
        ->orderBy('display_order')
        ->get();

    foreach ($butir as $satu) {
        $soal = $satu->soal;
        $kunci = $soal?->kunci_json ?? [];

        $isi = match ($soal?->jenis) {
            JenisSoal::PilihanGanda, JenisSoal::BenarSalah, JenisSoal::Media => ['opsi' => $kunci['opsi'] ?? []],
            JenisSoal::IsianSingkat => ['teks' => ($kunci['teks'] ?? [])[0] ?? ''],
            JenisSoal::Uraian => ['teks' => 'Jawaban uraian siswa.'],
            default => null,
        };

        if ($isi === null) {
            continue;
        }

        masukUji($test, $data['siswa'][$i])
            ->postJson("/siswa/ujian/{$peserta->id}/butir/{$satu->id}", ['isi' => $isi])
            ->assertOk();
    }

    masukUji($test, $data['siswa'][$i])
        ->post("/siswa/ujian/{$peserta->id}/selesai")
        ->assertRedirect();

    return attemptBerjalan($peserta);
}

it('menampilkan soal, jawaban, dan kunci untuk Guru', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruRincian($data['ujian']);

    kerjakanRincian($this, $data, 0);

    $peserta = $data['peserta'][0];

    masukUji($this, $guru)
        ->get("/guru/nilai/{$data['ujian']->id}/siswa/{$peserta->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guru/Nilai/Siswa')
            ->where('siswa.peserta_id', $peserta->id)
            ->where('siswa.nama', $data['siswa'][0]->name)
            ->has('butir.0')
            ->where('butir', fn ($b) => count($b) > 0)
            ->where('butir.0.nomor', 1)
            // Guru MELIHAT kunci - laman ini konsol penilaian, dan
            // menyembunyikan kunci dari orang yang menilai jawaban tidak
            // masuk akal. Aturan yang sama sudah berlaku di laman koreksi.
            ->has('butir.0.kunci')
            ->has('pengerjaan.mulai_label')
            ->has('pengerjaan.dikumpulkan_label')
            ->has('jalur_ujian')
        );
});

it('membangun linimasa dari data BERTANGGAL, bukan mengarang per klik', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruRincian($data['ujian']);

    $attempt = kerjakanRincian($this, $data, 0);
    $peserta = $data['peserta'][0];

    $props = masukUji($this, $guru)
        ->get("/guru/nilai/{$data['ujian']->id}/siswa/{$peserta->id}")
        ->viewData('page')['props'];

    $linimasa = $props['linimasa'];

    expect($linimasa)->not->toBeEmpty();

    // Kejadian pertama adalah Mulai, yang terakhir Mengumpulkan.
    expect($linimasa[0]['jenis'])->toBe('mulai')
        ->and($linimasa[0]['detik'])->toBe(0)
        ->and(end($linimasa)['jenis'])->toBe('kumpul');

    // Jumlah kejadian 'jawab' sama dengan jumlah BUTIR yang punya baris
    // jawaban - BUKAN jumlah penyimpanan. Satu butir yang disimpan dua kali
    // tetap satu baris.
    $jumlahJawaban = collect($linimasa)->where('jenis', 'jawab')->count();
    $jumlahBaris = StudentAnswer::query()
        ->whereIn('attempt_question_id', AttemptQuestion::query()
            ->where('exam_attempt_id', $attempt->id)->select('id'))
        ->count();

    expect($jumlahJawaban)->toBe($jumlahBaris);
});

it('menampilkan SATU baris per butir walau jawabannya disimpan berkali-kali', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruRincian($data['ujian']);

    $attempt = kerjakanRincian($this, $data, 0);
    $peserta = $data['peserta'][0];

    $jawaban = StudentAnswer::query()
        ->whereIn('attempt_question_id', AttemptQuestion::query()
            ->where('exam_attempt_id', $attempt->id)->select('id'))
        ->orderBy('id')
        ->firstOrFail();

    $butir = $jawaban->butir;
    $siswa = $data['siswa'][0];

    // Simpan ulang butir yang sama dua kali - bentuk yang sama dengan anak
    // yang mengubah pilihannya. `saved_at` diganti, `version` naik.
    foreach ([1, 2] as $_) {
        masukUji($this, $siswa)
            ->postJson("/siswa/ujian/{$peserta->id}/butir/{$butir->id}", ['isi' => ['opsi' => ['a']]])
            ->assertOk();
    }

    $props = masukUji($this, $guru)
        ->get("/guru/nilai/{$data['ujian']->id}/siswa/{$peserta->id}")
        ->viewData('page')['props'];

    $baris = collect($props['linimasa'])
        ->where('jenis', 'jawab')
        ->where('nomor', $butir->display_order);

    // Satu, bukan tiga.
    expect($baris)->toHaveCount(1);

    // Dan jamnya adalah simpanan TERAKHIR, bukan yang pertama.
    expect($baris->first()['pada'])->toBe(
        StudentAnswer::query()->whereKey($jawaban->id)->firstOrFail()->saved_at->toIso8601String(),
    );
});

it('menolak membuka rincian peserta milik ujian lain, meski sekolahnya sama', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $lain = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $guru = guruRincian($data['ujian']);

    kerjakanRincian($this, $data, 0);

    // Peserta ujian LAIN yang dibuka lewat rute ujian ini. Binding implisit
    // menjamin barisnya ada di sekolah ini, bukan di ujian ini - tanpa
    // pemeriksaan tambahan, Guru bisa membaca jawaban siswa ujian lain
    // dengan menebak id-nya.
    masukUji($this, $guru)
        ->get("/guru/nilai/{$data['ujian']->id}/siswa/{$lain['peserta'][0]->id}")
        ->assertNotFound();
});

it('menjawab 404 untuk guru yang tidak mengampu ujian itu', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    kerjakanRincian($this, $data, 0);

    $penyusup = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'guru.penyusup.rincian',
    ]);

    masukUji($this, $penyusup)
        ->get("/guru/nilai/{$data['ujian']->id}/siswa/{$data['peserta'][0]->id}")
        ->assertNotFound();
});

it('menampilkan peserta yang belum mulai sebagai tanpa pengerjaan', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruRincian($data['ujian']);

    $peserta = $data['peserta'][0];

    masukUji($this, $guru)
        ->get("/guru/nilai/{$data['ujian']->id}/siswa/{$peserta->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guru/Nilai/Siswa')
            ->where('pengerjaan', null)
            ->where('hasil', null)
            ->has('butir', 0)
            ->has('linimasa', 0)
        );
});

it('membawa peserta_id di setiap baris nilai kelas, untuk tautan rincian', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruRincian($data['ujian']);

    kerjakanRincian($this, $data, 0);

    masukUji($this, $guru)
        ->get("/guru/nilai/{$data['ujian']->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guru/Nilai/Ujian')
            ->where('baris.data.0.peserta_id', fn ($id) => is_int($id) && $id > 0)
        );
});
