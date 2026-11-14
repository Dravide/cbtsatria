<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Event\Enums\EventStatus;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Papan publik pengawas - `/papan/{slug}`.
 *
 * =====================================================================
 * Yang dijawab papan ini, dan mengapa tidak ada laman lain yang bisa
 * =====================================================================
 * "Bu Ani di ruang mana?" adalah pertanyaan yang berulang di lorong pada
 * setiap hari ujian, dan sampai 2026-11-14 tidak ada satu pun layar yang
 * bisa dibuka untuk menjawabnya: penugasan pengawas hanya tampak di konsol
 * Admin (per baris, setelah login), dan di beranda pengawas itu sendiri
 * (hanya untuk orangnya). Wali kelas, panitia, dan orang tua tidak punya
 * pintu.
 *
 * =====================================================================
 * Batas isinya adalah tiga kolom, dan itu bukan kehati-hatian berlebih
 * =====================================================================
 * Alamat ini di luar middleware `auth`, jadi ia tayang untuk seluruh
 * internet. Yang boleh tampil:
 *
 *   - nama ruang (kode + nama lokasi);
 *   - nama pengawas;
 *   - jam sesinya.
 *
 * Yang TIDAK boleh: nama siswa, nomor kursi, token, jumlah peserta,
 * berita acara, dan apa pun dari `attendance_records`. Batas ini sama
 * dengan pintu ruang `/p/{slug}` - lihat catatan panjangnya di sana - dan
 * alasannya sama: tautan yang tertempel di lorong bisa difoto siapa pun
 * yang lewat.
 *
 * =====================================================================
 * Null slug berarti papan tidak ada
 * =====================================================================
 * `schools.papan_slug` nullable, dan sekolah yang belum pernah menyalakan
 * papannya tidak punya alamat sama sekali. Rute yang tidak pernah lahir
 * tidak bisa ditebak - dan itu lebih kuat daripada halaman yang menolak
 * dengan 403, karena 403 membuktikan alamatnya ada.
 *
 * =====================================================================
 * Sesi yang dipakai: BERJALAN, lalu yang paling dekat
 * =====================================================================
 * Papan ini dibuka pada pagi hari ujian untuk menjawab satu pertanyaan
 * tentang SEKARANG. Menampilkan seluruh sesi event berarti daftar yang
 * panjang dan jawaban yang harus dicari. Yang dipilih:
 *
 *   1. sesi `berjalan` bila ada (paling banyak satu per ujian, tetapi
 *      sekolah bisa menjalankan dua ujian serentak);
 *   2. bila tidak ada, sesi yang akan datang paling dekat.
 *
 * Bila keduanya tidak ada, papan menyatakan itu apa adanya alih-alih
 * menampilkan daftar kosong yang tak menjelaskan apa pun.
 */
class PapanPengawas
{
    /** Batas jumlah sesi berjalan yang ditampilkan dalam satu papan. */
    public const MAKS_SESI = 3;

    /**
     * Bentuk papan satu sekolah - null bila sekolahnya tidak punya slug.
     *
     * @return array<string, mixed>|null
     */
    public function susun(School $sekolah): ?array
    {
        if ($sekolah->papan_slug === null) {
            return null;
        }

        $sesi = $this->sesiAktif($sekolah);

        return [
            'sekolah' => $sekolah->name,
            'diperbarui' => now()->format('H:i'),
            'ada_slug' => true,
            'sesi' => $sesi->map(fn ($s) => $this->ringkasSesi($s))->all(),
            'tanpa_pengawas' => $sesi
                ->flatMap(fn ($s) => $s->rooms)
                ->filter(fn (ExamSessionRoom $r) => $r->supervisors->isEmpty())
                ->map(fn (ExamSessionRoom $r) => $r->eventRoom?->room?->code)
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * Papan per pengawas - `/papan/{slug}/pengawas/{id}`.
     *
     * Pertanyaan di lorong biasanya menyebut NAMA, bukan ruang. Tanpa
     * halaman ini, penanya harus memindai seluruh baris daftar untuk
     * menemukan satu orang - dan pada sekolah dengan 24 ruang itu 24 baris.
     *
     * Yang dicari adalah pengawas yang punya penugasan pada sesi aktif
     * sekolah ini. Pengawas yang ditugaskan di luar sesi aktif tidak
     * ditemukan, dan itu jawaban yang benar: ia memang tidak sedang
     * mengawas.
     *
     * @return array<string, mixed>|null
     */
    public function susunPengawas(School $sekolah, int $supervisorId): ?array
    {
        if ($sekolah->papan_slug === null) {
            return null;
        }

        $sesi = $this->sesiAktif($sekolah);

        $baris = $sesi
            ->flatMap(fn ($s) => $s->rooms->map(fn (ExamSessionRoom $r) => [
                'sesi' => $s,
                'ruang' => $r,
            ]))
            ->filter(fn (array $b) => $b['ruang']->supervisors
                ->contains(fn (SupervisionAssignment $t) => (int) $t->supervisor_id === $supervisorId));

        if ($baris->isEmpty()) {
            return null;
        }

        $nama = $baris
            ->flatMap(fn (array $b) => $b['ruang']->supervisors)
            ->first(fn (SupervisionAssignment $t) => (int) $t->supervisor_id === $supervisorId)
            ?->supervisor?->name;

        return [
            'sekolah' => $sekolah->name,
            'diperbarui' => now()->format('H:i'),
            'nama' => $nama,
            'ruang' => $baris->map(fn (array $b) => [
                'kode' => $b['ruang']->eventRoom?->room?->code,
                'nama' => $b['ruang']->eventRoom?->room?->name,
                'lokasi' => $b['ruang']->eventRoom?->room?->location,
                'sesi' => $b['sesi']->label(),
                'ujian' => $b['sesi']->exam?->title,
                'jam' => $this->jam($b['sesi']),
            ])->values()->all(),
        ];
    }

    /**
     * Sesi yang dijawab papan ini: berjalan lebih dulu, lalu yang terdekat.
     *
     * =====================================================================
     * Event yang sudah SELESAI/ARSIP/BATAL tidak ikut - perbaikan 2026-11-14
     * =====================================================================
     * Versi pertama hanya menyaring `school_id`, dan akibatnya papan publik
     * ini tetap menampilkan ruang dan pengawas dari event yang sudah
     * diarsipkan - atau, lebih menyesatkan lagi, sesi yang jendelanya sudah
     * lewat dan baru ditutup beberapa menit lalu. Di lorong, papan yang
     * menyebut "Bu Ani di R-02" untuk ujian kemarin membuat orang mencarinya
     * di ruang yang sudah dikosongkan.
     *
     * Dua penyaringnya berbeda dan keduanya perlu:
     *
     *   status event   Arsip/Batal bukan "sedang berlangsung" dalam arti apa
     *                  pun. `Selesai` juga tidak - event yang seluruh
     *                  sesinya tuntas tidak punya pengawas yang sedang
     *                  berdiri di mana pun.
     *   hard_end_at    Sesi yang jendelanya lewat tetapi statusnya masih
     *                  `berjalan` - keadaan yang bisa bertahan sampai
     *                  `ujian:tutup-sesi` berjalan satu menit kemudian -
     *                  tidak boleh tampil sebagai sesi yang sedang aktif.
     * Aturan statusnya dari `EventStatus::belumSelesai()`, bukan daftar
     * sendiri di sini: papan yang menyaring berbeda dari layar lain adalah
     * papan yang menjawab pertanyaan tentang dunia yang berbeda. Yang
     * tersisa karena itu Draft, Persiapan, Siap, dan Berjalan - dan dua
     * yang terakhir itulah yang benar-benar punya sesi.
     *
     * @return Collection<int, ExamSession>
     */
    private function sesiAktif(School $sekolah): Collection
    {
        $sekarang = now();

        $statusAktif = array_map(
            fn (EventStatus $s) => $s->value,
            EventStatus::belumSelesai(),
        );

        $layak = fn ($q) => $q
            ->where('school_id', $sekolah->id)
            ->whereIn('status', $statusAktif)
            // Jendelanya sudah lewat = tidak ada yang sedang mengawas, apa
            // pun status barisnya.
            ->where('hard_end_at', '>=', $sekarang);

        $berjalan = ExamSession::query()
            ->whereHas('exam.event', $layak)
            ->where('status', 'berjalan')
            ->with($this->muatan())
            ->orderBy('access_opens_at')
            ->limit(self::MAKS_SESI)
            ->get();

        if ($berjalan->isNotEmpty()) {
            return $berjalan;
        }

        // Tidak ada yang berjalan: sesi TERDEKAT yang belum berakhir.
        // Papan yang menampilkan sesi kemarin akan menjawab pertanyaan yang
        // tidak ditanyakan siapa pun pada pagi hari ujian.
        return ExamSession::query()
            ->whereHas('exam.event', $layak)
            ->with($this->muatan())
            ->orderBy('access_opens_at')
            ->limit(self::MAKS_SESI)
            ->get();
    }

    /**
     * Relasi yang dimuat - dan batasnya ditulis di sini, bukan di pemanggil.
     *
     * `supervisors.supervisor:id,name` adalah SATU-SATUNYA kolom pengguna
     * yang boleh keluar dari modul ini. Mengirim `User` utuh berarti
     * username, email, dan `school_id` ikut terbaca di halaman publik -
     * dan yang pertama menyadarinya bukan siapa pun di sekolah ini.
     *
     * @return array<int, string>
     */
    private function muatan(): array
    {
        return [
            'exam:id,title,subject_id,event_id',
            'exam.subject:id,name',
            'rooms.eventRoom.room:id,code,name,location',
            'rooms.supervisors.supervisor:id,name',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ringkasSesi($sesi): array
    {
        return [
            'id' => (int) $sesi->id,
            'label' => $sesi->label(),
            'ujian' => $sesi->exam?->title,
            'mapel' => $sesi->exam?->subject?->name,
            'jam' => $this->jam($sesi),
            'status' => $sesi->status->value,
            'ruang' => $sesi->rooms->map(fn (ExamSessionRoom $r) => [
                'id' => (int) $r->id,
                'kode' => $r->eventRoom?->room?->code,
                'nama' => $r->eventRoom?->room?->name,
                'lokasi' => $r->eventRoom?->room?->location,
                'pengawas' => $r->supervisors
                    ->map(fn (SupervisionAssignment $t) => [
                        'id' => (int) $t->supervisor_id,
                        'nama' => $t->supervisor?->name,
                    ])
                    ->filter(fn (array $p) => $p['nama'] !== null)
                    ->values()
                    ->all(),
            ])->values()->all(),
        ];
    }

    private function jam($sesi): string
    {
        return $sesi->access_opens_at->format('H:i').'–'.$sesi->hard_end_at->format('H:i');
    }

    /**
     * Bangkitkan slug unik dari nama sekolah.
     *
     * Empat huruf acak di belakang, pola yang sama dengan
     * `RoomEntryLinkService::slugUnik()`: slug yang hanya dibangkitkan dari
     * nama sekolah akan bertabrakan pada dua sekolah bernama sama, dan
     * rute publik ini di-resolve TANPA konteks tenant - sehingga tabrakan
     * berarti papan sekolah lain yang tayang.
     */
    public function slugUnik(School $sekolah): string
    {
        $awalan = Str::slug($sekolah->name) ?: 'sekolah';

        do {
            $slug = Str::limit($awalan, 40, '').'-'.Str::lower(Str::random(4));
        } while (School::query()->where('papan_slug', $slug)->exists());

        return $slug;
    }
}
