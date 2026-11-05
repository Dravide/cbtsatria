<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Services\JawabanService;
use App\Domain\Attendance\Services\MesinUjian;
use App\Domain\Attendance\Support\TanggaPelanggaran;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Tenancy\Support\PengaturanBawaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ujian terkunci - wireframe plate 25.
 *
 * =====================================================================
 * Dua hal di laman ini yang disengaja, dan keduanya menyangkut anak
 * =====================================================================
 * (1) RINCIAN PELANGGARANNYA DITAMPILKAN KEPADA SISWA ITU SENDIRI.
 * (2) TERTULIS BAHWA WAKTU TETAP BERJALAN SELAMA TERKUNCI.
 *
 * Yang kedua bukan hiasan. Siswa yang tidak tahu waktunya terus berkurang
 * akan menunggu dengan tenang, lalu kehabisan waktu - dan menyalahkan
 * sistem, dan kali ini ia benar. `sisa_detik` karena itu dikirim dan terus
 * diperbarui, bukan disembunyikan supaya layarnya terlihat tenang.
 *
 * Yang pertama adalah keputusan yang sedikit merugikan pengawas - catatan
 * yang bisa dibaca yang bersangkutan akan selalu ditanyakan - dan itu justru
 * tujuannya; alasan yang sama ditulis di plate 29. Rinciannya dibaca dari
 * tabel yang sama dengan yang dibaca pengawas, sehingga tidak ada dua versi
 * cerita tentang apa yang terjadi.
 *
 * ---------------------------------------------------------------------
 * Laman ini TIDAK memeriksa "apakah layak melanjutkan"
 * ---------------------------------------------------------------------
 * `KelayakanUjian` menolak attempt terkunci dengan pesan yang sama persis
 * ("Ujian Anda sedang dikunci."). Kalau laman ini memanggilnya, ia akan
 * menampilkan penolakan gerbang - bukan rincian yang dibutuhkan siswa untuk
 * menghubungi pengawas. Yang diperiksa di sini adalah SATU hal: apakah
 * attempt-nya memang terkunci. Kalau tidak, laman ini mengalihkan ke tempat
 * yang benar, sehingga tidak ada siswa yang membaca "terkunci" pada ujian
 * yang sedang berjalan.
 */
class TerkunciSiswaController extends SiswaDasar
{
    public function __construct(
        private readonly MesinUjian $mesin,
        private readonly JawabanService $jawaban,
    ) {}

    public function tampilkan(Request $request, ExamParticipant $peserta): Response|RedirectResponse
    {
        $peserta = $this->milikSaya($peserta);

        $attempt = $this->attemptTerakhir($peserta);

        if ($attempt === null || ! $attempt->dikunci()) {
            return redirect()->route('siswa.gerbang', ['peserta' => $peserta->id]);
        }

        $peserta->loadMissing(['exam.subject:id,name', 'session', 'room.eventRoom.room:id,code,name']);

        $pelanggaran = ExamViolation::query()
            ->where('exam_attempt_id', $attempt->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        // Tindakan tiap baris dihitung ulang dari urutannya - lihat
        // TanggaPelanggaran::jejakTindakan(). Yang dibaca siswa adalah yang
        // SAMA dengan yang dibaca pengawas di plate 16, bukan versi ringkas.
        $jejak = TanggaPelanggaran::jejakTindakan($pelanggaran, $attempt->ambang($this->ambangBawaan()));

        $riwayat = $pelanggaran->sortByDesc(fn (ExamViolation $v) => [$v->occurred_at?->getTimestamp() ?? 0, $v->id])->values();

        return Inertia::render('Siswa/Terkunci', [
            'peserta' => $this->ringkasPeserta($peserta),

            'attempt' => [
                'id' => $attempt->id,
                'alasan' => $attempt->lock_reason,
                'terkunci_pada' => $attempt->locked_at?->toIso8601String(),
                'jumlah' => $attempt->violation_count,
                // Waktu terus berjalan - lihat catatan kelas ini.
                'sisa_detik' => $this->mesin->sisaDetik($attempt),
                'deadline' => $attempt->deadline_at?->toIso8601String(),
            ],

            // Dikelompokkan per jenis supaya "keluar dari layar ujian · 3×"
            // terbaca sebagai satu kebiasaan, bukan tiga baris yang harus
            // dijumlahkan sendiri oleh anak yang sedang panik. Rincian
            // waktunya tetap dikirim, dan laman yang memilih menampilkannya.
            'ringkasan' => $this->ringkasPelanggaran($pelanggaran),

            'riwayat' => $riwayat->map(fn (ExamViolation $v) => [
                'id' => $v->id,
                'jam' => $v->occurred_at?->format('H:i'),
                'jenis' => $v->labelJenis(),
                'tindakan' => $jejak[$v->id] ?? TanggaPelanggaran::labelTindakan($v),
                'tingkat' => $v->labelTingkat(),
                'nada' => $v->nadaTingkat(),
                'durasi' => $v->duration_ms === null ? null : round($v->duration_ms / 1000, 1),
                'tindakan_pengawas' => in_array($v->violation_type, ExamViolation::JENIS_PENGAWAS, true),
            ])->all(),

            'progres' => $this->jawaban->progres($attempt),
        ]);
    }

    /** Ambang bawaan sekolah, dipakai bila rantai peserta->ujian->event putus. */
    private function ambangBawaan(): int
    {
        return (int) (auth()->user()?->school?->settings['ambang_pelanggaran']
            ?? PengaturanBawaan::NILAI['ambang_pelanggaran']);
    }

    /**
     * Ringkasan per jenis - pratinjau plate 25.
     *
     * Yang dikelompokkan hanyalah pelanggaran SISWA; tindakan pengawas tidak
     * ikut dihitung, karena "Kunci dibuka pengawas · 1×" di kotak ringkasan
     * pelanggaran akan terbaca sebagai perbuatan anak.
     *
     * @param  Collection<int, ExamViolation>  $pelanggaran
     * @return array<int, array<string, mixed>>
     */
    private function ringkasPelanggaran($pelanggaran): array
    {
        return $pelanggaran
            ->reject(fn (ExamViolation $v) => in_array($v->violation_type, ExamViolation::JENIS_PENGAWAS, true))
            ->groupBy('violation_type')
            ->map(function ($baris, string $jenis) {
                /** @var ExamViolation $terberat */
                $terberat = $baris->sortByDesc('severity')->first();

                return [
                    'jenis' => $jenis,
                    'label' => $terberat->labelJenis(),
                    'jumlah' => $baris->count(),
                    'tingkat' => $terberat->labelTingkat(),
                    'nada' => $terberat->nadaTingkat(),
                ];
            })
            ->sortByDesc('jumlah')
            ->values()
            ->all();
    }
}
