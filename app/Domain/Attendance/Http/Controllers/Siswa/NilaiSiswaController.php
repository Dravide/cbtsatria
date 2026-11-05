<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Attendance\Contracts\SumberNilai;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Tenancy\Support\PengaturanBawaan;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nilai saya - wireframe plate 28.
 *
 * =====================================================================
 * Angkanya datang dari `exam_results`, dan laman ini tidak menghitung apa pun
 * =====================================================================
 * Sampai Fase 7 laman ini menggambar KEADAAN, karena tabel nilainya belum
 * ada. Sekarang tabelnya ada (`exam_results`, diisi `PenghitungHasil` setiap
 * kali attempt ditutup), dan laman ini hanya MEMBACA - alasan yang sama
 * dengan plate 07 dan plate 21: empat layar yang menghitung sendiri adalah
 * empat angka yang berbeda untuk satu siswa.
 *
 * ---------------------------------------------------------------------
 * Yang BELUM terbit TIDAK ditampilkan, dan itu diputuskan di modul Grading
 * ---------------------------------------------------------------------
 * Penyaringan publikasi hidup di `SumberNilaiService`, bukan di sini. Bukan
 * kerapian: controller ini milik modul Attendance, dan `published_at` adalah
 * kolom milik Grading. Baris yang belum terbit tidak muncul di peta yang
 * dikembalikan, sehingga kebocoran nilai hanya mungkin terjadi bila seseorang
 * menambahkan kolom yang tidak ada - bukan karena satu `if` terlupa.
 *
 * Sebelum publikasi, laman tetap MENJAWAB pertanyaannya: ujian mana yang
 * sudah ia kerjakan, dan bahwa nilainya belum dibuka. Anak yang membuka laman
 * ini berulang kali bertanya "sudah keluar belum", dan jawaban "belum" adalah
 * jawaban yang benar.
 *
 * ---------------------------------------------------------------------
 * `show_objectives` mengatur RINCIAN, bukan boleh-tidaknya angka
 * ---------------------------------------------------------------------
 * Plate 21 punya dua centang: "Tampilkan nilai objektif ke siswa" dan
 * "Tampilkan pembahasan soal". Yang pertama membuka kolom "Obj."; angkanya
 * sendiri sudah dibuka publikasi. Menyatukan keduanya berarti satu centang
 * menyembunyikan nilai akhir - dan itu bukan yang ditulis plate 21.
 *
 * Pembahasan soal (`show_explanations`) TIDAK dibaca di sini karena laman ini
 * tidak menampilkan pembahasan sama sekali; yang memakainya kelak adalah
 * laman riwayat jawaban. Membacanya di sini hanya akan menjadi prop yang
 * tidak pernah dipakai.
 *
 * ---------------------------------------------------------------------
 * KKM dibaca dari pengaturan sekolah, dan hanya untuk kalimatnya
 * ---------------------------------------------------------------------
 * Plate 28 menampilkan catatan "di bawah KKM 70". Angkanya dibaca dari
 * `PengaturanBawaan` supaya kalimat yang muncul tidak menyebut KKM yang
 * berbeda dari yang dipegang Guru - satu angka, satu tempat.
 */
class NilaiSiswaController extends SiswaDasar
{
    public function __construct(private readonly SumberNilai $nilai) {}

    public function index(Request $request): Response
    {
        $penempatan = ExamParticipant::query()
            ->where('student_id', $request->user()->id)
            ->with([
                'exam.subject:id,name',
                'exam.event:id,name,results_published_at,show_objectives',
                'pesertaEvent.kelas:id,name',
            ])
            ->get();

        $attempt = ExamAttempt::query()
            ->whereIn('exam_participant_id', $penempatan->pluck('id'))
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('exam_participant_id')
            ->map(fn ($baris) => $baris->last());

        // Satu kueri untuk seluruh nilai, bukan satu per baris: siswa dengan
        // dua belas ujian akan menembak dua belas kueri bila dipanggil di
        // dalam loop, dan laman ini dibuka ulang berkali-kali oleh setiap
        // siswa satu sekolah.
        $nilai = $this->nilai->perAttempt($attempt->pluck('id')->filter()->all());

        $baris = $penempatan
            ->map(fn (ExamParticipant $p) => $this->bentukBaris($p, $attempt->get($p->id), $nilai))
            ->filter()
            ->sortBy(fn (array $b) => [$b['tanggal'] ?? '9999', $b['mapel'] ?? ''])
            ->values();

        $kkm = $this->kkm();

        return Inertia::render('Siswa/Nilai', [
            'nama' => $request->user()->name,
            'kelas' => $penempatan->first()?->pesertaEvent?->kelas?->name,
            'kkm' => $kkm,
            'daftar' => $baris->all(),

            // Bukan lagi "selalu false": prop ini kini benar-benar menjawab
            // apakah ada satu pun baris yang boleh dibaca. Bentuk propnya
            // TIDAK berubah - laman yang mengubah bentuknya saat nilai mulai
            // ada adalah laman yang tidak pernah diuji dalam bentuk yang
            // benar-benar dipakai.
            'ada_nilai' => $baris->contains(fn (array $b) => $b['nilai'] !== null),

            // Ujian yang sudah ia kerjakan tetapi belum dibuka hasilnya.
            // Dipisah supaya laman bisa mengatakan "nilai belum dibuka"
            // tanpa menebaknya dari `nilai === null` - dua sebab yang
            // berbeda (belum terbit, dan belum dikoreksi) tidak boleh
            // tampil sebagai kalimat yang sama.
            'menunggu_terbit' => $baris->contains(fn (array $b) => $b['nilai'] === null),

            // Kalimat remedial di plate 28 menyebut KKM dan menjadwalkan
            // remedial. Jadwalnya ditetapkan Guru, dan laman ini tidak boleh
            // mengarang tanggal - karena itu yang dikirim hanyalah KKM dan
            // daftar mapel di bawahnya.
            'remedial' => $baris
                ->filter(fn (array $b) => $b['nilai'] !== null && $b['nilai'] < $kkm)
                ->map(fn (array $b) => ['mapel' => $b['mapel'], 'nilai' => $b['nilai']])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Satu baris nilai, atau null bila ujiannya belum pernah dikerjakan.
     *
     * Ujian yang belum dikerjakan tidak punya baris di daftar nilai - bukan
     * karena disembunyikan, melainkan karena "nilai" atas ujian yang belum
     * berlangsung bukan pertanyaan yang masuk akal.
     *
     * @param  array<int, array<string, mixed>>  $nilai
     * @return array<string, mixed>|null
     */
    private function bentukBaris(ExamParticipant $p, ?ExamAttempt $attempt, array $nilai): ?array
    {
        if ($attempt === null) {
            return null;
        }

        $hasil = $nilai[$attempt->id] ?? null;
        $event = $p->exam?->event;

        // Objektif hanya dirinci bila Admin mencentangnya di plate 21.
        // Bawaannya true (lihat migrasi 0008_..._000018), dan NULL pada event
        // lama diperlakukan sebagai true dengan alasan yang sama.
        $tampilkanObjektif = (bool) ($event?->show_objectives ?? true);

        return [
            'peserta_id' => $p->id,
            'mapel' => $p->exam?->subject?->name,
            'ujian' => $p->exam?->title,
            'event' => $event?->name,
            'tanggal' => $attempt->submitted_at?->toIso8601String(),
            'tanggal_label' => $attempt->submitted_at?->format('d M'),
            'status' => $attempt->status,
            'status_label' => $attempt->labelStatus(),

            // `dipublikasikan` berarti hasil eventnya sudah dibuka, BUKAN
            // bahwa angkanya ada. Dua hal berbeda, dan laman yang
            // menyatukannya akan menampilkan "dipublikasikan" di atas tabel
            // kosong.
            'dipublikasikan' => $hasil !== null,

            // Null, dan bukan 0, saat hasilnya belum terbit. Baris berisi nol
            // di bawah judul "Nilai" terbaca sebagai nilai yang benar-benar
            // nol.
            'nilai' => $hasil === null ? null : round($hasil['persen'], 2),
            'objektif' => $hasil === null || ! $tampilkanObjektif ? null : round($hasil['otomatis'], 2),
            'uraian' => $hasil === null ? null : round($hasil['uraian'], 2),

            // Nilai yang masih menunggu koreksi uraian BUKAN nilai akhir.
            // Plate 07 menandainya "belum", dan laman siswa memakai tanda
            // yang sama - siswa yang membaca angka sementara sebagai nilai
            // akhir akan menagih Guru atas angka yang masih berubah.
            'final' => $hasil !== null && $hasil['final'],
            'sisa_uraian' => $hasil['sisa_uraian'] ?? 0,

            // `lulus` null saat belum final: siswa dengan uraian belum
            // dikoreksi memang belum bisa dinyatakan lulus, dan menyebutnya
            // "remedial" adalah tuduhan atas nilai yang belum selesai.
            'lulus' => $hasil === null || ! $hasil['final']
                ? null
                : $hasil['persen'] >= $this->kkm(),
        ];
    }

    /** KKM sekolah, dengan batas akal yang sama seperti di pengaturannya. */
    private function kkm(): int
    {
        $nilai = (int) (auth()->user()?->school?->settings['kkm'] ?? PengaturanBawaan::NILAI['kkm']);

        return min(100, max(0, $nilai));
    }
}
