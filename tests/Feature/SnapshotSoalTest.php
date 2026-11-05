<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\PembekuPaket;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionItem;
use App\Domain\Question\Models\QuestionOption;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Validation\ValidationException;

/**
 * Fase 4 - snapshot soal ke dalam ujian. PRD §16.6, blueprint `:312`.
 *
 * =====================================================================
 * Kalimat yang berkas ini jaga, dan asalnya
 * =====================================================================
 * Blueprint `:312`: "Bank soal tetap boleh diedit Guru tanpa merusak ujian yang
 * sedang berjalan. Tanpa ini, satu edit Guru bisa mengubah ujian 300 siswa yang
 * sedang berlangsung."
 *
 * Kalimat itu punya satu test yang membuktikannya - "mengubah soal asli tidak
 * mengubah snapshot" - dan tanpa test itu, seluruh tabel `exam_questions` bisa
 * diganti dengan foreign key biasa dan setiap test lain di berkas ini tetap
 * hijau. Snapshot yang menunjuk balik ke bank soal adalah snapshot yang gagal
 * justru pada satu-satunya hal yang dimintanya.
 *
 * =====================================================================
 * Berkas ini mengambil alih markTestIncomplete di ExamTest
 * =====================================================================
 * Fase 5 menandai `it('menandai snapshot paket soal sebagai belum dikerjakan')`
 * sebagai incomplete dengan alasan yang jujur: bank soal belum ada. Test itu
 * sudah dihapus, dan isinya dikerjakan sungguhan di sini - bukan dihapus tanpa
 * pengganti, karena menghapus penanda tanpa mengisi yang ditandainya adalah
 * cara paling tenang untuk kehilangan sebuah janji.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    $this->mapel = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MTK',
        'name' => 'Matematika',
    ]);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'snapshot.admin',
    ]);

    $this->guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'snapshot.guru',
    ]);

    $this->event = SchoolEvent::factory()->untukTahun($this->tahun)->create([
        'school_id' => $this->sekolah->id,
    ]);
});

function masukSnapshot(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/**
 * Paket berisi dua soal: satu pilihan ganda berbobot 2, satu uraian berbobot 5
 * dengan rubrik.
 *
 * Dua jenis, bukan satu: snapshot yang hanya menyalin pilihan ganda akan lulus
 * setiap pemeriksaan `opsi_json` sambil diam-diam kehilangan rubriknya - dan
 * yang menemukan uraian tanpa pedoman penilaian adalah korektor di Fase 8.
 */
function paketDuaSoal(User $guru, Subject $mapel, string $nama = 'Paket Snapshot'): QuestionPackage
{
    $paket = QuestionPackage::factory()->oleh($guru, $mapel)->siap()->create(['name' => $nama]);

    $pg = Question::factory()->oleh($guru, $mapel)->berjenis(JenisSoal::PilihanGanda)->create([
        'scoring_config_json' => ['bobot' => 2],
        // Pembahasan sengaja diisi: `untukSiswa()` harus membuangnya, dan test
        // yang membuang kolom kosong tidak membuktikan apa pun.
        'explanation_json' => ['teks' => '<p>Karena 2 + 2 = 4.</p>'],
    ]);

    QuestionOption::create([
        'school_id' => $pg->school_id,
        'question_id' => $pg->id,
        'option_key' => 'a',
        'content_json' => ['teks' => '<p>Empat</p>'],
        'is_correct' => true,
        'position' => 0,
    ]);

    QuestionOption::create([
        'school_id' => $pg->school_id,
        'question_id' => $pg->id,
        'option_key' => 'b',
        'content_json' => ['teks' => '<p>Lima</p>'],
        'is_correct' => false,
        'position' => 1,
    ]);

    $uraian = Question::factory()->oleh($guru, $mapel)->berjenis(JenisSoal::Uraian)->create([
        'scoring_config_json' => ['bobot' => 5],
    ]);

    $uraian->rubrik()->create([
        'school_id' => $uraian->school_id,
        'criterion' => 'Ketepatan konsep',
        'description' => 'Jawaban menyebut ketiga syarat.',
        'max_score' => 3,
        'position' => 0,
    ]);

    QuestionPackageItem::create([
        'school_id' => $paket->school_id,
        'question_package_id' => $paket->id,
        'question_id' => $pg->id,
        'points_override' => null,
        'position' => 1,
    ]);

    QuestionPackageItem::create([
        'school_id' => $paket->school_id,
        'question_package_id' => $paket->id,
        'question_id' => $uraian->id,
        'points_override' => null,
        'position' => 2,
    ]);

    return $paket;
}

/** Ujian yang lahir dari sebuah paket, lewat pintu yang sama dengan produksi. */
function ujianDariPaket(SchoolEvent $event, Subject $mapel, ?QuestionPackage $paket, User $admin): Exam
{
    return app(ExamRegistrar::class)->buat($event, [
        'subject_id' => $mapel->id,
        'question_package_id' => $paket?->id,
        'title' => 'UAS '.$mapel->name,
        'exam_type' => 'Ujian akhir semester',
        'duration_minutes' => 90,
        'class_id' => [],
    ], $admin->id);
}

/* ============================================================= Pembekuan */

it('tidak menulis baris snapshot untuk ujian tanpa paket', function () {
    $ujian = ujianDariPaket($this->event, $this->mapel, null, $this->admin);

    // Ujian tanpa paket adalah keadaan yang SAH - Admin menyusun jadwal lebih
    // dulu, paketnya dipasang belakangan. Yang menegakkannya gerbang kesiapan
    // event, bukan pembekuan ini.
    expect(ExamQuestion::query()->where('exam_id', $ujian->id)->count())->toBe(0)
        ->and($ujian->question_package_id)->toBeNull();
});

it('menyalin seluruh butir paket saat ujian dibuat', function () {
    $paket = paketDuaSoal($this->guru, $this->mapel);

    $ujian = ujianDariPaket($this->event, $this->mapel, $paket, $this->admin);

    $snapshot = ExamQuestion::query()->where('exam_id', $ujian->id)->orderBy('position')->get();

    expect($snapshot)->toHaveCount(2)

        // Nomor urut ikut tersalin: urutan penulisan paket yang menentukan
        // soal mana yang pertama, bukan urutan baris masuk basis data.
        ->and($snapshot[0]->position)->toBe(1)
        ->and($snapshot[1]->position)->toBe(2)

        // Asal-usulnya tercatat, meski tanpa foreign key - lihat migrasinya.
        ->and($snapshot[0]->question_id)->not->toBeNull()

        // Judul dan kode ikut disalin, sehingga daftar soal ujian bisa dibaca
        // tanpa menyentuh bank soal sama sekali.
        ->and($snapshot[0]->kode)->toBe($paket->butir[0]->question->code);
});

it('menyimpan kunci, bobot, dan rubrik di dalam snapshot', function () {
    $paket = paketDuaSoal($this->guru, $this->mapel);

    $ujian = ujianDariPaket($this->event, $this->mapel, $paket, $this->admin);

    $pg = ExamQuestion::query()->where('exam_id', $ujian->id)->where('jenis', JenisSoal::PilihanGanda)->first();
    $uraian = ExamQuestion::query()->where('exam_id', $ujian->id)->where('jenis', JenisSoal::Uraian)->first();

    // Tiga hal yang kriteria selesai blueprint sebut satu per satu:
    // "disnapshot tanpa kehilangan kunci, bobot, atau rubrik."
    expect($pg->kunci_json)->toBe(['opsi' => ['a']])
        ->and((float) $pg->bobot)->toBe(2.0)
        ->and($pg->opsi_json)->toHaveCount(2)

        // Rubrik uraian, tanpa itu Fase 8 tidak punya pedoman mengoreksi.
        ->and($uraian->rubrik_json)->toHaveCount(1)
        ->and($uraian->rubrik_json[0]['kriteria'])->toBe('Ketepatan konsep')
        ->and((float) $uraian->bobot)->toBe(5.0)

        // Uraian tidak punya kunci, dan itu benar - bukan kolom yang lupa diisi.
        ->and($uraian->kunci_json)->toBeNull();
});

it('memakai bobot override paket, bukan bobot soalnya', function () {
    $paket = paketDuaSoal($this->guru, $this->mapel);

    // Butir pertama diberi 10 poin di paket ini, meski soalnya berbobot 2.
    QuestionPackageItem::query()
        ->where('question_package_id', $paket->id)
        ->where('position', 1)
        ->update(['points_override' => 10]);

    $ujian = ujianDariPaket($this->event, $this->mapel, $paket, $this->admin);

    // Yang dibekukan adalah HASIL AKHIRNYA. Menyalin rumusnya berarti bobot
    // ujian berubah saat paketnya disunting sesudah ujian lahir - dan ujian
    // yang sedang berjalan tidak boleh berubah nilainya.
    expect((float) ExamQuestion::query()
        ->where('exam_id', $ujian->id)->where('position', 1)->value('bobot'))->toBe(10.0);
});

it('menolak paket kosong saat ujian dibuat', function () {
    $kosong = QuestionPackage::factory()->oleh($this->guru, $this->mapel)->siap()->create([
        'name' => 'Paket Kosong',
    ]);

    // Ujian tanpa soal bisa dijadwalkan, ditempati 300 siswa, dan baru ketahuan
    // kosong saat tokennya dibuka. Satu-satunya tempat penolakan itu berguna
    // adalah sebelum ujiannya tersimpan.
    expect(fn () => ujianDariPaket($this->event, $this->mapel, $kosong, $this->admin))
        ->toThrow(ValidationException::class);

    // Dan yang menentukan: ujiannya TIDAK ikut tersimpan. Transaksinya dibatalkan
    // seluruhnya - bukan hanya snapshotnya yang batal.
    expect(Exam::query()->where('question_package_id', $kosong->id)->exists())->toBeFalse();
});

/* ======================================================== Kekekalan snapshot */

it('mengubah soal asli tidak mengubah snapshot', function () {
    $paket = paketDuaSoal($this->guru, $this->mapel);

    $ujian = ujianDariPaket($this->event, $this->mapel, $paket, $this->admin);

    $soal = $paket->butir[0]->question;

    // Guru menyunting soalnya setelah ujian dijadwalkan - persis peristiwa
    // yang blueprint `:312` sebut.
    $soal->update([
        'title' => 'Judul yang sudah diubah',
        'prompt_json' => ['teks' => '<p>Soal yang sudah diubah.</p>'],
        'answer_key_json' => ['opsi' => ['b']],
    ]);

    QuestionOption::query()->where('question_id', $soal->id)->delete();

    $snapshot = ExamQuestion::query()->where('exam_id', $ujian->id)->where('position', 1)->first();

    // Snapshotnya utuh. Kalau baris ini gagal, tabel `exam_questions` bisa
    // diganti dengan foreign key biasa dan tidak ada yang berkurang.
    expect($snapshot->judul)->not->toBe('Judul yang sudah diubah')
        ->and($snapshot->kunci_json)->toBe(['opsi' => ['a']])
        ->and($snapshot->opsi_json)->toHaveCount(2)
        ->and($snapshot->soal_json['teks'])->not->toContain('sudah diubah');
});

it('menghapus butir paket tidak menghapus snapshot', function () {
    $paket = paketDuaSoal($this->guru, $this->mapel);

    $ujian = ujianDariPaket($this->event, $this->mapel, $paket, $this->admin);

    QuestionPackageItem::query()->where('question_package_id', $paket->id)->delete();

    expect(ExamQuestion::query()->where('exam_id', $ujian->id)->count())->toBe(2);
});

it('membekukan ulang membuang snapshot lama, bukan menambahinya', function () {
    $paket = paketDuaSoal($this->guru, $this->mapel);

    $ujian = ujianDariPaket($this->event, $this->mapel, $paket, $this->admin);

    // Paketnya menyusut menjadi satu butir, lalu ujiannya dibekukan ulang -
    // keadaan yang terjadi kalau Admin memasang paket lain ke mapel event dan
    // pembekuan dijalankan lagi.
    QuestionPackageItem::query()
        ->where('question_package_id', $paket->id)
        ->where('position', 2)
        ->delete();

    app(PembekuPaket::class)->bekukan($ujian);

    // SATU baris, bukan tiga. Snapshot yang ditambahi berarti ujian berisi
    // sisa paket lama yang tidak lagi disetujui siapa pun.
    expect(ExamQuestion::query()->where('exam_id', $ujian->id)->count())->toBe(1);
});

/* ========================================================= Laman siswa */

it('tidak pernah mengirim kunci jawaban ke browser siswa', function () {
    $paket = paketDuaSoal($this->guru, $this->mapel);

    $ujian = ujianDariPaket($this->event, $this->mapel, $paket, $this->admin);

    $pg = ExamQuestion::query()->where('exam_id', $ujian->id)->where('position', 1)->first();
    $uraian = ExamQuestion::query()->where('exam_id', $ujian->id)->where('position', 2)->first();

    $untukSiswa = $pg->untukSiswa();

    // Lapis 1 - bentuk payload. WAJIB hijau sejak Fase 4, dan tetap hijau
    // sesudahnya: ini satu-satunya bentuk yang Fase 7 boleh pakai.
    expect($untukSiswa)->not->toHaveKeys(['kunci_json', 'rubrik_json', 'pembahasan_json'])

        // Ketiganya BENAR-BENAR ada di modelnya, sehingga test ini tidak lulus
        // karena kolomnya kebetulan kosong.
        ->and($pg->kunci_json)->not->toBeNull()
        ->and($pg->pembahasan_json)->not->toBeNull()
        ->and($uraian->rubrik_json)->not->toBeNull()

        // Rubrik uraian juga tidak boleh lolos lewat jalur mana pun.
        ->and($uraian->untukSiswa())->not->toHaveKeys(['kunci_json', 'rubrik_json', 'pembahasan_json'])

        // Yang boleh dibaca siswa tetap ada: tanpa `skor` ia tidak tahu bobot
        // tiap nomor, dan itu bagian dari aturan main.
        ->and($untukSiswa)->toHaveKeys(['id', 'position', 'bobot', 'jenis', 'soal', 'opsi', 'skor'])
        ->and($untukSiswa['bobot'])->toBe(2.0);

    // Lapis 2 - bentuk yang BENAR-BENAR dikirim route siswa - sekarang ada di
    // `SiswaUjianTest`, test "tidak pernah mengirim kunci, rubrik, atau
    // pembahasan ke laman pengerjaan". Ia membaca respons `/siswa/ujian/{id}/kerjakan`
    // dan menelusuri props-nya, sehingga yang diperiksa di sana bukan method ini
    // melainkan pemanggilnya. Dua lapis itu memang berbeda: lapis 1 gagal bila
    // `untukSiswa()` berubah bentuk, lapis 2 gagal bila route-nya berhenti
    // memakainya - dan yang kedua tidak akan tertangkap oleh lapis 1 sendirian.
});

/* ============================================================ Keterangan */

it('membedakan soal yang otomatis dan yang perlu dikoreksi', function () {
    $paket = paketDuaSoal($this->guru, $this->mapel);

    $ujian = ujianDariPaket($this->event, $this->mapel, $paket, $this->admin);

    $snapshot = ExamQuestion::query()->where('exam_id', $ujian->id)->orderBy('position')->get();

    // Fase 8 memakainya untuk memisahkan yang bisa dinilai mesin dari yang
    // menunggu korektor. Dihitung dari JENISNYA, bukan dari ada-tidaknya rubrik,
    // supaya satu soal uraian tanpa rubrik tidak diam-diam dianggap otomatis.
    expect($snapshot[0]->perluKoreksiManual())->toBeFalse()
        ->and($snapshot[1]->perluKoreksiManual())->toBeTrue();
});

it('menyimpan butir jodoh dan urut apa adanya', function () {
    $paket = QuestionPackage::factory()->oleh($this->guru, $this->mapel)->siap()->create([
        'name' => 'Paket Jodoh',
    ]);

    $jodoh = Question::factory()->oleh($this->guru, $this->mapel)->berjenis(JenisSoal::Menjodohkan)->create();

    QuestionItem::create([
        'school_id' => $jodoh->school_id,
        'question_id' => $jodoh->id,
        'item_group' => 'kiri',
        'item_key' => 'k1',
        'content_json' => ['teks' => '<p>Tokyo</p>'],
        'correct_match_key' => 'c1',
        'correct_position' => null,
        'position' => 0,
    ]);

    QuestionItem::create([
        'school_id' => $jodoh->school_id,
        'question_id' => $jodoh->id,
        'item_group' => 'kanan',
        'item_key' => 'c1',
        'content_json' => ['teks' => '<p>Jepang</p>'],
        'correct_match_key' => null,
        'correct_position' => null,
        'position' => 1,
    ]);

    // Pasangan kedua, supaya kuncinya utuh dua pasang - dan itu menuntut
    // keempat barisnya ada. Kunci yang menyebut pasangan tanpa barisnya adalah
    // soal yang tidak bisa dijawab.
    QuestionItem::create([
        'school_id' => $jodoh->school_id,
        'question_id' => $jodoh->id,
        'item_group' => 'kiri',
        'item_key' => 'k2',
        'content_json' => ['teks' => '<p>Paris</p>'],
        'correct_match_key' => 'c2',
        'correct_position' => null,
        'position' => 2,
    ]);

    QuestionItem::create([
        'school_id' => $jodoh->school_id,
        'question_id' => $jodoh->id,
        'item_group' => 'kanan',
        'item_key' => 'c2',
        'content_json' => ['teks' => '<p>Prancis</p>'],
        'correct_match_key' => null,
        'correct_position' => null,
        'position' => 3,
    ]);

    QuestionPackageItem::create([
        'school_id' => $paket->school_id,
        'question_package_id' => $paket->id,
        'question_id' => $jodoh->id,
        'points_override' => null,
        'position' => 1,
    ]);

    $ujian = ujianDariPaket($this->event, $this->mapel, $paket, $this->admin);

    $snapshot = ExamQuestion::query()->where('exam_id', $ujian->id)->first();

    // Dua sisi DAN pasangannya ikut tersalin. Soal jodoh yang kehilangan sisi
    // kanannya adalah soal yang tidak bisa dijawab sama sekali.
    expect($snapshot->butir_json)->toHaveCount(4)
        ->and($snapshot->kunci_json)->toBe(['pasangan' => ['k1' => 'c1', 'k2' => 'c2']])
        ->and(collect($snapshot->butir_json)->pluck('grup')->unique()->sort()->values()->all())
        ->toBe(['kanan', 'kiri']);
});
