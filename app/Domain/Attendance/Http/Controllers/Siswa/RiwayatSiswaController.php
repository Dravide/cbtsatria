<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Attendance\Contracts\SumberNilai;
use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Support\RingkasJawaban;
use App\Domain\Exam\Models\ExamParticipant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Riwayat jawaban satu ujian - "soal mana yang saya jawab apa".
 *
 * =====================================================================
 * Laman yang ditulis tetapi tidak pernah ada, dan datanya sudah menunggu
 * =====================================================================
 * `NilaiSiswaController` menulis sendiri bahwa `show_explanations` tidak
 * dibaca di sana karena "yang memakainya kelak adalah laman riwayat
 * jawaban". Sampai sekarang laman itu tidak ada: anak yang ingin tahu soal
 * nomor 7 dijawabnya apa hanya bisa menebak dari angka akhir.
 *
 * Yang dibutuhkan laman ini sudah tersimpan seluruhnya -
 * `attempt_questions` (urutan), `student_answers` (isinya, benar/salah,
 * skornya), `exam_questions` (snapshot soal, kunci, dan pembahasan), dan
 * `essay_gradings` lewat `student_answers.score_awarded`. Tidak ada satu
 * pun kueri yang mengarang data.
 *
 * =====================================================================
 * Tiga pintu, dan masing-masing menutup sesuatu yang berbeda
 * =====================================================================
 *
 *   1. PUBLIKASI (`results_published_at`). Sebelum hasilnya diterbitkan,
 *      laman ini menjawab 404 - bukan laman kosong. Membuka riwayat berarti
 *      membuka kunci jawaban satu angkatan lewat jalan belakang: anak yang
 *      sudah selesai bisa membacakan jawabannya ke anak yang belum
 *      mengerjakan di sesi berikutnya. Aturan yang sama dengan nilai.
 *
 *   2. `show_objectives` - menjaga KUNCI JAWABAN. Null berarti tidak
 *      dikirim sama sekali, bukan dikirim lalu disembunyikan laman:
 *      peramban yang menerimanya sudah membocorkannya.
 *
 *   3. `show_explanations` - menjaga PEMBAHASAN. Bawaannya false, dan
 *      alasannya tertulis di migrasi 0018: pembahasan pilihan ganda sama
 *      dengan kunci ditambah alasannya.
 *
 * Kunci dan pembahasan TIDAK disatukan di satu prop. `show_objectives`
 * boleh menyala sementara `show_explanations` padam, dan prop gabungan akan
 * memaksa keduanya menyala bersama - persis yang tidak diizinkan plate 21.
 *
 * =====================================================================
 * Uraian: rubrik tetap tidak pernah keluar
 * =====================================================================
 * Uraian tidak punya "kunci" yang bisa ditampilkan; ia punya rubrik, dan
 * rubrik memuat kata kunci yang dicari korektor. Yang dikirim untuk uraian
 * adalah JAWABAN ANAK dan SKOR YANG DIBERI Guru - bukan rubriknya. Guru
 * yang ingin menjelaskan rubrik menjelaskannya di kelas, dan itu keputusan
 * yang sudah diambil `ExamQuestion::untukSiswa()` sejak Fase 4.
 */
class RiwayatSiswaController extends SiswaDasar
{
    public function __construct(
        private readonly SumberNilai $nilai,
        private readonly RingkasJawaban $ringkas,
    ) {}

    public function tampilkan(ExamParticipant $peserta): Response
    {
        $peserta = $this->milikSaya($peserta);

        $attempt = $this->attemptTerakhir($peserta);

        abort_if($attempt === null, 404);

        $peserta->loadMissing(['exam:id,title,subject_id,event_id', 'exam.subject:id,name', 'exam.event:id,name,results_published_at,show_objectives,show_explanations']);
        $event = $peserta->exam?->event;

        // 1 - publikasi. 404, dan bukan laman kosong: "tidak ada" adalah
        // jawaban yang sama benarnya bagi anak yang belum berhak membaca
        // dan bagi anak yang menebak alamat.
        abort_unless($event?->hasilTerbit() === true, 404);

        // Attempt yang masih berjalan atau terkunci belum punya nilai dan
        // belum seharusnya dibaca - riwayat adalah laporan SESUDAH selesai.
        abort_unless(
            in_array($attempt->status, [
                ExamAttempt::STATUS_DIKIRIM,
                ExamAttempt::STATUS_KEDALUWARSA,
            ], true),
            404,
        );

        $hasil = $this->nilai->perAttempt([$attempt->id])[$attempt->id] ?? null;

        // Hasil yang belum terbit tidak ada di peta `SumberNilai` - lihat
        // SumberNilaiService. Tanpa barisnya, rincian per butir akan
        // menampilkan jawaban tanpa angka, dan itu laporan setengah jadi.
        abort_if($hasil === null, 404);

        $bukaKunci = (bool) ($event?->show_objectives ?? true);
        $bukaPembahasan = (bool) ($event?->show_explanations ?? false);

        $butir = $this->butir($attempt, $bukaKunci, $bukaPembahasan);

        return Inertia::render('Siswa/Riwayat', [
            'ujian' => [
                'judul' => $peserta->exam?->title,
                'mapel' => $peserta->exam?->subject?->name,
                'event' => $event?->name,
                'dikumpulkan' => $attempt->submitted_at?->toIso8601String(),
                'dikumpulkan_label' => $attempt->submitted_at?->format('d M Y · H:i'),
                'kedaluwarsa' => $attempt->status === ExamAttempt::STATUS_KEDALUWARSA,
                'status_label' => $attempt->labelStatus(),
            ],

            'hasil' => [
                'persen' => round($hasil['persen'], 2),
                'final' => $hasil['final'],
                'sisa_uraian' => $hasil['sisa_uraian'],
            ],

            // Dua penanda, bukan satu. Laman memakai `boleh_kunci` untuk
            // memutuskan apakah kolom "Kunci" digambar sama sekali, dan
            // `boleh_pembahasan` untuk blok penjelasan di bawah butirnya.
            'boleh_kunci' => $bukaKunci,
            'boleh_pembahasan' => $bukaPembahasan,

            'butir' => $butir,

            // Jumlah benar/salah dihitung dari baris yang benar-benar
            // dinilai - `is_correct` null pada uraian berarti belum
            // dikoreksi, dan menghitungnya sebagai salah akan membuat anak
            // membaca "3 benar dari 5" padahal guru belum memeriksa dua
            // di antaranya.
            //
            // Dihitung dari baris yang SAMA dengan yang dikirim, bukan dari
            // kueri kedua: dua kueri atas tabel yang sama adalah dua jawaban
            // yang bisa berbeda.
            'ringkasan' => $this->ringkasan($butir),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function butir(ExamAttempt $attempt, bool $bukaKunci, bool $bukaPembahasan): array
    {
        $baris = AttemptQuestion::query()
            ->where('exam_attempt_id', $attempt->id)
            ->with(['soal', 'jawaban'])
            ->orderBy('display_order')
            ->get();

        return $baris->map(function (AttemptQuestion $b) use ($bukaKunci, $bukaPembahasan) {
            $soal = $b->soal;
            $jawaban = $b->jawaban;

            if ($soal === null) {
                // Snapshot yang hilang tetap dikirim sebagai butir kosong,
                // bukan dibuang - nomor soal adalah hal yang disebut
                // pengawas di ruang ujian, dan membuangnya menggeser seluruh
                // nomor sesudahnya. Sama dengan KerjakanSiswaController.
                return [
                    'id' => $b->id,
                    'nomor' => $b->display_order,
                    'jenis_label' => 'Soal tidak tersedia',
                    'judul' => null,
                    'soal' => null,
                    'opsi' => [],
                    'jawaban' => null,
                    'kunci' => null,
                    'pembahasan' => null,
                    'benar' => null,
                    'skor' => null,
                    'bobot' => 0.0,
                    'keadaan' => 'belum',
                ];
            }

            $jenis = $soal->jenis;

            return [
                'id' => $b->id,
                // Nomor YANG DILIHAT anak (display_order), bukan `position`
                // snapshotnya - lihat AttemptQuestion.
                'nomor' => $b->display_order,
                'jenis' => $jenis->value,
                'jenis_label' => $jenis->label(),
                'judul' => $soal->judul,
                'soal' => $this->teks($soal->soal_json),
                'opsi' => $this->opsi($soal->opsi_json),

                'jawaban' => $jawaban === null
                    ? null
                    : [
                        ...$this->ringkas->jawaban($jenis, $jawaban->answer_json, $soal->butir_json),
                        'ditandai' => (bool) $jawaban->ditandai,
                    ],

                // Dua nilai terpisah, dan masing-masing bisa null sendiri.
                // Kunci jawaban dan pembahasan adalah dua izin yang berbeda
                // di plate 21.
                'kunci' => $bukaKunci
                    ? $this->ringkas->kunci($jenis, $soal->kunci_json, $soal->butir_json)
                    : null,
                'pembahasan' => $bukaPembahasan ? $this->teks($soal->pembahasan_json) : null,

                // `benar` null berarti BELUM DINILAI (uraian), dan laman
                // wajib membedakannya dari "salah" - lihat StudentAnswer.
                'benar' => $jawaban?->is_correct,
                'skor' => $jawaban?->score_awarded === null
                    ? null
                    : (float) $jawaban->score_awarded,
                'bobot' => $soal->bobotBerlaku(),

                // EMPAT keadaan, bukan tiga - lihat `keadaan()`.
                'keadaan' => $this->keadaan($jawaban?->is_correct, $jawaban?->score_awarded),
            ];
        })->all();
    }

    /**
     * EMPAT keadaan butir, dan yang keempat tidak boleh dilipat ke yang lain.
     *
     * `is_correct` tidak pernah diisi untuk uraian - lihat PenilaiUraian:
     * kolom itu boolean, dan jawaban uraian tidak benar atau salah, ia
     * bernilai sebagian. Akibatnya `is_correct === null` berarti DUA hal yang
     * berbeda: "uraian yang sudah dikoreksi" dan "butir yang belum dinilai
     * siapa pun". Membedakannya: uraian yang sudah dikoreksi punya
     * `score_awarded`.
     *
     * Menyatukan keduanya membuat anak membaca "belum dinilai" pada uraian
     * yang sudah dikoreksi gurunya - dan anak yang nilainya sudah final
     * menagih pekerjaan yang sudah selesai.
     */
    private function keadaan(?bool $benar, mixed $skor): string
    {
        if ($benar === true) {
            return 'benar';
        }

        if ($benar === false) {
            return 'salah';
        }

        return $skor === null ? 'belum' : 'dinilai';
    }

    /**
     * Tiga angka, dihitung dari baris yang SUDAH dirakit.
     *
     * Kueri kedua atas tabel yang sama tidak dipakai: dua pembacaan atas
     * `student_answers` adalah dua jawaban yang bisa berbeda, dan laman yang
     * menyebut "3 benar" di atas daftar berisi empat centang benar adalah
     * laman yang tidak bisa dipercaya.
     *
     * @param  array<int, array<string, mixed>>  $butir
     * @return array{benar: int, salah: int, dinilai: int, belum: int}
     */
    private function ringkasan(array $butir): array
    {
        $keadaan = array_map(fn (array $b) => $b['keadaan'], $butir);

        return [
            'benar' => count(array_filter($keadaan, fn ($k) => $k === 'benar')),
            'salah' => count(array_filter($keadaan, fn ($k) => $k === 'salah')),
            // Uraian yang sudah dikoreksi: nilainya ada, benar/salahnya
            // memang tidak pernah ada.
            'dinilai' => count(array_filter($keadaan, fn ($k) => $k === 'dinilai')),
            // Butir yang tidak dijawab, dan uraian yang belum dikoreksi.
            'belum' => count(array_filter($keadaan, fn ($k) => $k === 'belum')),
        ];
    }

    /**
     * Isi `*_json` menjadi teks.
     *
     * `prompt_json` dan `explanation_json` berbentuk `['teks' => '...']` -
     * lihat Question. Membacanya sebagai string menghasilkan null untuk
     * setiap soal, dan laman yang kosong tidak memperlihatkan kesalahannya.
     *
     * @param  array<string, mixed>|null  $isi
     */
    private function teks(?array $isi): ?string
    {
        $teks = $isi['teks'] ?? null;

        return is_string($teks) && trim($teks) !== '' ? trim($teks) : null;
    }

    /**
     * Opsi snapshot sebagai peta kunci-ke-teks.
     *
     * Peta, bukan daftar: jawaban anak menyimpan HURUF opsi, dan laman harus
     * bisa menulis "Anda menjawab B" tanpa mencari opsi mana yang berhuruf B
     * - pencarian itu di peramban adalah satu tempat lagi yang bisa salah.
     *
     * @param  array<int, array<string, mixed>>|null  $opsi
     * @return array<string, string>
     */
    private function opsi(?array $opsi): array
    {
        if (! is_array($opsi)) {
            return [];
        }

        $peta = [];

        foreach ($opsi as $satu) {
            if (! is_array($satu) || ! isset($satu['kunci'])) {
                continue;
            }

            $isi = $satu['teks'] ?? null;

            if (is_array($isi)) {
                $isi = $isi['teks'] ?? null;
            }

            $peta[(string) $satu['kunci']] = is_string($isi) ? trim($isi) : '';
        }

        return $peta;
    }
}
