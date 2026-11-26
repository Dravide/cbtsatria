<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamIncident;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Models\RoomSignal;
use App\Domain\Attendance\Services\InsidenService;
use App\Domain\Attendance\Services\JawabanService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Attendance\Services\MesinUjian;
use App\Domain\Attendance\Services\RadarAnomaliRuang;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\NomorKursi;
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
    public function __construct(
        private readonly InsidenService $insiden,
        private readonly NomorKursi $kursi,
        private readonly RadarAnomaliRuang $radar,
    ) {}

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
            ->get();

        // Urutannya dari `NomorKursi`, BUKAN disusun ulang di sini: nomor
        // kursi di papan ini harus sama dengan nomor yang dibaca anak di
        // ponselnya dan nomor yang tercetak di tablo. Aturan urutannya satu
        // tempat - lihat catatan panjang di `NomorKursi`.
        //
        // Nomornya dihitung atas RUANG EVENT, karena kursi anak tidak
        // berpindah antar sesi. Kuncinya `student_id`, bukan id penempatan:
        // satu siswa punya satu baris penempatan per ujian, dan menomori
        // barisnya akan menaruh siswa yang sama di dua kursi. Karena itu
        // `peta()` - yang berkunci id penempatan - tidak dipakai di sini.
        $nomor = $this->kursi->petaRuangEvent((int) $ruang->eventRoom?->id);

        $penempatan = $this->kursi->urutkan($penempatan);

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
        $terputus = $attempt->filter(
            fn (?ExamAttempt $a) => $a !== null
                && $a->status === ExamAttempt::STATUS_BERJALAN
                && $a->terhubung() === false,
        )->count();

        return Inertia::render('Pengawas/Monitoring', [
            'ruang' => [
                'id' => $ruang->id,
                'kode' => $konteks->kodeRuang(),
                'nama' => $ruang->eventRoom?->room?->name,
                'sesi' => $ruang->session?->label(),
                'ujian' => $ruang->session?->exam?->title,
                'berakhir_pada' => $ruang->session?->hard_end_at?->toIso8601String(),
                'meja_per_baris' => NomorKursi::MEJA_PER_BARIS,
                'kursi_per_meja' => NomorKursi::KURSI_PER_MEJA,
                'kapasitas' => $ruang->eventRoom?->kapasitasEfektif() ?? $penempatan->count(),
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

            'kursi' => $penempatan->map(function (ExamParticipant $p) use ($attempt, $pelanggaran, $nomor) {
                $a = $attempt->get($p->id);
                $jenis = $pelanggaran->get($p->id, collect());

                return [
                    'peserta_id' => $p->id,
                    'kursi' => $nomor[(int) $p->student_id] ?? null,
                    'nama' => $p->student?->name ?? 'Peserta',
                    'nis' => $p->student?->studentProfile?->nis,
                    'kelas' => $p->pesertaEvent?->kelas?->name,

                    'status' => $a?->status,
                    'status_label' => $a?->labelStatus() ?? 'Belum mulai',
                    'status_nada' => $a?->nadaStatus() ?? 'mute',
                    'terhubung' => $a !== null && $a->status === ExamAttempt::STATUS_BERJALAN ? $a->terhubung() : null,

                    // Progres dihitung dari jawaban, dan jawaban adalah Fase 7.
                    // Bentuknya tetap dikirim supaya laman tidak berubah lagi.
                    'progres' => $a?->guard_flags_json['progres'] ?? null,
                    'nomor_soal' => $a?->guard_flags_json['nomor'] ?? null,
                    'denyut_pada' => $a?->last_heartbeat_at?->format('H:i:s'),
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
            'radar_anomali' => $this->radar->deteksi($ruang, $penempatan, $attempt),
            'sinyal' => RoomSignal::query()
                ->where('exam_session_room_id', $ruang->id)
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (RoomSignal $s) => [
                    'id' => $s->id,
                    'jenis' => $s->jenis,
                    'label' => $s->labelJenis(),
                    'catatan' => $s->catatan,
                    'status' => $s->status,
                    'jam' => $s->created_at?->format('H:i'),
                ])
                ->all(),
            'jenis_sinyal' => RoomSignal::JENIS,
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

    /** Kirim sinyal darurat infrastruktur ke Admin/Teknisi. */
    public function kirimSinyal(ExamSessionRoom $ruang, Request $request): RedirectResponse
    {
        $konteks = KonteksRuang::dari($ruang);

        $valid = $request->validate([
            'jenis' => ['required', 'string', 'in:'.implode(',', array_keys(RoomSignal::JENIS))],
            'catatan' => ['nullable', 'string', 'max:255'],
        ]);

        RoomSignal::create([
            'school_id' => $ruang->school_id,
            'exam_session_room_id' => $ruang->id,
            'jenis' => $valid['jenis'],
            'catatan' => $valid['catatan'] ?? null,
            'pelapor_id' => $konteks->penggunaId(),
            'pelapor_nama' => $konteks->pelakuLabel(),
            'status' => RoomSignal::STATUS_AKTIF,
        ]);

        return back()->with('sukses', 'Sinyal teknisi terkirim ke panitia/admin.');
    }

    /** Terima paket jawaban darurat (QR/kode bertanda tangan HMAC) saat perangkat siswa offline. */
    public function terimaQrDarurat(
        ExamSessionRoom $ruang,
        Request $request,
        JawabanService $jawaban,
        MesinUjian $mesin,
    ): RedirectResponse {
        KonteksRuang::dari($ruang);

        $valid = $request->validate([
            'paket' => ['required', 'string', 'max:32000'],
        ]);

        $decoded = json_decode(base64_decode($valid['paket'], true) ?: '', true);

        if (! is_array($decoded) || ! isset($decoded['a'], $decoded['j'], $decoded['s']) || ! is_array($decoded['j'])) {
            return back()->with('galat', 'Format paket QR darurat tidak valid.');
        }

        $attempt = ExamAttempt::query()
            ->whereKey((int) $decoded['a'])
            ->whereHas('penempatan', fn ($q) => $q->where('exam_session_room_id', $ruang->id))
            ->first();

        if ($attempt === null) {
            return back()->with('galat', 'Attempt peserta tidak ditemukan di ruang ini.');
        }

        $secret = hash_hmac(
            'sha256',
            'qr-darurat|'.$attempt->id.'|'.$attempt->started_at?->toIso8601String(),
            (string) config('app.key'),
        );
        $expected = hash_hmac('sha256', json_encode(['a' => $attempt->id, 'j' => $decoded['j']], JSON_UNESCAPED_UNICODE), $secret);

        if (! hash_equals($expected, (string) $decoded['s'])) {
            return back()->with('galat', 'Tanda tangan paket darurat tidak cocok.');
        }

        $butirMap = AttemptQuestion::query()
            ->where('exam_attempt_id', $attempt->id)
            ->get()
            ->keyBy('id');

        $tersimpan = 0;

        foreach ($decoded['j'] as $butirId => $isi) {
            $butir = $butirMap->get((int) $butirId);

            if ($butir !== null && is_array($isi)) {
                $jawaban->simpan($attempt, $butir, $isi);
                $tersimpan++;
            }
        }

        if ($attempt->status === ExamAttempt::STATUS_BERJALAN) {
            $attempt->forceFill([
                'status' => ExamAttempt::STATUS_DIKIRIM,
                'submitted_at' => now(),
            ])->save();
            $mesin->tutupBilaKedaluwarsa($attempt);
        }

        return back()->with('sukses', "Paket darurat diterima ({$tersimpan} jawaban disinkronkan).");
    }
}
