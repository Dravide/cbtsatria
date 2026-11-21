<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Exam\Models\ExamToken;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Support\PengaturanBawaan;
use Inertia\Testing\AssertableInertia;

it('menyimpan pengaturan wajib_token_ujian melalui /admin/pengaturan', function () {
    $sekolah = School::factory()->create();
    $admin = User::factory()->admin()->untukSekolah($sekolah)->create();

    $payload = array_merge(PengaturanBawaan::NILAI, [
        'wajib_token_ujian' => false,
    ]);

    masukUji($this, $admin)
        ->put('/admin/pengaturan', $payload)
        ->assertSessionHas('sukses');

    expect($sekolah->refresh()->settings['wajib_token_ujian'])->toBeFalse();
});

it('mengizinkan siswa memulai ujian tanpa token bila wajib_token_ujian dimatikan', function () {
    $sekolah = School::factory()->create();
    $admin = User::factory()->admin()->untukSekolah($sekolah)->create();
    $tahun = AcademicYear::factory()->untukSekolah($sekolah)->create();
    $semester = Semester::factory()->create([
        'school_id' => $sekolah->id,
        'academic_year_id' => $tahun->id,
    ]);

    $data = siapkanUjianSiswa($sekolah, $tahun, $semester, $admin);
    $peserta = $data['peserta']->first();
    $siswa = $data['siswa'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang']->first(), $peserta);

    // Cabut semua token sesi untuk memastikan pintu tetap terbuka tanpa token.
    ExamToken::query()->where('exam_session_id', $data['sesi']->id)->delete();

    // Default wajib_token_ujian = true -> harus menolak tanpa token.
    mulaiAttempt($this, $siswa, $peserta, null)
        ->assertSessionHasErrors('token');

    // Matikan wajib_token_ujian di /admin/pengaturan.
    $sekolah->forceFill([
        'settings' => array_merge(PengaturanBawaan::NILAI, ['wajib_token_ujian' => false]),
    ])->save();

    masukUji($this, $siswa)
        ->get("/siswa/ujian/{$peserta->id}/gerbang")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Siswa/Gerbang')
            ->where('wajib_token', false)
        );

    // Sekarang siswa bisa langsung mulai tanpa token.
    mulaiAttempt($this, $siswa, $peserta, null)
        ->assertRedirect("/siswa/ujian/{$peserta->id}/kerjakan");
});
