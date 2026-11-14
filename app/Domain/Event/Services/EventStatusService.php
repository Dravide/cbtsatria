<?php

namespace App\Domain\Event\Services;

use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\SchoolEvent;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya pintu yang mengubah status event.
 *
 * ---------------------------------------------------------------------
 * Mengapa transisinya tidak ditulis di controller
 * ---------------------------------------------------------------------
 * Graf status (EventStatus::tujuanYangBoleh) adalah aturan produk, bukan
 * aturan satu laman. Kalau setiap controller memutuskan sendiri "boleh
 * pindah ke siap atau tidak", maka mengubah grafnya kelak berarti mencari
 * ke seluruh konsol - dan yang paling mungkin terjadi bukan pencarian yang
 * gagal, melainkan satu tempat yang terlewat.
 *
 * Service ini membuat perubahan graf menjadi satu berkas, dan membuat
 * setiap pelanggaran transisi menjadi satu jenis galat yang sama.
 *
 * ---------------------------------------------------------------------
 * Gerbang kesiapan diperiksa di sini juga, bukan di controller tombol
 * ---------------------------------------------------------------------
 * Tombol "Tandai Ready" di plate 12 dirender nonaktif, tetapi tampilan bukan
 * penegakan. Request bisa datang tanpa pernah menyentuh tombol itu, dan
 * status "siap" pada event yang belum lengkap adalah keadaan yang membuat
 * hari-H kacau - peserta sudah dijadwalkan, ruang belum dialokasikan.
 *
 * Karena itu pemeriksaan kesiapan berada di sini, di jalur yang sama dengan
 * pemeriksaan transisi. Controller hanya memanggilnya dan menerjemahkan
 * hasilnya menjadi pesan.
 */
class EventStatusService
{
    public function __construct(
        private readonly EventReadiness $kesiapan,
    ) {}

    /**
     * Pindahkan status, atau jelaskan mengapa tidak bisa.
     *
     * Melempar DomainException, bukan mengembalikan false: pemanggil yang
     * lupa memeriksa nilai kembalian adalah pemanggil yang statusnya berubah
     * tanpa ia sadari. Galat yang dilempar tidak bisa diabaikan tanpa
     * sengaja.
     */
    public function pindah(SchoolEvent $event, EventStatus $tujuan): SchoolEvent
    {
        $asal = $event->status;

        if (! $asal->bolehPindahKe($tujuan)) {
            throw new DomainException(
                $asal->final()
                    ? "Event berstatus {$asal->label()} bersifat final dan tidak bisa dipindahkan lagi."
                    : "Status tidak bisa pindah dari {$asal->label()} ke {$tujuan->label()}.",
            );
        }

        // Gerbang kesiapan hanya pada jalur menuju Siap. Menempatkannya di
        // setiap transisi berarti membatalkan event yang sudah berjalan pun
        // harus lolos syarat kesiapan - dan itu justru mencegah satu-satunya
        // tindakan yang paling dibutuhkan saat ada masalah.
        if ($tujuan === EventStatus::Ready) {
            $laporan = $this->kesiapan->periksa($event);

            if (! $laporan['lulus']) {
                throw new DomainException(
                    'Event belum siap: '.$laporan['gagal'].' dari '.$laporan['total']
                    .' syarat belum terpenuhi.',
                );
            }
        }

        // Transaksi: status dan jejaknya adalah satu perubahan. Event yang
        // berpindah tanpa jejaknya berarti pertanyaan "sejak kapan event ini
        // siap" tidak punya jawaban.
        return DB::transaction(function () use ($event, $tujuan) {
            $event->forceFill(['status' => $tujuan])->save();

            return $event->refresh();
        });
    }

    /**
     * Boleh atau tidak, tanpa melempar.
     *
     * Dipakai laman yang perlu MERENDER pilihan status - plate 12
     * menampilkan tombol nonaktif beserta alasannya, dan pertanyaan
     * "boleh?" di sana tidak boleh menghasilkan galat.
     */
    public function boleh(SchoolEvent $event, EventStatus $tujuan): bool
    {
        if (! $event->status->bolehPindahKe($tujuan)) {
            return false;
        }

        if ($tujuan === EventStatus::Ready) {
            return $this->kesiapan->periksa($event)['lulus'];
        }

        return true;
    }

    /**
     * Semua langkah yang mungkin dari status sekarang, beserta alasannya
     * bila tidak boleh.
     *
     * ---------------------------------------------------------------------
     * `pemulihan` menandai tujuan YANG MEMBUKA KEMBALI arsip
     * ---------------------------------------------------------------------
     * Sejak 2026-11-14 arsip bisa dipindahkan ke lima keadaan, dan kelimanya
     * berbeda arti dari transisi biasa: Admin yang menekannya sedang
     * MEMBATALKAN pengarsipan, bukan melanjutkan alur. Laman menampilkannya
     * sebagai kelompok tersendiri ("Buka kembali dari arsip") karena
     * mencampurnya dengan "Batalkan"/"Selesaikan" membuat lima tombol
     * berdiri sejajar tanpa urutan yang masuk akal.
     *
     * Penanda ini juga yang dipakai laman untuk menanyakan konfirmasi -
     * pemulihan mengubah status event yang mungkin sudah dibaca orang lain
     * di papan dan laporan.
     *
     * @return array<int, array{tujuan: string, label: string, boleh: bool, alasan: ?string, pemulihan: bool}>
     */
    public function langkahTersedia(SchoolEvent $event): array
    {
        $laporan = null;
        $hasil = [];
        $dariArsip = $event->status === EventStatus::Archived;

        foreach ($event->status->tujuanYangBoleh() as $tujuan) {
            $boleh = true;
            $alasan = null;

            if ($tujuan === EventStatus::Ready) {
                // Dihitung sekali, bukan sekali per tujuan.
                $laporan ??= $this->kesiapan->periksa($event);

                if (! $laporan['lulus']) {
                    $boleh = false;
                    $alasan = $laporan['gagal'].' syarat belum terpenuhi.';
                }
            }

            $hasil[] = [
                'tujuan' => $tujuan->value,
                'label' => $tujuan->label(),
                'boleh' => $boleh,
                'alasan' => $alasan,
                'pemulihan' => $dariArsip,
            ];
        }

        return $hasil;
    }
}
