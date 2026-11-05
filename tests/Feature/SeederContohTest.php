<?php

use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Models\StudentAnswer;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use Database\Seeders\DatabaseSeeder;

/**
 * Seeder data contoh benar-benar menghasilkan keadaan yang dibaca layar.
 *
 * =====================================================================
 * Mengapa seeder perlu testnya sendiri
 * =====================================================================
 * Sampai Fase 7 tidak ada satu pun test yang menjalankan seeder, dan
 * akibatnya terlihat justru saat Fase 7 selesai: `PenempatanService::sebar()`
 * dipanggil di dalam loop tiga sesi, padahal ia memindahkan SELURUH peserta
 * ke sesi yang diberikan. Seluruh 12 siswa berakhir di sesi 3, dan sesi 1 -
 * satu-satunya yang jendelanya sedang terbuka - kosong.
 *
 * Yang membuatnya mahal bukan kesalahannya, melainkan cara ia menyamar:
 * `db:seed` selesai tanpa satu galat pun, dan yang kosong cuma layarnya.
 * Papan presensi, papan monitoring, dan seluruh laman pengerjaan membaca
 * ruang yang tidak berisi siapa pun - dan laman kosong tidak membuktikan
 * apa pun tentang fitur yang baru saja dibangun.
 *
 * Karena itu yang diperiksa di sini bukan "seedernya jalan", melainkan
 * "keadaan yang dibaca layar memang ada": peserta di sesi yang berjalan,
 * presensi yang sudah diverifikasi, attempt dalam empat keadaan, dan hitungan
 * pelanggaran yang cocok dengan barisnya.
 *
 * ---------------------------------------------------------------------
 * Keempat keadaan attempt, dan mengapa bukan satu
 * ---------------------------------------------------------------------
 * Papan monitoring plate 15 punya empat kolom keadaan. Data contoh yang
 * hanya berisi attempt "berjalan" membuat tiga kolom lainnya tidak pernah
 * terlihat terisi selama pengembangan - dan kolom yang tidak pernah berisi
 * adalah kolom yang salahnya baru ketahuan di hari-H.
 */
beforeEach(function () {
    // Seeder mengisi satu sekolah lengkap dengan event, ujian, sesi, dan
    // pengerjaan contohnya. Dipanggil sekali per test; `RefreshDatabase`
    // sudah mengosongkan basis datanya lebih dulu.
    $this->seed(DatabaseSeeder::class);
});

/** Sesi pertama ujian - satu-satunya yang jendelanya sedang terbuka. */
function sesiBerjalan(): ExamSession
{
    return ExamSession::query()->orderBy('exam_id')->orderBy('session_number')->firstOrFail();
}

it('menempatkan peserta di sesi yang sedang berjalan', function () {
    $sesi = sesiBerjalan();

    // Inilah invarian yang dilanggar sebelum seeder diperbaiki: sesi 1 harus
    // berisi peserta, karena seluruh laman Fase 6 dan Fase 7 membacanya.
    $ditempatkan = ExamParticipant::query()
        ->where('exam_session_id', $sesi->id)
        ->count();

    expect($ditempatkan)->toBeGreaterThan(0);

    // Dan seluruh peserta ujian itu memang ada di sesi ini - tidak ada yang
    // tertinggal menggantung tanpa sesi, dan tidak ada yang berpindah ke sesi
    // berikutnya. `sebar()` menempatkan semuanya atau menolak semuanya.
    $total = ExamParticipant::query()
        ->where('exam_id', $sesi->exam_id)
        ->where('status', ExamParticipant::STATUS_TERDAFTAR)
        ->count();

    expect($ditempatkan)->toBe($total);

    // Setiap peserta punya ruang, dan ruangnya milik sesi ini - bukan sesi
    // lain. Penempatan yang menunjuk ruang sesi lain adalah penempatan yang
    // tidak akan muncul di papan ruang mana pun.
    $nyasar = ExamParticipant::query()
        ->where('exam_session_id', $sesi->id)
        ->whereDoesntHave('room', fn ($q) => $q->where('exam_session_id', $sesi->id))
        ->count();

    expect($nyasar)->toBe(0);
});

it('memverifikasi presensi seluruh peserta yang mengerjakan', function () {
    $sesi = sesiBerjalan();

    $mengerjakan = ExamAttempt::query()
        ->whereIn('exam_participant_id', ExamParticipant::query()
            ->where('exam_session_id', $sesi->id)
            ->pluck('id'))
        ->count();

    expect($mengerjakan)->toBeGreaterThan(0);

    // Setiap attempt yang ada WAJIB milik peserta yang presensinya sudah
    // diverifikasi. `KelayakanUjian::periksaPresensi()` menolak gerbang bagi
    // baris yang `verified_at`-nya null, sehingga attempt tanpa itu adalah
    // keadaan yang tidak bisa terjadi sungguhan - data contoh yang saling
    // membantah.
    $belumDiverifikasi = ExamAttempt::query()
        ->whereIn('exam_participant_id', ExamParticipant::query()
            ->where('exam_session_id', $sesi->id)
            ->pluck('id'))
        ->whereNotIn('exam_participant_id', AttendanceRecord::query()
            ->whereNotNull('verified_at')
            ->pluck('exam_participant_id'))
        ->count();

    expect($belumDiverifikasi)->toBe(0);
});

it('menghasilkan attempt dalam empat keadaan yang dibaca papan monitoring', function () {
    $status = ExamAttempt::query()->pluck('status')->unique()->values()->all();

    // Keempat kolom plate 15: mengerjakan, terkunci, selesai, dan - yang
    // dihitung dari selisih denyut - terputus.
    expect($status)
        ->toContain(ExamAttempt::STATUS_BERJALAN)
        ->toContain(ExamAttempt::STATUS_DIKUNCI)
        ->toContain(ExamAttempt::STATUS_DIKIRIM);

    // Attempt yang dikunci membawa alasannya, karena plate 25 dan plate 16
    // menampilkannya dan attempt terkunci tanpa alasan tidak menjawab
    // pertanyaan pertama setiap orang yang melihatnya.
    $terkunci = ExamAttempt::query()->where('status', ExamAttempt::STATUS_DIKUNCI)->first();

    expect($terkunci->lock_reason)->not->toBeNull();

    // Dan yang mengunci adalah `sesi_ganda` - satu-satunya jenis yang mengunci
    // seketika, sehingga jalur "kunci tanpa menunggu hitungan" benar-benar
    // dilalui data contoh, bukan hanya ditulis di komentar.
    $jenis = ExamViolation::query()
        ->where('exam_attempt_id', $terkunci->id)
        ->pluck('violation_type')
        ->all();

    expect($jenis)->toContain('sesi_ganda');

    // Satu attempt dengan denyut yang BASI, supaya kolom "terputus"
    // benar-benar terhitung pada data contoh yang seharusnya memperlihatkan
    // keempat keadaannya.
    $terputus = ExamAttempt::query()
        ->where('status', ExamAttempt::STATUS_DIKIRIM)
        ->get()
        ->contains(fn (ExamAttempt $a) => $a->terhubung() === false);

    expect($terputus)->toBeTrue();
});

it('mencatat pelanggaran lewat jalur yang menghitungnya, bukan dikarang', function () {
    $attempt = ExamAttempt::query()->orderBy('id')->get();

    expect($attempt)->not->toBeEmpty();

    foreach ($attempt as $a) {
        // `violation_count` adalah kolom yang memutuskan apakah seorang anak
        // terkunci, dan ia dibaca papan monitoring SETIAP kali disegarkan.
        // Yang menjaganya tetap benar bukan niat baik, melainkan bahwa
        // barisnya lahir dari `PencatatPelanggaran` - dan test ini memeriksa
        // bahwa keduanya memang sepakat.
        $nyata = ExamViolation::query()
            ->where('exam_attempt_id', $a->id)
            ->whereNotIn('violation_type', ExamViolation::JENIS_PENGAWAS)
            ->count();

        expect($a->violation_count)->toBe($nyata, "hitungan attempt {$a->id} tidak cocok dengan barisnya");

        $terberat = ExamViolation::query()
            ->where('exam_attempt_id', $a->id)
            ->whereNotIn('violation_type', ExamViolation::JENIS_PENGAWAS)
            ->max('severity');

        expect($a->violation_worst_severity)->toBe((int) ($terberat ?? 0));

        // Progres ditulis ke `guard_flags_json` dan dibaca papan monitoring
        // dari sana. Attempt tanpa progres akan menampilkan tanda pisah di
        // kolom yang justru paling sering dibaca pengawas.
        expect($a->guard_flags_json['progres'] ?? null)->not->toBeNull();
    }

    // Dua attempt sengaja dibiarkan bersih - dan yang bersih itu nyata, bukan
    // kebetulan: attempt keempat sudah dikumpulkan sehingga laporan
    // pelanggaran sesudahnya ditolak `PencatatPelanggaran`.
    expect($attempt->contains(fn (ExamAttempt $a) => $a->violation_count === 0))->toBeTrue();
});

it('mengisi jawaban dengan bentuk yang benar-benar dibaca laman pengerjaan', function () {
    $attempt = ExamAttempt::query()
        ->where('status', ExamAttempt::STATUS_BERJALAN)
        ->firstOrFail();

    $butir = AttemptQuestion::query()
        ->where('exam_attempt_id', $attempt->id)
        ->orderBy('display_order')
        ->get();

    // Urutan soal disalin mesin ujian, sehingga laman pengerjaan punya nomor
    // yang bisa disebut pengawas di ruang ujian.
    expect($butir)->not->toBeEmpty();
    expect($butir->pluck('display_order')->all())->toBe(range(1, $butir->count()));

    $jawaban = StudentAnswer::query()
        ->whereIn('attempt_question_id', $butir->pluck('id'))
        ->get();

    expect($jawaban)->not->toBeEmpty();

    // Isinya array, bukan skalar - `StudentAnswer` menyimpannya sebagai JSON
    // dan `terjawab()` menelusuri nilainya. Jawaban berbentuk lain akan
    // terbaca "belum dijawab" dan anak melihat butir yang sudah ia kerjakan
    // tampak kosong.
    foreach ($jawaban as $j) {
        expect($j->answer_json)->toBeArray();

        // `is_correct` null berarti BELUM DINILAI - dan penilaian adalah
        // Fase 8. Mengisinya di seeder akan menandai jawaban uraian setiap
        // siswa sebagai salah.
        expect($j->is_correct)->toBeNull();
    }

    // Progresnya cocok dengan jumlah jawaban yang benar-benar ada isinya.
    $terjawab = $jawaban->filter(fn (StudentAnswer $j) => $j->terjawab())->count();

    expect($attempt->guard_flags_json['progres'] ?? null)
        ->toBe($terjawab.' / '.$butir->count().' terjawab');
});
