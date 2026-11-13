<?php

namespace App\Domain\Exam\Services;

use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\ExamEventShuffle;
use App\Domain\Exam\Models\ExamParticipant;
use Illuminate\Support\Collection;

/**
 * Nomor kursi menurut RUANG EVENT - satu aturan untuk seluruh permukaan.
 *
 * =====================================================================
 * Mengapa ruang EVENT, bukan ruang SESI
 * =====================================================================
 * Sampai 2026-11-09 setiap permukaan menomori kursi atas penempatan SATU
 * RUANG-SESI, yaitu baris-baris `exam_participants` yang menunjuk
 * `exam_session_room_id` yang sama. Bentuk itu menomori orang menurut ujian
 * yang sedang mereka kerjakan, dan itu bukan pertanyaan yang ditanyakan
 * siapa pun:
 *
 *   "Anak saya di kursi berapa?"  -> berlaku sepanjang ujian, bukan per mapel.
 *
 * Kursi anak tidak berpindah antar sesi: ruangnya satu sepanjang event, dan
 * meja di ruang itu tidak disusun ulang tiap mapel. Karena itu nomornya
 * dihitung atas seluruh penghuni RUANG EVENT, dan hasilnya sama di sesi mana
 * pun.
 *
 * =====================================================================
 * Penghuninya dihitung ULANG dari benih, bukan dibaca dari baris tersimpan
 * =====================================================================
 * Sampai 2026-11-10 kelas ini mengambil penghuni ruang dari
 * `exam_participants.exam_session_room_id` - yaitu baris yang SUDAH disebar.
 * Itu salah, dan kesalahannya terlihat di layar: pada event yang hanya
 * SEBAGIAN ujiannya punya sesi, penyebaran hanya menulis kursi untuk ujian
 * yang punya sesi. Ruang berkapasitas 39 pun terbaca "terisi 17", dan denah
 * ruang 1 hanya memuat kelas 7 - kelas 8 dan 9 punya baris `exam_participants`
 * tetapi tidak satu pun menunjuk ruang, karena ujiannya belum punya sesi.
 *
 * Yang harus dijawab denah, tablo, dan papan pengawas bukan "siapa yang
 * kursinya sudah tersimpan", melainkan "siapa yang DUDUK DI SINI menurut
 * kocokan event". Kocokan itu tidak hilang: benihnya tersimpan di
 * `exam_event_shuffles`, dan `PenempatanKocok::susun()` menghasilkannya ulang
 * dengan urutan yang sama setiap kali. Jadi yang dibaca di sini adalah peta
 * benih itu - `per_ruang`, berkunci `event_room_id`, berisi `EventParticipant`.
 *
 * Konsekuensinya harus disebut terang-terangan: selama penyebaran belum
 * selesai, kursi di kertas bisa berbeda dari kursi yang tersimpan di
 * `exam_participants`. Denah yang menggambar kocokan adalah denah yang
 * menggambar APA YANG AKAN BERLAKU - dan itu memang yang ditempel di meja.
 *
 * =====================================================================
 * Tanpa benih tersimpan, yang dipakai baris tersimpan
 * =====================================================================
 * Event yang belum pernah dikocok tidak punya peta untuk dihitung ulang.
 * Membaca seed kosong lalu menggambar ruangan kosong akan menghapus satu-
 * satunya kebenaran yang ada - yaitu baris yang sudah tersimpan. Karena itu
 * `susunan()` mengembalikan null bila benihnya tidak ada, dan setiap method
 * di kelas ini jatuh kembali ke perilaku lamanya.
 *
 * =====================================================================
 * Kuncinya `student_id`, bukan id penempatan
 * =====================================================================
 * Satu siswa bisa punya LEBIH DARI SATU baris `exam_participants` di event
 * yang sama - satu per ujian yang diikutinya - dan semuanya menunjuk ruang
 * yang sama. Menomori barisnya berarti siswa itu muncul di dua kursi
 * sekaligus. Karena itu daftarnya dipegang sekali per siswa: kursinya
 * di-key dengan `student_id`, dan `student_id` juga yang dipakai sebagai
 * pemutus seri nama kembar - Sehingga denah, papan pengawas, dan laman siswa
 * menyebut angka yang sama.
 *
 * Nomor kursinya sendiri tetap dihitung `NomorKursi` - aturan abjad nama
 * yang mengikat seluruh aplikasi. Kelas ini hanya menentukan SIAPA YANG
 * DIHITUNG, bukan bagaimana urutannya.
 */
final class PetaKursiEvent
{
    /**
     * Hasil `susun()` per event, di memori satu permintaan.
     *
     * `susun()` membaca seluruh peserta event lalu mengocoknya - O(1265) pada
     * event besar. Satu laman denah memanggilnya dua kali (cacah ruang dan
     * gambar ruang), dan tablo seluruh ruang memanggilnya sekali per ruang.
     * Tanpa simpanan ini laman ruang-33 menjalankan tiga puluh tiga kocokan
     * penuh.
     *
     * Yang disimpan hanya peta `per_ruang`-nya, dan hanya untuk umur satu
     * permintaan: kelas ini diikat `scoped` di ExamServiceProvider, sehingga
     * simpanannya dibuang setiap permintaan selesai. Tanpa pengikatan itu
     * yang tersimpan adalah hasil permintaan SEBELUMNYA, dan laman bisa
     * menggambar kocokan lama.
     *
     * @var array<int, array<int, array<int, EventParticipant>>|null>
     */
    private array $susunan = [];

    /**
     * @var array<int, array<int, array{id: ?int, student_id: int, kursi: int, nama: ?string, nis: ?string, kelas: ?string}>>
     */
    private array $isi = [];

    /**
     * Id penempatan yang sudah dimuat, dikelompokkan per `student_id`.
     *
     * Dipakai `payload()` supaya satu laman yang menggambar tiga puluh tiga
     * ruang tidak menjalankan tiga puluh tiga kueri untuk pertanyaan yang
     * sama. Nilainya `Collection` biasa (bukan Eloquent) karena `groupBy`
     * mengembalikan bentuk itu - lihat catatan jebakan di
     * `PemantauanEventController::statistikButir()`.
     *
     * @var Collection<int, Collection<int, ExamParticipant>>|null
     */
    private ?Collection $penempatan = null;

    public function __construct(
        private readonly PenempatanKocok $kocok,
        private readonly NomorKursi $kursi,
    ) {}

    /**
     * Penghuni satu ruang event menurut kocokan BENIH tersimpan.
     *
     * @return Collection<int, EventParticipant>|null Null bila belum ada benih.
     */
    public function anggotaBenih(int $eventRoomId): ?Collection
    {
        $perRuang = $this->perRuang($eventRoomId);

        if ($perRuang === null) {
            return null;
        }

        return collect($perRuang[$eventRoomId] ?? []);
    }

    /**
     * Penghuni SELURUH ruang event, lengkap dengan nama, NIS, dan kelasnya.
     *
     * Dipakai laman yang menggambar banyak ruang sekaligus, dan bentuknya
     * ada karena `barisBenih()` dipanggil per ruang: tiga puluh tiga
     * pemanggilan berarti tiga puluh tiga kueri id penempatan, untuk
     * pekerjaan yang bisa diselesaikan satu. Method ini memuat seluruh id
     * penempatannya lebih dulu, sehingga setiap `payload()` sesudahnya
     * membaca simpanan di memori.
     *
     * @return array<int, array<int, array{id: ?int, student_id: int, kursi: int, nama: ?string, nis: ?string, kelas: ?string}>>|null
     *                                                                                                                                Kunci = event_room_id, null bila benihnya belum tersimpan.
     */
    public function rosterEvent(SchoolEvent $event): ?array
    {
        $peta = $this->perRuangDariEvent($event);

        if ($peta === null) {
            return null;
        }

        $idSiswa = [];

        foreach ($peta as $anggota) {
            foreach ($anggota as $p) {
                $idSiswa[(int) $p->student_id] = true;
            }
        }

        $this->muatPenempatan(array_keys($idSiswa));

        $hasil = [];

        foreach ($peta as $idRuang => $anggota) {
            $hasil[(int) $idRuang] = $this->payload(collect($anggota));
        }

        return $hasil;
    }

    /**
     * Penghuni satu ruang event dari baris yang SUDAH disebar.
     *
     * Bentuk lama, dan tetap dipakai sebagai cadangan - lihat catatan kelas
     * ini tentang event yang belum pernah dikocok.
     *
     * @return Collection<int, ExamParticipant>
     */
    public function anggota(int $eventRoomId): Collection
    {
        return $this->kursi->urutkan(
            $this->baris($eventRoomId)->unique('student_id')->values(),
        );
    }

    /**
     * Nomor kursi setiap siswa di satu ruang event.
     *
     * Dengan benih tersimpan, nomornya datang dari peta benih. Tanpa benih,
     * bentuk lamanya yang dipakai - lihat catatan kelas ini tentang mengapa
     * peta kosong bukan jawaban yang benar untuk event yang belum dikocok.
     *
     * @return array<int, int> student_id => kursi (mulai 1)
     */
    public function kursi(int $eventRoomId): array
    {
        if ($eventRoomId <= 0) {
            return [];
        }

        $anggota = $this->anggotaBenih($eventRoomId);

        if ($anggota === null) {
            return $this->kursi->beriNomor(
                $this->anggota($eventRoomId),
                fn (ExamParticipant $p) => (int) $p->student_id,
            );
        }

        return $this->nomorDari($anggota);
    }

    /**
     * Nomor kursi satu siswa di ruang event-nya sendiri.
     *
     * Dipakai laman siswa, yang memegang satu penempatan tanpa daftar
     * ruangnya. Kuerinya menyaring lewat `room.event_room_id`, sehingga yang
     * dimuat hanya penghuni satu ruang - bukan seluruh peserta event. Bila
     * benihnya tersimpan, nomornya datang dari peta benih itu.
     */
    public function kursiSatu(ExamParticipant $peserta): ?int
    {
        $peserta->loadMissing('room:id,event_room_id');

        $idRuang = (int) $peserta->room?->event_room_id;

        if ($idRuang <= 0) {
            return null;
        }

        return $this->kursi($idRuang)[(int) $peserta->student_id] ?? null;
    }

    /**
     * Cacah penghuni tiap ruang event.
     *
     * Dengan benih tersimpan, angkanya datang dari peta benih - dan itu yang
     * membuat "39/39" di pemilih ruang denah sama dengan "39" di laman
     * penempatan. Tanpa benih, bentuk lamanya dipakai: satu kueri untuk
     * seluruh ruang, bukan satu per ruang.
     *
     * ---------------------------------------------------------------------
     * Eventnya dicapai lewat `room.session.exam`, bukan lewat kolom event_id
     * ---------------------------------------------------------------------
     * `exam_participants` TIDAK punya kolom `event_id` - jangan tertukar
     * dengan `event_participants` yang punya. Satu-satunya rantai yang ada
     * dari sebuah penempatan ke eventnya adalah
     * `exam_session_room_id -> exam_session_rooms.exam_session_id ->
     * exam_sessions.exam_id -> exams.event_id`, dan itulah yang ditelusuri
     * `whereHas` di bawah. Rantai yang sama dipakai `baris()`.
     *
     * @return Collection<int, int> event_room_id => jumlah siswa
     */
    public function terisiPerRuang(SchoolEvent $event): Collection
    {
        $perRuang = $this->perRuangDariEvent($event);

        if ($perRuang !== null) {
            return collect($perRuang)
                ->map(fn (array $anggota) => collect($anggota)->unique('student_id')->count());
        }

        return ExamParticipant::query()
            ->whereHas('room.session.exam', fn ($q) => $q->where('event_id', $event->id))
            ->with('room:id,event_room_id')
            ->get()
            ->groupBy(fn (ExamParticipant $p) => (int) $p->room?->event_room_id)
            // Kuncinya dibiarkan tanpa tipe: `groupBy` di atas mengembalikan
            // int, tetapi `Collection::filter` menurunkan kunci apa adanya dan
            // parameter bertipe `int` di sini akan meledak sebagai TypeError
            // begitu ada satu kunci yang menjadi teks.
            ->filter(fn (Collection $anggota, $idRuang) => (int) $idRuang > 0)
            ->map(fn (Collection $anggota) => $anggota->unique('student_id')->count());
    }

    /**
     * Seluruh kursi satu ruang event, termasuk yang kosong - bahan denah.
     *
     * Bentuknya sengaja sama dengan `NomorKursi::denah()`, karena laman denah
     * dan tablo sudah membaca bentuk itu. Yang berbeda hanya dari mana
     * penghuninya datang.
     *
     * @return array<int, array{kursi: int, meja: int, kolom: int, baris: int, peserta: array<string, mixed>|null}>
     */
    public function sel(int $eventRoomId, int $kapasitas): array
    {
        $anggota = $this->anggotaBenih($eventRoomId);

        if ($anggota === null) {
            return $this->kursi->denah($this->anggota($eventRoomId), $kapasitas);
        }

        $isi = $this->payload($anggota);

        return $this->rakitSel($isi, $kapasitas);
    }

    /**
     * Daftar kursi satu ruang event, urut nomor - bahan tablo PDF.
     *
     * @return array<int, array<string, mixed>>
     */
    public function barisRuang(int $eventRoomId): array
    {
        $benih = $this->barisBenih($eventRoomId);

        if ($benih === null) {
            return $this->barisTersimpan($eventRoomId);
        }

        return $benih;
    }

    /**
     * Daftar kursi satu ruang menurut kocokan benih, atau null tanpa benih.
     *
     * Dipisahkan dari `barisRuang()` karena laman presensi Admin harus bisa
     * Membedakan "event ini dikocok" dari "event ini belum dikocok": yang
     * pertama boleh menyebut seluruh penghuni ruang, yang kedua hanya boleh
     * menyebut baris yang sudah disebar. Menggabungkan keduanya di satu
     * method berarti laman presensi memakai peta kosong pada event yang
     * belum dikocok - dan daftar anak yang kosong lebih buruk daripada
     * daftar yang lebih pendek dari seharusnya.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function barisBenih(int $eventRoomId): ?array
    {
        $anggota = $this->anggotaBenih($eventRoomId);

        if ($anggota === null) {
            return null;
        }

        $baris = [];

        foreach ($this->payload($anggota) as $p) {
            $baris[] = [
                'kursi' => $p['kursi'],
                'meja' => NomorKursi::meja($p['kursi']),
                'peserta_id' => $p['id'],
                'student_id' => $p['student_id'],
                'nama' => $p['nama'],
                'nis' => $p['nis'],
                'kelas' => $p['kelas'],
            ];
        }

        return $baris;
    }

    /**
     * Bentuk `baris` lama, dari penempatan yang sudah menunjuk ruangnya.
     *
     * @return array<int, array<string, mixed>>
     */
    private function barisTersimpan(int $eventRoomId): array
    {
        $penempatan = $this->anggota($eventRoomId);
        $peta = $this->kursi->peta($penempatan);
        $olehId = $penempatan->keyBy('id');

        $baris = [];

        asort($peta);

        foreach ($peta as $id => $kursi) {
            /** @var ExamParticipant|null $p */
            $p = $olehId->get($id);

            if ($p === null) {
                continue;
            }

            $baris[] = [
                'kursi' => $kursi,
                'meja' => NomorKursi::meja($kursi),
                'peserta_id' => (int) $p->id,
                'student_id' => (int) $p->student_id,
                'nama' => $p->student?->name ?? 'Peserta',
                'nis' => $p->student?->studentProfile?->nis,
                'kelas' => $p->pesertaEvent?->kelas?->name,
            ];
        }

        return $baris;
    }

    /**
     * Nomor kursi yang mengikat untuk sekumpulan penghuni, atau array kosong.
     *
     * @param  Collection<int, EventParticipant>|null  $anggota
     * @return array<int, int>
     */
    private function nomorDari(?Collection $anggota): array
    {
        if ($anggota === null || $anggota->isEmpty()) {
            return [];
        }

        $peta = [];

        foreach ($this->payload($anggota) as $p) {
            $peta[$p['student_id']] = $p['kursi'];
        }

        return $peta;
    }

    /**
     * Penghuni benih, berurut nomor, lengkap dengan nomor dan id penempatannya.
     *
     * Dua pekerjaan dalam satu tempat karena keduanya harus memakai urutan
     * yang sama: nomor kursi DITENTUKAN oleh urutan ini, sehingga menghitung
     * nomornya terpisah berarti menghitung dua urutan yang bisa berbeda.
     *
     * `id` adalah id `exam_participants` milik siswa di event ini, dan boleh
     * null: peserta yang ujiannya belum ditarik memang belum punya baris
     * ujian. Nilai null itu penting - ia membuat laman tidak menautkan baris
     * ke rute yang tidak ada.
     *
     * @param  Collection<int, EventParticipant>  $anggota
     * @return array<int, array{id: ?int, student_id: int, kursi: int, nama: ?string, nis: ?string, kelas: ?string}>
     */
    private function payload(Collection $anggota): array
    {
        $dasar = $anggota
            // Dieduplikasi per siswa: satu siswa punya satu baris penempatan
            // per ujian yang diikutinya, dan menomori barisnya akan menaruh
            // siswa yang sama di dua kursi.
            ->unique('student_id')
            ->sortBy(fn (EventParticipant $p) => NomorKursi::kunci(
                $p->student?->name,
                (int) $p->student_id,
            ))
            ->values();

        $idSiswa = $dasar->pluck('student_id')->map(fn ($id) => (int) $id)->all();
        $tanda = implode(',', $idSiswa);

        // Satu ruang dibaca sampai tiga kali oleh satu laman - cacah, nomor,
        // dan gambar. Penandanya daftar siswanya yang sudah berurut, sehingga
        // panggilan kedua atas himpunan yang sama tidak menyentuh basis data.
        if (isset($this->isi[$tanda])) {
            return $this->isi[$tanda];
        }

        // Satu kueri untuk id penempatan seluruh ruang, bukan satu per siswa.
        // `muatPenempatan()` menyimpan hasilnya, sehingga laman yang membaca
        // tiga puluh tiga ruang tidak menjalankan tiga puluh tiga kueri.
        $this->muatPenempatan($idSiswa);
        $idPenempatan = $this->penempatan ?? collect();

        $hasil = [];

        foreach ($dasar as $i => $p) {
            $baris = $idPenempatan->get((int) $p->student_id);

            $hasil[] = [
                // Id penempatan ujiannya hanya dipakai bila TUNGGAL. Siswa
                // yang ikut dua ujian punya dua baris, dan memilih salah
                // satunya berarti menautkan baris ini ke ujian yang mungkin
                // bukan ujian ruang ini.
                'id' => $baris?->count() === 1 ? (int) $baris->first()->id : null,
                'student_id' => (int) $p->student_id,
                'kursi' => $i + 1,
                'nama' => $p->student?->name,
                'nis' => $p->student?->studentProfile?->nis,
                'kelas' => $p->kelas?->name,
            ];
        }

        return $this->isi[$tanda] = $hasil;
    }

    /**
     * Id penempatan setiap siswa, dikelompokkan per `student_id`.
     *
     * Dipakai laman yang perlu menautkan seorang siswa ke baris
     * `exam_participants`-nya SEMUA - bukan hanya bila tunggal seperti di
     * `payload()`. Laman presensi Admin membutuhkannya untuk mencari baris
     * presensi, dan seorang siswa yang ikut dua ujian punya dua baris yang
     * keduanya harus diperiksa.
     *
     * Dibaca dari simpanan yang sama dengan `payload()`, sehingga pemanggil
     * yang sudah menjalankan `rosterEvent()` tidak menjalankan kueri kedua.
     *
     * @param  array<int, int>  $idSiswa
     * @return Collection<int, Collection<int, ExamParticipant>>
     */
    public function penempatanPerSiswa(array $idSiswa): Collection
    {
        $this->muatPenempatan($idSiswa);

        return $this->penempatan ?? collect();
    }

    /**
     * Muat id penempatan untuk sekumpulan siswa, sekali saja per permintaan.
     *
     * Yang belum ada di simpanan ditanyakan, sisanya dibiarkan. Simpanannya
     * MENUMPUK, bukan diganti: laman presensi memanggil ini sekali untuk
     * seluruh event, lalu `payload()` per ruang menemukan setiap id yang
     * dibutuhkannya sudah ada - dan laman siswa yang memanggil satu ruang
     * tetap mendapat satu kueri kecil.
     *
     * @param  array<int, int>  $idSiswa
     */
    private function muatPenempatan(array $idSiswa): void
    {
        if ($idSiswa === []) {
            return;
        }

        $this->penempatan ??= collect();

        $belum = [];

        foreach ($idSiswa as $id) {
            if (! $this->penempatan->has((int) $id)) {
                $belum[] = (int) $id;
            }
        }

        if ($belum === []) {
            return;
        }

        $baru = ExamParticipant::query()
            ->whereIn('student_id', $belum)
            // `exam_id` ikut dimuat supaya pemanggil bisa menyaring penempatan
            // ke ujian-ujian SATU event. Seorang siswa bisa terdaftar di dua
            // event, dan tanpa kolom ini baris presensi event lain akan ikut
            // terbaca.
            ->get(['id', 'student_id', 'exam_id'])
            ->groupBy('student_id');

        foreach ($baru as $id => $baris) {
            $this->penempatan->put((int) $id, $baris);
        }

        // Siswa yang TIDAK punya baris penempatan sama sekali tetap ditandai
        // sudah ditanyakan, supaya kueri berikutnya untuk himpunan yang sama
        // tidak mengulanginya.
        foreach ($belum as $id) {
            if (! $this->penempatan->has($id)) {
                $this->penempatan->put($id, collect());
            }
        }
    }

    /**
     * Susun sel denah dari payload yang sudah berurut.
     *
     * Kursi yang terisi di luar kapasitas tetap digambar: denah yang
     * menyembunyikan anak yang benar-benar duduk di sana adalah denah yang
     * berbohong tentang ruangannya.
     *
     * @param  array<int, array{id: ?int, student_id: int, kursi: int, nama: ?string, nis: ?string, kelas: ?string}>  $isi
     * @return array<int, array{kursi: int, meja: int, kolom: int, baris: int, peserta: array<string, mixed>|null}>
     */
    private function rakitSel(array $isi, int $kapasitas): array
    {
        $olehKursi = [];

        foreach ($isi as $p) {
            $olehKursi[$p['kursi']] = $p;
        }

        $jumlah = max($kapasitas, count($olehKursi));
        $sel = [];

        for ($kursi = 1; $kursi <= $jumlah; $kursi++) {
            $meja = NomorKursi::meja($kursi);
            $p = $olehKursi[$kursi] ?? null;

            $sel[] = [
                'kursi' => $kursi,
                'meja' => $meja,
                'baris' => intdiv($meja - 1, NomorKursi::MEJA_PER_BARIS) + 1,
                'kolom' => (($meja - 1) % NomorKursi::MEJA_PER_BARIS) + 1,
                'peserta' => $p === null ? null : [
                    'id' => $p['id'],
                    'student_id' => $p['student_id'],
                    'nama' => $p['nama'] ?? 'Peserta',
                    'nis' => $p['nis'],
                    'kelas' => $p['kelas'],
                ],
            ];
        }

        return $sel;
    }

    /**
     * Peta `per_ruang` satu ruang event, atau null bila benihnya tidak ada.
     *
     * @return array<int, array<int, EventParticipant>>|null
     */
    private function perRuang(int $eventRoomId): ?array
    {
        if ($eventRoomId <= 0) {
            return null;
        }

        $idEvent = (int) EventRoom::query()->whereKey($eventRoomId)->value('event_id');

        if ($idEvent <= 0) {
            return null;
        }

        $event = SchoolEvent::query()->find($idEvent);

        return $event === null ? null : $this->perRuangDariEvent($event);
    }

    /**
     * Peta `per_ruang` seluruh event menurut benih yang TERSIMPAN.
     *
     * Benih yang belum tersimpan berarti eventnya belum pernah dikocok, dan
     * yang berlaku adalah baris yang sudah ada - bukan peta kosong.
     *
     * @return array<int, array<int, EventParticipant>>|null
     */
    private function perRuangDariEvent(SchoolEvent $event): ?array
    {
        if (array_key_exists($event->id, $this->susunan)) {
            return $this->susunan[$event->id];
        }

        $tersimpan = ExamEventShuffle::query()
            ->where('event_id', $event->id)
            ->first(['seed', 'mode']);

        $peta = null;

        if ($tersimpan !== null && $tersimpan->seed !== '') {
            try {
                // MODE ikut dikirim, bukan dibiarkan memakai bawaan. Denah
                // yang menghitung ulang dengan mode berbeda dari yang
                // disebar menaruh anak di kursi orang lain - dan itu baru
                // ketahuan setelah kertasnya ditempel di pintu.
                $peta = $this->kocok->susun($event, $tersimpan->seed, $tersimpan->mode)['per_ruang'];
            } catch (\DomainException) {
                // Event tanpa ruang aktif tidak punya peta untuk dihitung.
                // Melemparnya dari sini akan merusak denah event yang baru
                // dibuat - dan yang benar untuk laman itu adalah denah kosong.
                $peta = null;
            }
        }

        return $this->susunan[$event->id] = $peta;
    }

    /**
     * Baris penempatan tersimpan yang menunjuk satu ruang event.
     *
     * @return Collection<int, ExamParticipant>
     */
    private function baris(int $eventRoomId): Collection
    {
        return ExamParticipant::query()
            ->whereHas('room', fn ($q) => $q->where('event_room_id', $eventRoomId))
            ->with([
                'student:id,name',
                'student.studentProfile:id,user_id,nis',
                'pesertaEvent.kelas:id,name',
            ])
            ->get();
    }
}
