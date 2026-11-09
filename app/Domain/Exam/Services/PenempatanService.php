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
use Illuminate\Support\Collection;
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
     * ---------------------------------------------------------------------
     * Urutan abjad adalah BAWAAN, bukan aturan
     * ---------------------------------------------------------------------
     * `orderBy('student_id')` di sini mengelompokkan peserta dari kelas yang
     * sama, karena nomor induk siswa diterbitkan berurutan per angkatan.
     * Akibatnya setiap ruang berisi satu tingkatan - kebalikan dari ujian
     * campuran yang diminta. Karena itu penyebaran yang SUNGGUHAN dipakai
     * laman penempatan adalah `PenempatanKocok::sebarEvent()`, yang menyusun
     * peta `siswa -> ruang event` sekali untuk seluruh event lalu
     * menerjemahkannya per sesi lewat `sebarKeRuang()`.
     *
     * Method ini tetap ada karena ia yang dipakai test, seeder, dan jalur
     * "sebar cepat" - dan karena satu-satunya penulis `exam_session_room_id`
     * harus tetap satu tempat.
     *
     * @return array<int, int> Jumlah peserta per exam_session_room_id.
     */
    public function sebar(Exam $ujian, ExamSession $sesi): array
    {
        $peserta = ExamParticipant::query()
            ->where('exam_id', $ujian->id)
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->orderBy('student_id')
            ->get();

        return $this->sebarBerurutan($ujian, $sesi, $peserta);
    }

    /**
     * Sebarkan dengan urutan peserta yang DIBERIKAN pemanggilnya.
     *
     * Satu-satunya penulis `exam_session_room_id`, persis seperti janji di
     * docblock kelas ini. Urutannya diserahkan ke pemanggil karena urutan
     * itulah keputusan yang diminta Admin - acak campur tingkatan - sementara
     * penulisannya tetap satu: di sini, bersama presensinya.
     *
     * Antrean yang lebih panjang daripada kursinya ditolak SELURUHNYA, bukan
     * dipotong. Memotong berarti sebagian siswa punya ruang dan sisanya
     * menggantung tanpa penjelasan - lihat catatan kelas ini.
     *
     * @param  Collection<int, ExamParticipant>  $antrean
     * @return array<int, int> Jumlah peserta per exam_session_room_id.
     */
    public function sebarBerurutan(Exam $ujian, ExamSession $sesi, Collection $antrean): array
    {
        $ruangan = $this->ruanganSesi($sesi);

        $peserta = $antrean->values();

        $kapasitas = $ruangan->sum(fn (ExamSessionRoom $r) => $r->kapasitasEfektif());

        // Diperiksa lebih dulu, sebelum satu baris pun ditulis - lihat
        // catatan kelas ini.
        if ($peserta->count() > $kapasitas) {
            throw new DomainException(
                "Kapasitas sesi {$sesi->label()} hanya {$kapasitas} kursi untuk {$peserta->count()} peserta.",
            );
        }

        // Pengisian berurutan: ruang pertama sampai penuh, lalu berikutnya.
        // Urutannya peserta yang menentukan siapa di ruang mana, dan urutan itu
        // datang dari pemanggil - lihat docblock method ini.
        $peta = [];
        $posisi = 0;

        foreach ($ruangan as $r) {
            $bagian = $peserta->slice($posisi, $r->kapasitasEfektif());

            foreach ($bagian as $p) {
                $peta[$p->id] = $r->id;
            }

            $posisi += $bagian->count();
        }

        return $this->sebarKeRuang($ujian, $sesi, $peta);
    }

    /**
     * Sebarkan peserta ke ruang SESI menurut peta `peserta => ruang` yang tegas.
     *
     * Berbeda dari `sebarBerurutan()`, yang membagi menurut urutan, method ini
     * menerima kursi setiap orang apa adanya. Itu yang dibutuhkan kocokan per
     * EVENT: peta `siswa -> ruang` disusun sekali di tingkat event - sehingga
     * seorang siswa memegang satu ruang yang sama sepanjang ujian - lalu
     * diterjemahkan ke id ruang SESI untuk tiap sesi, karena satu ruang event
     * muncul sebagai baris yang berbeda di setiap sesi.
     *
     * Tetap di kelas ini, dan tetap method ini, karena janji kelasnya: hanya
     * ADA SATU penulis `exam_session_room_id`. Penerjemahan id ruang tidak
     * mengubah janji itu - yang berubah hanya dari mana kursinya datang.
     *
     * Seluruh peta ditolak bila ADA SATU ruang kelebihan muatan, dan
     * penolakannya terjadi sebelum satu baris pun ditulis. Sisa jaminan
     * `sebarBerurutan()` berlaku sama: tidak ada penempatan setengah jadi.
     *
     * @param  array<int, int>  $peta  exam_participant_id => exam_session_room_id
     * @return array<int, int> Jumlah peserta per exam_session_room_id.
     */
    public function sebarKeRuang(Exam $ujian, ExamSession $sesi, array $peta): array
    {
        $ruangan = $this->ruanganSesi($sesi);
        $kunciRuangan = $ruangan->keyBy('id');

        // Peserta yang peta-nya menunjuk ruang di luar sesi ini tidak diam-diam
        // diabaikan: kalau id ruangnya milik sesi lain, hasilnya adalah siswa
        // yang "sudah ditempatkan" tetapi tidak muncul di daftar ruang mana pun.
        foreach ($peta as $idRuang) {
            if (! $kunciRuangan->has($idRuang)) {
                throw new DomainException(
                    "Ruang itu bukan milik sesi {$sesi->label()}.",
                );
            }
        }

        $peserta = ExamParticipant::query()
            ->whereIn('id', array_keys($peta))
            ->get()
            ->keyBy('id');

        $muatan = [];

        foreach ($peta as $idPeserta => $idRuang) {
            if (! $peserta->has($idPeserta)) {
                throw new DomainException('Ada peserta yang bukan milik ujian ini.');
            }

            $muatan[$idRuang] = ($muatan[$idRuang] ?? 0) + 1;
        }

        foreach ($muatan as $idRuang => $jumlah) {
            $kapasitas = $kunciRuangan->get($idRuang)->kapasitasEfektif();

            if ($jumlah > $kapasitas) {
                $kode = $kunciRuangan->get($idRuang)->eventRoom?->room?->code ?? "ruang #{$idRuang}";

                throw new DomainException(
                    "Ruang {$kode} pada sesi {$sesi->label()} hanya {$kapasitas} kursi untuk {$jumlah} peserta.",
                );
            }
        }

        return DB::transaction(function () use ($sesi, $peta, $peserta) {
            $hasil = [];

            foreach ($peta as $idPeserta => $idRuang) {
                $p = $peserta->get($idPeserta);

                $p->forceFill([
                    'exam_session_id' => $sesi->id,
                    'exam_session_room_id' => $idRuang,
                    'assigned_at' => now(),
                ])->save();

                // Presensi lahir BERSAMAAN dengan kursinya, dan lewat kontrak -
                // bukan lewat model Attendance. Alasan lengkapnya ada di
                // `sebarBerurutan()`; yang sama di sini adalah janjinya, dan
                // dua jalur yang menjanjikan hal sama harus menepatinya dengan
                // cara yang sama.
                app(PencatatKehadiran::class)->pastikanUntuk($p);

                $hasil[$idRuang] = ($hasil[$idRuang] ?? 0) + 1;
            }

            return $hasil;
        });
    }

    /** @return Collection<int, ExamSessionRoom> */
    private function ruanganSesi(ExamSession $sesi): Collection
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

        return $ruangan;
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
