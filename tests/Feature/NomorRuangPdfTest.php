<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

it('mengunduh pdf nomor ruang ukuran A4 untuk admin', function () {
    $sekolah = School::factory()->create(['name' => 'SMPN 1 Cipanas']);
    $admin = User::factory()->admin()->untukSekolah($sekolah)->create();
    $tahun = AcademicYear::factory()->untukSekolah($sekolah)->aktif()->create(['name' => '2026/2027']);
    $semester = Semester::factory()->create([
        'school_id' => $sekolah->id,
        'academic_year_id' => $tahun->id,
        'name' => 'Ganjil',
    ]);

    $data = siapkanUjianSiswa($sekolah, $tahun, $semester, $admin);

    $res = $this->actingAs($admin)
        ->withSession(['school_id' => $sekolah->id, 'event_aktif' => $data['event']->id])
        ->get('/admin/ruang/nomor-pdf');

    $res->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(str_starts_with((string) $res->getContent(), '%PDF'))->toBeTrue();
});
