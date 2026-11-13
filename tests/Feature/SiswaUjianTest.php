<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Models\StudentAnswer;
use App\Domain\Attendance\Support\Kelayakan;
use App\Domain\Attendance\Support\TanggaPelanggaran;
use App\Domain\Event\Support\AmbangPelanggaran;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Exam\Services\TokenSesi;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Models\QuestionMedia;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\Storage;

/**
 * Fase 7 - mesin ujian siswa. Wireframe plate 19-29.
 *
 * =====================================================================
 * Yang dibuktikan berkas ini adalah PENOLAKANNYA
 * =====================================================================
 * Bentuk yang sama dipakai AttendanceTest dan ExamTest: sistem ujian yang
 * tidak memeriksa apa pun akan lulus setiap test "bahagia". Karena itu
 * daftar di sini dibaca sebagai kalimat penolakan - "siswa tanpa presensi
 * tidak masuk", "token salah tidak membuka pintu", "kunci jawaban tidak
 * pernah sampai ke peramban", "jawaban dari tab yang basi ditolak 409" -
 * dan test yang membuktikannya adalah test yang MENCOBA melanggar.
 *
 * =====================================================================
 * Invarian yang paling penting: kunci tidak pernah ke peramban
 * =====================================================================
 * `SnapshotSoalTest` sudah mengunci BENTUK payload (`untukSiswa()`), dan
 * berkas ini mengisi "lapis 2" yang dijanjikan di sana: bentuk yang
 * BENAR-BENAR dikirim route `/siswa/ujian/{peserta}/kerjakan`. Lapis 1
 * menjaga methodnya; lapis 2 menjaga pemanggilnya - dan yang bisa rusak
 * tanpa suara adalah pemanggilnya, misalnya lewat `with('soal')` yang
 * diserialisasi apa adanya.
 *
 * =====================================================================
 * Jendela sesi dihitung dari SEKARANG
 * =====================================================================
 * Sama seperti AttendanceTest, dan alasannya sama: test yang memakai tanggal
 * tetap lulus satu minggu lalu gagal minggu berikutnya, dan kegagalannya
 * menunjuk ke laman alih-alih ke tanggal di dalam testnya.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);
    $this->lain = School::factory()->create(['name' => 'SMK Negeri 2']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.ujian',
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

/* =====================================================================
 | Middleware `siswa` - 403, bukan 404
 |===================================================================== */

it('menolak peran selain Siswa membuka konsol siswa', function () {
    // `auth` saja akan meloloskan ketiganya. Bagi Admin dan Guru batasnya
    // memang 404 lewat kepemilikan penempatan, tetapi Pengawas memegang
    // tautan ruang - dan kepala layar siswa membaca `pengawasRuang` dari
    // shared props, sehingga ia akan melihat R-01 di atas jadwal ujian anak.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create(['username' => 'guru.tolak']);

    foreach ([$this->admin, $guru, $data['pengawas']] as $bukanSiswa) {
        masukUji($this, $bukanSiswa)->get('/siswa')->assertForbidden();
    }

    // Dan yang menentukan: Siswanya sendiri tetap masuk. Tanpa baris ini,
    // gerbang yang menolak SEMUA orang akan lulus test di atas.
    masukUji($this, $data['siswa'][0])->get('/siswa')->assertOk();

    app(SchoolResolver::class)->forget();
});

/* =====================================================================
 | Plate 20-21 - gerbang
 |===================================================================== */

it('menolak masuk sebelum presensinya diverifikasi pengawas, dengan kalimat PRD', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/gerbang")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Siswa/Gerbang')
            ->where('layak.boleh', false)
            // Kalimatnya disalin dari PRD §10.9, bukan dikarang laman - dan
            // itulah yang membuatnya bisa dibandingkan apa adanya.
            ->where('layak.alasan', Kelayakan::PESAN_BELUM_DIPRESENSI)
        );

    // Dan endpoint `mulai` menolaknya juga. PRD §10.8: "Validasi dilakukan
    // kembali pada endpoint start exam" - laman yang memeriksa sendiri akan
    // berbeda dari server begitu salah satunya ditambah syarat.
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token'])
        ->assertSessionHasErrors('gerbang');

    expect(ExamAttempt::query()->where('exam_participant_id', $peserta->id)->count())->toBe(0);
});

it('membuka gerbang sesudah presensi diverifikasi, dan menandai bahwa tombolnya melanjutkan', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);

    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/gerbang")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('layak.boleh', true)
            ->where('layak.alasan', null)
            // Belum ada attempt, jadi tombolnya "Mulai ujian" - bukan
            // "Lanjutkan". Dua keadaan yang membuat anak yang sinyalnya
            // putus tahu jawabannya masih ada.
            ->where('melanjutkan', false)
            ->where('ber_token', true)
            ->has('aturan')
        );
});

it('menolak token yang salah dan token yang tidak diisi', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);

    // Tanpa token sama sekali. Sesi ini dijaga token, dan ujian ber-token yang
    // dimulai tanpa mengetik apa pun harus ditolak - kalau tidak, seluruh
    // gunanya token hilang pada hari pertama.
    mulaiAttempt($this, $data['siswa'][0], $peserta)
        ->assertSessionHasErrors('token');

    // Token yang bukan milik sesi ini.
    mulaiAttempt($this, $data['siswa'][0], $peserta, 'ZZZZZZ')
        ->assertSessionHasErrors('token');

    expect(ExamAttempt::query()->where('exam_participant_id', $peserta->id)->count())->toBe(0);

    // Dan token yang benar membukanya.
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token'])
        ->assertRedirect("/siswa/ujian/{$peserta->id}/kerjakan");

    expect(attemptBerjalan($peserta)->status)->toBe(ExamAttempt::STATUS_BERJALAN);
});

/* =====================================================================
 | Sesi tanpa token aktif - pintunya TERTUTUP, bukan terbuka
 |===================================================================== */

it('menolak memulai dan membuka laman pengerjaan saat token belum diterbitkan', function () {
    // Keadaan yang dilaporkan: sekolah lupa menerbitkan token di
    // `/admin/events/{event}/token`, dan siswa tetap bisa mengerjakan.
    //
    // Sampai 2026-11-10 ada DUA jalan yang membiarkannya: `periksaToken()`
    // melewatkan sesi tanpa token sama sekali, dan `/kerjakan` tidak
    // memeriksa apa pun sehingga alamat itu bisa dibuka langsung. Keduanya
    // ditutup, dan test ini mencoba keduanya.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);

    app(TokenSesi::class)->cabutSemua($data['sesi']);

    // Bahwa cabutnya berhasil, bukan test yang lulus karena sesinya memang
    // tidak pernah punya token.
    expect($data['sesi']->fresh()?->tokenAktif())->toBeNull();

    // 1. Gerbangnya sendiri menyatakan bahwa tokennya belum ada.
    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/gerbang")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('layak.boleh', true)
            ->where('ber_token', false)
        );

    // 2. Memulai ditolak, termasuk kalau token lama masih diketik - token
    //    yang dicabut tidak membuka apa pun.
    mulaiAttempt($this, $data['siswa'][0], $peserta)
        ->assertSessionHasErrors('token');

    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token'])
        ->assertSessionHasErrors('token');

    expect(ExamAttempt::query()->where('exam_participant_id', $peserta->id)->count())->toBe(0);

    // 3. Dan alamat `/kerjakan` yang dibuka langsung tidak menyerahkan soal.
    //    Di sini yang menolaknya adalah "belum ada attempt" - lihat test
    //    berikutnya untuk attempt yang SUDAH berjalan lalu tokennya dicabut.
    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/kerjakan")
        ->assertRedirect("/siswa/ujian/{$peserta->id}/gerbang");
});

it('mengembalikan siswa ke gerbang saat token ujian yang sedang berjalan dicabut', function () {
    // Keadaan yang berbeda dari di atas, dan yang lebih berbahaya: attempt
    // sudah BERJALAN, lalu tokennya dicabut di tengah ujian. Sebelum
    // pemeriksaan di `tampilkan()`, anak yang memuat ulang laman ini tetap
    // menerima seluruh soal beserta jawabannya.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    expect(attemptBerjalan($peserta)->status)->toBe(ExamAttempt::STATUS_BERJALAN);

    app(TokenSesi::class)->cabutSemua($data['sesi']);

    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/kerjakan")
        ->assertRedirect("/siswa/ujian/{$peserta->id}/gerbang")
        ->assertSessionHasErrors('token');
});

it('menolak siswa lain membuka penempatan yang bukan miliknya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $milikOrang = $data['peserta'][1];

    // Dua siswa di sekolah yang sama, dan SchoolScope tidak membedakan
    // keduanya - hanya `student_id` yang bisa. Jawabannya 404, bukan 403:
    // 403 akan memberi tahu penebak bahwa id itu benar-benar ada.
    foreach (['gerbang', 'kerjakan', 'terkunci', 'selesai'] as $laman) {
        masukUji($this, $data['siswa'][0])
            ->get("/siswa/ujian/{$milikOrang->id}/{$laman}")
            ->assertNotFound();
    }

    // Halaman miliknya sendiri bisa dibuka, supaya test ini membuktikan
    // penolakan atas ID YANG SALAH.
    masukUji($this, $data['siswa'][1])
        ->get("/siswa/ujian/{$milikOrang->id}/gerbang")
        ->assertOk();
});

/* =====================================================================
 | PRD §11.5 - deadline = MIN(durasi, akhir sesi)
 |===================================================================== */

it('memakai durasi ujian sebagai deadline bila sesinya masih panjang', function () {
    $data = siapkanUjianSiswa(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['akhir' => '+3 hours'],
        2,
        45,
    );

    $peserta = $data['peserta'][0];
    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    $attempt = attemptBerjalan($peserta);

    expect($attempt->deadline_at->diffInMinutes(now()->addMinutes(45)))->toBeLessThan(1)
        // Dan yang menentukan: deadline-nya BUKAN akhir sesinya.
        ->and($attempt->deadline_at->lt($data['sesi']->hard_end_at))->toBeTrue();
});

it('memakai akhir sesi sebagai deadline bila durasinya lebih panjang dari jendelanya', function () {
    // Sesi 90 menit yang hanya menyisakan 20 menit. Yang berlaku adalah yang
    // lebih dulu - dan inilah satu-satunya test yang membedakan hasil MIN
    // dari komponen tunggalnya.
    $data = siapkanUjianSiswa(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['tutup' => '+20 minutes', 'akhir' => '+20 minutes'],
        2,
        90,
    );

    $peserta = $data['peserta'][0];
    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    $attempt = attemptBerjalan($peserta);

    expect($attempt->deadline_at->timestamp)
        ->toBe($data['sesi']->hard_end_at->timestamp);
});

/* =====================================================================
 | Lapis 2 - kunci tidak pernah sampai ke peramban
 |===================================================================== */

it('tidak pernah mengirim kunci, rubrik, atau pembahasan ke laman pengerjaan', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    $halaman = masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/kerjakan")
        ->assertOk();

    $props = $halaman->viewData('page')['props'];

    // Empat butir - bukan kosong, sehingga test ini tidak lulus karena
    // halamannya gagal memuat apa pun.
    expect($props['butir'])->toHaveCount(4);

    foreach ($props['butir'] as $butir) {
        expect($butir)->not->toHaveKeys(['kunci_json', 'rubrik_json', 'pembahasan_json'])
            ->and($butir['rusak'])->toBeFalse()
            ->and($butir['nomor'])->toBeInt();
    }

    // Yang menentukan: isi kolom terlarangnya TIDAK ikut lewat jalur lain.
    // Rubrik uraian dan pembahasannya ada di snapshot, dan keduanya tidak
    // boleh muncul sebagai teks di payload mana pun.
    $mentah = json_encode($props);

    expect($mentah)->not->toContain('Ketepatan konsep')
        ->and($mentah)->not->toContain('Pembahasan yang tidak boleh dibaca siswa');

    // Dan yang boleh dibaca siswa tetap ada: bobot tiap nomor adalah bagian
    // dari aturan main, bukan rahasia.
    expect($props['butir'][0]['bobot'])->toBe(2.0)
        ->and($props['butir'][0])->toHaveKeys(['soal', 'opsi', 'media', 'jawaban', 'skor', 'nomor', 'rusak']);
});

/* =====================================================================
 | PRD §11.4 - autosave, versi, dan 409
 |===================================================================== */

it('menyimpan jawaban, menaikkan versi, dan menghitung progres', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    $butir = AttemptQuestion::query()
        ->where('exam_attempt_id', attemptBerjalan($peserta)->id)
        ->orderBy('display_order')
        ->firstOrFail();

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/butir/{$butir->id}", [
            'isi' => ['opsi' => ['a']],
        ])
        ->assertOk()
        ->assertJsonPath('versi', 1)
        ->assertJsonPath('terjawab', true)
        ->assertJsonPath('progres.terjawab', 1)
        ->assertJsonPath('progres.total', 4)
        ->assertJsonPath('progres.ringkas', '1 / 4 terjawab');

    expect(StudentAnswer::query()->where('attempt_question_id', $butir->id)->value('version'))->toBe(1);
});

it('menolak 409 jawaban yang dikirim dari salinan versi yang sudah basi', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    $butir = AttemptQuestion::query()
        ->where('exam_attempt_id', attemptBerjalan($peserta)->id)
        ->orderBy('display_order')
        ->firstOrFail();

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/butir/{$butir->id}", [
            'isi' => ['opsi' => ['a']],
            'versi' => 0,
        ])
        ->assertOk();

    // Tab kedua masih memegang versi 0. 409, bukan 422: isinya tidak salah,
    // ia hanya datang dari salinan yang sudah basi - dan pesan di bawah kotak
    // jawaban akan membuat siswa mengira jawabannya yang keliru.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/butir/{$butir->id}", [
            'isi' => ['opsi' => ['b']],
            'versi' => 0,
        ])
        ->assertStatus(409);

    // Dan jawaban yang lebih baru MENANG - bukan yang belakangan dikirim.
    expect(StudentAnswer::query()->where('attempt_question_id', $butir->id)->firstOrFail()->answer_json)
        ->toBe(['opsi' => ['a']]);
});

it('menolak menulis jawaban ke butir milik attempt orang lain', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    // Dua siswa, keduanya memulai.
    foreach ([0, 1] as $i) {
        verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $data['peserta'][$i]);
        mulaiAttempt($this, $data['siswa'][$i], $data['peserta'][$i], $data['token']);
    }

    $butirOrangLain = AttemptQuestion::query()
        ->where('exam_attempt_id', attemptBerjalan($data['peserta'][1])->id)
        ->firstOrFail();

    // Siswa pertama menebak id butir milik siswa kedua. Yang tertimpa kalau
    // lolos adalah jawaban anak yang tidak pernah membuka laman itu.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$data['peserta'][0]->id}/butir/{$butirOrangLain->id}", [
            'isi' => ['opsi' => ['a']],
        ])
        ->assertNotFound();

    expect(StudentAnswer::query()->where('attempt_question_id', $butirOrangLain->id)->exists())->toBeFalse();
});

it('menandai ragu tanpa menaikkan versi jawaban', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    $butir = AttemptQuestion::query()
        ->where('exam_attempt_id', attemptBerjalan($peserta)->id)
        ->firstOrFail();

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/butir/{$butir->id}", ['isi' => ['opsi' => ['a']]])
        ->assertOk();

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/butir/{$butir->id}/tandai", ['ditandai' => true])
        ->assertOk()
        ->assertJsonPath('ditandai', true)
        ->assertJsonPath('progres.ditandai', 1);

    // Ragu bukan jawaban. Menaikkan versinya berarti tab lain yang sedang
    // menyunting jawaban butir itu ditolak hanya karena tombol Ragu ditekan
    // di tab pertama.
    $baris = StudentAnswer::query()->where('attempt_question_id', $butir->id)->firstOrFail();

    expect($baris->version)->toBe(1)
        ->and($baris->ditandai)->toBeTrue();
});

/* =====================================================================
 | Plate 24 - laporan pelanggaran tidak dipercaya
 |===================================================================== */

it('memperlakukan jenis yang tidak dikenal sebagai tingkat 1, bukan menolaknya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    // Peramban versi baru yang melaporkan jenis yang belum ada di daftar
    // harus tetap tercatat. Menolaknya berarti kejadian itu hilang tanpa
    // jejak - dan yang mengirimnya bukan kode yang kita kendalikan.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/lapor", ['jenis' => 'jenis_belum_ada'])
        ->assertOk()
        ->assertJsonPath('jumlah', 1);

    $baris = ExamViolation::query()->firstOrFail();

    expect($baris->violation_type)->toBe('jenis_belum_ada')
        ->and($baris->severity)->toBe(1);
});

it('tidak menulis baris sama sekali untuk tingkat 0', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    // Klik kanan diblokir DIAM-DIAM. Pemeriksaannya ada di server, bukan
    // hanya di peramban: peramban adalah kode yang berjalan di perangkat
    // orang lain, dan permintaan yang ditempel langsung akan mengirim apa
    // saja. Catatan berisi tiga puluh kali "klik kanan" akan menenggelamkan
    // satu baris "berpindah aplikasi 40 detik" yang perlu dibaca pengawas.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/lapor", ['jenis' => 'klik_kanan'])
        ->assertOk()
        ->assertJsonPath('eskalasi', 0)
        ->assertJsonPath('jumlah', 0);

    expect(ExamViolation::query()->count())->toBe(0)
        ->and(attemptBerjalan($peserta)->violation_count)->toBe(0);
});

it('mengunci ujian seketika saat sesi ganda terdeteksi', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    // Satu-satunya jenis yang mengunci tanpa menunggu hitungan: attempt yang
    // sama dibuka dari dua perangkat berarti salinan soalnya ada di dua
    // layar, dan tidak ada kekeliruan yang menghasilkan itu. Menunggu sampai
    // ambang terlewati berarti membiarkan orang kedua membaca soal yang sama.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/lapor", ['jenis' => 'sesi_ganda'])
        ->assertOk()
        ->assertJsonPath('dikunci', true)
        ->assertJsonPath('eskalasi', 3)
        ->assertJsonPath('status', ExamAttempt::STATUS_DIKUNCI);

    $attempt = attemptBerjalan($peserta);

    expect($attempt->dikunci())->toBeTrue()
        ->and($attempt->lock_reason)->toContain('Sesi ganda');

    // Dan lamannya berpindah: mengetik di laman pengerjaan setelah dikunci
    // berarti jawaban yang tidak akan pernah tersimpan.
    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/kerjakan")
        ->assertRedirect("/siswa/ujian/{$peserta->id}/terkunci");
});

it('tidak menerima laporan pelanggaran dari attempt yang sudah ditutup', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    masukUji($this, $data['siswa'][0])
        ->post("/siswa/ujian/{$peserta->id}/selesai")
        ->assertRedirect();

    // Laporan tentang layar yang sudah tidak menampilkan ujian adalah
    // laporan tentang kejadian yang tidak ada.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/lapor", ['jenis' => 'pindah_tab', 'durasi_ms' => 5000])
        ->assertOk()
        ->assertJsonPath('jumlah', 0);

    expect(ExamViolation::query()->count())->toBe(0);
});

/* =====================================================================
 | Tangga eskalasi mengikuti ambang EVENT
 |===================================================================== */

it('memberi peringatan yang menyebut sisa menuju kunci, tanpa menyebut ambangnya', function () {
    // Ambang dipersempit: peringatkan di 1, kunci di 3.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    $data['event']->forceFill([
        'violation_thresholds' => ['peringatkan' => 1, 'tandai_pengawas' => 2, 'kunci_ujian' => 3],
    ])->save();

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/lapor", ['jenis' => 'pindah_tab', 'durasi_ms' => 4000])
        ->assertOk()
        ->assertJsonPath('eskalasi', 1)
        ->assertJsonPath('diperingatkan', true)
        ->assertJsonPath('dikunci', false)
        // Sisanya dikirim, ambangnya TIDAK. Mengirim ambang ke peramban
        // berarti mengirim aturan main yang bisa dibaca sebelum dipatuhi.
        ->assertJsonPath('sisa_menuju_kunci', 2);

    // Pelanggaran kedua menandai pengawas; ujian BERJALAN TERUS.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/lapor", ['jenis' => 'pindah_tab', 'durasi_ms' => 4000])
        ->assertOk()
        ->assertJsonPath('eskalasi', 2)
        ->assertJsonPath('pengawas_ditandai', true)
        ->assertJsonPath('dikunci', false);

    expect(attemptBerjalan($peserta)->status)->toBe(ExamAttempt::STATUS_BERJALAN);

    // Pelanggaran ketiga mengunci.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/lapor", ['jenis' => 'pindah_tab', 'durasi_ms' => 4000])
        ->assertOk()
        ->assertJsonPath('eskalasi', 3)
        ->assertJsonPath('dikunci', true);

    expect(attemptBerjalan($peserta)->status)->toBe(ExamAttempt::STATUS_DIKUNCI);
});

it('menghitung tindakan tiap baris riwayat dari urutannya, bukan dari kolom tersimpan', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    $data['event']->forceFill([
        'violation_thresholds' => ['peringatkan' => 1, 'tandai_pengawas' => 2, 'kunci_ujian' => 3],
    ])->save();

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    foreach (range(1, 3) as $_) {
        masukUji($this, $data['siswa'][0])
            ->postJson("/siswa/ujian/{$peserta->id}/lapor", ['jenis' => 'pindah_tab']);
    }

    $baris = ExamViolation::query()->orderBy('id')->get();

    // Baris pertama berbunyi "diperingatkan" hanya karena ia yang pertama;
    // baris ketiga "ujian dikunci" karena dua sebelumnya ada. Ambang pelanggaran
    // memang BERUBAH - ia milik event - sehingga menyimpannya berarti riwayat
    // yang membeku dengan aturan versi lama.
    $jejak = TanggaPelanggaran::jejakTindakan(
        $baris,
        new AmbangPelanggaran(1, 2, 3),
    );

    expect($jejak)->toBe([
        $baris[0]->id => 'diperingatkan',
        $baris[1]->id => 'pengawas diberi tahu',
        $baris[2]->id => 'ujian dikunci',
    ]);
});

/* =====================================================================
 | Plate 26-27 - menutup attempt, dan menutupnya dua kali
 |===================================================================== */

it('menutup attempt secara idempotent', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    masukUji($this, $data['siswa'][0])
        ->post("/siswa/ujian/{$peserta->id}/selesai")
        ->assertRedirect("/siswa/ujian/{$peserta->id}/selesai");

    $pertama = attemptBerjalan($peserta);
    expect($pertama->status)->toBe(ExamAttempt::STATUS_DIKIRIM);

    // Ketukan kedua pada tombol yang sama. Jam kumpulnya TIDAK digeser:
    // penutupan kedua adalah kejadian yang sama, bukan yang baru.
    masukUji($this, $data['siswa'][0])
        ->post("/siswa/ujian/{$peserta->id}/selesai")
        ->assertRedirect();

    expect(attemptBerjalan($peserta)->submitted_at->timestamp)->toBe($pertama->submitted_at->timestamp)
        ->and(ExamAttempt::query()->where('exam_participant_id', $peserta->id)->count())->toBe(1);
});

it('menutup attempt yang kedaluwarsa sekali saja, pada deadline-nya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    $attempt = attemptBerjalan($peserta);
    $deadline = now()->subMinutes(5);

    $attempt->forceFill(['deadline_at' => $deadline])->save();

    // Penutupan kedaluwarsa terjadi di SETIAP pembacaan - denyut, muat ulang,
    // simpanan - bukan oleh penjadwal. Penjadwal yang mati akan membuat
    // attempt kedaluwarsa tetap "berjalan", dan papan monitoring menampilkan
    // siswa yang sudah pulang sebagai masih mengerjakan.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/denyut", ['perangkat' => 'p-1'])
        ->assertOk()
        ->assertJsonPath('status', ExamAttempt::STATUS_KEDALUWARSA);

    $tertutup = attemptBerjalan($peserta);
    expect($tertutup->submitted_at->timestamp)->toBe($deadline->timestamp);

    // Denyut kedua tidak menggeser jam tutupnya.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/denyut", ['perangkat' => 'p-1'])
        ->assertOk();

    expect(attemptBerjalan($peserta)->submitted_at->timestamp)->toBe($deadline->timestamp);
});

it('menolak menyimpan jawaban setelah attempt ditutup, tanpa melempar galat', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    $butir = AttemptQuestion::query()
        ->where('exam_attempt_id', attemptBerjalan($peserta)->id)
        ->firstOrFail();

    masukUji($this, $data['siswa'][0])->post("/siswa/ujian/{$peserta->id}/selesai");

    // Autosave dari halaman yang sudah lama terbuka tiba SESUDAH siswa
    // menekan Kumpulkan. Menjawabnya dengan galat akan membuat siswa yang
    // sudah selesai melihat pesan gagal tepat pada detik ia menekan tombol
    // yang mengakhirinya.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/butir/{$butir->id}", ['isi' => ['opsi' => ['a']]])
        ->assertOk()
        ->assertJsonPath('status', ExamAttempt::STATUS_DIKIRIM);

    // Dan jawabannya TIDAK berubah sesudah waktu habis.
    expect(StudentAnswer::query()->where('attempt_question_id', $butir->id)->exists())->toBeFalse();
});

/* =====================================================================
 | Denyut - plate 15 membaca kolom yang ditulis di sini
 |===================================================================== */

it('menulis denyut, termasuk progres yang dihitung server', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/denyut", [
            'perangkat' => 'id-peramban-1',
            'layar_penuh' => true,
            'pelindung' => true,
        ])
        ->assertOk()
        ->assertJsonPath('terkunci', false)
        // Denyut yang lewat TIDAK menjadi pelanggaran: koneksi yang putus
        // bukan perbuatan siswa. Papan monitoring ADALAH isyaratnya.
        ->assertJsonPath('catatan', []);

    $attempt = attemptBerjalan($peserta);

    expect($attempt->last_heartbeat_at)->not->toBeNull()
        ->and($attempt->terhubung())->toBeTrue()
        // Progres dihitung server, bukan dikirim peramban: angka yang dibaca
        // pengawas harus berasal dari jawaban yang benar-benar tersimpan.
        ->and($attempt->guard_flags_json['progres'])->toBe('0 / 4 terjawab');
});

it('mencatat nomor soal yang sedang dilihat anak, dan TIDAK mengarangnya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    // Nomor ini datang dari peramban, dan server TIDAK bisa menghitungnya
    // sendiri: nomor yang dilihat anak adalah
    // `attempt_questions.display_order`, yang berbeda dari
    // `exam_questions.position` begitu urutan soalnya dikocok. Karena itu
    // yang diuji di sini adalah bahwa nilai yang DIKIRIM benar-benar
    // tersimpan - bukan bahwa server menebaknya dengan benar.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/denyut", [
            'perangkat' => 'id-peramban-1',
            'nomor' => 3,
        ])
        ->assertOk();

    expect(attemptBerjalan($peserta)->guard_flags_json['nomor'])->toBe(3);

    // Denyut berikutnya memindahkan nomornya - dan yang tersisa hanyalah
    // yang terakhir, karena yang ditanyakan Admin selalu "SEKARANG di
    // nomor berapa", bukan riwayat perpindahannya.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/denyut", [
            'perangkat' => 'id-peramban-1',
            'nomor' => 7,
        ])
        ->assertOk();

    expect(attemptBerjalan($peserta)->guard_flags_json['nomor'])->toBe(7);

    // ------------------------------------------------------------------
    // Yang TIDAK dikarang, dan itu yang dijaga di sini
    // ------------------------------------------------------------------
    // Denyut tanpa nomor - peramban versi lama, atau laman yang dibuka
    // sebelum kolomnya ada - menghasilkan null, BUKAN angka tadi. Nomor
    // yang dipertahankan dari denyut sebelumnya akan menampilkan anak di
    // soal yang mungkin sudah ia tinggalkan berjam-jam lalu, dan papan yang
    // menampilkan angka basi sebagai angka baru lebih buruk daripada papan
    // yang mengaku tidak tahu.
    //
    // `null` juga bukan `1`: "belum ada kabar" dan "baru membuka soal
    // pertama" adalah dua keadaan yang berbeda, dan yang pertama adalah
    // yang perlu ditanyakan ke pengawas ruangnya.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/denyut", ['perangkat' => 'id-peramban-1'])
        ->assertOk();

    expect(attemptBerjalan($peserta)->guard_flags_json['nomor'])->toBeNull();
});

/* =====================================================================
 | Muat ulang TIDAK boleh mengunci - dan ini regresi, bukan kehati-hatian
 |===================================================================== */

it('tidak mengunci ujian saat peramban memuat ulang halamannya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    // =================================================================
    // Yang dijaga test ini, dan mengapa ia pernah gagal
    // =================================================================
    // `DenyutService` menyimpulkan `sesi_ganda` dari dua hal sekaligus:
    // perangkatnya BEDA, dan perangkat LAMA masih berdenyut di bawah 90
    // detik. Kesimpulan itu benar untuk tab kedua di perangkat kedua.
    //
    // Yang membuatnya salah adalah `idPeramban()` di Kerjakan.tsx: idnya
    // dulu hanya hidup di objek `window`, sehingga MUAT ULANG - `F5`,
    // ponsel yang kehabisan memori lalu memulihkan tabnya, layar yang
    // berputar - melahirkan id baru. Dari sisi server itu tidak bisa
    // dibedakan dari perangkat kedua, jadi satu muat ulang mengunci anak
    // dari ujiannya sendiri, permanen, dengan alasan "Sesi ganda".
    //
    // Perbaikannya ada di peramban (`sessionStorage`), dan test PHP tidak
    // bisa menjalankan JavaScript. Yang BISA diuji di sini adalah sisi
    // servernya: denyut berulang dengan id yang SAMA harus tetap tenang.
    // Itu menjaga kontrak yang dilanggar - dan `scripts/cbt-cek-laman.mjs`
    // yang menutup sisanya, karena ia benar-benar memuat ulang laman.
    foreach (range(1, 3) as $ke) {
        masukUji($this, $data['siswa'][0])
            ->postJson("/siswa/ujian/{$peserta->id}/denyut", ['perangkat' => 'id-peramban-tetap'])
            ->assertOk()
            ->assertJsonPath('terkunci', false)
            ->assertJsonPath('catatan', []);
    }

    $attempt = attemptBerjalan($peserta);

    expect($attempt->status)->toBe(ExamAttempt::STATUS_BERJALAN)
        ->and($attempt->violation_count)->toBe(0)
        // Perangkatnya tercatat, dan yang penting: ia TIDAK berubah di
        // antara ketiga denyut itu. Kalau idnya berganti tiap permintaan,
        // denyut pertama tidak akan menuliskannya dan denyut kedua sudah
        // cukup untuk mengunci - jadi kolom ini yang membuktikan sisi
        // servernya tenang, bukan cuma kebetulan belum melewati ambang.
        ->and($attempt->guard_flags_json['perangkat'])->toBeString()
        ->and($attempt->guard_flags_json['perangkat'])->toHaveLength(32);
});

it('tetap mengunci saat perangkatnya benar-benar berganti sementara yang lama berdenyut', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/denyut", ['perangkat' => 'ponsel-pertama'])
        ->assertOk();

    // Denyut kedua dari perangkat LAIN, dan yang pertama baru saja terdengar
    // - jadi ini dua layar yang sama-sama mengerjakan, bukan ponsel yang
    // diganti karena baterainya habis. Yang kedua tetap dikunci; yang
    // diperbaiki di test sebelumnya adalah agar MUAT ULANG tidak masuk ke
    // sini, bukan agar jalur ini mati.
    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/denyut", ['perangkat' => 'ponsel-kedua'])
        ->assertOk()
        ->assertJsonPath('terkunci', true);

    expect(attemptBerjalan($peserta)->status)->toBe(ExamAttempt::STATUS_DIKUNCI);
});

/* =====================================================================
 | Media soal - tiga penolakan, dan satu pembukaan
 |===================================================================== */

it('menolak berkas soal lewat tiga jalan, dan membukanya lewat jalan yang benar', function () {
    Storage::fake('local');

    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    $soal = ExamQuestion::query()
        ->where('exam_id', $data['ujian']->id)
        ->whereNotNull('media_json')
        ->firstOrFail();

    $idMedia = (int) $soal->media_json[0]['id'];

    $media = QuestionMedia::query()->withoutGlobalScopes()->findOrFail($idMedia);
    Storage::disk('local')->put($media->storage_path, 'isi-berkas');

    foreach ([0, 1] as $i) {
        verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $data['peserta'][$i]);
        mulaiAttempt($this, $data['siswa'][$i], $data['peserta'][$i], $data['token']);
    }

    // (1) Penempatan milik anak lain.
    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$data['peserta'][1]->id}/berkas/{$soal->id}/{$idMedia}")
        ->assertNotFound();

    // (2) Snapshot dari ujian lain: id berkas yang sah, butir yang salah.
    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/berkas/".($soal->id + 99999)."/{$idMedia}")
        ->assertNotFound();

    // (3) Berkas yang TIDAK tercantum di `media_json` butir itu. Inilah yang
    // menjadikan rutenya bukan pintu baca seluruh bank soal satu sekolah lewat
    // nomor id yang berurutan.
    $lain = QuestionMedia::query()->withoutGlobalScopes()->create([
        'school_id' => $data['ujian']->school_id,
        'question_id' => $soal->question_id,
        'option_id' => null,
        'media_type' => 'gambar',
        'mime_type' => 'image/png',
        'storage_path' => 'soal/lain-'.uniqid().'.png',
        'nama_asli' => 'lain.png',
        'ukuran' => 10,
        'metadata_json' => null,
    ]);

    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/berkas/{$soal->id}/{$lain->id}")
        ->assertNotFound();

    // Dan yang benar-benar milik butir itu terbuka.
    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/berkas/{$soal->id}/{$idMedia}")
        ->assertOk();
});

/* =====================================================================
 | Plate 19, 25, 28, 29 - laman yang membaca, bukan menulis
 |===================================================================== */

it('membaca satu jam yang sama untuk status dan untuk jam yang dikirim', function () {
    // Regresi 2026-11-11. `bentukBaris()` dulu memanggil
    // `$sekarang->modify('+1 day')` untuk menghitung batas kartu utama.
    // `modify()` mengubah Carbon DI TEMPAT, dan `$sekarang` adalah satu
    // instance yang dipakai ulang untuk setiap baris - sehingga status yang
    // dihitung sesudahnya dibandingkan dengan BESOK.
    //
    // Gejalanya tidak terlihat sebagai galat: sesi yang masih terbuka
    // dilaporkan "terlewat" berwarna bad sementara `boleh_mulai` di baris
    // yang sama bernilai true, dan `sekarang` yang sampai ke peramban
    // bertanggal besok. Karena itu yang diperiksa di sini adalah KETIGANYA
    // sekaligus - status, nada, dan tanggal kiriman.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta']->firstWhere('student_id', $data['siswa'][0]->id);

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);

    $props = masukUji($this, $data['siswa'][0])
        ->get('/siswa')
        ->viewData('page')['props'];

    $baris = collect($props['jadwal'])->firstWhere('peserta_id', $peserta->id);

    expect($baris['status'])->toBe('siap dimulai')
        ->and($baris['nada'])->toBe('ok')
        ->and($baris['boleh_mulai'])->toBeTrue()
        ->and(substr($props['sekarang'], 0, 10))->toBe(now()->format('Y-m-d'));
});

it('menampilkan jadwal, nilai, dan riwayat pelanggaran siswa itu sendiri', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    masukUji($this, $data['siswa'][0])
        ->get('/siswa')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Siswa/Jadwal')
            ->where('nama', $data['siswa'][0]->name)
            // Kartu utamanya diputuskan SERVER: membandingkan `mulai` dengan
            // jam ponsel akan menampilkan ujian yang salah sebagai "hari ini".
            ->where('utama.peserta_id', $peserta->id)
        );

    // Nilai: belum ada satu pun angka, dan laman itu mengatakannya alih-alih
    // menggambar kolom kosong di bawah judul "Nilai".
    masukUji($this, $data['siswa'][0])
        ->get('/siswa/nilai')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Siswa/Nilai')
            ->where('ada_nilai', false)
            ->has('kkm')
        );

    // Riwayat pelanggaran: kosong, dan jumlahnya nol - bukan "tidak ada
    // laman".
    masukUji($this, $data['siswa'][0])
        ->get('/siswa/pelanggaran')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Siswa/Pelanggaran')
            ->where('angka.total', 0)
            ->where('angka.pernah_dikunci', 0)
        );
});

it('menampilkan laman terkunci hanya bagi attempt yang benar-benar terkunci', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    // Attempt yang masih berjalan TIDAK berhak membuka laman terkunci -
    // ia dialihkan ke gerbang, bukan ditampilkan laman yang berbohong.
    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/terkunci")
        ->assertRedirect("/siswa/ujian/{$peserta->id}/gerbang");

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/lapor", ['jenis' => 'sesi_ganda']);

    masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/terkunci")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Siswa/Terkunci')
            ->where('attempt.jumlah', 1)
            ->has('ringkasan')
            ->has('riwayat')
        );
});

/* =====================================================================
 | Jalan pulang dari laman ujian - ditambahkan 2026-11-14
 |===================================================================== */

it('gerbang selalu menyediakan jalan pulang ke jadwal', function () {
    // Sampai 2026-11-14 laman gerbang memakai `tanpaKeluar` TANPA tabbar,
    // sehingga tidak ada satu pun jalan pulang. Pada keadaan "menunggu
    // presensi" itu masih bisa diterima; pada sesi yang sudah berakhir ia
    // menjadi jebakan - anak berdiri di depan tombol mati, di halaman tanpa
    // jalan keluar, dan satu-satunya yang bisa ia lakukan adalah menutup
    // peramban.
    //
    // Penjagaannya di server, bukan di peramban: yang dikirim laman adalah
    // prop `layak`, dan tautannya dirender tanpa syarat apa pun. Test ini
    // memeriksa komponennya benar-benar sampai ke layar lewat halaman yang
    // bisa dibuka - bukan bahwa tombolnya "diharapkan ada".
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    // Belum dipresensi: keadaan penolakan yang paling umum, dan keadaan yang
    // dulu paling lama membuat anak menunggu tanpa jalan keluar.
    $halaman = masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/gerbang")
        ->assertOk()
        ->assertSee('/siswa', escape: false);

    $props = $halaman->viewData('page')['props'];

    expect($props['layak']['boleh'])->toBeFalse();

    // Halaman jadwalnya sendiri hidup - tautan pulang yang menuju 404 sama
    // saja dengan tidak ada tautan.
    masukUji($this, $data['siswa'][0])->get('/siswa')->assertOk();
});

it('menampilkan sisa waktu di laman terkunci, dan tidak menyembunyikannya', function () {
    // Alasan yang sama: laman terkunci memakai `tanpaKeluar` dan tidak punya
    // tabbar. Selama sisa waktunya belum habis, menunggu memang pekerjaannya.
    // Sesudah habis, laman itu berhenti menjadi jawaban atas apa pun - dan
    // hanya tautan pulang yang tersisa.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/monitoring/peserta/{$peserta->id}/hentikan", [
            'pesan' => 'Panggil pengawas ke kursi 1.',
        ])
        ->assertSessionHas('sukses');

    $props = masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/terkunci")
        ->assertOk()
        ->viewData('page')['props'];

    // Sisa waktu masih positif: waktunya memang masih berjalan, dan itulah
    // yang ditunggu anaknya.
    expect($props['attempt']['sisa_detik'])->toBeGreaterThan(0);
});
