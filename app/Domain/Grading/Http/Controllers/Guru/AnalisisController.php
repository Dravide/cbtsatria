<?php

namespace App\Domain\Grading\Http\Controllers\Guru;

use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Grading\Models\QuestionAnalysis;
use App\Domain\Grading\Services\DeteksiKemiripanKursi;
use App\Domain\Grading\Services\PenghitungAnalisis;
use App\Domain\Grading\Support\UjianGuru;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Analisis butir - wireframe-peran.html plate 08.
 *
 * =====================================================================
 * Tabelnya DIBACA dari `question_analyses`, bukan dihitung saat dibuka
 * =====================================================================
 * Daya beda menuntut SELURUH attempt diurutkan lalu dibagi dua kelompok, dan
 * itu pekerjaan yang tumbuh sebanding jumlah siswa. Menghitungnya di setiap
 * pembacaan laman berarti Guru yang menyegarkan halaman tiga kali membebani
 * basis data tiga kali untuk angka yang sama.
 *
 * Yang menghitung adalah `PenghitungAnalisis`, dipanggil tombol "Hitung
 * ulang" - dan sekali lagi otomatis sesudah satu koreksi uraian menyimpan,
 * karena peringkat kelas berubah saat satu nilai berubah.
 *
 * =====================================================================
 * "Putusan" adalah kalimat, bukan hanya angka
 * =====================================================================
 * Plate 08 menampilkan kolom "Putusan" berisi "periksa kunci", "terlalu
 * mudah", "sulit", "baik" - dan inbox di bawahnya menjelaskan mengapa.
 * `QuestionAnalysis::putuskan()` mengambil keputusan itu dari daya beda dan
 * tingkat kesukaran DENGAN URUTAN yang menentukan: daya beda negatif berarti
 * kuncinya kemungkinan salah, dan soal yang kuncinya salah tidak akan menjadi
 * "sulit" hanya karena sedikit yang menjawab benar.
 *
 * Urutan itu tidak ditulis ulang di sini. Laman ini membaca hasilnya.
 */
class AnalisisController
{
    public function __construct(
        private readonly PenghitungAnalisis $analisis,
        private readonly DeteksiKemiripanKursi $kemiripan,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('koreksi');

        $user = $request->user();
        $daftar = UjianGuru::untuk($user->id)->orderByDesc('id')->get();

        $jumlah = QuestionAnalysis::query()
            ->whereIn('exam_question_id', ExamQuestion::query()
                ->whereIn('exam_id', $daftar->pluck('id')->all())
                ->select('id'))
            ->selectRaw('count(*) as total')
            ->value('total');

        return Inertia::render('Guru/Analisis/Index', [
            'ujian' => $daftar->map(fn (Exam $satu) => [
                'ujian_id' => $satu->id,
                'mapel' => $satu->subject?->name,
                'judul' => $satu->title,
                'event' => $satu->event?->name,
                'butir' => $satu->soal()->count(),
                'dianalisis' => QuestionAnalysis::query()
                    ->whereIn('exam_question_id', $satu->soal()->select('id'))
                    ->count(),
            ])->all(),
            'ada_analisis' => $jumlah > 0,
        ]);
    }

    public function ujian(Request $request, Exam $ujian): Response
    {
        Gate::authorize('koreksi');

        $ujian = UjianGuru::satu($request->user()->id, $ujian->id);

        // Butir yang belum dianalisis TETAP ditampilkan - dengan `null` pada
        // setiap angkanya. Menyembunyikannya berarti Guru melihat empat dari
        // empat puluh baris dan menyimpulkan ujiannya cuma empat soal.
        $analisis = QuestionAnalysis::query()
            ->whereIn('exam_question_id', ExamQuestion::query()
                ->where('exam_id', $ujian->id)
                ->select('id'))
            ->get()
            ->keyBy('exam_question_id');

        $soalDaftar = ExamQuestion::query()
            ->where('exam_id', $ujian->id)
            ->orderBy('position')
            ->get();

        $telemetri = AttemptQuestion::query()
            ->whereIn('exam_question_id', $soalDaftar->pluck('id')->all())
            ->with('jawaban:id,attempt_question_id,answer_json')
            ->get()
            ->groupBy('exam_question_id');

        $baris = $soalDaftar->map(function (ExamQuestion $butir) use ($analisis, $telemetri) {
            $a = $analisis->get($butir->id);
            $rekaman = $telemetri->get($butir->id, collect());
            $durasiPositif = $rekaman->pluck('durasi_detik')->filter(fn ($d) => (int) $d > 0);
            $rataDurasi = $durasiPositif->isEmpty() ? null : (int) round($durasiPositif->avg());

            $opsiMati = [];
            if ($butir->jenis->value === 'pilihan_ganda' && is_array($butir->opsi_json) && $rekaman->isNotEmpty()) {
                $terpilih = $rekaman
                    ->map(fn (AttemptQuestion $aq) => $aq->jawaban?->answer_json['opsi'][0] ?? null)
                    ->filter()
                    ->flip();

                foreach ($butir->opsi_json as $o) {
                    $k = $o['kunci'] ?? $o['option_key'] ?? null;
                    if ($k !== null && ! $terpilih->has($k)) {
                        $opsiMati[] = (string) $k;
                    }
                }
            }

            $persen = $a === null ? null : (float) $a->percent_correct;
            $kuadran = null;
            if ($rataDurasi !== null && $persen !== null) {
                $cepat = $rataDurasi <= 45;
                $benarTinggi = $persen >= 60;
                $kuadran = match (true) {
                    $cepat && $benarTinggi => 'Cepat & Benar',
                    ! $cepat && ! $benarTinggi => 'Lama & Salah',
                    $cepat && ! $benarTinggi => 'Cepat & Salah',
                    default => 'Lama & Benar',
                };
            }

            return [
                'butir_id' => $butir->id,
                'nomor' => $butir->position,
                'judul' => $butir->judul,
                'jenis' => $butir->jenis->value,
                'jenis_label' => $butir->jenis->label(),
                'bobot' => $butir->bobotBerlaku(),
                'dijawab' => $a?->answered_count,
                'benar' => $a?->correct_count,
                'salah' => $a === null ? null : max(0, (int) $a->answered_count - (int) $a->correct_count),
                'persen_benar' => $persen,
                'daya_beda' => $a?->discrimination === null ? null : (float) $a->discrimination,
                'putusan' => $a?->verdict,
                'putusan_nada' => $a?->nada(),
                'rata_durasi_detik' => $rataDurasi,
                'kuadran' => $kuadran,
                'opsi_mati' => $opsiMati,
            ];
        });

        return Inertia::render('Guru/Analisis/Ujian', [
            'ujian' => [
                'id' => $ujian->id,
                'judul' => $ujian->title,
                'kode' => $ujian->code,
                'mapel' => $ujian->subject?->name,
                'event' => $ujian->event?->name,
            ],
            'baris' => $baris->all(),
            'ada_analisis' => $analisis->isNotEmpty(),
            'kemiripan_kursi' => $this->kemiripan->untukUjian($ujian->id),
            'ambang' => [
                'mudah' => QuestionAnalysis::MUDAH_DI_ATAS,
                'sulit' => QuestionAnalysis::SULIT_DI_BAWAH,
                'daya_beda' => QuestionAnalysis::DAYA_BEDA_MINIMAL,
                'minimal_peserta' => PenghitungAnalisis::MINIMAL_PESERTA,
            ],
        ]);
    }

    /**
     * Hitung ulang seluruh butir satu ujian.
     *
     * Tombol manual, bukan otomatis pada setiap penyimpanan jawaban: analisis
     * menuntut ujiannya SELESAI (peringkat kelas tidak berarti bila separuh
     * kelas masih mengerjakan), dan menghitungnya di tengah ujian berarti
     * angka yang berubah setiap kali satu siswa menekan Kumpulkan.
     */
    public function hitungUlang(Request $request, Exam $ujian): RedirectResponse
    {
        Gate::authorize('koreksi');

        $ujian = UjianGuru::satu($request->user()->id, $ujian->id);

        $jumlah = $this->analisis->hitungUlang($ujian->id);

        return back()->with('sukses', $jumlah === 0
            ? 'Ujian ini belum punya butir untuk dianalisis.'
            : "Analisis {$jumlah} butir dihitung ulang.");
    }
}
