<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\KehadiranService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\PetaKursiEvent;
use App\Domain\Facility\Models\RoomEntryLink;
use App\Domain\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kios per-PC ruang lab - `/lab/{slug}/{nomor}`.
 *
 * =====================================================================
 * Mengapa laman ini ada, dan apa yang tidak bisa dijawab jalur lain
 * =====================================================================
 * Ruang lab bukan ruang BYOD: kursinya terikat pada PC bernomor, dan
 * presensinya bisa dibuktikan MESIN - anak benar-benar masuk di PC yang
 * ditugaskan kepadanya. Jalur lama (`/p/{slug}` + kode pengawas) mencatat
 * presensi lewat daftar pengawas, dan pada anak yang duduk di depan PC-nya
 * sendiri itu bukti yang lebih lemah daripada yang sebenarnya tersedia.
 *
 * =====================================================================
 * Slug yang dipakai adalah slug TAUTAN RUANG yang sudah ada
 * =====================================================================
 * Satu tautan per ruang, bukan satu per sesi dan bukan satu per PC. Tiga
 * alasan:
 *
 *   1. Tautan ruang sudah diterbitkan Admin dan sudah ditempel di pintu.
 *      Menambah bentuk tautan kedua berarti dua kartu di satu pintu, dan
 *      salah satunya akan terlupa.
 *   2. Yang membedakan kios satu dengan lainnya adalah NOMOR KURSI di
 *      URL-nya, bukan kredensialnya. Nomor itu bukan rahasia - ia tercetak
 *      di meja, di tablo, dan di papan pengawas.
 *   3. Pencabutan tetap satu tombol: mencabut tautan ruang mematikan
 *      pintunya DAN kiosnya sekaligus, tanpa kode tambahan.
 *
 * ---------------------------------------------------------------------
 * Tautan yang berlaku TIDAK membuka ujian, dan kios tidak memintas gerbang
 * ---------------------------------------------------------------------
 * Kios hanya MENULIS PRESENSI. Anak tetap harus punya akun, tetap mengetik
 * token, dan tetap melewati `KelayakanUjian` - pemeriksaan yang sama dengan
 * laman gerbang. Yang berubah hanya dari mana presensinya datang.
 *
 * ---------------------------------------------------------------------
 * Yang tayang di layar kios hanya identitas PERANGKAT
 * ---------------------------------------------------------------------
 * Layar ini menghadap ruangan dan difoto orang. Sebelum siswa masuk, yang
 * tampil hanya: nama sekolah, kode ruang, nomor PC, dan jam sesi. Tidak ada
 * daftar nama, tidak ada nama siswa lain, tidak ada token.
 */
class KiosLabController
{
    public function __construct(
        private readonly KehadiranService $kehadiran,
        private readonly PetaKursiEvent $peta,
    ) {}

    /** Layar tunggu: kursi menyala, menunggu akun. */
    public function tampilkan(string $slug, int $nomor): Response
    {
        $ruang = $this->ruang($slug);

        // Nomor kursi yang tidak ada di ruang ini bukan "tidak ditemukan":
        // ia PC yang salah ketik di monitor. 404, dan pesannya dibedakan di
        // laman supaya teknisi yang memasang monitor tahu apa yang salah.
        $sah = $this->nomorSah($ruang);

        return Inertia::render('Pengawas/KiosLab', [
            'sekolah' => $ruang->eventRoom?->room?->school?->name
                ?? $ruang->eventRoom?->room?->name,
            'slug' => $slug,
            'nomor' => $nomor,
            'ruang' => [
                'kode' => $ruang->eventRoom?->room?->code,
                'nama' => $ruang->eventRoom?->room?->name,
                'kapasitas' => $ruang->eventRoom?->kapasitasEfektif() ?? 0,
                'kursi_ber_pc' => $ruang->eventRoom?->kursiBerPerangkat() ?? 0,
            ],
            // Nomor yang benar-benar ada di ruang ini - dipakai layar untuk
            // memberi tahu bila nomor di URL berada di luar jangkauan.
            'nomor_sah' => $sah['maks'] > 0 && $nomor > $sah['maks'],
            'sesi' => [
                'label' => $ruang->session?->label(),
                'ujian' => $ruang->session?->exam?->subject?->name
                    ?? $ruang->session?->exam?->title,
                'jam' => $ruang->session === null
                    ? null
                    : $ruang->session->access_opens_at->format('H:i')
                        .'–'.$ruang->session->hard_end_at->format('H:i'),
                'berjalan' => $this->berjalan($ruang),
            ],
            'pesan' => $this->pesanKeadaan($ruang, $nomor),
        ]);
    }

    /**
     * Tujuan tombol "Masuk" di layar tunggu - sudah terautentikasi.
     *
     * =====================================================================
     * Mengapa rute tersendiri, bukan tautan langsung ke `/login`
     * =====================================================================
     * `LoginController` memakai `redirect()->intended()`, dan kunci
     * `url.intended` hanya ditulis oleh middleware `auth` saat ia
     * MENGALIHKAN permintaan. Tautan ke `/login` dari laman publik tidak
     * melewati middleware itu, sehingga anak yang berhasil masuk
     * dikembalikan ke berandanya - bukan ke kios yang sedang ia hadapi.
     *
     * Rute inilah yang membuat pengalihannya terjadi, dan ia menempuh jalur
     * yang SAMA dengan `masuk()` - satu inti, bukan dua salinan aturan.
     */
    public function gerbang(Request $request, string $slug, int $nomor): RedirectResponse
    {
        return $this->masuk($request, $slug, $nomor);
    }

    /**
     * Anak masuk di kios ini.
     *
     * ---------------------------------------------------------------------
     * Tiga penolakan, dan masing-masing menyebut apa yang harus dilakukan
     * ---------------------------------------------------------------------
     * Kursi tidak cocok  -> duduk di kursi yang tertera, atau panggil pengawas
     * Bukan peserta      -> akun ini tidak ditempatkan di ruang ini
     * Sesi tidak berjalan-> jamnya belum/sudah lewat
     *
     * Yang pertama adalah inti fiturnya: tanpa ikatan kursi, dua anak bisa
     * bertukar tempat tanpa ada yang tahu, dan seluruh tablo, denah, serta
     * berita acara menjadi salah.
     *
     * ---------------------------------------------------------------------
     * Akun yang menentukan SIAPA, bukan nomor di URL
     * ---------------------------------------------------------------------
     * Nomor kursi di URL bisa diketik siapa saja - ia tercetak di meja dan
     * di tablo. Akun datang dari sesi login. Mencari lewat nomor lebih dulu
     * berarti siapa pun yang mengetik nomor anak lain mendapat
     * penempatannya.
     */
    public function masuk(Request $request, string $slug, int $nomor): RedirectResponse
    {
        $ruang = $this->ruang($slug);

        $user = $request->user();

        if ($user === null) {
            return back()->with('galat', 'Kios ini menuntut akun siswa. Masuk lebih dulu.');
        }

        // Ruang tanpa kursi ber-PC bukan ruang kios - lihat `pesanKeadaan()`.
        // Diperiksa juga di sini, bukan hanya di layar: form yang dikirim
        // dengan tangan tidak melewati layar itu.
        if (! ($ruang->eventRoom?->adaPerangkat() ?? false)) {
            return back()->with('galat', 'Ruang ini tidak punya PC, jadi tidak memakai kios lab.');
        }

        if (! $this->berjalan($ruang)) {
            return back()->with('galat', 'Sesi ruang ini tidak sedang berjalan.');
        }

        $peserta = $this->pesertaDiKursi($ruang, $user);

        if ($peserta === null) {
            return back()->with('galat', 'Akun ini tidak ditempatkan di ruang ini.');
        }

        $kursi = $this->peta->kursiSatu($peserta);

        if ($kursi === null) {
            return back()->with('galat', 'Kursi akun ini belum ditetapkan. Panggil pengawas ruang.');
        }

        if ($kursi !== $nomor) {
            return back()->with('galat', "Kursi Anda PC {$kursi}, bukan PC {$nomor}. Duduk di kursi yang tertera.");
        }

        $record = AttendanceRecord::query()
            ->where('exam_participant_id', $peserta->id)
            ->first();

        if ($record === null) {
            return back()->with('galat', 'Baris presensi belum ada untuk penempatan ini. Panggil pengawas ruang.');
        }

        // ---------------------------------------------------------------
        // Status yang sudah membuka gerbang TIDAK ditulis ulang
        // ---------------------------------------------------------------
        // Anak yang memindai QR pintu dari HP-nya lalu duduk di kios akan
        // sudah berstatus `hadir`. Menulisnya lagi berarti baris log kedua
        // untuk kejadian yang sama, dan riwayat presensi yang melaporkan dua
        // kejadian akan membuat pertanyaan "kenapa dua" tidak bisa dijawab.
        if ($record->attendance_status->membukaGerbang()) {
            return $this->keGerbang($peserta);
        }

        $this->kehadiran->catat(
            $record,
            StatusPresensi::Hadir,
            $this->konteksKios($ruang, $nomor),
            "Masuk lewat kios PC {$nomor}.",
        );

        return $this->keGerbang($peserta);
    }

    /* ------------------------------------------------------------- Pembantu */

    /**
     * Ruang-sesi dari slug tautan - hanya bila tautannya masih berlaku.
     *
     * Aturannya sama dengan `PintuRuangController::ruang()`: yang dipilih
     * adalah ruang-sesi yang jendelanya sedang terbuka, karena satu ruang
     * dipakai sesi pagi dan siang pada hari yang sama.
     */
    private function ruang(string $slug): ExamSessionRoom
    {
        $ruang = $this->ruangMungkin($slug);

        // 404 hanya untuk keadaan yang memang TIDAK ADA: slug keliru, tautan
        // dicabut, atau ruang yang tidak pernah dipakai sesi mana pun.
        //
        // Ruang yang sudah dipakai sesi tetapi jendelanya lewat BUKAN 404 -
        // itu keadaan yang sah pada kios yang dinyalakan sebelum ujian atau
        // sesudahnya, dan layar yang menjawab "Not Found" kepada teknisi yang
        // baru memasang monitor tidak memberitahunya apa pun. Keadaan itu
        // ditangani `tampilkan()` lewat `pesanKeadaan()`.
        if ($ruang === null) {
            abort(404);
        }

        return $ruang;
    }

    /**
     * Ruang-sesi untuk sebuah slug, tanpa memutuskan 404 - null bila tidak ada.
     *
     * ---------------------------------------------------------------------
     * Tiga tingkat pencarian, dan urutannya menentukan
     * ---------------------------------------------------------------------
     *   1. ruang-sesi yang jendelanya SEDANG BERJALAN  -> sesi yang benar;
     *   2. bila tidak ada, ruang-sesi TERBARU apa pun   -> layar menyebutkan
     *      jadwalnya, bukan 404;
     *   3. bila ruangnya memang belum pernah dipakai   -> null, dan 404.
     *
     * Tingkat kedua ada karena kios dinyalakan LEBIH DULU daripada sesinya,
     * dan tetap menyala SESUDAHNYA. Keduanya keadaan normal di lab, dan
     * keduanya menuntut layar yang menjelaskan - bukan halaman galat.
     */
    private function ruangMungkin(string $slug): ?ExamSessionRoom
    {
        $tautan = RoomEntryLink::tanpaTenant()->where('slug', $slug)->first();

        if ($tautan === null || ! $tautan->berlaku()) {
            return null;
        }

        $muatan = [
            'eventRoom.room:id,code,name,capacity,device_count',
            'session.exam:id,title,subject_id',
            'session.exam.subject:id,name',
        ];

        // (1) Jendela yang sedang berlaku. Tanpa saringan ini, ruang yang
        // dipakai dua sesi akan memilih yang mana saja, dan presensinya masuk
        // ke sesi yang salah.
        $berjalan = ExamSessionRoom::tanpaTenant()
            ->with($muatan)
            ->whereHas('eventRoom', fn ($q) => $q->where('room_id', $tautan->room_id))
            ->whereHas('session', fn ($q) => $q
                ->where('access_opens_at', '<=', now())
                ->where('hard_end_at', '>=', now()))
            ->orderByDesc('id')
            ->first();

        if ($berjalan !== null) {
            return $berjalan;
        }

        // (2) Sesi terbaru yang pernah memakai ruang ini, apa pun jamnya.
        return ExamSessionRoom::tanpaTenant()
            ->with($muatan)
            ->whereHas('eventRoom', fn ($q) => $q->where('room_id', $tautan->room_id))
            ->whereHas('session')
            ->orderByDesc('id')
            ->first();
    }

    private function berjalan(ExamSessionRoom $ruang): bool
    {
        $sesi = $ruang->session;

        if ($sesi === null) {
            return false;
        }

        return now()->between($sesi->access_opens_at, $sesi->hard_end_at);
    }

    /**
     * Peserta di kursi ini - dicari dari akunnya, bukan dari nomor kursi.
     *
     * Urutannya menentukan: nomor kursi datang dari URL yang bisa diketik
     * siapa saja, sedangkan akun datang dari sesi login. Mencari lewat nomor
     * lebih dulu berarti siapa pun yang mengetik nomor anak lain akan
     * mendapat penempatannya.
     */
    private function pesertaDiKursi(ExamSessionRoom $ruang, User $user): ?ExamParticipant
    {
        return ExamParticipant::tanpaTenant()
            ->where('exam_session_room_id', $ruang->id)
            ->where('student_id', $user->id)
            ->first();
    }

    /** @return array{maks: int} */
    private function nomorSah(ExamSessionRoom $ruang): array
    {
        $kursi = $this->peta->kursi((int) $ruang->eventRoom?->id);

        return ['maks' => $kursi === [] ? 0 : max($kursi)];
    }

    private function pesanKeadaan(ExamSessionRoom $ruang, int $nomor): ?string
    {
        // ---------------------------------------------------------------
        // Ruang tanpa PC BUKAN ruang kios, dan itu dikatakan lebih dulu
        // ---------------------------------------------------------------
        // Kios ada untuk kursi yang perangkatnya milik sekolah. Ruang yang
        // tidak punya satu pun kursi ber-PC adalah ruang BYOD: siswanya
        // membawa HP sendiri dan masuk lewat jalur biasa (`/hadir/{kode}`
        // atau daftar pengawas).
        //
        // Tanpa pemeriksaan ini, `/lab/...` di ruang BYOD menampilkan layar
        // kios yang berfungsi - dan teknisi yang memasangnya mengira ruang
        // itu lab, lalu menunggu anak-anak yang seharusnya tidak lewat sini.
        if (! ($ruang->eventRoom?->adaPerangkat() ?? false)) {
            return 'Ruang ini tidak punya PC, jadi tidak memakai kios lab. '
                .'Siswa di ruang ini membawa perangkat sendiri dan diabsen lewat pintu ruang.';
        }

        if ($ruang->session === null) {
            return 'Ruang ini belum dipakai sesi mana pun.';
        }

        if (! $this->berjalan($ruang)) {
            $sesi = $ruang->session;
            $sekarang = now();

            // Tiga kalimat, bukan satu: yang dibaca teknisi yang memasang
            // monitor perlu tahu JADWALNYA, bukan hanya bahwa ia belum bisa
            // dipakai - dan apakah jadwalnya belum tiba atau sudah lewat.
            $kapan = $sekarang->lt($sesi->access_opens_at)
                ? 'Jadwalnya '.$sesi->access_opens_at->format('d M · H:i').' – '
                    .$sesi->hard_end_at->format('H:i').'.'
                : 'Sesi ini sudah berakhir pukul '.$sesi->hard_end_at->format('d M · H:i').'.';

            return 'Sesi ruang ini tidak sedang berjalan. '.$kapan;
        }

        $sah = $this->nomorSah($ruang);

        if ($sah['maks'] > 0 && $nomor > $sah['maks']) {
            return "PC {$nomor} tidak ada di ruang ini. Kursi tertinggi: PC {$sah['maks']}.";
        }

        return null;
    }

    /**
     * Konteks untuk penulisan presensi, dengan pelaku yang menyebut PERANGKAT.
     *
     * `KonteksRuang` tidak bisa dibangun di sini: ia menuntut hibah sesi
     * pengawas atau akun bertugas, dan kios bukan keduanya. Yang dipakai
     * karena itu konteks khusus yang menyebut ruang dan nomor PC-nya -
     * supaya baris log menjawab "dari mana presensi ini datang" tanpa
     * mengarang seorang pelaku.
     */
    private function konteksKios(ExamSessionRoom $ruang, int $nomor): KonteksRuang
    {
        return KonteksRuang::kios($ruang, "perangkat {$ruang->eventRoom?->room?->code} PC {$nomor}");
    }

    private function keGerbang(ExamParticipant $peserta): RedirectResponse
    {
        return redirect()->route('siswa.gerbang', ['peserta' => $peserta->id]);
    }
}
