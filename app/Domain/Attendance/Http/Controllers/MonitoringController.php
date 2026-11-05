<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamIncident;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Services\InsidenService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Papan monitoring ruang - wireframe plate 15.
 *
 * =====================================================================
 * Fase 6 MEMBACA exam_attempts dan exam_violations; Fase 7 menulisnya
 * =====================================================================
 * Seluruh laman ini dibangun terhadap tabel yang belum punya penulis, dan
 * itu keputusan yang dikunci di awal fase. Alasannya bukan keberanian:
 * bentuk yang benar untuk "peserta yang sedang mengerjakan" sudah bisa
 * ditetapkan sekarang, dan menetapkannya sekarang berarti Fase 7 tinggal
 * mengisi kolomnya - bukan mengubah tata letak papan yang sudah dipakai
 * orang di hari-H.
 *
 * Satu hal yang karena itu harus jujur: papan ini menampilkan "belum
 * mulai" untuk semua orang, dan itu memang keadaannya sampai mesin ujian
 * ada. Angka yang dikarang akan jauh lebih buruk.
 *
 * ---------------------------------------------------------------------
 * Pengawas hanya melihat siswa di ruangnya
 * ---------------------------------------------------------------------
 * "Nama siswa di ruang lain tidak muncul sama sekali, bukan disamarkan."
 * Karena itu daftarnya dibangun dari penempatan di ruang INI, dan attempt
 * dicari lewat penempatannya - bukan dari daftar attempt seluruh sekolah
 * yang lalu disaring.
 */
class MonitoringController
{
    public function __construct(private readonly InsidenService $insiden) {}

    public function index(ExamSessionRoom $ruang): Response
    {
        $konteks = KonteksRuang::dari($ruang);

        $penempatan = ExamParticipant::query()
            ->where('exam_session_room_id', $ruang->id)
            ->with([
                'student:id,name',
                'student.studentProfile:id,user_id,nis',
                'pesertaEvent.kelas:id,name',
            ])
            ->get()
            ->sortBy(fn (ExamParticipant $p) => $p->student?->name)
            ->values();

        // Satu query untuk seluruh attempt, bukan satu per peserta: papan
        // ini di segarkan terus selama ujian berjalan, justru saat basis
        // data paling sibuk.
        $attempt = ExamAttempt::query()
            ->whereIn('exam_participant_id', $penempatan->pluck('id'))
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('exam_participant_id')
            ->map(fn ($baris) => $baris->last());

        // Pelanggaran terberat per peserta - dipakai chip "2 keluar tab".
        $pelanggaran = ExamViolation::query()
            ->whereIn('exam_participant_id', $penempatan->pluck('id'))
            ->selectRaw('exam_participant_id, violation_type, count(*) as jumlah')
            ->groupBy('exam_participant_id', 'violation_type')
            ->get()
            ->groupBy('exam_participant_id');

        $pengerjaan = $attempt->filter(fn (?ExamAttempt $a) => $a?->status === ExamAttempt::STATUS_BERJALAN)->count();
        $selesai = $attempt->filter(fn (?ExamAttempt $a) => $a?->status === ExamAttempt::STATUS_DIKIRIM)->count();
        $terputus = $attempt->filter(fn (?ExamAttempt $a) => $a !== null && $a->terhubung() === false)->count();

        return Inertia::render('Pengawas/Monitoring', [
            'ruang' => [
                'id' => $ruang->id,
                'kode' => $konteks->kodeRuang(),
                'nama' => $ruang->eventRoom?->room?->name,
                'sesi' => $ruang->session?->label(),
                'ujian' => $ruang->session?->exam?->title,
                'berakhir_pada' => $ruang->session?->hard_end_at?->toIso8601String(),
            ],
            'pelaku' => $konteks->jenisPelaku(),

            'angka' => [
                'peserta' => $penempatan->count(),
                'mengerjakan' => $pengerjaan,
                'selesai' => $selesai,
                'terputus' => $terputus,
                'pelanggaran' => $pelanggaran->flatten()->sum('jumlah'),
                'insiden' => $this->insiden->jumlahTerbuka($ruang),
            ],

            'kursi' => $penempatan->values()->map(function (ExamParticipant $p, int $i) use ($attempt, $pelanggaran) {
                $a = $attempt->get($p->id);
                $jenis = $pelanggaran->get($p->id, collect());

                return [
                    'peserta_id' => $p->id,
                    'kursi' => $i + 1,
                    'nama' => $p->student?->name ?? 'Peserta',
                    'nis' => $p->student?->studentProfile?->nis,
                    'kelas' => $p->pesertaEvent?->kelas?->name,

                    'status' => $a?->status,
                    'status_label' => $a?->labelStatus() ?? 'Belum mulai',
                    'status_nada' => $a?->nadaStatus() ?? 'mute',
                    'terhubung' => $a?->terhubung(),

                    // Progres dihitung dari jawaban, dan jawaban adalah Fase 7.
                    // Bentuknya tetap dikirim supaya laman tidak berubah lagi.
                    'progres' => $a?->guard_flags_json['progres'] ?? null,
                    'pelanggaran' => $a?->violation_count ?? 0,
                    'terberat' => $a?->violation_worst_severity ?? 0,
                    'jenis_pelanggaran' => $jenis
                        ->map(fn ($v) => $v->jumlah.' '.strtolower(ExamViolation::JENIS[$v->violation_type] ?? $v->violation_type))
                        ->values()
                        ->all(),
                    'terkunci' => $a?->dikunci() ?? false,
                    'kunci_alasan' => $a?->lock_reason,
                ];
            })->all(),

            'insiden' => $this->insiden->daftarRuang($ruang)->map(fn (ExamIncident $i) => [
                'id' => $i->id,
                'jenis' => $i->labelJenis(),
                'keterangan' => $i->description,
                'peserta' => $i->peserta?->student?->name,
                'pelaku' => $i->pelaku_label,
                'jam' => $i->occurred_at?->format('H:i'),
                'tanggal' => $i->occurred_at?->format('d M'),
                'status' => $i->status,
                'terbuka' => $i->terbuka(),
            ])->all(),

            'jenis_insiden' => ExamIncident::JENIS,
        ]);
    }

    /** Plate 15: "Catat insiden". */
    public function catatInsiden(ExamSessionRoom $ruang, Request $request): RedirectResponse
    {
        $konteks = KonteksRuang::dari($ruang);

        $valid = $request->validate([
            'jenis' => ['required', 'string'],
            'keterangan' => ['required', 'string', 'min:4', 'max:1000'],
            'peserta_id' => ['nullable', 'integer'],
        ], [
            'keterangan.required' => 'Insiden harus disertai keterangan.',
        ], ['jenis' => 'jenis insiden', 'keterangan' => 'keterangan']);

        // Peserta yang disebut harus benar-benar duduk di ruang ini. Tanpa
        // pemeriksaan ini, insiden bisa ditempelkan ke siswa ruang lain -
        // dan catatan perilaku yang salah orang adalah catatan yang tidak
        // bisa ditarik kembali.
        $peserta = null;

        if (! empty($valid['peserta_id'])) {
            $peserta = ExamParticipant::query()
                ->where('exam_session_room_id', $ruang->id)
                ->find($valid['peserta_id']);

            if ($peserta === null) {
                abort(404);
            }
        }

        try {
            $this->insiden->catat($ruang, $konteks, $valid['jenis'], $valid['keterangan'], $peserta);
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Insiden tercatat.');
    }
}
