<?php

namespace App\Domain\Attendance\Http\Middleware;

use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Facility\Models\RoomEntryLink;
use App\Domain\Tenancy\Services\SchoolResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penjaga portal pengawas - dua pintu, satu middleware.
 *
 * ---------------------------------------------------------------------
 * Mengapa bukan middleware('auth')
 * ---------------------------------------------------------------------
 * Pemegang tautan ruang TIDAK PUNYA AKUN. Itu bukan kekurangan sementara
 * yang kelak diperbaiki; itu bentuk pintunya: pengawas berdiri di depan
 * pintu kelas, membuka tautan di ponselnya, lalu mengetik enam angka. Tidak
 * ada username, tidak ada password, dan sengaja begitu - menambahkannya
 * berarti setiap pengawas harus punya akun sebelum bisa mengawas.
 *
 * Karena itu middleware ini meloloskan permintaan bila SALAH SATU benar:
 *
 *   - ada kunci sesi `pengawas_ruang` yang MASIH SAH, dan barisnya masih
 *     menunjuk ruang yang diminta rutenya; atau
 *   - ada User berperan Pengawas dengan penugasan pada ruang itu, atau Admin.
 *
 * ---------------------------------------------------------------------
 * {ruang} di-resolve ULANG di sini, setelah konteks tenant ditetapkan
 * ---------------------------------------------------------------------
 * SubstituteBindings berjalan di dalam grup `web` - jadi SEBELUM middleware
 * ini - dan ia membaca SchoolScope. Pada permintaan ber-tautan belum ada
 * user, sehingga `SchoolResolver::id()` mengembalikan null, dan null BUKAN
 * "tidak ada sekolah" melainkan "jangan saring": SchoolScope menjadi no-op.
 *
 * Urutan yang benar karena itu:
 *
 *   1. middleware menetapkan konteks tenant lebih dulu - dari akun, atau
 *      dari baris room_entry_links bagi pemegang tautan;
 *   2. BARU SESUDAH ITU ruangnya dipastikan berada di sekolah itu.
 *
 * Membalik kedua langkah menghasilkan kebocoran yang tidak tampak sebagai
 * galat: lamannya terbuka, dan isinya milik sekolah lain.
 *
 * ---------------------------------------------------------------------
 * Pemeriksaan ulang tiap permintaan - inilah seluruh cerita pencabutan
 * ---------------------------------------------------------------------
 * Kunci sesi hanya menyebut BARIS MANA yang harus diperiksa; ia tidak pernah
 * menjadi bukti bahwa barisnya masih berlaku. Karena pemeriksaannya diulang
 * di setiap permintaan, `POST /admin/ruang/{id}/cabut` memutus sesi yang
 * SEDANG berjalan pada permintaan berikutnya - tanpa daftar sesi yang harus
 * disapu, tanpa penjadwal, dan tanpa cache yang bisa basi.
 *
 * Kegagalan mana pun melupakan kunci sesinya lalu mengembalikan ke halaman
 * pintu dengan pesan - BUKAN 403. Yang gagal bukan izinnya, melainkan
 * tiketnya.
 */
class AksesPengawas
{
    public function __construct(private readonly SchoolResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $pengguna = Auth::user();
        $tautan = KonteksRuang::tautanSesi();

        // ---- Pintu pertama: tautan ruang ----
        if ($tautan !== null) {
            return $this->lewatTautan($request, $next, $tautan);
        }

        // ---- Pintu kedua: akun ----
        if ($pengguna === null) {
            return redirect()->route('login');
        }

        // Konteks tenant sudah benar sendiri lewat akun - namun resolver
        // bisa saja sempat menyimpan null bila ada kode yang membacanya
        // sebelum autentikasi dihidrasi. Menyetelnya ulang tidak berbahaya
        // dan menutup satu urutan yang sulit ditemukan.
        if ($pengguna->school_id !== null) {
            $this->resolver->tetapkan((int) $pengguna->school_id);
        }

        $ruang = $this->resolusiRuang($request);

        if ($ruang === null) {
            return $next($request);
        }

        // Admin: seluruh ruang di sekolahnya. Pengawas: HANYA yang ditugaskan
        // kepadanya - diperiksa lewat penugasan itu sendiri, sehingga ruang
        // orang lain tampak tidak ada (404), bukan terlarang (403).
        if (! $pengguna->role->isAdmin()) {
            $ditugaskan = $ruang->supervisors()
                ->where('supervisor_id', $pengguna->id)
                ->exists();

            if (! $ditugaskan) {
                abort(404);
            }
        }

        return $next($request);
    }

    private function lewatTautan(Request $request, Closure $next, RoomEntryLink $tautan): Response
    {
        // (1) Konteks tenant DITETAPKAN dari kredensial, bukan dari akun, dan
        //     bukan dari parameter permintaan mana pun.
        $this->resolver->tetapkan((int) $tautan->school_id);

        $ruang = $this->resolusiRuang($request);

        if ($ruang === null) {
            return $next($request);
        }

        // (3) Kredensial ini menunjuk ruang MANA. Tautan R-01 tidak pernah
        //     membuka R-02, meski keduanya milik sekolah yang sama - dan
        //     inilah yang membuat "kode ruang lain ditolak meski sah"
        //     menjadi 404, bukan halaman yang terbuka.
        if ((int) $tautan->room_id !== (int) $ruang->eventRoom?->room_id) {
            KonteksRuang::lupakanTautan();

            return redirect()->route('pintu.ruang', $tautan->slug)
                ->with('galat', 'Tautan ini bukan untuk ruang tersebut.');
        }

        // (4) Tidak ada kredensial yang hidup lebih lama daripada sesinya.
        $sesi = $ruang->session;
        $sekarang = now();

        $lewatJendela = $sesi === null
            || $sekarang->lt($sesi->access_opens_at)
            || $sekarang->gt($sesi->hard_end_at)
            || in_array($sesi->status, [StatusSesi::Selesai, StatusSesi::Batal], true);

        if ($lewatJendela) {
            KonteksRuang::lupakanTautan();

            return redirect()->route('pintu.ruang', $tautan->slug)
                ->with('galat', 'Sesi ruang ini sudah berakhir.');
        }

        return $next($request);
    }

    /**
     * Ruang-sesi yang disebut rute, dipastikan berada di sekolah yang aktif.
     *
     * ---------------------------------------------------------------------
     * Mengapa hasil binding DIPERIKSA ULANG, bukan dipercaya
     * ---------------------------------------------------------------------
     * SubstituteBindings berjalan di dalam grup `web`, jadi ia SELESAI
     * sebelum middleware ini. Pada permintaan ber-akun itu tidak masalah:
     * SchoolResolver sudah membaca user dari sesi, sehingga SchoolScope
     * menyaring seperti biasa.
     *
     * Pada permintaan ber-tautan, saat binding berjalan belum ada user dan
     * resolver mengembalikan null - dan null berarti "jangan saring".
     * Binding karena itu bisa menghasilkan ruang milik SEKOLAH LAIN, dan
     * kebocoran seperti itu tidak tampak sebagai galat: lamannya terbuka.
     *
     * Karena itu school_id-nya diperiksa ulang di sini, setelah konteks
     * tenant ditetapkan. Satu pemeriksaan, satu tempat, dan test-nya
     * membuktikan hasilnya 404 - bukan halaman yang berisi data orang lain.
     */
    private function resolusiRuang(Request $request): ?ExamSessionRoom
    {
        $nilai = $request->route('ruang');

        if ($nilai === null) {
            return null;
        }

        $ruang = $nilai instanceof ExamSessionRoom
            ? $nilai
            : ExamSessionRoom::query()->find($nilai);

        if ($ruang === null) {
            abort(404);
        }

        if ($this->resolver->id() !== null && (int) $ruang->school_id !== $this->resolver->id()) {
            abort(404);
        }

        if (! $ruang->relationLoaded('session')) {
            $ruang->load(['session.exam.event', 'eventRoom.room:id,code,name,location']);
        }

        return $ruang;
    }
}
