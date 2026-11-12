<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Attendance\Services\KelayakanUjian;
use App\Domain\Attendance\Services\MesinUjian;
use App\Domain\Exam\Models\ExamParticipant;
use App\Support\PembatasLaju;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gerbang ujian - wireframe plate 20 dan 21.
 *
 * =====================================================================
 * DUA laman, satu alamat, dan itu disengaja
 * =====================================================================
 * Plate 20 menggambar penolakan dan plate 21 menggambar pintu masuk, dan
 * keduanya berbagi `/siswa/ujian/{peserta}/gerbang`. Yang TIDAK dipakai
 * adalah dua alamat berbeda, karena alamatnya adalah tautan yang dikirim
 * guru lewat pesan: "buka ini" harus bekerja baik saat anak belum
 * dipresensi maupun sesudahnya.
 *
 * ---------------------------------------------------------------------
 * Pemeriksaannya kelas yang SAMA dengan endpoint `mulai`
 * ---------------------------------------------------------------------
 * PRD §10.8 menutup dengan kalimat yang menentukan bentuk kelas ini:
 * "Validasi dilakukan kembali pada endpoint start exam." Karena itu laman
 * ini TIDAK memeriksa sendiri - ia memanggil `KelayakanUjian::periksa()`,
 * kelas yang juga dipanggil `MesinUjian::mulai()`. Laman yang memeriksa
 * dengan caranya sendiri akan berbeda dari server begitu salah satunya
 * ditambah syarat.
 *
 * ---------------------------------------------------------------------
 * Kalimatnya disalin apa adanya dari PRD §10.9
 * ---------------------------------------------------------------------
 * `Kelayakan::PESAN_BELUM_DIPRESENSI` adalah teks PRD, bukan karangan UI.
 * Tombol "Mulai ujian" tetap DILIHAT tetapi mati - plate 20 menulis
 * alasannya: "supaya siswa tahu bahwa yang kurang adalah verifikasi, bukan
 * haknya untuk ikut ujian."
 *
 * ---------------------------------------------------------------------
 * Menyegar sendiri 15 detik, dan itu bukan hiasan
 * ---------------------------------------------------------------------
 * Anak yang menunggu pengawas memverifikasi presensinya akan menekan
 * tombol itu berulang kali, dan setiap penekanan adalah satu permintaan.
 * Menyegarkan sendiri membuat laman itu berubah sendiri tepat pada detik
 * pengawas menekan verifikasi.
 */
class GerbangSiswaController extends SiswaDasar
{
    public function __construct(
        private readonly KelayakanUjian $kelayakan,
        private readonly MesinUjian $mesin,
    ) {}

    public function tampilkan(Request $request, ExamParticipant $peserta): Response
    {
        $peserta = $this->milikSaya($peserta);

        $peserta->loadMissing([
            'exam.subject:id,name',
            // `enforce_fullscreen` milik EVENT - lihat `aturan()` di bawah,
            // dan `KerjakanSiswaController` yang memakai prop yang sama.
            'exam.event:id,enforce_fullscreen,detect_split_screen,detect_float_window,show_watermark',
            'session',
            'room.eventRoom.room:id,code,name',
        ]);

        $layak = $this->kelayakan->periksa($peserta);
        $berjalan = $this->kelayakan->attemptBerjalan($peserta);

        // Sesi ini punya token AKTIF atau belum - dan sejak 2026-11-10
        // "belum" berarti pintunya TERTUTUP, bukan "ujian tanpa token".
        // Dibaca dari token aktif, bukan dari ada-tidaknya token yang pernah
        // terbit: token yang dicabut menghasilkan keadaan yang sama dengan
        // token yang belum pernah dibuat, dan kotak token yang tetap tampil
        // akan membuat anak mencari kode yang sudah tidak berlaku.
        //
        // Yang dipakai laman: `false` membuat kotak token dan tombol Mulai
        // mati, dan laman menyegar sendiri sampai tokennya terbit. Lihat
        // `resources/js/Pages/Siswa/Gerbang.tsx`.
        $sesi = $peserta->session;
        $berToken = $sesi !== null && $sesi->tokenAktif() !== null;

        return Inertia::render('Siswa/Gerbang', [
            'peserta' => $this->ringkasPeserta($peserta),

            'layak' => [
                'boleh' => $layak->boleh,
                'alasan' => $layak->alasan,
                'petunjuk' => $layak->petunjuk,
            ],

            'ber_token' => $berToken,

            // Siswa yang mencoba lagi setelah keluar karena sinyal putus
            // harus tahu bahwa jawabannya masih ada - jadi tombolnya berbunyi
            // "Lanjutkan", bukan "Mulai". Lihat KelayakanUjian::attemptBerjalan().
            'melanjutkan' => $berjalan !== null,
            'sisa_detik' => $berjalan !== null ? $this->mesin->sisaDetik($berjalan) : null,

            // Aturan main ditulis LENGKAP sebelum tombol mulai - plate 21.
            // Anak yang tahu aturannya sejak awal tidak dapat mengatakan ia
            // tidak diberi tahu, dan itulah yang membuat sanksi pelanggaran
            // bisa dibela.
            'aturan' => $this->aturan($peserta),

            // Apakah laman ini harus meminta layar penuh sebelum ujian
            // dimulai.
            //
            // `enforce_fullscreen` ada di event sejak Fase 3, tetapi sampai
            // sekarang ia hanya tersimpan: gerbang tidak memintanya, laman
            // pengerjaan tidak memeriksanya, dan tidak ada satu baris pun di
            // peramban yang membacanya. Artinya pilihannya ada di formulir
            // Admin dan tidak berakibat apa pun.
            //
            // Yang TIDAK dikirim adalah ambang pelanggaran - dan itu tetap
            // berlaku di sini: yang dibaca laman ini hanya satu boolean yang
            // mengubah tampilan, bukan aturan main.
            'wajib_layar_penuh' => $this->wajibLayarPenuh($peserta),
        ]);
    }

    /**
     * Apakah event menuntut layar penuh.
     *
     * Rantainya peserta -> ujian -> event, sama dengan `ExamAttempt::ambang()`.
     * Rantai yang putus jatuh ke `false`, bukan ke `true`: menuntut layar
     * penuh dari event yang tidak bisa dibaca berarti memblokir ujian karena
     * data yang hilang - dan ujian yang tidak bisa dimulai jauh lebih mahal
     * daripada ujian yang tidak memakai layar penuh.
     *
     * Bawaannya `false` juga karena alasan yang lebih besar: peramban hanya
     * mengizinkan `requestFullscreen()` dari klik pengguna, dan satu-satunya
     * klik yang tersedia di sini adalah klik "Mulai". Bila pilihannya mati
     * secara bawaan, satu sekolah yang belum mengaturnya tidak menemukan
     * tombol yang tiba-tiba meminta izin layar.
     */
    private function wajibLayarPenuh(ExamParticipant $peserta): bool
    {
        return (bool) ($peserta->exam?->event?->enforce_fullscreen ?? false);
    }

    /**
     * Penjaga layar yang dinyalakan event, atau semuanya mati bila rantainya
     * putus.
     *
     * Dua yang pertama tunduk pada layar penuh: tanpa layar penuh, larangan
     * membelah layar tidak punya dasar. Tanda air TIDAK - tangkapan layar
     * bisa diambil dari ujian yang membiarkan jendela dibuka, dan tanda air
     * yang hanya muncul pada sebagian ujian adalah tanda air yang bisa
     * dipakai untuk membuktikan ujian mana yang "aman".
     *
     * @return array{terbagi: bool, mengambang: bool, tanda_air: bool}
     */
    private function penjaga(ExamParticipant $peserta): array
    {
        $layarPenuh = $this->wajibLayarPenuh($peserta);
        $event = $peserta->exam?->event;

        return [
            'terbagi' => $layarPenuh && (bool) ($event?->detect_split_screen ?? false),
            'mengambang' => $layarPenuh && (bool) ($event?->detect_float_window ?? false),
            'tanda_air' => (bool) ($event?->show_watermark ?? false),
        ];
    }

    /**
     * Mulai atau lanjutkan - PRD §11.1.
     *
     * Menyegarkan halaman ini sendiri akan memanggil `tampilkan` lagi, dan
     * percobaan yang gagal kembali sebagai galat validasi di field
     * `gerbang`/`token` - bukan sebagai 500, karena `MesinUjian` melempar
     * ValidationException.
     */
    public function mulai(Request $request, ExamParticipant $peserta): RedirectResponse
    {
        $peserta = $this->milikSaya($peserta);

        $valid = $request->validate([
            'token' => ['nullable', 'string', 'max:12'],
        ], [], ['token' => 'token ujian']);

        try {
            $attempt = $this->mesin->mulai($peserta, $valid['token'] ?? null);
        } catch (ValidationException $e) {
            // Dikembalikan ke laman gerbang dengan pesannya, bukan dilempar:
            // siswa yang salah mengetik token harus bisa langsung mengetik
            // ulang, bukan menatap halaman galat.
            return back()->withErrors($e->errors())->withInput();
        }

        // ------------------------------------------------------------------
        // Jatah percobaan dibersihkan SESUDAH tokennya cocok
        // ------------------------------------------------------------------
        // Pembatasnya sendiri (`throttle:gerbang-ujian`) sudah memeriksa lebih
        // dulu, sebelum permintaan ini sampai ke `MesinUjian` - dan urutan itu
        // yang penting, karena yang diserang adalah biaya pemeriksaan token.
        //
        // Yang dikerjakan di sini kebalikannya: siswa yang berhasil TIDAK
        // membawa hitungannya ke ujian yang baru saja ia mulai. "Lanjutkan
        // ujian" juga melewati endpoint ini - anak yang ponselnya mati lalu
        // menyalakannya lagi - dan tanpa baris ini, siswa yang telah memakai
        // sebagian jatahnya karena salah baca papan tulis akan kehabisan
        // jatah pada pemulihan berikutnya, tepat saat ia paling perlu masuk.
        //
        // Hanya kunci PER SISWA yang dibersihkan. Plafon per-IP dibiarkan
        // berdiri: ia penjaga penyalahgunaan satu jaringan, dan satu siswa
        // yang berhasil tidak boleh menghapus hitungan seluruh sekolah.
        PembatasLaju::bersihkan(PembatasLaju::GERBANG, PembatasLaju::kunciGerbangSiswa((int) auth()->id()));

        return redirect()->route('siswa.kerjakan', ['peserta' => $peserta->id]);
    }

    /**
     * Aturan yang ditulis di plate 21, dengan angka yang benar-benar berlaku.
     *
     * Angka-angka itu datang dari ujian dan sesinya, bukan dari teks tetap -
     * dan itu yang membedakannya dari hiasan: ujian 60 menit yang menulis
     * "durasi 90 menit" adalah janji yang tidak ditepati, dan anak yang
     * merencanakan waktunya berdasarkan janji itu.
     *
     * @return array<int, string>
     */
    private function aturan(ExamParticipant $peserta): array
    {
        $sesi = $peserta->session;
        $durasi = $sesi?->durasiMenit() ?? 0;

        $aturan = [
            'Durasi '.$durasi.' menit, dihitung server sejak Anda menekan Mulai.',
            'Jawaban tersimpan otomatis; Anda tidak perlu menekan Simpan.',
        ];

        if ($peserta->exam?->allow_back_navigation === false) {
            $aturan[] = 'Anda tidak dapat kembali ke soal sebelumnya.';
        } else {
            $aturan[] = 'Anda boleh mundur ke soal sebelumnya selama waktu tersisa.';
        }

        $aturan[] = 'Klik kanan, salin, dan keluar dari layar ujian dipantau.';
        $aturan[] = 'Waktu berjalan terus meski Anda keluar dari halaman ini.';

        // Ditulis SEBELUM tombol ditekan, karena sesudahnya peramban akan
        // meminta izin layar penuh dan anak yang tidak tahu sebabnya akan
        // menolaknya - dan penolakan itu bukan pelanggaran, hanya ujian yang
        // tidak memakai layar penuh.
        if ($this->wajibLayarPenuh($peserta)) {
            $aturan[] = 'Ujian ini berjalan dalam layar penuh. Jangan keluar dari layar penuh '
                .'dan jangan membuka jendela lain di atas soal.';
        }

        // Penjaga yang dinyalakan event disebut satu per satu, dan yang mati
        // TIDAK disebut. Kalimat "layar terbagi dipantau" pada ujian yang
        // memaafkannya adalah ancaman kosong - dan ancaman kosong adalah
        // alasan yang paling mudah dipakai membantah sanksi yang sungguh
        // dijatuhkan.
        $penjaga = $this->penjaga($peserta);

        if ($penjaga['terbagi']) {
            $aturan[] = 'Layar yang terbagi (split screen) dipantau dan dicatat.';
        }

        if ($penjaga['mengambang']) {
            $aturan[] = 'Jendela yang mengambang di atas soal dipantau dan dicatat.';
        }

        if ($penjaga['tanda_air']) {
            $aturan[] = 'Nama dan nomor induk Anda tercetak samar di atas soal, sebagai penanda '
                .'bila jawaban atau soal ini berpindah tangan.';
        }

        return $aturan;
    }
}
