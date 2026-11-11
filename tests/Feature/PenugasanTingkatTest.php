<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Penugasan mengajar per TINGKAT - satu tindakan untuk seluruh kelas selevel.
 *
 * ---------------------------------------------------------------------
 * Yang paling perlu dijaga di berkas ini bukan "apakah barisnya terbentuk"
 * ---------------------------------------------------------------------
 * Membentuk sebelas baris dari satu klik adalah bagian yang mudah. Yang sulit,
 * dan yang paling mahal kalau salah, adalah batasnya:
 *
 * - Kelas tahun lalu TIDAK boleh ikut tertugaskan. Barisnya sah menurut skema,
 *   tidak muncul di layar mana pun, dan membuat guru terlihat berhak atas
 *   kelas yang sudah bubar.
 * - Kelas dari tingkat lain TIDAK boleh tersentuh.
 * - Sekolah lain TIDAK boleh tersentuh.
 *
 * Ketiganya menghasilkan kegagalan yang diam: tidak ada galat, tidak ada 500,
 * hanya izin yang terlalu luas. Karena itu ketiganya diuji di sini, bukan
 * dipercayakan pada pembacaan ulang kueri.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);
    $this->lain = School::factory()->create(['name' => 'SMK Negeri 2']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.tingkat',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $this->mapel = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MTK',
        'name' => 'Matematika',
    ]);

    $this->guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'name' => 'Ani Mulyani',
    ]);
});

/**
 * Kelas di tahun ajaran aktif milik sekolah uji.
 */
function kelasTingkat(string $nama, string $tingkat, ?AcademicYear $tahun = null): SchoolClass
{
    return SchoolClass::factory()
        ->untukTahun($tahun ?? test()->tahun)
        ->create(['name' => $nama, 'grade_level' => $tingkat]);
}

it('menugaskan guru ke seluruh kelas satu tingkat dalam satu tindakan', function () {
    kelasTingkat('9A', '9');
    kelasTingkat('9B', '9');
    kelasTingkat('9C', '9');
    kelasTingkat('8A', '8');

    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'tingkat',
            'tingkat' => '9',
            'teacher_id' => $this->guru->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('sukses');

    expect(TeachingAssignment::query()->where('subject_id', $this->mapel->id)->count())->toBe(3)
        // Tingkat 8 tidak ikut, dan itu yang membedakan penugasan per tingkat
        // dari "tugaskan ke semua kelas".
        ->and(TeachingAssignment::query()
            ->whereHas('kelas', fn ($q) => $q->where('name', '8A'))
            ->count())->toBe(0);
});

it('menyebut jumlah kelas yang tertugaskan di pesan sukses', function () {
    kelasTingkat('9A', '9');
    kelasTingkat('9B', '9');

    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'tingkat',
            'tingkat' => '9',
            'teacher_id' => $this->guru->id,
        ])
        ->assertSessionHas('sukses', fn (string $pesan) => str_contains($pesan, '2 kelas tingkat 9'));
});

it('tidak menyentuh kelas tingkat yang sama di tahun ajaran lain', function () {
    // Tahun ajaran lampau - nama kelasnya sengaja identik dengan yang aktif,
    // karena itulah yang terjadi di sekolah: 9A tahun lalu dan 9A tahun ini
    // adalah dua baris berbeda dengan nama yang sama.
    $lampau = AcademicYear::factory()->untukSekolah($this->sekolah)->create([
        'name' => '2025/2026',
        'is_active' => false,
    ]);

    kelasTingkat('9A', '9');
    $kelasLampau = SchoolClass::factory()->untukTahun($lampau)->create([
        'name' => '9A',
        'grade_level' => '9',
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'tingkat',
            'tingkat' => '9',
            'teacher_id' => $this->guru->id,
        ])
        ->assertRedirect();

    expect(TeachingAssignment::query()
        ->where('class_id', $kelasLampau->id)
        ->count())->toBe(0);
});

it('tidak menyentuh kelas tingkat yang sama milik sekolah lain', function () {
    $tahunLain = AcademicYear::factory()->untukSekolah($this->lain)->aktif()->create();

    $kelasLain = SchoolClass::factory()->untukTahun($tahunLain)->create([
        'name' => '9A',
        'grade_level' => '9',
    ]);

    kelasTingkat('9A', '9');

    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'tingkat',
            'tingkat' => '9',
            'teacher_id' => $this->guru->id,
        ])
        ->assertRedirect();

    expect(TeachingAssignment::query()->where('class_id', $kelasLain->id)->count())->toBe(0);
});

it('melewati kelas yang sudah ditugaskan alih-alih menggandakannya', function () {
    $sudah = kelasTingkat('9A', '9');
    kelasTingkat('9B', '9');

    TeachingAssignment::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'teacher_id' => $this->guru->id,
        'subject_id' => $this->mapel->id,
        'class_id' => $sudah->id,
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'tingkat',
            'tingkat' => '9',
            'teacher_id' => $this->guru->id,
        ])
        // Menekan tombolnya dua kali tidak boleh terbaca seperti kegagalan:
        // yang kedua hasilnya sama, dan Admin perlu tahu itu memang disengaja.
        ->assertSessionHas('sukses', fn (string $pesan) => str_contains($pesan, 'sudah ditugaskan sebelumnya'));

    expect(TeachingAssignment::query()->where('subject_id', $this->mapel->id)->count())->toBe(2);
});

it('menolak tingkat yang tidak punya kelas di tahun ajaran aktif', function () {
    kelasTingkat('8A', '8');

    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'tingkat',
            'tingkat' => '9',
            'teacher_id' => $this->guru->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('galat', fn (string $pesan) => str_contains($pesan, 'tingkat 9'));

    expect(TeachingAssignment::query()->count())->toBe(0);
});

it('menolak tingkat saat belum ada tahun ajaran aktif', function () {
    $this->tahun->update(['is_active' => false]);

    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'tingkat',
            'tingkat' => '9',
            'teacher_id' => $this->guru->id,
        ])
        ->assertSessionHas('galat');

    expect(TeachingAssignment::query()->count())->toBe(0);
});

it('menuntut tingkat diisi saat lingkupnya tingkat', function () {
    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'tingkat',
            'tingkat' => '',
            'teacher_id' => $this->guru->id,
        ])
        ->assertSessionHasErrors('tingkat');

    expect(TeachingAssignment::query()->count())->toBe(0);
});

it('menuntut kelas diisi saat lingkupnya kelas', function () {
    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'kelas',
            'class_id' => '',
            'teacher_id' => $this->guru->id,
        ])
        ->assertSessionHasErrors('class_id');

    expect(TeachingAssignment::query()->count())->toBe(0);
});

it('menolak lingkup yang tidak dikenal', function () {
    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'semua',
            'teacher_id' => $this->guru->id,
        ])
        ->assertSessionHasErrors('lingkup');
});

it('tetap menugaskan satu kelas saja saat lingkupnya kelas', function () {
    $kelas = kelasTingkat('9A', '9');
    kelasTingkat('9B', '9');

    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'kelas',
            'class_id' => $kelas->id,
            'teacher_id' => $this->guru->id,
        ])
        ->assertSessionHas('sukses');

    expect(TeachingAssignment::query()->where('subject_id', $this->mapel->id)->count())->toBe(1)
        ->and(TeachingAssignment::query()->first()->class_id)->toBe($kelas->id);
});

it('menolak kelas milik sekolah lain', function () {
    $tahunLain = AcademicYear::factory()->untukSekolah($this->lain)->aktif()->create();
    $kelasLain = SchoolClass::factory()->untukTahun($tahunLain)->create(['name' => '9Z']);

    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'kelas',
            'class_id' => $kelasLain->id,
            'teacher_id' => $this->guru->id,
        ])
        ->assertSessionHas('galat');

    expect(TeachingAssignment::query()->count())->toBe(0);
});

it('menolak guru milik sekolah lain', function () {
    $guruLain = User::factory()->guru()->untukSekolah($this->lain)->create();
    kelasTingkat('9A', '9');

    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'tingkat',
            'tingkat' => '9',
            'teacher_id' => $guruLain->id,
        ])
        ->assertNotFound();

    expect(TeachingAssignment::query()->count())->toBe(0);
});

it('menolak guru yang bukan berperan guru', function () {
    $siswa = User::factory()->untukSekolah($this->sekolah)->create(['role' => 'student']);
    kelasTingkat('9A', '9');

    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'tingkat',
            'tingkat' => '9',
            'teacher_id' => $siswa->id,
        ])
        ->assertNotFound();
});

it('mencatat satu baris log untuk satu tindakan tingkat', function () {
    kelasTingkat('9A', '9');
    kelasTingkat('9B', '9');

    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'tingkat',
            'tingkat' => '9',
            'teacher_id' => $this->guru->id,
        ]);

    // SATU baris, bukan dua. Sebelas baris log untuk satu keputusan membuat
    // riwayatnya tidak terbaca, dan yang dicari kelak adalah "siapa menugaskan
    // Bu Ani ke seluruh tingkat 9", bukan sebelas kejadian terpisah.
    $log = DB::table('activity_logs')
        ->where('action', 'Menambah penugasan mengajar')
        ->get();

    expect($log)->toHaveCount(1)
        ->and(json_decode($log->first()->metadata_json ?? '[]', true)['tingkat'])->toBe('9')
        ->and(json_decode($log->first()->metadata_json ?? '[]', true)['jumlah'])->toBe(2);
});

it('tidak mencatat log saat seluruh kelas sudah ditugaskan sebelumnya', function () {
    $kelas = kelasTingkat('9A', '9');

    TeachingAssignment::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'teacher_id' => $this->guru->id,
        'subject_id' => $this->mapel->id,
        'class_id' => $kelas->id,
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'tingkat',
            'tingkat' => '9',
            'teacher_id' => $this->guru->id,
        ]);

    expect(DB::table('activity_logs')->where('action', 'Menambah penugasan mengajar')->count())->toBe(0);
});

it('menolak guru yang tidak boleh mengubah mapel', function () {
    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create();
    kelasTingkat('9A', '9');

    $this->actingAs($guru)
        ->post("/admin/mapel/{$this->mapel->id}/tugaskan", [
            'lingkup' => 'tingkat',
            'tingkat' => '9',
            'teacher_id' => $this->guru->id,
        ])
        ->assertForbidden();

    expect(TeachingAssignment::query()->count())->toBe(0);
});

it('mengirim katalog tingkat dari kelas tahun ajaran aktif saja', function () {
    kelasTingkat('9A', '9');
    kelasTingkat('9B', '9');
    kelasTingkat('8A', '8');

    // Namanya DIPATOK, bukan dibiarkan diundi. `AcademicYearFactory` memilih
    // tahun antara 2020 dan 2030, dan (school_id, name) unik - sehingga satu
    // dari sebelas kali jalan, hasil undiannya '2026/2027' dan test ini merah
    // dengan galat duplikat yang tidak ada hubungannya dengan tingkat.
    $lampau = AcademicYear::factory()->untukSekolah($this->sekolah)->create([
        'name' => '2025/2026',
        'is_active' => false,
    ]);
    SchoolClass::factory()->untukTahun($lampau)->create(['name' => '7A', 'grade_level' => '7']);

    $tahunLain = AcademicYear::factory()->untukSekolah($this->lain)->aktif()->create();
    SchoolClass::factory()->untukTahun($tahunLain)->create(['name' => '9A', 'grade_level' => '9']);

    $this->actingAs($this->admin)
        ->get('/admin/mapel')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Mapel')
            ->has('tingkat', 2)
            ->where('tingkat.0.tingkat', '8')
            ->where('tingkat.0.jumlah', 1)
            ->where('tingkat.0.kelas', ['8A'])
            ->where('tingkat.1.tingkat', '9')
            ->where('tingkat.1.jumlah', 2)
            ->where('tingkat.1.kelas', ['9A', '9B'])
        );
});

it('mengurutkan tingkat secara alami, bukan menurut abjad', function () {
    // Menurut abjad "10" berada di antara "1" dan "2", dan daftar tingkat yang
    // terurut 1, 10, 2, 3 terbaca seperti kesalahan ketik.
    foreach (['10', '2', '1', '9'] as $tingkat) {
        kelasTingkat("K{$tingkat}", $tingkat);
    }

    $this->actingAs($this->admin)
        ->get('/admin/mapel')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tingkat.0.tingkat', '1')
            ->where('tingkat.1.tingkat', '2')
            ->where('tingkat.2.tingkat', '9')
            ->where('tingkat.3.tingkat', '10')
        );
});

it('mengeluarkan kelas yang tingkatnya belum diisi dari katalog', function () {
    kelasTingkat('9A', '9');
    SchoolClass::factory()->untukTahun($this->tahun)->create([
        'name' => 'Tanpa Tingkat',
        'grade_level' => null,
    ]);

    $this->actingAs($this->admin)
        ->get('/admin/mapel')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // Satu tingkat saja. Kelas tanpa tingkat tidak membentuk opsi
            // "tingkat kosong": mengelompokkannya berarti satu klik menugaskan
            // guru ke setiap kelas yang datanya belum lengkap, dan kelas-kelas
            // itu tidak punya kesamaan apa pun selain itu.
            ->has('tingkat', 1)
            ->where('tingkat.0.tingkat', '9')
            // Kelasnya sendiri tetap bisa ditugaskan satu per satu.
            ->has('kelas', 2)
        );
});

it('mengirim katalog tingkat yang kosong saat belum ada kelas sama sekali', function () {
    $this->actingAs($this->admin)
        ->get('/admin/mapel')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tingkat', 0)
            ->has('kelas', 0)
        );
});
