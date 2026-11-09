<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Models\Question;
use App\Domain\Tenancy\Models\PengaturanAi;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Generate soal dengan AI - tombol di laman builder Guru.
 *
 * =====================================================================
 * Klaim yang paling penting di berkas ini bukan "AI bisa membuat soal"
 * =====================================================================
 * Melainkan: **endpoint ini tidak punya jalur tulis ke bank soal.** Test
 * yang membuktikannya adalah test yang mengirim keluaran model yang bentuknya
 * salah (dua jawaban benar untuk pilihan ganda, kunci `c` untuk benar/salah,
 * uraian yang membawa kunci, uraian tanpa rubrik) lalu memeriksa dua hal
 * sekaligus: 422 dengan pesan Indonesia, dan jumlah baris `questions` yang
 * tidak berubah.
 *
 * Test kebahagiaan akan lulus juga pada sistem yang langsung menyimpan
 * keluaran AI - karena pada keluaran yang benar, menyimpan dan tidak
 * menyimpan menghasilkan layar yang sama.
 *
 * =====================================================================
 * `Http::fake()` di setiap test, tanpa kecuali
 * =====================================================================
 * Bukan demi kecepatan. Satu test yang lupa memalsukan akan menghubungi
 * penyedia sungguhan - dengan biaya nyata, dari mesin pengembang, setiap
 * kali suite dijalankan.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->lain = School::factory()->create();

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    $this->mapel = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MTK',
        'name' => 'Matematika',
    ]);

    $this->mapelLain = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'FIS',
        'name' => 'Fisika',
    ]);

    $this->kelas = SchoolClass::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);

    $this->guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'guru.ai.soal',
    ]);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.ai.soal',
    ]);

    // Guru hanya mengampu Matematika. Fisika sengaja milik orang lain:
    // tanpa mapel kedua, "tidak diampu" tidak bisa dibedakan dari "mapelnya
    // tidak ada".
    TeachingAssignment::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'teacher_id' => $this->guru->id,
        'subject_id' => $this->mapel->id,
        'class_id' => $this->kelas->id,
    ]);

    PengaturanAi::factory()->create([
        'school_id' => $this->sekolah->id,
        'base_url' => 'https://api.contoh.test/v1/',
        'model' => 'model-contoh',
        'api_key_encrypted' => Crypt::encryptString('sk-rahasia-abcdef'),
        'aktif' => true,
    ]);
});

/**
 * Palsukan jawaban penyedia dengan satu objek JSON di dalam `content`.
 *
 * @param  array<string, mixed>  $isi
 */
function jawabAi(array $isi, int $status = 200)
{
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode($isi, JSON_UNESCAPED_UNICODE)]]],
        ], $status),
    ]);
}

function masukSoalAi(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/** Payload permintaan Generate. */
function mintaAi(array $ganti = []): array
{
    return array_merge([
        'subject_id' => null,
        'jenis' => 'pilihan_ganda',
        'topik' => 'Himpunan dan operasi pada himpunan',
        'tingkat' => 'sedang',
    ], $ganti);
}

/* ----------------------------------------------------- Empat jenis soal */

it('mengisi bentuk pilihan ganda dengan huruf opsi yang urut', function () {
    jawabAi([
        'judul' => 'Himpunan penyelesaian',
        'pertanyaan' => '<p>Himpunan penyelesaian dari 2x + 5 = 17 adalah</p>',
        'pembahasan' => '<p>Kurangi 5, lalu bagi 2.</p>',
        'opsi' => [
            ['kunci' => 'a', 'teks' => '6'],
            ['kunci' => 'b', 'teks' => '11'],
            ['kunci' => 'c', 'teks' => '12'],
            ['kunci' => 'd', 'teks' => '22'],
        ],
        'kunci' => ['a'],
    ]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
        ->assertOk()
        ->assertJson([
            'jenis' => 'pilihan_ganda',
            'kunci' => ['a'],
            'judul' => 'Himpunan penyelesaian',
        ])
        ->assertJsonCount(4, 'opsi')
        ->assertJsonPath('opsi.0.kunci', 'a')
        ->assertJsonPath('opsi.3.kunci', 'd');
});

it('mengisi bentuk benar/salah dan menormalkan kunci yang bukan b/s', function () {
    jawabAi([
        'judul' => 'Sifat bilangan prima',
        'pertanyaan' => '<p>2 adalah bilangan prima.</p>',
        'pembahasan' => '<p>2 hanya habis dibagi 1 dan dirinya sendiri.</p>',
        'opsi' => [['kunci' => 'b', 'teks' => 'Benar'], ['kunci' => 's', 'teks' => 'Salah']],
        // Model yang membalas "benar" bukan kegagalan: bentuk kuncinya
        // dinormalkan, dan yang dinormalkan tetap divalidasi sesudahnya.
        'kunci' => ['benar'],
    ]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jenis' => 'benar_salah',
        ]))
        ->assertOk()
        ->assertJson(['jenis' => 'benar_salah', 'kunci' => ['b']])
        ->assertJsonCount(2, 'opsi');
});

it('mengisi bentuk isian singkat dengan varian jawaban', function () {
    jawabAi([
        'judul' => 'Ibu kota Indonesia',
        'pertanyaan' => '<p>Ibu kota Indonesia adalah ...</p>',
        'pembahasan' => '<p>Jakarta.</p>',
        'opsi' => [],
        'kunci' => ['Jakarta', 'DKI Jakarta'],
    ]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jenis' => 'isian_singkat',
        ]))
        ->assertOk()
        ->assertJson(['jenis' => 'isian_singkat', 'varian' => ['Jakarta', 'DKI Jakarta']]);
});

it('mengisi bentuk uraian dengan rubrik dan kunci kosong', function () {
    jawabAi([
        'judul' => 'Jelaskan proses fotosintesis',
        'pertanyaan' => '<p>Jelaskan proses fotosintesis.</p>',
        'pembahasan' => '<p>Fotosintesis mengubah cahaya menjadi energi kimia.</p>',
        'opsi' => [],
        'kunci' => [],
        'rubrik' => [
            ['kriteria' => 'Menyebut bahan', 'keterangan' => 'Air dan karbon dioksida', 'skor_maks' => 2],
            ['kriteria' => 'Menyebut hasil', 'keterangan' => 'Glukosa dan oksigen', 'skor_maks' => 3],
        ],
    ]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jenis' => 'uraian',
        ]))
        ->assertOk()
        ->assertJson(['jenis' => 'uraian', 'kunci' => []])
        ->assertJsonCount(2, 'rubrik')
        ->assertJsonPath('rubrik.0.skor_maks', 2);
});

/* ------------------------------------------------------- Bentuk HTTP-nya */

it('memanggil alamat penyedia dengan bentuk OpenAI-compatible', function () {
    jawabAi([
        'judul' => 'Soal',
        'pertanyaan' => '<p>Pertanyaan</p>',
        'pembahasan' => '<p>Pembahasan</p>',
        'opsi' => [
            ['kunci' => 'a', 'teks' => '1'],
            ['kunci' => 'b', 'teks' => '2'],
            ['kunci' => 'c', 'teks' => '3'],
            ['kunci' => 'd', 'teks' => '4'],
        ],
        'kunci' => ['b'],
    ]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
        ->assertOk();

    Http::assertSent(function ($request) {
        $badan = $request->data();

        return $request->url() === 'https://api.contoh.test/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-rahasia-abcdef')
            && $badan['model'] === 'model-contoh'
            && $badan['response_format'] === ['type' => 'json_object']
            && $badan['max_tokens'] === 1500;
    });
});

it('tetap mengurai jawaban yang dibungkus pagar kode', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => "```json\n".json_encode([
                'judul' => 'Soal berpagar',
                'pertanyaan' => '<p>Pertanyaan</p>',
                'opsi' => [
                    ['kunci' => 'a', 'teks' => '1'],
                    ['kunci' => 'b', 'teks' => '2'],
                    ['kunci' => 'c', 'teks' => '3'],
                    ['kunci' => 'd', 'teks' => '4'],
                ],
                'kunci' => ['c'],
            ])."\n```"]]],
        ]),
    ]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
        ->assertOk()
        ->assertJson(['judul' => 'Soal berpagar', 'kunci' => ['c']]);
});

it('mencoba ulang sekali tanpa response_format saat penyedia menolaknya', function () {
    // Penyedia seperti Ollama menolak parameter itu dengan 400. Tanpa
    // fallback, penyedia yang sepenuhnya sah tampak tidak bisa dipakai.
    Http::fakeSequence('*/chat/completions')
        ->push(['error' => ['message' => 'unknown field response_format']], 400)
        ->push(['choices' => [['message' => ['content' => json_encode([
            'judul' => 'Soal kedua',
            'pertanyaan' => '<p>Pertanyaan</p>',
            'opsi' => [
                ['kunci' => 'a', 'teks' => '1'],
                ['kunci' => 'b', 'teks' => '2'],
                ['kunci' => 'c', 'teks' => '3'],
                ['kunci' => 'd', 'teks' => '4'],
            ],
            'kunci' => ['d'],
        ])]]]], 200);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
        ->assertOk()
        ->assertJson(['kunci' => ['d']]);

    Http::assertSentCount(2);

    // Percobaan kedua tidak memuat parameter yang ditolak - kalau ia masih
    // memuatnya, penyedia akan menolak lagi dan percobaan ulangnya sia-sia.
    $kedua = Http::recorded()[1][0];

    expect($kedua->data())->not->toHaveKey('response_format');
});

/* ------------------------------------------------------ Keamanan keluaran */

it('membersihkan HTML berbahaya dari keluaran AI', function () {
    jawabAi([
        'judul' => '<script>alert(1)</script>Judul soal',
        'pertanyaan' => '<p onclick="alert(1)">Aman<script>alert(2)</script></p><img src=x onerror="alert(3)">',
        'pembahasan' => '<a href="javascript:alert(4)">tautan</a><p>Pembahasan</p>',
        'opsi' => [
            ['kunci' => 'a', 'teks' => '<b>1</b><script>alert(5)</script>'],
            ['kunci' => 'b', 'teks' => '2'],
            ['kunci' => 'c', 'teks' => '3'],
            ['kunci' => 'd', 'teks' => '4'],
        ],
        'kunci' => ['a'],
    ]);

    $jawaban = masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
        ->assertOk();

    $mentah = json_encode($jawaban->json(), JSON_UNESCAPED_SLASHES);

    // Keluaran model adalah HTML pihak ketiga yang akan sampai ke peramban
    // Guru LALU siswa. Satu prompt yang membujuk model menulis
    // `<img onerror=...>` adalah XSS ke seluruh ruang ujian.
    expect($mentah)->not->toContain('<script')
        ->and($mentah)->not->toContain('onerror')
        ->and($mentah)->not->toContain('javascript:')
        ->and($mentah)->not->toContain('onclick');

    // Yang sah tetap dipertahankan - pembersihan bukan pembuangan markup.
    expect($jawaban->json('pertanyaan'))->toContain('<p>');
});

it('tidak memuat kunci API di respons sukses', function () {
    jawabAi([
        'judul' => 'Soal',
        'pertanyaan' => '<p>Pertanyaan</p>',
        'opsi' => [
            ['kunci' => 'a', 'teks' => '1'],
            ['kunci' => 'b', 'teks' => '2'],
            ['kunci' => 'c', 'teks' => '3'],
            ['kunci' => 'd', 'teks' => '4'],
        ],
        'kunci' => ['a'],
    ]);

    $ok = masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
        ->assertOk();

    expect(json_encode($ok->json()))->not->toContain('sk-rahasia-abcdef');
});

it('tidak memuat kunci API di respons galat penyedia', function () {
    // Diuji di test TERPISAH, bukan di test yang sama: `Http::fake()`
    // MENGGABUNG stub, tidak menggantinya - fake kedua di test yang sama
    // tidak pernah menang atas fake pertama, dan test akan lulus dengan
    // 200 tanpa pernah menyentuh jalur galat.
    Http::fake([
        '*/chat/completions' => Http::response(
            ['error' => ['message' => 'Incorrect API key provided: sk-rahasia-abcdef']],
            401,
        ),
    ]);

    // Pesan penyedia yang memantulkan kunci tidak boleh diteruskan ke layar Guru.
    $gagal = masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
        ->assertStatus(422);

    expect(json_encode($gagal->json()))->not->toContain('sk-rahasia-abcdef');
});

/* --------------------------------------------- Keluaran yang tidak sah */

it('menolak keluaran pilihan ganda dengan dua jawaban benar dan tidak menulis apa pun', function () {
    $sebelum = Question::query()->count();

    jawabAi([
        'judul' => 'Soal',
        'pertanyaan' => '<p>Pertanyaan</p>',
        'opsi' => [
            ['kunci' => 'a', 'teks' => '1'],
            ['kunci' => 'b', 'teks' => '2'],
            ['kunci' => 'c', 'teks' => '3'],
            ['kunci' => 'd', 'teks' => '4'],
        ],
        'kunci' => ['a', 'b'],
    ]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
        ->assertStatus(422)
        ->assertJsonPath('pesan', 'AI mengembalikan soal yang bentuknya tidak sah, dan tidak disimpan. '
            .'Coba lagi, atau susun soalnya secara manual.');

    // Inilah klaim kepala berkas ini: AI tidak punya jalur tulis.
    expect(Question::query()->count())->toBe($sebelum);
});

it('menolak kunci benar/salah yang bukan b atau s', function () {
    jawabAi([
        'judul' => 'Soal',
        'pertanyaan' => '<p>Pertanyaan</p>',
        'opsi' => [['kunci' => 'b', 'teks' => 'Benar'], ['kunci' => 's', 'teks' => 'Salah']],
        // Guru mengira sedang menulis pilihan ganda. Dibiarkan lewat berarti
        // soal benar/salah dengan kunci yang tidak bisa dipilih siswa.
        'kunci' => ['c'],
    ]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jenis' => 'benar_salah',
        ]))
        ->assertStatus(422);
});

it('menolak uraian tanpa rubrik', function () {
    jawabAi([
        'judul' => 'Soal',
        'pertanyaan' => '<p>Pertanyaan</p>',
        'opsi' => [],
        'kunci' => [],
        'rubrik' => [],
    ]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jenis' => 'uraian',
        ]))
        ->assertStatus(422);

    expect(Question::query()->count())->toBe(0);
});

/* ------------------------------------------------------- Galat penyedia */

it('melaporkan penyedia yang menolak sebagai 422, bukan 500', function () {
    Http::fake(['*/chat/completions' => Http::response(['error' => 'boom'], 500)]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
        ->assertStatus(422)
        ->assertJsonPath('pesan', 'Penyedia AI sedang bermasalah (500). Coba lagi beberapa saat lagi.');
});

it('melaporkan penyedia yang tidak bisa dihubungi dalam bahasa Indonesia', function () {
    Http::fake(['*/chat/completions' => Http::failedConnection()]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
        ->assertStatus(422)
        ->assertJsonPath('pesan', 'Tidak bisa menghubungi penyedia AI. Periksa Base URL dan sambungan internet sekolah.');
});

it('melaporkan AI yang belum diatur sebelum memanggil penyedia', function () {
    PengaturanAi::query()->where('school_id', $this->sekolah->id)->update(['aktif' => false]);

    Http::fake();

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
        ->assertStatus(422)
        ->assertJsonPath('pesan', 'Generate AI belum diatur. Minta Admin mengisi Base URL, model, dan API key '
            .'di Pengaturan sekolah, lalu menyalakan AI di sana.');

    // Sekolah yang mematikan AI tidak boleh punya satu jalur pun yang
    // mengirim permintaan ke penyedia berbayar.
    Http::assertNothingSent();
});

/* -------------------------------------------------------------- Izin */

it('menolak Admin memakai Generate AI', function () {
    Http::fake();

    // `QuestionPolicy::create()` menolak Admin (PRD §3.1: Admin tidak
    // mengubah isi bank soal), dan endpoint ini memakai ability yang sama.
    masukSoalAi($this, $this->admin)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
        ->assertForbidden();

    Http::assertNothingSent();
});

it('menolak Guru meminta soal untuk mapel yang tidak ia ampu', function () {
    Http::fake();

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapelLain->id]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Anda tidak mengampu mata pelajaran tersebut.');

    Http::assertNothingSent();
});

/* ------------------------------------------------------------ Validasi */

it('menolak topik yang terlalu pendek sebelum menghubungi penyedia', function () {
    Http::fake();

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'topik' => 'x',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('topik');

    Http::assertNothingSent();
});

it('menolak jenis soal yang tidak didukung AI', function () {
    Http::fake();

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jenis' => 'menjodohkan',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('jenis');

    Http::assertNothingSent();
});

/* --------------------------------------------------------- Batas laju */

it('membatasi permintaan AI per Guru per menit', function () {
    jawabAi([
        'judul' => 'Soal',
        'pertanyaan' => '<p>Pertanyaan</p>',
        'opsi' => [
            ['kunci' => 'a', 'teks' => '1'],
            ['kunci' => 'b', 'teks' => '2'],
            ['kunci' => 'c', 'teks' => '3'],
            ['kunci' => 'd', 'teks' => '4'],
        ],
        'kunci' => ['a'],
    ]);

    for ($i = 0; $i < 10; $i++) {
        masukSoalAi($this, $this->guru)
            ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
            ->assertOk();
    }

    // Permintaan ke-11 dijawab JSON, bukan redirect: pemanggilnya `fetch`
    // dari modal, dan redirect akan diterima sebagai HTML yang gagal
    // diurai - Guru melihat "kesalahan" tanpa sebab.
    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
        ->assertStatus(429)
        ->assertJsonPath('pesan', 'Terlalu banyak permintaan AI. Tunggu satu menit, lalu coba lagi.');
});
