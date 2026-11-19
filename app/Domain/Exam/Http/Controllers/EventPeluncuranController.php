<?php

namespace App\Domain\Exam\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\EventReadiness;
use App\Domain\Event\Services\EventStatusService;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Services\SesiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Peluncuran ujian - satu laman untuk pekerjaan yang hari ini tersebar di
 * delapan laman.
 *
 * =====================================================================
 * Mengapa laman ini ADA, dan bukan sekadar tombol besar
 * =====================================================================
 * Sampai 2026-11-19 menyalakan ujian menuntut sembilan klik di empat laman,
 * dengan dua belas prasyarat yang hanya terlihat bila Admin membuka tab
 * "Kesiapan" di Ringkasan - dan memperbaikinya menuntunnya keluar laman itu
 * tanpa jalan kembali.
 *
 * Yang hilang bukan tombolnya, melainkan URUTAN: peserta dikerjakan sebelum
 * paket soal, paket sebelum sesi disiapkan, sesi sebelum token, dan semuanya
 * sebelum event boleh ditandai Siap. Tidak satu pun layar hari ini
 * mengucapkan urutan itu.
 *
 * =====================================================================
 * Di modul Exam, bukan Event
 * =====================================================================
 * Yang dihitung di sini sebagian besar milik ujian - sesi, token, pengawas,
 * kapasitas - sementara Event hanya memegang identitas, tahun, kelas, dan
 * peserta. Peta modul mengizinkan Exam menunjuk Event, tidak sebaliknya, dan
 * memaksakan laman ini ke modul Event berarti memecah peta itu untuk satu
 * halaman yang isinya justru tentang ujian.
 *
 * =====================================================================
 * `EventReadiness` dipakai apa adanya, tidak dihitung ulang
 * =====================================================================
 * Sebelas butirnya sudah ada, beserta tautan perbaikannya, dan ia singleton
 * di container. Laman ini hanya MENGELOMPOKKANNYA menjadi empat langkah -
 * bukan menghitung syarat baru. Syarat yang lahir di sini akan berbeda dari
 * yang dipakai gerbang "Tandai Siap" begitu salah satunya diubah, dan
 * perbedaan itu baru terlihat pada hari-H.
 */
class EventPeluncuranController
{
    public function __construct(
        private readonly EventReadiness $kesiapan,
        private readonly SesiService $sesi,
        private readonly EventStatusService $status,
    ) {}

    public function show(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $laporan = $this->kesiapan->periksa($event);
        $butir = collect($laporan['butir'])->keyBy('kunci');

        $sesi = $this->sesiEvent($event);

        $ringkasSesi = [
            'total' => $sesi->count(),
            'draft' => $sesi->where('status', StatusSesi::Draft)->count(),
            'siap' => $sesi->where('status', StatusSesi::Siap)->count(),
            'berjalan' => $sesi->where('status', StatusSesi::Berjalan)->count(),
        ];

        return Inertia::render('Admin/Event/Peluncuran', [
            'event' => [
                'id' => $event->id,
                'kode' => $event->code,
                'nama' => $event->name,
                'status' => $event->status->value,
                'status_label' => $event->status->label(),
                'final' => $event->status->final(),
            ],
            'kesiapan' => [
                'lulus' => $laporan['lulus'],
                'gagal' => $laporan['gagal'],
                'menunggu' => $laporan['menunggu'],
                'total' => $laporan['total'],
            ],
            'langkah' => $this->langkah($event, $butir->all(), $laporan, $ringkasSesi),
            'langkah_status' => $this->status->langkahTersedia($event),
            'sesi' => $ringkasSesi,
            'ujian' => $event->exams()->count(),
        ]);
    }

    /**
     * Siapkan seluruh sesi yang masih Draft.
     *
     * Laporan hasilnya BERPASANGAN, dan itu satu-satunya bentuk yang berguna
     * di sini: "11 disiapkan, 1 ditolak karena paket Matematika kosong"
     * memberi tahu apa yang harus dikerjakan berikutnya. "Gagal" saja tidak.
     */
    public function siapkan(Request $request, SchoolEvent $event, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $event);

        $hasil = $this->sesi->siapkanBanyak($this->sesiEvent($event));

        $logger->log('Menyiapkan sesi secara massal', $event, $hasil);

        return back()->with($this->nada($hasil), $this->pesanSiapkan($hasil));
    }

    /**
     * Buka seluruh sesi yang sudah Siap.
     *
     * Sesi yang tertinggal eventnya IKUT dilaporkan - lihat
     * `SesiService::mulaiBanyak()`. Pembukaannya sendiri tetap berhasil, dan
     * itu memang yang diinginkan: siswa yang sudah di depan pintu tidak boleh
     * ditahan oleh status administratif.
     */
    public function buka(Request $request, SchoolEvent $event, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $event);

        $hasil = $this->sesi->mulaiBanyak($this->sesiEvent($event));

        $logger->log('Membuka sesi secara massal', $event, $hasil);

        $pesan = $this->pesanBuka($hasil);

        // Nada `galat` hanya bila TIDAK ADA yang terbuka dan ada yang ditolak.
        // Kalau sebagian berhasil, yang perlu dibaca Admin adalah angkanya -
        // dan pesan sukses yang menyebut sisa yang gagal sudah cukup.
        $nada = $hasil['berhasil'] > 0 ? 'sukses' : ($hasil['gagal'] === [] ? 'sukses' : 'galat');

        return back()->with($nada, $pesan);
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Empat langkah, dirakit dari butir kesiapan yang sudah ada.
     *
     * @param  array<string, array<string, mixed>>  $butir
     * @param  array<string, mixed>  $laporan
     * @param  array<string, int>  $ringkasSesi
     * @return array<int, array<string, mixed>>
     */
    private function langkah(SchoolEvent $event, array $butir, array $laporan, array $ringkasSesi): array
    {
        $ambil = fn (array $kunci) => array_values(array_filter(
            array_map(fn (string $k) => $butir[$k] ?? null, $kunci),
        ));

        /**
         * Keadaan satu langkah, diturunkan dari butir yang menyusunnya.
         *
         * `belum` MENANG atas `menunggu`: satu butir yang bisa diperbaiki
         * sekarang adalah pekerjaan, sedangkan `menunggu` bukan. Mengurutkan
         * sebaliknya akan menyembunyikan pekerjaan di balik sesuatu yang
         * memang tidak bisa dikerjakan.
         */
        $keadaan = function (array $satu): string {
            if ($satu === []) {
                return 'ok';
            }

            $keadaan = array_map(fn (array $b) => $b['keadaan'] ?? 'ok', $satu);

            return match (true) {
                in_array('belum', $keadaan, true) => 'belum',
                in_array('menunggu', $keadaan, true) => 'menunggu',
                default => 'ok',
            };
        };

        $langkah = [];

        // 1 - identitas, tahun, kelas, peserta
        $satu = $ambil(['identitas', 'tahun', 'kelas', 'peserta']);
        $langkah[] = [
            'nomor' => 1,
            'kunci' => 'peserta',
            'label' => 'Peserta & kelas',
            'keadaan' => $keadaan($satu),
            'butir' => $satu,
            'aksi' => [
                ['label' => 'Kelas & peserta', 'href' => "/admin/events/{$event->id}/peserta"],
            ],
        ];

        // 2 - mapel dan paket soal
        $dua = $ambil(['mapel', 'paket']);
        $langkah[] = [
            'nomor' => 2,
            'kunci' => 'paket',
            'label' => 'Paket soal',
            'keadaan' => $keadaan($dua),
            'butir' => $dua,
            'aksi' => [
                ['label' => 'Paket soal', 'href' => "/admin/events/{$event->id}/mapel"],
            ],
        ];

        // 3 - jadwal, ruang, pengawas, kapasitas
        $tiga = $ambil(['jadwal', 'ruang', 'pengawas', 'kapasitas']);
        $langkah[] = [
            'nomor' => 3,
            'kunci' => 'jadwal',
            'label' => 'Jadwal & pengawasan',
            'keadaan' => $keadaan($tiga),
            'butir' => $tiga,
            'aksi' => [
                ['label' => 'Jadwal & sesi', 'href' => "/admin/events/{$event->id}/jadwal"],
                ['label' => 'Pengawas', 'href' => "/admin/events/{$event->id}/pengawas"],
                // Hanya muncul bila memang ada sesi Draft - tombol yang
                // tidak akan mengerjakan apa pun lebih buruk daripada tidak
                // ada tombol.
                $ringkasSesi['draft'] > 0
                    ? ['label' => "Siapkan {$ringkasSesi['draft']} sesi", 'aksi' => 'siapkan']
                    : null,
            ],
            'draf_sesi' => $ringkasSesi['draft'],
        ];

        // 4 - token, lalu Siap
        $token = $ambil(['token']);

        // Status event dibaca dari variabel, bukan dari `$event->status` di
        // dalam closure: modelnya bisa sudah disegarkan pemanggil lain, dan
        // yang dibaca di sini harus keadaan yang SAMA dengan `$laporan`.
        $statusEvent = $event->status;

        // Langkah ini TERTAHAN bila langkah 1-3 belum lulus: gerbang
        // kesiapan menolak transisi ke Siap, dan menampilkan tombolnya
        // menyala berarti menjanjikan sesuatu yang server tolak.
        $siap = match (true) {
            in_array($statusEvent, [EventStatus::Ready, EventStatus::Ongoing, EventStatus::Completed], true) => 'ok',
            ! $laporan['lulus'] => 'tertahan',
            default => $keadaan($token),
        };

        $sudahSiap = in_array($statusEvent, [EventStatus::Ready, EventStatus::Ongoing, EventStatus::Completed], true);

        $langkah[] = [
            'nomor' => 4,
            'kunci' => 'siap',
            'label' => 'Token & tandai Siap',
            'keadaan' => $siap,
            'butir' => $token,
            'aksi' => [
                ['label' => 'Token', 'href' => "/admin/events/{$event->id}/token"],
                // Tombol "Tandai Siap" HANYA muncul bila gerbangnya lulus.
                // Menampilkannya mati dengan alasan yang sudah terbaca di
                // butir di atasnya berarti dua kali mengatakan hal yang sama.
                ! $laporan['lulus'] || $sudahSiap
                    ? null
                    : ['label' => 'Tandai Siap', 'aksi' => 'siap'],
                $ringkasSesi['siap'] > 0
                    ? ['label' => "Buka {$ringkasSesi['siap']} sesi", 'aksi' => 'buka']
                    : null,
            ],
        ];

        return array_map(function (array $l) {
            $l['aksi'] = array_values(array_filter($l['aksi'] ?? []));

            return $l;
        }, $langkah);
    }

    /** @return Collection<int, ExamSession> */
    private function sesiEvent(SchoolEvent $event): Collection
    {
        return ExamSession::query()
            ->whereIn('exam_id', $event->exams()->select('id'))
            ->orderBy('access_opens_at')
            ->orderBy('session_number')
            ->get();
    }

    /**
     * @param  array{berhasil: int, dilewati: int, gagal: array<int, array{sesi: string, pesan: string}>, tertinggal?: array<int, string>}  $hasil
     */
    private function pesanSiapkan(array $hasil): string
    {
        if ($hasil['berhasil'] === 0 && $hasil['gagal'] === [] && $hasil['dilewati'] > 0) {
            return 'Tidak ada sesi draft yang perlu disiapkan. Seluruh sesi sudah siap atau sudah berjalan.';
        }

        $pesan = $hasil['berhasil'].' sesi disiapkan.';

        if ($hasil['dilewati'] > 0) {
            $pesan .= ' '.$hasil['dilewati'].' sesi dilewati karena statusnya bukan draft.';
        }

        if ($hasil['gagal'] !== []) {
            $pesan .= ' '.count($hasil['gagal']).' sesi ditolak: '.$this->rangkum($hasil['gagal']);
        }

        return $pesan;
    }

    /**
     * @param  array{berhasil: int, dilewati: int, gagal: array<int, array{sesi: string, pesan: string}>, tertinggal?: array<int, string>}  $hasil
     */
    private function pesanBuka(array $hasil): string
    {
        if ($hasil['berhasil'] === 0 && $hasil['gagal'] === [] && $hasil['dilewati'] > 0) {
            return 'Tidak ada sesi siap yang bisa dibuka. Siapkan sesinya lebih dulu.';
        }

        $pesan = $hasil['berhasil'].' sesi dibuka.';

        if ($hasil['gagal'] !== []) {
            $pesan .= ' '.count($hasil['gagal']).' sesi ditolak: '.$this->rangkum($hasil['gagal']);
        }

        // Kalimat ini yang mencegah bug 2026-11-19 terulang tanpa terlihat:
        // sesinya terbuka, eventnya tertinggal, dan tidak ada yang memberi
        // tahu.
        foreach ($hasil['tertinggal'] ?? [] as $tertinggal) {
            $pesan .= ' '.$tertinggal;
        }

        return $pesan;
    }

    /** @param  array<int, array{sesi: string, pesan: string}>  $gagal */
    private function rangkum(array $gagal): string
    {
        return collect($gagal)
            ->take(3)
            ->map(fn (array $g) => $g['sesi'].' - '.$g['pesan'])
            ->implode('; ').(count($gagal) > 3 ? ' dan '.(count($gagal) - 3).' lainnya' : '');
    }

    /** @param  array<string, mixed>  $hasil */
    private function nada(array $hasil): string
    {
        return $hasil['berhasil'] > 0 || $hasil['gagal'] === [] ? 'sukses' : 'galat';
    }
}
