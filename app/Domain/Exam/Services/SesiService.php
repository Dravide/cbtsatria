<?php

namespace App\Domain\Exam\Services;

use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Services\EventStatusService;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sesi ujian: penyusunan jendela waktu, pembukaan, dan penutupan.
 *
 * ---------------------------------------------------------------------
 * Mengapa urutan tiga waktu diperiksa di sini, bukan di Form Request
 * ---------------------------------------------------------------------
 * `access_opens_at < access_closes_at ≤ hard_end_at` terdengar seperti aturan
 * satu form, dan karena itu godaannya adalah menaruhnya di validasi request.
 * Godaan itu salah untuk dua alasan:
 *
 *   1. Sesi juga dibuat oleh seeder, oleh penggandaan jadwal, dan kelak oleh
 *      impor. Ketiganya tidak melewati Form Request, dan ketiganya bisa
 *      menghasilkan sesi yang jendelanya terbalik.
 *   2. Pelanggarannya bukan "input tidak lengkap" melainkan "jadwal ini tidak
 *      bisa dijalankan" - dan pemeriksaan yang menjaga arti data memang
 *      tempatnya di layanan, bukan di pintu masuk HTTP.
 *
 * ---------------------------------------------------------------------
 * Sesi pertama memindahkan event, dan itu bukan efek samping
 * ---------------------------------------------------------------------
 * Docblock EventStatus::tujuanYangBoleh menuliskan janjinya sejak Fase 3:
 * "Fase 5 akan memindahkan kedua transisi ini otomatis: ke Berjalan saat sesi
 * pertama dimulai, ke Selesai saat sesi terakhir ditutup."
 *
 * Yang membuat ini tidak berbahaya adalah kenyataan bahwa kegagalannya
 * TERLIHAT: kalau EventStatusService menolak transisinya, sesinya sudah
 * terlanjur berjalan. Karena itu urutannya dibalik - event dipindahkan lebih
 * dulu, di dalam transaksi yang sama, dan bila pemindahan itu gagal seluruh
 * pembukaan sesi dibatalkan. Peserta tidak boleh mulai mengerjakan di event
 * yang sistemnya sendiri anggap belum berjalan.
 *
 * Pemindahan itu juga tidak fatal bila eventnya sudah Berjalan: dua sesi yang
 * dibuka pada event yang sama adalah keadaan NORMAL, bukan pelanggaran.
 */
class SesiService
{
    public function __construct(
        private readonly EventStatusService $status,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function buat(Exam $ujian, array $data): ExamSession
    {
        $this->periksaJendela($data);

        return DB::transaction(function () use ($ujian, $data) {
            $sesi = ExamSession::create([
                'school_id' => $ujian->school_id,
                'exam_id' => $ujian->id,
                'session_number' => $data['session_number'] ?? $this->nomorBerikutnya($ujian),
                'access_opens_at' => $data['access_opens_at'],
                'access_closes_at' => $data['access_closes_at'],
                'hard_end_at' => $data['hard_end_at'],
                'duration_minutes' => isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : null,
                'status' => StatusSesi::Draft,
            ]);

            $this->simpanRuang($sesi, $data['event_room_id'] ?? []);

            return $sesi;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function perbarui(ExamSession $sesi, array $data): ExamSession
    {
        // Jendela dibandingkan terhadap nilai BARU yang lengkap: field yang
        // tidak dikirim memakai nilai yang sudah ada. Memeriksa hanya yang
        // dikirim berarti menggeser `access_closes_at` saja bisa menghasilkan
        // jendela terbalik tanpa satu pun pemeriksaan melihatnya.
        $gabung = [
            'access_opens_at' => $data['access_opens_at'] ?? $sesi->access_opens_at,
            'access_closes_at' => $data['access_closes_at'] ?? $sesi->access_closes_at,
            'hard_end_at' => $data['hard_end_at'] ?? $sesi->hard_end_at,
        ];

        $this->periksaJendela($gabung);

        return DB::transaction(function () use ($sesi, $data, $gabung) {
            $sesi->fill([
                ...$gabung,
                'duration_minutes' => isset($data['duration_minutes'])
                    ? (int) $data['duration_minutes']
                    : $sesi->duration_minutes,
            ])->save();

            if (array_key_exists('event_room_id', $data)) {
                $this->simpanRuang($sesi, $data['event_room_id'] ?? []);
            }

            return $sesi;
        });
    }

    /**
     * Siapkan sesi: jendelanya sudah final, tinggal dijalankan.
     *
     * ---------------------------------------------------------------------
     * Mengapa langkah ini ADA, padahal dulu tidak ada penghasilnya
     * ---------------------------------------------------------------------
     * Graf `StatusSesi` sudah sejak Fase 5 mewajibkan
     * `Draft -> Siap -> Berjalan`. Tetapi sampai 2026-11-07 tidak ada satu pun
     * kode di `app/` yang memindahkan sesi ke `Siap`: hanya seeder dan helper
     * test, lewat `forceFill`. Artinya sesi yang dibuat Admin lewat laman
     * jadwal SELALU ditolak saat dibuka - "berstatus Draft dan tidak bisa
     * dibuka" - dan tidak ada tombol apa pun di seluruh aplikasi yang bisa
     * memperbaikinya. Bug itu bukan salah ketik di graf; yang kurang adalah
     * penghasilnya.
     *
     * ---------------------------------------------------------------------
     * Yang TIDAK diperiksa di sini, dan itu keputusan
     * ---------------------------------------------------------------------
     * Ruang dan pengawas TIDAK diperiksa. Itu tugas `mulai()`. Menaruh aturan
     * yang sama di dua tempat berarti aturan itu ditulis dua kali lalu
     * diam-diam berbeda suatu hari. Arti `Siap` di graf adalah "jendelanya
     * sudah final", bukan "siap dijalankan" - dan yang menjawab "siap
     * dijalankan" tetap `mulai()`, yang masih menolak bila ruangnya kosong
     * atau ada ruang tanpa pengawas.
     *
     * `Siap -> Draft` tetap boleh, karena grafnya memang begitu: Admin harus
     * bisa memperbaiki jendela yang ternyata salah tanpa membatalkan sesinya
     * lebih dulu.
     */
    public function siapkan(ExamSession $sesi): ExamSession
    {
        if (! $sesi->status->bolehPindahKe(StatusSesi::Siap)) {
            throw new DomainException(
                "Sesi {$sesi->label()} berstatus {$sesi->status->label()} dan tidak bisa disiapkan.",
            );
        }

        // Paket yang dipasang SESUDAH ujiannya lahir dibekukan di sini - lihat
        // catatan panjang di `ExamRegistrar::bekukanPaketMapel()`. Dipanggil
        // SEBELUM statusnya berpindah: paket yang kosong ditolak
        // `PembekuPaket` dengan ValidationException, dan penolakan itu harus
        // terjadi selagi sesinya masih Draft - bukan sesudah ia berstatus Siap
        // dan dilaporkan siap dijalankan.
        app(ExamRegistrar::class)->bekukanPaketMapel($sesi);

        $sesi->forceFill(['status' => StatusSesi::Siap])->save();

        return $sesi->refresh();
    }

    /**
     * Buka sesi: peserta boleh mulai masuk.
     *
     * Sesi hanya boleh dibuka bila sudah punya ruang DAN setiap ruangnya
     * punya pengawas. Keduanya bukan kelengkapan administratif - presensi
     * harus diverifikasi orang (plate 17), jadi ruang tanpa pengawas adalah
     * ruang yang siswanya tidak akan pernah bisa masuk.
     */
    public function mulai(ExamSession $sesi): ExamSession
    {
        if (! $sesi->status->bolehPindahKe(StatusSesi::Berjalan)) {
            throw new DomainException(
                "Sesi {$sesi->label()} berstatus {$sesi->status->label()} dan tidak bisa dibuka.",
            );
        }

        $kurang = $this->ruangTanpaPengawas($sesi);

        if ($sesi->rooms()->count() === 0) {
            throw new DomainException("Sesi {$sesi->label()} belum punya ruang.");
        }

        if ($kurang !== []) {
            throw new DomainException(
                'Ruang berikut belum punya pengawas: '.implode(', ', $kurang).'.',
            );
        }

        return DB::transaction(function () use ($sesi) {
            // Event dipindahkan LEBIH DULU - lihat catatan kelas ini.
            $this->pindahkanEvent($sesi, EventStatus::Ongoing);

            $sesi->forceFill(['status' => StatusSesi::Berjalan])->save();

            return $sesi->refresh();
        });
    }

    /**
     * Tutup sesi: ujian benar-benar berhenti.
     *
     * Sesi terakhir menutup eventnya. Yang menentukan "terakhir" bukan
     * session_number, melainkan apakah masih ada sesi lain yang belum tuntas -
     * sesi 3 bisa ditutup sebelum sesi 2 bila sesi 2 dibatalkan.
     */
    public function tutup(ExamSession $sesi): ExamSession
    {
        if (! $sesi->status->bolehPindahKe(StatusSesi::Selesai)) {
            throw new DomainException(
                "Sesi {$sesi->label()} berstatus {$sesi->status->label()} dan tidak bisa ditutup.",
            );
        }

        return DB::transaction(function () use ($sesi) {
            $sesi->forceFill(['status' => StatusSesi::Selesai])->save();

            if ($this->semuaTuntas($sesi->exam)) {
                $this->pindahkanEvent($sesi, EventStatus::Completed);
            }

            return $sesi->refresh();
        });
    }

    /**
     * Hapus sesi.
     *
     * Penempatannya dikosongkan lebih dulu, di dalam transaksi, dan itu bukan
     * kehati-hatian berlebih: FK dari `exam_participants.exam_session_id`
     * memakai RESTRICT, sehingga menghapus sesi yang masih punya peserta gagal
     * dengan galat integritas. Pesertanya tetap ada - yang hilang hanya
     * ruangnya, dan itu memang artinya membubarkan sesi.
     */
    public function hapus(ExamSession $sesi): void
    {
        DB::transaction(function () use ($sesi) {
            ExamParticipant::query()
                ->where('exam_session_id', $sesi->id)
                ->update([
                    'exam_session_id' => null,
                    'exam_session_room_id' => null,
                ]);

            // Penugasan dan ruang sesi boleh hilang bersama sesinya: keduanya
            // tidak bermakna di luar sesi yang menaunginya.
            $sesi->delete();
        });
    }

    /**
     * Nomor sesi berikutnya, dihitung di dalam ujiannya.
     *
     * Diambil dari nomor tertinggi, bukan dari jumlah baris - sesi yang
     * dihapus akan membuat jumlah baris menurun dan nomor berikutnya
     * menabrak nomor yang masih dipakai.
     */
    public function nomorBerikutnya(Exam $ujian): int
    {
        return (int) $ujian->sessions()->max('session_number') + 1;
    }

    /**
     * Kode ruang yang dipakai sesi ini tetapi belum punya satu pengawas pun.
     *
     * @return array<int, string>
     */
    public function ruangTanpaPengawas(?ExamSession $sesi): array
    {
        if ($sesi === null) {
            return [];
        }

        return $sesi->ruangTanpaPengawas()
            ->map(fn (ExamSessionRoom $r) => $r->eventRoom?->room?->code ?? 'R-?')
            ->values()
            ->all();
    }

    /* ----------------------------------------------------------- Pembantu */

    /**
     * @param  array<string, mixed>  $data
     */
    private function periksaJendela(array $data): void
    {
        $buka = $data['access_opens_at'] ?? null;
        $tutup = $data['access_closes_at'] ?? null;
        $akhir = $data['hard_end_at'] ?? null;

        if ($buka === null || $tutup === null || $akhir === null) {
            throw ValidationException::withMessages([
                'access_opens_at' => 'Waktu pembukaan, batas mulai, dan batas akhir wajib diisi.',
            ]);
        }

        $buka = Carbon::parse($buka);
        $tutup = Carbon::parse($tutup);
        $akhir = Carbon::parse($akhir);

        // Tiga pemeriksaan, bukan satu. `tutup > akhir` adalah yang paling
        // mudah terlewat: jendela masuknya masih terbuka ketika ujiannya sudah
        // harus berhenti, dan siswa yang memakainya akan kehilangan waktu
        // mengerjakan tanpa ada yang tahu mengapa.
        if ($buka->greaterThanOrEqualTo($tutup)) {
            throw ValidationException::withMessages([
                'access_closes_at' => 'Batas akhir memulai harus setelah waktu pembukaan akses.',
            ]);
        }

        if ($tutup->greaterThan($akhir)) {
            throw ValidationException::withMessages([
                'hard_end_at' => 'Batas akhir mutlak tidak boleh lebih awal dari batas akhir memulai.',
            ]);
        }
    }

    /**
     * @param  array<int, int>  $eventRoomId
     */
    private function simpanRuang(ExamSession $sesi, array $eventRoomId): void
    {
        $sekarang = $sesi->rooms()->pluck('event_room_id')->all();

        // Ruang yang dibuang tetapi masih memuat peserta tidak boleh langsung
        // hilang: FK-nya RESTRICT, jadi penghapusannya akan gagal. Yang
        // dikembalikan ke pemanggil adalah daftar ruang yang masih terpakai,
        // supaya layar bisa menjelaskan mengapa ruang itu belum bisa dilepas.
        $dibuang = array_diff($sekarang, $eventRoomId);

        foreach ($dibuang as $id) {
            $ruang = $sesi->rooms()->where('event_room_id', $id)->first();

            if ($ruang !== null && $ruang->participants()->exists()) {
                throw new DomainException(
                    'Ruang masih memuat peserta. Kosongkan penempatannya lebih dulu.',
                );
            }
        }

        $sesi->rooms()->whereIn('event_room_id', $dibuang)->delete();

        // Hanya ruang event yang benar-benar milik event ini. Id milik event
        // lain tetap lolos composite FK (sekolahnya sama), sehingga
        // penyaringan di sini bukan kehati-hatian berlebih - ia satu-satunya
        // yang menutupnya di jalur tulis. KonflikJadwal memeriksanya kembali
        // sebagai jaring kedua.
        $sah = EventRoom::query()
            ->whereIn('id', $eventRoomId)
            ->where('event_id', $sesi->exam?->event_id)
            ->pluck('id')
            ->all();

        foreach ($sah as $id) {
            $sesi->rooms()->firstOrCreate([
                'school_id' => $sesi->school_id,
                'event_room_id' => $id,
            ]);
        }
    }

    private function semuaTuntas(Exam $ujian): bool
    {
        return $ujian->sessions()
            ->whereIn('status', [StatusSesi::Draft->value, StatusSesi::Siap->value, StatusSesi::Berjalan->value])
            ->doesntExist();
    }

    private function pindahkanEvent(ExamSession $sesi, EventStatus $tujuan): void
    {
        $event = $sesi->exam?->event;

        if ($event === null || $event->status === $tujuan) {
            return;
        }

        if (! $event->status->bolehPindahKe($tujuan)) {
            // Bukan galat. Event yang sudah Berjalan ketika sesi kedua dibuka
            // adalah keadaan normal, dan event yang sudah Selesai ketika sesi
            // ketiga ditutup tidak perlu dipindahkan lagi.
            return;
        }

        $this->status->pindah($event, $tujuan);
    }
}
