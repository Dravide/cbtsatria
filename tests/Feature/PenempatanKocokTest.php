<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Exam\Models\ExamEventShuffle;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\PenempatanKocok;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Inertia\Testing\AssertableInertia;

/**
 * Penempatan peserta ke ruang dengan kocokan berbenih - PRD §9.5, plate 16b.
 *
 * ---------------------------------------------------------------------
 * SATU peta untuk seluruh event
 * ---------------------------------------------------------------------
 * Keputusan yang diuji di sini dibalik pada 07 Nov 2026: ruang siswa berlaku
 * sepanjang event, bukan berganti tiap sesi. Pertanyaan "anak saya di ruang
 * berapa?" dijawab sekali, dan jawabannya berlaku sampai ujian selesai.
 *
 * Yang dibayar sebagai gantinya adalah aturan yang harus diuji terpisah:
 * setiap sesi wajib memakai SELURUH ruang event. Sesi yang ruangnya kurang
 * membatalkan penyebaran seluruhnya - dan test "menolak menyebar bila satu
 * sesi tidak memakai seluruh ruang event" adalah yang menjaganya, karena
 * pelanggarannya tidak menghasilkan galat apa pun: hasilnya siswa yang
 * "sudah ditempatkan" tetapi tidak muncul di daftar ruang mana pun.
 *
 * ---------------------------------------------------------------------
 * Dua janji yang bisa dilanggar tanpa satu pun galat muncul
 * ---------------------------------------------------------------------
 *   1. "Siswa diacak masuk tiap ruangan, campur semua tingkatan." Yang
 *      membuktikannya BUKAN bahwa satu kocokan kebetulan menghasilkan ruang
 *      campuran - itu bisa terjadi meski tanpa aturan apa pun. Yang
 *      membuktikannya adalah SEPULUH benih berbeda, dan tidak satu pun
 *      menghasilkan ruang seisi satu kelas.
 *
 *   2. "Daftar ruang yang sudah dicetak masih berlaku." Yang membuktikannya
 *      adalah benih yang sama menghasilkan kursi yang sama, DAN kursi yang
 *      benar-benar tersimpan sama persis dengan yang ditampilkan layar.
 *      Kalau keduanya dihitung dengan cara yang berbeda, perselisihannya baru
 *      ketahuan saat siswa berdiri di depan pintu ruang yang salah.
 *
 * ---------------------------------------------------------------------
 * Mengapa eventnya disusun sendiri, bukan lewat `siapkanEvent()` di ExamTest
 * ---------------------------------------------------------------------
 * Helper itu membuat SATU kelas berisi empat siswa. Dengan satu kelas, rotasi
 * dan kocok rata menghasilkan hal yang sama - sehingga test lulus tanpa
 * membuktikan apa pun tentang campuran. Di sini kelasnya dua dan ukurannya
 * sengaja BEDA (3 dan 5): dengan ukuran yang sama, urutan rotasi tidak pernah
 * terlihat pengaruhnya.
 *
 * Kelasnya juga sengaja berukuran lebih kecil daripada kapasitas ruang, supaya
 * pembagiannya benar-benar terjadi di dua ruang. Dengan satu ruang penuh dan
 * satu ruang kosong, pemeriksaan campuran tidak berarti apa-apa.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.kocok',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);
});

/* ---------------------------------------------------------- Kocok berbenih */

it('menghasilkan kursi yang sama dari benih yang sama', function () {
    // Inilah janji yang membuat daftar ruang yang dicetak bisa dipercaya.
    // Tanpa ini, membuka laman penempatan dua kali menghasilkan dua susunan
    // yang berbeda - dan Admin yang mencetak daftarnya pagi menemukan susunan
    // lain sore harinya, tanpa satu pun galat yang memberi tahu.
    $d = siapkanKocok($this->admin, $this->tahun);
    $kocok = app(PenempatanKocok::class);

    $satu = $kocok->susun($d['event'], 'BENIH1')['antrean']->pluck('id')->all();
    $dua = $kocok->susun($d['event'], 'BENIH1')['antrean']->pluck('id')->all();

    expect($satu)->toBe($dua)
        ->and($satu)->toHaveCount(8);
});

it('menghasilkan susunan berbeda dari benih yang berbeda', function () {
    // Pasangan dari test di atas, dan yang membuatnya berarti: kalau SEMUA
    // benih menghasilkan urutan yang sama, test pertama lulus tanpa
    // membuktikan apa pun tentang benihnya.
    $d = siapkanKocok($this->admin, $this->tahun);
    $kocok = app(PenempatanKocok::class);

    $satu = $kocok->susun($d['event'], 'BENIH1')['antrean']->pluck('id')->all();
    $dua = $kocok->susun($d['event'], 'BENIH2')['antrean']->pluck('id')->all();

    expect($satu)->not->toBe($dua);
});

it('mengembalikan generator acak global setelah mengocok', function () {
    // Buktinya bukan "angkanya kelihatan acak", melainkan perbandingan dua
    // tarikan SESUDAH dua kocokan berbenih sama. Bila benihnya tidak
    // dikembalikan, `mt_srand($benih)` di dalam kocokan men-start ulang
    // generatornya, dan kedua tarikan itu pasti identik: benih yang sama,
    // jumlah angka yang dikonsumsi sama.
    //
    // Kalau itu terjadi, permintaan BERIKUTNYA di proses yang sama - kode
    // pengawas, token sesi - ikut memakai urutan yang bisa dihitung dari satu
    // benih yang kebetulan terlihat di layar.
    $d = siapkanKocok($this->admin, $this->tahun);
    $kocok = app(PenempatanKocok::class);

    $kocok->susun($d['event'], 'BENIH1');
    $sesudahPertama = tarikanAcak(5);

    $kocok->susun($d['event'], 'BENIH1');
    $sesudahKedua = tarikanAcak(5);

    // Dikembalikan ke keadaan acak supaya test berikutnya tidak mewarisi
    // urutan dari test ini.
    mt_srand();

    expect($sesudahPertama)->not->toBe($sesudahKedua);
});

/* -------------------------------------------------------- Campur tingkatan */

it('merotasi peserta antar kelas sehingga tidak ada ruang seisi satu kelas', function () {
    // Inti permintaannya: siswa diacak masuk tiap ruangan, campur semua
    // tingkatan. Kocok rata TIDAK menjamin ini - ia hanya membuatnya MUNGKIN,
    // dan pada sekolah dengan tiga kelas besar, ruang seisi satu kelas adalah
    // kemungkinan yang nyata. Karena itu yang dibuktikan di sini adalah
    // ATURANNYA: sepuluh benih berbeda, dan tidak satu pun boleh melanggarnya.
    $d = siapkanKocok($this->admin, $this->tahun);
    $kocok = app(PenempatanKocok::class);

    $benih = ['AAAAAA', 'BBBBBB', 'CCCCCC', 'DDDDDD', 'EEEEEE', 'FFFFFF', 'GGGGGG', 'HHHHHH', 'JJJJJJ', 'KKKKKK'];

    foreach ($benih as $b) {
        $susunan = $kocok->susun($d['event'], $b);

        expect($susunan['seisi_satu_kelas'])
            ->toBe([], "benih {$b} menghasilkan ruang seisi satu kelas");

        // Dan pembagiannya memang terjadi: dua ruang terisi, bukan satu ruang
        // penuh dan satu ruang kosong - keadaan yang membuat pemeriksaan
        // campuran di atas tidak membuktikan apa-apa.
        expect(collect($susunan['per_ruang'])->filter(fn (array $a) => $a !== [])->count())
            ->toBe(2, "benih {$b} tidak membagi peserta ke dua ruang");
    }
});

it('memakai setiap peserta tepat sekali', function () {
    // Rotasi yang salah bisa menggandakan atau menjatuhkan orang, dan keduanya
    // terlihat sama di layar: cacahnya bisa saja benar sementara namanya salah.
    //
    // Yang dihitung adalah SELURUH peserta event, bukan peserta ujian: peta ini
    // berlaku untuk event, sehingga siswa yang hanya ikut satu mapel pun sudah
    // punya kursi sejak mapel pertama.
    $d = siapkanKocok($this->admin, $this->tahun);

    $susunan = app(PenempatanKocok::class)->susun($d['event'], 'SEKALI1');

    $id = $susunan['antrean']->pluck('id')->all();

    expect($id)->toHaveCount(8)
        ->and(array_unique($id))->toHaveCount(8)
        ->and($id)->toEqualCanonicalizing(
            $d['event']->participants()->pluck('id')->all(),
        );
});

it('melaporkan ruang seisi satu kelas lewat kode ruangnya, bukan true', function () {
    // Nilainya dipakai langsung di layar sebagai "R-04 · seisi XII-3". `true`
    // yang lolos ke sana akan tampil di depan Admin sebagai "1 · seisi 1".
    $d = siapkanKocok($this->admin, $this->tahun);
    $kocok = app(PenempatanKocok::class);
    $ruangan = $kocok->ruanganEvent($d['event']);
    $pertama = $ruangan->first();

    $satuKelas = $d['event']->participants()
        ->where('class_id', $d['kelas']['X-1'])
        ->get();

    expect($kocok->seisiSatuKelas([$pertama->id => $satuKelas->all()], $ruangan))
        ->toBe([$pertama->id => $pertama->room->code])
        // Dan ruang berisi satu orang TIDAK dihitung: melaporkannya berarti
        // setiap pratinjau pada event yang belum lengkap berbunyi seperti
        // kegagalan.
        ->and($kocok->seisiSatuKelas([$pertama->id => [$satuKelas->first()]], $ruangan))
        ->toBe([]);
});

/* ------------------------------------------------------ Menyebar sungguhan */

it('menyebar persis seperti yang ditampilkan pratinjau', function () {
    // Janji yang paling mahal bila dilanggar. Keduanya dihitung dari benih
    // yang sama, dan test ini yang membuktikan bahwa cara menghitungnya memang
    // sama - bukan hanya bahwa keduanya "kelihatannya acak".
    $d = siapkanKocok($this->admin, $this->tahun);
    $kocok = app(PenempatanKocok::class);

    masukSebagai($this, $this->admin)
        ->post(jalurPenempatan($d['event']), ['benih' => 'COCOK1'])
        ->assertRedirect();

    $susunan = $kocok->susun($d['event'], 'COCOK1');

    expect(petaTersimpan($d['ujian']))
        ->toBe(petaKocokanSesi($kocok, $d['event'], $d['sesi'], $susunan['per_ruang']));

    $jejak = ExamEventShuffle::query()->where('event_id', $d['event']->id)->first();

    expect($jejak)->not->toBeNull()
        ->and($jejak->seed)->toBe('COCOK1')
        ->and($jejak->peserta)->toBe(8)
        ->and($jejak->shuffled_by)->toBe($this->admin->id);
});

it('menarik peserta lebih dulu, sehingga sebar tidak menulis nol kursi', function () {
    // Bug yang ditemukan 07 Nov 2026 di layar siswa: event yang jadwalnya
    // lengkap dan sudah "disebar" tetap membalas "Belum ada ujian yang
    // ditempatkan untuk Anda".
    //
    // Sebabnya penarikan peserta hanya dikerjakan `ExamJadwalController::sebar()`,
    // sedangkan tombol "Sebar peserta" di laman Penempatan memanggil
    // `sebarEvent()` langsung. Karena `petaSesi()` hanya bisa memetakan peserta
    // ujian yang barisnya sudah ada, penyebaran itu melaporkan "8 peserta
    // disebar" sambil menulis NOL baris - dan tidak satu pun galat muncul.
    //
    // Helper `siapkanKocok()` memanggil `tarikPeserta()` sendiri, dan itulah
    // yang menyembunyikan bug ini dari seluruh test di berkas ini. Test ini
    // menghapus barisnya lebih dulu supaya keadaannya sama dengan event yang
    // jadwalnya baru disusun Admin dan belum pernah lewat laman Jadwal.
    $d = siapkanKocok($this->admin, $this->tahun);

    ExamParticipant::query()->where('exam_id', $d['ujian']->id)->delete();

    expect(ExamParticipant::query()->where('exam_id', $d['ujian']->id)->count())->toBe(0);

    masukSebagai($this, $this->admin)
        ->post(jalurPenempatan($d['event']), ['benih' => 'TARIK1'])
        ->assertRedirect();

    // Delapan peserta event ada, dan kedelapan-delapannya mendapat kursi.
    expect(ExamParticipant::query()->where('exam_id', $d['ujian']->id)->count())->toBe(8)
        ->and(ExamParticipant::query()->where('exam_id', $d['ujian']->id)
            ->whereNull('exam_session_room_id')->count())->toBe(0);

    // Dan yang menentukan bagi siswa: `JadwalSiswaController` membaca
    // `exam_participants.student_id`. Seorang siswa dari event itu harus
    // benar-benar menemukan barisnya - bukan sekadar "ada delapan baris".
    $siswa = ExamParticipant::query()->where('exam_id', $d['ujian']->id)->first()->student_id;

    expect(ExamParticipant::query()
        ->where('student_id', $siswa)
        ->where('exam_id', $d['ujian']->id)
        ->whereNotNull('exam_session_id')
        ->exists())->toBeTrue();
});

it('memberi satu ruang yang sama pada setiap sesi', function () {
    // Inti keputusan per-event: yang berpindah antar mapel hanyalah ID baris
    // ruangnya - "R-01 pada Matematika" dan "R-01 pada Bahasa Indonesia" adalah
    // dua baris berbeda - sementara KODE ruang yang dibaca siswa tetap sama.
    //
    // Yang dibandingkan karenanya KODE ruang, bukan id-nya. Test yang
    // membandingkan id akan lulus meski petanya berbeda per sesi, karena id-nya
    // memang selalu berbeda.
    //
    // Ujiannya dua, masing-masing satu sesi: satu ujian dengan dua sesi ditolak
    // oleh `sebarEvent()` - lihat test "menolak ujian yang punya lebih dari satu
    // sesi". Dua mapel adalah bentuk yang nyata; sesi susulan tidak, karena
    // peta event tidak tahu siswa mana yang duduk di sesi mana.
    $d = siapkanKocok($this->admin, $this->tahun);

    [$ujianKedua, $sesiKedua] = ujianTambahan($this->admin, $d['event'], 'Kedua');

    masukSebagai($this, $this->admin)
        ->post(jalurPenempatan($d['event']), ['benih' => 'SATU11'])
        ->assertRedirect();

    $kodeRuang = fn (?int $idRuangSesi): string => ExamSessionRoom::query()
        ->find($idRuangSesi)?->eventRoom?->room?->code ?? '?';

    $ruangSiswa = function (int $idUjian) use ($kodeRuang): array {
        return ExamParticipant::query()
            ->where('exam_id', $idUjian)
            ->whereNotNull('exam_session_room_id')
            ->get()
            ->mapWithKeys(fn (ExamParticipant $p) => [$p->student_id => $kodeRuang($p->exam_session_room_id)])
            ->all();
    };

    $pertama = $ruangSiswa($d['ujian']->id);
    $kedua = $ruangSiswa($ujianKedua->id);

    // Kedua sesi terisi seluruhnya, dan tidak ada seorang pun yang jatuh.
    expect($pertama)->toHaveCount(8)
        ->and($kedua)->toHaveCount(8)
        // Dan setiap siswa memegang KODE ruang yang sama di kedua mapel - itu
        // seluruh janjinya.
        ->and($kedua)->toBe($pertama);

    // Sesi yang benar-benar dipakai memang dua, bukan satu yang tertimpa.
    expect(ExamParticipant::query()->where('exam_id', $d['ujian']->id)
        ->pluck('exam_session_id')->unique()->all())->toBe([$d['sesi']->id])
        ->and(ExamParticipant::query()->where('exam_id', $ujianKedua->id)
            ->pluck('exam_session_id')->unique()->all())->toBe([$sesiKedua->id]);
});

it('menolak ujian yang punya lebih dari satu sesi', function () {
    // Satu baris `exam_participants` hanya bisa menunjuk SATU sesi. Peta event
    // tidak tahu siswa mana yang duduk di sesi susulan, jadi menyebarkannya ke
    // kedua sesi akan menulis kursi yang sama berulang kali - dan yang terakhir
    // menang, diam-diam. Siapa yang ada di sesi mana lalu ditentukan urutan
    // query, bukan keputusan Admin.
    //
    // Lebih baik ditolak dengan sebabnya daripada menghasilkan penempatan yang
    // tampak berhasil.
    $d = siapkanKocok($this->admin, $this->tahun);

    app(SesiService::class)->buat($d['ujian'], [
        'session_number' => 2,
        'access_opens_at' => '2026-12-02 07:30:00',
        'access_closes_at' => '2026-12-02 09:00:00',
        'hard_end_at' => '2026-12-02 09:30:00',
        'event_room_id' => $d['event']->rooms()->pluck('id')->all(),
    ]);

    masukSebagai($this, $this->admin)
        ->post(jalurPenempatan($d['event']), ['benih' => 'DUASES'])
        ->assertSessionHas('galat');

    expect(ExamParticipant::query()->where('exam_id', $d['ujian']->id)
        ->whereNull('exam_session_room_id')->count())->toBe(8)
        ->and(ExamEventShuffle::query()->where('event_id', $d['event']->id)->count())->toBe(0);
});

it('mengunci kocokan sehingga menyebar ulang tidak memindahkan seorang pun', function () {
    // Yang membuat tombol "Sebar" aman ditekan dua kali, dan yang membuat
    // halaman boleh dibuka ulang tanpa membatalkan daftar yang sudah dicetak.
    //
    // Benih yang dikirim kedua kali adalah benih yang sama dengan yang
    // ditampilkan layar. Yang membuatnya tetap kursi yang sama adalah
    // `sebarEvent()` yang jatuh ke benih tersimpan ketika diberi `null` - dan
    // itulah yang diuji test setelah ini.
    $d = siapkanKocok($this->admin, $this->tahun);
    $url = jalurPenempatan($d['event']);

    masukSebagai($this, $this->admin)->post($url, ['benih' => 'TETAP1']);

    $sebelum = petaTersimpan($d['ujian']);

    masukSebagai($this, $this->admin)->post($url, ['benih' => 'TETAP1']);

    expect(petaTersimpan($d['ujian']))->toBe($sebelum);

    masukSebagai($this, $this->admin)->post($url, []);

    expect(petaTersimpan($d['ujian']))->toBe($sebelum);
});

it('mengganti benih saat dikocok ulang, dan tetap satu baris per event', function () {
    // Kocok ulang MENGGANTI, bukan menambah. Dua baris untuk satu event berarti
    // dua kebenaran tentang kursi yang sama - dan yang dibaca layar adalah
    // baris yang kebetulan lebih dulu.
    //
    // Yang sengaja TIDAK dituntut di sini: bahwa pesertanya benar-benar
    // berpindah. Itu bergantung pada benih acak yang baru, sehingga test yang
    // menuntutnya gagal kira-kira satu dari tiga puluh kali. Yang dituntut
    // sebagai gantinya adalah kursi yang tersimpan tetap sama dengan hasil
    // hitung dari benih yang tersimpan - syarat yang membuat perpindahan itu
    // bisa dipercaya ketika ia memang terjadi.
    $d = siapkanKocok($this->admin, $this->tahun);

    masukSebagai($this, $this->admin)
        ->post(jalurPenempatan($d['event']), ['benih' => 'AWAL11']);

    masukSebagai($this, $this->admin)
        ->post(jalurPenempatan($d['event']).'/kocok-ulang')
        ->assertRedirect();

    $jejak = ExamEventShuffle::query()->where('event_id', $d['event']->id)->get();

    expect($jejak)->toHaveCount(1)
        ->and($jejak->first()->seed)->not->toBe('AWAL11');

    $kocok = app(PenempatanKocok::class);
    $susunan = $kocok->susun($d['event'], $jejak->first()->seed);

    expect(petaTersimpan($d['ujian']))
        ->toBe(petaKocokanSesi($kocok, $d['event'], $d['sesi'], $susunan['per_ruang']));
});

/* ------------------------------------------------------------------- Layar */

it('menghitung terisi dari peta EVENT, bukan dari peta sesi', function () {
    // Dibaca terbalik dari versi sebelumnya, dan itu keputusan 09 Nov 2026.
    //
    // Versi 09 Nov pagi menghitung `terisi` dari peta SESI supaya angkanya
    // cocok dengan laman denah waktu itu. Denahnya sendiri kemudian dibetulkan:
    // yang digambarnya adalah kursi yang tidak berpindah antar sesi, sehingga
    // "penghuni di sesi ini" bukan angka yang berlaku sepanjang ujian - pada
    // ujian yang diikuti sebagian kelas, ruang berkapasitas 35 terbaca
    // sebelas, lalu berganti lagi saat mapel berikutnya dibuka.
    //
    // Fixture-nya sengaja begini: ujiannya hanya untuk SATU kelas dari dua,
    // sehingga selisih antara "peserta ujian ini" (3) dan "penghuni ruang"
    // (8) benar-benar ada. Tanpa selisih itu, test ini lulus walau `terisi`
    // dihitung dari peta mana pun.
    $d = siapkanKocok($this->admin, $this->tahun);

    ujianSatuKelas($this->admin, $d['event'], $d['kelas']['X-1'], 'Sempit');

    masukSebagai($this, $this->admin)
        ->get(jalurPenempatan($d['event']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('pratinjau.ruang', 2)
            ->where('pratinjau.ruang', fn ($ruang) => collect($ruang)->sum('terisi') === 8
                && collect($ruang)->sum('terisi_event') === 8
                && collect($ruang)->sum(fn (array $r) => count($r['peserta'])) === 8
            )
        );
});

it('menyebut angka ruang yang sama dengan laman denah', function () {
    // Inti laporan 09 Nov 2026: dua laman, satu ruang, dua angka. Yang
    // mengikat adalah peta EVENT - kursi anak tidak berpindah antar sesi -
    // jadi denah dan pratinjau harus membaca himpunan yang sama. Ujian yang
    // hanya diikuti satu kelas dipakai justru karena di situlah keduanya dulu
    // bercerai: peta sesi menyebut 3, peta event menyebut 8.
    $d = siapkanKocok($this->admin, $this->tahun);

    $sesiSempit = ujianSatuKelas($this->admin, $d['event'], $d['kelas']['X-1'], 'Sempit');

    masukSebagai($this, $this->admin)
        ->post(jalurPenempatan($d['event']), ['benih' => 'SEMPIT'])
        ->assertRedirect();

    $props = masukSebagai($this, $this->admin)
        ->get(jalurPenempatan($d['event']))
        ->viewData('page')['props'];

    $propsDenah = masukSebagai($this, $this->admin)
        ->get(jalurPenempatan($d['event']).'/denah')
        ->viewData('page')['props'];

    $dariDenah = collect($propsDenah['daftar_ruang'])->keyBy('id');

    foreach ($props['pratinjau']['ruang'] as $r) {
        expect($r['terisi'])->toBe($dariDenah[$r['id']]['terisi']);
    }

    // Dan yang tersimpan untuk ujian sempit itu memang tiga orang - bukan
    // delapan. Keduanya benar sekaligus: `exam_session_room_id` menunjuk tiga
    // peserta ujian itu, sementara ruangnya dihuni delapan orang event.
    expect(ExamParticipant::query()
        ->where('exam_id', $sesiSempit->exam_id)
        ->whereNotNull('exam_session_room_id')
        ->count())->toBe(3);
});

it('mengirim pratinjau berisi nama dan kelas setiap peserta', function () {
    // Pratinjau yang hanya berisi "4/4" tidak membuktikan apa pun tentang
    // campurannya - dan campuran itulah yang diminta. Karena itu namanya
    // dikirim UTUH.
    $d = siapkanKocok($this->admin, $this->tahun);

    masukSebagai($this, $this->admin)
        ->get(jalurPenempatan($d['event']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Penempatan')
            ->has('baris', 1)
            ->where('baris.0.peserta', 8)
            ->where('baris.0.ruang', 2)
            ->where('baris.0.bisa', true)
            ->where('baris.0.sebab', null)
            ->where('laman.terkunci', false)
            ->where('laman.peserta', 8)
            ->where('laman.kapasitas', 8)
            ->where('laman.kurang', 0)
            ->where('laman.ruang', 2)
            ->where('laman.seisi_satu_kelas', 0)
            ->where('laman.bisa_sebar', true)
            ->has('pratinjau.ruang', 2)
            ->has('pratinjau.ruang.0.peserta', 4)
            ->has('pratinjau.ruang.0.peserta.0.nama')
            ->has('pratinjau.ruang.0.peserta.0.kelas')
            ->where('pratinjau.ruang.0.dipakai_sesi', true)
            ->where('pratinjau.benih', fn ($b) => is_string($b) && strlen($b) === 6)
            ->where('angka.peserta_event', 8)
            ->where('angka.sesi', 1)
            ->where('angka.bisa', 1)
        );
});

it('mengirim satu peta ruang yang sama, sesi mana pun yang diminta', function () {
    // Sampai 2026-11-09 laman ini punya pemilih sesi: `?sesi=` memilih sesi
    // yang pratinjaunya ditampilkan. Pilihan itu dibuang bersama bentuknya,
    // karena yang ditanyakan Admin adalah "anak saya di ruang berapa" - dan
    // jawabannya satu untuk seluruh ujian.
    //
    // Test ini menjaga supaya `?sesi=` tidak diam-diam kembali berpengaruh:
    // dua permintaan dengan sesi berbeda harus menghasilkan peta yang SAMA
    // PERSIS. Yang tetap per sesi adalah KELAYAKAN di tabel bawah - dan itu
    // memang pertanyaan yang berbeda.
    $d = siapkanKocok($this->admin, $this->tahun);

    [$ujianKedua, $sesiKedua] = ujianTambahan($this->admin, $d['event'], 'Kedua');

    // Disebar dulu dengan benih tetap. Tanpa itu setiap muat membuat benih
    // BARU - dan dua permintaan yang berbeda benihnya memang menghasilkan dua
    // peta yang berbeda, sehingga yang diuji jadi kebetulan generatornya,
    // bukan pengaruh `?sesi=`.
    masukSebagai($this, $this->admin)
        ->post(jalurPenempatan($d['event']), ['benih' => 'PETA11'])
        ->assertRedirect();

    $tanpaSesi = masukSebagai($this, $this->admin)
        ->get(jalurPenempatan($d['event']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Penempatan')
            ->has('baris', 2)
            ->where('pratinjau.sesi_id', null)
            ->where('pratinjau.label', 'seluruh event')
            ->where('pratinjau.benih', 'PETA11')
            ->has('pratinjau.ruang', 2)
        )
        ->viewData('page')['props'];

    $denganSesi = masukSebagai($this, $this->admin)
        ->get(jalurPenempatan($d['event'])."?sesi={$sesiKedua->id}")
        ->assertOk()
        ->viewData('page')['props'];

    expect($denganSesi['pratinjau']['ruang'])->toBe($tanpaSesi['pratinjau']['ruang'])
        ->and($denganSesi['pratinjau']['sesi_id'])->toBeNull();

    expect($ujianKedua->id)->not->toBe($d['ujian']->id);
});

it('menandai event yang sudah dikocok sebagai terkunci', function () {
    $d = siapkanKocok($this->admin, $this->tahun);

    masukSebagai($this, $this->admin)
        ->post(jalurPenempatan($d['event']), ['benih' => 'KUNCI1']);

    masukSebagai($this, $this->admin)
        ->get(jalurPenempatan($d['event']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('laman.terkunci', true)
            ->where('laman.benih', 'KUNCI1')
            ->where('laman.dikocok_oleh', $this->admin->name)
            ->where('laman.dikocok_pada', fn ($p) => is_string($p) && $p !== '')
        );
});

/* -------------------------------------------------------------- Kapasitas */

it('melaporkan ujian yang sesinya tidak memakai seluruh ruang event, tanpa menggagalkan ujian lain', function () {
    // Satu sesi yang ruangnya kurang adalah keadaan yang Admin perbaiki di
    // laman jadwal - bukan kerusakan aplikasi. Laman yang gagal seluruhnya
    // karena satu sesi membuat Admin menebak sesi mana yang bermasalah.
    //
    // Sebabnya disebut lengkap: "ruang event tidak dipakai sesi ini" DAN
    // berapa peserta yang jatuh karenanya. Menyebut salah satunya saja membuat
    // Admin memperbaiki ruangnya tanpa tahu berapa orang yang kehilangan kursi.
    $d = siapkanKocok($this->admin, $this->tahun);
    $ruangEvent = $d['event']->rooms()->orderBy('id')->pluck('id')->all();

    [, $sesiSempit] = ujianTambahan($this->admin, $d['event'], 'Kedua', [$ruangEvent[0]]);

    masukSebagai($this, $this->admin)
        ->get(jalurPenempatan($d['event']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('baris', 2)
            ->where('baris.0.bisa', true)
            ->where('baris.1.bisa', false)
            ->where('baris.1.sebab', fn ($s) => is_string($s)
                && str_contains($s, 'tidak dipakai sesi ini')
                && str_contains($s, 'peserta tanpa kursi'))
            ->where('laman.bisa_sebar', false)
            ->where('angka.bisa', 1)
        );

    expect($sesiSempit->id)->not->toBe($d['sesi']->id);
});

it('menolak menyebar bila satu sesi tidak memakai seluruh ruang event', function () {
    // Test yang menjaga keputusan per-event. Pelanggarannya TIDAK menghasilkan
    // galat apa pun bila dibiarkan: hasilnya siswa yang sudah punya
    // `exam_session_id` tetapi tidak muncul di daftar ruang mana pun, dan yang
    // menemukannya adalah pengawas saat siswa berdiri di depan pintu dengan
    // daftar yang tidak memuat namanya.
    //
    // Karena itu penyebarannya ditolak SELURUHNYA - bukan diterapkan pada sesi
    // yang bisa sambil melewati yang tidak. Termasuk pada sesi yang SEHAT:
    // itulah bedanya dengan penyebaran yang diterapkan sebagian.
    $d = siapkanKocok($this->admin, $this->tahun);
    $ruangEvent = $d['event']->rooms()->orderBy('id')->pluck('id')->all();

    [$ujianKedua] = ujianTambahan($this->admin, $d['event'], 'Kedua', [$ruangEvent[0]]);

    masukSebagai($this, $this->admin)
        ->post(jalurPenempatan($d['event']), ['benih' => 'SATU22'])
        ->assertSessionHas('galat');

    expect(ExamParticipant::query()->where('exam_id', $d['ujian']->id)
        ->whereNull('exam_session_room_id')->count())->toBe(8)
        ->and(ExamParticipant::query()->where('exam_id', $ujianKedua->id)
            ->whereNull('exam_session_room_id')->count())->toBe(8)
        // Dan tidak ada satu baris kocokan pun: penolakannya sebelum satu baris
        // ditulis.
        ->and(ExamEventShuffle::query()->where('event_id', $d['event']->id)->count())->toBe(0);
});

it('menolak sesi yang kapasitasnya lebih sempit daripada ruang eventnya', function () {
    // Sesi boleh menimpa kapasitas ruangnya. Bila penimpaan itu membuat
    // petanya tidak muat, sesi itulah yang bermasalah - bukan petanya, dan
    // bukan seluruh event. Angka yang menentukan tetap kapasitas ruang EVENT,
    // karena itulah yang membentuk petanya.
    $d = siapkanKocok($this->admin, $this->tahun);

    $d['ruang']->first()
        ->forceFill(['capacity_override' => 1])
        ->save();

    masukSebagai($this, $this->admin)
        ->get(jalurPenempatan($d['event']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('baris.0.bisa', false)
            ->where('baris.0.sebab', fn ($s) => is_string($s) && str_contains($s, 'kursi'))
            ->where('laman.bisa_sebar', false)
        );

    masukSebagai($this, $this->admin)
        ->post(jalurPenempatan($d['event']), ['benih' => 'SEMPIT'])
        ->assertSessionHas('galat');

    expect(ExamParticipant::query()->where('exam_id', $d['ujian']->id)
        ->whereNull('exam_session_room_id')->count())->toBe(8);
});

it('menolak menyebar ketika kursinya kurang tanpa menulis satu baris pun', function () {
    // Mengisi sebagian menghasilkan penempatan yang terlihat berhasil: separuh
    // siswa punya ruang, separuhnya menggantung tanpa penjelasan. Yang
    // menemukannya adalah pengawas di hari-H, saat siswa berdiri di depan
    // pintu dengan daftar yang tidak memuat namanya.
    $d = siapkanKocok($this->admin, $this->tahun, kapasitasPerRuang: 2);

    masukSebagai($this, $this->admin)
        ->post(jalurPenempatan($d['event']), ['benih' => 'KURANG'])
        ->assertSessionHas('galat');

    expect(ExamParticipant::query()->where('exam_id', $d['ujian']->id)
        ->whereNull('exam_session_room_id')->count())->toBe(8)
        ->and(ExamEventShuffle::query()->where('event_id', $d['event']->id)->count())->toBe(0);
});

it('menolak menyebar pada event yang belum punya ruang', function () {
    // Peserta tanpa ruang bukan "belum ditempatkan" - mereka tidak punya
    // tempat duduk sama sekali. Eventnya juga tidak boleh dinyatakan siap.
    $d = siapkanKocok($this->admin, $this->tahun, jumlahRuang: 0);

    masukSebagai($this, $this->admin)
        ->post(jalurPenempatan($d['event']), ['benih' => 'KOSONG'])
        ->assertSessionHas('galat');

    // Dan laman penempatannya tetap terbuka, dengan pesannya - bukan 500.
    masukSebagai($this, $this->admin)
        ->get(jalurPenempatan($d['event']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('baris', 1)
            ->where('baris.0.ruang', 0)
            ->where('baris.0.bisa', false)
            ->where('laman.pesan', fn ($p) => is_string($p) && str_contains($p, 'belum punya ruang'))
            ->where('laman.bisa_sebar', false)
        );

    expect(ExamParticipant::query()->where('exam_id', $d['ujian']->id)
        ->whereNull('exam_session_room_id')->count())->toBe(8);
});

/* ---------------------------------------------------------------- Isolasi */

it('membalas 404 untuk event milik sekolah lain', function () {
    // Scope tenant yang menutup ini, dan buktinya tetap perlu: 404, bukan 403.
    // 403 membuktikan barisnya ada, dan itu sudah membocorkan satu bit tentang
    // event yang belum tentu boleh dilihat.
    $sekolahLain = School::factory()->create();
    $adminLain = User::factory()->admin()->untukSekolah($sekolahLain)->create();
    $tahunLain = AcademicYear::factory()->untukSekolah($sekolahLain)->aktif()->create();
    $lain = siapkanKocok($adminLain, $tahunLain);

    masukSebagai($this, $this->admin)
        ->get(jalurPenempatan($lain['event']))
        ->assertNotFound();

    masukSebagai($this, $this->admin)
        ->post(jalurPenempatan($lain['event']), ['benih' => 'SALAH2'])
        ->assertNotFound();
});

it('menolak Guru menyebar peserta maupun membuka penempatan', function () {
    // Guru dan Admin sama-sama melihat event yang sama-sama milik sekolahnya,
    // sehingga yang menolak bukan scope tenant. Yang menolak adalah Policy:
    // `update` menuntut Admin, dan `view` menuntut Guru itu benar-benar
    // ditugaskan pada eventnya. Guru yang tidak ditugaskan ke mana pun adalah
    // keadaan biasa, bukan serangan - dan baginya 403 adalah jawaban yang
    // benar, bukan 404.
    $d = siapkanKocok($this->admin, $this->tahun);
    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create();

    masukSebagai($this, $guru)
        ->post(jalurPenempatan($d['event']), ['benih' => 'GURU01'])
        ->assertForbidden();

    masukSebagai($this, $guru)
        ->get(jalurPenempatan($d['event']))
        ->assertForbidden();

    expect(ExamParticipant::query()->where('exam_id', $d['ujian']->id)
        ->whereNull('exam_session_room_id')->count())->toBe(8);
});
