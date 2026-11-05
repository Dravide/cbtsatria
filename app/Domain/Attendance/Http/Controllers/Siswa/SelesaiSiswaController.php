<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Services\JawabanService;
use App\Domain\Attendance\Services\MesinUjian;
use App\Domain\Exam\Models\ExamParticipant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Konfirmasi dan penutupan ujian - wireframe plate 26 dan 27.
 *
 * =====================================================================
 * SATU alamat, DUA laman, dan yang membedakannya keadaan attempt
 * =====================================================================
 * Plate 26 menggambar konfirmasi (masih berjalan) dan plate 27 menggambar
 * keadaan sesudah dikumpulkan, keduanya di `/siswa/ujian/{id}/selesai`.
 * Bentuk ini dipilih karena alamat yang sama adalah alamat yang sama bagi
 * siswa yang menekan "Kembali mengerjakan" lalu menekan "Kumpulkan" lagi -
 * dan yang kedua harus menemukan laman yang benar, bukan formulir yang
 * meminta ia mengumpulkan dua kali.
 *
 * ---------------------------------------------------------------------
 * TIDAK ada dialog "Yakin?"
 * ---------------------------------------------------------------------
 * Plate 26 menyatakannya sendiri: "Dialog konfirmasi hanya melatih orang
 * menekan ya tanpa membaca." Nomor yang belum terjawab karena itu ditulis
 * LANGSUNG di laman, sebagai daftar yang bisa diketuk - sehingga siswa yang
 * mengubah pikirannya berjalan ke soalnya, bukan menekan tombol.
 *
 * ---------------------------------------------------------------------
 * TIDAK ada nilai di laman ini
 * ---------------------------------------------------------------------
 * Plate 27 menulis alasannya: "Menampilkan nilai objektif segera setelah
 * dikumpulkan akan membuat siswa membandingkannya sebelum uraian selesai
 * dinilai, lalu menagih nilai yang belum final." Selain itu, sampai Fase 8
 * memang belum ada tabel nilai sama sekali - jadi kalimatnya pun bukan
 * karangan tampilan, melainkan keadaan yang sebenarnya.
 */
class SelesaiSiswaController extends SiswaDasar
{
    public function __construct(
        private readonly MesinUjian $mesin,
        private readonly JawabanService $jawaban,
    ) {}

    public function tampilkan(Request $request, ExamParticipant $peserta): Response|RedirectResponse
    {
        $peserta = $this->milikSaya($peserta);

        $attempt = $this->attemptTerakhir($peserta);

        if ($attempt === null) {
            return redirect()->route('siswa.gerbang', ['peserta' => $peserta->id]);
        }

        $attempt = $this->mesin->tutupBilaKedaluwarsa($attempt);

        if ($attempt->dikunci()) {
            return redirect()->route('siswa.terkunci', ['peserta' => $peserta->id]);
        }

        $peserta->loadMissing(['exam.subject:id,name', 'session', 'room.eventRoom.room:id,code,name']);

        $selesai = $attempt->status !== ExamAttempt::STATUS_BERJALAN;

        return Inertia::render('Siswa/Selesai', [
            'peserta' => $this->ringkasPeserta($peserta),

            'selesai' => $selesai,

            'attempt' => [
                'id' => $attempt->id,
                'status' => $attempt->status,
                'status_label' => $attempt->labelStatus(),
                'status_nada' => $attempt->nadaStatus(),
                'dikumpulkan' => $attempt->submitted_at?->toIso8601String(),
                'jam_kumpul' => $attempt->submitted_at?->format('H:i'),
                // Kedaluwarsa BUKAN "dikumpulkan", dan laman yang menulis
                // "jawaban diterima" pada attempt yang kehabisan waktu akan
                // membuat siswa menduga ujiannya lengkap. Bedanya dibawa oleh
                // `status`, dan laman yang memilih kalimatnya.
                'kedaluwarsa' => $attempt->status === ExamAttempt::STATUS_KEDALUWARSA,
                'sisa_detik' => $selesai ? 0 : $this->mesin->sisaDetik($attempt),
            ],

            // Kosong saat sudah selesai: daftar soal yang belum dijawab tidak
            // berguna lagi, dan menampilkannya pada laman plate 27 berarti
            // memberi siswa sesuatu yang tidak bisa ia kerjakan.
            'progres' => $this->jawaban->progres($attempt),

            // Nomor soal yang belum terjawab - plate 26 menampilkan deretan
            // nomor yang bisa diketuk. Nomornya `display_order`, yaitu nomor
            // YANG DILIHAT SISWA - lihat AttemptQuestion.
            'belum_terjawab' => $selesai ? [] : $this->belumTerjawab($attempt),
        ]);
    }

    /**
     * Kumpulkan - PRD §11.7.
     *
     * Idempotent: PRD §11.8 menuntutnya untuk penutupan otomatis, dan
     * ketukan ganda pada tombol yang sama adalah kejadian yang sama dari arah
     * siswa. Attempt yang sudah ditutup dikembalikan apa adanya oleh
     * MesinUjian::kumpulkan(), bukan ditutup ulang dengan jam baru - jam
     * pengumpulan adalah bukti, dan bukti yang bergeser setiap kali tombolnya
     * ditekan bukan bukti.
     *
     * Mengembalikan redirect ke alamat yang sama, sehingga yang muncul
     * sesudahnya adalah plate 27.
     */
    public function kumpulkan(Request $request, ExamParticipant $peserta): RedirectResponse
    {
        $peserta = $this->milikSaya($peserta);

        $attempt = $this->attemptTerakhir($peserta);

        if ($attempt === null) {
            return redirect()->route('siswa.gerbang', ['peserta' => $peserta->id]);
        }

        // Kedaluwarsa diperiksa lebih dulu: attempt yang sudah lewat
        // deadline-nya ditutup sebagai `kedaluwarsa`, bukan sebagai `dikirim`,
        // dan siswa yang menekan Kumpulkan satu detik terlalu lambat harus
        // menerima catatan yang sebenarnya.
        $attempt = $this->mesin->tutupBilaKedaluwarsa($attempt);

        $this->mesin->kumpulkan($attempt);

        return redirect()->route('siswa.selesai', ['peserta' => $peserta->id]);
    }

    /**
     * Nomor soal yang belum terjawab, dalam urutan yang dilihat siswa.
     *
     * @return array<int, array<string, mixed>>
     */
    private function belumTerjawab(ExamAttempt $attempt): array
    {
        return AttemptQuestion::query()
            ->where('exam_attempt_id', $attempt->id)
            ->with('jawaban')
            ->orderBy('display_order')
            ->get()
            ->filter(fn (AttemptQuestion $b) => $b->jawaban === null || ! $b->jawaban->terjawab())
            ->map(fn (AttemptQuestion $b) => [
                'id' => $b->id,
                'nomor' => $b->display_order,
                'ditandai' => (bool) ($b->jawaban?->ditandai ?? false),
            ])
            ->values()
            ->all();
    }
}
