<?php

namespace App\Domain\Exam\Services;

use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use Illuminate\Support\Collection;

/**
 * Yang perlu diperhatikan Admin saat ujiannya sudah dekat atau sedang jalan.
 *
 * =====================================================================
 * Mengapa ini ada, padahal `EventReadiness` sudah memeriksa sebelas butir
 * =====================================================================
 * `EventReadiness` menjawab pertanyaan "apakah event ini boleh dijalankan" -
 * dan jawabannya tidak berubah banyak setelah eventnya berjalan. Yang
 * hilang dari seorang Admin pada hari-H justru pertanyaan yang berbeda:
 * "apa yang sedang menghambat SEKARANG". Dua di antaranya hanya bisa
 * dijawab oleh waktu, dan `EventReadiness` tidak memeriksanya sama sekali:
 *
 *   1. Sesi yang statusnya masih `Siap` padahal jendela waktunya sudah
 *      lewat. Siswa di depan pintu tidak bisa masuk, dan tidak ada satu pun
 *      butir kesiapan yang berbunyi tentang ini - sesinya sendiri sudah
 *      "ada", "berpengawas", dan "bertoken".
 *   2. Sesi yang akan segera dibuka tetapi tokennya belum terbit. Token
 *      memang sudah diperiksa `EventReadiness`, tetapi sebagai keadaan
 *      biner untuk SELURUH event; Admin yang boleh membuka sesinya sepuluh
 *      menit lagi butuh tahu sesi MANA yang belum siap.
 *
 * Jadi kelas ini BUKAN pengganti `EventReadiness` dan tidak mengulang
 * aturannya. Ia membaca hasilnya untuk butir yang memang sama (`penghambat`),
 * lalu menambahkan sinyal waktu yang tidak dimiliki kelas itu.
 *
 * ---------------------------------------------------------------------
 * Diam saat tidak ada yang perlu dikerjakan
 * ---------------------------------------------------------------------
 * Panel yang selalu berbunyi akan berhenti dibaca. Karena itu kelas ini
 * mengembalikan daftar KOSONG untuk event yang masih `Persiapan` jauh dari
 * hari-H, dan untuk event yang sudah `Selesai`. Yang diperiksa hanya event
 * yang waktunya sudah dekat atau sedang berjalan - lihat `untukEvent()`.
 *
 * ---------------------------------------------------------------------
 * `sejak` tidak pernah null
 * ---------------------------------------------------------------------
 * Satu-satunya kolom waktu yang selalu ada di setiap sesi adalah
 * `access_opens_at`. Sesi tanpa jam dianggap "sudah lewat" hanya bila
 * jendelanya benar-benar lewat, sehingga baris tanpa jam tidak memunculkan
 * peringatan palsu - ia hanya tidak ikut diperiksa.
 */
class PeringatanHariH
{
    /** Sesi yang dibuka dalam rentang ini dianggap "segera". */
    public const MENIT_SEGERA = 120;

    /**
     * Peringatan untuk event AKTIF di sesi, atau daftar kosong.
     *
     * ---------------------------------------------------------------------
     * Mengapa pencarian eventnya ada di sini, bukan di controller
     * ---------------------------------------------------------------------
     * Beranda Admin perlu mencari `SchoolEvent` dari id yang tersimpan di
     * sesi, tetapi modul Admin tidak boleh menyentuh model milik Event -
     * itulah yang ditegakkan `DomainBoundariesTest`, dan yang membuat peta
     * arah modul tetap bisa dibaca siapa pun. Controller karena itu menyerahkan
     * ID-nya ke sini, dan kelas inilah yang membaca modelnya.
     *
     * Pencariannya lewat `SchoolEvent::query()` yang sudah di-scope tenant:
     * id dari sesi tidak dipercaya begitu saja. Sesi yang dibuat sebelum
     * pengguna berpindah akun - mis. di komputer bersama ruang guru - akan
     * menyebut event sekolah lain, dan scope-lah yang menolaknya.
     *
     * @return array<int, array{kunci: string, judul: string, pesan: string, nada: string, href: string}>
     */
    public function untukEventAktif(?int $idEvent): array
    {
        if ($idEvent === null) {
            return [];
        }

        $event = SchoolEvent::query()->find($idEvent);

        return $event === null ? [] : $this->untukEvent($event);
    }

    /**
     * Peringatan untuk satu event, atau daftar kosong.
     *
     * Event yang statusnya sudah final (selesai/arsip/batal) tidak
     * diperiksa: tidak ada lagi yang bisa diperbaiki di sana, dan
     * memunculkan peringatan untuk ujian kemarin berarti panel ini berhenti
     * berarti untuk ujian hari ini.
     *
     * @return array<int, array{kunci: string, judul: string, pesan: string, nada: string, href: string}>
     */
    public function untukEvent(SchoolEvent $event): array
    {
        if ($event->status->final()) {
            return [];
        }

        $sesi = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->select('id'))
            ->with([
                'exam:id,title,subject_id,event_id',
                'exam.subject:id,name',
                // Dimuat sekali untuk seluruh sesi. Tanpa `with`, memeriksa
                // token berarti satu kueri per sesi - dan event dengan dua
                // belas sesi membuka laman beranda Admin dengan dua belas
                // kueri tambahan.
                'tokens' => fn ($q) => $q->whereNull('revoked_at')->orderByDesc('id'),
            ])
            ->get();

        if ($sesi->isEmpty()) {
            return [];
        }

        // Event yang masih jauh dari hari-H tidak diberi peringatan waktu -
        // jadwalnya memang belum dijalankan, dan "sesi belum dibuka" adalah
        // keadaan yang benar untuknya, bukan masalah.
        $dekat = $this->sudahDekat($event, $sesi);

        $peringatan = array_merge(
            $this->sesiSudahLewatJendela($sesi),
            $dekat ? $this->tokenBelumTerbit($sesi) : [],
            $dekat ? $this->sesiBelumDibuka($sesi) : [],
            $dekat ? $this->ruangTanpaPengawas($event, $sesi) : [],
        );

        return array_values($peringatan);
    }

    /* --------------------------------------------------------- Tiap butir */

    /**
     * Sesi yang jendelanya sudah lewat tetapi statusnya belum berjalan.
     *
     * Inilah peringatan yang paling menentukan: siswa sudah berdiri di depan
     * pintu, jadwalnya sudah tiba, dan sesinya masih `Siap`. Tidak ada satu
     * pun butir kesiapan yang menangkapnya, karena dari sisi data sesinya
     * memang lengkap - yang kurang hanya tindakan Admin menekan "Buka".
     *
     * @param  Collection<int, ExamSession>  $sesi
     * @return array<int, array<string, string>>
     */
    private function sesiSudahLewatJendela(Collection $sesi): array
    {
        $lewat = $sesi->filter(fn (ExamSession $s) => $s->status === StatusSesi::Siap
            && $s->access_opens_at !== null
            && $s->access_opens_at->isPast()
            && ! $s->hard_end_at?->isPast());

        return $lewat->map(fn (ExamSession $s) => [
            'kunci' => 'sesi-belum-dibuka-'.$s->id,
            'judul' => 'Sesi '.$s->label().' belum dibuka',
            'pesan' => $this->namaSesi($s).' sudah masuk jadwalnya pukul '
                .$s->access_opens_at->format('H:i').', tetapi sesinya belum dibuka. '
                .'Siswa tidak dapat masuk sampai Anda menekan Buka.',
            'nada' => 'bad',
            'href' => "/admin/events/{$this->eventId($s)}/jadwal",
        ])->values()->all();
    }

    /**
     * Sesi aktif yang belum punya token berlaku.
     *
     * Diperiksa per sesi, bukan sebagai keadaan event: Admin yang sesinya
     * dibuka sepuluh menit lagi butuh tahu sesi MANA yang belum siap, dan
     * tautannya langsung ke laman token event itu.
     *
     * @param  Collection<int, ExamSession>  $sesi
     * @return array<int, array<string, string>>
     */
    private function tokenBelumTerbit(Collection $sesi): array
    {
        $aktif = $sesi->filter(fn (ExamSession $s) => ! $s->status->final());

        // Membaca koleksi `tokens` yang sudah dimuat di `untukEvent()`, bukan
        // `tokenAktif()` - method itu menembak kueri sendiri, dan ia dipanggil
        // dua kali per sesi bila dipakai di sini.
        $kurang = $aktif->reject(function (ExamSession $s) {
            $token = $s->tokens->first();

            return $token !== null && $token->belumKedaluwarsa();
        });

        if ($kurang->isEmpty()) {
            return [];
        }

        $pertama = $kurang->first();
        $label = $kurang->map(fn (ExamSession $s) => $s->label())->take(4)->implode(', ');
        $sisa = $kurang->count() - 4;

        return [[
            'kunci' => 'token-belum-terbit',
            'judul' => $kurang->count().' sesi belum punya token',
            'pesan' => 'Sesi '.$label.($sisa > 0 ? ' dan '.$sisa.' lainnya' : '')
                .' belum punya token yang berlaku. Siswa tidak dapat memulai ujian tanpa token, '
                .'meski presensinya sudah dikonfirmasi pengawas.',
            'nada' => 'warn',
            'href' => "/admin/events/{$this->eventId($pertama)}/token",
        ]];
    }

    /**
     * Sesi yang statusnya masih Draft, padahal waktunya sudah dekat.
     *
     * Draf tidak bisa langsung dibuka - grafnya menuntut Draft -> Siap ->
     * Berjalan. Jadi peringatan ini berbunyi lebih awal daripada yang di
     * atas, dan memang harus: Admin yang baru sadar sesinya masih Draft
     * lima menit sebelum dibuka harus menekan dua tombol, bukan satu.
     *
     * @param  Collection<int, ExamSession>  $sesi
     * @return array<int, array<string, string>>
     */
    private function sesiBelumDibuka(Collection $sesi): array
    {
        $kini = now();

        // `diffInMinutes` di Carbon 3 mengembalikan selisih BERTANDA, jadi
        // `isFuture()` dipertahankan di sampingnya meski tampak berlebihan:
        // tanpa itu sesi yang jendelanya lewat sehari pun lolos perbandingan
        // `<=` dan muncul sebagai "dibuka dalam 120 menit ke depan". Sesi
        // yang lewat ditangani `sesiSudahLewatJendela()`.
        $draft = $sesi->filter(fn (ExamSession $s) => $s->status === StatusSesi::Draft
            && $s->access_opens_at !== null
            && $s->access_opens_at->isFuture()
            && $kini->diffInMinutes($s->access_opens_at) <= self::MENIT_SEGERA);

        if ($draft->isEmpty()) {
            return [];
        }

        $pertama = $draft->first();

        return [[
            'kunci' => 'sesi-masih-draft',
            'judul' => $draft->count().' sesi masih Draft',
            'pesan' => 'Sesi '.$draft->map(fn (ExamSession $s) => $s->label())->take(4)->implode(', ')
                .' dibuka dalam '.self::MENIT_SEGERA.' menit ke depan. Sesi Draft harus '
                .'disiapkan lebih dulu sebelum bisa dibuka.',
            'nada' => 'warn',
            'href' => "/admin/events/{$this->eventId($pertama)}/jadwal",
        ]];
    }

    /**
     * Ruang-sesi yang belum punya pengawas.
     *
     * `EventReadiness` juga memeriksanya, dan itu disengaja: kesiapan event
     * dan peringatan hari-H memang menjawab pertanyaan yang berbeda tentang
     * data yang sama. Yang pertama "boleh dijalankan atau tidak", yang kedua
     * "apa yang menghambat sekarang" - dan saat sesinya sudah berjalan,
     * ruang tanpa pengawas berarti presensi tidak bisa dikonfirmasi siapa pun.
     *
     * @param  Collection<int, ExamSession>  $sesi
     * @return array<int, array<string, string>>
     */
    private function ruangTanpaPengawas(SchoolEvent $event, Collection $sesi): array
    {
        $ruangan = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $sesi->pluck('id'))
            ->with(['supervisors', 'eventRoom.room:id,code'])
            ->get();

        $kosong = $ruangan->filter(fn (ExamSessionRoom $r) => $r->supervisors->isEmpty());

        if ($kosong->isEmpty()) {
            return [];
        }

        $kode = $kosong
            ->map(fn (ExamSessionRoom $r) => $r->eventRoom?->room?->code ?? 'R-?')
            ->unique()
            ->values();

        return [[
            'kunci' => 'ruang-tanpa-pengawas',
            'judul' => $kosong->count().' ruang belum punya pengawas',
            'pesan' => 'Ruang '.$kode->take(6)->implode(', ')
                .($kode->count() > 6 ? ' dan '.($kode->count() - 6).' lainnya' : '')
                .' belum ditugasi pengawas. Tanpa pengawas, presensi di ruang itu '
                .'tidak dapat dikonfirmasi siapa pun.',
            'nada' => 'warn',
            'href' => "/admin/events/{$event->id}/pengawas",
        ]];
    }

    /* ----------------------------------------------------------- Pembantu */

    /**
     * Apakah event ini waktunya sudah dekat atau sedang berjalan.
     *
     * Tiga jalan menuju "ya", dan ketiganya sengaja:
     *
     *   - statusnya `Berjalan` - ujiannya memang sedang berlangsung;
     *   - eventnya sudah masuk rentang tanggalnya;
     *   - ada sesi yang dibuka dalam `MENIT_SEGERA` menit ke depan.
     *
     * Yang ketiga penting karena event berstatus `Siap` yang sesinya dibuka
     * sepuluh menit lagi belum "berjalan" dan tanggalnya mungkin baru besok,
     * tetapi Adminnya sudah harus tahu sekarang.
     *
     * @param  Collection<int, ExamSession>  $sesi
     */
    private function sudahDekat(SchoolEvent $event, Collection $sesi): bool
    {
        if ($event->status === EventStatus::Ongoing) {
            return true;
        }

        $kini = now();

        if ($event->start_at !== null && $event->end_at !== null
            && $event->start_at->lte($kini) && $event->end_at->gte($kini)) {
            return true;
        }

        return $sesi->contains(fn (ExamSession $s) => $s->access_opens_at !== null
            && $s->access_opens_at->isFuture()
            && $kini->diffInMinutes($s->access_opens_at) <= self::MENIT_SEGERA);
    }

    /** Nama ujian yang dibaca Admin, tanpa embel-embel mapel yang panjang. */
    private function namaSesi(ExamSession $s): string
    {
        $mapel = $s->exam?->subject?->name;

        return $mapel === null ? 'Ujian' : $mapel;
    }

    private function eventId(ExamSession $s): ?int
    {
        return $s->exam?->event_id;
    }
}
