<?php

namespace App\Domain\Grading\Exports;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Event\Models\EventClass;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
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
                'kehadiran' => 'Kehadiran',
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

    /**
     * Kehadiran per PENEMPATAN, bukan per attempt.
     *
     * ---------------------------------------------------------------------
     * Mengapa barisnya penempatan, dan bukan pengerjaan
     * ---------------------------------------------------------------------
     * Versi sebelumnya menyusuri `ExamAttempt`, sehingga tabel ini hanya
     * memuat siswa yang SUDAH membuka ujian. Siswa yang tidak pernah masuk
     * - yaitu orang yang justru dicari saat laporan kehadiran dibuka - tidak
     * punya attempt, dan karena itu tidak punya baris sama sekali. Laporan
     * yang tidak bisa menyebut siapa yang tidak datang bukan laporan
     * kehadiran; ia laporan pengerjaan.
     *
     * Yang menentukan kehadiran adalah `attendance_records`, yang ditulis
     * pengawas di ruang dan bukan disimpulkan dari aktivitas peramban:
     * siswa yang hadir tetapi tidak mengerjakan apa pun tetap HADIR, dan
     * siswa yang membuka ujian lalu pergi tetap TIDAK HADIR bila pengawas
     * mencatatnya begitu.
     *
     * ---------------------------------------------------------------------
     * Tiga keadaan yang harus dibedakan, dan tidak boleh digabung
     * ---------------------------------------------------------------------
     *   1. Sudah diverifikasi pengawas - Hadir / Terlambat / Tidak hadir /
     *      Izin, dibaca dari `attendance_records`.
     *   2. Belum diverifikasi - barisnya ada, statusnya masih `pending`.
     *   3. Belum ditempatkan - belum punya kursi, jadi belum punya baris
     *      presensi sama sekali. Menggabungkannya dengan (2) akan menuduh
     *      Admin lalai memverifikasi orang yang belum pernah ia tempatkan.
     *
     * Peserta berstatus `dikecualikan` tidak ikut: ia memang tidak
     * dijadwalkan mengerjakan ujian ini, dan menghitungnya sebagai tidak
     * hadir adalah tuduhan yang salah alamat.
     *
     * @param  array<string, mixed>  $p
     * @return Collection<int, array<string, mixed>>
     */
    private static function kehadiran(int $eventId, array $p): Collection
    {
        $peserta = ExamParticipant::query()
            ->whereHas('exam', fn ($q) => $q->where('event_id', $eventId))
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->when($p['kelas'] ?? null, fn ($q, $kelas) => $q->whereHas(
                'pesertaEvent',
                fn ($s) => $s->where('class_id', $kelas),
            ))
            ->when($p['mapel'] ?? null, fn ($q, $mapel) => $q->whereHas(
                'exam',
                fn ($s) => $s->where('subject_id', $mapel),
            ))
            ->with([
                'exam:id,title,subject_id',
                'pesertaEvent:id,student_id,class_id',
                'pesertaEvent.student:id,name,username',
                'pesertaEvent.kelas:id,name',
            ])
            ->orderBy('exam_id')
            ->orderBy('student_id')
            ->get();

        $idPenempatan = $peserta->pluck('id');

        // Dua kueri tetap, berapa pun jumlah pesertanya. Membaca presensi dan
        // pengerjaan lewat relasi per baris berarti dua kueri per siswa, dan
        // laporan satu angkatan berarti lebih dari dua ribu kueri untuk
        // halaman yang seharusnya selesai dalam satu tarikan napas.
        $presensi = AttendanceRecord::query()
            ->whereIn('exam_participant_id', $idPenempatan)
            ->get(['exam_participant_id', 'attendance_status'])
            ->keyBy('exam_participant_id');

        // Attempt TERAKHIR saja: peserta yang mengulang punya lebih dari satu,
        // dan yang menjawab "apakah ia mengerjakan" adalah yang terakhir.
        $pengerjaan = ExamAttempt::query()
            ->whereIn('exam_participant_id', $idPenempatan)
            ->orderByDesc('id')
            ->get(['id', 'exam_participant_id', 'status', 'started_at', 'submitted_at'])
            ->keyBy('exam_participant_id');

        return $peserta
            ->map(function (ExamParticipant $penempatan) use ($presensi, $pengerjaan) {
                $catatan = $presensi->get($penempatan->id);
                $attempt = $pengerjaan->get($penempatan->id);

                return [
                    'nis' => $penempatan->pesertaEvent?->student?->username,
                    'nama' => $penempatan->pesertaEvent?->student?->name,
                    'kelas' => $penempatan->pesertaEvent?->kelas?->name,
                    'ujian' => $penempatan->exam?->title,
                    'kehadiran' => $catatan === null
                        ? 'Belum ditempatkan'
                        : $catatan->attendance_status->label(),
                    'status' => $attempt?->labelStatus(),
                    'mulai' => $attempt?->started_at?->format('Y-m-d H:i'),
                    'kumpul' => $attempt?->submitted_at?->format('Y-m-d H:i'),
                ];
            })
            ->values();
    }

    /**
     * Jumlah per keadaan kehadiran, dari baris yang SAMA dengan tabelnya.
     *
     * Dihitung dari `kehadiran()`, bukan dari kueri sendiri: ringkasan yang
     * angkanya berbeda dari daftar di bawahnya adalah ringkasan yang membuat
     * Admin menghitung ulang barisnya satu per satu.
     *
     * Urutannya mengikuti `StatusPresensi` dengan dua tambahan - "Belum
     * diverifikasi" dan "Belum ditempatkan" - karena keduanya bukan nilai
     * enum: yang pertama ada di database sebagai `pending`, yang kedua
     * ketiadaan barisnya.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return array<int, array{kunci: string, label: string, jumlah: int, nada: string}>
     */
    public static function ringkasanKehadiran(Collection $baris): array
    {
        $hitung = fn (string $label) => $baris->where('kehadiran', $label)->count();

        return [
            ['kunci' => 'hadir', 'label' => 'Hadir', 'jumlah' => $hitung('Hadir'), 'nada' => 'ok'],
            ['kunci' => 'terlambat', 'label' => 'Terlambat', 'jumlah' => $hitung('Terlambat'), 'nada' => 'info'],
            ['kunci' => 'tidak_hadir', 'label' => 'Tidak hadir', 'jumlah' => $hitung('Tidak hadir'), 'nada' => 'bad'],
            ['kunci' => 'izin', 'label' => 'Izin', 'jumlah' => $hitung('Izin'), 'nada' => 'warn'],
            [
                'kunci' => 'belum_diverifikasi',
                'label' => StatusPresensi::Pending->label(),
                'jumlah' => $hitung(StatusPresensi::Pending->label()),
                'nada' => 'mute',
            ],
            [
                'kunci' => 'belum_ditempatkan',
                'label' => 'Belum ditempatkan',
                'jumlah' => $hitung('Belum ditempatkan'),
                'nada' => 'mute',
            ],
            ['kunci' => 'total', 'label' => 'Seluruh baris', 'jumlah' => $baris->count(), 'nada' => 'mute'],
        ];
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
