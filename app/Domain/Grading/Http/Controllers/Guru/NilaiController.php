<?php

namespace App\Domain\Grading\Http\Controllers\Guru;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Event\Models\EventClass;
use App\Domain\Exam\Models\Exam;
use App\Domain\Grading\Models\ExamResult;
use App\Domain\Grading\Support\UjianGuru;
use App\Domain\Tenancy\Support\PengaturanBawaan;
use App\Support\TabelQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nilai kelas - wireframe-peran.html plate 07.
 *
 * =====================================================================
 * Angkanya DIBACA dari `exam_results`, tidak dihitung di sini
 * =====================================================================
 * `PenghitungHasil` yang mengisi baris itu setiap kali attempt ditutup atau
 * koreksi disimpan. Laman ini hanya membaca, dengan alasan yang sama seperti
 * plate 08 dan plate 28: rata-rata yang dihitung ulang di setiap layar
 * adalah rata-rata yang bisa berbeda dari rata-rata di layar sebelahnya.
 *
 * =====================================================================
 * Uraian yang belum dikoreksi TIDAK dihitung nol
 * =====================================================================
 * Kalimat di plate 07: "Nilai uraian yang belum dikoreksi ditandai 'belum'
 * dan tidak ikut dihitung sebagai nol." Yang diterjemahkan ke data: kolom
 * "Uraian" bernilai null saat `pending_essays > 0`, kolom "Nilai" memakai
 * persentase yang SUDAH dihitung `PenghitungHasil` (pembilangnya tidak
 * terisi, penyebutnya penuh), dan barisnya membawa `final: false`.
 *
 * Menampilkan 0 akan membuat rata-rata kelas turun karena pekerjaan Guru
 * yang belum selesai - bukan karena siswa mengerjakan lebih buruk.
 *
 * =====================================================================
 * KKM dibaca dari pengaturan sekolah
 * =====================================================================
 * `exam_results.lulus()` menuntutnya sebagai parameter, dan yang benar-benar
 * menentukan KKM adalah pengaturan SEKOLAH - bukan angka yang diketik di
 * laman ini. Satu angka, satu tempat.
 */
class NilaiController
{
    /**
     * Daftar ujian yang punya hasil.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('koreksi');

        $user = $request->user();
        $daftar = UjianGuru::untuk($user->id)->orderByDesc('id')->get();

        $ringkas = $this->ringkasBanyak($daftar->pluck('id')->all());
        $kkm = $this->kkm();

        $ujian = $daftar
            ->map(fn (Exam $satu) => ($ringkas[$satu->id] ?? []) + [
                'ujian_id' => $satu->id,
                'mapel' => $satu->subject?->name,
                'judul' => $satu->title,
                'event' => $satu->event?->name,
            ])
            ->filter(fn (array $b) => ($b['hasil'] ?? 0) > 0)
            ->values();

        return Inertia::render('Guru/Nilai/Index', [
            'ujian' => $ujian->all(),
            'kkm' => $kkm,
        ]);
    }

    /**
     * Nilai satu ujian - plate 07.
     */
    public function ujian(Request $request, Exam $ujian): Response
    {
        Gate::authorize('koreksi');

        $guru = $request->user();
        $ujian = UjianGuru::satu($guru->id, $ujian->id);

        $kkm = $this->kkm();

        $hasil = ExamResult::query()
            ->whereIn('exam_attempt_id', ExamAttempt::query()
                ->whereHas('penempatan', fn ($q) => $q->where('exam_id', $ujian->id))
                ->select('id'))
            // Dua baris pendukung per halaman: peserta (nama/NIS/kelas) dan
            // status attempt-nya. Daftar nilai tanpa nama adalah daftar yang
            // tidak bisa dipakai.
            // `penempatan` menunjuk `exam_participants`, dan tabel itu tidak
            // punya `exam_participant_id` - namanya `event_participant_id`,
            // karena yang terdaftar di situ adalah peserta EVENT yang
            // ditempatkan ke sebuah ujian.
            ->with([
                'pengerjaan:id,exam_participant_id,status',
                'pengerjaan.penempatan:id,event_participant_id',
                'pengerjaan.penempatan.pesertaEvent:id,student_id,class_id',
                'pengerjaan.penempatan.pesertaEvent.student:id,name,username',
                'pengerjaan.penempatan.pesertaEvent.kelas:id,name',
            ]);

        // Penyaring kelas dilakukan di kueri, bukan di PHP: `per_page` dihitung
        // sesudah penyaringan, dan menyaring sesudah paginasi menghasilkan
        // halaman berisi tiga baris dari lima puluh.
        $kelas = $request->query('kelas');

        if ($kelas !== null && $kelas !== '') {
            $hasil->whereHas('pengerjaan.penempatan.pesertaEvent', fn ($q) => $q->where('class_id', $kelas));
        }

        $halaman = TabelQuery::buat(
            $hasil,
            $request,
            cariDi: [],
            bolehDiurut: ['final_percentage'],
            urutBawaan: 'id',
            arahBawaan: 'asc',
        );

        $ringkas = $this->ringkasBanyak([$ujian->id])[$ujian->id];

        return Inertia::render('Guru/Nilai/Ujian', [
            'ujian' => [
                'id' => $ujian->id,
                'judul' => $ujian->title,
                'kode' => $ujian->code,
                'mapel' => $ujian->subject?->name,
                'event' => $ujian->event?->name,
            ],
            'kkm' => $kkm,
            'ringkas' => $ringkas,
            'baris' => $halaman->through(function (ExamResult $r) use ($kkm) {
                $peserta = $r->pengerjaan?->penempatan?->pesertaEvent;

                return [
                    'hasil_id' => $r->id,
                    'nis' => $peserta?->student?->username,
                    'nama' => $peserta?->student?->name,
                    'kelas' => $peserta?->kelas?->name,
                    'objektif' => (float) $r->automatic_score,
                    // Null saat uraian belum selesai dikoreksi - lihat catatan
                    // kepala kelas. Nol di sini berarti "dinilai, tidak dapat
                    // poin", dan itu kalimat yang berbeda.
                    'uraian' => $r->final() ? (float) $r->essay_score : null,
                    'nilai' => (float) $r->final_percentage,
                    'final' => $r->final(),
                    'sisa_uraian' => (int) $r->pending_essays,
                    'status' => $r->grading_status->value,
                    'status_label' => $r->grading_status->label(),
                    'status_nada' => $r->grading_status->nada(),
                    // `lulus` null saat belum final, dan laman yang
                    // menampilkannya sebagai "remedial" akan menuduh siswa
                    // atas pekerjaan Guru yang belum selesai.
                    'lulus' => $r->final() ? $r->lulus($kkm) : null,
                ];
            }),
            'filter' => ['kelas' => $kelas ?? ''],
            // Kelas yang memang peserta event ini - bukan seluruh kelas
            // sekolah. Penyaring yang menawarkan kelas yang tidak ikut event
            // akan selalu menghasilkan daftar kosong, dan Guru yang memilihnya
            // akan mengira nilainya hilang.
            'kelas' => SchoolClass::query()
                ->whereIn('id', EventClass::query()
                    ->where('event_id', $ujian->event_id)
                    ->select('class_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (SchoolClass $k) => ['id' => (string) $k->id, 'label' => $k->name])
                ->all(),
        ]);
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Ringkasan hasil untuk sekumpulan ujian - empat KPI plate 07.
     *
     * Rata-rata, tertinggi, dan jumlah remedial dihitung dari baris yang
     * SUDAH FINAL saja. Mamasukkan hasil yang masih menunggu koreksi ke
     * rata-rata berarti rata-rata kelas berubah setiap kali satu uraian
     * dikoreksi - dan Guru yang membacanya akan mengira kelasnya memburuk.
     *
     * @param  array<int, int>  $ujianIds
     * @return array<int, array{siapa: int, hasil: int, rata_rata: float|null, tertinggi: float|null, remedial: int, belum_final: int}>
     */
    private function ringkasBanyak(array $ujianIds): array
    {
        if ($ujianIds === []) {
            return [];
        }

        $kkm = $this->kkm();

        $seluruh = ExamResult::query()
            ->join('exam_attempts', 'exam_attempts.id', '=', 'exam_results.exam_attempt_id')
            ->join('exam_participants', 'exam_participants.id', '=', 'exam_attempts.exam_participant_id')
            ->join('exams', 'exams.id', '=', 'exam_participants.exam_id')
            ->whereIn('exams.id', $ujianIds)
            ->selectRaw('exams.id as ujian_id, exam_results.final_percentage, exam_results.pending_essays')
            ->get();

        $hasil = [];

        foreach ($ujianIds as $id) {
            $baris = $seluruh->where('ujian_id', $id);
            $final = $baris->where('pending_essays', 0);

            $hasil[$id] = [
                'hasil' => $baris->count(),
                'rata_rata' => $final->isNotEmpty() ? round((float) $final->avg('final_percentage'), 2) : null,
                'tertinggi' => $final->isNotEmpty() ? round((float) $final->max('final_percentage'), 2) : null,
                'remedial' => $final->filter(fn ($r) => (float) $r->final_percentage < $kkm)->count(),
                'belum_final' => $baris->count() - $final->count(),
            ];
        }

        return $hasil;
    }

    /** KKM sekolah, dengan batas akal yang sama seperti di pengaturannya. */
    private function kkm(): int
    {
        $nilai = (int) (auth()->user()?->school?->settings['kkm'] ?? PengaturanBawaan::NILAI['kkm']);

        return min(100, max(0, $nilai));
    }
}
