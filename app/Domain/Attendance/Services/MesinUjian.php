<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\PenilaiPengerjaan;
use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Services\TokenSesi;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuat dan menutup attempt - PRD §11.1-§11.8, plate 21-27.
 *
 * ---------------------------------------------------------------------
 * Mengapa mesin ujian hidup di modul Attendance, bukan di Exam
 * ---------------------------------------------------------------------
 * Arah impor modul di aplikasi ini satu arah: Attendance menunjuk Exam,
 * Exam tidak pernah menunjuk Attendance (`DomainBoundariesTest`). Mesin
 * ujian menulis `attempt_questions` dan `student_answers` - dua tabel
 * Attendance - sambil membaca `ExamParticipant`, `ExamQuestion`, dan
 * `ExamSession`. Kalau ia ditulis di modul Exam, ia akan mengimpor model
 * Attendance dan arahnya berbalik, lingkaran modulnya terjadi, dan seluruh
 * peta ketergantungan berhenti menjelaskan apa pun.
 *
 * Yang membuat penempatan ini masuk akal, bukan sekadar menghindari
 * pelanggaran: attempt memang KELANJUTAN presensi. Gerbang presensi-lah yang
 * membuka pintunya (`StatusPresensi::membukaGerbang()`), dan kolom
 * `last_heartbeat_at` yang diisi mesin ini adalah yang dibaca papan
 * monitoring ruang. Fase 6 sudah membaca tabel ini dari sini; Fase 7 mulai
 * menulisnya, di tempat yang sama.
 *
 * ---------------------------------------------------------------------
 * `deadline_at` dihitung di sini, dan hanya di sini
 * ---------------------------------------------------------------------
 * PRD §11.5 menetapkannya sebagai rumus, bukan sebagai preferensi:
 *
 *   deadline_at = MIN(started_at + duration, hard_end_at)
 *
 * Dua batas yang berbeda dan tidak boleh disatukan: durasi ujian bisa
 * 90 menit sementara sesinya hanya menyisakan 40 menit. Yang berlaku adalah
 * yang lebih dulu, dan menyimpan hasil MIN-nya (bukan kedua komponennya
 * secara terpisah lalu menghitungnya lagi di klien) adalah yang membuat jam
 * perangkat siswa tidak pernah menentukan waktu akhir ujian.
 *
 * ---------------------------------------------------------------------
 * Pembuatan attempt mengunci barisnya
 * ---------------------------------------------------------------------
 * Dua ketukan pada tombol "Mulai" yang berjarak 200 ms - hal biasa di
 * ponsel dengan sinyal buruk - akan membuat dua attempt kalau tidak dikunci.
 * `lockForUpdate()` pada baris peserta, di dalam satu transaksi, membuat
 * ketukan kedua menunggu lalu menemukan attempt yang sudah ada.
 *
 * ---------------------------------------------------------------------
 * Snapshot dibaca lewat `ExamQuestion`, urutannya ditulis ke attempt_questions
 * ---------------------------------------------------------------------
 * `exam_questions` adalah snapshot yang dibekukan Fase 4 - isinya tidak
 * berubah, dan karena itu aman dibaca berkali-kali. Yang PER ATTEMPT adalah
 * urutan, dan itulah yang ditulis di sini. Soal yang dikirim ke siswa tetap
 * lewat `untukSiswa()`, satu-satunya bentuk yang membuang kunci - lihat
 * docblock ExamQuestion.
 */
class MesinUjian
{
    public function __construct(
        private readonly KelayakanUjian $kelayakan,
        private readonly TokenSesi $token,
        private readonly PenilaiPengerjaan $penilai,
    ) {}

    /**
     * Mulai attempt baru, atau lanjutkan yang sedang berjalan.
     *
     * Mengembalikan attempt yang berlaku untuk siswa ini SESUDAH syaratnya
     * diperiksa ulang - PRD §10.8: "Validasi dilakukan kembali pada endpoint
     * start exam." Pemeriksaan di laman gerbang sudah terjadi, tetapi
     * jendela sesi bisa menutup di antara keduanya.
     *
     * @param  string|null  $kodeToken  Token yang diketik siswa, bila ada.
     */
    public function mulai(ExamParticipant $peserta, ?string $kodeToken = null): ExamAttempt
    {
        $layak = $this->kelayakan->periksa($peserta);

        if (! $layak->boleh) {
            throw ValidationException::withMessages(['gerbang' => $layak->alasan]);
        }

        // Syarat 9 - token. Diperiksa di sini, bukan di gerbang: token adalah
        // kredensial yang diketik SAAT memulai, dan laman gerbang yang
        // menolak karena token salah sebelum siswa sempat mengetiknya adalah
        // laman yang tidak bisa dipakai.
        $this->periksaToken($peserta->session, $kodeToken);

        return DB::transaction(function () use ($peserta) {
            // Baris peserta yang terkunci: dua permintaan bersamaan
            // berbaris di sini, bukan di tempat lain.
            $terkunci = ExamParticipant::query()
                ->whereKey($peserta->id)
                ->lockForUpdate()
                ->firstOrFail();

            $berjalan = $this->kelayakan->attemptBerjalan($terkunci);

            if ($berjalan !== null) {
                // PRD §11.6: satu attempt aktif per peserta. Yang kedua
                // kembali ke yang pertama, bukan membuat yang baru.
                return $berjalan;
            }

            return $this->buatAttempt($terkunci);
        });
    }

    /**
     * Periksa token untuk sesi ini.
     *
     * `null` berarti tidak ada token yang diketik - dan itu hanya sah bila
     * sesinya memang belum punya token aktif. Ujian ber-token yang dimulai
     * tanpa mengetik apa pun harus ditolak, atau seluruh gunanya token hilang
     * pada hari pertama.
     */
    private function periksaToken(?ExamSession $sesi, ?string $kodeToken): void
    {
        if ($sesi === null) {
            throw ValidationException::withMessages(['gerbang' => 'Sesi ujian tidak ditemukan.']);
        }

        $aktif = $sesi->tokenAktif();

        if ($aktif === null) {
            // Tanpa token terbit, sesi ini memang tidak dijaga token.
            return;
        }

        if ($kodeToken === null || trim($kodeToken) === '') {
            throw ValidationException::withMessages([
                'token' => 'Token ujian wajib diisi. Token ditulis di papan tulis ruang ujian.',
            ]);
        }

        // Hash-nya memuat school_id lewat global scope pada ExamToken, jadi
        // token sekolah lain tidak akan pernah cocok di sini - bukan karena
        // pemeriksaan tambahan, melainkan karena barisnya tidak terlihat.
        $cocok = $this->token->verifikasi($kodeToken);

        if ($cocok === null || $cocok->exam_session_id !== $sesi->id) {
            throw ValidationException::withMessages([
                'token' => 'Token tidak cocok dengan sesi ini.',
            ]);
        }

        if (! $cocok->berlaku()) {
            throw ValidationException::withMessages([
                'token' => 'Token itu sudah tidak berlaku. Minta Pengawas ruang menanyakannya ke Admin.',
            ]);
        }
    }

    /**
     * Tulis attempt baru beserta urutan soalnya.
     *
     * Dipanggil di dalam transaksi `mulai()`, sehingga `lockForUpdate()` di
     * sana berlaku untuk seluruh penulisan di sini.
     */
    private function buatAttempt(ExamParticipant $peserta): ExamAttempt
    {
        $sesi = $peserta->session;

        if ($sesi === null) {
            throw new DomainException('Peserta tidak punya sesi; kelayakan seharusnya menolaknya lebih dulu.');
        }

        $sekarang = now();

        // PRD §11.5: yang lebih dulu antara durasi dan akhir sesi.
        $batasDurasi = $sekarang->copy()->addMinutes($sesi->durasiMenit());
        $deadline = $batasDurasi->lt($sesi->hard_end_at) ? $batasDurasi : $sesi->hard_end_at->copy();

        $nomor = (int) ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->max('attempt_number') + 1;

        $attempt = ExamAttempt::create([
            'school_id' => $peserta->school_id,
            'exam_participant_id' => $peserta->id,
            'attempt_number' => $nomor,
            'started_at' => $sekarang,
            'deadline_at' => $deadline,
            'status' => ExamAttempt::STATUS_BERJALAN,
            'violation_count' => 0,
            'violation_worst_severity' => 0,
        ]);

        $this->tulisUrutan($attempt, $sesi);

        return $attempt;
    }

    /**
     * Salin snapshot soal ke attempt_questions, dengan urutan yang berlaku.
     *
     * `shuffle_questions` dan `shuffle_options` dibaca dari ujian. Tanpa
     * pengacakan, `display_order` tetap ditulis - bukan karena sama dengan
     * `position`, melainkan karena layar siswa membaca SATU kolom untuk
     * "soal nomor berapa", dan kolom itu harus ada baik soal diacak maupun
     * tidak.
     */
    private function tulisUrutan(ExamAttempt $attempt, ExamSession $sesi): void
    {
        $ujian = $sesi->exam;

        $soal = ExamQuestion::query()
            ->where('exam_id', $sesi->exam_id)
            ->orderBy('position')
            ->get();

        if ($soal->isEmpty()) {
            // Snapshot kosong berarti pembekuan paketnya gagal, dan ujian
            // tanpa soal tidak boleh berjalan. Transaksinya dibatalkan, bukan
            // diteruskan dengan attempt kosong.
            throw new DomainException('Ujian ini tidak punya butir soal. Hubungi Admin sebelum memulai.');
        }

        if ($ujian?->shuffle_questions) {
            $soal = $soal->shuffle();
        }

        $urutan = $soal->values();

        foreach ($urutan as $posisi => $butir) {
            AttemptQuestion::create([
                'school_id' => $attempt->school_id,
                'exam_attempt_id' => $attempt->id,
                'exam_question_id' => $butir->id,
                'display_order' => $posisi + 1,
                'option_order_json' => $this->urutanOpsi($butir, (bool) $ujian?->shuffle_options),
                'item_order_json' => null,
            ]);
        }
    }

    /**
     * Urutan opsi yang dilihat peserta, atau null bila tidak diacak.
     *
     * Null dipilih alih-alih menyalin urutan aslinya: "tidak diacak" dan
     * "diacak kebetulan menjadi sama" adalah dua keadaan berbeda, dan hanya
     * yang pertama yang boleh diwakili null. Yang membacanya `option_order_json`
     * memperlakukan null sebagai "ikut snapshot".
     *
     * @return list<string>|null
     */
    private function urutanOpsi(ExamQuestion $butir, bool $acak): ?array
    {
        if (! $acak) {
            return null;
        }

        $kunci = collect($butir->opsi_json ?? [])
            ->pluck('option_key')
            ->filter()
            ->values()
            ->all();

        if ($kunci === []) {
            return null;
        }

        shuffle($kunci);

        return $kunci;
    }

    /**
     * Tutup attempt atas permintaan siswa (plate 26-27).
     *
     * Idempotent: PRD §11.8 menuntutnya untuk penutupan otomatis, dan
     * permintaan ganda dari tombol yang ditekan dua kali adalah kejadian
     * yang sama dari arah siswa. Attempt yang sudah ditutup dikembalikan apa
     * adanya, bukan ditutup ulang dengan jam baru.
     */
    public function kumpulkan(ExamAttempt $attempt): ExamAttempt
    {
        if ($attempt->status !== ExamAttempt::STATUS_BERJALAN) {
            return $attempt;
        }

        $attempt->forceFill([
            'status' => ExamAttempt::STATUS_DIKIRIM,
            'submitted_at' => now(),
        ])->save();

        // Nilainya dihitung SESUDAH statusnya tersimpan, dan itu bukan urutan
        // yang bisa ditukar: `PenghitungHasil` menolak attempt yang masih
        // berjalan, karena angka pada ujian yang belum selesai pasti berubah.
        //
        // Penilaiannya sendiri tidak menggagalkan penutupan - lihat
        // `PenilaiPengerjaan`.
        $this->penilai->nilai($attempt);

        return $attempt;
    }

    /**
     * Tutup attempt yang deadline-nya sudah lewat.
     *
     * Dipanggil di setiap pembacaan attempt (heartbeat, muat ulang laman,
     * simpanan jawaban), bukan oleh penjadwal: penjadwal yang mati akan
     * membuat attempt kedaluwarsa tetap "berjalan", dan papan monitoring
     * menampilkan siswa yang sudah pulang sebagai masih mengerjakan.
     */
    public function tutupBilaKedaluwarsa(ExamAttempt $attempt): ExamAttempt
    {
        if ($attempt->status !== ExamAttempt::STATUS_BERJALAN) {
            return $attempt;
        }

        if ($attempt->deadline_at !== null && now()->gte($attempt->deadline_at)) {
            $attempt->forceFill([
                'status' => ExamAttempt::STATUS_KEDALUWARSA,
                'submitted_at' => $attempt->deadline_at,
            ])->save();

            // Siswa yang kehabisan waktu tetap harus punya nilai. Tanpa
            // pemanggilan ini, `exam_results` hanya berisi siswa yang sempat
            // menekan Kumpulkan - dan justru merekalah minoritas di ujian
            // sungguhan.
            //
            // Metode ini dipanggil di SETIAP pembacaan attempt, tetapi
            // `$attempt->status` sudah bukan BERJALAN pada panggilan kedua,
            // sehingga pemeriksaan di atas menutup jalannya. Satu penilaian,
            // bukan satu per penyegaran halaman.
            $this->penilai->nilai($attempt);
        }

        return $attempt;
    }

    /**
     * Butir yang dilihat siswa, dalam urutan attempt-nya.
     *
     * @return Collection<int, AttemptQuestion>
     */
    public function butir(ExamAttempt $attempt): Collection
    {
        return AttemptQuestion::query()
            ->where('exam_attempt_id', $attempt->id)
            ->with('soal', 'jawaban')
            ->orderBy('display_order')
            ->get();
    }

    /** Sisa detik sampai deadline. Tidak pernah negatif. */
    public function sisaDetik(ExamAttempt $attempt): int
    {
        if ($attempt->deadline_at === null) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($attempt->deadline_at, false));
    }
}
