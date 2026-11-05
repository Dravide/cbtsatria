<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Support\TanggaPelanggaran;
use App\Domain\Exam\Models\ExamParticipant;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Riwayat pelanggaran saya - wireframe plate 29.
 *
 * =====================================================================
 * Siswa membaca catatannya sendiri, dan itu keputusan yang disengaja
 * =====================================================================
 * Plate 29 menulis konsekuensinya apa adanya: "Ini keputusan yang sedikit
 * merugikan pengawas - catatan yang dapat dibaca yang bersangkutan akan
 * selalu ditanyakan - tetapi itu justru tujuannya: catatan yang tidak dapat
 * dibantah dengan data adalah catatan yang tidak layak dipakai untuk
 * menjatuhkan sanksi."
 *
 * Konsekuensi teknisnya ada di sini: yang dikirim adalah catatan yang SAMA
 * dengan yang dibaca pengawas di plate 16 - jam, jenis, tingkat, dan
 * tindakannya. Bukan versi yang diperhalus. Siswa yang menerima versi
 * ringkas lalu mendengar versi lengkapnya dari pengawas akan menyimpulkan
 * bahwa sistemnya menyembunyikan sesuatu.
 *
 * ---------------------------------------------------------------------
 * HANYA BACA, dan tidak ada satu pun rute tulis di kelas ini
 * ---------------------------------------------------------------------
 * `exam_violations` append-only sejak migrasinya: `UPDATED_AT` null, dan
 * tidak ada route yang mengubahnya. Yang bisa dilakukan pengawas hanyalah
 * menandai `resolved_at`. Laman ini tidak menawarkan tombol apa pun - yang
 * ditawarkan plate 29 adalah CARA MENGAJUKAN KEBERATAN (menghubungi
 * pengawas atau Admin), dan itu kalimat, bukan tombol yang tidak melakukan
 * apa-apa.
 *
 * ---------------------------------------------------------------------
 * Lintas event, dengan pemilih event
 * ---------------------------------------------------------------------
 * Plate 29 menaruh pemilih event di bar atas - "satu laman untuk semua
 * event", sama seperti plate 28. Penyaringnya dikerjakan di sini, bukan di
 * peramban: daftar pelanggaran satu sekolah bisa panjang, dan menyaringnya
 * di peramban berarti mengirimkan seluruh riwayat anak-anak lain lebih dulu
 * supaya bisa disaring keluar.
 *
 * Yang dikirim tetap HANYA milik siswa ini - disaring lewat `student_id`
 * pada penempatannya, sama seperti JadwalSiswaController. Menyaring lewat
 * `exam_participant_id` saja tidak cukup: id penempatan anak lain bisa
 * ditebak.
 */
class PelanggaranSiswaController extends SiswaDasar
{
    public function index(Request $request): Response
    {
        $penempatan = ExamParticipant::query()
            ->where('student_id', $request->user()->id)
            ->with(['exam.subject:id,name', 'exam.event:id,name'])
            ->get();

        $idPenempatan = $penempatan->pluck('id');

        // Daftar event yang punya catatan - hanya yang punya, karena pemilih
        // berisi empat event kosong adalah pemilih yang harus dibuka satu per
        // satu untuk menemukan yang berisi.
        $perEvent = ExamViolation::query()
            ->whereIn('exam_participant_id', $idPenempatan)
            ->get()
            ->groupBy('exam_participant_id');

        $event = $penempatan
            ->groupBy(fn (ExamParticipant $p) => $p->exam?->event_id)
            ->map(function ($baris, $eventId) use ($perEvent) {
                $pertama = $baris->first();
                $idEvent = $pertama->exam?->event_id;

                $jumlah = $baris->sum(fn (ExamParticipant $p) => $perEvent->get($p->id)?->count() ?? 0);

                return [
                    'id' => $idEvent,
                    'nama' => $pertama->exam?->event?->name,
                    'jumlah' => $jumlah,
                ];
            })
            ->filter(fn (array $e) => $e['jumlah'] > 0)
            ->values();

        $terpilih = $request->integer('event') ?: $event->first()['id'] ?? null;

        $idTerpilih = $penempatan
            ->filter(fn (ExamParticipant $p) => (int) $p->exam?->event_id === (int) $terpilih)
            ->pluck('id');

        $pelanggaran = ExamViolation::query()
            ->whereIn('exam_participant_id', $idTerpilih)
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get();

        $attempt = ExamAttempt::query()
            ->whereIn('exam_participant_id', $idTerpilih)
            ->get();

        return Inertia::render('Siswa/Pelanggaran', [
            'nama' => $request->user()->name,
            'event' => $event->all(),
            'terpilih' => $terpilih,

            'angka' => [
                'total' => $this->hitungSiswa($pelanggaran),
                'terberat' => (int) $this->hitungSiswa($pelanggaran, true)->max('severity'),
                // "Pernah dikunci" dihitung dari attempt, bukan dari baris
                // pelanggaran: yang mengunci adalah keputusan mesin atas
                // rangkaiannya, dan anak yang terkunci lalu dibuka tetap
                // pernah mengalaminya.
                'pernah_dikunci' => $attempt->where('status', ExamAttempt::STATUS_DIKUNCI)->count()
                    + $pelanggaran->where('violation_type', 'kunci_dibuka')->count(),
            ],

            'riwayat' => $pelanggaran->map(fn (ExamViolation $v) => [
                'id' => $v->id,
                'jam' => $v->occurred_at?->format('H:i'),
                'tanggal' => $v->occurred_at?->format('d M'),
                'jenis' => $v->labelJenis(),
                'tindakan' => TanggaPelanggaran::labelTindakan($v),
                'tingkat' => $v->labelTingkat(),
                'nada' => $v->nadaTingkat(),
                'mapel' => $this->mapelDari($penempatan, $v->exam_participant_id),
                'durasi' => $v->duration_ms === null
                    ? null
                    : round($v->duration_ms / 1000, 1).' detik',
                'tindakan_pengawas' => in_array($v->violation_type, ExamViolation::JENIS_PENGAWAS, true),
            ])->all(),
        ]);
    }

    /**
     * Pelanggaran yang benar-benar perbuatan siswa.
     *
     * Tindakan pengawas (`kunci_dibuka`) dibuang dari hitungannya: "Total
     * kejadian 4" yang memuat satu pembukaan kunci berarti menuduh anak atas
     * perbuatan orang dewasa - kekeliruan yang sama yang dicegah
     * `labelTingkat()`.
     *
     * @param  Collection<int, ExamViolation>  $pelanggaran
     * @return Collection<int, ExamViolation>
     */
    private function hitungSiswa($pelanggaran, bool $semua = false)
    {
        $bersih = $pelanggaran->reject(
            fn (ExamViolation $v) => in_array($v->violation_type, ExamViolation::JENIS_PENGAWAS, true)
        );

        return $semua ? $bersih : $bersih->count();
    }

    /**
     * Nama mapel untuk satu baris riwayat.
     *
     * Dicari lewat penempatannya, bukan disimpan di baris pelanggaran:
     * `exam_violations` menyimpan id penempatan, dan menyalin nama mapelnya
     * ke sana akan membuat dua tempat yang menyimpan nama yang sama.
     *
     * @param  Collection<int, ExamParticipant>  $penempatan
     */
    private function mapelDari($penempatan, int $pesertaId): ?string
    {
        return $penempatan->firstWhere('id', $pesertaId)?->exam?->subject?->name;
    }
}
