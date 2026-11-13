<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Event\Models\EventAnnouncement;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Papan pengumuman event - ditambahkan 2026-11-14.
 *
 * Empat hal yang dijaga di sini, dan "tidak terlihat oleh siswa event lain"
 * adalah yang paling mahal bila rusak: ia tidak menimbulkan galat apa pun,
 * hanya satu kalimat milik kegiatan orang lain yang muncul di beranda
 * seorang anak.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->lain = School::factory()->create();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.pengumuman',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
    ]);

    $this->event = SchoolEvent::factory()->untukTahun($this->tahun)->create([
        'school_id' => $this->sekolah->id,
    ]);
});

it('menerbitkan pengumuman yang langsung tayang', function () {
    $this->actingAs($this->admin)
        ->post("/admin/events/{$this->event->id}/pengumuman", [
            'judul' => 'Sesi 2 mundur 15 menit',
            'isi' => 'Ujian Matematika sesi 2 dimulai pukul 10.15. Tetap menunggu di ruang.',
            'nada' => 'penting',
        ])
        ->assertRedirect()
        ->assertSessionHas('sukses');

    $p = EventAnnouncement::query()->where('event_id', $this->event->id)->sole();

    expect($p->title)->toBe('Sesi 2 mundur 15 menit')
        ->and($p->nada)->toBe('penting')
        ->and($p->expires_at)->toBeNull()
        ->and($p->kadaluwarsa())->toBeFalse()
        ->and($p->created_by)->toBe($this->admin->id);

    // Jejaknya ditulis: pengumuman yang dibaca seluruh angkatan adalah
    // pernyataan sekolah, dan "siapa yang menulisnya" harus terjawab.
    expect(ActivityLog::query()->where('action', 'Menerbitkan pengumuman event')->exists())->toBeTrue();
});

it('menolak pengumuman tanpa judul atau isi', function () {
    $this->actingAs($this->admin)
        ->post("/admin/events/{$this->event->id}/pengumuman", ['judul' => '', 'isi' => ''])
        ->assertSessionHasErrors(['judul', 'isi']);

    expect(EventAnnouncement::query()->count())->toBe(0);
});

it('menolak waktu berakhir yang sudah lewat', function () {
    // Pengumuman yang berakhir pada detik ia ditulis tidak pernah tayang,
    // dan Admin yang menulisnya akan mengira tombolnya gagal.
    $this->actingAs($this->admin)
        ->post("/admin/events/{$this->event->id}/pengumuman", [
            'judul' => 'Uji',
            'isi' => 'Uji',
            'berlaku_sampai' => now()->subHour()->format('Y-m-d\TH:i'),
        ])
        ->assertSessionHasErrors('berlaku_sampai');

    expect(EventAnnouncement::query()->count())->toBe(0);
});

it('menayangkan hanya pengumuman yang belum berakhir', function () {
    EventAnnouncement::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $this->event->id,
        'title' => 'Masih berlaku',
        'body' => 'Sampai besok.',
        'expires_at' => now()->addDay(),
    ]);

    EventAnnouncement::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $this->event->id,
        'title' => 'Sudah lewat',
        'body' => 'Kemarin.',
        'expires_at' => now()->subHour(),
    ]);

    $props = $this->actingAs($this->admin)
        ->get("/admin/events/{$this->event->id}/pengumuman")
        ->assertOk()
        ->viewData('page')['props'];

    // Laman Admin memuat KEDUANYA - yang berakhir tetap tampil, karena pada
    // hari-H Admin perlu melihatnya untuk memperpanjang.
    expect($props['pengumuman'])->toHaveCount(2)
        ->and($props['angka'])->toMatchArray(['tayang' => 1, 'berakhir' => 1]);

    $tayang = collect($props['pengumuman'])->firstWhere('judul', 'Masih berlaku');
    $lewat = collect($props['pengumuman'])->firstWhere('judul', 'Sudah lewat');

    expect($tayang['tayang'])->toBeTrue()
        ->and($lewat['tayang'])->toBeFalse();
});

it('mengubah pengumuman yang sudah terbit', function () {
    $p = EventAnnouncement::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $this->event->id,
        'title' => 'Judul lama',
        'body' => 'Isi lama.',
        'nada' => 'info',
    ]);

    $this->actingAs($this->admin)
        ->put("/admin/events/{$this->event->id}/pengumuman/{$p->id}", [
            'judul' => 'Judul baru',
            'isi' => 'Isi baru.',
            'nada' => 'penting',
            'berlaku_sampai' => now()->addHours(3)->format('Y-m-d\TH:i'),
        ])
        ->assertRedirect()
        ->assertSessionHas('sukses');

    $p->refresh();

    expect($p->title)->toBe('Judul baru')
        ->and($p->nada)->toBe('penting')
        ->and($p->expires_at)->not->toBeNull();
});

it('menghapus pengumuman dari beranda siswa', function () {
    $p = EventAnnouncement::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $this->event->id,
        'title' => 'Hapus aku',
        'body' => 'Isi.',
    ]);

    $this->actingAs($this->admin)
        ->delete("/admin/events/{$this->event->id}/pengumuman/{$p->id}")
        ->assertRedirect()
        ->assertSessionHas('sukses');

    expect(EventAnnouncement::query()->find($p->id))->toBeNull();
});

it('tidak menemukan pengumuman milik event lain', function () {
    $lain = SchoolEvent::factory()->untukTahun($this->tahun)->create([
        'school_id' => $this->sekolah->id,
        'code' => 'EV-LAIN',
    ]);

    $p = EventAnnouncement::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $lain->id,
        'title' => 'Milik event lain',
        'body' => 'Isi.',
    ]);

    // 404, bukan 403: alasan yang sama dengan seluruh rute bersarang.
    $this->actingAs($this->admin)
        ->delete("/admin/events/{$this->event->id}/pengumuman/{$p->id}")
        ->assertNotFound();

    expect(EventAnnouncement::query()->find($p->id))->not->toBeNull();
});

it('tidak membocorkan pengumuman sekolah lain', function () {
    $tahunLain = AcademicYear::factory()->untukSekolah($this->lain)->aktif()->create();
    $eventLain = SchoolEvent::factory()->untukTahun($tahunLain)->create();

    EventAnnouncement::create([
        'school_id' => $this->lain->id,
        'event_id' => $eventLain->id,
        'title' => 'Rahasia sekolah lain',
        'body' => 'Tidak boleh terbaca.',
    ]);

    $props = $this->actingAs($this->admin)
        ->get("/admin/events/{$this->event->id}/pengumuman")
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['pengumuman'])->toBe([]);
});

it('menampilkan pengumuman event siswa di berandanya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    EventAnnouncement::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $data['event']->id,
        'title' => 'Bawa kartu peserta',
        'body' => 'Kartu peserta wajib dibawa setiap sesi.',
        'nada' => 'penting',
    ]);

    $props = masukUji($this, $data['siswa'][0])
        ->get('/siswa')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['pengumuman'])->toHaveCount(1)
        ->and($props['pengumuman'][0]['judul'])->toBe('Bawa kartu peserta')
        ->and($props['pengumuman'][0]['nada'])->toBe('penting');
});

it('tidak menampilkan pengumuman event yang bukan milik siswa', function () {
    // Event tempat anaknya terdaftar, dan event lain di sekolah yang SAMA.
    // Scope tenant meloloskan keduanya, jadi yang menahan hanya penyaringan
    // event di `pengumumanBerlaku()`.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $eventLain = SchoolEvent::factory()->untukTahun($this->tahun)->create([
        'school_id' => $this->sekolah->id,
        'code' => 'EV-BUKAN-MILIKKU',
    ]);

    EventAnnouncement::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $eventLain->id,
        'title' => 'Bukan untuk anak ini',
        'body' => 'Kelas lain.',
    ]);

    $props = masukUji($this, $data['siswa'][0])
        ->get('/siswa')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['pengumuman'])->toBe([]);
});

it('tidak menampilkan pengumuman yang sudah berakhir di beranda siswa', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    EventAnnouncement::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $data['event']->id,
        'title' => 'Sudah lewat',
        'body' => 'Kemarin.',
        'expires_at' => now()->subMinute(),
    ]);

    $props = masukUji($this, $data['siswa'][0])
        ->get('/siswa')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['pengumuman'])->toBe([]);
});
