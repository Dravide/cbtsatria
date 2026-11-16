<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Exam\Enums\ModePenempatan;
use App\Domain\Exam\Models\ExamEventShuffle;
use App\Domain\Exam\Models\ExamParticipant;
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

it('TIDAK mencampur dua kelas dalam satu ruang pada mode kelas', function () {
    // Regresi 2026-11-16. Perilaku lama mengisi ruang SAMPAI PENUH lalu
    // pindah, tanpa peduli batas kelas - sehingga kelas kecil menambal sisa
    // kursi ruang pertama dan kelas berikutnya terbelah. Sekolah yang meminta
    // "berurutan per kelas" justru mendapat satu ruang berisi dua kelas.
    //
    // Fixture-nya sengaja MEMUAT: kelas 3 dan 4 di dua ruang berkapasitas 4.
    // Dengan ruang yang cukup, janjinya mutlak - satu ruang satu kelas.
    $f = siapkanKocok($this->admin, $this->tahun, 3, 4, 4, 2);

    $susunan = $this->kocok->susun($f['event'], 'BENIHK', ModePenempatan::Kelas);

    foreach ($susunan['per_ruang'] as $idRuang => $anggota) {
        $kelas = collect($anggota)->pluck('class_id')->unique()->values();

        expect($kelas)->toHaveCount(1, "Ruang {$idRuang} berisi ".$kelas->count().' kelas.');
    }

    // Dan tak ada satu pun kelas yang terbelah.
    expect($susunan['kelas_terbelah'])->toBeEmpty();
});

it('menempatkan tiap kelas di ruangnya sendiri tanpa memadatkan ke sisa kursi', function () {
    // Kelas 3 orang di ruang berkapasitas 4 harus MENINGGALKAN satu kursi
    // kosong, bukan diisi kelas lain. Itu konsekuensi yang sengaja dipilih:
    // kursi kosong lebih baik daripada lembar absen dua kelas.
    $f = siapkanKocok($this->admin, $this->tahun, 3, 4, 4, 2);

    $susunan = $this->kocok->susun($f['event'], 'BENIHK', ModePenempatan::Kelas);

    $pemilik = collect($susunan['per_ruang'])->map(
        fn ($anggota) => collect($anggota)->pluck('class_id')->unique()->first(),
    );

    // Kedua kelas menempati ruang yang BERBEDA.
    expect($pemilik->values()->unique())->toHaveCount(2);
    expect($pemilik->values()->all())
        ->toContain($f['kelas']['X-1'])
        ->toContain($f['kelas']['XI-A']);

    // Kelas 3 orang menempati tepat 3 kursi - bukan 4. Kursi keempat dibiarkan
    // kosong, dan itu yang membedakan mode ini dari mode campur.
    $ruangX1 = $pemilik->search($f['kelas']['X-1']);

    expect($susunan['per_ruang'][$ruangX1])->toHaveCount(3);
});

it('tidak menghilangkan peserta saat ruang cukup untuk memisahkan kelas', function () {
    // Janji yang paling tidak boleh dilanggar: mode `kelas` yang mengubah
    // susunan tidak boleh menendang seorang siswa keluar dari kursinya.
    // Fixture ini 7 peserta di 8 kursi - semuanya harus dapat tempat.
    $f = siapkanKocok($this->admin, $this->tahun, 3, 4, 4, 2);

    $susunan = $this->kocok->susun($f['event'], 'BENIHK', ModePenempatan::Kelas);

    $ditempatkan = collect($susunan['per_ruang'])->flatten()->count();

    expect($ditempatkan)->toBe($susunan['peserta'])
        ->and($susunan['kurang'])->toBe(0);
});

it('tetap memuat kelas yang lebih besar daripada satu ruang, dan melaporkannya terbelah', function () {
    // Batas yang tidak bisa dilampaui: kelas 5 di ruang berkapasitas 4 memang
    // HARUS memakai dua ruang. Yang penting bukan "tak pernah terbelah" -
    // itu mustahil - melainkan bahwa TIDAK ADA siswa yang kehilangan kursi,
    // dan bahwa pembelahannya disebutkan.
    $susunan = $this->kocok->susun($this->event, 'BENIHK', ModePenempatan::Kelas);

    $ditempatkan = collect($susunan['per_ruang'])->flatten()->count();

    expect($ditempatkan)->toBe($susunan['peserta'])
        ->and($susunan['kurang'])->toBe(0)
        ->and($susunan['kelas_terbelah'])->not->toBeEmpty();
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

/* =====================================================================
 | Mode `rata` - tiap kelas disebar merata ke semua ruang
 |===================================================================== */

it('membagi tiap kelas MERATA ke semua ruang pada mode rata', function () {
    // Empat kelas berisi 6 siswa, empat ruang berkapasitas 6. Mode `campur`
    // akan mengisi R-01 penuh lalu pindah, sehingga ruang terakhir tinggal
    // sisa. Mode `rata` harus menghasilkan 6/6/6/6 - dan tiap ruang berisi
    // empat kelas, bukan satu.
    $f = siapkanKocok($this->admin, $this->tahun, 6, 6, 6, 2);

    $susunan = $this->kocok->susun($f['event'], 'BENIHR', ModePenempatan::Rata);

    $isi = collect($susunan['per_ruang'])->map(fn ($a) => count($a))->values()->all();

    // Tidak ada ruang yang kosong, dan selisih isi antar ruang paling banyak
    // satu orang - itulah arti "rata".
    expect(min($isi))->toBeGreaterThan(0)
        ->and(max($isi) - min($isi))->toBeLessThanOrEqual(1);
});

it('mencampur beberapa kelas dalam satu ruang pada mode rata', function () {
    // Kebalikan dari mode `kelas`: di sini setiap ruang memang berisi lebih
    // dari satu kelas - itulah tujuannya.
    $f = siapkanKocok($this->admin, $this->tahun, 6, 6, 6, 2);

    $susunan = $this->kocok->susun($f['event'], 'BENIHR', ModePenempatan::Rata);

    $kelasPerRuang = collect($susunan['per_ruang'])
        ->map(fn ($anggota) => collect($anggota)->pluck('class_id')->unique()->count());

    expect($kelasPerRuang->max())->toBeGreaterThan(1);
});

it('tidak menghilangkan peserta pada mode rata', function () {
    $f = siapkanKocok($this->admin, $this->tahun, 6, 6, 6, 2);

    $susunan = $this->kocok->susun($f['event'], 'BENIHR', ModePenempatan::Rata);

    $ditempatkan = collect($susunan['per_ruang'])->flatten()->count();

    expect($ditempatkan)->toBe($susunan['peserta'])
        ->and($susunan['kurang'])->toBe(0);
});

it('tidak melaporkan kelas terbelah pada mode rata', function () {
    // Di mode `rata` kelas yang tersebar adalah TUJUANNYA - melaporkannya
    // sebagai temuan membalik arti seluruh laman.
    $f = siapkanKocok($this->admin, $this->tahun, 6, 6, 6, 2);

    $susunan = $this->kocok->susun($f['event'], 'BENIHR', ModePenempatan::Rata);

    expect($susunan['kelas_terbelah'])->toBeEmpty();
});

/* =====================================================================
 | Kunci penempatan
 |===================================================================== */

it('menolak sebar dan kocok ulang sesudah penempatan dikunci', function () {
    // Kunci menutup SELURUH jalur tulis kursi. Tanpa ini, satu tekanan tidak
    // sengaja pada "Sebar ulang" membuat daftar ruang yang sudah dicetak dan
    // ditempel di pintu tidak berlaku lagi, sementara layarnya terlihat benar.
    $this->kocok->sebarEvent($this->event, $this->kocok->benihBaru(), null, ModePenempatan::Kelas);

    $this->actingAs($this->admin)
        ->post(jalurPenempatan($this->event).'/kunci')
        ->assertSessionHas('sukses');

    expect($this->kocok->benihTersimpan($this->event)->terkunci())->toBeTrue();

    $this->actingAs($this->admin)
        ->post(jalurPenempatan($this->event), ['benih' => 'BARU11'])
        ->assertSessionHas('galat', fn (string $p) => str_contains($p, 'dikunci'));

    $this->actingAs($this->admin)
        ->post(jalurPenempatan($this->event).'/kocok-ulang', ['mode' => 'campur'])
        ->assertSessionHas('galat', fn (string $p) => str_contains($p, 'dikunci'));
});

it('TETAP mengizinkan Pakai penempatan sesudah dikunci', function () {
    // Regresi 2026-11-16. "Pakai penempatan" menerapkan peta ruang event yang
    // SUDAH ADA ke satu sesi - ia tidak mengocok benih baru dan tidak
    // memindahkan siswa. Siswa yang sama tetap di ruang yang sama; yang
    // berubah hanyalah sesi mana yang kursinya tercatat.
    //
    // Itu justru tindakan yang dibutuhkan SESUDAH dikunci: sesi yang baru
    // ditambahkan, atau sesi yang ruangnya baru diperbaiki, perlu memakai peta
    // yang sudah dibekukan. Menahannya membuat sesi baru tidak akan pernah bisa
    // diisi tanpa membuka kunci - padahal tidak ada satu kursi pun yang berubah.
    $this->kocok->sebarEvent($this->event, $this->kocok->benihBaru(), null, ModePenempatan::Kelas);

    $this->actingAs($this->admin)->post(jalurPenempatan($this->event).'/kunci');

    expect($this->kocok->benihTersimpan($this->event)->terkunci())->toBeTrue();

    // Kursi sesi dikosongkan lebih dulu, supaya "Pakai penempatan" benar-benar
    // MENULIS sesuatu - bukan sekadar menjawab sukses atas keadaan yang sudah
    // benar.
    ExamParticipant::query()
        ->where('exam_id', $this->f['ujian']->id)
        ->update(['exam_session_id' => null, 'exam_session_room_id' => null]);

    $this->actingAs($this->admin)
        ->post(jalurPenempatan($this->event).'/sesi/'.$this->f['sesi']->id, [
            'benih' => $this->kocok->benihTersimpan($this->event)->seed,
        ])
        ->assertSessionHas('sukses');

    expect(ExamParticipant::query()
        ->where('exam_id', $this->f['ujian']->id)
        ->whereNotNull('exam_session_room_id')
        ->count())->toBeGreaterThan(0);
});

it('membuka kunci dengan alasan, lalu mengizinkan sebar lagi', function () {
    $this->kocok->sebarEvent($this->event, $this->kocok->benihBaru(), null, ModePenempatan::Kelas);

    $this->actingAs($this->admin)->post(jalurPenempatan($this->event).'/kunci');

    // Alasan WAJIB: membuka kunci berarti mengizinkan daftar tercetak tidak
    // berlaku lagi, dan yang bertanya "mengapa kursi berubah" harus mendapat
    // jawaban dari log.
    $this->actingAs($this->admin)
        ->post(jalurPenempatan($this->event).'/buka-kunci', [])
        ->assertSessionHasErrors('alasan');

    $this->actingAs($this->admin)
        ->post(jalurPenempatan($this->event).'/buka-kunci', ['alasan' => 'Ruang R-02 rusak'])
        ->assertSessionHas('sukses');

    expect($this->kocok->benihTersimpan($this->event)->terkunci())->toBeFalse();

    $this->actingAs($this->admin)
        ->post(jalurPenempatan($this->event), ['benih' => 'BARU22'])
        ->assertSessionHas('sukses');
});

it('menolak mengunci penempatan yang belum pernah disebar', function () {
    $this->actingAs($this->admin)
        ->post(jalurPenempatan($this->event).'/kunci')
        ->assertSessionHas('galat', fn (string $p) => str_contains($p, 'Sebar'));
});

it('mengirim status kunci ke laman penempatan', function () {
    $this->kocok->sebarEvent($this->event, $this->kocok->benihBaru(), null, ModePenempatan::Kelas);
    $this->actingAs($this->admin)->post(jalurPenempatan($this->event).'/kunci');

    $this->actingAs($this->admin)
        ->get(jalurPenempatan($this->event))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Event/Penempatan')
            ->where('laman.dikunci', true)
            ->where('laman.dikunci_oleh', $this->admin->name)
            ->has('laman.mode_keterangan.rata')
        );
});

it('mengizinkan ganti mode SESUDAH disebar, lewat kocok ulang', function () {
    // Regresi 2026-11-16. Tombol mode dulu DIMATIKAN begitu kocokan disebar,
    // dan itu membuat mode tidak akan pernah bisa diganti sama sekali:
    // "Kocok ulang" membaca `mode` dari state yang tidak bisa diubah. Mode
    // ketiga (`rata`) karena itu tidak bisa dipilih pada event yang sudah
    // punya penempatan - tombolnya mati.
    //
    // Yang dijaga di sini adalah JALUR SERVERNYA: kocok ulang dengan mode
    // `rata` harus benar-benar tersimpan dan benar-benar mengubah susunan.
    $this->kocok->sebarEvent($this->event, 'BENIH1', null, ModePenempatan::Kelas);

    expect($this->kocok->benihTersimpan($this->event)->mode)->toBe(ModePenempatan::Kelas);

    $this->actingAs($this->admin)
        ->post(jalurPenempatan($this->event).'/kocok-ulang', ['mode' => ModePenempatan::Rata->value])
        ->assertSessionHas('sukses');

    expect($this->kocok->benihTersimpan($this->event)->mode)->toBe(ModePenempatan::Rata);
});

it('mengirim mode rata sebagai pilihan ketiga di laman penempatan', function () {
    $this->actingAs($this->admin)
        ->get(jalurPenempatan($this->event))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Event/Penempatan')
            ->where('laman.mode_pilihan.rata', 'Acak rata per kelas')
            ->where('laman.mode_pilihan.kelas', 'Berurutan per kelas')
            ->where('laman.mode_pilihan.campur', 'Campur antar kelas')
            ->has('laman.mode_keterangan.rata')
        );
});

it('memakai mode rata pada kocokan tersimpan saat mode tidak diberikan', function () {
    // Sebar ulang tanpa mode harus tetap `rata`, bukan diam-diam kembali ke
    // campur - Admin yang menekan Sebar kedua kali tidak sedang mengubah
    // susunannya.
    $this->kocok->sebarEvent($this->event, 'BENIH1', null, ModePenempatan::Rata);

    $hasil = $this->kocok->sebarEvent($this->event);

    expect($hasil['mode'])->toBe(ModePenempatan::Rata);
});
