<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\StudentAnswer;
use App\Domain\Event\Models\EventClass;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Riwayat jawaban siswa - `GET /siswa/ujian/{peserta}/riwayat`.
 *
 * =====================================================================
 * Yang diuji berkas ini: TIGA PINTU, bukan tata letaknya
 * =====================================================================
 * Datanya sudah lama tersimpan - `attempt_questions`, `student_answers`,
 * `exam_questions` - dan laman yang membaca semuanya dengan benar tetap
 * salah kalau satu pintu terbuka. Berkas ini karena itu memeriksa:
 *
 *   1. Publikasi. Sebelum `results_published_at` terisi, 404.
 *   2. `show_objectives`. Saat dimatikan, `kunci` NULL di setiap butir -
 *      dan yang diperiksa adalah PAYLOAD, bukan laman: prop yang dikirim
 *      lalu disembunyikan React sudah bocor sejak dikirim.
 *   3. `show_explanations`. Terpisah dari `show_objectives`, karena plate 21
 *      punya dua centang dan satu centang bisa menyala tanpa yang lain.
 *
 * Rubrik uraian diperiksa sebagai KATA, bukan sebagai kunci: kolom yang tidak
 * dikirim tetapi isinya ikut lewat jalur lain adalah kebocoran yang paling
 * sulit terlihat.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.riwayat',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
    ]);
});

/**
 * Satu siswa mengerjakan ujian sampai dikumpulkan, lalu hasilnya diterbitkan.
 *
 * Langkahnya mengikuti produksi - presensi pengawas, gerbang, jawaban lewat
 * endpoint siswa, Kumpulkan - dan bukan `forceFill`. Test yang memalsukan
 * salah satu langkah akan membuktikan bahwa lamannya membaca KEADAAN YANG
 * TIDAK PERNAH TERJADI.
 *
 * @return array{0: ExamAttempt, 1: ExamParticipant}
 */
function riwayatSiapTerbit(TestCase $test, array $data, int $i = 0, string $jenisSalah = ''): array
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

        if ($soal === null) {
            continue;
        }

        $salah = $jenisSalah !== '' && $soal->jenis->value === $jenisSalah;

        $isi = $salah ? jawabanRiwayatSalah($soal) : jawabanRiwayatBenar($soal);

        if ($isi === null) {
            continue;
        }

        masukUji($test, $data['siswa'][$i])
            ->postJson("/siswa/ujian/{$peserta->id}/butir/{$satu->id}", ['isi' => $isi]);
    }

    masukUji($test, $data['siswa'][$i])
        ->post("/siswa/ujian/{$peserta->id}/selesai")
        ->assertRedirect();

    return [attemptBerjalan($peserta), $peserta];
}

/** Kunci snapshot, dalam bentuk yang pasti dinilai benar. */
function jawabanRiwayatBenar($soal): ?array
{
    $kunci = $soal->kunci_json ?? [];

    return match ($soal->jenis) {
        JenisSoal::PilihanGanda, JenisSoal::BenarSalah,
        JenisSoal::JawabanGanda, JenisSoal::Media => ['opsi' => $kunci['opsi'] ?? []],

        JenisSoal::IsianSingkat => ['teks' => ($kunci['teks'] ?? [])[0] ?? ''],
        JenisSoal::Angka => ['nilai' => $kunci['nilai'] ?? 0],
        JenisSoal::Menjodohkan => ['pasangan' => $kunci['pasangan'] ?? []],
        JenisSoal::Mengurutkan => ['urutan' => $kunci['urutan'] ?? []],

        JenisSoal::Uraian => ['teks' => 'Jawaban uraian siswa.'],

        default => null,
    };
}

/** Jawaban yang salah, untuk satu jenis yang bisa dipastikan salah. */
function jawabanRiwayatSalah($soal): ?array
{
    return match ($soal->jenis) {
        JenisSoal::PilihanGanda, JenisSoal::BenarSalah,
        JenisSoal::Media => ['opsi' => ['b']],

        JenisSoal::IsianSingkat => ['teks' => 'delapan'],

        default => null,
    };
}

/** Guru pengampu, supaya uraiannya bisa dikoreksi dan publikasi terbuka. */
function guruRiwayat(Exam $ujian): User
{
    $kelas = EventClass::query()->where('event_id', $ujian->event_id)->firstOrFail();
    $event = $ujian->event()->firstOrFail();

    $guru = User::factory()->guru()->untukSekolah(School::find($ujian->school_id))->create([
        'username' => 'guru.riwayat.'.$ujian->id,
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

/**
 * Koreksi seluruh uraian, lalu terbitkan hasil eventnya.
 *
 * Dua langkah dan bukan satu: publikasi DITOLAK selama masih ada uraian
 * belum dikoreksi - gerbang itu sendiri sudah diuji berkas Fase 8, dan
 * mengulanginya di sini hanya akan menggandakan test yang sama.
 */
function terbitkanRiwayat(TestCase $test, array $data, Exam $ujian, User $admin): void
{
    $guru = guruRiwayat($ujian);

    $uraian = StudentAnswer::query()
        ->whereHas('butir', fn ($q) => $q
            ->whereHas('soal', fn ($s) => $s->where('jenis', JenisSoal::Uraian->value)))
        ->whereHas('butir.attempt', fn ($q) => $q
            ->whereIn('exam_participant_id', collect($data['peserta'])->pluck('id')))
        ->get();

    foreach ($uraian as $satu) {
        masukUji($test, $guru)
            ->post('/guru/koreksi/jawaban/'.$satu->id, [
                'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 5]],
            ])
            ->assertRedirect();
    }

    masukUji($test, $admin)
        ->post("/admin/events/{$data['event']->id}/hasil/terbitkan")
        ->assertRedirect()
        ->assertSessionHasNoErrors();
}

/* =====================================================================
 | Pintu 1 - publikasi
 |===================================================================== */

it('menjawab 404 sebelum hasil eventnya diterbitkan', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    [$attempt, $peserta] = riwayatSiapTerbit($this, $data);

    // Prasyaratnya dibuktikan lebih dulu: attempt-nya SUNGGUH selesai dan
    // jawabannya SUNGGUH tersimpan. Tanpa ini, 404 di bawah bisa berasal
    // dari "belum dikerjakan" - keadaan yang tidak sedang diuji.
    expect($attempt->status)->toBe(App\Domain\Attendance\Models\ExamAttempt::STATUS_DIKIRIM)
        ->and($data['event']->refresh()->hasilTerbit())->toBeFalse();

    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/riwayat")
        ->assertNotFound();

    // Dan bukan karena rutenya tidak ada.
    expect(Route::has('siswa.riwayat'))->toBeTrue();
});

it('menjawab 404 untuk penempatan milik siswa lain', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    riwayatSiapTerbit($this, $data);
    terbitkanRiwayat($this, $data, $data['ujian'], $this->admin);

    // Id penempatan milik siswa lain dijawab 404, bukan 403: 403 akan
    // memberi tahu bahwa id itu memang ada. Aturannya sama dengan seluruh
    // laman siswa - lihat SiswaDasar.
    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$data['peserta'][1]->id}/riwayat")
        ->assertNotFound();
});

/* =====================================================================
 | Isi laman - jawaban anak, dan bentuknya
 |===================================================================== */

it('mengirim jawaban siswa apa adanya sesudah hasilnya terbit', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    // Satu jenis dijawab SALAH, supaya laman ini punya kedua keadaan
    // sekaligus - dan supaya `benar: false` tidak bisa lolos hanya karena
    // seluruh butir kebetulan benar.
    riwayatSiapTerbit($this, $data, 0, JenisSoal::PilihanGanda->value);
    terbitkanRiwayat($this, $data, $data['ujian'], $this->admin);

    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$data['peserta'][0]->id}/riwayat")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Siswa/Riwayat')
            ->has('butir', 4)
            ->where('boleh_kunci', true)
            ->where('boleh_pembahasan', false)
            ->where('ringkasan.benar', 2)
            ->where('ringkasan.salah', 1)
            // Uraian SUDAH dikoreksi, dan ia tidak pernah menjadi "benar"
            // atau "salah": `is_correct` tidak diisi untuk uraian - lihat
            // PenilaiUraian. Ia menghuni keadaan keempat, `dinilai`, dan
            // melipatnya ke `belum` akan membuat anak menagih koreksi yang
            // sudah selesai.
            ->where('ringkasan.dinilai', 1)
            ->where('ringkasan.belum', 0)
            // 3 (isian) + 5 (uraian) + 1 (media) = 9 dari 11 -> 81.82
            ->where('hasil.persen', 81.82)
            ->where('hasil.final', true)
        );
});

it('memisahkan butir yang belum dinilai dari butir yang salah', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    // Uraian sengaja TIDAK dijawab. Akibatnya `student_answers` tidak punya
    // barisnya - lihat JawabanService - sehingga butir itu harus muncul
    // sebagai `jawaban: null` dan `benar: null`, bukan sebagai salah.
    foreach (AttemptQuestion::query()
        ->where('exam_attempt_id', attemptBerjalan($peserta)->id)
        ->with('soal')->get() as $satu) {
        if ($satu->soal === null || $satu->soal->jenis === JenisSoal::Uraian) {
            continue;
        }

        masukUji($this, $data['siswa'][0])
            ->postJson("/siswa/ujian/{$peserta->id}/butir/{$satu->id}", [
                'isi' => jawabanRiwayatBenar($satu->soal),
            ]);
    }

    masukUji($this, $data['siswa'][0])->post("/siswa/ujian/{$peserta->id}/selesai");

    terbitkanRiwayat($this, $data, $data['ujian'], $this->admin);

    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/riwayat")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('butir', 4)
            // Satu butir tanpa jawaban, dan ia BUKAN salah.
            ->where('butir.2.jawaban', null)
            ->where('butir.2.benar', null)
            // Keadaannya `belum`, bukan `dinilai`: tidak ada skor, dan tidak
            // ada benar/salah - satu-satunya butir di kedua hitungan itu.
            ->where('butir.2.keadaan', 'belum')
            ->where('ringkasan.belum', 1)
            ->where('ringkasan.salah', 0)
            ->where('ringkasan.benar', 3)
        );
});

/* =====================================================================
 | Pintu 2 dan 3 - dua centang, dua izin yang berbeda
 |===================================================================== */

it('tidak mengirim kunci maupun pembahasan saat keduanya dimatikan', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    riwayatSiapTerbit($this, $data);
    terbitkanRiwayat($this, $data, $data['ujian'], $this->admin);

    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/hasil/tampilan", [
            'tampilkan_objektif' => false,
            'tampilkan_pembahasan' => false,
        ])
        ->assertRedirect();

    $props = masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$data['peserta'][0]->id}/riwayat")
        ->viewData('page')['props'];

    expect($props['boleh_kunci'])->toBeFalse()
        ->and($props['boleh_pembahasan'])->toBeFalse();

    foreach ($props['butir'] as $b) {
        expect($b['kunci'])->toBeNull()
            ->and($b['pembahasan'])->toBeNull();
    }

    // Rubrik uraian diperiksa sebagai KATA: kolom yang tidak dikirim tetapi
    // isinya ikut lewat jalur lain adalah kebocoran yang paling sulit
    // terlihat, dan 'Ketepatan konsep' adalah teks rubrik di paket contoh.
    expect(json_encode($props))->not->toContain('Ketepatan konsep');
});

it('membuka kunci tanpa membuka pembahasan, dan sebaliknya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    riwayatSiapTerbit($this, $data);
    terbitkanRiwayat($this, $data, $data['ujian'], $this->admin);

    // Bawaan migrasinya: objektif TRUE, pembahasan FALSE. Keduanya dibaca
    // dari event yang sama, dan kunci boleh keluar sementara pembahasan
    // tidak - itulah yang membuat dua prop ini tidak boleh disatukan.
    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/hasil/tampilan", [
            'tampilkan_objektif' => true,
            'tampilkan_pembahasan' => false,
        ])
        ->assertRedirect();

    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$data['peserta'][0]->id}/riwayat")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('boleh_kunci', true)
            ->where('boleh_pembahasan', false)
            ->where('butir.0.kunci.dipilih', ['a'])
            ->where('butir.0.pembahasan', null)
        );

    // Sekarang kebalikannya: pembahasan dibuka, kunci DITUTUP. Laman yang
    // mengambil kunci dari pembahasan akan lulus pemeriksaan pertama dan
    // gagal yang ini.
    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/hasil/tampilan", [
            'tampilkan_objektif' => false,
            'tampilkan_pembahasan' => true,
        ])
        ->assertRedirect();

    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$data['peserta'][0]->id}/riwayat")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('boleh_kunci', false)
            ->where('boleh_pembahasan', true)
            ->where('butir.0.kunci', null)
            // Uraian punya pembahasan di paket contohnya; pilihan ganda
            // tidak. Yang diperiksa: pembahasan URAIAN benar-benar keluar
            // saat izinnya menyala.
            ->where('butir.2.pembahasan', '<p>Pembahasan yang tidak boleh dibaca siswa.</p>')
        );
});

/* =====================================================================
 | Pintu keadaan - attempt yang belum selesai
 |===================================================================== */

it('menjawab 404 untuk attempt yang masih berjalan', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    // Hasil event dibukakan langsung - keadaannya dibuat mustahil lewat
    // jalur normal, dan justru itu intinya: gerbang publikasi saja TIDAK
    // cukup untuk menahan anak yang masih mengerjakan dari membuka kunci
    // jawaban ujiannya sendiri.
    $data['event']->forceFill(['results_published_at' => now()])->save();

    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/riwayat")
        ->assertNotFound();
});

it('mengalihkan pengunjung yang belum masuk', function () {
    app(SchoolResolver::class)->forget();

    $this->get('/siswa/ujian/1/riwayat')->assertRedirect('/login');
});
