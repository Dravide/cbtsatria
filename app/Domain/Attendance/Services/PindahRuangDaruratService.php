<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\PetaKursiEvent;
use App\Domain\Facility\Models\Room;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Pindah ruang darurat untuk SATU sesi berjalan (tidak mengubah penempatan sesi lain)
 * serta pemetaan slot PC kios lab pada ruang ber-PC.
 */
final class PindahRuangDaruratService
{
    public const MODE_HP = 'hp';

    public const MODE_PC = 'pc';

    public function __construct(private readonly PetaKursiEvent $peta) {}

    /**
     * Daftar ruang lain pada sesi yang sama yang bisa menjadi tujuan pindah darurat,
     * lengkap dengan nomor PC yang masih kosong.
     *
     * @return array<int, array{
     *     id: int,
     *     kode: string,
     *     nama: ?string,
     *     kapasitas: int,
     *     terisi: int,
     *     pc: int,
     *     pc_tersedia: array<int, int>
     * }>
     */
    public function tujuanTersedia(ExamSessionRoom $ruangAsal): array
    {
        $ruangLain = ExamSessionRoom::query()
            ->with(['eventRoom.room:id,code,name,capacity,device_count'])
            ->where('exam_session_id', $ruangAsal->exam_session_id)
            ->where('id', '!=', $ruangAsal->id)
            ->orderBy('id')
            ->get();

        $hasil = [];

        foreach ($ruangLain as $tujuan) {
            $master = $tujuan->eventRoom?->room;
            $kapasitas = (int) ($tujuan->eventRoom?->kapasitasEfektif() ?? $master?->capacity ?? 0);
            $jumlahPc = (int) ($tujuan->eventRoom?->kursiBerPerangkat() ?? $master?->device_count ?? 0);

            $penghuni = ExamParticipant::query()
                ->where('exam_session_room_id', $tujuan->id)
                ->get(['id', 'student_id', 'emergency_device_mode', 'emergency_seat_number']);

            $terpakai = $this->pcTerpakai($tujuan, $penghuni);
            $pcTersedia = [];

            for ($n = 1; $n <= $jumlahPc; $n++) {
                if (! isset($terpakai[$n])) {
                    $pcTersedia[] = $n;
                }
            }

            $hasil[] = [
                'id' => (int) $tujuan->id,
                'kode' => $master?->code ?? "R-{$tujuan->id}",
                'nama' => $master?->name,
                'kapasitas' => $kapasitas,
                'terisi' => $penghuni->count(),
                'pc' => $jumlahPc,
                'pc_tersedia' => $pcTersedia,
            ];
        }

        return $hasil;
    }

    /**
     * Pindahkan satu peserta ke ruang lain HANYA pada sesi ini.
     */
    public function pindahkan(
        ExamParticipant $peserta,
        ExamSessionRoom $ruangAsal,
        ExamSessionRoom $ruangTujuan,
        string $mode,
        ?int $nomorPc,
        string $alasan,
    ): void {
        if ((int) $peserta->exam_session_room_id !== (int) $ruangAsal->id) {
            throw new DomainException('Peserta ini tidak berada di ruang asal tersebut.');
        }

        if ((int) $ruangTujuan->exam_session_id !== (int) $ruangAsal->exam_session_id) {
            throw new DomainException('Ruang tujuan harus berada pada sesi ujian yang sama.');
        }

        if ((int) $ruangTujuan->id === (int) $ruangAsal->id) {
            throw new DomainException('Ruang tujuan harus berbeda dari ruang saat ini.');
        }

        if (! in_array($mode, [self::MODE_HP, self::MODE_PC], true)) {
            throw new DomainException('Pilihan perangkat tidak valid.');
        }

        $alasanBersih = trim($alasan);

        if ($alasanBersih === '') {
            throw new DomainException('Alasan pindah ruang wajib diisi.');
        }

        $ruangTujuan->loadMissing('eventRoom.room:id,code,name,capacity,device_count');

        $jumlahPc = (int) ($ruangTujuan->eventRoom?->kursiBerPerangkat() ?? $ruangTujuan->eventRoom?->room?->device_count ?? 0);

        if ($mode === self::MODE_PC) {
            if ($jumlahPc <= 0) {
                throw new DomainException('Ruang tujuan tidak memiliki PC lab.');
            }

            if ($nomorPc === null || $nomorPc < 1 || $nomorPc > $jumlahPc) {
                throw new DomainException("Nomor PC harus antara 1 sampai {$jumlahPc}.");
            }
        }

        DB::transaction(function () use ($peserta, $ruangAsal, $ruangTujuan, $mode, $nomorPc, $alasanBersih): void {
            if ($mode === self::MODE_PC && $nomorPc !== null) {
                $penghuni = ExamParticipant::query()
                    ->where('exam_session_room_id', $ruangTujuan->id)
                    ->where('id', '!=', $peserta->id)
                    ->lockForUpdate()
                    ->get(['id', 'student_id', 'emergency_device_mode', 'emergency_seat_number']);

                $terpakai = $this->pcTerpakai($ruangTujuan, $penghuni);

                if (isset($terpakai[$nomorPc])) {
                    throw new DomainException("PC {$nomorPc} di ruang tujuan sudah dipakai peserta lain.");
                }
            }

            $peserta->forceFill([
                'exam_session_room_id' => $ruangTujuan->id,
                'emergency_device_mode' => $mode,
                'emergency_seat_number' => $mode === self::MODE_PC ? $nomorPc : null,
                'emergency_origin_room_id' => $peserta->emergency_origin_room_id ?? $ruangAsal->id,
                'emergency_reason' => mb_substr($alasanBersih, 0, 255),
                'emergency_moved_at' => now(),
            ])->save();
        });
    }

    /**
     * Pemetaan slot PC (PC 1..N) pada satu ruang master untuk halaman manajemen kios /admin/ruang/{ruang}.
     *
     * @return array{
     *     sesi_pilihan: array<int, array{id: int, label: string, ujian: string, jam: ?string, berjalan: bool}>,
     *     sesi_terpilih: ?array{id: int, event_id: int, label: string, ujian: string, jam: ?string, berjalan: bool},
     *     slot: array<int, array{
     *         nomor: int,
     *         kios_url: ?string,
     *         peserta: ?array{
     *             peserta_id: int,
     *             nama: string,
     *             nis: ?string,
     *             kelas: ?string,
     *             status_presensi: string,
     *             status_presensi_label: string,
     *             status_presensi_nada: string,
     *             status_pengerjaan: string,
     *             status_pengerjaan_nada: string,
     *             darurat: bool,
     *             alasan_darurat: ?string
     *         }
     *     }>,
     *     peserta_hp: array<int, array{
     *         peserta_id: int,
     *         nama: string,
     *         nis: ?string,
     *         kelas: ?string,
     *         darurat: bool,
     *         alasan_darurat: ?string
     *     }>
     * }
     */
    public function petaKiosRuang(Room $ruang, ?int $sesiRuangId = null, ?string $slugAktif = null): array
    {
        $sesiRuang = ExamSessionRoom::query()
            ->with([
                'eventRoom.room:id,code,name,capacity,device_count',
                'session.exam:id,event_id,title,subject_id',
                'session.exam.subject:id,name',
            ])
            ->whereHas('eventRoom', fn ($q) => $q->where('room_id', $ruang->id))
            ->whereHas('session')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $pilihan = $sesiRuang->map(function (ExamSessionRoom $sr): array {
            $s = $sr->session;
            $berjalan = $s !== null && now()->between($s->access_opens_at, $s->hard_end_at);

            return [
                'id' => (int) $sr->id,
                'label' => $s?->label() ?? 'Sesi',
                'ujian' => $s?->exam?->subject?->name ?? $s?->exam?->title ?? 'Ujian',
                'jam' => $s === null ? null : $s->access_opens_at->format('d/m H:i').'–'.$s->hard_end_at->format('H:i'),
                'berjalan' => $berjalan,
            ];
        })->values()->all();

        $terpilih = null;

        if ($sesiRuangId !== null) {
            $terpilih = $sesiRuang->firstWhere('id', $sesiRuangId);
        }

        if ($terpilih === null) {
            $terpilih = $sesiRuang->first(
                fn (ExamSessionRoom $sr) => $sr->session !== null
                    && now()->between($sr->session->access_opens_at, $sr->session->hard_end_at),
            ) ?? $sesiRuang->first();
        }

        $jumlahPc = (int) $ruang->device_count;
        $slot = [];
        $pesertaHp = [];

        if ($terpilih === null) {
            for ($n = 1; $n <= $jumlahPc; $n++) {
                $slot[] = [
                    'nomor' => $n,
                    'kios_url' => $slugAktif ? url("/lab/{$slugAktif}/{$n}") : null,
                    'peserta' => null,
                ];
            }

            return [
                'sesi_pilihan' => $pilihan,
                'sesi_terpilih' => null,
                'slot' => $slot,
                'peserta_hp' => [],
            ];
        }

        $pesertaList = ExamParticipant::query()
            ->with([
                'student:id,name',
                'student.studentProfile:id,user_id,nis',
                'pesertaEvent.kelas:id,name',
            ])
            ->where('exam_session_room_id', $terpilih->id)
            ->orderBy('id')
            ->get();

        $idPeserta = $pesertaList->pluck('id');

        $presensi = AttendanceRecord::query()
            ->whereIn('exam_participant_id', $idPeserta)
            ->get()
            ->keyBy('exam_participant_id');

        $attempts = ExamAttempt::query()
            ->whereIn('exam_participant_id', $idPeserta)
            ->orderByDesc('attempt_number')
            ->get()
            ->unique('exam_participant_id')
            ->keyBy('exam_participant_id');

        $kursiPeta = $this->peta->kursi((int) $terpilih->event_room_id);
        $perPc = [];

        foreach ($pesertaList as $p) {
            if ($p->emergency_device_mode === self::MODE_HP) {
                $pesertaHp[] = [
                    'peserta_id' => (int) $p->id,
                    'nama' => $p->student?->name ?? 'Peserta',
                    'nis' => $p->student?->studentProfile?->nis,
                    'kelas' => $p->pesertaEvent?->kelas?->name,
                    'darurat' => $p->emergency_moved_at !== null,
                    'alasan_darurat' => $p->emergency_reason,
                ];

                continue;
            }

            $nomor = $p->emergency_seat_number ?? ($kursiPeta[(int) $p->student_id] ?? null);

            if ($nomor === null || $nomor < 1 || $nomor > $jumlahPc) {
                continue;
            }

            $rec = $presensi->get($p->id);
            $att = $attempts->get($p->id);

            $perPc[$nomor] = [
                'peserta_id' => (int) $p->id,
                'nama' => $p->student?->name ?? 'Peserta',
                'nis' => $p->student?->studentProfile?->nis,
                'kelas' => $p->pesertaEvent?->kelas?->name,
                'status_presensi' => $rec?->attendance_status->value ?? 'pending',
                'status_presensi_label' => $rec?->attendance_status->pendek() ?? 'belum',
                'status_presensi_nada' => $rec?->attendance_status->nada() ?? 'mute',
                'status_pengerjaan' => $att?->labelStatus() ?? 'Belum mulai',
                'status_pengerjaan_nada' => $att?->nadaStatus() ?? 'mute',
                'darurat' => $p->emergency_moved_at !== null,
                'alasan_darurat' => $p->emergency_reason,
            ];
        }

        for ($n = 1; $n <= $jumlahPc; $n++) {
            $slot[] = [
                'nomor' => $n,
                'kios_url' => $slugAktif ? url("/lab/{$slugAktif}/{$n}") : null,
                'peserta' => $perPc[$n] ?? null,
            ];
        }

        $s = $terpilih->session;

        return [
            'sesi_pilihan' => $pilihan,
            'sesi_terpilih' => [
                'id' => (int) $terpilih->id,
                'event_id' => (int) ($s?->exam?->event_id ?? 0),
                'label' => $s?->label() ?? 'Sesi',
                'ujian' => $s?->exam?->subject?->name ?? $s?->exam?->title ?? 'Ujian',
                'jam' => $s === null ? null : $s->access_opens_at->format('d/m H:i').'–'.$s->hard_end_at->format('H:i'),
                'berjalan' => $s !== null && now()->between($s->access_opens_at, $s->hard_end_at),
            ],
            'slot' => $slot,
            'peserta_hp' => $pesertaHp,
        ];
    }

    /**
     * Peta nomor PC yang sedang dipakai pada satu ruang-sesi.
     *
     * @param  iterable<int, ExamParticipant>  $penghuni
     * @return array<int, true>
     */
    private function pcTerpakai(ExamSessionRoom $ruang, iterable $penghuni): array
    {
        $kursiPeta = $this->peta->kursi((int) $ruang->event_room_id);
        $terpakai = [];

        foreach ($penghuni as $p) {
            if ($p->emergency_device_mode === self::MODE_HP) {
                continue;
            }

            $nomor = $p->emergency_seat_number ?? ($kursiPeta[(int) $p->student_id] ?? null);

            if ($nomor !== null && $nomor > 0) {
                $terpakai[$nomor] = true;
            }
        }

        return $terpakai;
    }
}
