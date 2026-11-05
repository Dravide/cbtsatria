<?php

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Services\KelayakanUjian;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Identity\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SeederBeban;

/**
 * Seeder uji beban benar-benar menyiapkan keadaan yang bisa diuji.
 *
 * =====================================================================
 * Mengapa seeder ini punya testnya sendiri
 * =====================================================================
 * `SeederBeban` tidak dijalankan Pest mana pun sampai uji beban Fase 9
 * dijalankan sungguhan - dan di situlah satu kekurangan yang menentukan
 * ketahuan: sekolah contoh punya DUA ruang berkapasitas 32, yaitu 64 kursi,
 * sementara prosedur di `docs/BEBAN.md` menyuruh menyemai TIGA RATUS siswa.
 *
 * `PenempatanService::sebar()` memeriksa kapasitas lebih dulu dan menolak
 * seluruhnya, jadi kegagalannya bersih - tetapi uji beban yang mengikat
 * kriteria keluar Fase 9 berhenti di langkah pertamanya, di basis data yang
 * throwaway, dan yang terlihat hanyalah satu pesan galat di antara keluaran
 * seeding yang panjang.
 *
 * Test di bawah mengunci keduanya: bahwa penyemaian 300 benar-benar BERHASIL,
 * dan bahwa jalan yang membuatnya berhasil adalah penyediaan kursi - bukan
 * pelonggaran pemeriksaan kapasitas di `sebar()`. Yang terakhir akan bekerja
 * juga, dan justru itu bahayanya: ia membuka penempatan berlebih di jalur
 * Admin sungguhan.
 *
 * ---------------------------------------------------------------------
 * Basis data yang dipakai
 * ---------------------------------------------------------------------
 * `RefreshDatabase` menjalankan seluruh `tests/Feature` pada
 * `cbt_satria_test`, yang berakhiran `_test` - jadi pengaman nama di dalam
 * seeder ini meloloskannya tanpa perlu dilemahkan.
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/** Sesi yang jendelanya sedang terbuka - yang digeser dan diisi seeder. */
function sesiBeban(): ExamSession
{
    return ExamSession::query()
        ->where('status', StatusSesi::Berjalan)
        ->orderBy('session_number')
        ->firstOrFail();
}

/** Jumlah peserta terdaftar pada ujian pertama. */
function jumlahPesertaBeban(): int
{
    return ExamParticipant::query()
        ->where('exam_id', Exam::query()->orderBy('id')->value('id'))
        ->where('status', ExamParticipant::STATUS_TERDAFTAR)
        ->count();
}

it('menolak basis data yang namanya bukan basis data uji', function () {
    // Pengaman ini yang menjaga 300 siswa palsu keluar dari basis data
    // pengembangan. Diperiksa lewat nama yang TIDAK berakhiran _test/_beban/
    // _pulih - dan nama itu diambil dari koneksi, bukan dikarang.
    config(['database.connections.mysql.database' => 'cbt_satria']);

    $this->artisan('db:seed', ['--class' => SeederBeban::class, '--force' => true])
        ->expectsOutputToContain('DITOLAK')
        ->assertSuccessful();

    // Tidak satu akun pun lahir - itulah yang dijanjikan penolakannya.
    expect(User::query()->where('username', 'like', 'beban%')->count())
        ->toBe(0);
});

it('menaikkan kapasitas kursi sampai memuat seluruh peserta', function () {
    $sesi = sesiBeban();

    // Keadaan sebelum: sekolah contoh hanya punya dua ruang 32 kursi untuk
    // sesi ini. Diperiksa lebih dulu supaya test ini gagal dengan jelas bila
    // data contohnya berubah, bukan lolos karena kebetulan sudah cukup.
    $sebelum = ExamSessionRoom::query()
        ->where('exam_session_id', $sesi->id)
        ->with('eventRoom.room:id,capacity')
        ->get()
        ->sum(fn (ExamSessionRoom $r) => $r->kapasitasEfektif());

    expect($sebelum)->toBeLessThan(300);

    putenv('CBT_BEBAN_SISWA=300');

    $this->artisan('db:seed', ['--class' => SeederBeban::class, '--force' => true])
        ->assertSuccessful();

    putenv('CBT_BEBAN_SISWA');

    $sesudah = ExamSessionRoom::query()
        ->where('exam_session_id', $sesi->id)
        ->with('eventRoom.room:id,capacity')
        ->get()
        ->sum(fn (ExamSessionRoom $r) => $r->kapasitasEfektif());

    $peserta = jumlahPesertaBeban();

    // Invarian yang menentukan uji bebannya bisa jalan: setiap peserta punya
    // kursi di sesi yang jendelanya terbuka.
    expect($peserta)->toBeGreaterThanOrEqual(300)
        ->and($sesudah)->toBeGreaterThanOrEqual($peserta);

    // Dan yang membuktikannya bukan sekadar angkanya: tidak ada peserta
    // terdaftar yang tertinggal tanpa `exam_session_room_id`.
    expect(
        ExamParticipant::query()
            ->where('exam_id', Exam::query()->orderBy('id')->value('id'))
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->whereNull('exam_session_room_id')
            ->count(),
    )->toBe(0);
});

it('menggeser jendela sesi ke sekarang dan menerbitkan token yang cocok', function () {
    putenv('CBT_BEBAN_SISWA=40');

    $this->artisan('db:seed', ['--class' => SeederBeban::class, '--force' => true])
        ->assertSuccessful();

    putenv('CBT_BEBAN_SISWA');

    $sesi = sesiBeban()->fresh();

    // Jendelanya TERBUKA - itulah satu-satunya alasan uji bebannya bisa
    // masuk. `DatabaseSeeder` membukanya tiga puluh menit sebelum ia
    // dijalankan; tanpa pergeseran ini, uji yang dijalankan belakangan
    // gagal seluruhnya dengan `KelayakanUjian` menjawab dengan benar bahwa
    // sesinya sudah berakhir.
    expect($sesi->access_opens_at->isPast())->toBeTrue()
        ->and($sesi->hard_end_at->isFuture())->toBeTrue();

    // Tokennya harus menempel pada jendela YANG BARU. Menerbitkan token
    // sebelum menggeser jendela menghasilkan token yang sudah kedaluwarsa
    // untuk sesi yang baru saja dibuka - dan gerbang akan menolaknya dengan
    // pesan yang menyebut token, bukan jendela.
    $token = $sesi->tokens()->whereNull('revoked_at')->firstOrFail();

    expect($token->valid_until->equalTo($sesi->hard_end_at))->toBeTrue();
});

it('mengembalikan peniru ke keadaan belum mulai saat dijalankan ulang', function () {
    // Seeder ini menjanjikan keadaan yang SERAGAM, dan bagian yang paling
    // menentukan adalah "belum ada yang mulai". Jalan pertama meninggalkan
    // satu attempt `dikirim` per peniru; jalan kedua tanpa pembersihan akan
    // menemukan attempt itu, menghitungnya sebagai kesempatan yang sudah
    // terpakai, dan menolak dengan "Anda sudah memakai seluruh kesempatan
    // ujian ini (1x)".
    //
    // Gejalanya di uji beban adalah 302 pada `/kerjakan` untuk SETIAP
    // peniru - identik dengan jendela sesi yang sudah tertutup. Dua sebab
    // berbeda yang bergejala sama itulah alasan test ini ada.
    putenv('CBT_BEBAN_SISWA=5');

    $this->artisan('db:seed', ['--class' => SeederBeban::class, '--force' => true])
        ->assertSuccessful();

    $sesi = sesiBeban()->fresh();

    // Keadaan sesudah jalan pertama: terverifikasi, punya kursi, belum mulai.
    $peserta = ExamParticipant::query()
        ->where('exam_id', Exam::query()->orderBy('id')->value('id'))
        ->where('status', ExamParticipant::STATUS_TERDAFTAR)
        ->whereNotNull('exam_session_room_id')
        ->whereHas('student', fn ($q) => $q->where('username', 'like', 'beban%'))
        ->get();

    expect($peserta)->toHaveCount(5);

    // Peniru pertama mengerjakan ujian dan mengirimkannya - persis seperti
    // yang ditinggalkan uji beban pada jalan pertamanya.
    $pertama = $peserta->first();

    ExamAttempt::create([
        'school_id' => $pertama->school_id,
        'exam_participant_id' => $pertama->id,
        'attempt_number' => 1,
        'started_at' => now()->subMinutes(5),
        'deadline_at' => $sesi->hard_end_at,
        'submitted_at' => now()->subMinute(),
        'status' => ExamAttempt::STATUS_DIKIRIM,
    ]);

    // Dan kelayakannya memang sudah tertutup oleh attempt itu - diperiksa
    // lebih dulu supaya test ini gagal dengan jelas bila aturan kesempatannya
    // berubah, bukan lolos karena kebetulan.
    expect(app(KelayakanUjian::class)->periksa($pertama->fresh())->boleh)->toBeFalse();

    // Jalan kedua seeder: satu-satunya yang boleh berubah adalah attemptnya.
    $this->artisan('db:seed', ['--class' => SeederBeban::class, '--force' => true])
        ->expectsOutputToContain('Attempt lama dihapus')
        ->assertSuccessful();

    putenv('CBT_BEBAN_SISWA');

    expect(ExamAttempt::tanpaTenant()
        ->whereIn('exam_participant_id', $peserta->pluck('id'))
        ->count())->toBe(0);

    // Dan yang penting bukan sekadar barisnya hilang, melainkan gerbangnya
    // kembali terbuka - itulah yang diuji beban dipakai untuk masuk.
    expect(app(KelayakanUjian::class)->periksa($pertama->fresh())->boleh)->toBeTrue();

    // Penempatan dan presensinya TIDAK ikut dibersihkan: menghapusnya berarti
    // seeder ini membangun ulang tiga ratus baris setiap kali dijalankan,
    // padahal yang perlu dikembalikan hanyalah "belum mulai".
    expect(ExamParticipant::query()
        ->whereIn('id', $peserta->pluck('id'))
        ->whereNotNull('exam_session_room_id')
        ->count())->toBe(5);
});
