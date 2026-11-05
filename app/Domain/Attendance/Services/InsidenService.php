<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\ExamIncident;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Insiden ruang ujian - wireframe plate 15.
 *
 * ---------------------------------------------------------------------
 * Mengapa terpisah dari pelanggaran, meski keduanya "kejadian buruk"
 * ---------------------------------------------------------------------
 * Pelanggaran melekat pada satu attempt dan berasal dari mesin ujian.
 * Insiden dilaporkan ORANG dan tidak melekat pada siapa pun. Menggabungkan
 * keduanya berarti salah satu dari dua hal: insiden dipaksa punya attempt
 * yang tidak ada, atau pelanggaran kehilangan keterkaitannya dengan
 * pengerjaan yang dilanggarnya.
 *
 * Berkas ini menangani yang kedua saja. Yang menulis exam_violations adalah
 * Fase 7.
 */
class InsidenService
{
    /**
     * Catat satu insiden pada ruang-sesi.
     *
     * `pelaku_label` diambil dari konteks, sehingga insiden yang dilaporkan
     * pemegang tautan ruang tetap punya pelaku yang bisa disebut - dengan
     * cara yang sama seperti presensi.
     */
    public function catat(
        ExamSessionRoom $ruang,
        KonteksRuang $konteks,
        string $jenis,
        string $keterangan,
        ?ExamParticipant $peserta = null,
    ): ExamIncident {
        if (! array_key_exists($jenis, ExamIncident::JENIS)) {
            throw new DomainException("Jenis insiden '{$jenis}' tidak dikenal.");
        }

        if (trim($keterangan) === '') {
            throw new DomainException('Insiden harus disertai keterangan.');
        }

        return ExamIncident::create([
            'school_id' => $ruang->school_id,
            'exam_session_room_id' => $ruang->id,
            'exam_participant_id' => $peserta?->id,
            'supervisor_id' => $konteks->penggunaId(),
            'pelaku_label' => $konteks->pelakuLabel(),
            'incident_type' => $jenis,
            'description' => $keterangan,
            'occurred_at' => now(),
            'status' => ExamIncident::STATUS_TERBUKA,
        ]);
    }

    /**
     * Tandai satu insiden sudah ditangani.
     *
     * Tidak ada penghapusan: insiden yang bisa dihapus adalah insiden yang
     * tidak akan pernah dilaporkan, karena pelapornya tahu jejaknya bisa
     * hilang.
     */
    public function tangani(ExamIncident $insiden): ExamIncident
    {
        $insiden->forceFill(['status' => ExamIncident::STATUS_DITANGANI])->save();

        return $insiden;
    }

    /**
     * Insiden satu ruang-sesi, terbaru lebih dulu.
     *
     * @return Collection<int, ExamIncident>
     */
    public function daftarRuang(ExamSessionRoom $ruang): Collection
    {
        return ExamIncident::query()
            ->where('exam_session_room_id', $ruang->id)
            ->with('peserta.student:id,name')
            ->orderByDesc('occurred_at')
            ->get();
    }

    /** Berapa insiden yang masih terbuka - dipakai KPI plate 15. */
    public function jumlahTerbuka(ExamSessionRoom $ruang): int
    {
        return ExamIncident::query()
            ->where('exam_session_room_id', $ruang->id)
            ->where('status', ExamIncident::STATUS_TERBUKA)
            ->count();
    }

    /**
     * Berapa insiden terbuka untuk BANYAK ruang sekaligus.
     *
     * ---------------------------------------------------------------------
     * Mengapa satu kueri, bukan memanggil `jumlahTerbuka()` per ruang
     * ---------------------------------------------------------------------
     * Papan monitoring Admin (`PemantauanEventController::monitoring`) dan
     * laman presensinya menampilkan SELURUH ruang satu event, dan keduanya
     * memanggil angka ini di dalam `map()`. Dengan `jumlahTerbuka()` per
     * ruang, event dua puluh ruang berarti empat puluh kueri - tepat pada
     * papan yang dibuka Admin di tengah hari-H, saat basis data paling
     * sibuk, dan tepat pada angka yang paling tidak berharga untuk dihitung
     * satu per satu.
     *
     * ---------------------------------------------------------------------
     * Hasilnya memuat ruang bernilai NOL, bukan hanya yang punya insiden
     * ---------------------------------------------------------------------
     * Sisi pemanggil membaca `$peta[$ruang->id] ?? 0`, sehingga ruang yang
     * tidak punya insiden tidak menghasilkan kueri tambahan. Dan `?? 0`
     * tetap ditulis meski `whereIn` menjamin semua ruang ada di hasilnya:
     * ruang yang dibuat SESUDAH kueri ini berjalan tidak akan ada di
     * koleksinya, dan papan yang melempar galat karena satu ruangan baru
     * jauh lebih buruk daripada papan yang menampilkan angka yang benar.
     *
     * @param  Collection<int, ExamSessionRoom>|array<int, int>  $ruang
     * @return Collection<int, int> kunci: id ruang-sesi
     */
    public function jumlahTerbukaPerRuang(Collection|array $ruang): Collection
    {
        // `collect()` atas keduanya, bukan `pluck` pada cabang Collection saja.
        // Versi pertama method ini memanggil `pluck()` langsung pada cabang
        // itu dan membiarkan cabang array apa adanya - sehingga pemanggil
        // yang mengirim array mendapat `isEmpty()` atas array, dan galatnya
        // baru muncul saat ada yang benar-benar mengirim array.
        $id = collect($ruang instanceof Collection ? $ruang->pluck('id') : $ruang);

        if ($id->isEmpty()) {
            return collect();
        }

        // `pluck` + `keyBy` di PHP, bukan agregasi SQL: kueri ini menyentuh
        // baris yang jumlahnya sebanding dengan insiden sebuah event - yaitu
        // belasan - sementara `GROUP BY` menuntut MySQL membaca indeks yang
        // sama dan mengembalikan hasil yang harus dipetakan juga.
        return ExamIncident::query()
            ->whereIn('exam_session_room_id', $id)
            ->where('status', ExamIncident::STATUS_TERBUKA)
            ->pluck('exam_session_room_id')
            ->countBy()
            ->map(fn (int $jumlah) => $jumlah);
    }
}
