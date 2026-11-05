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
            'session',
            'room.eventRoom.room:id,code,name',
        ]);

        $layak = $this->kelayakan->periksa($peserta);
        $berjalan = $this->kelayakan->attemptBerjalan($peserta);

        // Sesi ini dijaga token atau tidak - dibaca dari token AKTIF, bukan
        // dari ada-tidaknya token yang pernah terbit. Token yang dicabut
        // berarti sesinya kembali tidak ber-token, dan gerbang yang tetap
        // menampilkan kotak tokennya akan membuat anak mencari kode yang
        // sudah tidak berlaku.
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
        ]);
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

        return $aturan;
    }
}
