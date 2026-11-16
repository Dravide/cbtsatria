<?php

namespace App\Domain\Event\Contracts;

use App\Domain\Event\Models\SchoolEvent;

/**
 * Pemakai ruang event: kontrak yang dipanggil modul Event, dijawab modul Exam.
 *
 * ---------------------------------------------------------------------
 * Mengapa kontrak, bukan impor langsung
 * ---------------------------------------------------------------------
 * Melepas satu ruang dari event berarti menghapus baris `event_rooms`, dan
 * `event_rooms` dirujuk `exam_session_rooms` (CASCADE). Bila sesi ujian masih
 * memakai ruang itu, penghapusannya merambat ke `exam_session_rooms`, lalu ke
 * `attendance_scan_codes` (CASCADE), dan akhirnya DITOLAK
 * `attendance_acknowledgements` (RESTRICT) - karena pengakuan pindai adalah
 * bukti kehadiran yang tidak boleh hilang. Yang sampai ke Admin adalah galat
 * integritas 1451 mentah, bukan penjelasan.
 *
 * Modul Event tidak boleh menyebut model Exam (`DomainBoundariesTest`), jadi
 * pertanyaan "ruang ini masih dipakai sesi ujian mana" dijawab Exam lewat
 * kontrak ini - bentuk yang sama dengan `ButirKesiapanUjian` dan
 * `ButirSoalPerMapel`: Event mendeklarasikan, Exam menyediakan, provider
 * menyambungkan.
 *
 * ---------------------------------------------------------------------
 * Mengapa MENOLAK, bukan memaksa hapus
 * ---------------------------------------------------------------------
 * Menghapus paksa berarti membuang bukti pindai dan memutus ruang dari sesi
 * yang mungkin sudah berjalan. Yang benar adalah Admin melepas ruang itu dari
 * sesinya lebih dulu (batalkan sesi, atau ganti ruangnya), lalu melepasnya
 * dari event. Kontrak ini memberi kalimat yang menyebut ruang dan sesi mana,
 * supaya langkah itu jelas.
 */
interface PemakaiRuangEvent
{
    /**
     * Ruang (kode master) yang masih dipakai sesi ujian mana pun di event ini.
     *
     * @param  array<int, int>  $roomIds  id ruang MASTER yang akan dilepas dari
     *                                    event (kolom `event_rooms.room_id`).
     * @return array<int, string> Label seperti "R-01 (S-01)" - satu per
     *                            pemakaian, supaya pesannya menyebut sesi mana.
     */
    public function ruangTerpakaiSesi(SchoolEvent $event, array $roomIds): array;
}
