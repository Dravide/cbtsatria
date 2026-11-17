<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Exam\Services\PenempatanKocok;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Kursi ber-PC dan peserta bertanda "butuh perangkat" - wireframe-ruang-lab.html.
 *
 * =====================================================================
 * Model tiga jenis kursi, dan mengapa tidak butuh kolom baru
 * =====================================================================
 * Lab komputer bukan ruang yang seluruhnya ber-PC. `0 < device_count <
 * capacity` adalah lab campuran; `0` adalah BYOD; `= capacity` adalah lab
 * penuh PC. Tiga-tiganya dinyatakan oleh dua kolom yang sudah ada.
 *
 * Yang diuji di sini bukan rumusnya, melainkan AKIBATNYA: peserta bertanda
 * harus berakhir di kursi ber-PC, berapa pun mode penempatan yang dipakai.
 * Test yang hanya memeriksa `kursiBerPerangkat()` akan hijau walau
 * penempatannya tidak pernah memanggilnya.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.perangkat',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);
});

/** Event lengkap, dengan ruang ber-PC/campuran/BYOD sesuai permintaan. */
function eventPerangkat(
    School $sekolah,
    AcademicYear $tahun,
    Semester $semester,
    User $admin,
    array $spesifikasiRuang,
    int $jumlahSiswa,
    array $bertanda = [],
): array {
    $data = siapkanPresensi(
        $sekolah,
        $tahun,
        $semester,
        $admin,
        array_map(fn (array $r) => $r['kode'], $spesifikasiRuang),
        $jumlahSiswa,
    );

    $event = $data['event'];

    // Sesuaikan kapasitas dan jumlah PC tiap ruang sesuai permintaan.
    foreach ($spesifikasiRuang as $i => $spek) {
        $ruang = $data['ruang'][$i]->eventRoom;

        $ruang->room->forceFill([
            'capacity' => $spek['kapasitas'],
            'device_count' => $spek['pc'],
        ])->save();

        $ruang->forceFill(['capacity_override' => $spek['kapasitas']])->save();
    }

    // Tandai peserta yang butuh perangkat.
    if ($bertanda !== []) {
        $peserta = EventParticipant::query()
            ->where('event_id', $event->id)
            ->orderBy('id')
            ->get();

        foreach ($bertanda as $i) {
            $peserta[$i]->forceFill(['butuh_perangkat' => true])->save();
        }
    }

    return $data;
}

it('menghitung kursi ber-PC dari device_count, dibatasi kapasitas', function () {
    $data = eventPerangkat(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        [['kode' => 'LAB', 'kapasitas' => 36, 'pc' => 24]],
        4,
    );

    $ruang = $data['ruang'][0]->eventRoom->fresh(['room']);

    // Lab campuran: 36 kursi, 24 ber-PC.
    expect($ruang->kapasitasEfektif())->toBe(36)
        ->and($ruang->kursiBerPerangkat())->toBe(24)
        ->and($ruang->adaPerangkat())->toBeTrue();
});

it('membatasi kursi ber-PC pada kapasitas bila PC lebih banyak', function () {
    // Menimpa kapasitas menjadi lebih kecil daripada jumlah PC berarti
    // kursinya ikut berkurang: kursi yang tidak ada tidak bisa berisi PC.
    $data = eventPerangkat(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        [['kode' => 'LAB', 'kapasitas' => 10, 'pc' => 24]],
        2,
    );

    $ruang = $data['ruang'][0]->eventRoom->fresh(['room']);

    expect($ruang->kursiBerPerangkat())->toBe(10);
});

it('menyatakan ruang BYOD sebagai tanpa kursi ber-PC', function () {
    $data = eventPerangkat(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        [['kode' => 'R-01', 'kapasitas' => 32, 'pc' => 0]],
        2,
    );

    $ruang = $data['ruang'][0]->eventRoom->fresh(['room']);

    expect($ruang->kursiBerPerangkat())->toBe(0)
        ->and($ruang->adaPerangkat())->toBeFalse();
});

it('menempatkan peserta bertanda di kursi ber-PC, walau mode acak', function () {
    // Inti fiturnya. Tiga peserta bertanda dari enam; lab berkapasitas enam
    // dengan empat PC. Tanpa aturan perangkat, kocokan bebas bisa menaruh
    // peserta bertanda di kursi 5-6 yang tidak ber-PC.
    $data = eventPerangkat(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        [['kode' => 'LAB', 'kapasitas' => 6, 'pc' => 4]],
        6,
        [0, 2, 4],
    );

    $event = $data['event'];

    $susunan = app(PenempatanKocok::class)->susun($event, 'PERANGKAT1');

    $ruangId = $data['ruang'][0]->eventRoom->id;
    $isi = $susunan['per_ruang'][$ruangId];

    $bertanda = collect($isi)
        ->filter(fn (EventParticipant $p) => (bool) $p->butuh_perangkat)
        ->values();

    expect($bertanda)->toHaveCount(3);

    // Semua peserta bertanda berada di empat posisi pertama - yaitu kursi
    // yang ber-PC.
    $posisi = collect($isi)
        ->map(fn (EventParticipant $p, int $i) => [$i, (bool) $p->butuh_perangkat])
        ->filter(fn (array $pasang) => $pasang[1])
        ->pluck(0)
        ->all();

    expect($posisi)->each->toBeLessThan(4);
});

it('melaporkan peserta bertanda yang tidak kebagian PC, dengan NAMANYA', function () {
    // Lima bertanda, hanya dua kursi ber-PC. Tiga sisanya harus dilaporkan
    // dengan nama - "3 peserta" tidak menjawab pertanyaan "siapa".
    $data = eventPerangkat(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        [['kode' => 'LAB', 'kapasitas' => 6, 'pc' => 2]],
        6,
        [0, 1, 2, 3, 4],
    );

    $susunan = app(PenempatanKocok::class)->susun($data['event'], 'PERANGKAT2');

    expect($susunan['kursi_berperangkat'])->toBe(2)
        ->and($susunan['bertanda'])->toBe(5)
        ->and($susunan['perangkat_kurang'])->toHaveCount(3);

    // Setiap baris menyebut nama dan ruangnya.
    foreach ($susunan['perangkat_kurang'] as $kurang) {
        expect($kurang['nama'])->toBeString()->not->toBe('')
            ->and($kurang['ruang'])->toBeString()->not->toBe('');
    }
});

it('memindahkan peserta bertanda ke ruang lain yang masih punya kursi ber-PC', function () {
    // Ruang A punya 1 PC, Ruang B punya 3. Tiga peserta bertanda: satu muat
    // di A, dua sisanya harus PINDAH ke B - bukan dibiarkan di kursi tanpa
    // PC.
    $data = eventPerangkat(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        [
            ['kode' => 'A', 'kapasitas' => 3, 'pc' => 1],
            ['kode' => 'B', 'kapasitas' => 3, 'pc' => 3],
        ],
        6,
        [0, 1, 2],
    );

    $susunan = app(PenempatanKocok::class)->susun($data['event'], 'PERANGKAT3');

    expect($susunan['perangkat_kurang'])->toBe([]);

    $jumlahDiPc = 0;

    foreach ($susunan['per_ruang'] as $idRuang => $isi) {
        $ruang = EventRoom::query()->with('room:id,capacity,device_count')->find($idRuang);
        $jatah = $ruang->kursiBerPerangkat();

        foreach ($isi as $pos => $p) {
            if (! $p->butuh_perangkat) {
                continue;
            }

            expect($pos)->toBeLessThan($jatah);
            $jumlahDiPc++;
        }
    }

    // Seluruhnya dapat kursi ber-PC - tidak ada yang tertinggal.
    expect($jumlahDiPc)->toBe(3);
});

it('tidak mengubah apa pun bila tidak ada peserta bertanda', function () {
    // Event tanpa tanda harus berperilaku persis seperti sebelum fitur ini -
    // termasuk pada lab campuran. Aturan yang mengubah susunan meski tak ada
    // yang bertanda adalah aturan yang mengejutkan.
    $data = eventPerangkat(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        [['kode' => 'LAB', 'kapasitas' => 6, 'pc' => 4]],
        6,
    );

    $kocok = app(PenempatanKocok::class);

    $susunan = $kocok->susun($data['event'], 'TANPA-TANDA');

    expect($susunan['bertanda'])->toBe(0)
        ->and($susunan['perangkat_kurang'])->toBe([]);

    $ruangId = $data['ruang'][0]->eventRoom->id;

    expect($susunan['per_ruang'][$ruangId])->toHaveCount(6);
});

/* =====================================================================
 | Tanda dari Admin
 |===================================================================== */

it('menandai dan melepas kebutuhan perangkat lewat laman peserta', function () {
    $data = eventPerangkat(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        [['kode' => 'LAB', 'kapasitas' => 6, 'pc' => 4]],
        6,
    );

    $event = $data['event'];

    $peserta = EventParticipant::query()
        ->where('event_id', $event->id)
        ->orderBy('id')
        ->take(3)
        ->pluck('id')
        ->all();

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$event->id}/peserta/perangkat", [
            'id' => $peserta,
            'butuh' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(EventParticipant::query()->whereIn('id', $peserta)->where('butuh_perangkat', true)->count())
        ->toBe(3);

    // Lepas lagi - tanda harus bisa DIBATALKAN, karena anak yang membawa HP
    // tahun ini tidak boleh tetap tercatat butuh perangkat.
    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$event->id}/peserta/perangkat", [
            'id' => $peserta,
            'butuh' => 0,
        ])
        ->assertRedirect();

    expect(EventParticipant::query()->whereIn('id', $peserta)->where('butuh_perangkat', true)->count())
        ->toBe(0);
});

it('menolak menandai peserta milik event lain', function () {
    // Scope tenant tidak menolongnya: sekolahnya sama. Penyaringan `event_id`
    // di controller yang menutupnya.
    $satu = eventPerangkat(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        [['kode' => 'A', 'kapasitas' => 4, 'pc' => 2]],
        4,
    );

    $dua = eventPerangkat(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        [['kode' => 'B', 'kapasitas' => 4, 'pc' => 2]],
        4,
    );

    $asing = EventParticipant::query()
        ->where('event_id', $dua['event']->id)
        ->pluck('id')
        ->all();

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$satu['event']->id}/peserta/perangkat", [
            'id' => $asing,
            'butuh' => 1,
        ])
        ->assertRedirect();

    // Nol, bukan seluruhnya - penyaringannya bekerja.
    expect(EventParticipant::query()->whereIn('id', $asing)->where('butuh_perangkat', true)->count())
        ->toBe(0);
});

it('menolak peserta tanpa izin mengubah event', function () {
    $data = eventPerangkat(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        [['kode' => 'LAB', 'kapasitas' => 4, 'pc' => 2]],
        4,
    );

    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'guru.perangkat',
    ]);

    masukSebagai($this, $guru)
        ->post("/admin/events/{$data['event']->id}/peserta/perangkat", [
            'id' => [$data['event']->id],
            'butuh' => 1,
        ])
        ->assertForbidden();
});
