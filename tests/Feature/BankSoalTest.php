<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Models\Question;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Fase 4 - bank soal. Wireframe-peran.html plate 01-03, PRD §3.1, §16.5, §24 #5.
 *
 * =====================================================================
 * Tiga klaim yang berkas ini jaga, dan masing-masing punya pembuktian penolakan
 * =====================================================================
 *
 *   1. BANK SOAL ADALAH ASET SEKOLAH, BUKAN CATATAN PRIBADI.
 *      Yang membuktikannya bukan "Guru A melihat soal Guru B" - test
 *      kebahagiaan akan lulus juga pada sistem yang menyaring `created_by`,
 *      karena soal itu memang milik Guru B dan testnya menanyakan Guru B.
 *      Yang membuktikannya adalah Guru A melihat soal Guru B badannya tidak
 *      difilter.
 *
 *   2. "TIDAK BISA DILIHAT" DAN "TIDAK BISA DIUBAH" ADALAH DUA JAWABAN YANG
 *      BERBEDA: 404 dan 403. Test yang hanya memeriksa "ditolak" akan lulus
 *      pada sistem yang menjawab 404 untuk keduanya - dan sistem itu membuat
 *      Guru mengira datanya hilang, karena soalnya baru saja tampil di daftar.
 *
 *   3. KUNCI JAWABAN TIDAK PERNAH MELINTAS DI PAYLOAD DAFTAR. Testnya adalah
 *      `->missing()`, bukan pemeriksaan bahwa Guru tidak bisa mengubahnya:
 *      yang dijaga adalah bentuk respons, karena kunci yang tidak dikirim
 *      tidak bisa bocor.
 *
 * ---------------------------------------------------------------------
 * Helper di berkas ini, bukan di ExamTest
 * ---------------------------------------------------------------------
 * `masukSebagai()` ada di ExamTest.php. Berkas test tidak saling mengimpor
 * fungsi, dan memindahkannya ke Pest.php berarti setiap berkas test menerima
 * helper yang hanya dipakai dua. Karena itu versi kecilnya ditulis di sini -
 * dengan `forget()` yang sama dan alasan yang sama: lihat docblock panjang di
 * ExamTest.php tentang SchoolResolver yang menyimpan `null` dari pembangunan
 * di luar permintaan.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->lain = School::factory()->create();

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    // Dua Guru di sekolah yang sama, satu mapel yang mereka ampu bersama.
    // Inilah bentuk yang membuat bank soal bersama punya arti: satu mapel,
    // beberapa Guru, satu kumpulan soal.
    $this->mapel = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MTK',
        'name' => 'Matematika',
    ]);

    // Mapel kedua yang hanya diampu Guru A - dipakai test "mapel yang tidak
    // diampu". Tanpa mapel ini, "tidak diampu" tidak bisa dibedakan dari
    // "mapelnya tidak ada".
    $this->mapelLain = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'FIS',
        'name' => 'Fisika',
    ]);

    $this->kelas = SchoolClass::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);

    $this->guruA = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'guru.a',
        'name' => 'Guru A',
    ]);

    $this->guruB = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'guru.b',
        'name' => 'Guru B',
    ]);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.bank',
    ]);

    foreach ([[$this->guruA, $this->mapel], [$this->guruB, $this->mapel], [$this->guruA, $this->mapelLain]] as [$guru, $mapel]) {
        TeachingAssignment::factory()->create([
            'school_id' => $this->sekolah->id,
            'academic_year_id' => $this->tahun->id,
            'teacher_id' => $guru->id,
            'subject_id' => $mapel->id,
            'class_id' => $this->kelas->id,
        ]);
    }
});

function masukBank(TestCase $test, User $user): TestCase
{
    // Lihat docblock beforeAll berkas ini untuk mengapa baris ini ada.
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/** Payload satu soal pilihan ganda yang sah. */
function payloadSoal(array $ganti = []): array
{
    return array_merge([
        'subject_id' => null,
        'code' => null,
        'title' => 'Himpunan penyelesaian',
        'question_type' => 'pilihan_ganda',
        'difficulty' => 'sedang',
        'status' => 'draft',
        'prompt_json' => ['teks' => '<p>Himpunan penyelesaian dari 2x + 5 = 17</p>'],
        'answer_key_json' => ['opsi' => ['a']],
        'scoring_config_json' => ['bobot' => 2],
        'opsi' => [
            ['option_key' => 'a', 'content_json' => ['teks' => '6'], 'position' => 0],
            ['option_key' => 'b', 'content_json' => ['teks' => '11'], 'position' => 1],
            ['option_key' => 'c', 'content_json' => ['teks' => '12'], 'position' => 2],
            ['option_key' => 'd', 'content_json' => ['teks' => '22'], 'position' => 3],
        ],
    ], $ganti);
}

/* ============================================================ Daftar */

it('menampilkan bank soal lintas penulis untuk mapel yang diampu', function () {
    Question::factory()->oleh($this->guruB, $this->mapel)->create(['title' => 'Soal Guru B']);

    $respons = masukBank($this, $this->guruA)->get('/guru/bank-soal');

    $respons->assertOk();
    $respons->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->component('Guru/BankSoal/Index')
        // INTI klaim pertama: Guru A melihat soal Guru B. Menambahkan
        // `where('created_by', auth()->id())` di controller akan mematikan
        // test ini - dan itu tepat, karena filter itu membatalkan keputusan
        // PRD §24 #5.
        ->has('baris.data', 1)
        ->where('baris.data.0.penulis', 'Guru B')
        ->where('baris.data.0.milik_saya', false)
    );
});

it('tidak pernah mengirim kunci jawaban di payload daftar', function () {
    Question::factory()->oleh($this->guruA, $this->mapel)->create();

    $respons = masukBank($this, $this->guruA)->get('/guru/bank-soal');

    $respons->assertOk();

    // Diperiksa pada JSON mentah, bukan pada props: `assertInertia->missing()`
    // memeriksa props, sedangkan yang bocor di sistem yang salah adalah seluruh
    // badan respons - `answer_key_json` bisa ikut lewat model yang diserialkan
    // apa adanya.
    $isi = $respons->getContent();

    expect($isi)->not->toContain('answer_key_json');
    expect($isi)->not->toContain('"kunci"');
});

it('menyaring daftar pada mapel yang diampu saja', function () {
    Question::factory()->oleh($this->guruA, $this->mapel)->create();
    Question::factory()->oleh($this->guruB, $this->mapelLain)->create();

    $respons = masukBank($this, $this->guruA)->get('/guru/bank-soal');

    // Guru A mengampu Matematika DAN Fisika, jadi keduanya tampil. Yang
    // diuji di sini adalah bahwa penyaringan benar-benar ada dan bukan
    // kebetulan: satu baris mapel dihapus dari penugasan, dan daftarnya
    // menyusut.
    $respons->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->has('baris.data', 2)
    );

    TeachingAssignment::query()
        ->where('teacher_id', $this->guruA->id)
        ->where('subject_id', $this->mapelLain->id)
        ->delete();

    app(SchoolResolver::class)->forget();

    masukBank($this, $this->guruA)
        ->get('/guru/bank-soal')
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('baris.data', 1));
});

it('menampilkan seluruh bank soal sekolah kepada Admin', function () {
    Question::factory()->oleh($this->guruA, $this->mapel)->create();
    Question::factory()->oleh($this->guruB, $this->mapelLain)->create();

    masukBank($this, $this->admin)
        ->get('/guru/bank-soal')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('baris.data', 2));
});

/* ============================================================ Simpan */

it('menyimpan soal baru beserta pilihan jawabannya', function () {
    $respons = masukBank($this, $this->guruA)->post('/guru/bank-soal', payloadSoal([
        'subject_id' => $this->mapel->id,
    ]));

    $respons->assertRedirect('/guru/bank-soal');

    $soal = Question::tanpaTenant()->firstOrFail();

    expect($soal->created_by)->toBe($this->guruA->id)
        ->and($soal->school_id)->toBe($this->sekolah->id)
        // Kode dibangkitkan service karena payload tidak menyebutnya.
        ->and($soal->code)->toStartWith('Q-'.$this->mapel->id.'-')
        ->and($soal->question_type)->toBe(JenisSoal::PilihanGanda);

    expect($soal->options)->toHaveCount(4);

    // `is_correct` DITURUNKAN dari kunci, bukan dari payload - dan itulah
    // satu-satunya cara keduanya dijamin sepakat. Payload di atas tidak
    // pernah menyebut is_correct.
    $benar = $soal->options->where('is_correct', true)->pluck('option_key')->all();
    expect($benar)->toBe(['a']);
});

it('memakai kode yang diberikan Guru bila diisi', function () {
    masukBank($this, $this->guruA)->post('/guru/bank-soal', payloadSoal([
        'subject_id' => $this->mapel->id,
        'code' => 'MTK-001',
    ]));

    expect(Question::tanpaTenant()->firstOrFail()->code)->toBe('MTK-001');
});

it('membersihkan HTML berbahaya sebelum menyimpan', function () {
    masukBank($this, $this->guruA)->post('/guru/bank-soal', payloadSoal([
        'subject_id' => $this->mapel->id,
        'prompt_json' => [
            'teks' => '<p onclick="jahat()">Hitung</p><script>alert(1)</script>',
        ],
    ]));

    $teks = Question::tanpaTenant()->firstOrFail()->prompt_json['teks'];

    expect($teks)->toContain('Hitung')
        ->and($teks)->not->toContain('<script')
        ->and($teks)->not->toContain('onclick');
});

it('menyimpan format teks pilihan jawaban, dan membersihkan HTML berbahayanya', function () {
    // Pilihan jawaban kini ditulis lewat editor berformat yang sama dengan
    // teks soal (pangkat untuk x², miring untuk judul, tebal untuk tekanan).
    // Yang menyimpannya tetap `content_json.teks`, dan pembersihnya tetap
    // PembersihHtml - jadi bentuk yang diizinkan bertahan, sisanya dibuang.
    masukBank($this, $this->guruA)->post('/guru/bank-soal', payloadSoal([
        'subject_id' => $this->mapel->id,
        'answer_key_json' => ['opsi' => ['a']],
        'opsi' => [
            ['option_key' => 'a', 'content_json' => ['teks' => '<b>6</b> dan x<sup>2</sup>'], 'position' => 0],
            ['option_key' => 'b', 'content_json' => ['teks' => '<img src=x onerror="jahat()">11'], 'position' => 1],
        ],
    ]));

    $opsi = Question::tanpaTenant()->firstOrFail()->options->keyBy('option_key');

    expect($opsi['a']->content_json['teks'])
        ->toContain('<b>6</b>')
        ->toContain('<sup>2</sup>');

    // Tag di luar daftar putih dibuang, atribut berbahaya hilang, dan sisa
    // teksnya tetap terbaca - bukan kotak kosong.
    expect($opsi['b']->content_json['teks'])
        ->not->toContain('onerror')
        ->not->toContain('<img')
        ->toContain('11');
});

it('menolak soal untuk mapel yang tidak diampu dengan 422', function () {
    // 422, bukan 403: Guru memang boleh menyusun soal - yang salah adalah
    // NILAI mapel yang ia kirim. 403 akan berarti "perannya tidak boleh
    // menyusun soal", dan itu tidak benar.
    $respons = masukBank($this, $this->guruB)->post('/guru/bank-soal', payloadSoal([
        'subject_id' => $this->mapelLain->id,
    ]));

    $respons->assertStatus(422);

    expect(Question::tanpaTenant()->count())->toBe(0);
});

it('menolak kunci pilihan ganda yang tidak ada di antara pilihannya', function () {
    $respons = masukBank($this, $this->guruA)->post('/guru/bank-soal', payloadSoal([
        'subject_id' => $this->mapel->id,
        'answer_key_json' => ['opsi' => ['z']],
    ]));

    $respons->assertSessionHasErrors('kunci');
    expect(Question::tanpaTenant()->count())->toBe(0);
});

it('menolak soal uraian tanpa rubrik', function () {
    $respons = masukBank($this, $this->guruA)->post('/guru/bank-soal', payloadSoal([
        'subject_id' => $this->mapel->id,
        'question_type' => 'uraian',
        'answer_key_json' => null,
        'opsi' => [],
    ]));

    $respons->assertSessionHasErrors('rubrik');
});

it('menolak rubrik uraian yang jumlahnya tidak sama dengan bobotnya', function () {
    // Kejadian nyata: rubrik 3+4+3 pada butir berbobot 1 tersimpan rapi, lalu
    // di laman koreksi SETIAP kriteria ditolak `PenilaiUraian` - sehingga
    // jawabannya tidak pernah bisa dikoreksi dan hasil event-nya tidak
    // pernah bisa diterbitkan. Yang salah bukan koreksinya, melainkan
    // soalnya, dan tempat memperbaikinya adalah di sini.
    $respons = masukBank($this, $this->guruA)->post('/guru/bank-soal', payloadSoal([
        'subject_id' => $this->mapel->id,
        'question_type' => 'uraian',
        'answer_key_json' => null,
        'scoring_config_json' => ['bobot' => 1],
        'opsi' => [],
        'rubrik' => [
            ['criterion' => 'Bobot nilai tempat', 'description' => null, 'max_score' => 3, 'position' => 0],
            ['criterion' => 'Menghitung hasil', 'description' => null, 'max_score' => 4, 'position' => 1],
            ['criterion' => 'Kesimpulan', 'description' => null, 'max_score' => 3, 'position' => 2],
        ],
    ]));

    $respons->assertSessionHasErrors('rubrik');

    // Dan soal yang jumlahnya COCOK tetap tersimpan - aturannya menolak
    // ketidakcocokan, bukan uraian.
    masukBank($this, $this->guruA)->post('/guru/bank-soal', payloadSoal([
        'subject_id' => $this->mapel->id,
        'question_type' => 'uraian',
        'answer_key_json' => null,
        'scoring_config_json' => ['bobot' => 10],
        'opsi' => [],
        'rubrik' => [
            ['criterion' => 'Bobot nilai tempat', 'description' => null, 'max_score' => 3, 'position' => 0],
            ['criterion' => 'Menghitung hasil', 'description' => null, 'max_score' => 4, 'position' => 1],
            ['criterion' => 'Kesimpulan', 'description' => null, 'max_score' => 3, 'position' => 2],
        ],
    ]))->assertSessionHasNoErrors();

    expect(Question::query()->where('question_type', 'uraian')->count())->toBe(1);
});

it('menolak bobot nol', function () {
    $respons = masukBank($this, $this->guruA)->post('/guru/bank-soal', payloadSoal([
        'subject_id' => $this->mapel->id,
        'scoring_config_json' => ['bobot' => 0],
    ]));

    // Ditolak dua kali: sekali oleh FormRequest (`gt:0`) dan sekali oleh
    // SkemaJenisSoal. Yang diuji di sini adalah bahwa salah satunya bekerja -
    // dan yang penting adalah tidak ada baris yang tersimpan.
    $respons->assertSessionHasErrors('scoring_config_json.bobot');
    expect(Question::tanpaTenant()->count())->toBe(0);
});

it('menyimpan soal menjodohkan beserta pasangannya', function () {
    masukBank($this, $this->guruA)->post('/guru/bank-soal', payloadSoal([
        'subject_id' => $this->mapel->id,
        'question_type' => 'menjodohkan',
        'answer_key_json' => ['pasangan' => ['k1' => 'c1', 'k2' => 'c2']],
        'opsi' => [],
        'butir' => [
            ['item_key' => 'k1', 'item_group' => 'kiri', 'content_json' => ['teks' => 'Kubus'], 'position' => 0],
            ['item_key' => 'k2', 'item_group' => 'kiri', 'content_json' => ['teks' => 'Bola'], 'position' => 1],
            ['item_key' => 'c1', 'item_group' => 'kanan', 'content_json' => ['teks' => 's³'], 'position' => 0],
            ['item_key' => 'c2', 'item_group' => 'kanan', 'content_json' => ['teks' => '4/3 πr³'], 'position' => 1],
        ],
    ]))->assertRedirect();

    $soal = Question::tanpaTenant()->firstOrFail();
    expect($soal->items)->toHaveCount(4);
});

it('menyimpan soal isian rumpang beserta kuncinya', function () {
    masukBank($this, $this->guruA)->post('/guru/bank-soal', payloadSoal([
        'subject_id' => $this->mapel->id,
        'question_type' => 'isian_rumpang',
        'prompt_json' => ['teks' => '<p>Gaya tarik bumi disebut {{1}}, satuannya {{2}}.</p>'],
        'answer_key_json' => ['rumpang' => [['gravitasi', 'gaya gravitasi'], ['newton']]],
        'opsi' => [],
    ]))->assertRedirect('/guru/bank-soal');

    $soal = Question::tanpaTenant()->firstOrFail();

    expect($soal->question_type)->toBe(JenisSoal::IsianRumpang)
        // Bentuknya diperiksa UTUH: array-dari-array, bukan objek berkunci
        // nomor. Inilah yang membuat lubang 2 tidak tertukar dengan lubang 10
        // ketika `JSON.stringify` mengurutkan kuncinya sebagai teks.
        ->and($soal->answer_key_json)->toBe(['rumpang' => [['gravitasi', 'gaya gravitasi'], ['newton']]]);

    // Penanda {{n}} harus SELAMAT melewati pembersih HTML - kalau `{`/`}`
    // di-escape menjadi entitas, teks soalnya tersimpan tanpa satu pun lubang
    // dan soal yang sah berubah menjadi kalimat biasa di layar siswa.
    expect($soal->prompt_json['teks'])->toContain('{{1}}')->toContain('{{2}}');
});

it('menolak rumpang yang jumlah penandanya tidak sama dengan kuncinya', function () {
    $respons = masukBank($this, $this->guruA)->post('/guru/bank-soal', payloadSoal([
        'subject_id' => $this->mapel->id,
        'question_type' => 'isian_rumpang',
        'prompt_json' => ['teks' => '<p>Sebutkan {{1}} dan {{2}}.</p>'],
        'answer_key_json' => ['rumpang' => [['gravitasi']]],
        'opsi' => [],
    ]));

    $respons->assertSessionHasErrors('prompt_json');
    expect(Question::tanpaTenant()->count())->toBe(0);
});

/* ======================================================== Ubah & hapus */

it('mengubah soal milik sendiri', function () {
    $soal = Question::factory()->oleh($this->guruA, $this->mapel)->create();

    masukBank($this, $this->guruA)
        ->put("/guru/bank-soal/{$soal->id}", payloadSoal([
            'subject_id' => $this->mapel->id,
            'title' => 'Judul baru',
        ]))
        ->assertRedirect('/guru/bank-soal');

    expect($soal->fresh()->title)->toBe('Judul baru');
});

it('menolak Guru lain mengubah soal dengan 403, bukan 404', function () {
    $soal = Question::factory()->oleh($this->guruB, $this->mapel)->create();

    // INTI klaim kedua. Guru A MELIHAT soal ini di daftarnya - ia mengampu
    // Matematika. Karena terlihat, jawabannya harus 403: barisnya ada, dan
    // yang ditolak adalah hak mengubahnya. 404 di sini akan berarti sistem
    // berpura-pura soalnya tidak ada, padahal Guru A baru saja melihatnya.
    masukBank($this, $this->guruA)
        ->put("/guru/bank-soal/{$soal->id}", payloadSoal([
            'subject_id' => $this->mapel->id,
            'title' => 'Dibajak',
        ]))
        ->assertStatus(403);

    expect($soal->fresh()->title)->not->toBe('Dibajak');
});

it('menolak Admin mengubah isi soal dengan 403', function () {
    $soal = Question::factory()->oleh($this->guruA, $this->mapel)->create();

    // PRD §3.1 memberi Admin "Tidak" untuk bank soal. Yang penting di sini:
    // Admin BOLEH memandang laman bank soal (AksesGuru meloloskannya), dan
    // karena itu penolakannya 403 - bukan 404.
    masukBank($this, $this->admin)
        ->put("/guru/bank-soal/{$soal->id}", payloadSoal([
            'subject_id' => $this->mapel->id,
            'title' => 'Disunting admin',
        ]))
        ->assertStatus(403);

    expect($soal->fresh()->title)->not->toBe('Disunting admin');
});

it('membuka laman ubah soal Guru lain tanpa tombol simpan', function () {
    $soal = Question::factory()->oleh($this->guruB, $this->mapel)->create();

    // 200, bukan 403: Guru A berhak MELIHAT. Yang tidak boleh adalah
    // menyimpannya, dan itu dinyatakan lewat prop `bolehUbah` - sehingga
    // tombolnya tidak dirender, bukan dirender lalu gagal.
    masukBank($this, $this->guruA)
        ->get("/guru/bank-soal/{$soal->id}/ubah")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Guru/BankSoal/Builder')
            ->where('bolehUbah', false)
        );
});

it('mengarsipkan soal alih-alih menghapusnya', function () {
    $soal = Question::factory()->oleh($this->guruA, $this->mapel)->siap()->create();

    masukBank($this, $this->guruA)
        ->post("/guru/bank-soal/{$soal->id}/arsip")
        ->assertRedirect();

    $segar = $soal->fresh();

    // Barisnya MASIH ADA. Soal yang pernah dipakai ujian dirujuk snapshot
    // dan laporan nilai; menghapusnya memutus penelusuran "soal ini dari
    // mana", dan itu kerusakan yang tidak bisa diperbaiki setelah kejadian.
    expect($segar)->not->toBeNull()
        ->and($segar->status)->toBe('arsip')
        ->and($segar->published_at)->toBeNull();
});

it('menolak mengarsipkan soal Guru lain dengan 403', function () {
    $soal = Question::factory()->oleh($this->guruB, $this->mapel)->create();

    masukBank($this, $this->guruA)
        ->post("/guru/bank-soal/{$soal->id}/arsip")
        ->assertStatus(403);

    expect($soal->fresh()->status)->not->toBe('arsip');
});

it('menduplikat soal Guru lain menjadi milik sendiri', function () {
    $soal = Question::factory()->oleh($this->guruB, $this->mapel)->create(['status' => 'siap']);

    masukBank($this, $this->guruA)
        ->post("/guru/bank-soal/{$soal->id}/duplikat")
        ->assertRedirect();

    $salinan = Question::tanpaTenant()
        ->where('created_by', $this->guruA->id)
        ->firstOrFail();

    expect($salinan->id)->not->toBe($soal->id)
        // Kode baru, bukan kode asal: unique(school_id, subject_id, code)
        // akan menolaknya, dan galat basis data itu tidak berarti apa pun
        // bagi Guru yang menekan tombol Duplikat.
        ->and($salinan->code)->not->toBe($soal->code)
        // Salinan selalu Draf, apa pun status asalnya: Guru yang menduplikat
        // belum tentu sudah memeriksa isinya.
        ->and($salinan->status)->toBe('draft')
        ->and($salinan->published_at)->toBeNull();
});

/* ========================================================== Isolasi */

it('mengembalikan 404 untuk soal sekolah lain', function () {
    $mapelLain = Subject::factory()->untukSekolah($this->lain)->create();
    $guruLain = User::factory()->guru()->untukSekolah($this->lain)->create();
    $soal = Question::factory()->oleh($guruLain, $mapelLain)->create();

    // 404, tanpa kecuali: menyebut soal sekolah lain sebagai "ada tetapi
    // bukan milikmu" sudah membocorkan bahwa barisnya ada.
    masukBank($this, $this->guruA)
        ->get("/guru/bank-soal/{$soal->id}/ubah")
        ->assertNotFound();

    masukBank($this, $this->guruA)
        ->put("/guru/bank-soal/{$soal->id}", payloadSoal(['subject_id' => $this->mapel->id]))
        ->assertNotFound();
});

it('mengembalikan 404 untuk soal mapel yang tidak diampu Guru lain', function () {
    // Guru B TIDAK mengampu Fisika. Soal ini tidak pernah muncul di
    // daftarnya, sehingga 404 adalah jawaban yang benar: bagi dia, soalnya
    // memang tidak ada. Inilah sisi lain dari pembedaan 404/403.
    $soal = Question::factory()->oleh($this->guruA, $this->mapelLain)->create();

    masukBank($this, $this->guruB)
        ->get("/guru/bank-soal/{$soal->id}/ubah")
        ->assertNotFound();
});

/* ======================================================== Pintu peran */

it('menolak siswa dan pengawas masuk konsol guru', function () {
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create();
    $pengawas = User::factory()->pengawas()->untukSekolah($this->sekolah)->create();

    // 403, bukan 404: keberadaan /guru bukan rahasia - setiap Guru tahu
    // menunya ada. Yang ditolak adalah aksesnya. Tanpa middleware `guru`,
    // Siswa akan menerima daftar soal ujiannya sendiri di sini.
    masukBank($this, $siswa)->get('/guru')->assertForbidden();
    masukBank($this, $siswa)->get('/guru/bank-soal')->assertForbidden();
    masukBank($this, $pengawas)->get('/guru/bank-soal')->assertForbidden();
});

it('mengalihkan tamu ke laman masuk', function () {
    $this->get('/guru/bank-soal')->assertRedirect('/login');
});

it('membuka beranda guru bagi Guru', function () {
    masukBank($this, $this->guruA)
        ->get('/guru')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Guru/Dashboard')
            ->has('ringkasan.soal')
        );
});

it('menghitung KPI beranda dari baris yang benar saja', function () {
    Question::factory()->oleh($this->guruA, $this->mapel)->count(2)->create();
    Question::factory()->oleh($this->guruB, $this->mapel)->create();

    masukBank($this, $this->guruA)
        ->get('/guru')
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            // "Soal SAYA" - satu-satunya tempat `created_by` memang
            // memfilter, dan itu karena pertanyaannya "berapa yang saya
            // tulis", bukan "berapa yang bisa saya pakai".
            ->where('ringkasan.soal', 2)
            // Guru A punya dua penugasan (Matematika dan Fisika) di SATU
            // kelas. `distinct` itulah bedanya: tanpa itu angkanya 2.
            ->where('ringkasan.kelas', 1)
        );
});
