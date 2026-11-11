<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jadwal ujian saya - wireframe plate 19.
 *
 * ---------------------------------------------------------------------
 * Ujian hari ini muncul sebagai KARTU, bukan baris pertama tabel
 * ---------------------------------------------------------------------
 * Plate 19 menulis alasannya sendiri: "Anak yang membuka laman ini sedang
 * mencari satu hal - apa yang harus saya kerjakan sekarang - dan jawabannya
 * tidak boleh memerlukan pembacaan tabel."
 *
 * Karena itu kartu utamanya dipisah dari daftarnya, dan yang menentukan
 * kartu mana yang muncul BUKAN tanggal hari ini di peramban siswa,
 * melainkan urutan penempatan: yang pertama kali ditemukan belum dikerjakan
 * dan jendelanya belum lewat.
 *
 * ---------------------------------------------------------------------
 * Tanggal hari ini TIDAK dipakai untuk memilih kartunya
 * ---------------------------------------------------------------------
 * Membandingkan `access_opens_at` dengan `now()` peramban berarti jam
 * perangkat anak menentukan apa yang ia lihat - dan ponsel yang jamnya
 * meleset satu hari akan menampilkan ujian yang salah sebagai "hari ini".
 * Yang dibandingkan adalah waktu SERVER, dan tanggalnya hanya dipakai untuk
 * mengelompokkan label.
 *
 * ---------------------------------------------------------------------
 * Yang dikirim hanya penempatan MILIK sendiri
 * ---------------------------------------------------------------------
 * `exam_participants` tidak punya global scope per-siswa, jadi penyaringnya
 * ditulis eksplisit di sini. Tanpa itu, seorang siswa menerima seluruh
 * jadwal ujian sekolahnya - dan lewat `peserta_id` yang dikirim balik, ia
 * bisa membuka gerbang anak lain.
 */
class JadwalSiswaController extends SiswaDasar
{
    public function index(Request $request): Response
    {
        $penempatan = ExamParticipant::query()
            ->where('student_id', $request->user()->id)
            ->with([
                'exam.subject:id,name',
                'exam.event:id,name',
                'session',
                'room.eventRoom.room:id,code,name,location',
            ])
            ->get();

        $attempt = ExamAttempt::query()
            ->whereIn('exam_participant_id', $penempatan->pluck('id'))
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('exam_participant_id')
            ->map(fn ($baris) => $baris->last());

        $sekarang = now();

        $baris = $penempatan
            ->map(fn (ExamParticipant $p) => $this->bentukBaris($p, $attempt->get($p->id), $sekarang))
            // Urutan jadwal, bukan urutan id: yang dibaca anak adalah
            // "setelah ini apa", dan id tidak menjawabnya.
            ->sortBy(fn (array $b) => [$b['mulai'] ?? '9999', $b['mapel'] ?? ''])
            ->values();

        // Kartu besar: satu ujian yang paling mendesak - sedang berjalan,
        // atau yang paling cepat dibuka. Kriterianya "belum selesai", bukan
        // "hari ini" - lihat catatan kelas ini.
        $utama = $baris->first(fn (array $b) => $b['utama']);

        return Inertia::render('Siswa/Jadwal', [
            'nama' => $request->user()->name,
            'kelas' => $penempatan->first()?->pesertaEvent?->kelas?->name,
            'sekarang' => $sekarang->toIso8601String(),
            'utama' => $utama,
            'jadwal' => $baris->all(),
            'nilai_keluar' => $this->adaNilaiKeluar($request->user()->id),
        ]);
    }

    /**
     * Satu baris jadwal, dari sudut pandang anak yang akan mengerjakannya.
     *
     * @return array<string, mixed>
     */
    private function bentukBaris(ExamParticipant $p, ?ExamAttempt $attempt, CarbonInterface $sekarang): array
    {
        $sesi = $p->session;
        $ujian = $p->exam;

        $bolehMulai = $sesi !== null
            && $sekarang >= $sesi->access_opens_at
            && $sekarang <= $sesi->hard_end_at;

        $selesai = $attempt !== null
            && in_array($attempt->status, [ExamAttempt::STATUS_DIKIRIM, ExamAttempt::STATUS_KEDALUWARSA], true);

        // "menunggu" pada kartu utama berarti: hari ini, dan belum selesai.
        // Kartu yang menunjuk ujian minggu depan akan membuat anak menekan
        // tombol yang pasti ditolak gerbangnya.
        //
        // `copy()` WAJIB. `$sekarang` adalah satu instance Carbon yang dipakai
        // ULANG oleh pemanggil untuk setiap baris, dan `modify()` mengubahnya
        // di tempat. Tanpa salinan, batas "besok" ini menggeser `$sekarang`
        // sehari penuh untuk sisa fungsi ini - sehingga status yang dihitung
        // baris 135 dibandingkan dengan BESOK, bukan dengan sekarang.
        //
        // Gejalanya persis kebalikan dari yang terbaca: sesi yang sudah tutup
        // dilaporkan "terlewat" (nada bad) sementara `boleh_mulai` di baris
        // yang sama bernilai true, dan `sekarang` yang dikirim ke peramban
        // bertanggal besok.
        $batasUtama = $sekarang->copy()->addDay();

        $utama = ! $selesai
            && $sesi !== null
            && $sesi->hard_end_at >= $sekarang
            && $sesi->access_opens_at <= $batasUtama;

        return [
            'peserta_id' => $p->id,
            'mapel' => $ujian?->subject?->name,
            'ujian' => $ujian?->title,
            'tanggal' => $sesi?->access_opens_at?->format('d M'),
            'tanggal_iso' => $sesi?->access_opens_at?->toIso8601String(),
            'jam' => $sesi === null
                ? null
                : $sesi->access_opens_at->format('H:i').'–'.$sesi->hard_end_at->format('H:i'),
            'mulai' => $sesi?->access_opens_at?->toIso8601String(),
            'hard_end' => $sesi?->hard_end_at?->toIso8601String(),
            'ruang' => $p->room?->eventRoom?->room?->code,
            'ruang_nama' => $p->room?->eventRoom?->room?->name,
            'kursi' => $this->nomorKursi($p),

            'utama' => $utama,
            'boleh_mulai' => $bolehMulai && ! $selesai,
            'selesai' => $selesai,

            'status' => $this->statusBaris($p, $attempt, $sesi, $sekarang),
            'nada' => $this->nadaBaris($p, $attempt, $sesi, $sekarang),
        ];
    }

    /**
     * Label status baris - kosakata plate 19 apa adanya.
     *
     * Yang dilaporkan di sini adalah keadaan yang paling perlu diketahui
     * anak untuk MEMUTUSKAN, bukan keadaan penempatannya. "presensi belum"
     * jauh lebih berguna daripada "terdaftar" pada ujian yang mulai 20 menit
     * lagi.
     */
    private function statusBaris(ExamParticipant $p, ?ExamAttempt $attempt, ?ExamSession $sesi, CarbonInterface $sekarang): string
    {
        if ($p->status !== ExamParticipant::STATUS_TERDAFTAR) {
            return 'dikecualikan';
        }

        if ($attempt !== null) {
            return match ($attempt->status) {
                ExamAttempt::STATUS_DIKIRIM => 'selesai',
                ExamAttempt::STATUS_KEDALUWARSA => 'waktu habis',
                ExamAttempt::STATUS_DIKUNCI => 'terkunci',
                ExamAttempt::STATUS_BATAL => 'batal',
                default => 'sedang dikerjakan',
            };
        }

        if ($sesi === null) {
            return 'belum dijadwalkan';
        }

        if ($sekarang > $sesi->hard_end_at) {
            return 'terlewat';
        }

        // Presensi dibaca di sini, bukan lewat KelayakanUjian: yang
        // dibutuhkan jadwal adalah "apakah anak ini perlu menghubungi
        // pengawas", dan KelayakanUjian menjawab pertanyaan yang berbeda -
        // "boleh mulai atau tidak" - yang menuntut seluruh sebelas syaratnya
        // dipenuhi.
        $hadir = $p->exam_session_id !== null && AttendanceRecord::query()
            ->where('exam_participant_id', $p->id)
            ->whereNotNull('verified_at')
            ->exists();

        if (! $hadir) {
            return $sekarang >= $sesi->access_opens_at ? 'presensi belum' : 'besok';
        }

        return $sesi->access_opens_at > $sekarang ? 'siap' : 'siap dimulai';
    }

    private function nadaBaris(ExamParticipant $p, ?ExamAttempt $attempt, ?ExamSession $sesi, CarbonInterface $sekarang): string
    {
        $status = $this->statusBaris($p, $attempt, $sesi, $sekarang);

        return match ($status) {
            'selesai', 'siap', 'siap dimulai' => 'ok',
            'sedang dikerjakan' => 'info',
            'presensi belum', 'terkunci' => 'warn',
            'terlewat', 'waktu habis', 'dikecualikan' => 'bad',
            default => 'mute',
        };
    }

    /**
     * Apakah ada nilai yang sudah keluar - plate 19 menampilkan inboxnya.
     *
     * Sejak Fase 8 jawabannya datang dari `school_events.results_published_at`,
     * bukan dari `exams.publish_results` - kolom itu dijatuhkan karena
     * publikasi adalah satu aksi untuk seluruh event (plate 21).
     *
     * Rantainya peserta -> ujian -> event, dan satu simpul di tengahnya bisa
     * putus (ujian tanpa event); `whereHas` menangani itu sebagai "tidak ada",
     * yang memang jawaban yang benar.
     */
    private function adaNilaiKeluar(int $siswaId): bool
    {
        return ExamAttempt::query()
            ->whereIn('exam_participant_id', ExamParticipant::query()
                ->where('student_id', $siswaId)
                ->pluck('id'))
            ->whereIn('status', [ExamAttempt::STATUS_DIKIRIM, ExamAttempt::STATUS_KEDALUWARSA])
            ->whereHas('penempatan.exam.event', fn ($q) => $q->whereNotNull('results_published_at'))
            ->exists();
    }
}
