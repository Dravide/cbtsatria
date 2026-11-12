<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\KehadiranService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Grading\Exports\LaporanData;
use App\Domain\Grading\Models\ReportExport;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/**
 * Laporan kehadiran menyebut yang HADIR dan yang TIDAK HADIR.
 *
 * ---------------------------------------------------------------------
 * Yang dijaga berkas ini adalah satu kesalahan yang tidak berbunyi
 * ---------------------------------------------------------------------
 * Versi pertama laporan ini menyusuri `ExamAttempt`, sehingga hanya siswa
 * yang sudah membuka ujian yang muncul. Siswa yang tidak pernah datang -
 * orang yang justru dicari saat laporan kehadiran dibuka - tidak punya
 * attempt, dan karena itu tidak punya baris. Tabelnya terlihat wajar,
 * jumlahnya terlihat wajar, dan yang hilang justru daftar orang yang perlu
 * ditindaklanjuti.
 *
 * Setiap test di bawah karena itu menempatkan seorang siswa yang TIDAK
 * mengerjakan apa pun, lalu menuntut namanya ada di laporan.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.kehadiran',
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
 * Catat satu status lewat jalur yang sama dengan pengawas di ruang.
 *
 * Masuk sebagai Admin lebih dulu karena `KonteksRuang::dari()` menolak
 * permintaan tanpa pelaku - dan pelaku itu memang bagian dari catatan
 * presensi: baris yang tidak menyebut siapa yang memverifikasi adalah baris
 * yang tidak bisa dipertanggungjawabkan.
 */
function catatPresensi($test, User $admin, array $data, ExamParticipant $peserta, StatusPresensi $status): void
{
    masukUji($test, $admin);

    $record = AttendanceRecord::query()
        ->where('exam_participant_id', $peserta->id)
        ->firstOrFail();

    app(KehadiranService::class)->catat(
        $record,
        $status,
        KonteksRuang::dari($data['ruang']->firstOrFail()),
    );
}

it('menampilkan siswa yang tidak pernah mengerjakan ujian, dengan status tidak hadir', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin, [], 3);

    $peserta = $data['peserta'];

    // Satu hadir, satu tidak hadir, satu tidak pernah diverifikasi.
    catatPresensi($this, $this->admin, $data, $peserta[0], StatusPresensi::Hadir);
    catatPresensi($this, $this->admin, $data, $peserta[1], StatusPresensi::TidakHadir);

    $baris = LaporanData::ambil('kehadiran', $data['event']->refresh()->id, []);

    // TIGA baris, bukan satu - jangan sampai jumlahnya ikut menyusut.
    expect($baris)->toHaveCount(3);

    $perNama = $baris->keyBy('nama');

    expect($perNama)->toHaveKeys([
        $data['siswa'][0]->name,
        $data['siswa'][1]->name,
        $data['siswa'][2]->name,
    ])
        ->and($perNama[$data['siswa'][0]->name]['kehadiran'])->toBe('Hadir')
        ->and($perNama[$data['siswa'][1]->name]['kehadiran'])->toBe('Tidak hadir')
        // Yang belum diverifikasi BUKAN tidak hadir - lihat catatan
        // `StatusPresensi` tentang mengapa `pending` adalah keadaan sendiri.
        ->and($perNama[$data['siswa'][2]->name]['kehadiran'])->toBe('Belum diverifikasi');
});

it('membedakan belum ditempatkan dari belum diverifikasi', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin, [], 1);

    // Peserta dikeluarkan dari ruang: kursinya hilang, dan bersama kursinya
    // baris presensinya ikut dilepas - lihat `PencatatKehadiranService`.
    app(PenempatanService::class)->lepas($data['peserta'][0]);

    $baris = LaporanData::ambil('kehadiran', $data['event']->refresh()->id, []);

    // Siswa berstatus terdaftar tetapi tanpa kursi TETAP masuk laporan:
    // ia orang yang harus dihubungi Admin sebelum ujian dibuka.
    expect($baris)->toHaveCount(1)
        ->and($baris[0]['kehadiran'])->toBe('Belum ditempatkan');
});

it('tidak menghitung peserta yang dikecualikan sebagai tidak hadir', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin, [], 2);

    app(PenempatanService::class)->kecualikan($data['peserta'][0]);

    $baris = LaporanData::ambil('kehadiran', $data['event']->refresh()->id, []);

    // Pengecualian berarti "tidak dijadwalkan mengerjakan ujian ini" - bukan
    // "tidak datang". Menghitungnya di sini adalah tuduhan yang salah alamat.
    expect($baris)->toHaveCount(1)
        ->and($baris[0]['nama'])->toBe($data['siswa'][1]->name);
});

it('menjumlahkan setiap keadaan kehadiran dari baris yang sama dengan tabelnya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin, [], 4);

    catatPresensi($this, $this->admin, $data, $data['peserta'][0], StatusPresensi::Hadir);
    catatPresensi($this, $this->admin, $data, $data['peserta'][1], StatusPresensi::Terlambat);
    catatPresensi($this, $this->admin, $data, $data['peserta'][2], StatusPresensi::Izin);

    $event = $data['event']->refresh();
    $baris = LaporanData::ambil('kehadiran', $event->id, []);
    $ringkasan = collect(LaporanData::ringkasanKehadiran($baris))->keyBy('kunci');

    expect($ringkasan['hadir']['jumlah'])->toBe(1)
        ->and($ringkasan['terlambat']['jumlah'])->toBe(1)
        ->and($ringkasan['izin']['jumlah'])->toBe(1)
        ->and($ringkasan['tidak_hadir']['jumlah'])->toBe(0)
        ->and($ringkasan['belum_diverifikasi']['jumlah'])->toBe(1)
        ->and($ringkasan['belum_ditempatkan']['jumlah'])->toBe(0)
        // Jumlah seluruh kartu harus sama dengan jumlah baris tabelnya,
        // kecuali kartu totalnya sendiri.
        ->and($ringkasan['total']['jumlah'])->toBe(4);
});

it('mengirim ringkasan kehadiran ke laman, dan tidak ke laporan lain', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin, [], 2);
    $event = $data['event']->refresh();

    catatPresensi($this, $this->admin, $data, $data['peserta'][0], StatusPresensi::Hadir);

    masukUji($this, $this->admin)
        ->withSession(['event_aktif' => $event->id])
        ->get('/admin/laporan?jenis=kehadiran')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Laporan')
            ->where('jenis', 'kehadiran')
            ->has('ringkasan_kehadiran', 7)
            ->where('ringkasan_kehadiran.0.kunci', 'hadir')
            ->where('ringkasan_kehadiran.0.jumlah', 1)
            ->has('tabel.data', 2)
            ->has('kolom', 8)
            // Kolom `kehadiran` duduk SEBELUM status pengerjaan: pertanyaan
            // "apakah ia datang" mendahului "sejauh mana ia mengerjakan".
            ->where('kolom.4.kunci', 'kehadiran'));

    masukUji($this, $this->admin)
        ->withSession(['event_aktif' => $event->id])
        ->get('/admin/laporan?jenis=nilai')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('ringkasan_kehadiran', 0));
});

it('memuat yang tidak hadir di berkas ekspornya juga', function () {
    Storage::fake('local');

    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin, [], 2);
    $event = $data['event']->refresh();

    catatPresensi($this, $this->admin, $data, $data['peserta'][0], StatusPresensi::Hadir);
    catatPresensi($this, $this->admin, $data, $data['peserta'][1], StatusPresensi::TidakHadir);

    masukUji($this, $this->admin)
        ->withSession(['event_aktif' => $event->id])
        ->post('/admin/laporan', ['jenis' => 'kehadiran', 'format' => 'csv'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $ekspor = ReportExport::query()->orderByDesc('id')->firstOrFail();

    expect($ekspor->status)->toBe(ReportExport::STATUS_SELESAI)
        ->and($ekspor->total_baris)->toBe(2);

    $isi = Storage::disk('local')->get($ekspor->path);

    // Kedua nama ada di berkas - dan yang tidak hadir TIDAK boleh hilang
    // hanya karena ia tidak punya attempt.
    expect($isi)->toContain($data['siswa'][0]->name)
        ->and($isi)->toContain($data['siswa'][1]->name)
        ->and($isi)->toContain('Tidak hadir')
        ->and($isi)->toContain('Hadir');
});
