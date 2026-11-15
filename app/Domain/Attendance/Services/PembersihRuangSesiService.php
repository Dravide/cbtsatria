<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\PembersihRuangSesi;
use App\Domain\Attendance\Models\AttendanceAcknowledgement;
use App\Domain\Attendance\Models\AttendanceScanCode;
use App\Domain\Attendance\Models\ExamIncident;
use App\Domain\Attendance\Models\SessionReport;
use DomainException;
use Illuminate\Support\Facades\Storage;

/**
 * Jawaban modul Attendance atas penghapusan sebuah sesi ujian.
 *
 * Lihat `PembersihRuangSesi` untuk mengapa ini kontrak dan bukan impor
 * langsung dari `SesiService`.
 *
 * ---------------------------------------------------------------------
 * Yang diperiksa dua tabel, yang dibuang satu
 * ---------------------------------------------------------------------
 * `session_reports` dan `exam_incidents` sama-sama RESTRICT terhadap
 * `exam_session_rooms`, sehingga keduanya akan menggagalkan penghapusan
 * sesi. Yang berbeda adalah apa yang boleh terjadi pada isinya:
 *
 *   draf berita acara  -> dibuang. Ia lahir sendiri saat lamanya dibuka,
 *                         dan tanpa foto atau tanda tangan ia bukan bukti.
 *   berita acara final -> DITOLAK. Ia sudah ditandatangani dan dikirim.
 *   insiden            -> DITOLAK. Sesi yang dibatalkan bukan berarti
 *                         ruangannya tidak pernah kejadian apa-apa.
 *
 * Penolakannya menyebut ruang dan isinya, karena Admin yang membacanya di
 * laman jadwal harus bisa langsung memutuskan apa yang dilakukan - bukan
 * menebak dari galat 1451.
 *
 * ---------------------------------------------------------------------
 * Kode pindai dan pengakuannya IKUT DIBUANG - dan itu pernah terlupa
 * ---------------------------------------------------------------------
 * Keduanya lahir di Fase 6, SESUDAH kelas ini ditulis, dan rantai FK-nya
 * menjebak dengan cara yang tidak terbaca dari kode mana pun:
 *
 *   exam_sessions       CASCADE -> exam_session_rooms
 *   exam_session_rooms  CASCADE -> attendance_scan_codes
 *   attendance_scan_codes RESTRICT -> attendance_acknowledgements
 *
 * Sesi dihapus, ruangnya ikut terhapus, kode pindainya ikut terhapus - lalu
 * berhenti di pengakuannya, dengan galat 1451 yang menyebut
 * `pengakuan_kode` dan sama sekali tidak menyebut kode pindai. Yang dibaca
 * Admin adalah "Cannot delete or update a parent row", tanpa satu pun
 * petunjuk tentang apa yang menahannya.
 *
 * Pengakuannya aman dibuang, dan berbeda dari insiden: ia lahir dari
 * TINDAKAN SISWA memindai, bukan dari pengamatan pengawas. Kode pindai
 * terbit sendiri setiap laman presensi dimuat - bahkan pada sesi yang masih
 * Draf - sehingga pengakuan bisa ada untuk ujian yang belum pernah
 * berjalan, dan menahannya berarti jadwal yang salah tidak bisa dibuang.
 *
 * Yang HARUS tetap hilang bersama sesinya hanyalah presensinya sendiri
 * (`attendance_records`), dan itu sudah diurus CASCADE lewat
 * `exam_participants`.
 */
final class PembersihRuangSesiService implements PembersihRuangSesi
{
    public function siapkanHapus(array $idRuangSesi): void
    {
        if ($idRuangSesi === []) {
            return;
        }

        $this->periksaBukti($idRuangSesi);

        $this->buangKodePindai($idRuangSesi);

        $draf = SessionReport::query()
            ->whereIn('exam_session_room_id', $idRuangSesi)
            ->where('status', SessionReport::STATUS_DRAFT)
            ->with(['berkas:id,session_report_id,path', 'tandaTangan:id,session_report_id,path'])
            ->get();

        foreach ($draf as $laporan) {
            // Berkasnya dihapus lebih dulu: baris `session_report_files`
            // hilang lewat cascade saat laporannya dihapus, dan sesudah itu
            // tidak ada lagi yang menyimpan `path`-nya di disk.
            foreach ([$laporan->berkas, $laporan->tandaTangan] as $berkas) {
                foreach ($berkas as $b) {
                    Storage::disk('local')->delete($b->path);
                }
            }

            $laporan->delete();
        }
    }

    /**
     * Buang kode pindai ruang-ruang ini beserta pengakuannya.
     *
     * ---------------------------------------------------------------------
     * Pengakuannya dihapus DULU, dan urutannya bukan gaya penulisan
     * ---------------------------------------------------------------------
     * FK-nya RESTRICT, jadi menghapus kode pindai yang masih punya pengakuan
     * akan gagal - dan gagalnya di tengah pembersihan, sesudah sebagian
     * baris lain terlanjur hilang. Urutan di sini adalah urutan yang
     * dituntut constraint-nya, bukan pilihan.
     *
     * Dihapus lewat kueri, bukan lewat relasi: `AttendanceScanCode` tidak
     * punya relasi ke pengakuannya, dan menambahkannya berarti model kode
     * pindai ikut mengetahui tabel yang cuma membacanya di laman pengawas.
     *
     * @param  array<int, int>  $idRuangSesi
     */
    private function buangKodePindai(array $idRuangSesi): void
    {
        $idKode = AttendanceScanCode::query()
            ->whereIn('exam_session_room_id', $idRuangSesi)
            ->pluck('id');

        if ($idKode->isEmpty()) {
            return;
        }

        AttendanceAcknowledgement::query()
            ->whereIn('attendance_scan_code_id', $idKode)
            ->delete();

        AttendanceScanCode::query()->whereIn('id', $idKode)->delete();
    }

    /**
     * Tolak penghapusan bila ada bukti yang tidak boleh ikut hilang.
     *
     * Dipisahkan supaya pemeriksaannya bisa dibaca sebagai satu aturan, dan
     * supaya pesan penolakannya tidak tercampur dengan pekerjaan pembersihan.
     *
     * @param  array<int, int>  $idRuangSesi
     */
    private function periksaBukti(array $idRuangSesi): void
    {
        $final = SessionReport::query()
            ->whereIn('exam_session_room_id', $idRuangSesi)
            ->where('status', SessionReport::STATUS_FINAL)
            ->count();

        if ($final > 0) {
            throw new DomainException(
                "{$final} berita acara sudah dikirim dan tidak bisa dihapus. ".
                'Batalkan sesinya alih-alih menghapusnya, supaya laporannya tetap tersimpan.',
            );
        }

        $insiden = ExamIncident::query()
            ->whereIn('exam_session_room_id', $idRuangSesi)
            ->count();

        if ($insiden > 0) {
            throw new DomainException(
                "{$insiden} insiden tercatat di ruang sesi ini dan tidak bisa dihapus. ".
                'Batalkan sesinya alih-alih menghapusnya.',
            );
        }
    }
}
