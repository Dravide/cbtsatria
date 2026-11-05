<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionImportBatch;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use App\Support\Impor\PembacaBerkas;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 4 - impor soal bertahap. Wireframe-peran.html plate 04, blueprint `:1225`.
 *
 * =====================================================================
 * Kalimat yang berkas ini jaga
 * =====================================================================
 * Blueprint `:1225`: "Guru membuat satu paket berisi kesembilan jenis soal,
 * mengimpornya lewat Excel dengan setidaknya satu galat yang dilaporkan tepat
 * barisnya, lalu paket itu dapat disnapshot tanpa kehilangan kunci, bobot,
 * atau rubrik."
 *
 * Bagian "galat yang dilaporkan tepat barisnya" adalah kalimat yang paling
 * mudah dipenuhi secara teknis dan paling mudah gagal secara nyata: importer
 * yang melaporkan "baris 2 gagal" untuk galat di baris 7 lulus setiap
 * pemeriksaan yang hanya menanyakan "apakah ada galat". Karena itu test
 * utamanya menuntut NOMOR YANG DILIHAT GURU DI EXCEL, dan nomor itu adalah
 * nomor baris di dalam berkasnya - bukan nomor urut baris yang berhasil
 * diurai.
 *
 * =====================================================================
 * Berkasnya dibangun di dalam test, bukan disimpan sebagai fixture
 * =====================================================================
 * Alasan yang sama dengan PembacaBerkasTest: fixture biner hanya membuktikan
 * sesuatu bila ia dibuat alat LAIN. CSV yang ditulis di sini dengan teks
 * terbuka membuat setiap kasus bisa dibaca di tempat - dan yang menentukan di
 * berkas ini adalah bentuk berkasnya, bukan pembacanya (yang sudah diuji
 * tersendiri).
 *
 * Berkasnya memakai `UploadedFile::fake()` supaya unggahannya melewati jalur
 * yang sama dengan unggahan sungguhan, termasuk `getClientOriginalName()`.
 */
beforeEach(function () {
    Storage::fake('local');

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
        'username' => 'impor.guru',
        'name' => 'Guru Impor',
    ]);

    $this->guruLain = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'impor.guru.lain',
    ]);

    foreach ([[$this->guru, $this->mapel], [$this->guru, $this->mapelLain], [$this->guruLain, $this->mapel]] as [$guru, $mapel]) {
        TeachingAssignment::factory()->create([
            'school_id' => $this->sekolah->id,
            'academic_year_id' => $this->tahun->id,
            'teacher_id' => $guru->id,
            'subject_id' => $mapel->id,
            'class_id' => $this->kelas->id,
        ]);
    }
});

function masukImpor(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/**
 * Berkas CSV dari baris-baris sel, memakai pemisah titik koma.
 *
 * Titik koma, bukan koma - dan itu keadaan yang paling sering datang dari
 * sekolah: Excel berbahasa Indonesia menyimpan CSV-nya bertitik koma, dan
 * pembaca yang hanya menerima koma melihat seluruh barisnya sebagai satu
 * kolom. Mengujinya dengan koma berarti menguji bentuk yang tidak dipakai.
 *
 * @param  array<int, array<int, string>>  $baris
 */
function berkasCsv(array $baris, string $nama = 'soal.csv'): UploadedFile
{
    $isi = "\xEF\xBB\xBF";

    foreach ($baris as $satu) {
        $isi .= implode(';', array_map(fn (string $s) => '"'.str_replace('"', '""', $s).'"', $satu))."\r\n";
    }

    $jalur = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cbt-impor-uji-'.uniqid().'-'.$nama;

    file_put_contents($jalur, $isi);

    return new UploadedFile($jalur, $nama, 'text/csv', null, true);
}

/** Judul kolom sesuai template unduhan. */
function judulKolom(): array
{
    return ['kode', 'judul', 'jenis', 'tingkat', 'bobot', 'soal', 'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'opsi_e', 'kunci', 'rubrik', 'pembahasan'];
}

/**
 * Satu baris soal pilihan ganda yang sah.
 *
 * @return array<int, string>
 */
function barisPg(string $judul = 'Himpunan penyelesaian', string $kunci = 'a'): array
{
    return [
        '', $judul, 'pilihan_ganda', 'mudah', '2',
        'Himpunan penyelesaian dari 2x + 5 = 17 adalah…',
        '6', '11', '12', '22', '',
        $kunci, '', 'Karena 2(6) + 5 = 17.',
    ];
}

/** Paket siap satu butir, milik Guru yang mengimpor. */
function paketImpor(User $guru, Subject $mapel): QuestionPackage
{
    $paket = QuestionPackage::factory()->oleh($guru, $mapel)->create(['name' => 'Paket Impor']);

    $soal = Question::factory()->oleh($guru, $mapel)->create();

    QuestionPackageItem::factory()->create([
        'school_id' => $guru->school_id,
        'question_package_id' => $paket->id,
        'question_id' => $soal->id,
        'position' => 1,
    ]);

    return $paket;
}

/* ============================================================== Unggah */

it('menyimpan berkas di disk privat dengan nama acak, bukan nama aslinya', function () {
    $berkas = berkasCsv([judulKolom(), barisPg()], 'soal_matematika_ganjil.csv');

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => $berkas,
        'subject_id' => $this->mapel->id,
    ]);

    $batch = QuestionImportBatch::query()->firstOrFail();

    // Nama aslinya DIINGAT di kolomnya sendiri - supaya Guru mengenali
    // berkasnya di daftar riwayat.
    expect($batch->nama_asli)->toBe('soal_matematika_ganjil.csv')
        // Dan nama di disknya TIDAK memuatnya: berkas impor memuat seluruh
        // soal satu mapel, dan itu bahan yang belum boleh dibaca siapa pun
        // yang menebak namanya.
        ->and($batch->file_path)->not->toContain('ganjil')
        ->and($batch->file_path)->toStartWith('laporan/impor/'.$this->sekolah->id.'/'.$batch->id.'/');

    Storage::disk('local')->assertExists($batch->file_path);
});

it('menolak mengunggah ulang berkas yang isinya sama', function () {
    // INTI idempotensi. Mengunggah ulang berkas yang sama adalah DUA KALI
    // impor yang sama, dan tanpa penjagaan ini gejalanya bukan galat
    // melainkan bank soal berisi dua salinan setiap soal.
    $isi = [judulKolom(), barisPg()];

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv($isi, 'pertama.csv'),
        'subject_id' => $this->mapel->id,
    ])->assertRedirect();

    $respons = masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv($isi, 'kedua.csv'),
        'subject_id' => $this->mapel->id,
    ]);

    $respons->assertSessionHasErrors('berkas');

    expect(QuestionImportBatch::query()->count())->toBe(1);

    // Nama berkas YANG KEDUA yang disebut, bukan yang pertama: Guru baru saja
    // mengunggahnya, dan pesan yang menyebut berkas lain membuatnya mencari
    // berkas yang tidak ia pilih.
    expect(session('errors')->first('berkas'))->toContain('sudah pernah Anda unggah');
});

it('mengizinkan Guru lain mengunggah berkas yang sama', function () {
    // Hash-nya unik per (sekolah, GURU). Dua Guru yang memakai berkas template
    // sama dari sekolah yang sama tidak melakukan kesalahan apa pun.
    $isi = [judulKolom(), barisPg()];

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv($isi, 'a.csv'),
        'subject_id' => $this->mapel->id,
    ])->assertRedirect();

    masukImpor($this, $this->guruLain)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv($isi, 'b.csv'),
        'subject_id' => $this->mapel->id,
    ])->assertSessionHasNoErrors();

    expect(QuestionImportBatch::query()->count())->toBe(2);
});

it('menolak berkas .xls lama dengan pesan yang menjelaskan sebabnya', function () {
    $jalur = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cbt-lama-'.uniqid().'.xls';
    file_put_contents($jalur, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 200));

    $respons = masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => new UploadedFile($jalur, 'soal.xls', 'application/vnd.ms-excel', null, true),
        'subject_id' => $this->mapel->id,
    ]);

    $respons->assertSessionHasErrors('berkas');

    // Guru yang menyimpan berkasnya sebagai .xls akan mengulangi langkah yang
    // sama bila pesannya tidak menyebutkan apa yang harus ia lakukan.
    expect(session('errors')->first('berkas'))->toContain('.xlsx atau .csv');
});

it('menolak mapel yang tidak diampu Guru', function () {
    $mapelOrangLain = Subject::factory()->untukSekolah($this->sekolah)->create(['name' => 'Kimia']);

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv([judulKolom(), barisPg()]),
        'subject_id' => $mapelOrangLain->id,
    ])->assertSessionHasErrors('subject_id');

    expect(QuestionImportBatch::query()->count())->toBe(0);
});

/* =========================================================== Pratinjau */

it('melaporkan nomor baris 7 dan kolom kunci dengan tepat', function () {
    // INTI seluruh berkas ini, dan seluruh plate 04.
    //
    // Baris 7 di dalam berkas, dan kolom `kunci`. Yang membuat test ini
    // berarti adalah DUA hal yang sekaligus diperiksa: nomornya sama dengan
    // yang dibaca Guru di tepi kiri Excel, dan kolomnya adalah kolom yang
    // benar-benar perlu ia perbaiki.
    //
    // Dua baris rusak di atasnya - baris 6 salah bobotnya - sengaja
    // disisipkan: importer yang melaporkan "baris gagal ke-2" alih-alih
    // "baris 7" akan lulus setiap pemeriksaan yang hanya menanyakan "apakah
    // ada galat".
    $baris = [judulKolom()];

    // 6 baris sah di atasnya, supaya baris rusaknya benar-benar di nomor 7.
    for ($i = 0; $i < 5; $i++) {
        $baris[] = barisPg('Soal nomor '.($i + 2));
    }

    // Baris 7: kuncinya menunjuk pilihan yang tidak ada di kolom opsinya.
    $rusak = barisPg('Kunci menggantung');
    $rusak[11] = 'e';
    $baris[] = $rusak;

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv($baris),
        'subject_id' => $this->mapel->id,
    ])->assertRedirect();

    $batch = QuestionImportBatch::query()->firstOrFail();
    $pratinjau = $batch->pratinjau_json;

    expect($pratinjau['sah'])->toBe(5)
        ->and($pratinjau['gagal'])->toBe(1);

    $gagal = collect($pratinjau['baris'])->firstWhere('sah', false);

    expect($gagal['nomor'])->toBe(7)
        ->and($gagal['kolom'])->toBe('kunci')
        ->and($gagal['pesan'])->toContain('"e"');
});

it('menemukan baris judul kolom meski ada baris kosong di atasnya', function () {
    // Berkas yang disiapkan dari laporan lain sering punya baris judul berkas
    // di atas judul kolomnya. Menolaknya berarti menolak berkas yang isinya
    // benar.
    $berkas = berkasCsv([
        ['Laporan bank soal', '', ''],
        [],
        judulKolom(),
        barisPg(),
    ]);

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => $berkas,
        'subject_id' => $this->mapel->id,
    ])->assertRedirect();

    expect(QuestionImportBatch::query()->firstOrFail()->pratinjau_json['sah'])->toBe(1);
});

it('menolak berkas tanpa kolom wajib dan menyebut kolom yang terbaca', function () {
    // "nama" adalah sinonim "judul", sehingga yang benar-benar hilang hanya
    // kolom "soal" - dan itulah yang harus disebut pesannya.
    $berkas = berkasCsv([
        ['kode', 'nama', 'jenis', 'a', 'b'],
        ['', 'Soal tanpa teks', 'pg', '3', '4'],
    ]);

    $respons = masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => $berkas,
        'subject_id' => $this->mapel->id,
    ]);

    $respons->assertSessionHasErrors('berkas');

    $batch = QuestionImportBatch::query()->firstOrFail();

    expect($batch->status)->toBe('gagal');

    $pesan = $batch->pratinjau_json['pesan'];

    expect($pesan)->toContain('soal')
        ->and($pesan)->toContain('kode')
        // "nama" dan "judul" TIDAK BOLEH muncul di daftar yang HILANG:
        // sinonimnya sudah diterjemahkan, dan menyebutnya hilang akan
        // membuat Guru menambah kolom yang sudah ada.
        ->and(explode('Kolom yang terbaca', $pesan)[0])->not->toContain('judul')
        // Kolom yang TERBACA ikut disebut. Pesan yang hanya menyebut kolom
        // yang hilang meninggalkan pertanyaan yang tidak bisa dijawab Guru
        // sendiri: apakah namanya salah tulis, atau kolomnya memang tidak ada.
        ->and($pesan)->toContain('Kolom yang terbaca')
        ->and(explode('Kolom yang terbaca', $pesan)[1])->toContain('judul');

    // Dan Guru menerima GALAT di layar, bukan lencana hijau "berkas tidak
    // bisa dibaca" - warna lencana adalah seluruh isi pesannya bagi siapa pun
    // yang membacanya sambil lalu.
    expect(session('errors')->first('berkas'))->toBe($pesan);
});

it('memahami sinonim nama kolom', function () {
    // "pertanyaan" untuk soal, "jawaban" untuk kunci, "tipe soal" untuk jenis,
    // "nama" untuk judul. Keempatnya nama yang dipilih separuh orang, dan
    // menolaknya berarti mengajari Guru kosakata yang tidak ia pakai.
    $berkas = berkasCsv([
        ['nama', 'pertanyaan', 'tipe soal', 'jawaban', 'a', 'b'],
        ['Dua tambah dua', '2 + 2 sama dengan…', 'pg', 'b', '3', '4'],
    ]);

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => $berkas,
        'subject_id' => $this->mapel->id,
    ])->assertRedirect();

    $pratinjau = QuestionImportBatch::query()->firstOrFail()->pratinjau_json;

    expect($pratinjau['sah'])->toBe(1);

    // Judulnya benar-benar tersimpan di kolom judul, bukan di kolom lain -
    // dan itulah yang membuktikan sinonimnya diterjemahkan, bukan sekadar
    // tidak menimbulkan galat.
    expect($pratinjau['baris'][0]['muatan']['title'])->toBe('Dua tambah dua');
});

it('melaporkan item jodoh yang pasangannya kurang tepat barisnya', function () {
    // Kegagalan yang paling mahal di jenis menjodohkan, dan yang paling
    // sering terjadi: Guru menambah satu item kiri lalu lupa menambah
    // pasangannya.
    $berkas = berkasCsv([
        judulKolom(),
        ['', 'Ibu kota', 'menjodohkan', 'mudah', '4',
            'Jodohkan provinsi dengan ibu kotanya.',
            'Jawa Barat', 'Jawa Timur', 'Bali', '', '',
            'a=Bandung; b=Surabaya', '', ''],
    ]);

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => $berkas,
        'subject_id' => $this->mapel->id,
    ])->assertRedirect();

    $gagal = collect(QuestionImportBatch::query()->firstOrFail()->pratinjau_json['baris'])->firstWhere('sah', false);

    // Item kiri "c" (Bali) tidak muncul di kolom kunci.
    expect($gagal['nomor'])->toBe(2)
        ->and($gagal['kolom'])->toBe('kunci')
        ->and($gagal['pesan'])->toContain('"c"');
});

it('menolak baris berjenis media dengan sebab, bukan dengan galat skema', function () {
    // Berkasnya tidak bisa dititipkan ke dalam sel Excel. Guru yang membaca
    // "mekanisme tidak dikenal" akan mengira ia salah menulis jenisnya, dan
    // mencoba lagi dengan ejaan lain.
    $baris = barisPg();
    $baris[2] = 'media';

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv([judulKolom(), $baris]),
        'subject_id' => $this->mapel->id,
    ])->assertRedirect();

    $gagal = collect(QuestionImportBatch::query()->firstOrFail()->pratinjau_json['baris'])->firstWhere('sah', false);

    expect($gagal['pesan'])->toContain('berkas')
        ->and($gagal['pesan'])->toContain('builder');
});

it('tidak menulis satu pun soal pada langkah pratinjau', function () {
    // Langkah tengah yang menulis soal adalah langkah tengah yang tidak
    // berguna: Guru kehilangan satu-satunya kesempatannya membatalkan.
    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv([judulKolom(), barisPg(), barisPg('Kedua')]),
        'subject_id' => $this->mapel->id,
    ])->assertRedirect();

    expect(Question::query()->count())->toBe(0)
        ->and(QuestionImportBatch::query()->firstOrFail()->status)->toBe('dipratinjau');
});

/* ========================================================== Konfirmasi */

it('menyimpan hanya baris yang sah', function () {
    $rusak = barisPg('Bobot bukan angka');
    $rusak[4] = 'dua';

    $berkas = berkasCsv([judulKolom(), barisPg('Pertama'), $rusak, barisPg('Ketiga')]);

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => $berkas,
        'subject_id' => $this->mapel->id,
    ])->assertRedirect();

    $batch = QuestionImportBatch::query()->firstOrFail();

    masukImpor($this, $this->guru)->post("/guru/bank-soal/impor/{$batch->id}/konfirmasi", [
        'ke_paket' => false,
    ])->assertRedirect();

    $batch->refresh();

    expect($batch->status)->toBe('dikonfirmasi')
        ->and($batch->success_rows)->toBe(2)
        ->and($batch->failed_rows)->toBe(1)
        ->and($batch->imported_at)->not->toBeNull();

    // Berkas dengan baris rusak TETAP `dikonfirmasi`, bukan `gagal` - dan
    // itulah bedanya: 2 soal benar-benar masuk, dan yang 1 dilaporkan.
    expect(Question::query()->count())->toBe(2);
});

it('menyimpan sepuluh soal sekaligus ke bank soal', function () {
    $baris = [judulKolom()];

    for ($i = 1; $i <= 10; $i++) {
        $baris[] = barisPg('Soal ke-'.$i);
    }

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv($baris),
        'subject_id' => $this->mapel->id,
    ])->assertRedirect();

    $batch = QuestionImportBatch::query()->firstOrFail();

    masukImpor($this, $this->guru)->post("/guru/bank-soal/impor/{$batch->id}/konfirmasi", [
        'ke_paket' => false,
    ]);

    // Setiap soal mendapat KODE yang dibangkitkan, bukan kode kosong: kolom
    // `code` NOT NULL, dan impor tanpa kode adalah impor yang gagal di basis
    // data setelah 50 barisnya diurai.
    $kode = Question::query()->pluck('code');

    expect($kode)->toHaveCount(10)
        ->and($kode->unique())->toHaveCount(10)
        ->and($kode->first())->toStartWith('Q-'.$this->mapel->id.'-');

    // Bobotnya ikut tersimpan, bukan dikembalikan ke bawaan 1.
    expect(Question::query()->first()->scoring_config_json['bobot'])->toEqual(2);
});

it('menambahkan hasil impor ke paket tujuan', function () {
    $paket = paketImpor($this->guru, $this->mapel);

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv([judulKolom(), barisPg('Masuk paket')]),
        'subject_id' => $this->mapel->id,
        'question_package_id' => $paket->id,
    ])->assertRedirect();

    $batch = QuestionImportBatch::query()->firstOrFail();

    expect($batch->question_package_id)->toBe($paket->id);

    masukImpor($this, $this->guru)->post("/guru/bank-soal/impor/{$batch->id}/konfirmasi", [
        'ke_paket' => true,
    ]);

    // Paket sudah berisi satu butir dari helper, jadi hasilnya dua.
    expect($paket->butir()->count())->toBe(2);
});

it('tidak menyentuh paket saat diminta impor ke bank saja', function () {
    // "Impor ke bank saja" bukan hiasan: menyiapkan persediaan umum dan
    // menyiapkan soal untuk satu ujian adalah dua niat yang berbeda.
    $paket = paketImpor($this->guru, $this->mapel);

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv([judulKolom(), barisPg('Ke bank saja')]),
        'subject_id' => $this->mapel->id,
        'question_package_id' => $paket->id,
    ])->assertRedirect();

    $batch = QuestionImportBatch::query()->firstOrFail();

    masukImpor($this, $this->guru)->post("/guru/bank-soal/impor/{$batch->id}/konfirmasi", [
        'ke_paket' => false,
    ]);

    expect($paket->butir()->count())->toBe(1)
        ->and(Question::query()->count())->toBe(2);
});

it('membatalkan seluruh simpanan bila paketnya tidak bisa ditambahi', function () {
    // Paket berisi soal mapel LAIN adalah paket yang ditolak PaketSoalService.
    // Ketika itu terjadi setelah 3 soal tersimpan, transaksinya harus
    // membatalkan semuanya - bukan meninggalkan 3 soal tanpa paketnya.
    $paket = paketImpor($this->guru, $this->mapelLain);

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv([judulKolom(), barisPg('Mapel beda')]),
        'subject_id' => $this->mapel->id,
        'question_package_id' => $paket->id,
    ])->assertRedirect();

    $batch = QuestionImportBatch::query()->firstOrFail();

    masukImpor($this, $this->guru)->post("/guru/bank-soal/impor/{$batch->id}/konfirmasi", [
        'ke_paket' => true,
    ])->assertSessionHasErrors('question_ids');

    expect(Question::query()->count())->toBe(1) // hanya soal bawaan helper
        ->and($batch->refresh()->status)->toBe('dipratinjau');
});

it('menolak konfirmasi kedua atas berkas yang sama', function () {
    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv([judulKolom(), barisPg()]),
        'subject_id' => $this->mapel->id,
    ])->assertRedirect();

    $batch = QuestionImportBatch::query()->firstOrFail();

    $this->actingAs($this->guru)->post("/guru/bank-soal/impor/{$batch->id}/konfirmasi", ['ke_paket' => false]);
    $this->actingAs($this->guru)->post("/guru/bank-soal/impor/{$batch->id}/konfirmasi", ['ke_paket' => false])
        ->assertSessionHasErrors('berkas');

    // INTI: 1 soal, bukan 2. Konfirmasi kedua yang lolos berarti setiap soal
    // di bank soal berjumlah dua kali lipat setelah Guru menekan tombolnya
    // dua kali - dan itu kerusakan yang membereskannya menuntut perbandingan
    // isi soal satu per satu.
    expect(Question::query()->count())->toBe(1);
});

it('menolak konfirmasi berkas yang belum dipratinjau', function () {
    // Baris `menunggu` yang dikonfirmasi langsung berarti menyimpan tanpa
    // pernah diperiksa - dan Guru tidak punya kesempatan melihat baris mana
    // yang rusak.
    $batch = QuestionImportBatch::factory()->untukGuru($this->guru)->create([
        'subject_id' => $this->mapel->id,
        'status' => 'menunggu',
        'pratinjau_json' => null,
    ]);

    masukImpor($this, $this->guru)->post("/guru/bank-soal/impor/{$batch->id}/konfirmasi", [
        'ke_paket' => false,
    ])->assertSessionHasErrors('berkas');

    expect(Question::query()->count())->toBe(0);
});

/* ============================================== Laporan & kewenangan */

it('menyimpan laporan galat di disk privat dan mengizinkan pemiliknya mengunduh', function () {
    $rusak = barisPg('Bobot salah');
    $rusak[4] = 'bukan angka';

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv([judulKolom(), barisPg(), $rusak]),
        'subject_id' => $this->mapel->id,
    ])->assertRedirect();

    $batch = QuestionImportBatch::query()->firstOrFail();

    masukImpor($this, $this->guru)->post("/guru/bank-soal/impor/{$batch->id}/konfirmasi", ['ke_paket' => false]);

    $batch->refresh();

    expect($batch->error_report_path)->not->toBeNull();

    // Laporan disimpan di DISK PRIVAT, bukan di `public/`. Yang membuktikannya
    // bukan jalurnya - `Storage::fake()` mengganti akar disknya - melainkan
    // dua fakta: isinya ada di disk bernama `local`, dan rute unduhannya
    // berwenang. Cakram `local` inilah yang di produksi menunjuk
    // `storage/app/private`, di luar jangkauan peramban.
    Storage::disk('local')->assertExists($batch->error_report_path);
    expect(Storage::disk('local')->get($batch->error_report_path))->not->toBeEmpty();

    // Isinya menyebut nomor baris dan kolomnya, dan nomor itu nomor di Excel.
    $isi = Storage::disk('local')->get($batch->error_report_path);

    expect($isi)->toContain('baris')
        ->and($isi)->toContain('bobot')
        ->and($isi)->toContain('bukan angka')
        ->and($isi)->toContain('3');

    masukImpor($this, $this->guru)
        ->get("/guru/bank-soal/impor/{$batch->id}/laporan")
        ->assertOk()
        ->assertHeader('content-disposition');
});

it('menjawab 404 saat Guru lain membuka laporan impor', function () {
    // 404, bukan 403: baris impor memuat NAMA ASLI berkas Guru lain, dan
    // nama berkas sudah cukup untuk memberitahu apa yang sedang ia susun.
    // Bagi Guru lain, barisnya memang tidak ada.
    $batch = QuestionImportBatch::factory()->untukGuru($this->guru)->create([
        'subject_id' => $this->mapel->id,
        'status' => 'dikonfirmasi',
        'error_report_path' => 'laporan/impor/1/1/laporan.csv',
    ]);

    Storage::disk('local')->put($batch->error_report_path, "baris;kolom;pesan\n");

    masukImpor($this, $this->guruLain)
        ->get("/guru/bank-soal/impor/{$batch->id}/laporan")
        ->assertNotFound();

    // Guru lain juga tidak bisa mengonfirmasi berkasnya.
    masukImpor($this, $this->guruLain)
        ->post("/guru/bank-soal/impor/{$batch->id}/konfirmasi", ['ke_paket' => false])
        ->assertNotFound();
});

it('menyembunyikan riwayat impor Guru lain dari daftarnya', function () {
    QuestionImportBatch::factory()->untukGuru($this->guruLain)->create([
        'subject_id' => $this->mapel->id,
        'nama_asli' => 'milik_guru_lain.csv',
    ]);

    masukImpor($this, $this->guru)->get('/guru/bank-soal/impor')
        ->assertOk()
        ->assertInertia(fn ($halaman) => $halaman
            ->component('Guru/BankSoal/Impor')
            ->has('riwayat', 0)
        );
});

/* =================================================== Template unduhan */

it('mengunduh template CSV dengan nama kolom yang dibaca importer', function () {
    // Template dan pembacanya memakai SATU daftar kolom, dan test ini yang
    // menjaganya tetap satu: template yang menawarkan kolom yang tidak dibaca
    // importer adalah template yang mengajari Guru menulis berkas yang ditolak.
    $respons = masukImpor($this, $this->guru)->get('/guru/bank-soal/impor/template');

    $respons->assertOk();

    // `response()`, bukan `streamDownload()`: isinya empat baris, dan
    // mengalirkannya berarti satu lapisan yang tidak membeli apa pun.
    $respons->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $isi = $respons->getContent();

    // BOM: tanpa itu Excel berbahasa Indonesia merusak huruf beraksennya.
    expect($isi)->toStartWith("\xEF\xBB\xBF");

    // `fputcsv` menutup setiap barisnya dengan "\n" - bukan "\r\n" - dan
    // template ini ditulis dengan `fputcsv` yang sama seperti laporan galat,
    // supaya satu tempat memutuskan bentuk berkasnya.
    $baris = explode("\n", trim(substr($isi, 3)));

    expect($baris[0])->toBe(implode(';', judulKolom()))
        // Tiga baris contoh, satu untuk setiap jenis yang kesepakatan kolom
        // `kunci`-nya berbeda: pilihan ganda, isian singkat, dan uraian.
        ->and($baris)->toHaveCount(4)
        ->and($baris[1])->toContain('pilihan_ganda')
        ->and($baris[2])->toContain('isian_singkat')
        ->and($baris[3])->toContain('uraian');
});

it('menolak impor bagi Admin karena bank soal bukan pekerjaannya', function () {
    $admin = User::factory()->admin()->untukSekolah($this->sekolah)->create();

    masukImpor($this, $admin)->get('/guru/bank-soal/impor')->assertForbidden();
});

/* ======================================================= Sidik jari */

it('menolak berkas yang melebihi batas baris sebelum menyimpan apa pun', function () {
    // 2.000 baris yang diurai seluruhnya lalu ditolak tetap menghabiskan
    // waktu dan memori server. Yang diperiksa di sini adalah bahwa
    // penolakannya terjadi, dan bahwa tidak ada baris impor yang tersisa
    // setengah jadi.
    $baris = [judulKolom()];

    for ($i = 0; $i <= PembacaBerkas::BARIS_MAKS; $i++) {
        $baris[] = barisPg('Soal '.$i);
    }

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv($baris, 'besar.csv'),
        'subject_id' => $this->mapel->id,
    ])->assertSessionHasErrors('berkas');

    expect(Question::query()->count())->toBe(0);
});

it('mengizinkan guru menyusun ulang berkasnya lalu mengimpornya kembali', function () {
    // Skenario lengkap plate 04, dan satu-satunya test yang menjalankannya
    // dari ujung ke ujung: unggah -> dua baris rusak -> perbaiki -> unggah
    // ulang -> konfirmasi.
    //
    // Berkas "yang isinya sama" ditolak, jadi berkas yang DIPERBAIKI harus
    // benar-benar berbeda isinya - dan itulah yang membedakan impor ulang
    // yang sah dari penggandaan.
    $rusak = barisPg('Kunci salah');
    $rusak[11] = 'e';

    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv([judulKolom(), barisPg('Pertama'), $rusak], 'v1.csv'),
        'subject_id' => $this->mapel->id,
    ])->assertRedirect();

    expect(QuestionImportBatch::query()->firstOrFail()->pratinjau_json['gagal'])->toBe(1);

    // Guru memperbaiki kesalahan pada dua barisnya, lalu mengunggah berkasnya
    // lagi. Berkasnya benar-benar berbeda - dan itulah yang membedakan impor
    // ulang yang sah dari penggandaan.
    masukImpor($this, $this->guru)->post('/guru/bank-soal/impor/unggah', [
        'berkas' => berkasCsv([judulKolom(), barisPg('Pertama'), barisPg('Kedua')], 'v2.csv'),
        'subject_id' => $this->mapel->id,
    ])->assertSessionHasNoErrors();

    expect(QuestionImportBatch::query()->count())->toBe(2);

    $kedua = QuestionImportBatch::query()->orderByDesc('id')->firstOrFail();

    masukImpor($this, $this->guru)->post("/guru/bank-soal/impor/{$kedua->id}/konfirmasi", ['ke_paket' => false]);

    // Hanya 2 soal dari berkas kedua. Berkas pertama tidak pernah
    // dikonfirmasi, sehingga tidak ada soal yang tergandakan.
    expect(Question::query()->count())->toBe(2)
        ->and(Question::query()->pluck('title')->sort()->values()->all())
        ->toBe(['Kedua', 'Pertama']);
});
