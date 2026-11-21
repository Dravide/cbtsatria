<?php

namespace App\Domain\Exam\Services;

use App\Domain\Attendance\Contracts\PembersihRuangSesi;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Services\EventStatusService;
use App\Domain\Exam\Enums\JenisSesi;
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
                'jenis' => $data['jenis'] ?? JenisSesi::Utama->value,
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

        $this->terbitkanTokenOtomatis($sesi);

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

            $this->terbitkanTokenOtomatis($sesi);

            return $sesi->refresh();
        });
    }

    /**
     * Terbitkan token otomatis bila sesi belum punya token aktif serta sudah
     * memiliki ruang dan seluruh ruangnya sudah punya pengawas.
     */
    private function terbitkanTokenOtomatis(ExamSession $sesi): void
    {
        if ($sesi->tokenAktif() !== null) {
            return;
        }

        if ($sesi->rooms()->doesntExist() || $this->ruangTanpaPengawas($sesi) !== []) {
            return;
        }

        $oleh = auth()->id();

        app(TokenSesi::class)->terbitkan($sesi, $oleh !== null ? (int) $oleh : null);
    }

    /**
     * Event yang tertinggal dari sesinya, atau `null` bila tidak ada.
     *
     * =====================================================================
     * Mengapa ini ADA, dan mengapa bukan penolakan
     * =====================================================================
     * `mulai()` sengaja TIDAK menolak sesi pada event yang belum Siap.
     * Menolaknya berarti siswa yang sudah duduk di depan pintu ditahan oleh
     * keadaan administratif yang tidak bisa diperbaiki pengawas dari sana -
     * dan momen terburuk untuk menolak adalah hari-H. Membuka sesi tetap
     * harus berhasil.
     *
     * Yang tidak boleh terjadi adalah kegagalannya TIDAK TERLIHAT. Sampai
     * 2026-11-19 `pindahkanEvent()` kembali tanpa sepatah kata bila graf
     * status menolak tujuannya, sehingga `Draft -> Berjalan` (yang memang
     * tidak ada di graf) meninggalkan event di Draft selamanya: `tutup()`
     * tidak bisa memindahkannya ke `Selesai`, dan `PeringatanHariH` - yang
     * membaca `status === Ongoing` - tidak pernah berbunyi.
     *
     * Method ini menjawab persis pertanyaan yang dulu tidak pernah diajukan:
     * "apakah `pindahkanEvent()` tadi GAGAL?". Isinya cermin dari
     * `pindahkanEvent()`, dan bukan perkiraan: kondisinya sama, urutannya
     * sama, hanya nilai kembaliannya yang berbeda.
     *
     * =====================================================================
     * Sesi `Siap` menjawab `null`
     * =====================================================================
     * Sesi yang belum dibuka tidak menuntut apa pun dari eventnya - Admin
     * masih boleh memindahkannya sendiri lewat laman Event. Yang dilaporkan
     * hanya sesi yang SUDAH berjalan (dan sesi terakhir yang sudah ditutup),
     * karena hanya pada keduanya "event yang tertinggal" berarti sesuatu.
     *
     * @return EventStatus|null Tujuan yang gagal dicapai, atau null.
     */
    public function eventTertinggal(ExamSession $sesi): ?EventStatus
    {
        $tujuan = match ($sesi->status) {
            StatusSesi::Berjalan => EventStatus::Ongoing,
            StatusSesi::Selesai => EventStatus::Completed,
            default => null,
        };

        if ($tujuan === null) {
            return null;
        }

        // Sesi terakhir belum ditutup: event memang belum seharusnya pindah.
        // Syarat yang sama persis dengan `tutup()` sebelum memanggil
        // `pindahkanEvent()`.
        if ($tujuan === EventStatus::Completed && ! $this->semuaTuntas($sesi->exam)) {
            return null;
        }

        $event = $sesi->exam?->event;

        // Sudah di tujuan, atau tidak punya event - dua-duanya bukan
        // ketertinggalan. `pindahkanEvent()` juga melewati keduanya.
        if ($event === null || $event->status === $tujuan) {
            return null;
        }

        // Masih bisa pindah berarti `pindahkanEvent()` belum jalan (mis.
        // transaksi yang gagal sesudahnya), bukan bahwa grafnya menolak.
        // Keduanya dilaporkan, sebab akibatnya bagi Admin sama: statusnya
        // belum berpindah.
        return $tujuan;
    }

    /**
     * Kalimat yang dibaca Admin saat eventnya tertinggal.
     *
     * Ditulis di sini, bukan di controller, supaya layar dan pesan tidak
     * bisa berbeda: yang menyebut sebabnya juga yang mengetahui grafnya.
     */
    public function pesanEventTertinggal(ExamSession $sesi): ?string
    {
        $tujuan = $this->eventTertinggal($sesi);

        if ($tujuan === null) {
            return null;
        }

        $event = $sesi->exam?->event;

        return "Sesi {$sesi->label()} berjalan, tetapi event {$event?->name} masih berstatus "
            ."{$event?->status->label()}. Tandai event Siap di laman Event supaya statusnya ikut berpindah - "
            .'tanpa itu eventnya tidak akan pernah tercatat Berjalan maupun Selesai.';
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

    /* --------------------------------------------------- Aksi massal */

    /**
     * Siapkan seluruh sesi yang masih `Draft`, lalu laporkan per sesi.
     *
     * =====================================================================
     * Per sesi di transaksinya sendiri, dan itu bukan kehati-hatian
     * =====================================================================
     * Satu transaksi untuk dua belas sesi berarti satu paket soal yang belum
     * berisi butir - `PembekuPaket` melempar `ValidationException` - membatalkan
     * sebelas sesi yang sebenarnya sudah siap. Admin lalu membaca "gagal"
     * tanpa tahu yang mana, dan mengulanginya menghasilkan kegagalan yang
     * sama.
     *
     * Pola yang dipakai sama dengan `ImporPenggunaService::simpanBaris()`:
     * tiap baris berdiri sendiri, hasilnya berpasangan, dan yang gagal
     * membawa sebabnya.
     *
     * =====================================================================
     * Hanya sesi `Draft` yang disentuh
     * =====================================================================
     * Sesi yang sudah `Siap` atau lebih jauh DILEWATI dengan tenang, bukan
     * dilaporkan sebagai galat: Admin yang menekan tombol ini pada jadwal
     * setengah jadi sedang menyelesaikan pekerjaan, bukan mengulanginya.
     * Tanpa penyaringan ini, `siapkan()` akan melempar untuk setiap sesi yang
     * sudah siap dan pesannya terbaca seperti tombolnya rusak.
     *
     * @param  iterable<ExamSession>  $sesi
     * @return array{berhasil: int, dilewati: int, gagal: array<int, array{sesi: string, pesan: string}>}
     */
    public function siapkanBanyak(iterable $sesi): array
    {
        $berhasil = 0;
        $dilewati = 0;
        $gagal = [];

        foreach ($sesi as $satu) {
            if ($satu->status !== StatusSesi::Draft) {
                $dilewati++;

                continue;
            }

            try {
                $this->siapkan($satu);
                $berhasil++;
            } catch (DomainException|ValidationException $e) {
                $gagal[] = ['sesi' => $satu->label(), 'pesan' => $e->getMessage()];
            }
        }

        return ['berhasil' => $berhasil, 'dilewati' => $dilewati, 'gagal' => $gagal];
    }

    /**
     * Buka seluruh sesi yang masih `Siap`, lalu laporkan per sesi.
     *
     * =====================================================================
     * Yang dibuka hanya yang sudah Siap
     * =====================================================================
     * Sesi `Draft` sengaja DILEWATI, bukan disiapkan diam-diam lebih dulu.
     * Menyiapkan lalu membuka akan membekukan paket soal tanpa Admin pernah
     * melihat layar pratinjaunya - dan pembekuan itu tidak bisa dibatalkan
     * setelah sesinya berjalan. Yang ingin membuka seluruhnya menyiapkan
     * lebih dulu, dan laman peluncuran menyebutkan bahwa itu langkah
     * sebelumnya.
     *
     * =====================================================================
     * Kegagalan pertama TIDAK menghentikan sisanya
     * =====================================================================
     * Ruang yang belum berpengawas adalah satu-satunya penolakan yang lazim
     * di sini, dan ia milik SATU ruang - bukan milik seluruh jadwal. Membuka
     * sebelas sesi lalu berhenti pada yang kedua belas berarti Admin harus
     * menekan tombol yang sama lagi dan membaca ulang pesan yang sama.
     *
     * =====================================================================
     * `tertinggal` adalah hasil yang BERHASIL, dan tetap harus dibaca
     * =====================================================================
     * Sesi yang terbuka pada event Draft membuka pintunya dengan benar -
     * siswanya boleh masuk - tetapi eventnya tidak ikut berpindah, dan itu
     * kerusakan yang tidak berbunyi. Ia dihitung `berhasil` (karena memang
     * berhasil), DAN dicatat di `tertinggal` supaya Admin tahu apa yang
     * masih harus ia kerjakan di laman Event.
     *
     * @param  iterable<ExamSession>  $sesi
     * @return array{berhasil: int, dilewati: int, gagal: array<int, array{sesi: string, pesan: string}>, tertinggal: array<int, string>}
     */
    public function mulaiBanyak(iterable $sesi): array
    {
        $berhasil = 0;
        $dilewati = 0;
        $gagal = [];
        $tertinggal = [];

        foreach ($sesi as $satu) {
            if ($satu->status === StatusSesi::Berjalan) {
                // Sudah berjalan - keadaan normal untuk sesi kedua yang
                // dibuka belakangan, dan bukan kegagalan.
                $dilewati++;

                continue;
            }

            if ($satu->status !== StatusSesi::Siap) {
                $dilewati++;

                continue;
            }

            try {
                $this->mulai($satu);
                $berhasil++;

                // Dibaca dari model yang sudah disegarkan: `mulai()` mengubah
                // dua baris (sesi dan eventnya), dan instance yang dipegang
                // pemanggil masih memuat status sebelum keduanya.
                $segar = $satu->refresh();
                $sebabGagal = $this->eventTertinggal($segar);

                if ($sebabGagal !== null) {
                    // Sekali saja, walau dua belas sesi tertinggal bersamaan:
                    // sebabnya satu - eventnya belum Siap - dan dua belas
                    // salinan kalimat yang sama berhenti dibaca. Kuncinya
                    // adalah TUJUAN yang gagal dicapai, bukan pesannya:
                    // kalimatnya menyebut label sesi, jadi dua sesi
                    // menghasilkan dua teks berbeda untuk satu sebab.
                    $tertinggal[$sebabGagal->value] ??= $this->pesanEventTertinggal($segar) ?? '';
                }
            } catch (DomainException $e) {
                $gagal[] = ['sesi' => $satu->label(), 'pesan' => $e->getMessage()];
            }
        }

        return [
            'berhasil' => $berhasil,
            'dilewati' => $dilewati,
            'gagal' => $gagal,
            // Nilai, bukan kunci: kuncinya nilai enum, yang tidak berarti apa
            // pun bagi pemanggil.
            'tertinggal' => array_values(array_filter($tertinggal)),
        ];
    }

    /**
     * Batalkan sesi.
     *
     * ---------------------------------------------------------------------
     * Mengapa pembatalan BUKAN penghapusan, dan itu ditulis di tempat lain
     * ---------------------------------------------------------------------
     * `PembersihRuangSesiService` sudah menyuruh Admin membatalkan alih-alih
     * menghapus begitu ruang sesinya punya berita acara final atau insiden -
     * dua bukti yang TIDAK boleh hilang. Sampai sekarang nasihat itu tidak
     * punya tombol: `StatusSesi::Batal` ada di enum sejak Fase 5, grafnya
     * mengizinkannya, dan lima pembaca menanganinya dengan benar, tetapi
     * tidak ada satu pun penulisnya. Satu-satunya jalan yang tersisa adalah
     * menghapus, dan penghapusan itulah yang ditolak.
     *
     * ---------------------------------------------------------------------
     * Token dicabut, attempt TIDAK disentuh
     * ---------------------------------------------------------------------
     * Token dicabut di sini karena token milik modul ini, dan sesi yang
     * dibatalkan tidak boleh menyisakan kredensial yang masih membuka pintu:
     * `TokenSesi::terbitkan()` menolak menerbitkan untuk sesi Batal, dan
     * tanpa pencabutan di sini token yang sudah beredar tetap sah.
     *
     * Attempt yang sedang berjalan TIDAK ditutup di sini, dan itu batas
     * modul, bukan kelalaian: `exam_attempts` milik Attendance, dan Exam
     * tidak boleh menyentuhnya (`DomainBoundariesTest`). Yang menutupnya
     * adalah `MesinUjian::tutupBilaKedaluwarsa()` pada setiap pembacaan
     * attempt dan oleh penjadwal `ujian:tutup-kedaluwarsa`; `deadline_at`
     * setiap attempt sudah dibatasi `hard_end_at` sesinya, sehingga tidak ada
     * attempt yang bisa menggantung selamanya sesudah pembatalan.
     *
     * ---------------------------------------------------------------------
     * Event TIDAK dipindahkan, dan itu berbeda dari `tutup()`
     * ---------------------------------------------------------------------
     * `tutup()` menutup eventnya bila sesi terakhir ditutup, karena ujiannya
     * memang sudah selesai dijalankan. Pembatalan BUKAN penyelesaian:
     * sesinya justru tidak jadi dijalankan, dan satu-satunya jalan keluar
     * yang masuk akal sesudahnya adalah menyusun sesi pengganti di event yang
     * sama.
     *
     * Memindahkan event ke `Selesai` di sini akan menutup pintu itu: graf
     * `EventStatus` tidak punya jalan kembali dari `Selesai` selain `Arsip`,
     * dan `mulai()` pada sesi pengganti akan tetap berhasil - `pindahkanEvent`
     * diam-diam melewati transisi yang ditolak graf - sehingga sekolah
     * menjalankan ujian di bawah event yang sistemnya sendiri anggap sudah
     * selesai. Admin tetap bisa memindahkan eventnya lewat laman event bila
     * memang tidak ada pengganti.
     */
    public function batalkan(ExamSession $sesi, ?string $alasan = null): ExamSession
    {
        if (! $sesi->status->bolehPindahKe(StatusSesi::Batal)) {
            throw new DomainException(
                "Sesi {$sesi->label()} berstatus {$sesi->status->label()} dan tidak bisa dibatalkan.",
            );
        }

        $alasan = trim((string) $alasan);

        return DB::transaction(function () use ($sesi, $alasan) {
            $sesi->forceFill([
                'status' => StatusSesi::Batal,
                'cancelled_at' => now(),
                'cancel_reason' => $alasan === '' ? null : mb_substr($alasan, 0, 200),
            ])->save();

            $sesi->tokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);

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
     *
     * ---------------------------------------------------------------------
     * Bukti di ruang-sesinya diperiksa Attendance, dan ia bisa MENOLAK
     * ---------------------------------------------------------------------
     * Sampai 2026-11-10 method ini hanya mengosongkan penempatan, lalu
     * menghapus sesinya. Yang menghadangnya adalah `session_reports`: FK-nya
     * RESTRICT, dan satu draf berita acara - yang lahir SENDIRI setiap kali
     * pengawas membuka lamanya - sudah cukup untuk menggagalkan seluruh
     * penghapusan dengan galat 1451 mentah di laman jadwal. Admin tidak
     * membaca apa pun kecuali nama constraint.
     *
     * Yang menyelesaikannya adalah kontrak `PembersihRuangSesi`: Attendance
     * yang tahu baris mana yang menahan, dan ia juga yang memutuskan apa
     * yang boleh hilang (draf) dan apa yang tidak (berita acara final,
     * insiden). Penolakannya berupa `DomainException` berkalimat, yang
     * ditangkap `hapusSesi()` dan diteruskan sebagai pesan galat.
     *
     * Urutannya penting: pemeriksaan berjalan SEBELUM satu baris pun
     * diubah, sehingga penolakan tidak meninggalkan sesi setengah
     * dibongkar.
     */
    public function hapus(ExamSession $sesi): void
    {
        $idRuang = $sesi->rooms()->pluck('id')->all();

        DB::transaction(function () use ($sesi, $idRuang) {
            app(PembersihRuangSesi::class)->siapkanHapus($idRuang);

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
            //
            // Yang TIDAK boleh terjadi adalah keadaan ini lolos tanpa jejak:
            // `Draft -> Berjalan` juga sampai ke sini, dan akibatnya eventnya
            // tertinggal selamanya. Karena itu penanya tersendiri -
            // `eventTertinggal()` - dan pemanggil yang ingin memberi tahu
            // Admin memakainya. Menolak `mulai()` di sini bukan pilihan:
            // siswa sudah di depan pintu.
            return;
        }

        $this->status->pindah($event, $tujuan);
    }
}
