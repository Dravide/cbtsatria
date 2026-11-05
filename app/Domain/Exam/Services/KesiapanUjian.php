<?php

namespace App\Domain\Exam\Services;

use App\Domain\Event\Contracts\ButirKesiapanUjian;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\ExamToken;
use Illuminate\Support\Collection;

/**
 * Butir kesiapan yang hanya bisa dijawab modul ujian.
 *
 * ---------------------------------------------------------------------
 * Implementasi kontrak, dan itu yang memutus lingkaran impor
 * ---------------------------------------------------------------------
 * EventReadiness menyelesaikan kelas ini dari container, lalu menyambung
 * keempat butirnya ke sebelas butirnya sendiri. Lihat ButirKesiapanUjian
 * untuk alasan lengkapnya: satu arah, bukan lingkaran.
 *
 * ---------------------------------------------------------------------
 * Yang berubah dari Fase 3, dan yang sengaja TIDAK berubah
 * ---------------------------------------------------------------------
 * Docblock EventReadiness menjanjikan: "Perubahan yang terjadi saat Fase 4-5
 * tiba adalah satu baris: butir itu pindah dari 'menunggu' ke 'belum'." Itu
 * yang terjadi di sini untuk jadwal, pengawas, token, dan kapasitas.
 *
 * Yang TIDAK berubah adalah daftar butirnya. `paket` tidak pernah pindah ke
 * kelas ini, dan sesudah Fase 4 pun tidak akan: paket soal adalah properti
 * MAPEL EVENT (`event_subjects.question_package_id`), bukan properti sesi
 * ujian. Satu mapel biasanya memakai SATU paket untuk semua kelasnya, dan
 * memeriksanya per sesi berarti event dengan tiga sesi menuntut tiga paket -
 * padahal Admin memasangnya sekali di plate 15.
 *
 * Karena itu butir `paket` tetap dijawab `EventReadiness::paketSoal()`, dan
 * yang menyeberang dari modul Question hanyalah angka dan teks lewat
 * `PemasanganPaket`. Kelas ini mengurus empat butir yang memang milik sesi:
 * jadwal, pengawas, token, kapasitas.
 *
 * ---------------------------------------------------------------------
 * Mengapa pengawas berganti sumber, dan itu disengaja
 * ---------------------------------------------------------------------
 * Fase 3 membaca "pengawas ditugaskan" dari kepanitiaan. Fase 5 membacanya
 * dari penugasan ruang-sesi, dan kalimat plate 17 adalah alasannya:
 * "penugasan inilah yang memberi Pengawas hak melihat dan memverifikasi
 * presensi di ruang itu. Tanpa penugasan, ia tidak melihat apa pun."
 *
 * Daftar panitia tidak membuat satu ruang pun bisa dibuka. Mempertahankannya
 * sebagai sumber akan membuat gate melaporkan "siap" untuk event yang setiap
 * ruangnya tidak bisa dibuka pengawasnya.
 */
class KesiapanUjian implements ButirKesiapanUjian
{
    public function __construct(
        private readonly KonflikJadwal $konflik,
    ) {}

    /** @return array<string, array{kunci: string, label: string, keadaan: string, keterangan: ?string, perbaikan: ?string}> */
    public function butir(SchoolEvent $event): array
    {
        $ujian = $event->exams()->get();
        $sesi = ExamSession::query()
            ->whereIn('exam_id', $ujian->pluck('id'))
            ->get();

        return [
            'jadwal' => $this->jadwal($event, $sesi),
            'pengawas' => $this->pengawas($event, $sesi),
            'token' => $this->token($sesi),
            'kapasitas' => $this->kapasitas($event, $ujian, $sesi),
        ];
    }

    public function jumlahUjian(SchoolEvent $event): int
    {
        return $event->exams()->count();
    }

    /* ------------------------------------------------------------- Butir */

    /** @return array<string, mixed> */
    private function jadwal(SchoolEvent $event, Collection $sesi): array
    {
        if ($sesi->isEmpty()) {
            return $this->butirGagal(
                'jadwal',
                'Jadwal ujian tersedia',
                'Belum ada sesi ujian di event ini.',
                "/admin/events/{$event->id}/jadwal",
            );
        }

        $tanpaRuang = $sesi->filter(fn (ExamSession $s) => $s->rooms()->doesntExist());

        if ($tanpaRuang->isNotEmpty()) {
            return $this->butirGagal(
                'jadwal',
                'Jadwal ujian tersedia',
                $tanpaRuang->count().' sesi belum punya ruang.',
                "/admin/events/{$event->id}/jadwal",
            );
        }

        // Konflik diperiksa SEBELUM butir ini melaporkan lulus, dan itulah
        // bagian yang membuat gerbangnya berarti: PRD §6.5 meminta "tidak
        // terdapat konflik jadwal atau kapasitas yang belum diselesaikan", dan
        // jadwal yang bentrok bukan jadwal.
        $temuan = $this->konflik->periksa($event);
        $benturan = array_filter($temuan, fn (array $t) => in_array($t['jenis'], [
            'benturan-ruang', 'benturan-peserta', 'benturan-pengawas',
            'ruang-tidak-aktif', 'ruang-luar-event',
        ], true));

        if ($benturan !== []) {
            $pertama = array_values($benturan)[0];

            return $this->butirGagal(
                'jadwal',
                'Jadwal ujian tersedia',
                count($benturan).' benturan jadwal belum diselesaikan. '.$pertama['pesan'],
                "/admin/events/{$event->id}/jadwal",
            );
        }

        return $this->butirOk('jadwal', 'Jadwal ujian tersedia', $sesi->count().' sesi');
    }

    /** @return array<string, mixed> */
    private function pengawas(SchoolEvent $event, Collection $sesi): array
    {
        if ($sesi->isEmpty()) {
            return $this->butirGagal(
                'pengawas',
                'Pengawas ditugaskan',
                'Belum ada sesi, sehingga belum ada ruang yang bisa ditugaskan.',
                "/admin/events/{$event->id}/jadwal",
            );
        }

        $ruangan = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $sesi->pluck('id'))
            ->with(['supervisors', 'eventRoom.room:id,code'])
            ->get();

        if ($ruangan->isEmpty()) {
            return $this->butirGagal(
                'pengawas',
                'Pengawas ditugaskan',
                'Belum ada ruang yang dipakai sesi mana pun.',
                "/admin/events/{$event->id}/jadwal",
            );
        }

        $kosong = $ruangan->filter(fn (ExamSessionRoom $r) => $r->supervisors->isEmpty());

        if ($kosong->isNotEmpty()) {
            $kode = $kosong
                ->map(fn (ExamSessionRoom $r) => $r->eventRoom?->room?->code ?? 'R-?')
                ->unique()
                ->values()
                ->all();

            return $this->butirGagal(
                'pengawas',
                'Pengawas ditugaskan',
                'Ruang berikut belum punya pengawas: '.implode(', ', $kode).'.',
                "/admin/events/{$event->id}/pengawas",
            );
        }

        $orang = $ruangan->flatMap(fn (ExamSessionRoom $r) => $r->supervisors->pluck('supervisor_id'))
            ->unique()
            ->count();

        return $this->butirOk('pengawas', 'Pengawas ditugaskan', $orang.' orang di '.$ruangan->count().' ruang');
    }

    /** @return array<string, mixed> */
    private function token(Collection $sesi): array
    {
        $aktif = $sesi->filter(fn (ExamSession $s) => ! $s->status->final());

        if ($aktif->isEmpty()) {
            return $this->butirGagal(
                'token',
                'Token tersedia',
                'Belum ada sesi aktif yang memerlukan token.',
                null,
            );
        }

        // Token lama (kedaluwarsa) tidak dihitung: sesi yang tokennya sudah
        // lewat jendelanya akan ditolak di pintu, dan melaporkannya "ok"
        // berarti gate berbohong tentang hal yang justru membuat siswa tidak
        // bisa masuk.
        //
        // Token yang jendelanya BELUM dibuka justru dihitung - dan itu
        // pembedaan yang disengaja. Sekolah menerbitkan token saat menyusun
        // jadwal, bukan pada menit sesinya dibuka, sehingga memakai
        // `berlaku()` di sini berarti event hanya bisa menjadi Siap selama
        // sesinya sedang berlangsung. Lihat ExamToken::belumKedaluwarsa().
        $idSesi = $aktif->pluck('id')->all();

        $punyaToken = ExamToken::query()
            ->whereIn('exam_session_id', $idSesi)
            ->whereNull('revoked_at')
            ->get()
            ->filter(fn (ExamToken $t) => $t->belumKedaluwarsa())
            ->pluck('exam_session_id')
            ->unique()
            ->all();

        $kurang = $aktif->reject(fn (ExamSession $s) => in_array($s->id, $punyaToken, true));

        if ($kurang->isNotEmpty()) {
            return $this->butirGagal(
                'token',
                'Token tersedia',
                $kurang->count().' sesi belum punya token yang berlaku.',
                $kurang->first()->rooms()->exists()
                    ? "/admin/events/{$kurang->first()->exam?->event_id}/token"
                    : "/admin/events/{$kurang->first()->exam?->event_id}/jadwal",
            );
        }

        return $this->butirOk('token', 'Token tersedia', $punyaToken === [] ? null : count($punyaToken).' sesi bertoken');
    }

    /**
     * Kapasitas diperiksa PER RUANG-SESI, bukan total kursi.
     *
     * Fase 3 membandingkan total kursi terhadap jumlah peserta, dan itu ukuran
     * yang menyesatkan begitu ada sesi: sekolah dengan 200 kursi dan 150
     * peserta bisa tetap gagal bila sesi 1 hanya memakai dua ruang berkapasitas
     * 30. Pertanyaan yang benar adalah "apakah setiap ruang-sesi memuat
     * peserta yang ditempatkan di sana" - dan itulah yang diperiksa di sini.
     *
     * @param  Collection<int, Exam>  $ujian
     */
    private function kapasitas(SchoolEvent $event, Collection $ujian, Collection $sesi): array
    {
        if ($sesi->isEmpty()) {
            return $this->butirGagal(
                'kapasitas',
                'Kapasitas ruang mencukupi',
                'Belum ada sesi, sehingga kapasitasnya belum bisa diperiksa.',
                "/admin/events/{$event->id}/jadwal",
            );
        }

        $temuan = array_filter(
            $this->konflik->periksa($event),
            fn (array $t) => $t['jenis'] === 'kapasitas-ruang',
        );

        if ($temuan !== []) {
            return $this->butirGagal(
                'kapasitas',
                'Kapasitas ruang mencukupi',
                array_values($temuan)[0]['pesan'],
                "/admin/events/{$event->id}/jadwal",
            );
        }

        // Peserta yang terdaftar di ujian tetapi belum ditempatkan ke ruang
        // juga merupakan kegagalan kapasitas: ia adalah siswa yang akan berdiri
        // di depan pintu tanpa nama di daftar pengawas.
        $menggantung = ExamParticipant::query()
            ->whereIn('exam_id', $ujian->pluck('id'))
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->whereNull('exam_session_room_id')
            ->count();

        if ($menggantung > 0) {
            return $this->butirGagal(
                'kapasitas',
                'Kapasitas ruang mencukupi',
                "{$menggantung} peserta belum ditempatkan ke ruang mana pun.",
                "/admin/events/{$event->id}/jadwal",
            );
        }

        $kursi = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $sesi->pluck('id'))
            ->with('eventRoom.room:id,capacity')
            ->get()
            ->sum(fn (ExamSessionRoom $r) => $r->kapasitasEfektif());

        return $this->butirOk('kapasitas', 'Kapasitas ruang mencukupi', $kursi.' kursi di '.$sesi->count().' sesi');
    }

    /* ----------------------------------------------------------- Pembantu */

    /** @return array<string, mixed> */
    private function butirGagal(string $kunci, string $label, string $keterangan, ?string $perbaikan): array
    {
        return [
            'kunci' => $kunci,
            'label' => $label,
            'keadaan' => 'belum',
            'keterangan' => $keterangan,
            'perbaikan' => $perbaikan,
        ];
    }

    /** @return array<string, mixed> */
    private function butirOk(string $kunci, string $label, ?string $keterangan): array
    {
        return [
            'kunci' => $kunci,
            'label' => $label,
            'keadaan' => 'ok',
            'keterangan' => $keterangan,
            'perbaikan' => null,
        ];
    }
}
