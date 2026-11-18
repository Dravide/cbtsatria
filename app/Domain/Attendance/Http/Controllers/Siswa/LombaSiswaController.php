<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Attendance\Models\LeisureGameScore;
use App\Domain\Attendance\Models\LeisureMatch;
use App\Domain\Attendance\Services\JendelaLuang;
use App\Domain\Attendance\Services\KuotaPermainan;
use App\Domain\Attendance\Services\LombaLuang;
use App\Domain\Attendance\Services\PapanPeringkatLuang;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lomba hitung cepat dua pemain - undangan lewat kode.
 *
 * =====================================================================
 * Satu alamat, tiga keadaan
 * =====================================================================
 *   GET  /siswa/luang/lomba          ruang tunggu, lomba berjalan, hasil
 *   POST /siswa/luang/lomba          membuat undangan baru
 *   POST /siswa/luang/lomba/masuk    menerima undangan lewat kode
 *   GET  /siswa/luang/lomba/{kode}/keadaan  denyut keadaan (JSON)
 *   POST /siswa/luang/lomba/{kode}/skor     kiriman skor satu pemain
 *   POST /siswa/luang/lomba/{kode}/batal    membatalkan
 *
 * GET pertama adalah SATU halaman untuk seluruh daur hidup lomba, sama
 * seperti gerbang ujian: yang membedakan tampilannya keadaan lombanya, bukan
 * alamatnya. Alamat yang berbeda per fase berarti anak yang membuka ulang
 * lamannya saat lomba masih berjalan akan mendarat di halaman yang salah.
 *
 * =====================================================================
 * Jendela dan kuota diperiksa di SETIAP pintu
 * =====================================================================
 * Lomba tidak menambah aturan baru ke `JendelaLuang` dan `KuotaPermainan`:
 * keduanya tetap berlaku, diperiksa di sini. Anak yang masuk lomba lalu
 * sesi ujiannya dibuka harus keluar - dan yang memutuskan itu adalah
 * jawaban server, bukan jam di perambannya.
 *
 * Akibatnya yang perlu disebut: lomba bisa berhenti di tengah jalan karena
 * salah satu pemain masuk ujian. Itu bukan cacat, itu aturannya - lihat
 * `alasanBatalUntuk()` tentang apa yang terjadi pada skornya.
 *
 * =====================================================================
 * Enggan membuat tabel lomba tumbuh tanpa batas
 * =====================================================================
 * Undangan yang tidak dipakai kedaluwarsa setelah `BERLAKU_MENIT`, tetapi
 * barisnya tetap ada - ia tidak dihapus, dan itu disengaja: Admin yang
 * bertanya "mengapa anak saya tidak bisa masuk" perlu melihat bahwa
 * undangannya memang sudah mati. Yang membuatnya tidak menumpuk adalah
 * `code` yang dibuat hanya saat ada yang menekan tombol; lomba tanpa pemain
 * berjumlah sebanyak tombol yang ditekan, bukan sebanyak waktu yang lewat.
 */
class LombaSiswaController extends SiswaDasar
{
    public function __construct(
        private readonly LombaLuang $lomba,
        private readonly JendelaLuang $jendela,
        private readonly KuotaPermainan $kuota,
        private readonly PapanPeringkatLuang $papan,
    ) {}

    /**
     * Ruang lomba: membuat, menunggu, bermain, dan hasil - satu laman.
     *
     * `{kode}` boleh kosong: tanpa kode, lamannya adalah ruang tunggu yang
     * menampilkan tombol "Buat undangan" dan isian kode.
     */
    public function tampilkan(Request $request, ?string $kode = null): Response|RedirectResponse
    {
        $siswa = $request->user();
        $penempatan = $this->penempatan($siswa->id);
        $jendela = $this->jendela->untukSiswa($penempatan, now());

        // Pintunya tertutup selama anak itu mengerjakan ujian - sama persis
        // dengan `/siswa/luang`. Alihannya menyebut sebabnya, bukan diam.
        if ($jendela === null) {
            return redirect()->route('siswa.dashboard')
                ->with('galat', 'Selesaikan atau kumpulkan ujian Anda dulu. Permainan terbuka kembali setelah itu.');
        }

        $lomba = $kode === null ? null : $this->cariLomba($siswa, $kode);

        // Kode yang tidak menunjuk lomba milik anak ini dijawab 404, bukan
        // laman kosong: kode yang salah ketik dan kode yang sudah dipakai
        // orang lain harus terlihat sama, dan laman kosong akan membuat anak
        // mengira kodenya benar tetapi lombanya rusak.
        if ($kode !== null && $lomba === null) {
            abort(404);
        }

        return Inertia::render('Siswa/Lomba', [
            'jendela' => $jendela,
            'lomba' => $lomba === null ? null : $this->potret($lomba, $siswa),
            'kuota' => $this->kuota->hariIni($siswa->id, now()),
            'rekor' => $this->papan->rekorSaya($siswa, LeisureGameScore::GAME_HITUNG),
            'sekarang' => now()->toIso8601String(),
        ]);
    }

    /**
     * Membuat undangan baru, lalu mengalihkan ke ruang tunggunya.
     *
     * POST + redirect, bukan JSON: pembuatnya menekan tombol di laman, dan
     * yang ia butuhkan adalah alamat lomba yang bisa ia muat ulang atau
     * kirimkan ke temannya.
     */
    public function buat(Request $request): RedirectResponse
    {
        $siswa = $request->user();
        $penempatan = $this->penempatan($siswa->id);

        if ($this->jendela->untukSiswa($penempatan, now()) === null) {
            return back()->with('galat', 'Anda sedang mengerjakan ujian. Lomba tidak bisa dibuat sekarang.');
        }

        // Kuota diperiksa SEBELUM undangan dibuat. Undangan yang lahir lalu
        // tidak bisa dimainkan karena kuotanya habis adalah undangan yang
        // membuat temannya mengetik kode untuk sebuah laman yang menolak.
        if (! $this->kuota->hariIni($siswa->id, now())['boleh']) {
            return back()->with('galat', 'Kuota main hari ini sudah habis. Coba lagi besok.');
        }

        $lomba = $this->lomba->buat($siswa, now());

        return redirect()->route('siswa.luang.lomba.ruang', ['kode' => $lomba->code]);
    }

    /**
     * Menerima undangan lewat kode.
     *
     * Kode yang tidak sah dijawab SATU kalimat, apa pun sebabnya - lihat
     * `LombaLuang::masuk()`.
     */
    public function masuk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kode' => ['required', 'string', 'size:6'],
        ], [], ['kode' => 'kode undangan']);

        $siswa = $request->user();
        $penempatan = $this->penempatan($siswa->id);

        if ($this->jendela->untukSiswa($penempatan, now()) === null) {
            return back()->with('galat', 'Anda sedang mengerjakan ujian. Lomba tidak bisa dimasuki sekarang.');
        }

        if (! $this->kuota->hariIni($siswa->id, now())['boleh']) {
            return back()->with('galat', 'Kuota main hari ini sudah habis. Coba lagi besok.');
        }

        $lomba = $this->lomba->masuk($data['kode'], $siswa, now());

        if ($lomba === null) {
            return back()->with('galat', 'Kode undangan itu tidak berlaku. Periksa lagi, atau minta kode baru.');
        }

        return redirect()->route('siswa.luang.lomba.ruang', ['kode' => $lomba->code]);
    }

    /**
     * Denyut keadaan lomba - dipanggil kedua layar tiap 1,5 detik.
     *
     * Yang dikirim adalah keadaan yang dihitung server, bukan yang dihitung
     * peramban. `sekarang` ikut dikirim supaya kedua layar bisa mengoreksi
     * selisih jam perangkatnya: jam dua ponsel bisa berbeda beberapa detik,
     * dan hitung mundur yang dihitung sendiri oleh masing-masing akan membuat
     * satu anak mulai dua detik lebih awal - lihat catatan di berkas
     * `Lomba.tsx`.
     */
    public function keadaan(Request $request, string $kode): JsonResponse
    {
        $siswa = $request->user();
        $lomba = $this->cariLomba($siswa, $kode);

        if ($lomba === null) {
            return response()->json(['pesan' => 'Lomba itu tidak ditemukan.'], 404);
        }

        // Jendela diperiksa SETIAP denyut, dan itu satu-satunya hal di sini
        // yang menutup lomba saat salah satu pemainnya masuk ujian. Tanpa
        // pemeriksaan ini, lomba tetap berjalan di layar anak yang seharusnya
        // sudah di gerbang ujian.
        if ($this->jendela->untukSiswa($this->penempatan($siswa->id), now()) === null) {
            $this->lomba->batal($lomba, LeisureMatch::BATAL_UJIAN, now());

            return response()->json([
                'fase' => 'batal',
                'alasan' => LeisureMatch::BATAL_UJIAN,
                'pesan' => 'Ujian Anda sudah dibuka. Lomba ini berhenti di sini.',
            ]);
        }

        return response()->json($this->potret($lomba->refresh(), $siswa));
    }

    /**
     * Kiriman lembar jawaban satu pemain setelah lombanya selesai.
     *
     * =====================================================================
     * Yang dikirim LEMBAR JAWABAN, bukan poinnya
     * =====================================================================
     * Peramban tidak pernah tahu mana jawaban yang benar - kuncinya tinggal
     * di server - sehingga ia juga tidak bisa menghitung skornya sendiri.
     * Yang dikirim adalah daftar pilihannya, satu angka per soal, dan server
     * yang membandingkannya dengan kunci yang sudah tersimpan di baris lomba.
     *
     * Ini BUKAN pengurangan kepercayaan terhadap anak: peramban tetap bisa
     * mengirim lembar yang seluruhnya benar tanpa pernah memainkannya. Yang
     * dijaga tetap sama seperti permainan sendirian - `PenjagaSkorLuar`
     * menolak laju poin yang mustahil - dan yang dibeli di sini adalah skor
     * yang BENAR-BENAR berarti "berapa soal yang dijawab benar", bukan
     * "berapa kali tombol ditekan".
     */
    public function skor(Request $request, string $kode): JsonResponse
    {
        $data = $request->validate([
            // Satu angka pilihan per soal yang dijawab.
            //
            // `present`, BUKAN `required`: `required` menolak array kosong,
            // dan anak yang sama sekali tidak menjawab dalam enam puluh
            // detik memang mengirim `[]`. Menolaknya dengan 422 membuat
            // lombanya berakhir tanpa skor sama sekali - padahal skor nol
            // adalah jawaban yang benar untuk lembar kosong.
            //
            // TANPA batas `max`: lembar yang lebih panjang dari daftar soal
            // dipotong oleh `LombaLuang::nilai()`, bukan ditolak. Menolaknya
            // di sini berarti anak yang mengetuk cepat di detik terakhir
            // kehilangan SELURUH skornya karena beberapa angka berlebih -
            // hukuman yang jauh lebih besar daripada kesalahannya.
            'jawaban' => ['present', 'array', 'max:1000'],
            'jawaban.*' => ['required', 'integer'],
            'duration_ms' => ['required', 'integer', 'min:0', 'max:3600000'],
        ]);

        $siswa = $request->user();
        $lomba = $this->cariLomba($siswa, $kode);

        if ($lomba === null) {
            return response()->json(['pesan' => 'Lomba itu tidak ditemukan.'], 404);
        }

        $keadaan = $lomba->keadaan(now());

        // Skor hanya diterima saat lombanya sudah SELESAI. Kiriman yang
        // datang saat lombanya masih berjalan berarti peramban yang
        // mengirim terlalu cepat, dan menerimanya akan membuat lembar yang
        // belum lengkap tercatat sebagai lengkap.
        if ($keadaan['fase'] !== 'selesai') {
            return response()->json([
                'pesan' => 'Lombanya belum selesai. Skor ini tidak dicatat.',
                'fase' => $keadaan['fase'],
            ], 409);
        }

        // Satu pemain satu skor per lomba. Kiriman kedua ditolak, bukan
        // ditimpa: skor pertama sudah tercatat, dan baris yang ditimpa
        // berarti anak itu bisa menaikkan angkanya dengan memuat ulang.
        $sudahAda = LeisureGameScore::query()
            ->where('match_id', $lomba->id)
            ->where('student_id', $siswa->id)
            ->exists();

        if ($sudahAda) {
            return response()->json(['pesan' => 'Skor Anda pada lomba ini sudah tercatat.']);
        }

        $poin = $this->lomba->nilai($lomba, $data['jawaban']);

        $penempatan = $this->penempatan($siswa->id);
        $peserta = $penempatan->first(fn (ExamParticipant $p) => $p->exam_session_id !== null)
            ?? $penempatan->first();

        $baris = $this->lomba->catat(
            $lomba,
            $siswa,
            $peserta,
            $poin,
            (int) $data['duration_ms'],
        );

        return response()->json([
            'diterima' => $baris->diterima(),
            'score' => $baris->score,
            'pesan' => $baris->diterima()
                ? 'Skor tersimpan.'
                : 'Skor ditolak: kiriman ini tidak sesuai dengan permainan yang bisa dihasilkan panggung.',
        ]);
    }

    /** Membatalkan lomba yang belum selesai, atas permintaan pemainnya. */
    public function batal(Request $request, string $kode): JsonResponse
    {
        $siswa = $request->user();
        $lomba = $this->cariLomba($siswa, $kode);

        if ($lomba === null) {
            return response()->json(['pesan' => 'Lomba itu tidak ditemukan.'], 404);
        }

        $this->lomba->batal($lomba, LeisureMatch::BATAL_PEMBUAT, now());

        return response()->json(['pesan' => 'Lomba dibatalkan.']);
    }

    /* ------------------------------------------------------------ bantu */

    /**
     * Lomba yang boleh dibaca siswa ini: ia pembuatnya atau lawannya.
     *
     * Dicari LEWAT kepemilikan, sama seperti `SiswaDasar::milikSaya()` -
     * lomba orang lain tampak TIDAK ADA, bukan "tidak boleh".
     */
    private function cariLomba(User $siswa, string $kode): ?LeisureMatch
    {
        return LeisureMatch::query()
            ->where('code', strtoupper(trim($kode)))
            ->where(function ($q) use ($siswa) {
                $q->where('host_id', $siswa->id)
                    ->orWhere('guest_id', $siswa->id);
            })
            ->first();
    }

    /**
     * Bentuk lomba yang dikirim ke layar.
     *
     * Kunci jawaban DIBUANG di sini, satu tempat - dan alasannya bukan
     * kerapian: soal yang lolos membawa `jawaban`-nya akan membuat anak yang
     * membuka DevTools menang tanpa berhitung. `soal` yang dikirim hanya
     * `teks` dan `pilihan`.
     *
     * @return array<string, mixed>
     */
    private function potret(LeisureMatch $lomba, User $siswa): array
    {
        $keadaan = $lomba->keadaan(now());
        $sayaHost = (int) $lomba->host_id === (int) $siswa->id;

        // Soal hanya dikirim saat lombanya berjalan atau selesai. Mengirimnya
        // saat masih menunggu berarti soal bisa dibaca lebih dulu oleh anak
        // yang membuka DevTools sebelum lawannya masuk.
        $soal = in_array($keadaan['fase'], ['berjalan', 'selesai'], true)
            ? array_map(fn (array $s) => [
                'teks' => $s['teks'],
                'pilihan' => $s['pilihan'],
            ], $lomba->soal ?? [])
            : null;

        $skor = LeisureGameScore::query()
            ->where('match_id', $lomba->id)
            ->get(['student_id', 'score', 'rejected_reason']);

        return [
            'kode' => $lomba->code,
            'fase' => $keadaan['fase'],
            'sisa' => $keadaan['sisa'],
            'alasan_batal' => $lomba->alasan_batal,
            'saya' => $sayaHost ? 'host' : 'tamu',
            'host' => $this->namaPemain($lomba->host_id),
            'tamu' => $lomba->guest_id === null ? null : $this->namaPemain($lomba->guest_id),
            'soal' => $soal,
            'skor' => [
                'host' => $this->skorPemain($skor, (int) $lomba->host_id),
                'tamu' => $lomba->guest_id === null ? null : $this->skorPemain($skor, (int) $lomba->guest_id),
            ],
        ];
    }

    private function namaPemain(int $studentId): string
    {
        return (string) User::query()
            ->whereKey($studentId)
            ->value('name');
    }

    /** Skor satu pemain, atau null bila ia belum mengirim. */
    private function skorPemain(Collection $skor, int $studentId): ?int
    {
        $baris = $skor->firstWhere('student_id', $studentId);

        if ($baris === null || $baris->rejected_reason !== null) {
            return null;
        }

        return (int) $baris->score;
    }
}
