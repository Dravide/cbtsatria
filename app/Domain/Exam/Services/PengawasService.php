<?php

namespace App\Domain\Exam\Services;

use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penugasan pengawas ke ruang-sesi - PRD §9.6, wireframe plate 17.
 *
 * ---------------------------------------------------------------------
 * Panitia berjabatan pengawas BUKAN sumber penugasan
 * ---------------------------------------------------------------------
 * Plate 13 mencatat siapa saja pengawasnya di tingkat event; tabel ini
 * mencatat siapa yang berdiri di R-02 pada sesi 1. Keduanya berbeda modul di
 * peta blueprint (5 dan 11), dan plate 17 menjelaskan mengapa pemisahan itu
 * menentukan: "penugasan inilah yang memberi Pengawas hak melihat dan
 * memverifikasi presensi di ruang itu. Tanpa penugasan, ia tidak melihat apa
 * pun."
 *
 * Karena itu `tugaskanOtomatis` MEMBACA kepanitiaan sebagai daftar calon,
 * tetapi tidak pernah memperlakukan keanggotaan panitia sebagai penugasan.
 * Pengawas yang ada di kepanitiaan tetapi tidak ditugaskan ke satu ruang pun
 * memang tidak bisa membuka ruang mana pun - itu aturannya, bukan celah.
 *
 * ---------------------------------------------------------------------
 * Benturan diperiksa pada saat penugasan, bukan hanya saat validasi jadwal
 * ---------------------------------------------------------------------
 * KonflikJadwal memeriksa benturan pengawas sebagai laporan. Pemeriksaan itu
 * berguna untuk melihat keadaan, tetapi ia tidak mencegah apa pun - Admin
 * tetap bisa menyimpan penugasan yang bentrok, lalu menemukannya di panel
 * validasi, lalu harus menebak mana yang harus digeser.
 *
 * Karena itu pemeriksaan yang sama diulang di sini, pada saat penugasannya
 * dibuat, dan kali ini ia MENOLAK. Dua tempat memeriksa hal yang sama memang
 * duplikasi, dan itu disengaja: satu menilai keadaan, satu mencegahnya.
 */
class PengawasService
{
    /**
     * Jumlah pengawas per ruang yang disarankan plate 17.
     *
     * Ini ANGKASARAN, bukan syarat. PRD §9.6 berbunyi "satu ruang dapat
     * memiliki beberapa Pengawas" - satu orang sudah sah mengawas satu ruang,
     * dan sekolah dengan 24 ruang tetapi 26 pengawas memang hanya bisa
     * menugaskan satu per ruang. Karena itu ruang berisi satu pengawas BUKAN
     * temuan, dan tidak boleh diperlakukan seperti ruang yang belum siap.
     */
    public const SARAN_PER_RUANG = 2;

    /**
     * Jumlah pengawas paling sedikit yang membuat ruang bisa dibuka.
     *
     * Angka ini yang dipakai gerbang kesiapan: SesiService menolak membuka
     * sesi, dan TokenSesi menolak menerbitkan token, hanya bila ada ruang
     * yang NOL pengawas. Bedanya dengan SARAN_PER_RUANG menentukan seluruh
     * perilaku laman plate 17 - mencampur keduanya membuat "kurang dari dua"
     * terbaca sebagai "tidak bisa dibuka", dan itu tidak benar.
     */
    public const MINIMAL_PER_RUANG = 1;

    public function __construct(
        private readonly KonflikJadwal $konflik,
    ) {}

    /**
     * Tugaskan seorang pengawas ke satu ruang-sesi.
     *
     * @param  array<string, mixed>  $data  start_at / end_at opsional.
     */
    public function tugaskan(ExamSessionRoom $ruang, User $pengawas, ?int $olehUserId = null, array $data = []): SupervisionAssignment
    {
        if (! in_array($pengawas->role, [Role::Supervisor, Role::Teacher], true)) {
            throw ValidationException::withMessages([
                'supervisor_id' => 'Hanya akun Pengawas atau Guru yang bisa ditugaskan mengawas.',
            ]);
        }

        return DB::transaction(function () use ($ruang, $pengawas, $olehUserId, $data) {
            $tugas = SupervisionAssignment::query()->firstOrCreate(
                [
                    'school_id' => $ruang->school_id,
                    'exam_session_room_id' => $ruang->id,
                    'supervisor_id' => $pengawas->id,
                ],
                [
                    'assigned_by' => $olehUserId,
                    'start_at' => $data['start_at'] ?? null,
                    'end_at' => $data['end_at'] ?? null,
                    'status' => 'aktif',
                ],
            );

            // Benturan diperiksa SESUDAH barisnya ada, karena pemeriksaannya
            // membaca seluruh penugasan pengawas itu - termasuk yang baru.
            // Bila bentrok, transaksinya dibatalkan dan barisnya hilang lagi.
            if ($this->bentrok($pengawas)) {
                throw ValidationException::withMessages([
                    'supervisor_id' => "{$pengawas->name} sudah ditugaskan di ruang lain pada jam yang sama.",
                ]);
            }

            return $tugas;
        });
    }

    public function batalkan(SupervisionAssignment $tugas): void
    {
        $tugas->delete();
    }

    /**
     * Tukar dua penugasan pengawas - ditambahkan 2026-11-14.
     *
     * =====================================================================
     * Yang ditukar adalah ORANGNYA, bukan ruangnya
     * =====================================================================
     * Dua baris `supervision_assignments` bertukar `supervisor_id`. Ruang,
     * sesi, dan jadwalnya tidak bergerak sama sekali - yang berpindah adalah
     * siapa yang berdiri di masing-masing ruang.
     *
     * =====================================================================
     * Mengapa satu method, bukan "lepaskan lalu tugaskan dua kali"
     * =====================================================================
     * Urutan lepas-tugaskan lewat controller berarti tiga permintaan HTTP,
     * dan tiga permintaan yang masing-masing memeriksa bentrok bisa gagal di
     * tengahnya: orang pertama sudah dilepas, orang kedua belum masuk, dan
     * ruangnya kosong sampai Admin menyelesaikannya dengan tangan. Di sini
     * seluruh pertukaran ada di satu transaksi, dan bila bentroknya ditolak,
     * TIDAK ADA satu baris pun yang berubah.
     *
     * =====================================================================
     * Bentrok diperiksa, meski tukar di sesi yang sama tidak mungkin bentrok
     * =====================================================================
     * Tukar lintas SESI mungkin - mis. pengawas sesi pagi bertukar dengan
     * sesi siang karena sakit - dan pada kasus itu orang yang pindah bisa
     * bertabrakan dengan penugasan lain yang sudah ia pegang. Pemeriksaannya
     * dijalankan SESUDAH pertukaran sementara, di dalam transaksi yang sama,
     * sehingga penolakan mengembalikan keduanya ke keadaan semula.
     *
     * @throws ValidationException Bila hasil pertukaran membuat salah satu
     *                             orang punya dua ruang pada jam yang sama.
     */
    public function tukar(SupervisionAssignment $a, SupervisionAssignment $b): void
    {
        if ((int) $a->id === (int) $b->id) {
            throw ValidationException::withMessages([
                'tukar' => 'Dua penugasan yang sama tidak bisa ditukar dengan dirinya sendiri.',
            ]);
        }

        DB::transaction(function () use ($a, $b) {
            $idA = (int) $a->supervisor_id;
            $idB = (int) $b->supervisor_id;

            // `forceFill` + `save()`, bukan `update()`: `supervisor_id` ada di
            // fillable, tetapi jalur ini menulis kolom yang menentukan HAK
            // AKSES, dan mass-assignment dari luar tidak boleh bisa
            // menyentuhnya.
            $a->forceFill(['supervisor_id' => $idB])->save();
            $b->forceFill(['supervisor_id' => $idA])->save();

            // Sesudah kedua barisnya bertukar, baru diperiksa - bentroknya
            // hanya mungkin terlihat pada keadaan AKHIR. Penolakan di sini
            // melempar keluar dari transaksi, dan kedua `save()` di atas ikut
            // dibatalkan.
            $orangA = $a->supervisor()->first();
            $orangB = $b->supervisor()->first();

            foreach ([$orangA, $orangB] as $orang) {
                if ($orang !== null && $this->bentrok($orang)) {
                    throw ValidationException::withMessages([
                        'tukar' => "Pertukaran ini membuat {$orang->name} berada di dua ruang pada jam yang sama.",
                    ]);
                }
            }
        });
    }

    /**
     * Tugaskan otomatis: SATU pengawas dulu untuk setiap ruang, baru ditambah
     * yang kedua bila pengawasnya masih tersisa.
     *
     * ---------------------------------------------------------------------
     * Mengapa dua putaran, bukan satu
     * ---------------------------------------------------------------------
     * Versi satu-putaran mengisi tiap ruang sampai DUA orang sebelum pindah ke
     * ruang berikutnya. Pada sekolah yang pengawasnya lebih sedikit daripada
     * dua kali jumlah ruangnya, hasilnya adalah bencana yang sunyi: delapan
     * ruang pertama berisi dua orang, dan sisanya NOL. Ruang tanpa pengawas
     * adalah ruang yang tidak bisa dibuka, jadi tombol "Tugaskan otomatis" -
     * yang gunanya menolong - justru meninggalkan separuh ujian tanpa
     * pengawas, dan Admin baru menyadarinya saat sesinya menolak dibuka.
     *
     * Putaran pertama memastikan setiap ruang punya SATU orang; putaran kedua
     * baru menambah orang kedua selama calonnya masih ada. Dengan begitu
     * keadaan terburuknya adalah "semua ruang satu pengawas" - dan itu keadaan
     * yang sah, bukan kegagalan.
     *
     * Gilirannya diambil dari kepanitiaan berjabatan pengawas - itu daftar
     * orang yang sekolah sudah tetapkan untuk mengawas. Yang bentrok
     * DILEWATI, bukan dijadikan galat: seluruh gunanya tombol ini adalah
     * menyelesaikan pekerjaan yang membosankan, dan berhenti pada benturan
     * pertama berarti Admin tetap harus menugaskan sisanya satu per satu.
     *
     * @return array{ruang: int, penugasan: int, dilewati: int}
     */
    public function tugaskanOtomatis(SchoolEvent $event, ?int $olehUserId = null): array
    {
        $calon = $this->calonPengawas($event);

        if ($calon === []) {
            throw new DomainException(
                'Belum ada panitia berjabatan pengawas. Tambahkan pengawas di laman Kepanitiaan terlebih dahulu.',
            );
        }

        $ruangan = ExamSessionRoom::query()
            ->whereIn('exam_session_id', ExamSession::query()
                ->whereIn('exam_id', $event->exams()->pluck('id'))
                ->pluck('id'))
            ->with(['supervisors', 'session'])
            ->orderBy('exam_session_id')
            ->orderBy('id')
            ->get();

        $dibuat = 0;
        $tersentuh = [];
        $dilewati = [];

        foreach ([self::MINIMAL_PER_RUANG, self::SARAN_PER_RUANG] as $target) {
            foreach ($ruangan as $r) {
                $kurang = $target - $r->supervisors->count();

                if ($kurang <= 0) {
                    continue;
                }

                foreach ($calon as $orang) {
                    if ($kurang <= 0) {
                        break;
                    }

                    $sudah = $r->supervisors->contains('supervisor_id', $orang->id);

                    if ($sudah) {
                        continue;
                    }

                    try {
                        $this->tugaskan($r, $orang, $olehUserId);
                        $dibuat++;
                        $kurang--;
                        $tersentuh[$r->id] = true;
                    } catch (ValidationException) {
                        // Bentrok - coba orang berikutnya. Dihitung sekali per
                        // ORANG, bukan sekali per percobaan: calon yang sama
                        // dicoba lagi di putaran kedua, dan "2 calon dilewati"
                        // untuk satu orang yang bentrok adalah angka yang
                        // membuat Admin mencari orang kedua yang tidak ada.
                        $dilewati[$orang->id] = true;
                    }
                }

                // Dibaca ulang supaya orang berikutnya tidak dipilih dua kali
                // untuk ruang yang sama, dan supaya putaran kedua melihat
                // penugasan yang baru saja dibuat putaran pertama.
                $r->load('supervisors');
            }
        }

        return [
            'ruang' => count($tersentuh),
            'penugasan' => $dibuat,
            'dilewati' => count($dilewati),
        ];
    }

    /**
     * @return array<int, User>
     */
    public function calonPengawas(SchoolEvent $event): array
    {
        // Akun yang sudah ditautkan ke kepanitiaan berjabatan pengawas.
        $idPanitia = $event->committee()
            ->where('position', 'like', '%pengawas%')
            ->where('status', 'aktif')
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all();

        return User::query()
            ->whereIn('id', $idPanitia)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * Apakah pengawas ini punya dua penugasan yang jendelanya bertumpang.
     *
     * Memakai KonflikJadwal sebagai sumbernya, bukan menyalin logika
     * jendelanya: penugasan yang start_at/end_at-nya kosong berarti "ikuti
     * sesi", dan salinan aturan itu di dua tempat akan berselisih diam-diam
     * pada penugasan pertama yang benar-benar mengisi kolomnya.
     */
    private function bentrok(User $pengawas): bool
    {
        $penugasan = SupervisionAssignment::query()
            ->where('supervisor_id', $pengawas->id)
            ->with(['room.session', 'room.eventRoom.room:id,code'])
            ->get();

        if ($penugasan->count() < 2) {
            return false;
        }

        return $this->konflik->adaBenturanPengawas($penugasan);
    }

    /**
     * Berapa ruang yang belum punya pengawas sama sekali.
     *
     * Dipakai KPI "Ruang tanpa pengawas" di plate 17, dan dipakai SesiService
     * untuk menolak membuka sesi. Angkanya satu, dan tempat menghitungnya satu -
     * karena KPI yang bilang "0" sementara sesinya menolak dibuka adalah layar
     * yang bertengkar dengan dirinya sendiri.
     */
    public function ruangTanpaPengawas(SchoolEvent $event): int
    {
        $idSesi = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->pluck('id')
            ->all();

        if ($idSesi === []) {
            return 0;
        }

        return ExamSessionRoom::query()
            ->whereIn('exam_session_id', $idSesi)
            ->whereDoesntHave('supervisors')
            ->count();
    }

    /**
     * Berapa penugasan aktif yang dimiliki satu event.
     */
    public function jumlahPenugasan(SchoolEvent $event): int
    {
        $idRuang = ExamSessionRoom::query()
            ->whereIn('exam_session_id', ExamSession::query()
                ->whereIn('exam_id', $event->exams()->pluck('id'))
                ->pluck('id'))
            ->pluck('id');

        return SupervisionAssignment::query()
            ->whereIn('exam_session_room_id', $idRuang)
            ->where('status', 'aktif')
            ->distinct()
            ->count('supervisor_id');
    }

    /**
     * Jabatan panitia yang dihitung sebagai calon pengawas.
     *
     * @return array<int, string>
     */
    public static function jabatanPengawas(): array
    {
        return EventCommitteeMember::query()
            ->where('position', 'like', '%pengawas%')
            ->distinct()
            ->pluck('position')
            ->all();
    }
}
