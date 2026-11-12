<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Exam\Models\Exam;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Presensi dan Monitoring memuat SELURUH mapel event, termasuk yang belum
 * dijadwalkan.
 *
 * ---------------------------------------------------------------------
 * Yang dijaga di sini adalah baris yang TIDAK ADA di tabel ruang
 * ---------------------------------------------------------------------
 * Kedua laman itu menyusun barisnya dari `exam_session_rooms`. Pada event
 * yang baru sebagian mapelnya dijadwalkan, papan yang tampil terlihat
 * lengkap - dan Admin tidak punya cara mengetahui bahwa tiga mapel lain
 * belum punya jadwal sama sekali. Yang hilang justru daftar pekerjaan
 * berikutnya.
 *
 * Karena itu test di bawah menambahkan mapel yang sengaja TIDAK diberi
 * ujian, lalu menuntut namanya tetap muncul di payload kedua laman.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.mapel.event',
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

it('menyebut mapel yang dijadwalkan maupun yang belum, di laman presensi', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin);

    // Mapel kedua yang terdaftar di event tetapi TIDAK diberi ujian.
    $mapelLain = Subject::factory()->create([
        'school_id' => $this->sekolah->id,
        'code' => 'BIN-'.$data['event']->id,
        'name' => 'Bahasa Indonesia '.$data['event']->id,
    ]);

    EventSubject::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $data['event']->id,
        'subject_id' => $mapelLain->id,
    ]);

    masukUji($this, $this->admin)
        ->get("/admin/events/{$data['event']->id}/presensi")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Presensi')
            ->has('mapel', 2)
            // Urutannya kode mapel, supaya daftar yang dibaca Admin sama
            // urutannya dengan laman Mata pelajaran.
            ->where('mapel.0.kode', 'BIN-'.$data['event']->id)
            ->where('mapel.0.keadaan', 'belum_ujian')
            ->where('mapel.0.peserta', 0)
            ->where('mapel.1.keadaan', 'terjadwal')
            ->where('mapel.1.jumlah_sesi', 1)
            // Peserta mapel yang terjadwal ikut terhitung - dan angkanya
            // dibaca dari penempatan, bukan dari rekap ruang.
            ->where('mapel.1.peserta', 4));
});

it('menyebut mapel yang belum punya sesi, di laman monitoring', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin);

    // Mapel kedua dengan UJIAN, tetapi tanpa sesi - keadaan yang paling mudah
    // hilang dari papan, karena ujiannya ada dan jadwalnya tidak.
    $mapelLain = Subject::factory()->create([
        'school_id' => $this->sekolah->id,
        'code' => 'IPA-'.$data['event']->id,
        'name' => 'IPA '.$data['event']->id,
    ]);

    EventSubject::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $data['event']->id,
        'subject_id' => $mapelLain->id,
    ]);

    Exam::factory()->create([
        'school_id' => $this->sekolah->id,
        'event_id' => $data['event']->id,
        'subject_id' => $mapelLain->id,
        'title' => 'Ujian IPA',
    ]);

    masukUji($this, $this->admin)
        ->get("/admin/events/{$data['event']->id}/monitoring")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Monitoring')
            ->has('mapel', 2)
            ->where('mapel.0.kode', 'IPA-'.$data['event']->id)
            ->where('mapel.0.keadaan', 'belum_sesi')
            ->where('mapel.0.jumlah_sesi', 0)
            ->where('mapel.0.ujian.0', 'Ujian IPA')
            ->where('mapel.1.keadaan', 'terjadwal'));
});

it('tidak menambah kueri saat mapelnya ditambah', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $eventId = $data['event']->id;

    $hitung = function () use ($eventId) {
        $n = 0;

        DB::listen(function () use (&$n) {
            $n++;
        });

        app(SchoolResolver::class)->forget();

        masukUji($this, $this->admin)->get("/admin/events/{$eventId}/presensi")->assertOk();

        return $n;
    };

    // Pemanasan: permintaan pertama dalam satu test memuat kueri sekali-jalan
    // (resolusi sekolah, penyegaran sesi) yang tidak ada lagi di permintaan
    // kedua. Yang diukur di sini adalah selisih antara dua permintaan yang
    // sama-sama pasca-pemanasan.
    $hitung();

    $sebelum = $hitung();

    // Lima mapel tambahan, semuanya tanpa ujian.
    foreach (range(1, 5) as $i) {
        $mapel = Subject::factory()->create([
            'school_id' => $this->sekolah->id,
            'code' => 'TAM-'.$i.'-'.$eventId,
            'name' => 'Tambahan '.$i,
        ]);

        EventSubject::create([
            'school_id' => $this->sekolah->id,
            'event_id' => $eventId,
            'subject_id' => $mapel->id,
        ]);
    }

    $sesudah = $hitung();

    // Daftar mapel dibaca dengan empat kueri tetap - lihat `mapelEvent()`.
    // N+1 di sini akan menghasilkan selisih lima.
    expect($sesudah - $sebelum)->toBe(0);
});
