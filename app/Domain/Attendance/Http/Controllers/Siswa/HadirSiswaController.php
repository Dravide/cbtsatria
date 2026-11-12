<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Attendance\Enums\StatusPengakuan;
use App\Domain\Attendance\Models\AttendanceAcknowledgement;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\KodePindai;
use App\Domain\Attendance\Support\HasilPindai;
use App\Domain\Attendance\Support\PenolakanPindai;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Laman pindai presensi siswa - wireframe-presensi-qr.html plate B4-B6.
 *
 * =====================================================================
 * SATU alamat untuk TIGA laman, dan itu disengaja
 * =====================================================================
 * `/hadir/{kode}` menampilkan pengakuan (B4), keadaan menunggu (B5), atau
 * penolakan (B6) - yang membedakannya keadaan, bukan URL-nya. Alasannya
 * sama dengan laman gerbang siswa: alamat ini adalah yang tertanam di QR,
 * dan QR yang sudah tercetak tidak bisa memilih alamat kedua. Laman tunggu
 * yang punya alamat sendiri akan menuntut QR memuat dua tautan.
 *
 * Karena itu "Muat ulang" di plate B5 tidak menuju ke mana pun - ia
 * memuat ulang alamat yang sama.
 *
 * =====================================================================
 * Login WAJIB, dan akun itulah yang menentukan siapa yang dicatat
 * =====================================================================
 * Kode pindai hanya menjawab "ruang dan jendela waktu mana". Yang menjawab
 * "siapa" adalah sesi login, dan karena itu kode yang difoto lalu dikirim
 * ke teman di rumah tidak berguna: teman itu tetap butuh akun siswa yang
 * benar-benar ditempatkan di ruang ini, dan akun seperti itu hanya satu.
 *
 * Rutenya memakai middleware `auth`, sehingga tamu dialihkan ke login lalu
 * DIKEMBALIKAN ke alamat ini - `redirect()->intended()` di LoginController
 * sudah menanganinya, dan itulah yang membuat QR bisa dipindai dari ponsel
 * yang belum masuk.
 *
 * =====================================================================
 * Memeriksa TIDAK menulis; menulis hanya saat tombol ditekan
 * =====================================================================
 * Laman ini terbuka oleh pindaian, dan siswa harus menekan satu tombol lagi
 * (plate B4). Pemisahan itu ada supaya ponsel yang dipinjam, atau laman
 * yang tertinggal terbuka di tangan orang lain, tidak menulis presensi atas
 * nama anak yang tidak ada di sana.
 */
class HadirSiswaController extends SiswaDasar
{
    public function __construct(private readonly KodePindai $pindai) {}

    /** Plate B4/B5/B6 - dipilih oleh keadaan, bukan oleh URL. */
    public function tampilkan(string $kode): Response
    {
        $siswa = $this->siswa();

        $hasil = $this->pindai->periksa($kode, $siswa);

        if (! $hasil->diterima()) {
            return $this->halamanTolak($hasil);
        }

        // Pengakuan yang sudah ada berarti tombolnya sudah pernah ditekan:
        // yang ditampilkan adalah keadaan menunggu, bukan tombol lagi -
        // plate B5.
        if ($hasil->pengakuan !== null) {
            return $this->halamanTunggu($hasil, $kode);
        }

        return $this->halamanAkui($hasil, $kode);
    }

    /**
     * Tombol "Saya hadir di ruang ini" - plate B4.
     *
     * =====================================================================
     * Toleransi dipakai di sini, dan HANYA di sini
     * =====================================================================
     * Kode berumur 60 detik, dan laman ini dibuka oleh pindaian itu sendiri.
     * Siswa yang memindai pada detik ke-55 membaca nama dan ruangnya dulu,
     * lalu menekan tombol pada detik ke-57 - kode sudah mati, padahal ia
     * tidak melakukan kesalahan apa pun. Satu umur kode penuh dipakai
     * sebagai toleransi; lihat `KodePindai::TOLERANSI_DETIK`.
     *
     * Yang disimpan tetap jam pindainya (`scanned_at`), sehingga
     * hadir/terlambat tidak terpengaruh sama sekali.
     */
    public function akui(string $kode): RedirectResponse
    {
        $siswa = $this->siswa();

        $hasil = $this->pindai->periksa($kode, $siswa, KodePindai::TOLERANSI_DETIK);

        if (! $hasil->diterima() || $hasil->kode === null || $hasil->peserta === null) {
            // Ditolak: kembali ke alamat yang sama, yang akan menampilkan
            // halaman penolakan. Bukan redirect dengan flash, supaya
            // kalimatnya tetap dihasilkan oleh pemeriksaan yang sama -
            // dua sumber kalimat untuk satu penolakan akan berbeda kata.
            return redirect()->route('siswa.hadir', ['kode' => $kode]);
        }

        $this->pindai->akui($hasil->kode, $hasil->peserta);

        return redirect()
            ->route('siswa.hadir', ['kode' => $kode])
            ->with('sukses', 'Pengakuan terkirim. Presensi dicatat setelah Pengawas mengonfirmasi.');
    }

    /* ------------------------------------------------------------- Laman */

    /** Plate B4 - pengakuan, sebelum tombolnya ditekan. */
    private function halamanAkui(HasilPindai $hasil, string $kode): Response
    {
        return Inertia::render('Siswa/Hadir', [
            'keadaan' => 'akui',
            'kode' => $kode,
            'ruang' => $this->ringkasRuang($hasil),
            'siswa' => $this->ringkasSiswa($hasil->peserta),
            'penolakan' => null,
            'tunggu' => null,
        ]);
    }

    /** Plate B5 - pengakuan terkirim, menunggu pengawas. */
    private function halamanTunggu(HasilPindai $hasil, string $kode): Response
    {
        $peserta = $hasil->peserta;
        /** @var AttendanceAcknowledgement $pengakuan */
        $pengakuan = $hasil->pengakuan;

        $record = $peserta === null ? null : AttendanceRecord::query()
            ->where('exam_participant_id', $peserta->id)
            ->first();

        return Inertia::render('Siswa/Hadir', [
            'keadaan' => 'tunggu',
            'kode' => $kode,
            'ruang' => $this->ringkasRuang($hasil),
            'siswa' => $this->ringkasSiswa($peserta),
            'penolakan' => null,

            'tunggu' => [
                'dikirim' => $pengakuan->scanned_at?->format('H:i'),
                'dikonfirmasi' => $pengakuan->status === StatusPengakuan::Dikonfirmasi,
                'dikonfirmasi_jam' => $pengakuan->confirmed_at?->format('H:i'),
                // Baris "Gerbang ujian" ada di plate B5 karena itulah yang
                // sebenarnya ditunggu siswa. Tanpa baris itu mereka akan
                // menekan "Mulai" berulang kali dan mengira tokennya salah.
                'gerbang' => $record?->attendance_status?->membukaGerbang(
                    (bool) $peserta?->exam?->allow_late_entry,
                ) ?? false,
                'status' => $record?->attendance_status?->pendek(),
                'peserta_id' => $peserta?->id,
            ],
        ]);
    }

    /** Plate B6 - empat penolakan. */
    private function halamanTolak(HasilPindai $hasil): Response
    {
        /** @var PenolakanPindai $tolak */
        $tolak = $hasil->penolakan;

        return Inertia::render('Siswa/Hadir', [
            'keadaan' => 'tolak',
            'kode' => null,
            'ruang' => $this->ringkasRuang($hasil),
            'siswa' => null,
            'penolakan' => [
                'judul' => $tolak->judul,
                'pesan' => $tolak->pesan,
                // Balasan yang menenangkan, bukan galat merah - lihat
                // catatan `PenolakanPindai`.
                'tenang' => $hasil->ditolakDenganTenang(),
            ],
            'tunggu' => null,
        ]);
    }

    /* ----------------------------------------------------------- Data */

    /** @return array<string, mixed> */
    private function ringkasRuang(HasilPindai $hasil): ?array
    {
        $ruang = $hasil->kode?->room;

        if ($ruang === null) {
            return null;
        }

        $sesi = $ruang->session;

        return [
            'kode' => $ruang->eventRoom?->room?->code,
            'nama' => $ruang->eventRoom?->room?->name,
            'lokasi' => $ruang->eventRoom?->room?->location,
            'sesi' => $sesi?->label(),
            'ujian' => $sesi?->exam?->title,
            'mapel' => $sesi?->exam?->subject?->name,
            'jam' => $sesi === null
                ? null
                : $sesi->access_opens_at?->format('H:i').'–'.$sesi->hard_end_at?->format('H:i'),
            'sekolah' => $ruang->school?->name,
        ];
    }

    /** @return array<string, mixed>|null */
    private function ringkasSiswa(?ExamParticipant $peserta): ?array
    {
        if ($peserta === null) {
            return null;
        }

        $peserta->loadMissing([
            'student:id,name',
            'student.studentProfile:id,user_id,nis',
            'pesertaEvent.kelas:id,name',
        ]);

        return [
            'nama' => $peserta->student?->name,
            'nis' => $peserta->student?->studentProfile?->nis,
            'kelas' => $peserta->pesertaEvent?->kelas?->name,
            'kursi' => $this->nomorKursi($peserta),
        ];
    }

    /**
     * Akun siswa yang sedang memindai, atau penolakan yang terbaca.
     *
     * Peran diperiksa di sini, bukan dengan middleware `siswa`: middleware
     * itu menjawab 403 "Konsol ini hanya untuk Siswa", dan pengawas yang
     * tidak sengaja memindai QR ruangnya sendiri akan menerima kalimat yang
     * tidak menjelaskan apa pun. Halaman penolakan yang sama menjelaskannya.
     */
    private function siswa(): User
    {
        /** @var User $pengguna */
        $pengguna = auth()->user();

        if ($pengguna->role !== Role::Student) {
            abort(403, 'Presensi ruang hanya untuk akun Siswa.');
        }

        return $pengguna;
    }
}
