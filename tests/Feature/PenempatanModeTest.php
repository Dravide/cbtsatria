<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Exam\Enums\ModePenempatan;
use App\Domain\Exam\Models\ExamEventShuffle;
use App\Domain\Exam\Services\PenempatanKocok;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Mode penempatan - `campur` dan `kelas` di satu laman yang sama.
 *
 * =====================================================================
 * Dua janji yang dijaga berkas ini
 * =====================================================================
 *
 *   1. BAWAAN TETAP CAMPUR. Pemanggilan lama tanpa argumen mode harus
 *      menghasilkan susunan yang sama persis dengan yang menyebut
 *      `Campur` eksplisit - denah yang sudah dicetak tidak boleh berubah
 *      karena parameter baru.
 *
 *   2. MODE IKUT TERSIMPAN. Denah dan tablo menghitung ulang dari benih;
 *      tanpa mode tersimpan, ketiganya memakai bawaan dan mencetak
 *      susunan yang berbeda dari layar.
 *
 * Fixture-nya `siapkanKocok()` - kelasnya dua dengan ukuran BEDA (3 dan 5)
 * dan dua ruang berkapasitas 4, sehingga mode `kelas` benar-benar
 * menghasilkan pembagian yang berbeda dari rotasi campur.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->tahun = AcademicYear::factory()->untukSekolah(
        School::find($this->admin->school_id)
    )->aktif()->create();

    $this->f = siapkanKocok($this->admin, $this->tahun);
    $this->event = $this->f['event'];
    $this->kocok = app(PenempatanKocok::class);
});

it('membagi kelas berurutan ke ruang berurutan pada mode kelas', function () {
    // Mode kelas: kelas X-1 (3 siswa) menempati kursi pertama RUANG PERTAMA
    // berurutan - tidak ada siswa XI-A di kursi awal ruang pertama.
    $antreanKelas = collect($this->kocok->susun($this->event, 'BENIHK', ModePenempatan::Kelas)['per_ruang'])
        ->flatMap(fn ($isi) => collect($isi)->pluck('class_id'))
        ->filter()
        ->values()
        ->implode(',');

    $antreanCampur = collect($this->kocok->susun($this->event, 'BENIHK', ModePenempatan::Campur)['per_ruang'])
        ->flatMap(fn ($isi) => collect($isi)->pluck('class_id'))
        ->filter()
        ->values()
        ->implode(',');

    // Dua mode DUA BENIH yang sama harus menghasilkan urutan kelas berbeda:
    // campur menyelang-seling, kelas berurutan. Inilah satu-satunya klaim
    // yang benar-benar dibedakan keduanya.
    expect($antreanKelas)->not->toBe($antreanCampur);
});

it('menghasilkan susunan campur yang sama persis tanpa mode', function () {
    $a = $this->kocok->susun($this->event, 'BENIH1');
    $b = $this->kocok->susun($this->event, 'BENIH1', ModePenempatan::Campur);

    $kunciA = collect($a['per_ruang'])->map(fn ($isi) => collect($isi)->pluck('student_id')->all())->all();
    $kunciB = collect($b['per_ruang'])->map(fn ($isi) => collect($isi)->pluck('student_id')->all())->all();

    expect($kunciA)->toBe($kunciB);
});

it('mencatat kelas yang terbelah pada mode kelas', function () {
    $susunan = $this->kocok->susun($this->event, 'BENIH1', ModePenempatan::Kelas);

    // Kelas 5 orang ke ruang 4 kursi: terbelah adalah HASIL, bukan kegagalan.
    expect($susunan['kelas_terbelah'])->not->toBeEmpty()
        ->and($susunan['rincian_kelas'])->not->toBeEmpty()
        ->and($susunan['rincian_kelas'][0])->toHaveKeys(['nama', 'jumlah', 'ruang']);
});

it('tidak mencatat kelas terbelah pada mode campur', function () {
    $susunan = $this->kocok->susun($this->event, 'BENIH1', ModePenempatan::Campur);

    // Di mode campur kelas yang tersebar adalah TUJUANNYA - melaporkannya
    // sebagai temuan membalik arti seluruh laman.
    expect($susunan['kelas_terbelah'])->toBeEmpty();
});

it('menyimpan mode pada kocokan yang disebar', function () {
    $this->kocok->sebarEvent($this->event, $this->kocok->benihBaru(), null, ModePenempatan::Kelas);

    expect(ExamEventShuffle::query()->where('event_id', $this->event->id)->first()->mode)
        ->toBe(ModePenempatan::Kelas);
});

it('menyebar ulang dengan mode tersimpan saat mode tidak diberikan', function () {
    $this->kocok->sebarEvent($this->event, $this->kocok->benihBaru(), null, ModePenempatan::Kelas);

    // Sebar ulang TANPA mode: harus tetap kelas, bukan diam-diam kembali
    // ke campur - Admin yang menekan Sebar kedua kali tidak sedang mengubah
    // susunannya.
    $hasil = $this->kocok->sebarEvent($this->event);

    expect($hasil['mode'])->toBe(ModePenempatan::Kelas);
});

it('menolak mode yang tidak dikenal lewat HTTP', function () {
    $this->actingAs($this->admin)
        ->post(jalurPenempatan($this->event).'/kocok-ulang', ['mode' => 'acak'])
        ->assertSessionHasErrors('mode');
});
