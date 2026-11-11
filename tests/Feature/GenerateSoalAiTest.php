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

it('mencoba ulang sekali dengan anggaran lebih besar saat model kehabisan token untuk berpikir', function () {
    // Model penalaran menghabiskan `max_tokens` pada `reasoning_content` dan
    // berhenti sebelum satu baris JSON pun lahir: `finish_reason` menjadi
    // "length" dan `content` kosong. Kalau dibiarkan, `urai()` melaporkan
    // "bukan JSON" pada jawaban yang sebenarnya tidak pernah ada - dan ini
    // persis yang terjadi pada isian rumpang dengan anggaran satu soal
    // (pengamatan langsung, 2026-11-11: kena separuh dari percobaan, sementara
    // pilihan ganda hampir selalu lolos).
    Http::fakeSequence('*/chat/completions')
        ->push([
            'choices' => [[
                'finish_reason' => 'length',
                'message' => ['role' => 'assistant', 'content' => null],
            ]],
            'usage' => ['completion_tokens' => 1500],
        ], 200)
        ->push(['choices' => [['message' => ['content' => json_encode([
            'judul' => 'Soal yang akhirnya lahir',
            'pertanyaan' => '<p>Pertanyaan</p>',
            'opsi' => [
                ['kunci' => 'a', 'teks' => '1'],
                ['kunci' => 'b', 'teks' => '2'],
                ['kunci' => 'c', 'teks' => '3'],
                ['kunci' => 'd', 'teks' => '4'],
            ],
            'kunci' => ['b'],
        ])]]]], 200);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi(['subject_id' => $this->mapel->id]))
        ->assertOk()
        ->assertJson(['kunci' => ['b']]);

    Http::assertSentCount(2);

    // Percobaan kedua menggandakan anggaran token: model yang kehabisan
    // tempat berpikir tidak akan menulis apa pun di kolom yang sama.
    $kedua = Http::recorded()[1][0];

    expect($kedua->data()['max_tokens'])->toBe(3000);
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

/* ------------------------------------------------------ Beberapa soal */
//
// Satu permintaan mengembalikan N soal. Bentuknya `{soal: [...]}` HANYA
// ketika N > 1; permintaan satu soal tetap datar, dan test-test di atas
// adalah buktinya - seluruhnya membaca `judul`, `kunci`, `opsi` langsung dari
// akar jawaban.

/** Satu soal pilihan ganda yang sah, dengan penanda agar mudah dibedakan. */
function soalPilihanGanda(string $judul, string $kunci = 'a'): array
{
    return [
        'judul' => $judul,
        'pertanyaan' => "<p>Pertanyaan {$judul}</p>",
        'pembahasan' => '<p>Pembahasan.</p>',
        'opsi' => [
            ['kunci' => 'a', 'teks' => '1'],
            ['kunci' => 'b', 'teks' => '2'],
            ['kunci' => 'c', 'teks' => '3'],
            ['kunci' => 'd', 'teks' => '4'],
        ],
        'kunci' => [$kunci],
    ];
}

it('mengembalikan tiga soal dalam satu larik saat diminta tiga', function () {
    jawabAi(['soal' => [
        soalPilihanGanda('Soal satu', 'a'),
        soalPilihanGanda('Soal dua', 'b'),
        soalPilihanGanda('Soal tiga', 'c'),
    ]]);

    $jawaban = masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jumlah' => 3,
        ]))
        ->assertOk()
        ->assertJsonCount(3, 'soal');

    // Ketiganya soal yang BERBEDA, bukan satu soal yang diulang: itulah satu-
    // satunya alasan meminta tiga sekaligus.
    expect($jawaban->json('soal.*.judul'))->toBe(['Soal satu', 'Soal dua', 'Soal tiga'])
        ->and($jawaban->json('soal.*.kunci'))->toBe([['a'], ['b'], ['c']]);

    // Satu panggilan HTTP, bukan tiga. Permintaan berulang berarti biaya
    // berulang untuk hasil yang sama.
    Http::assertSentCount(1);
});

it('meminta model membalas larik bernama saat jumlah lebih dari satu', function () {
    jawabAi(['soal' => [soalPilihanGanda('Satu'), soalPilihanGanda('Dua')]]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jumlah' => 2,
        ]))
        ->assertOk();

    Http::assertSent(function ($request) {
        $pesan = $request->data()['messages'] ?? [];
        $sistem = $pesan[0]['content'] ?? '';
        $pengguna = $pesan[1]['content'] ?? '';

        // Pembungkusnya HARUS objek berkunci `soal`: `KlienAi::urai()` menolak
        // larik telanjang, jadi model yang membalas `[...]` akan gagal pada
        // keluaran yang sebenarnya benar.
        return str_contains($sistem, '"soal"')
            && str_contains($sistem, 'tepat 2 soal')
            && str_contains($pengguna, 'Buat 2 soal berbeda');
    });
});

it('menaikkan batas token dan waktu tunggu seiring jumlah soal', function () {
    jawabAi(['soal' => [soalPilihanGanda('Satu'), soalPilihanGanda('Dua'), soalPilihanGanda('Tiga')]]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jumlah' => 3,
        ]))
        ->assertOk();

    // Batas token di Pengaturan diisi untuk SATU soal (1500). Tiga soal yang
    // dijanjikan 1500 token akan terpotong di soal kedua, dan JSON terpotong
    // bukan JSON yang kurang - ia tidak bisa diurai sama sekali.
    Http::assertSent(fn ($request) => $request->data()['max_tokens'] === 4500);
});

it('membatalkan SELURUH permintaan bila satu soal cacat', function () {
    $sebelum = Question::query()->count();

    jawabAi(['soal' => [
        soalPilihanGanda('Soal baik', 'a'),
        // Dua jawaban benar untuk pilihan ganda tunggal: cacat, dan tidak
        // bisa diperbaiki dengan menebak.
        array_replace(soalPilihanGanda('Soal cacat', 'a'), ['kunci' => ['a', 'b']]),
        soalPilihanGanda('Soal baik lagi', 'c'),
    ]]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jumlah' => 3,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('pesan', 'AI mengembalikan soal yang bentuknya tidak sah, dan tidak disimpan. '
            .'Coba lagi, atau susun soalnya secara manual.');

    expect(Question::query()->count())->toBe($sebelum);
});

it('menolak keluaran tanpa larik soal saat diminta lebih dari satu', function () {
    // Model yang tetap membalas satu objek meski diminta tiga tidak boleh
    // dianggap tiga soal kosong - atau, lebih buruk, satu soal yang diterima
    // diam-diam sehingga Guru mengira tiga soalnya sudah datang.
    jawabAi(soalPilihanGanda('Hanya satu'));

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jumlah' => 3,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('pesan', 'Penyedia AI menjawab, tetapi bukan dalam bentuk JSON yang diminta. '
            .'Modelnya mungkin tidak mendukung mode JSON - coba model lain di Pengaturan.');
});

it('menolak model yang mengembalikan soal lebih banyak dari yang diminta', function () {
    jawabAi(['soal' => [
        soalPilihanGanda('Satu'), soalPilihanGanda('Dua'), soalPilihanGanda('Tiga'),
    ]]);

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jumlah' => 2,
        ]))
        ->assertStatus(422);

    // Memotong diam-diam ke dua soal berarti Guru menyimpan soal yang tidak
    // ia minta, dengan tagihan untuk tiga.
    expect(Question::query()->count())->toBe(0);
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
            // `media` - BUKAN `menjodohkan`: menjodohkan sudah didukung
            // sejak mode lanjut (2026-11-10). Media tetap ditolak karena
            // stimulusnya berupa berkas, dan model tidak bisa menghasilkannya -
            // soal "media" dari AI hanyalah soal biasa berlabel salah.
            'jenis' => 'media',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('jenis');

    Http::assertNothingSent();
});

it('menolak jumlah soal di luar satu sampai sepuluh', function (int $jumlah) {
    Http::fake();

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jumlah' => $jumlah,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('jumlah');

    // Ditolak SEBELUM menghubungi penyedia: permintaan yang ditolak setelah
    // terkirim tetap ditagih.
    Http::assertNothingSent();
})->with([0, 11]);

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

/* -------------------------------------------------------- Mode lanjut */

it('membuat lebih dari satu jenis dalam satu permintaan', function () {
    // Satu permintaan campuran = satu panggilan penyedia PER JENIS. Jawaban
    // penyedia dipalsukan bergiliran supaya tiga bentuk berbeda bisa diuji
    // sekaligus.
    Http::fakeSequence('*/chat/completions')
        ->push(['choices' => [['message' => ['content' => json_encode([
            'judul' => 'Himpunan',
            'pertanyaan' => '<p>Anggota {1,2,3} adalah</p>',
            'opsi' => [
                ['kunci' => 'a', 'teks' => '1, 2, 3'],
                ['kunci' => 'b', 'teks' => '1, 2'],
                ['kunci' => 'c', 'teks' => '2, 3'],
                ['kunci' => 'd', 'teks' => '3'],
            ],
            'kunci' => ['a'],
        ], JSON_UNESCAPED_UNICODE)]]]], 200)
        ->push(['choices' => [['message' => ['content' => json_encode([
            'judul' => 'Bilangan prima',
            'pertanyaan' => '<p>3 adalah bilangan prima.</p>',
            'opsi' => [['kunci' => 'b', 'teks' => 'Benar'], ['kunci' => 's', 'teks' => 'Salah']],
            'kunci' => ['benar'],
        ], JSON_UNESCAPED_UNICODE)]]]], 200)
        ->push(['choices' => [['message' => ['content' => json_encode([
            'judul' => 'Ibu kota',
            'pertanyaan' => '<p>Ibu kota Indonesia adalah ...</p>',
            'kunci' => ['Jakarta'],
        ], JSON_UNESCAPED_UNICODE)]]]], 200);

    $jawab = masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jenis' => ['pilihan_ganda', 'benar_salah', 'isian_singkat'],
            'jumlah' => 3,
        ]))
        ->assertOk()
        ->json();

    expect($jawab['soal'])->toHaveCount(3)
        ->and(array_column($jawab['soal'], 'jenis'))
        ->toBe(['pilihan_ganda', 'benar_salah', 'isian_singkat']);

    // Tiga jenis = tiga panggilan penyedia, bukan satu permintaan longgar
    // yang meminta model memilih bentuk per unsur.
    Http::assertSentCount(3);
});

it('menolak campuran yang memuat satu jenis tak didukung', function () {
    Http::fake();

    masukSoalAi($this, $this->guru)
        ->postJson('/guru/bank-soal/ai', mintaAi([
            'subject_id' => $this->mapel->id,
            'jenis' => ['pilihan_ganda', 'media'],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('jenis');

    Http::assertNothingSent();
});

/* --------------------------------------------------------- Jalur simpan */ it('menyimpan soal yang dicentang ke bank soal', function () {
    $sebelum = Question::query()->count();

    masukSoalAi($this, $this->guru)
        ->post('/guru/bank-soal/ai/simpan', [
            'subject_id' => $this->mapel->id,
            'soal' => [[
                'jenis' => 'pilihan_ganda',
                'tingkat' => 'sulit',
                'judul' => 'Himpunan penyelesaian',
                'pertanyaan' => '<p>Himpunan penyelesaian 2x + 5 = 17 adalah</p>',
                'pembahasan' => '<p>Kurangi 5, bagi 2.</p>',
                'opsi' => [
                    ['kunci' => 'a', 'teks' => '6'],
                    ['kunci' => 'b', 'teks' => '11'],
                    ['kunci' => 'c', 'teks' => '12'],
                    ['kunci' => 'd', 'teks' => '22'],
                ],
                'kunci' => ['a'],
            ]],
        ])
        ->assertRedirect('/guru/bank-soal');

    expect(Question::query()->count())->toBe($sebelum + 1);

    $soal = Question::query()->latest('id')->first();

    expect($soal->question_type->value)->toBe('pilihan_ganda')
        ->and($soal->difficulty)->toBe('sulit')
        ->and($soal->status)->toBe('draft')
        // Kode dibangkitkan BankSoalService, bukan dikirim peramban.
        ->and($soal->code)->toStartWith('Q-')
        ->and($soal->answer_key_json)->toBe(['opsi' => ['a']]);
});

it('menyimpan campuran jenis dalam satu kiriman', function () {
    masukSoalAi($this, $this->guru)
        ->post('/guru/bank-soal/ai/simpan', [
            'subject_id' => $this->mapel->id,
            'soal' => [
                [
                    'jenis' => 'menjodohkan',
                    'judul' => 'Ibu kota negara',
                    'pertanyaan' => '<p>Jodohkan negara dengan ibu kotanya.</p>',
                    'butir' => [
                        ['kunci' => 'k1', 'grup' => 'kiri', 'teks' => 'Indonesia', 'pasangan' => 'p1'],
                        ['kunci' => 'k2', 'grup' => 'kiri', 'teks' => 'Malaysia', 'pasangan' => 'p2'],
                        ['kunci' => 'p1', 'grup' => 'kanan', 'teks' => 'Jakarta', 'pasangan' => null],
                        ['kunci' => 'p2', 'grup' => 'kanan', 'teks' => 'Kuala Lumpur', 'pasangan' => null],
                    ],
                    'pasangan' => ['k1' => 'p1', 'k2' => 'p2'],
                ],
                [
                    'jenis' => 'angka',
                    'judul' => 'Akar kuadrat',
                    'pertanyaan' => '<p>Akar kuadrat dari 144 adalah ...</p>',
                    'nilai' => 12,
                    'toleransi' => 0,
                ],
                [
                    'jenis' => 'uraian',
                    'judul' => 'Jelaskan fotosintesis',
                    'pertanyaan' => '<p>Jelaskan proses fotosintesis.</p>',
                    'rubrik' => [
                        ['kriteria' => 'Menyebut bahan', 'keterangan' => 'Air, CO2', 'skor_maks' => 2],
                        ['kriteria' => 'Menyebut hasil', 'keterangan' => 'Glukosa, O2', 'skor_maks' => 3],
                    ],
                ],
            ],
        ])
        ->assertRedirect('/guru/bank-soal');

    $jenis = Question::query()->orderBy('id')->pluck('question_type')->map->value->all();

    expect($jenis)->toBe(['menjodohkan', 'angka', 'uraian']);

    $angka = Question::query()->where('question_type', 'angka')->first();
    expect($angka->answer_key_json)->toMatchArray(['nilai' => 12.0]);

    // Uraian: kunci WAJIB null, dan rubriknya tersimpan.
    $uraian = Question::query()->where('question_type', 'uraian')->first();
    expect($uraian->answer_key_json)->toBeNull()
        ->and($uraian->rubrik)->toHaveCount(2);
});

it('tidak menulis satu baris pun bila satu soal dalam kiriman cacat', function () {
    $sebelum = Question::query()->count();

    masukSoalAi($this, $this->guru)
        ->post('/guru/bank-soal/ai/simpan', [
            'subject_id' => $this->mapel->id,
            'soal' => [
                [
                    'jenis' => 'isian_singkat',
                    'judul' => 'Ibu kota',
                    'pertanyaan' => '<p>Ibu kota Indonesia.</p>',
                    'varian' => ['Jakarta'],
                ],
                [
                    // Dua jawaban benar untuk pilihan ganda - bentuk yang
                    // ditolak SkemaJenisSoal.
                    'jenis' => 'pilihan_ganda',
                    'judul' => 'Cacat',
                    'pertanyaan' => '<p>Dua jawaban benar.</p>',
                    'opsi' => [
                        ['kunci' => 'a', 'teks' => '1'],
                        ['kunci' => 'b', 'teks' => '2'],
                        ['kunci' => 'c', 'teks' => '3'],
                        ['kunci' => 'd', 'teks' => '4'],
                    ],
                    'kunci' => ['a', 'b'],
                ],
            ],
        ])
        ->assertSessionHasErrors('soal');

    // Seluruh kiriman ditolak: yang pertama sah, tetapi Guru mencentang
    // keduanya dalam satu klik - menyimpan sebagian berarti melaporkan
    // "tersimpan" untuk kiriman yang gagal.
    expect(Question::query()->count())->toBe($sebelum);
});

it('menyebut nomor soal yang gagal disimpan', function () {
    masukSoalAi($this, $this->guru)
        ->post('/guru/bank-soal/ai/simpan', [
            'subject_id' => $this->mapel->id,
            'soal' => [
                ['jenis' => 'isian_singkat', 'judul' => 'Satu', 'pertanyaan' => '<p>a</p>', 'varian' => ['x']],
                ['jenis' => 'isian_singkat', 'judul' => 'Dua', 'pertanyaan' => '<p>b</p>', 'varian' => ['y']],
                ['jenis' => 'isian_singkat', 'judul' => 'Tiga', 'pertanyaan' => '<p>c</p>', 'varian' => []],
            ],
        ])
        ->assertSessionHasErrors('soal');

    // Nomornya DIPERIKSA isinya, bukan hanya keberadaan galatnya: Guru
    // melihat tiga kartu, dan "soal nomor tiga" adalah satu-satunya kalimat
    // yang bisa ia tindak lanjuti.
    expect(session('errors')->first('soal'))->toContain('nomor 3');
});

it('menolak kiriman simpan dari Admin', function () {
    masukSoalAi($this, $this->admin)
        ->post('/guru/bank-soal/ai/simpan', [
            'subject_id' => $this->mapel->id,
            'soal' => [['jenis' => 'isian_singkat', 'judul' => 'x', 'pertanyaan' => '<p>x</p>', 'varian' => ['x']]],
        ])
        ->assertForbidden();

    expect(Question::query()->count())->toBe(0);
});

it('menolak simpan untuk mapel yang tidak diampu', function () {
    masukSoalAi($this, $this->guru)
        ->post('/guru/bank-soal/ai/simpan', [
            'subject_id' => $this->mapelLain->id,
            'soal' => [['jenis' => 'isian_singkat', 'judul' => 'x', 'pertanyaan' => '<p>x</p>', 'varian' => ['x']]],
        ])
        ->assertStatus(422);

    expect(Question::query()->count())->toBe(0);
});

it('menolak kiriman simpan yang melebihi batas', function () {
    masukSoalAi($this, $this->guru)
        ->post('/guru/bank-soal/ai/simpan', [
            'subject_id' => $this->mapel->id,
            'soal' => array_fill(0, 31, [
                'jenis' => 'isian_singkat', 'judul' => 'x', 'pertanyaan' => '<p>x</p>', 'varian' => ['x'],
            ]),
        ])
        ->assertSessionHasErrors('soal');

    expect(Question::query()->count())->toBe(0);
});

/* ----------------------------------------------------- Prop laman daftar */

it('mengirim daftar jenis AI ke laman bank soal', function () {
    masukSoalAi($this, $this->guru)
        ->get('/guru/bank-soal')
        ->assertInertia(fn ($halaman) => $halaman
            ->component('Guru/BankSoal/Index')
            ->where('ai', true)
            // Sembilan jenis, dan `media` TIDAK termasuk: stimulusnya berupa
            // berkas, dan model tidak bisa menghasilkannya.
            ->has('jenisAi', 9)
            ->where('jenisAi.0.nilai', 'pilihan_ganda')
        );
});
