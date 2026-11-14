<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Services\TempatUjianSiswa;
use App\Domain\Event\Models\EventAnnouncement;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    public function index(Request $request, TempatUjianSiswa $tempatUjian): Response
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

        // Presensi seluruh penempatan anak ini, SATU kueri. Dipakai baris
        // jadwal (status kehadiran) dan ringkasan di atas daftar.
        $presensi = AttendanceRecord::query()
            ->whereIn('exam_participant_id', $penempatan->pluck('id'))
            ->get()
            ->keyBy('exam_participant_id');

        $sekarang = now();

        $baris = $penempatan
            ->map(fn (ExamParticipant $p) => $this->bentukBaris(
                $p,
                $attempt->get($p->id),
                $presensi->get($p->id),
                $sekarang,
            ))
            // Urutan jadwal, bukan urutan id: yang dibaca anak adalah
            // "setelah ini apa", dan id tidak menjawabnya.
            ->sortBy(fn (array $b) => [$b['mulai'] ?? '9999', $b['mapel'] ?? ''])
            ->values();

        // Kartu besar: satu ujian yang paling mendesak - sedang berjalan,
        // atau yang paling cepat dibuka. Kriterianya "belum selesai", bukan
        // "hari ini" - lihat catatan kelas ini.
        $utama = $baris->first(fn (array $b) => $b['utama']);

        // Denah kursi HANYA untuk kartu utama, dan itu disengaja.
        //
        // Setiap denah menuntut satu pemanggilan `PetaKursiEvent::sel()` -
        // empat puluh kursi untuk satu ruang. Menempelkannya ke setiap baris
        // daftar berarti membayar seluruh ruangan untuk baris-baris yang
        // tidak akan ada yang membuka panelnya. Yang dibuka anak di hari-H
        // adalah baris pertama, dan itu tebakan yang layak disebut.
        //
        // `untuk()` dipakai alih-alih `terdekat()` supaya denahnya menunjuk
        // penempatan yang SAMA dengan kartu di atasnya. Dua pilihan yang
        // berbeda bisa menyebut ujian yang berbeda - kartu "Ruang 02, kursi
        // 7" dengan denah ruang 1 di belakangnya adalah layar yang
        // bertengkar dengan dirinya sendiri.
        if ($utama !== null) {
            $pesertaUtama = $penempatan->firstWhere('id', $utama['peserta_id']);
            $utama['tempat'] = $pesertaUtama === null ? null : $tempatUjian->untuk($pesertaUtama);
        }

        return Inertia::render('Siswa/Jadwal', [
            'nama' => $request->user()->name,
            'kelas' => $penempatan->first()?->pesertaEvent?->kelas?->name,
            'sekarang' => $sekarang->toIso8601String(),
            'utama' => $utama,
            'jadwal' => $baris->all(),
            'nilai_keluar' => $this->adaNilaiKeluar($request->user()->id),
            'pengumuman' => $this->pengumumanBerlaku($penempatan),

            // Ringkasan kemajuan ujian anak ini - dihitung dari baris yang
            // SAMA dengan daftar di bawahnya. Angka di atas daftar yang
            // dihitung dengan cara lain adalah angka yang suatu hari berbeda
            // dari isinya, dan yang membacanya berhenti memercayai keduanya.
            'kemajuan' => $this->kemajuan($baris),
        ]);
    }

    /**
     * Berapa ujian yang sudah dituntaskan, berapa yang masih menunggu, dan
     * berapa yang jendelanya sudah lewat.
     *
     * ---------------------------------------------------------------------
     * "Selesai" berarti pengerjaannya SUDAH DIKIRIM - bukan sekadar ada
     * ---------------------------------------------------------------------
     * Attempt yang `batal` adalah pengerjaan yang tidak dihitung sebagai
     * kesempatan (lihat `PengendaliPengerjaan::reset()`), jadi menghitungnya
     * sebagai "sudah dikerjakan" akan membuat anak yang direset melihat
     * "1 dari 5 selesai" untuk ujian yang justru harus ia ulangi.
     *
     * `kedaluwarsa` DIHITUNG selesai: waktunya habis, jawabannya terkunci,
     * dan tidak ada lagi yang bisa ia lakukan pada ujian itu.
     *
     * ---------------------------------------------------------------------
     * "Terlewat" BUKAN "masih menunggu" - perbaikan 2026-11-14
     * ---------------------------------------------------------------------
     * Sebelum ini `menunggu` dihitung sebagai `selesai === false`, dan baris
     * berstatus "terlewat" selalu `false` karena `selesai` hanya membaca
     * attempt. Akibatnya anak yang sudah melewati jendela sebuah ujian -
     * tanpa pernah mengerjakannya - melihat "3 masih menunggu" untuk ujian
     * yang tidak akan pernah datang, dan mengejar sesuatu yang tidak ada.
     *
     * Yang benar: terlewat adalah keadaan KETIGA, disebut apa adanya. Ia
     * tetap ditampilkan di daftar sebagai riwayat, tetapi ia tidak lagi
     * dijanjikan sebagai pekerjaan yang menunggu.
     *
     * ---------------------------------------------------------------------
     * Dikecualikan tidak dihitung sama sekali
     * ---------------------------------------------------------------------
     * Penempatan yang dikecualikan bukan ujian yang "menunggu" - ia ujian
     * yang tidak akan pernah datang, dan memasukkannya ke penyebut membuat
     * anak mengejar sesuatu yang tidak ada.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return array{total: int, selesai: int, terlewat: int, menunggu: int}
     */
    private function kemajuan(Collection $baris): array
    {
        $dihitung = $baris->reject(fn (array $b) => $b['status'] === 'dikecualikan');

        $terlewat = $dihitung->where('status', 'terlewat')->count();
        $selesai = $dihitung->where('selesai', true)->count();

        return [
            'total' => $dihitung->count(),
            'selesai' => $selesai,
            'terlewat' => $terlewat,
            // Sisanya, bukan `where('selesai', false)`: baris yang terlewat
            // DAN belum dikumpulkan akan terhitung dua kali bila dihitung
            // dari keadaan `selesai`.
            'menunggu' => $dihitung->count() - $selesai - $terlewat,
        ];
    }

    /**
     * Pengumuman yang sedang tayang di event-event milik anak ini.
     *
     * ---------------------------------------------------------------------
     * Dieja dari event PENEMPATANNYA, bukan dari seluruh event sekolah
     * ---------------------------------------------------------------------
     * `EventAnnouncement` adalah tabel Event, dan scope tenant-nya hanya
     * menyaring sekolah. Tanpa penyaringan tambahan, setiap siswa membaca
     * pengumuman seluruh event sekolah - termasuk kelas yang bukan
     * kelasnya, dan itu kebocoran informasi yang tidak tampak sebagai galat.
     *
     * ---------------------------------------------------------------------
     * Dibatasi lima, terbaru dulu
     * ---------------------------------------------------------------------
     * Beranda ini menjawab "apa yang harus kukerjakan sekarang". Pengumuman
     * keenam dan seterusnya tidak menambah apa pun selain panjang gulir -
     * dan yang paling penting pada hari-H biasanya yang terakhir ditulis.
     *
     * @param  Collection<int, ExamParticipant>  $penempatan
     * @return array<int, array<string, mixed>>
     */
    private function pengumumanBerlaku(Collection $penempatan): array
    {
        $idEvent = $penempatan
            ->map(fn (ExamParticipant $p) => $p->exam?->event_id)
            ->filter()
            ->unique()
            ->values();

        if ($idEvent->isEmpty()) {
            return [];
        }

        return EventAnnouncement::query()
            ->whereIn('event_id', $idEvent)
            ->berlaku()
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (EventAnnouncement $p) => [
                'id' => $p->id,
                'judul' => $p->title,
                'isi' => $p->body,
                'nada' => $p->nada,
                'berlaku_sampai' => $p->expires_at?->format('d M H:i'),
            ])
            ->all();
    }

    /**
     * Satu baris jadwal, dari sudut pandang anak yang akan mengerjakannya.
     *
     * @return array<string, mixed>
     */
    private function bentukBaris(
        ExamParticipant $p,
        ?ExamAttempt $attempt,
        ?AttendanceRecord $presensi,
        CarbonInterface $sekarang,
    ): array {
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
            'hitung_mundur' => $this->hitungMundur($sesi, $sekarang),

            'utama' => $utama,
            'boleh_mulai' => $bolehMulai && ! $selesai,
            'selesai' => $selesai,

            // ----------------------------------------------------------
            // Presensi - keadaan yang TIDAK bisa dibaca anak dari mana pun
            // ----------------------------------------------------------
            // Sampai 2026-11-14 satu-satunya cara anak tahu presensinya
            // sudah diverifikasi adalah membuka gerbang lalu membaca
            // penolakannya. Padahal presensi adalah gerbang ujian: anak yang
            // belum diverifikasi berdiri di depan pintu tanpa cara tahu
            // apakah ia harus memanggil pengawas atau menunggu.
            //
            // `pending` dikirim sebagai status, bukan sebagai `null`: "belum
            // diverifikasi" dan "tidak ada baris presensi" adalah dua keadaan
            // berbeda, tetapi yang kedua tidak bisa terjadi di sini - baris
            // presensi lahir bersama penempatan.
            'presensi' => [
                'status' => $presensi?->attendance_status->value,
                'label' => $presensi?->attendance_status->label()
                    ?? StatusPresensi::Pending->label(),
                'nada' => $presensi?->attendance_status->nada()
                    ?? StatusPresensi::Pending->nada(),
                'jam' => $presensi?->verified_at?->format('H:i'),
                // Membuka gerbang TERGANTUNG izin terlambat milik ujian -
                // aturan yang sama dengan `KelayakanUjian`. Dihitung di sini
                // supaya laman tidak menyimpulkan sendiri dan menampilkan
                // "siap mulai" untuk anak yang gerbangnya pasti menolak.
                'membuka_gerbang' => $presensi !== null
                    && $presensi->attendance_status->membukaGerbang(
                        (bool) $ujian?->allow_late_entry,
                    ),
            ],

            'status' => $this->statusBaris($p, $attempt, $sesi, $sekarang),
            'nada' => $this->nadaBaris($p, $attempt, $sesi, $sekarang),
        ];
    }

    /**
     * Detik sampai sesi ini dibuka, dan itu yang dihitung, bukan jam dinding.
     *
     * Dihitung SERVER dan dikirim sebagai selisih, bukan sebagai waktu buka.
     * Jam peramban anak bisa meleset berjam-jam, dan hitung mundur yang
     * memakai jam perangkat akan menjanjikan "3 menit lagi" pada anak yang
     * sesinya baru dibuka dua jam kemudian. Laman hanya mengurangi angka ini
     * satu per detik sejak prop itu diterima - dan angka yang sudah lewat
     * ditampilkan sebagai nol, bukan sebagai hitung mundur negatif.
     *
     * `null` bila sesinya tidak ada, sudah dibuka, atau sudah berakhir:
     * ketiganya bukan "menunggu", dan menghitung mundur ke waktu yang tidak
     * akan datang adalah janji yang tidak ditepati.
     */
    private function hitungMundur(?ExamSession $sesi, CarbonInterface $sekarang): ?int
    {
        if ($sesi === null || $sekarang->gte($sesi->access_opens_at)) {
            return null;
        }

        return max(0, (int) $sekarang->diffInSeconds($sesi->access_opens_at));
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
