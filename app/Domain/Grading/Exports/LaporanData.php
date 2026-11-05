<?php

namespace App\Domain\Grading\Exports;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Event\Models\EventClass;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Grading\Models\ExamResult;
use App\Domain\Grading\Models\QuestionAnalysis;
use App\Domain\Tenancy\Support\PengaturanBawaan;
use Illuminate\Support\Collection;

/**
 * Sumber data laporan - SATU tempat, dipakai tab layar dan berkas ekspor.
 *
 * =====================================================================
 * Mengapa layar dan berkas berbagi satu kueri
 * =====================================================================
 * Plate 22 menampilkan tabel yang SAMA dengan yang diekspor, dan ekspor yang
 * berbeda dari yang dilihat Admin adalah laporan yang tidak bisa
 * dipertanggungjawabkan saat angkanya dipertanyakan. Satu kelas, satu daftar
 * kolom, dan dua penyaji (Inertia dan XLSX/CSV/PDF).
 *
 * Perbedaannya hanya pada VOLUME: layar memaginasi lima puluh baris, berkas
 * mengambil semuanya. Penyaringnya sama persis.
 *
 * =====================================================================
 * Lima jenis, dan yang belum punya data tetap menyajikan KOLOM
 * =====================================================================
 * Berkas kosong tanpa kepala adalah berkas yang dibuka Excel lalu ditutup -
 * Admin tidak tahu apakah ekspornya gagal atau datanya memang kosong. Setiap
 * jenis karena itu membawa `kolom()` sendiri, dan itu yang ditulis lebih dulu
 * ke berkasnya.
 */
class LaporanData
{
    /** Lima tab plate 22, dalam urutan tampilnya. */
    public const JENIS = [
        'nilai' => 'Nilai per siswa',
        'rekap_kelas' => 'Rekap kelas',
        'analisis_butir' => 'Analisis butir',
        'kehadiran' => 'Kehadiran',
        'pelanggaran' => 'Pelanggaran',
    ];

    /** Tiga tombol di kanan atas plate 22. */
    public const FORMAT = ['xlsx' => 'XLSX', 'csv' => 'CSV', 'pdf' => 'PDF'];

    /**
     * @param  array{kelas?: string|null, mapel?: string|null}  $parameter
     * @return array<string, string>
     */
    public static function kolom(string $jenis): array
    {
        return match ($jenis) {
            'nilai' => [
                'nis' => 'NIS',
                'nama' => 'Nama',
                'kelas' => 'Kelas',
                'mapel' => 'Mata pelajaran',
                'ujian' => 'Ujian',
                'objektif' => 'Nilai objektif',
                'uraian' => 'Nilai uraian',
                'nilai' => 'Nilai akhir',
                'status' => 'Status',
            ],
            'rekap_kelas' => [
                'kelas' => 'Kelas',
                'peserta' => 'Peserta',
                'sudah_dinilai' => 'Sudah dinilai',
                'rata_rata' => 'Rata-rata',
                'tertinggi' => 'Tertinggi',
                'terendah' => 'Terendah',
                'remedial' => 'Remedial',
            ],
            'analisis_butir' => [
                'ujian' => 'Ujian',
                'nomor' => 'No',
                'judul' => 'Ringkas soal',
                'jenis' => 'Jenis',
                'dijawab' => 'Dijawab',
                'benar' => 'Benar',
                'persen_benar' => 'Persen benar',
                'daya_beda' => 'Daya beda',
                'putusan' => 'Putusan',
            ],
            'kehadiran' => [
                'nis' => 'NIS',
                'nama' => 'Nama',
                'kelas' => 'Kelas',
                'ujian' => 'Ujian',
                'status' => 'Status pengerjaan',
                'mulai' => 'Mulai',
                'kumpul' => 'Dikumpulkan',
            ],
            'pelanggaran' => [
                'nama' => 'Nama',
                'kelas' => 'Kelas',
                'ujian' => 'Ujian',
                'jenis' => 'Jenis',
                'tingkat' => 'Tingkat',
                'waktu' => 'Waktu',
            ],
        };
    }

    /**
     * Seluruh baris satu jenis laporan.
     *
     * @param  array<string, mixed>  $parameter
     * @return Collection<int, array<string, mixed>>
     */
    public static function ambil(string $jenis, int $eventId, array $parameter): Collection
    {
        return match ($jenis) {
            'nilai' => self::nilai($eventId, $parameter),
            'rekap_kelas' => self::rekapKelas($eventId, $parameter),
            'analisis_butir' => self::analisisButir($eventId, $parameter),
            'kehadiran' => self::kehadiran($eventId, $parameter),
            'pelanggaran' => self::pelanggaran($eventId, $parameter),
            default => collect(),
        };
    }

    /* ------------------------------------------------------------ Penyaji */

    private static function nilai(int $eventId, array $p): Collection
    {
        $kkm = self::kkm();

        return ExamResult::query()
            ->whereIn('exam_attempt_id', ExamAttempt::query()
                ->whereHas('penempatan.exam', fn ($q) => $q->where('event_id', $eventId))
                ->select('id'))
            ->with([
                'pengerjaan:id,exam_participant_id',
                'pengerjaan.penempatan:id,event_participant_id,exam_id',
                'pengerjaan.penempatan.exam:id,title,subject_id',
                'pengerjaan.penempatan.exam.subject:id,name',
                'pengerjaan.penempatan.pesertaEvent:id,student_id,class_id',
                'pengerjaan.penempatan.pesertaEvent.student:id,name,username',
                'pengerjaan.penempatan.pesertaEvent.kelas:id,name',
            ])
            ->when($p['kelas'] ?? null, fn ($q, $kelas) => $q->whereHas(
                'pengerjaan.penempatan.pesertaEvent',
                fn ($s) => $s->where('class_id', $kelas),
            ))
            ->when($p['mapel'] ?? null, fn ($q, $mapel) => $q->whereHas(
                'pengerjaan.penempatan.exam',
                fn ($s) => $s->where('subject_id', $mapel),
            ))
            ->orderBy('id')
            ->get()
            ->map(function (ExamResult $r) use ($kkm) {
                $peserta = $r->pengerjaan?->penempatan?->pesertaEvent;

                return [
                    'nis' => $peserta?->student?->username,
                    'nama' => $peserta?->student?->name,
                    'kelas' => $peserta?->kelas?->name,
                    'mapel' => $r->pengerjaan?->penempatan?->exam?->subject?->name,
                    'ujian' => $r->pengerjaan?->penempatan?->exam?->title,
                    'objektif' => (float) $r->automatic_score,
                    // Berkas juga menulis null, bukan nol - alasan yang sama
                    // dengan plate 07: 0 berarti "dinilai, tidak dapat poin".
                    'uraian' => $r->final() ? (float) $r->essay_score : null,
                    'nilai' => (float) $r->final_percentage,
                    'status' => $r->final()
                        ? ($r->lulus($kkm) ? 'tuntas' : 'remedial')
                        : 'belum dinilai',
                ];
            })
            ->values();
    }

    private static function rekapKelas(int $eventId, array $p): Collection
    {
        $kkm = self::kkm();

        $kelas = SchoolClass::query()
            ->whereIn('id', EventClass::query()
                ->where('event_id', $eventId)
                ->select('class_id'))
            ->when($p['kelas'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name')
            ->get();

        return $kelas->map(function (SchoolClass $k) use ($eventId, $kkm) {
            $hasil = ExamResult::query()
                ->whereIn('exam_attempt_id', ExamAttempt::query()
                    ->whereHas('penempatan.exam', fn ($q) => $q->where('event_id', $eventId))
                    ->whereHas('penempatan.pesertaEvent', fn ($q) => $q->where('class_id', $k->id))
                    ->select('id'))
                ->get();

            $final = $hasil->filter(fn (ExamResult $r) => $r->final());

            return [
                'kelas' => $k->name,
                'peserta' => $hasil->count(),
                'sudah_dinilai' => $final->count(),
                'rata_rata' => $final->isNotEmpty() ? round((float) $final->avg('final_percentage'), 2) : null,
                'tertinggi' => $final->isNotEmpty() ? round((float) $final->max('final_percentage'), 2) : null,
                'terendah' => $final->isNotEmpty() ? round((float) $final->min('final_percentage'), 2) : null,
                'remedial' => $final->filter(fn (ExamResult $r) => (float) $r->final_percentage < $kkm)->count(),
            ];
        })->values();
    }

    private static function analisisButir(int $eventId, array $p): Collection
    {
        $analisis = QuestionAnalysis::query()
            ->whereIn('exam_question_id', ExamQuestion::query()
                ->whereIn('exam_id', Exam::query()->where('event_id', $eventId)->select('id'))
                ->select('id'))
            ->with('butir:id,exam_id,position,judul,jenis')
            ->get();

        return $analisis
            ->when($p['mapel'] ?? null, fn ($c, $mapel) => $c->filter(
                fn ($a) => (int) Exam::query()->whereKey($a->butir?->exam_id)->value('subject_id') === (int) $mapel,
            ))
            ->sortBy(fn ($a) => [$a->butir?->exam_id, $a->butir?->position])
            ->map(fn ($a) => [
                'ujian' => Exam::query()->whereKey($a->butir?->exam_id)->value('title'),
                'nomor' => $a->butir?->position,
                'judul' => $a->butir?->judul,
                'jenis' => $a->butir?->jenis?->label(),
                'dijawab' => (int) $a->answered_count,
                'benar' => (int) $a->correct_count,
                'persen_benar' => (float) $a->percent_correct,
                'daya_beda' => $a->discrimination === null ? null : (float) $a->discrimination,
                'putusan' => $a->verdict,
            ])
            ->values();
    }

    private static function kehadiran(int $eventId, array $p): Collection
    {
        return ExamAttempt::query()
            ->whereHas('penempatan.exam', fn ($q) => $q->where('event_id', $eventId))
            ->when($p['kelas'] ?? null, fn ($q, $kelas) => $q->whereHas(
                'penempatan.pesertaEvent',
                fn ($s) => $s->where('class_id', $kelas),
            ))
            ->when($p['mapel'] ?? null, fn ($q, $mapel) => $q->whereHas(
                'penempatan.exam',
                fn ($s) => $s->where('subject_id', $mapel),
            ))
            ->with([
                'penempatan.exam:id,title,subject_id',
                'penempatan.pesertaEvent:id,student_id,class_id',
                'penempatan.pesertaEvent.student:id,name,username',
                'penempatan.pesertaEvent.kelas:id,name',
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (ExamAttempt $a) => [
                'nis' => $a->penempatan?->pesertaEvent?->student?->username,
                'nama' => $a->penempatan?->pesertaEvent?->student?->name,
                'kelas' => $a->penempatan?->pesertaEvent?->kelas?->name,
                'ujian' => $a->penempatan?->exam?->title,
                'status' => $a->labelStatus(),
                'mulai' => $a->started_at?->format('Y-m-d H:i'),
                'kumpul' => $a->submitted_at?->format('Y-m-d H:i'),
            ])
            ->values();
    }

    private static function pelanggaran(int $eventId, array $p): Collection
    {
        return ExamViolation::query()
            ->whereHas('attempt.penempatan.exam', fn ($q) => $q->where('event_id', $eventId))
            ->when($p['kelas'] ?? null, fn ($q, $kelas) => $q->whereHas(
                'attempt.penempatan.pesertaEvent',
                fn ($s) => $s->where('class_id', $kelas),
            ))
            ->with([
                'attempt:id,exam_participant_id',
                'attempt.penempatan:id,event_participant_id,exam_id',
                'attempt.penempatan.exam:id,title',
                'attempt.penempatan.pesertaEvent:id,student_id,class_id',
                'attempt.penempatan.pesertaEvent.student:id,name',
                'attempt.penempatan.pesertaEvent.kelas:id,name',
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (ExamViolation $v) => [
                'nama' => $v->attempt?->penempatan?->pesertaEvent?->student?->name,
                'kelas' => $v->attempt?->penempatan?->pesertaEvent?->kelas?->name,
                'ujian' => $v->attempt?->penempatan?->exam?->title,
                'jenis' => $v->labelJenis(),
                'tingkat' => $v->labelTingkat(),
                'waktu' => $v->created_at?->format('Y-m-d H:i'),
            ])
            ->values();
    }

    private static function kkm(): int
    {
        $nilai = (int) (auth()->user()?->school?->settings['kkm'] ?? PengaturanBawaan::NILAI['kkm']);

        return min(100, max(0, $nilai));
    }
}
