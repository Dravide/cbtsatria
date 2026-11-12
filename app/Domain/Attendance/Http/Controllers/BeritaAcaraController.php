<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Enums\PeranTandaTangan;
use App\Domain\Attendance\Enums\SisiFoto;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\SessionReport;
use App\Domain\Attendance\Models\SessionReportSignature;
use App\Domain\Attendance\Services\InsidenService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Attendance\Services\LaporanSesiService;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Berita acara ruang ujian - wireframe plate 18.
 *
 * =====================================================================
 * Admin boleh MEMBACA, tidak boleh MENANDATANGANI
 * =====================================================================
 * Blueprint `:962` menyatakannya sebagai kalimat, dan kalimat itu punya
 * akibat teknis yang harus ditegakkan di sini: "tanda tangan atas ruang yang
 * tidak ia masuki adalah keterangan palsu."
 *
 * Karena itu `tandaTangan()` menuntut pelakunya memenuhi `KonteksRuang`
 * sebagai PENGAWAS - akun berpenugasan pada ruang itu, atau pemegang
 * tautannya. Admin memang memenuhi `KonteksRuang` di seluruh laman lain
 * (PRD §3.5 memberinya kolom "Presensi: monitoring/koreksi"), tetapi pada
 * ruang yang tidak pernah ia masuki, namanya di bawah tanda tangan bukan
 * keterangan yang bisa dipertanggungjawabkan.
 *
 * Ketiga aksi lainnya - index, unggahFoto, kirim - tetap terbuka bagi Admin:
 * Admin yang menyelesaikan berita acara ruang yang pengawasnya sakit adalah
 * Admin yang menolong, bukan yang memalsukan. Yang tidak boleh ia lakukan
 * hanyalah mengaku berada di sana.
 *
 * ---------------------------------------------------------------------
 * Draf tersimpan otomatis, dan itu sebabnya tidak ada aksi "simpan"
 * ---------------------------------------------------------------------
 * Lihat catatan panjang di LaporanSesiService. Dari sisi controller,
 * konsekuensinya satu: setiap unggahan dan setiap tanda tangan menulis
 * langsung tanpa memeriksa apa pun lebih dulu selain izin - dan tidak ada
 * satu pun jalur di sini yang menghapus draf.
 */
class BeritaAcaraController
{
    public function __construct(
        private readonly LaporanSesiService $laporan,
        private readonly InsidenService $insiden,
    ) {}

    /** Plate 18: seluruh isi berita acara satu ruang-sesi. */
    public function index(ExamSessionRoom $ruang): Response
    {
        $konteks = KonteksRuang::dari($ruang);

        // Laporan dibuat saat lamannya DIBUKA, bukan saat sesuatu diunggah.
        // Bedanya berguna di hari-H: Admin yang menunggu laporan bisa melihat
        // ruang mana yang pengawasnya sudah membuka lamannya, bahkan bila
        // belum ada yang diunggah.
        $laporan = $this->laporan->untuk($ruang);

        return Inertia::render('Pengawas/BeritaAcara', [
            'ruang' => [
                'id' => $ruang->id,
                'kode' => $konteks->kodeRuang(),
                'nama' => $ruang->eventRoom?->room?->name,
                'lokasi' => $ruang->eventRoom?->room?->location,
                'sesi' => $ruang->session?->label(),
                'ujian' => $ruang->session?->exam?->title,
            ],
            'pelaku' => $konteks->jenisPelaku(),

            // Admin boleh membaca, jadi ia boleh membuka lamannya. Yang
            // dikirim ke UI adalah ATAU boleh menandatangani - supaya
            // tombolnya tidak pernah muncul bagi orang yang permintaannya
            // pasti ditolak.
            'boleh_ttd' => $konteks->jenisPelaku() !== 'admin',

            'angka' => $this->angka($ruang),

            'laporan' => [
                'id' => $laporan->id,
                'status' => $laporan->status,
                'final' => $laporan->final(),
                'catatan' => $laporan->catatan,
                'pernyataan_presensi' => $laporan->pernyataan_presensi,
                'pernyataan_keluar' => $laporan->pernyataan_keluar,
                'dikirim_pada' => $laporan->submitted_at?->format('d M Y H:i'),
                'pelaku_label' => $laporan->pelaku_label,
                'kurang' => $laporan->kekuranganFinal(),
                'boleh_final' => $laporan->bolehFinal(),
            ],

            // Satu foto, satu tanda tangan - dan kotaknya tetap dikirim
            // meski masih kosong. "Ambil foto keadaan ruang" adalah kotak
            // yang harus DILIHAT pengawas, bukan kotak yang muncul setelah
            // ia mengingatnya sendiri.
            //
            // `SisiFoto` masih punya dua sisi karena baris lama di disk bisa
            // saja memakai `sebelum`, dan `kekuranganFinal()` tetap menuntut
            // sisi yang sama (`sesudah`). Yang menyusut di sini hanyalah
            // yang DITAWARKAN lamannya.
            'foto' => [$this->kartuFoto($laporan, SisiFoto::Sesudah)],

            // Hanya pengawas ruang. Panitia menandatangani di luar aplikasi,
            // dan peran yang ditawarkan tetapi tidak pernah dipakai hanya
            // menambah satu kotak kosong di laman ponsel.
            'tanda_tangan' => [$this->kartuTandaTangan($laporan, PeranTandaTangan::Pengawas)],
        ]);
    }

    /**
     * Unggah satu sisi foto.
     *
     * `diambil_pada` diterima dari peramban sebagai waktu modifikasi berkas
     * (`File.lastModified`), dan itu satu-satunya alasan kolomnya dipisah
     * dari `diunggah_pada`. Keduanya dipakai plate 18 untuk membedakan foto
     * yang diambil di ruang ujian dari foto lama yang diunggah sebagai bukti.
     */
    public function unggahFoto(ExamSessionRoom $ruang, Request $request): RedirectResponse
    {
        $konteks = KonteksRuang::dari($ruang);
        $laporan = $this->laporan->untuk($ruang);

        $valid = $request->validate([
            'sisi' => ['required', 'string'],
            'foto' => ['required', 'file', 'image', 'max:'.LaporanSesiService::MAKS_KB],
            'diambil_pada' => ['nullable', 'integer'],
        ], [
            'foto.required' => 'Pilih atau ambil fotonya lebih dulu.',
            'foto.image' => 'Berkas harus berupa gambar (JPG, PNG, atau WEBP).',
            'foto.max' => 'Foto terlalu besar. Maksimum 5 MB.',
        ], ['sisi' => 'sisi foto', 'foto' => 'foto']);

        $sisi = SisiFoto::tryFrom($valid['sisi']);

        if ($sisi === null) {
            return back()->with('galat', 'Sisi foto itu tidak dikenal.');
        }

        if ($sisi !== SisiFoto::Sesudah) {
            return back()->with('galat', 'Berita acara ini hanya meminta satu foto: keadaan ruang saat ujian berlangsung.');
        }

        try {
            $this->laporan->simpanFoto(
                $laporan,
                $sisi,
                $request->file('foto'),
                $valid['diambil_pada'] ?? null,
            );
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Foto '.$sisi->label().' tersimpan sebagai draf.');
    }

    /**
     * Simpan tanda tangan dari kanvas ponsel.
     *
     * Kanvas mengirim data URL PNG, bukan berkas. Bentuk itulah yang dipakai
     * karena tanda tangan digambar dengan jari di layar: tidak ada berkas
     * yang pernah ada, dan memaksa satu muncul berarti menyuruh pengawas
     * mengunggah gambar tanda tangannya dari galeri.
     */
    public function tandaTangan(ExamSessionRoom $ruang, Request $request): RedirectResponse
    {
        $konteks = KonteksRuang::dari($ruang);

        // Lihat catatan kelas ini: tanda tangan atas ruang yang tidak pernah
        // dimasuki adalah keterangan palsu, dan Admin tidak pernah ada di
        // ruang mana pun.
        if ($konteks->jenisPelaku() === 'admin') {
            return back()->with(
                'galat',
                'Admin tidak menandatangani berita acara. Tanda tangan harus datang dari pengawas ruangnya.',
            );
        }

        $laporan = $this->laporan->untuk($ruang);

        $valid = $request->validate([
            'peran' => ['required', 'string'],
            'nama' => ['required', 'string', 'min:3', 'max:120'],
            'tanda_tangan' => ['required', 'string'],
        ], [
            'nama.required' => 'Nama penanda tangan wajib diisi.',
            'nama.min' => 'Nama penanda tangan terlalu pendek.',
            'tanda_tangan.required' => 'Gambar tanda tangannya lebih dulu.',
        ], [
            'peran' => 'peran penanda tangan',
            'nama' => 'nama penanda tangan',
            'tanda_tangan' => 'tanda tangan',
        ]);

        $peran = PeranTandaTangan::tryFrom($valid['peran']);

        if ($peran === null) {
            return back()->with('galat', 'Peran penanda tangan itu tidak dikenal.');
        }

        // Hanya pengawas ruang. Panitia menandatangani di luar aplikasi -
        // lihat `PeranTandaTangan::wajib()` - dan menerimanya di sini berarti
        // menerima tanda tangan yang tidak pernah dituntut dan tidak pernah
        // dibaca siapa pun.
        if ($peran !== PeranTandaTangan::Pengawas) {
            return back()->with('galat', 'Berita acara ini hanya ditandatangani pengawas ruang.');
        }

        try {
            $this->laporan->simpanTandaTangan(
                $laporan,
                $peran,
                $valid['nama'],
                $valid['tanda_tangan'],
                $konteks,
            );
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Tanda tangan '.$peran->label().' tersimpan.');
    }

    /**
     * Simpan catatan dan kedua pernyataan.
     *
     * Aksi tersendiri, meski drafnya tersimpan otomatis: kotak centang dan
     * catatan tidak punya peristiwa yang bisa memicunya. Foto punya tombol
     * unggah, tanda tangan punya tombol simpan di kanvas - dua kotak
     * pernyataan tidak punya apa pun kecuali satu tombol.
     */
    public function simpanCatatan(ExamSessionRoom $ruang, Request $request): RedirectResponse
    {
        KonteksRuang::dari($ruang);
        $laporan = $this->laporan->untuk($ruang);

        $valid = $request->validate([
            'catatan' => ['nullable', 'string', 'max:2000'],
            'pernyataan_presensi' => ['boolean'],
            'pernyataan_keluar' => ['boolean'],
        ], [], ['catatan' => 'catatan']);

        try {
            $this->laporan->simpanCatatan(
                $laporan,
                $valid['catatan'] ?? null,
                (bool) ($valid['pernyataan_presensi'] ?? false),
                (bool) ($valid['pernyataan_keluar'] ?? false),
            );
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Catatan tersimpan.');
    }

    /**
     * Kirim final.
     *
     * Penolakannya menyebut APA yang kurang, dan daftar itu berasal dari
     * `SessionReport::kekuranganFinal()` - bukan disusun ulang di sini.
     * Kalau aturannya hidup di dua tempat, suatu hari keduanya akan berbeda,
     * dan yang berbeda akan berupa laporan yang dikirim padahal kurang.
     */
    public function kirim(ExamSessionRoom $ruang): RedirectResponse
    {
        $konteks = KonteksRuang::dari($ruang);
        $laporan = $this->laporan->untuk($ruang);

        try {
            $this->laporan->kirim($laporan, $konteks);
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Berita acara dikirim.');
    }

    /**
     * Buka kembali laporan yang sudah final.
     *
     * Ada secara sengaja: berita acara yang salah tulis dan tidak bisa
     * diperbaiki akan memaksa pengawas mengarang laporan baru di atas
     * kertas. Yang dijaga bukan ketidakbisaan mengubahnya, melainkan
     * tercatatnya perubahan itu - statusnya kembali `draft`, dan
     * `submitted_at`-nya dikosongkan sehingga riwayat "kapan dikirim"
     * berpindah ke pengiriman yang sebenarnya.
     */
    public function bukaKembali(ExamSessionRoom $ruang): RedirectResponse
    {
        KonteksRuang::dari($ruang);

        $this->laporan->bukaKembali($this->laporan->untuk($ruang));

        return back()->with('sukses', 'Berita acara dibuka kembali sebagai draf.');
    }

    /* ------------------------------------------------------------- Pembantu */

    /**
     * Satu kartu foto berita acara.
     *
     * @return array<string, mixed>
     */
    private function kartuFoto(SessionReport $laporan, SisiFoto $sisi): array
    {
        $berkas = $laporan->berkas->firstWhere('sisi', $sisi);

        return [
            'sisi' => $sisi->value,
            'label' => $sisi->label(),
            'petunjuk' => $sisi->petunjuk(),
            'ada' => $berkas !== null,
            'url' => $berkas === null ? null : route('laporan.foto', $berkas),
            'diambil_pada' => $berkas?->diambil_pada?->format('H:i'),
            'diunggah_pada' => $berkas?->diunggah_pada?->format('d M H:i'),
            'ukuran' => $berkas?->ukuranTerbaca(),
            'nama_asli' => $berkas?->nama_asli,
            'terlambat' => $berkas !== null
                && $berkas->diambil_pada !== null
                && $berkas->diunggah_pada !== null
                && $berkas->diunggah_pada->diffInHours($berkas->diambil_pada) > 2,
        ];
    }

    /**
     * Satu kartu tanda tangan berita acara.
     *
     * @return array<string, mixed>
     */
    private function kartuTandaTangan(SessionReport $laporan, PeranTandaTangan $peran): array
    {
        $ttd = $laporan->tandaTangan->first(
            fn (SessionReportSignature $t) => $t->peran === $peran,
        );

        return [
            'peran' => $peran->value,
            'label' => $peran->label(),
            'wajib' => $peran->wajib(),
            'ada' => $ttd !== null,
            'nama' => $ttd?->nama,
            'oleh' => $ttd?->pelaku_label,
            'jam' => $ttd?->ditandatangani_pada?->format('H:i'),
            'url' => $ttd === null ? null : route('laporan.ttd', $ttd),
        ];
    }

    /**
     * Angka kepala plate 18: hadir, terlambat, tidak hadir, insiden.
     *
     * Dihitung dari baris presensi ruangnya, bukan dari `KehadiranService::rekap()`
     * - karena rekap itu juga membawa `izin` dan `pending`, sementara plate 18
     * meminta empat angka, dan angka kelima di kepala laman ini akan membuat
     * empat yang pertama berhenti bisa dibaca sekilas.
     *
     * @return array<string, int>
     */
    private function angka(ExamSessionRoom $ruang): array
    {
        $peserta = ExamParticipant::query()
            ->where('exam_session_room_id', $ruang->id)
            ->pluck('id');

        $records = AttendanceRecord::query()
            ->whereIn('exam_participant_id', $peserta)
            ->get()
            ->groupBy(fn (AttendanceRecord $r) => $r->attendance_status->value);

        return [
            'peserta' => $peserta->count(),
            'hadir' => $records->get(StatusPresensi::Hadir->value, collect())->count(),
            'terlambat' => $records->get(StatusPresensi::Terlambat->value, collect())->count(),
            'tidak_hadir' => $records->get(StatusPresensi::TidakHadir->value, collect())->count(),
            'insiden' => $this->insiden->daftarRuang($ruang)->count(),
        ];
    }
}
