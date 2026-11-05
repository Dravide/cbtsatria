<?php

namespace App\Domain\Exam\Services;

use App\Domain\Attendance\Contracts\PencatatKehadiran;
use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Penempatan peserta ke ujian, sesi, dan ruang - PRD §9.5.
 *
 * ---------------------------------------------------------------------
 * Menarik peserta dari event, bukan dari daftar kelas
 * ---------------------------------------------------------------------
 * Peserta event sudah tersaring sekali - kelasnya, statusnya, sekolahnya -
 * dan ujian memakai hasil saringan itu. Menyaring ulang dari daftar kelas
 * berarti ujian punya aturan keanggotaannya sendiri, dan dua aturan yang
 * berbeda tentang "siapa yang ikut" akan berselisih pada siswa pindahan,
 * siswa yang dikecualikan, dan siswa manual.
 *
 * Kelas sasaran ujian BUKAN saringan kedua. Ia saringan di ATAS daftar
 * peserta: ujian Matematika kelas X hanya mengambil peserta event yang
 * kelasnya X, tetapi ia tidak pernah mengambil siswa yang tidak terdaftar
 * di event.
 *
 * ---------------------------------------------------------------------
 * Ujian tanpa kelas sasaran berarti SELURUH peserta event
 * ---------------------------------------------------------------------
 * Ini pembacaan yang dipilih sengaja. Alternatifnya - "tanpa kelas berarti
 * tidak ada yang ikut" - membuat setiap ujian umum harus menyebut seluruh
 * kelas satu per satu, dan lupa mencentang satu kelas berarti kelas itu
 * tidak mendapat ujian tanpa ada satu pun peringatan.
 *
 * ---------------------------------------------------------------------
 * sebar() menolak ketika kursinya kurang, bukan mengisi sebagian
 * ---------------------------------------------------------------------
 * Mengisi sebagian menghasilkan penempatan yang terlihat berhasil: sebagian
 * siswa punya ruang, sisanya menggantung tanpa ruang dan tanpa penjelasan.
 * Yang menemukannya adalah pengawas di hari-H, saat siswa berdiri di depan
 * pintu dengan daftar yang tidak memuat namanya. Menolak seluruh penyebaran
 * lebih berguna - Admin memperbaiki kapasitasnya, lalu menyebar ulang.
 *
 * Penolakan itu terjadi SEBELUM satu baris pun ditulis, sehingga keadaan
 * setengah jadi tidak pernah ada.
 */
class PenempatanService
{
    /**
     * Tarik peserta event ke satu ujian.
     *
     * Idempoten: menarik ulang tidak menggandakan baris, karena unique
     * (school_id, exam_id, student_id) menahannya dan method ini memakai
     * updateOrCreate - bukan create.
     *
     * @return int Jumlah penempatan yang dibuat.
     */
    public function tarikPeserta(Exam $ujian): int
    {
        $kelasSasaran = $ujian->classes()->pluck('class_id')->all();

        return DB::transaction(function () use ($ujian, $kelasSasaran) {
            $peserta = EventParticipant::query()
                ->where('event_id', $ujian->event_id)
                ->where('status', EventParticipant::STATUS_TERDAFTAR)
                ->when($kelasSasaran !== [], fn ($q) => $q->whereIn('class_id', $kelasSasaran))
                ->get();

            $dibuat = 0;

            foreach ($peserta as $p) {
                $penempatan = ExamParticipant::query()->updateOrCreate(
                    [
                        'school_id' => $ujian->school_id,
                        'exam_id' => $ujian->id,
                        'student_id' => $p->student_id,
                    ],
                    [
                        'event_participant_id' => $p->id,
                        'status' => ExamParticipant::STATUS_TERDAFTAR,
                    ],
                );

                if ($penempatan->wasRecentlyCreated) {
                    $dibuat++;
                }
            }

            return $dibuat;
        });
    }

    /**
     * Sebarkan peserta satu ujian ke ruang-ruang satu sesi.
     *
     * Pembagiannya berurutan menurut nama, lalu diisi ke ruang pertama sampai
     * penuh sebelum pindah ke ruang berikutnya. Itu bukan yang paling adil
     * secara matematis, tetapi ia yang paling bisa dijelaskan di depan pintu:
     * "nama Anda ada di R-02" - dan urutan abjad adalah urutan yang bisa
     * dicari pengawas dengan mata.
     *
     * @return array<int, int> Jumlah peserta per exam_session_room_id.
     */
    public function sebar(Exam $ujian, ExamSession $sesi): array
    {
        $ruangan = ExamSessionRoom::query()
            ->where('exam_session_id', $sesi->id)
            // `capacity` WAJIB ikut di daftar kolomnya, meski yang dibaca
            // tampaknya hanya kode ruangnya. kapasitasEfektif() menelusuri
            // capacity_override -> eventRoom.capacity_override -> room.capacity,
            // dan kolom yang tidak dimuat bernilai null - sehingga setiap ruang
            // terbaca berkapasitas NOL, dan setiap penyebaran ditolak dengan
            // "hanya 0 kursi". Daftar kolom yang tampak cukup adalah jebakan
            // yang tidak menyebut dirinya.
            ->with('eventRoom.room:id,code,capacity')
            ->orderBy('id')
            ->get();

        if ($ruangan->isEmpty()) {
            throw new DomainException("Sesi {$sesi->label()} belum punya ruang.");
        }

        $peserta = ExamParticipant::query()
            ->where('exam_id', $ujian->id)
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->orderBy('student_id')
            ->get();

        $kapasitas = $ruangan->sum(fn (ExamSessionRoom $r) => $r->kapasitasEfektif());

        // Diperiksa lebih dulu, sebelum satu baris pun ditulis - lihat
        // catatan kelas ini.
        if ($peserta->count() > $kapasitas) {
            throw new DomainException(
                "Kapasitas sesi {$sesi->label()} hanya {$kapasitas} kursi untuk {$peserta->count()} peserta.",
            );
        }

        return DB::transaction(function () use ($ruangan, $peserta, $sesi) {
            $hasil = [];
            $antrean = $peserta->values();
            $posisi = 0;

            foreach ($ruangan as $r) {
                $muat = $r->kapasitasEfektif();
                $bagian = $antrean->slice($posisi, $muat);

                foreach ($bagian as $p) {
                    $p->forceFill([
                        'exam_session_id' => $sesi->id,
                        'exam_session_room_id' => $r->id,
                        'assigned_at' => now(),
                    ])->save();

                    // Presensi lahir BERSAMAAN dengan kursinya, dan lewat
                    // kontrak - bukan lewat model Attendance.
                    //
                    // ------------------------------------------------------------------
                    // Mengapa di sini dan bukan di observer Eloquent
                    // ------------------------------------------------------------------
                    // Observer tampak lebih murah: satu baris, dan barisnya
                    // muncul sendiri. Tetapi observer juga menyala untuk
                    // `lepas()` dan `kecualikan()` - yaitu tepat ketika peserta
                    // DIKELUARKAN dari ruangnya - dan presensi atas penempatan
                    // yang tidak punya ruang adalah baris yang tidak bisa
                    // dijelaskan kepada pengawas mana pun.
                    //
                    // Selain itu method ini menjanjikan di docblock kelasnya
                    // bahwa ia satu-satunya penulis `exam_session_room_id`.
                    // Efek samping tersembunyi di dalam save() memutus janji
                    // itu: pembacanya melihat penyimpanan tanpa melihat
                    // pencatatannya.
                    //
                    // Panggilannya lewat container karena modul Exam tidak
                    // boleh menyentuh model modul Attendance. Bentuknya sama
                    // dengan ButirKesiapanUjian di Fase 5 - lihat
                    // PencatatKehadiran.
                    app(PencatatKehadiran::class)->pastikanUntuk($p);
                }

                $hasil[$r->id] = $bagian->count();
                $posisi += $bagian->count();
            }

            return $hasil;
        });
    }

    /**
     * Keluarkan satu peserta dari penempatannya.
     *
     * Barisnya tidak dihapus, hanya dilepas ruang dan sesinya. Penghapusan
     * berarti menghapus catatan bahwa siswa ini pernah terdaftar di ujian -
     * dan pengecualian (status) adalah cara yang benar untuk menyatakan
     * "tidak ikut", bukan hilangnya baris.
     */
    public function lepas(ExamParticipant $penempatan): ExamParticipant
    {
        $penempatan->forceFill([
            'exam_session_id' => null,
            'exam_session_room_id' => null,
            'assigned_at' => null,
        ])->save();

        // Baris presensinya ikut dilepas - tetapi hanya bila masih `pending`.
        // Baris yang sudah diverifikasi adalah catatan tentang apa yang
        // terjadi di ruang ujian, dan perpindahan jadwal tidak membatalkan
        // apa yang sudah terjadi. Syaratnya ada di dalam implementasinya,
        // bukan di sini: pemanggil tidak perlu tahu bedanya.
        app(PencatatKehadiran::class)->lupakan($penempatan);

        return $penempatan;
    }

    /**
     * Kecualikan peserta dari satu ujian.
     *
     * Berbeda dari `lepas`: ia tetap terdaftar di event, tetapi tidak
     * mengerjakan ujian ini - siswa yang sakit, siswa yang nilainya sudah
     * lengkap, siswa yang mengerjakan ujian susulan.
     */
    public function kecualikan(ExamParticipant $penempatan): ExamParticipant
    {
        $penempatan->forceFill([
            'status' => ExamParticipant::STATUS_DIKECUALIKAN,
            'exam_session_id' => null,
            'exam_session_room_id' => null,
        ])->save();

        app(PencatatKehadiran::class)->lupakan($penempatan);

        return $penempatan;
    }

    /**
     * Berapa penempatan aktif yang sudah dimiliki satu event.
     *
     * Dipakai layar jadwal untuk menampilkan "belum ditempatkan: 12" sebelum
     * penyebaran dijalankan.
     */
    public function belumDitempatkan(SchoolEvent $event): int
    {
        return ExamParticipant::query()
            ->whereIn('exam_id', Exam::query()
                ->where('event_id', $event->id)
                ->pluck('id'))
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->whereNull('exam_session_room_id')
            ->count();
    }
}
