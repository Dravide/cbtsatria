<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\NomorKursi;
use Illuminate\Support\Collection;

/**
 * Radar anomali spasial berbasis denah kursi ruang ujian.
 *
 * Mendeteksi pasangan kursi bersebelahan (horizontal/vertikal/diagonal) yang
 * mengalami pelanggaran dalam jendela waktu berdekatan atau terputus bersamaan.
 */
class RadarAnomaliRuang
{
    public const JENDELA_DETIK = 20;

    public const KOLOM_BAWAAN = 4;

    public function __construct(private readonly NomorKursi $kursi) {}

    /**
     * Susun daftar peringatan spasial untuk satu ruang-sesi.
     *
     * @param  Collection<int, ExamParticipant>  $penempatan
     * @param  Collection<int, ExamAttempt|null>  $attempt
     * @return array<int, array<string, mixed>>
     */
    public function deteksi(ExamSessionRoom $ruang, Collection $penempatan, Collection $attempt): array
    {
        if ($penempatan->count() < 2) {
            return [];
        }

        $kolom = max(1, (int) ($ruang->eventRoom?->room?->columns ?? self::KOLOM_BAWAAN));
        $petaNomor = $this->kursi->petaRuangEvent((int) $ruang->eventRoom?->id);

        $posisi = [];

        foreach ($penempatan as $p) {
            $no = $petaNomor[$p->student_id] ?? null;

            if ($no === null) {
                continue;
            }

            $posisi[$p->id] = [
                'peserta' => $p,
                'kursi' => $no,
                'baris' => intdiv($no - 1, $kolom),
                'kolom' => ($no - 1) % $kolom,
            ];
        }

        $pelanggaran = ExamViolation::query()
            ->whereIn('exam_participant_id', $penempatan->pluck('id'))
            ->where('severity', '>=', 1)
            ->orderBy('occurred_at')
            ->get(['exam_participant_id', 'violation_type', 'occurred_at'])
            ->groupBy('exam_participant_id');

        $peringatan = [];
        $ids = array_keys($posisi);
        $total = count($ids);

        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                $a = $posisi[$ids[$i]];
                $b = $posisi[$ids[$j]];

                if (abs($a['baris'] - $b['baris']) > 1 || abs($a['kolom'] - $b['kolom']) > 1) {
                    continue;
                }

                $keduanyaPutus = ($attempt->get($a['peserta']->id)?->terhubung() === false)
                    && ($attempt->get($b['peserta']->id)?->terhubung() === false);

                $bersamaan = $this->pelanggaranBerdekatan(
                    $pelanggaran->get($a['peserta']->id, collect()),
                    $pelanggaran->get($b['peserta']->id, collect()),
                );

                if (! $keduanyaPutus && $bersamaan === 0) {
                    continue;
                }

                $peringatan[] = [
                    'kursi_a' => $a['kursi'],
                    'nama_a' => $a['peserta']->student?->name ?? 'Peserta',
                    'kursi_b' => $b['kursi'],
                    'nama_b' => $b['peserta']->student?->name ?? 'Peserta',
                    'bersamaan' => $bersamaan,
                    'keduanya_putus' => $keduanyaPutus,
                    'alasan' => $bersamaan > 0
                        ? "{$bersamaan} pelanggaran berdekatan (≤".self::JENDELA_DETIK.' dtk)'
                        : 'Terputus bersamaan di kursi bersebelahan',
                ];
            }
        }

        return $peringatan;
    }

    /**
     * @param  Collection<int, ExamViolation>  $va
     * @param  Collection<int, ExamViolation>  $vb
     */
    private function pelanggaranBerdekatan(Collection $va, Collection $vb): int
    {
        if ($va->isEmpty() || $vb->isEmpty()) {
            return 0;
        }

        $cocok = 0;

        foreach ($va as $satu) {
            foreach ($vb as $dua) {
                if ($satu->occurred_at === null || $dua->occurred_at === null) {
                    continue;
                }

                if (abs($satu->occurred_at->diffInSeconds($dua->occurred_at, false)) <= self::JENDELA_DETIK) {
                    $cocok++;
                    break;
                }
            }
        }

        return $cocok;
    }
}
