<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Facility\Models\RoomEntryLink;
use App\Domain\Facility\Services\RoomEntryLinkService;
use App\Support\PembatasLaju;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pintu ruang - halaman publik `/p/{slug}`.
 *
 * =====================================================================
 * Halaman ini SENGAJA di luar middleware('auth')
 * =====================================================================
 * Yang membukanya belum tentu punya akun, dan itu alasan keberadaannya.
 * Pengawas berdiri di depan pintu kelas, memindai kartu yang ditempel Admin
 * di daun pintu, lalu mengetik enam angka. Menuntutnya login lebih dulu
 * berarti menuntutnya membawa akun ke setiap ruang yang ia awasi - dan
 * dokumen sekolah yang ditandatangani di hari-H tidak boleh bergantung pada
 * itu.
 *
 * ---------------------------------------------------------------------
 * Halaman ini hanya boleh membocorkan TIGA hal
 * ---------------------------------------------------------------------
 *   - nama ruangnya ("Lab Komputer 1"),
 *   - nama sekolahnya ("SMK Negeri 1"),
 *   - dan nama sesinya ("S-1 - Matematika").
 *
 * Tidak daftar peserta, tidak token, tidak nama pengawas, tidak jumlah
 * siswa. Batas itu bukan kehati-hatian berlebih: tautan ini tertempel di
 * daun pintu yang bisa difoto siapa pun yang lewat di koridor, dan daftar
 * nama anak-anak di daun pintu adalah hal yang tidak boleh terjadi.
 *
 * ---------------------------------------------------------------------
 * Slug di-resolve dengan tanpaTenant(), dan itu WAJIB
 * ---------------------------------------------------------------------
 * Pada permintaan ini belum ada user, sehingga SchoolResolver mengembalikan
 * null - dan null berarti "jangan saring", bukan "tidak ada sekolah".
 * Memanggil `RoomEntryLink::where('slug', ...)` seperti biasa akan
 * "berhasil" sampai suatu hari resolver kebetulan punya konteks, dan
 * kegagalannya bukan galat melainkan tautan yang tiba-tiba tidak ditemukan.
 *
 * Karena itu `tanpaTenant()` dipakai eksplisit, dan school_id dari baris
 * itulah yang kemudian ditetapkan ke resolver oleh AksesPengawas.
 */
class PintuRuangController
{
    /**
     * Tampilkan form kode.
     *
     * Tautan yang dicabut dan yang kedaluwarsa dibedakan pesannya, dan
     * pembedaan itu berguna di pintu: "tautan sudah dicabut, minta Admin
     * menerbitkan yang baru" adalah instruksi, sementara "tautan tidak
     * ditemukan" bukan apa-apa.
     */
    public function tampilkan(string $slug): Response
    {
        $tautan = $this->tautan($slug);

        $ruang = $this->ruang($tautan);

        return Inertia::render('Pengawas/Pintu', [
            'slug' => $slug,
            'ruang' => $ruang === null ? null : $this->ringkas($ruang),
            'berlaku' => $tautan->berlaku(),
            'alasan' => $tautan->berlaku() ? null : $this->alasanTidakBerlaku($tautan),
            'sekolah' => $tautan->school?->name,
        ]);
    }

    /**
     * Buka pintu dengan kode enam angka.
     *
     * ---------------------------------------------------------------------
     * Kegagalan tidak mengembalikan 403, melainkan kembali ke form
     * ---------------------------------------------------------------------
     * 403 memberi tahu pemegangnya bahwa ia benar-benar ada di sistem orang
     * lain. Yang gagal di sini bukan izinnya, melainkan KODENYA - dan kode
     * yang salah dijawab dengan form yang sama beserta alasannya.
     */
    public function buka(string $slug, Request $request, RoomEntryLinkService $service, ActivityLogger $logger): RedirectResponse
    {
        $tautan = $this->tautan($slug);

        $valid = $request->validate([
            'kode' => ['required', 'string', 'size:6'],
        ], [], ['kode' => 'kode pengawas']);

        // `cocokkan()` menjalankan urutan yang benar di dalam dirinya:
        // berlaku -> Hash::check -> naikkan use_count. Urutan itu adalah
        // keamanannya - kalau dibalik, use_count naik untuk percobaan yang
        // GAGAL, dan Admin melihat "5 pemakaian" pada tautan yang belum
        // pernah dipakai siapa pun. Lihat RoomEntryLinkService.
        if (! $service->cocokkan($tautan, $valid['kode'])) {
            $logger->log('Percobaan kode pengawas ditolak', $tautan, [
                'slug' => $slug,
                'sebab' => $tautan->berlaku() ? 'kode_salah' : 'tautan_tidak_berlaku',
            ]);

            return back()->with('galat', $tautan->berlaku()
                ? 'Kode pengawas tidak cocok. Periksa kembali enam angkanya.'
                : $this->alasanTidakBerlaku($tautan));
        }

        $ruang = $this->ruang($tautan);

        // ------------------------------------------------------------------
        // Jatah percobaan dibersihkan SESUDAH kredensialnya cocok
        // ------------------------------------------------------------------
        // Pembatasnya sendiri (`throttle:pintu-ruang`) sudah memeriksa lebih
        // dulu, sebelum sampai ke sini - dan urutan itu yang penting, karena
        // `Hash::check` (~100 ms) justru beban yang diserang.
        //
        // Yang dikerjakan di sini adalah kebalikannya: pengawas yang berhasil
        // TIDAK menghukum dirinya sendiri karena satu kali salah ketik. Tanpa
        // baris ini, kartu yang benar pada percobaan keempat tetap membuka
        // pintunya, tetapi percobaan kelima - misalnya karena menyerahkan
        // ponsel ke pengawas berikutnya - sudah tertutup.
        //
        // Dua kunci dibersihkan, bukan satu: yang per-pasangan dan yang
        // per-tautan, karena keduanya dihitung berdampingan.
        //
        // Sesudah pemeriksaan ruang dan jendela sesi, BUKAN sebelum: kode yang
        // benar untuk ruang yang sesinya sudah tutup tidak membuka apa pun,
        // dan jatah percobaan yang terhapus di situ akan membuat kode yang
        // sudah tidak berguna tetap bisa ditebak tanpa batas.
        if ($ruang === null || $ruang->session === null) {
            return back()->with('galat', 'Ruang ini belum dipakai sesi mana pun.');
        }

        $sesi = $ruang->session;
        $sekarang = now();

        if ($sekarang->lt($sesi->access_opens_at)) {
            return back()->with('galat', 'Sesi ini belum dibuka. Akses mulai pukul '.$sesi->access_opens_at->format('H:i').'.');
        }

        if ($sekarang->gt($sesi->hard_end_at)) {
            return back()->with('galat', 'Sesi ini sudah berakhir pukul '.$sesi->hard_end_at->format('H:i').'.');
        }

        PembatasLaju::bersihkan(PembatasLaju::PINTU, PembatasLaju::kunciPintuPasangan($slug, (string) $request->ip()));
        PembatasLaju::bersihkan(PembatasLaju::PINTU, PembatasLaju::kunciPintuTautan($slug));

        // ------------------------------------------------------------------
        // Hibah sesi peramban - BUKAN Auth::login
        // ------------------------------------------------------------------
        // Keputusan fase ini, dan alasannya bukan keamanan semata: mencatat
        // pemegang tautan sebagai akun berarti mengarang identitas. Kalau ia
        // di-login sebagai pengawas mana pun, setiap baris presensi yang
        // ditandatanganinya menjadi presensi atas nama orang itu - dan
        // laporan yang berbohong lebih buruk daripada laporan yang menyebut
        // ruangnya.
        //
        // Yang disimpan hanya DUA id. Bukan salinan status, bukan waktu
        // kedaluwarsa: keduanya dibaca ulang dari basis data di setiap
        // permintaan, dan itulah yang membuat pencabutan berlaku seketika.
        $request->session()->regenerate();

        $request->session()->put(KonteksRuang::KUNCI_SESI, [
            'exam_session_room_id' => $ruang->id,
            'room_id' => $tautan->room_id,
            'room_entry_link_id' => $tautan->id,
            'sejak' => now()->toIso8601String(),
        ]);

        $logger->log('Membuka pintu ruang', $ruang, [
            'slug' => $slug,
            'ruang' => $ruang->eventRoom?->room?->code,
            'sesi' => $sesi->label(),
            'perangkat' => substr((string) $request->userAgent(), 0, 200),
        ]);

        return redirect()->route('pengawas.dashboard');
    }

    /**
     * Lepaskan hibah sesi.
     *
     * Ada supaya pengawas yang menyerahkan ponselnya ke pengawas berikutnya
     * tidak menyerahkan ruangnya sekaligus. Tanpa ini, satu-satunya cara
     * keluar adalah menutup peramban - dan itu bukan instruksi yang bisa
     * ditulis di kartu pintu.
     */
    public function keluar(Request $request): RedirectResponse
    {
        KonteksRuang::lupakanTautan();

        return redirect()->route('login');
    }

    private function tautan(string $slug): RoomEntryLink
    {
        return RoomEntryLink::tanpaTenant()
            ->where('slug', $slug)
            ->firstOrFail();
    }

    /**
     * Ruang yang ditunjuk tautan - hanya bila tautannya masih berlaku.
     *
     * Dipanggil dua kali dengan sengaja: pada GET untuk menampilkan nama
     * ruang, dan pada POST untuk memutuskan buka/tidak. Yang kedua tidak
     * mempercayai hasil yang pertama, karena di antara keduanya bisa ada
     * pencabutan.
     */
    private function ruang(?RoomEntryLink $tautan): ?ExamSessionRoom
    {
        if ($tautan === null || ! $tautan->berlaku()) {
            return null;
        }

        return DB::transaction(fn () => ExamSessionRoom::tanpaTenant()
            ->with(['eventRoom.room:id,code,name,location', 'session.exam.event:id,name'])
            ->whereHas('eventRoom', fn ($q) => $q->where('room_id', $tautan->room_id))
            ->whereHas('session', fn ($q) => $q->whereIn('id', $this->sesiAktif()))
            ->orderByDesc('id')
            ->first());
    }

    /**
     * Sesi yang jendelanya sedang terbuka.
     *
     * Dipakai untuk memilih ruang-sesi yang benar ketika satu ruang dipakai
     * lebih dari satu sesi - keadaan normal, bukan kasus tepi: ruang yang
     * sama dipakai sesi pagi dan sesi siang pada hari yang sama.
     *
     * @return Collection<int, int>
     */
    private function sesiAktif(): Collection
    {
        return ExamSession::tanpaTenant()
            ->where('access_opens_at', '<=', now())
            ->where('hard_end_at', '>=', now())
            ->pluck('id');
    }

    private function alasanTidakBerlaku(RoomEntryLink $tautan): string
    {
        if ($tautan->revoked_at !== null) {
            return 'Tautan ini sudah dicabut Admin. Minta tautan baru di laman ruang.';
        }

        if ($tautan->expires_at !== null && $tautan->expires_at->isPast()) {
            return 'Tautan ini sudah kedaluwarsa pukul '.$tautan->expires_at->format('H:i').'. Minta tautan baru.';
        }

        return 'Tautan ini tidak berlaku lagi.';
    }

    /**
     * Tiga hal yang boleh dibocorkan halaman pintu - dan hanya tiga.
     *
     * Nama ruangnya, nama sekolahnya, dan nama sesinya. Tidak daftar
     * peserta, tidak token, tidak nama pengawas, tidak jumlah siswa: tautan
     * ini tertempel di daun pintu yang bisa difoto siapa pun di koridor.
     *
     * Bentuknya dikirim sebagai array, bukan model, supaya kolom lain tidak
     * ikut terbawa ke peramban hanya karena satu hari nanti modelnya
     * bertambah.
     *
     * @return array<string, mixed>
     */
    private function ringkas(ExamSessionRoom $ruang): array
    {
        return [
            'nama' => $ruang->eventRoom?->room?->name,
            'kode' => $ruang->eventRoom?->room?->code,
            'lokasi' => $ruang->eventRoom?->room?->location,
            'sesi' => $ruang->session?->label(),
            'ujian' => $ruang->session?->exam?->title,
            'jam' => $ruang->session === null
                ? null
                : $ruang->session->access_opens_at->format('H:i').'–'.$ruang->session->hard_end_at->format('H:i'),
        ];
    }
}
