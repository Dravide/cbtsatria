<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Admin\Services\KartuWaliKelas;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;

beforeEach(function () {
    $this->sekolah = School::factory()->create([
        'name' => 'SMK Negeri 1',
        'code' => 'smkn1',
        'papan_slug' => 'smkn1-papan',
    ]);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.kelas.kartu',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);
});

function masukKartuKelas($test, User $user)
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

it('menyusun data kartu kelas dengan QR, URL, dan kode wali', function () {
    $wali = User::factory()->guru()->untukSekolah($this->sekolah)->create(['name' => 'Pak Budi']);

    $kelas = SchoolClass::factory()->untukTahun($this->tahun)->create([
        'name' => 'X-1',
        'homeroom_teacher_id' => $wali->id,
        'wali_slug' => 'x-1-abc1',
        'wali_code' => '482910',
    ]);

    $susunan = app(KartuWaliKelas::class)->susun(
        SchoolClass::query()->with(['academicYear', 'homeroomTeacher'])->whereKey($kelas->id)->get(),
        $this->sekolah->papan_slug,
    );

    expect($susunan)->toHaveCount(1)
        ->and($susunan[0]['nama'])->toBe('X-1')
        ->and($susunan[0]['wali'])->toBe('Pak Budi')
        ->and($susunan[0]['kode'])->toBe('482910')
        ->and($susunan[0]['url'])->toContain('/wali/smkn1-papan/kelas/'.$kelas->id)
        ->and($susunan[0]['qr'])->toStartWith('data:image/png;base64,');
});

it('mengunduh satu kartu kelas sebagai PDF dan mencatatnya', function () {
    $kelas = SchoolClass::factory()->untukTahun($this->tahun)->create([
        'name' => 'X-1',
        'wali_slug' => 'x-1-abc1',
        'wali_code' => '123456',
    ]);

    $respon = masukKartuKelas($this, $this->admin)
        ->get("/admin/kelas/kartu?kelas[]={$kelas->id}");

    $respon->assertOk();

    expect($respon->headers->get('content-type'))->toContain('application/pdf')
        ->and(substr($respon->getContent(), 0, 4))->toBe('%PDF');

    $this->assertDatabaseHas('activity_logs', [
        'school_id' => $this->sekolah->id,
        'action' => 'Mencetak kartu pantau wali kelas',
    ]);
});

it('membungkus banyak kartu kelas sebagai ZIP berisi satu PDF per kelas', function () {
    $k1 = SchoolClass::factory()->untukTahun($this->tahun)->create([
        'name' => 'X-1',
        'wali_slug' => 'x-1-a1',
        'wali_code' => '111111',
    ]);

    $k2 = SchoolClass::factory()->untukTahun($this->tahun)->create([
        'name' => 'X-2',
        'wali_slug' => 'x-2-a2',
        'wali_code' => '222222',
    ]);

    $respon = masukKartuKelas($this, $this->admin)
        ->get("/admin/kelas/kartu?kelas[]={$k1->id}&kelas[]={$k2->id}");

    $respon->assertOk();

    $jalur = tempnam(sys_get_temp_dir(), 'uji-kelas-');
    file_put_contents($jalur, $respon->streamedContent());

    $zip = new ZipArchive;
    expect($zip->open($jalur))->toBeTrue();

    $isi = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nama = $zip->getNameIndex($i);
        $isi[$nama] = substr((string) $zip->getFromIndex($i), 0, 4);
    }

    $zip->close();
    @unlink($jalur);

    expect($isi)->toHaveCount(2)
        ->and($isi['kartu-wali_x-1.pdf'])->toBe('%PDF')
        ->and($isi['kartu-wali_x-2.pdf'])->toBe('%PDF');
});

it('mencetak seluruh kelas pada tahun ajaran dan penyaring yang aktif', function () {
    SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'XI TKJ 1']);
    SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'XI TKJ 2']);
    SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'XII RPL 1']);

    $respon = masukKartuKelas($this, $this->admin)
        ->get("/admin/kelas/kartu?semua=1&ta={$this->tahun->id}&cari=TKJ");

    $respon->assertOk();

    $jalur = tempnam(sys_get_temp_dir(), 'uji-kelas-');
    file_put_contents($jalur, $respon->streamedContent());

    $zip = new ZipArchive;
    $zip->open($jalur);

    expect($zip->numFiles)->toBe(2);

    $zip->close();
    @unlink($jalur);
});

it('menolak pengguna non-admin dan mengisolasi kelas sekolah lain', function () {
    $sekolahLain = School::factory()->create(['name' => 'SMK Lain', 'code' => 'smklain']);
    $tahunLain = AcademicYear::factory()->untukSekolah($sekolahLain)->aktif()->create();
    $kelasLain = SchoolClass::factory()->untukTahun($tahunLain)->create(['name' => 'X-1']);

    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create();

    masukKartuKelas($this, $guru)
        ->get('/admin/kelas/kartu?semua=1')
        ->assertRedirect();

    masukKartuKelas($this, $this->admin)
        ->get("/admin/kelas/kartu?kelas[]={$kelasLain->id}")
        ->assertRedirect();
});
