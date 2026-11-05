<?php

namespace App\Domain\Grading\Http\Controllers\Guru;

use App\Domain\Attendance\Models\StudentAnswer;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Grading\Models\EssayGrading;
use App\Domain\Grading\Services\PenilaiUraian;
use App\Domain\Grading\Support\UjianGuru;
use App\Support\TabelQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Koreksi uraian - wireframe-peran.html plate 06.
 *
 * =====================================================================
 * Antreannya adalah JAWABAN, bukan pengerjaan
 * =====================================================================
 * Plate 06 menulis di kepalanya: "Sudah 26 / Sisa 12 / Poin 8 / Rata-rata
 * 5,4 / Antrean 3". "Sisa" menghitung JAWABAN uraian yang belum punya baris
 * koreksi, bukan siswa yang belum selesai dikoreksi - karena satu siswa bisa
 * meninggalkan dua uraian yang belum dinilai, dan angka "sisa 1" untuk siswa
 * itu menyembunyikan pekerjaan yang sebenarnya dua kali.
 *
 * ---------------------------------------------------------------------
 * Antrean yang bukan milik Guru ini TIDAK PERNAH terlihat
 * ---------------------------------------------------------------------
 * Penyaringnya bukan di layar: ujiannya sudah disaring `UjianGuru` sejak
 * kueri pertama. Guru Matematika yang mengetik id ujian Fisika di URL
 * menerima 404 - bukan daftar kosong, yang akan ia laporkan sebagai bug.
 *
 * =====================================================================
 * Rubrik dibaca dari SNAPSHOT, bukan dari bank soal
 * =====================================================================
 * Plate 06: "rubrik ditulis Admin per ujian, Guru hanya mencentang". Yang
 * dibaca di sini adalah `exam_questions.rubrik_json` - bentuk yang dibekukan
 * saat ujian dibuat. Bank soal aslinya boleh berubah kapan saja; rubrik yang
 * dilihat Guru saat mengoreksi harus rubrik yang berlaku saat soalnya
 * diujikan, atau koreksi atas ujian bulan lalu memakai rubrik yang ditulis
 * kemarin.
 */
class KoreksiController
{
    public function __construct(private readonly PenilaiUraian $penilai) {}

    /**
     * Daftar ujian yang punya antrean koreksi.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('koreksi');

        $user = $request->user();

        $daftar = UjianGuru::untuk($user->id)->orderByDesc('id')->get();

        // Ringkasannya dihitung untuk SELURUH ujian Guru ini dalam tigas
        // kueri, bukan tiga kueri per ujian. Dengan dua belas ujian, versi
        // naif menembak tiga puluh enam kueri untuk satu daftar - dan daftar
        // ini dibuka setiap kali Guru membuka menu koreksi.
        $ringkas = $this->ringkasBanyak($daftar->pluck('id')->all());

        $ujian = $daftar
            ->map(fn (Exam $satu) => $ringkas[$satu->id] + [
                'ujian_id' => $satu->id,
                'mapel' => $satu->subject?->name,
                'judul' => $satu->title,
                'event' => $satu->event?->name,
            ])
            // Ujian tanpa satu pun butir uraian tidak punya antrean, dan
            // barisnya di daftar "koreksi uraian" hanya akan menjadi satu
            // klik yang berakhir di laman kosong.
            ->filter(fn (array $b) => $b['total'] > 0)
            ->values();

        return Inertia::render('Guru/Koreksi/Index', [
            'ujian' => $ujian->all(),
            'jumlah' => [
                'sisa' => (int) $ujian->sum('sisa'),
                'sudah' => (int) $ujian->sum('sudah'),
                'total' => (int) $ujian->sum('total'),
                'poin' => (float) round((float) $ujian->sum('poin_diberikan'), 2),
            ],
        ]);
    }

    /**
     * Antrean satu ujian - plate 06, tanpa panel rubrik.
     */
    public function ujian(Request $request, Exam $ujian): Response
    {
        Gate::authorize('koreksi');

        $guru = $request->user();

        // `satu()` mengulang kueri izin yang sama, bukan memeriksa peran dua
        // kali: yang ia pastikan adalah "ujian ini urusan Guru ini", dan
        // itulah satu-satunya aturan yang berlaku di ketiga laman.
        $ujian = UjianGuru::satu($guru->id, $ujian->id);

        $halaman = TabelQuery::buat(
            $this->jawabanUraian($ujian),
            $request,
            cariDi: [],
            bolehDiurut: ['id'],
            // Tanpa penyaring: saringan "belum dinilai" menuntut kolom yang
            // tidak ada di `student_answers`, dan TabelQuery menyaring dengan
            // kesamaan kolom. Yang benar-benar menyaring status adalah
            // `whereNotIn` pada daftar antrean di `berikutnya()` - satu
            // tempat, sama dengan yang dipakai tombol "lanjut".
            urutBawaan: 'id',
            arahBawaan: 'asc',
        );

        // Satu kueri untuk seluruh koreksi di halaman ini, bukan satu per
        // baris. Lima puluh baris berarti lima puluh kueri, dan itu terjadi
        // pada setiap penyegaran selama Guru mengoreksi satu ujian.
        $koreksi = EssayGrading::query()
            ->whereIn('student_answer_id', $halaman->pluck('id')->all())
            ->get()
            ->keyBy('student_answer_id');

        return Inertia::render('Guru/Koreksi/Ujian', [
            'ujian' => $this->identitas($ujian),
            'ringkas' => $this->ringkas($ujian),
            'baris' => $halaman->through(fn (StudentAnswer $jawaban) => $this->baris($jawaban, $koreksi->get($jawaban->id))),
        ]);
    }

    /**
     * Satu jawaban: teks siswa + rubrik + kotak poin - panel kanan plate 06.
     */
    public function jawaban(Request $request, StudentAnswer $jawaban): Response
    {
        Gate::authorize('koreksi');

        $butir = $jawaban->butir;
        $attempt = $butir?->attempt;
        $ujian = $attempt?->penempatan?->exam;

        // 404 untuk jawaban yang bukan uraian maupun yang ujiannya bukan
        // urusan Guru ini. Satu jalur, bukan dua, karena keduanya berarti hal
        // yang sama dari sisi Guru: barisnya tidak ada.
        abort_if($butir === null || $attempt === null || $ujian === null, 404);

        $ujian = UjianGuru::satu($request->user()->id, $ujian->id);

        abort_unless($butir->soal?->perluKoreksiManual(), 404);

        $koreksi = EssayGrading::query()
            ->where('student_answer_id', $jawaban->id)
            ->first();

        $attempt->loadMissing('penempatan.pesertaEvent.student:id,name');

        return Inertia::render('Guru/Koreksi/Jawaban', [
            'ujian' => $this->identitas($ujian),
            'siswa' => [
                'nama' => $attempt->penempatan?->pesertaEvent?->student?->name,
                'nomor_soal' => $butir->display_order,
            ],
            'soal' => [
                'judul' => $butir->soal?->judul,
                'teks' => $butir->soal?->soal_json,
                'bobot' => $butir->soal?->bobotBerlaku(),
                // Rubrik dari SNAPSHOT - lihat catatan kepala kelas.
                'rubrik' => $butir->soal?->rubrik_json ?? [],
            ],
            'jawaban' => [
                'id' => $jawaban->id,
                'teks' => $jawaban->answer_json['teks'] ?? '',
                'terjawab' => $jawaban->terjawab(),
            ],
            'koreksi' => $koreksi === null ? null : [
                'score' => (float) $koreksi->score,
                'kriteria' => $koreksi->criteria_json ?? [],
                'feedback' => $koreksi->feedback,
                'dinilai_pada' => $koreksi->graded_at?->toIso8601String(),
            ],
            // Kunci koreksi berlaku per EVENT. Selama hasil event itu belum
            // dipublikasikan, Guru masih boleh menyunting; sesudahnya, tidak -
            // kecuali Admin membukanya kembali dengan alasan tercatat.
            'terkunci' => (bool) $ujian->event?->koreksiTerkunci(),
            // Antrean berikutnya, supaya Guru yang selesai mengoreksi satu
            // jawaban tidak perlu kembali ke daftar untuk mencari yang
            // berikutnya - plate 06 menomori antreannya "Antrean 3".
            'berikutnya' => $this->berikutnya($ujian, $jawaban),
        ]);
    }

    /**
     * Simpan satu koreksi.
     */
    public function simpan(Request $request, StudentAnswer $jawaban): RedirectResponse
    {
        Gate::authorize('koreksi');

        $butir = $jawaban->butir;
        $ujian = $butir?->attempt?->penempatan?->exam;

        abort_if($butir === null || $ujian === null, 404);

        UjianGuru::satu($request->user()->id, $ujian->id);

        $data = $request->validate([
            'kriteria' => ['array'],
            'kriteria.*.kriteria' => ['required', 'string', 'max:200'],
            'kriteria.*.poin' => ['required', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:'.PenilaiUraian::BATAS_FEEDBACK],
        ]);

        $this->penilai->koreksi(
            $jawaban,
            $request->user(),
            $data['kriteria'] ?? [],
            $data['feedback'] ?? null,
        );

        $berikutnya = $this->berikutnya($ujian, $jawaban);

        return $berikutnya === null
            ? redirect()
                ->route('guru.koreksi.ujian', $ujian->id)
                ->with('sukses', 'Seluruh uraian ujian ini sudah dikoreksi.')
            : redirect()
                ->route('guru.koreksi.jawaban', $berikutnya)
                ->with('sukses', 'Koreksi tersimpan. Lanjut ke jawaban berikutnya.');
    }

    /**
     * Batalkan satu koreksi - jawaban kembali "belum dinilai".
     */
    public function batalkan(Request $request, StudentAnswer $jawaban): RedirectResponse
    {
        Gate::authorize('koreksi');

        $ujian = $jawaban->butir?->attempt?->penempatan?->exam;

        abort_if($ujian === null, 404);

        UjianGuru::satu($request->user()->id, $ujian->id);

        $this->penilai->batalkan($jawaban, $request->user());

        return back()->with('sukses', 'Koreksi dibatalkan; jawaban kembali masuk antrean.');
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Jawaban uraian satu ujian, sebagai kueri.
     *
     * @return Builder<StudentAnswer>
     */
    private function jawabanUraian(Exam $ujian)
    {
        return StudentAnswer::query()
            ->whereHas('butir', fn ($q) => $q
                // Ujiannya disaring lewat SNAPSHOT soalnya, bukan lewat
                // `attempt_questions`: tabel itu menyimpan `exam_question_id`,
                // dan tidak punya `exam_id` sama sekali - menyaring `exam_id`
                // di sana bukan sekadar salah, ia galat SQL yang muncul hanya
                // saat Guru pertama kali mengoreksi.
                ->whereHas('soal', fn ($s) => $s
                    ->where('exam_id', $ujian->id)
                    ->where('jenis', 'uraian')))
            ->with([
                'butir:id,exam_attempt_id,exam_question_id,display_order',
                'butir.soal:id,exam_id,judul,jenis,bobot,rubrik_json',
                'butir.attempt:id,exam_participant_id,status',
                // `event_participant_id` WAJIB ikut terpilih: tanpa kolom itu,
                // relasi `pesertaEvent` di bawahnya menjadi null tanpa satu pun
                // galat - nama siswa hilang dari antrean koreksi.
                'butir.attempt.penempatan:id,event_participant_id',
                'butir.attempt.penempatan.pesertaEvent:id,student_id',
                'butir.attempt.penempatan.pesertaEvent.student:id,name',
            ])
            ->orderBy('id');
    }

    /** Satu baris antrean. */
    private function baris(StudentAnswer $jawaban, ?EssayGrading $koreksi): array
    {
        return [
            'id' => $jawaban->id,
            'nomor' => $jawaban->butir?->display_order,
            'siswa' => $jawaban->butir?->attempt?->penempatan?->pesertaEvent?->student?->name,
            'judul' => $jawaban->butir?->soal?->judul,
            'cuplikan' => $this->cuplikan($jawaban),
            'sudah_dinilai' => $koreksi !== null,
            'skor' => $koreksi === null ? null : (float) $koreksi->score,
            'bobot' => (float) ($jawaban->butir?->soal?->bobotBerlaku() ?? 0),
        ];
    }

    /** Cuplikan jawaban siswa, dipotong supaya tabelnya tidak melar. */
    private function cuplikan(StudentAnswer $jawaban): string
    {
        $teks = trim((string) ($jawaban->answer_json['teks'] ?? ''));

        return mb_strlen($teks) > 120 ? mb_substr($teks, 0, 120, 'UTF-8').'…' : $teks;
    }

    /** Ringkasan satu ujian untuk kepala plate 06. */
    private function ringkas(Exam $ujian): array
    {
        return $this->ringkasBanyak([$ujian->id])[$ujian->id];
    }

    /**
     * Ringkasan antrean untuk sekumpulan ujian, dalam tiga kueri total.
     *
     * ---------------------------------------------------------------
     * Mengapa bukan per ujian
     * ---------------------------------------------------------------
     * Versi naif menembak tiga kueri untuk setiap ujian: jumlah jawaban
     * uraian, jumlah koreksi, dan jumlah poinnya. Dua belas ujian berarti
     * tiga puluh enam kueri hanya untuk membuka menu koreksi - dan itu
     * terjadi pada setiap kunjungan, oleh setiap Guru, setiap hari ujian.
     *
     * Tiga kueri di bawah menghitung SEMUA ujian sekaligus dengan
     * `groupBy`, lalu dipetakan kembali. Bentuk angkanya sama; yang berubah
     * hanya berapa kali basis data ditanya.
     *
     * @param  array<int, int>  $ujianIds
     * @return array<int, array{total: int, sudah: int, sisa: int, poin_diberikan: float, rata_rata: float|null, maksimal: float}>
     */
    private function ringkasBanyak(array $ujianIds): array
    {
        if ($ujianIds === []) {
            return [];
        }

        // Jawaban uraian per ujian. `exam_questions.jenis` adalah kolom yang
        // sudah dibekukan, sehingga `uraian` di sini adalah jenis yang
        // BERLAKU saat ujian itu diujikan - bukan jenis soal aslinya sekarang.
        $total = StudentAnswer::query()
            ->join('attempt_questions', 'attempt_questions.id', '=', 'student_answers.attempt_question_id')
            ->join('exam_questions', 'exam_questions.id', '=', 'attempt_questions.exam_question_id')
            ->whereIn('exam_questions.exam_id', $ujianIds)
            ->where('exam_questions.jenis', 'uraian')
            ->groupBy('exam_questions.exam_id')
            ->selectRaw('exam_questions.exam_id as ujian_id, count(*) as jumlah')
            ->pluck('jumlah', 'ujian_id');

        // Koreksi yang sudah tersimpan, per ujian.
        $sudah = EssayGrading::query()
            ->join('student_answers', 'student_answers.id', '=', 'essay_gradings.student_answer_id')
            ->join('attempt_questions', 'attempt_questions.id', '=', 'student_answers.attempt_question_id')
            ->join('exam_questions', 'exam_questions.id', '=', 'attempt_questions.exam_question_id')
            ->whereIn('exam_questions.exam_id', $ujianIds)
            ->where('exam_questions.jenis', 'uraian')
            ->groupBy('exam_questions.exam_id')
            ->selectRaw('exam_questions.exam_id as ujian_id, count(*) as jumlah, sum(essay_gradings.score) as poin')
            ->get()
            ->keyBy('ujian_id');

        // Bobot maksimal butir uraian per ujian - penyebut rata-rata.
        $maksimal = ExamQuestion::query()
            ->whereIn('exam_id', $ujianIds)
            ->where('jenis', 'uraian')
            ->groupBy('exam_id')
            ->selectRaw('exam_id, sum(bobot) as jumlah')
            ->pluck('jumlah', 'exam_id');

        $hasil = [];

        foreach ($ujianIds as $id) {
            $jml = (int) ($total[$id] ?? 0);
            $dinilai = (int) ($sudah[$id]->jumlah ?? 0);
            $poin = (float) ($sudah[$id]->poin ?? 0);

            $hasil[$id] = [
                'total' => $jml,
                'sudah' => $dinilai,
                'sisa' => max(0, $jml - $dinilai),
                'poin_diberikan' => round($poin, 2),
                'rata_rata' => $dinilai > 0 ? round($poin / $dinilai, 2) : null,
                'maksimal' => (float) ($maksimal[$id] ?? 0),
            ];
        }

        return $hasil;
    }

    /** Identitas ujian untuk kepala laman. */
    private function identitas(Exam $ujian): array
    {
        return [
            'id' => $ujian->id,
            'judul' => $ujian->title,
            'kode' => $ujian->code,
            'mapel' => $ujian->subject?->name,
            'event' => $ujian->event?->name,
        ];
    }

    /**
     * Jawaban uraian berikutnya yang belum dikoreksi.
     *
     * Urutannya `student_answer.id`, sama dengan urutan antreannya di daftar.
     * "Berikutnya" yang berbeda dari daftar akan membuat Guru mengoreksi
     * jawaban yang tidak ia lihat di mana pun.
     */
    private function berikutnya(Exam $ujian, StudentAnswer $sekarang): ?int
    {
        $sudah = EssayGrading::query()->select('student_answer_id');

        // Ujiannya disaring lewat snapshot soal - lihat catatan di
        // `jawabanUraian()`. Kedua kueri di bawah memakai penyaring yang sama
        // persis, karena "berikutnya" yang memakai cakupan berbeda dari
        // daftarnya akan mengarahkan Guru ke jawaban ujian lain.
        $dalamUjian = fn ($q) => $q->whereHas('soal', fn ($s) => $s
            ->where('exam_id', $ujian->id)
            ->where('jenis', 'uraian'));

        return StudentAnswer::query()
            ->whereHas('butir', $dalamUjian)
            ->whereNotIn('id', $sudah)
            ->where('id', '>', $sekarang->id)
            ->orderBy('id')
            ->value('id')
            // Bila tidak ada yang lebih besar, mulai dari awal antrean -
            // jawaban yang dilewati Guru tetap bisa dikoreksi tanpa kembali
            // ke daftar.
            ?? StudentAnswer::query()
                ->whereHas('butir', $dalamUjian)
                ->whereNotIn('id', $sudah)
                ->orderBy('id')
                ->value('id');
    }
}
