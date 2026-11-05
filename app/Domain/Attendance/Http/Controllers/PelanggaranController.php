<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Attendance\Services\PencatatPelanggaran;
use App\Domain\Attendance\Support\TanggaPelanggaran;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Tenancy\Support\PengaturanBawaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Riwayat pelanggaran satu siswa - wireframe plate 16.
 *
 * =====================================================================
 * Fase 6 MEMBACA, Fase 7 MENULIS - dan tombolnya tetap ada
 * =====================================================================
 * Plate 16 menggambar tiga tombol ("Buka kunci", "Tandai sudah dibina",
 * "Catatan"), dan Fase 6 belum punya satu pun attempt terkunci untuk
 * dikunci-lawan. Yang dipilih bukan menghapus tombolnya, melainkan
 * membiarkannya MENOLAK DENGAN ALASAN.
 *
 * Alasannya bukan soal tampilan. Tombol yang hilang membuat laman ini
 * berbeda antara Fase 6 dan Fase 7, dan laman yang berubah bentuk saat
 * mesin ujian dinyalakan adalah laman yang belum pernah diuji dalam bentuk
 * yang sebenarnya dipakai. Sementara tombol yang menolak tanpa kalimat akan
 * membuat pengawas menekannya berulang kali lalu menelepon Admin.
 *
 * Yang dijaga di sini karena itu bukan "boleh atau tidak", melainkan
 * "keadaannya memungkinkan atau tidak" - dan keadaan itu dibaca dari basis
 * data, bukan dari keberadaan fase.
 *
 * ---------------------------------------------------------------------
 * Satu siswa, satu ruang - dan pemeriksaannya sama seperti presensi
 * ---------------------------------------------------------------------
 * Rute ini berbunyi `/pengawas/ruang/{ruang}/siswa/{peserta}`, keduanya
 * di-bind implisit. Tanpa pemeriksaan kepemilikan ruang, pengawas R-02 bisa
 * membaca catatan perilaku anak di R-03 dengan menebak ID-nya - dan catatan
 * perilaku yang salah orang adalah catatan yang merugikan anak yang tidak
 * pernah berbuat apa pun.
 *
 * Blueprint `:848` menyatakannya sebagai kriteria: "Nama siswa di ruang
 * lain tidak muncul sama sekali, bukan disamarkan."
 */
class PelanggaranController
{
    public function __construct(private readonly PencatatPelanggaran $pencatat) {}

    /** Plate 16: riwayat pelanggaran + keadaan pengerjaan satu siswa. */
    public function index(ExamSessionRoom $ruang, ExamParticipant $peserta): Response
    {
        $konteks = KonteksRuang::dari($ruang);
        $milik = $this->milikRuang($ruang, $peserta);

        $attempt = ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->orderByDesc('attempt_number')
            ->first();

        // Pelanggaran dibaca lewat PESERTA, bukan lewat attempt: plate 16
        // menampilkan riwayatnya per siswa, dan siswa yang attempt-nya di
        // ulang tetap harus memperlihatkan riwayat dari percobaan
        // sebelumnya. Menyaringnya lewat attempt terakhir akan menghapus
        // justru pelanggaran yang membuat attempt-nya diulang.
        $pelanggaran = ExamViolation::query()
            ->where('exam_participant_id', $peserta->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(100)
            ->get();

        // Tindakan pada setiap baris dihitung ULANG dari urutannya, bukan
        // dibaca dari kolom - dan itu bukan penghematan, melainkan
        // kebenarannya: tangganya sifat rangkaian, bukan sifat satu baris.
        // Lihat TanggaPelanggaran::jejakTindakan().
        $jejak = $attempt === null
            ? []
            : TanggaPelanggaran::jejakTindakan($pelanggaran, $attempt->ambang($this->ambangBawaan()));

        $riwayat = $pelanggaran
            ->sortByDesc(fn (ExamViolation $v) => [$v->occurred_at?->getTimestamp() ?? 0, $v->id])
            ->values();

        return Inertia::render('Pengawas/Pelanggaran', [
            'ruang' => [
                'id' => $ruang->id,
                'kode' => $konteks->kodeRuang(),
                'nama' => $ruang->eventRoom?->room?->name,
                'sesi' => $ruang->session?->label(),
                'ujian' => $ruang->session?->exam?->title,
            ],
            'pelaku' => $konteks->jenisPelaku(),

            'peserta' => [
                'id' => $peserta->id,
                'nama' => $peserta->student?->name ?? 'Peserta',
                'nis' => $peserta->student?->studentProfile?->nis,
                'kelas' => $peserta->pesertaEvent?->kelas?->name,
            ],

            'angka' => [
                'pelanggaran' => $attempt?->violation_count ?? $pelanggaran->count(),
                'terberat' => $attempt?->violation_worst_severity ?? (int) $pelanggaran->max('severity'),
                // Progres dihitung dari jawaban, dan jawaban adalah Fase 7 -
                // lihat catatan yang sama di MonitoringController.
                'progres' => $attempt?->guard_flags_json['progres'] ?? null,
            ],

            'pengerjaan' => [
                'ada' => $attempt !== null,
                'status' => $attempt?->status,
                'status_label' => $attempt?->labelStatus() ?? 'Belum mulai',
                'status_nada' => $attempt?->nadaStatus() ?? 'mute',
                'terkunci' => $attempt?->dikunci() ?? false,
                'kunci_alasan' => $attempt?->lock_reason,
                'terhubung' => $attempt?->terhubung(),
                'terakhir' => $attempt?->last_seen_at?->format('H:i'),
            ],

            'riwayat' => $riwayat->map(fn (ExamViolation $v) => [
                'id' => $v->id,
                'jam' => $v->occurred_at?->format('H:i'),
                'tanggal' => $v->occurred_at?->format('d M'),
                'jenis' => $v->labelJenis(),
                'tingkat' => $v->labelTingkat(),
                'nada' => $v->nadaTingkat(),
                'severity' => $v->severity,
                'tindakan' => $jejak[$v->id] ?? TanggaPelanggaran::labelTindakan($v),
                'sumber' => $v->reported_by_client ? 'peramban' : 'server',
                // Detik, bukan milidetik: "pindah tab 3 detik" adalah
                // kalimat yang bisa dinilai manusia, "3120 ms" bukan.
                'durasi' => $v->duration_ms === null ? null : round($v->duration_ms / 1000, 1).' detik',
                'ditangani' => $v->resolved_at !== null,
                'tindakan_pengawas' => in_array($v->violation_type, ExamViolation::JENIS_PENGAWAS, true),
            ])->all(),
        ]);
    }

    /** Ambang bawaan sekolah, dipakai bila rantai peserta->ujian->event putus. */
    private function ambangBawaan(): int
    {
        return (int) (auth()->user()?->school?->settings['ambang_pelanggaran']
            ?? PengaturanBawaan::NILAI['ambang_pelanggaran']);
    }

    /**
     * "Buka kunci" - plate 16.
     *
     * Selama tidak ada attempt terkunci, penolakannya menyebut apa yang harus
     * dilakukan pengawas berikutnya ("tidak ada yang terkunci"), bukan sekadar
     * "tidak diizinkan" - karena yang gagal di sini bukan izinnya, melainkan
     * keadaannya.
     *
     * ---------------------------------------------------------------------
     * Pembukaan kunci MENULIS baris tersendiri, dan tidak menghapus apa pun
     * ---------------------------------------------------------------------
     * Docblock ini menuntutnya sejak Fase 6: "siapa yang membukanya, kapan,
     * dan atas alasan apa." Fase 7 memenuhinya lewat
     * `PencatatPelanggaran::catatPembukaan()`, yang menulis baris
     * `kunci_dibuka` ke tabel yang SAMA dengan pelanggaran siswa.
     *
     * Kenapa tabel yang sama, bukan tabel audit tersendiri: pertanyaan yang
     * dijawab plate 16 dan plate 29 adalah "apa yang terjadi pada pengerjaan
     * ini", dan jawaban yang terbelah dua tempat tidak lagi punya satu
     * jawaban. Barisnya ditandai `JENIS_PENGAWAS` sehingga tidak pernah
     * terbaca sebagai perbuatan siswa - lihat `ExamViolation::labelTingkat()`.
     *
     * Penghitung pelanggarannya SENGAJA tidak direset: mereset berarti satu
     * pembukaan kunci menghapus riwayat anak yang mengakibatkannya, dan
     * pengawas berikutnya yang membacanya akan mengira pengerjaan itu bersih.
     */
    public function buka(Request $request, ExamSessionRoom $ruang, ExamParticipant $peserta): RedirectResponse
    {
        $konteks = KonteksRuang::dari($ruang);
        $this->milikRuang($ruang, $peserta);

        $valid = $request->validate([
            'alasan' => ['nullable', 'string', 'max:200'],
        ]);

        $attempt = ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->orderByDesc('attempt_number')
            ->first();

        if ($attempt === null) {
            return back()->with('galat', 'Peserta ini belum mulai mengerjakan, jadi tidak ada yang terkunci.');
        }

        if (! $attempt->dikunci()) {
            return back()->with('galat', 'Pengerjaan peserta ini tidak sedang terkunci.');
        }

        $alasan = trim((string) ($valid['alasan'] ?? ''));

        $this->pencatat->catatPembukaan(
            $attempt,
            $konteks->pelakuLabel(),
            $alasan === '' ? 'Dibuka dari papan pengawas ruang.' : $alasan,
            $konteks->penggunaId(),
        );

        return back()->with('sukses', 'Kunci dibuka. Penghitung pelanggaran tidak direset, dan pembukaannya tercatat.');
    }

    /**
     * Berkas pelanggaran satu peserta, dipastikan duduk di ruang ini.
     *
     * Sama seperti `PresensiController::milikRuang()`: peserta yang tidak
     * ditemukan dan peserta yang ditemukan di ruang lain berakhir sama,
     * yaitu 404. Bukan 403 - membedakannya akan membuktikan bahwa barisnya
     * ada di tempat lain.
     */
    private function milikRuang(ExamSessionRoom $ruang, ExamParticipant $peserta): ExamParticipant
    {
        if ((int) $peserta->exam_session_room_id !== (int) $ruang->id) {
            abort(404);
        }

        return $peserta;
    }
}
