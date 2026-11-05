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
    /** Jumlah pengawas per ruang yang disarankan plate 17. */
    public const SARAN_PER_RUANG = 2;

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
     * Tugaskan otomatis seluruh ruang yang belum punya pengawas cukup.
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
        $dilewati = 0;
        $tersentuh = 0;

        foreach ($ruangan as $r) {
            $kurang = self::SARAN_PER_RUANG - $r->supervisors->count();

            if ($kurang <= 0) {
                continue;
            }

            $tersentuh++;

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
                } catch (ValidationException) {
                    // Bentrok - coba orang berikutnya.
                    $dilewati++;
                }
            }

            // Dibaca ulang supaya orang berikutnya tidak dipilih dua kali
            // untuk ruang yang sama.
            $r->load('supervisors');
        }

        return ['ruang' => $tersentuh, 'penugasan' => $dibuat, 'dilewati' => $dilewati];
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
