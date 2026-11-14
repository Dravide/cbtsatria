<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Services\NomorKursi;
use App\Domain\Exam\Services\PetaKursiEvent;
use Illuminate\Support\Collection;

/**
 * "Tempat ujian saya" - ruang, kursi, dan denah ruangnya.
 *
 * =====================================================================
 * Mengapa ada kelas ini, padahal isinya sempat hidup di satu controller
 * =====================================================================
 * 2026-11-14 blok ini lahir di `AkunSiswaController`, satu laman. Beberapa
 * jam kemudian beranda siswa membutuhkan tombol yang sama untuk ujian yang
 * sedang menunggunya - dan menyalin `tempatUjian()` + `denah()` ke controller
 * kedua berarti dua tempat yang mengirim bentuk payload sama. Dua salinan
 * yang mengirim bentuk yang sama adalah dua salinan yang suatu hari berbeda,
 * dan yang menemukannya anak yang berdiri di depan pintu ruang 3 sambil
 * memegang ponsel yang menyebut ruang 4.
 *
 * Karena itu keduanya menyebut kelas ini, dan bentuk payload-nya ditentukan
 * di sini.
 *
 * =====================================================================
 * Geometri TIDAK dihitung di sini
 * =====================================================================
 * `NomorKursi` (2 kursi per meja, 4 meja per baris, bentuk Z) dan
 * `PetaKursiEvent` (siapa yang duduk di mana, dari benih kocokan) adalah
 * sumber yang sama dengan denah Admin, tablo PDF, dan papan pengawas. Kelas
 * ini hanya MEMBENTUK payload - ia tidak punya aturan tata letak sendiri.
 *
 * =====================================================================
 * Nama penghuni lain DIBUANG di sini, sebelum payload keluar
 * =====================================================================
 * `PetaKursiEvent::sel()` mengembalikan `peserta` lengkap: nama, NIS, kelas.
 * Denah ini untuk mencari kursi sendiri, bukan untuk tahu siapa duduk di
 * sebelah - dan panel yang memuat nama anak lain akan difoto lalu disebar.
 * Yang tersisa per sel hanya `terisi` (boolean) dan `saya`.
 */
final class TempatUjianSiswa
{
    /**
     * Tempat ujian yang paling relevan bagi satu siswa.
     *
     * =====================================================================
     * TIDAK disaring menurut jam sesi, dan itu perbaikan 2026-11-14
     * =====================================================================
     * Versi pertama menambahkan `whereHas('session', hard_end_at >= now())`,
     * dan akibatnya blok ini HILANG seketika sesinya lewat - termasuk pada
     * anak yang baru selesai mengerjakan ujiannya sendiri, yang justru
     * sedang berdiri di ruang itu. Kursi tidak kedaluwarsa bersama jam sesi:
     * ruang dan nomor kursi adalah tempat ujian yang ditetapkan untuk anak
     * itu, dan pertanyaan "saya duduk di mana" tetap sah sesudah ujiannya
     * berakhir.
     *
     * Yang dipilih: penempatan dengan sesi TERDEKAT YANG BELUM BERAKHIR; bila
     * tidak ada, yang paling akhir. Pemanggil menandai keadaan itu lewat
     * `selesai` supaya tidak terbaca sebagai ajakan datang.
     *
     * @return array<string, mixed>|null
     */
    public function terdekat(int $siswaId): ?array
    {
        $semua = $this->penempatanSiswa($siswaId);

        if ($semua->isEmpty()) {
            return null;
        }

        $sekarang = now();

        $peserta = $semua
            ->filter(fn (ExamParticipant $p) => $p->session !== null && $p->session->hard_end_at->gte($sekarang))
            ->sortBy(fn (ExamParticipant $p) => $p->session?->access_opens_at?->timestamp ?? PHP_INT_MAX)
            ->first()
            // Tidak ada yang belum berakhir: pakai yang TERAKHIR, supaya
            // blok ini tetap menjawab "tadi saya duduk di mana" alih-alih
            // hilang tanpa jejak.
            ?? $semua->sortByDesc(fn (ExamParticipant $p) => $p->session?->access_opens_at?->timestamp ?? PHP_INT_MIN)->first();

        return $peserta === null ? null : $this->untuk($peserta);
    }

    /**
     * Tempat ujian satu penempatan yang sudah dipegang pemanggil.
     *
     * Dipakai beranda, yang sudah memuat seluruh penempatan untuk keperluan
     * lain: di sana yang benar bukan "tempat terdekat menurut kelas ini",
     * melainkan tempat ujian yang SEDANG ditampilkan di kartu utama. Dua
     * pilihan yang berbeda itu bisa menunjuk ujian yang berbeda - dan kartu
     * yang menyebut "Ruang 02, kursi 7" dengan denah ruang 1 di belakangnya
     * adalah layar yang bertengkar dengan dirinya sendiri.
     *
     * @return array<string, mixed>|null
     */
    public function untuk(ExamParticipant $peserta): ?array
    {
        $peserta->loadMissing([
            'exam.subject:id,name',
            'session',
            'room.eventRoom.room:id,code,name,location,capacity',
        ]);

        $eventRoom = $peserta->room?->eventRoom;

        // Penempatan tanpa ruang belum punya kursi untuk ditunjuk, dan
        // mengirim blok yang isinya kosong terbaca sebagai "tempat ujian
        // saya hilang" - kalimat yang salah tentang keadaannya.
        if ($eventRoom === null) {
            return null;
        }

        $kursi = app(NomorKursi::class)->satu($peserta);
        $sesi = $peserta->session;

        return [
            'peserta_id' => (int) $peserta->id,
            'ruang' => $eventRoom->room?->code,
            'ruang_nama' => $eventRoom->room?->name,
            'lokasi' => $eventRoom->room?->location,
            'kursi' => $kursi,
            'meja' => $kursi === null ? null : NomorKursi::meja($kursi),
            'baris' => $kursi === null
                ? null
                : intdiv(NomorKursi::meja($kursi) - 1, NomorKursi::MEJA_PER_BARIS) + 1,
            'kolom' => $kursi === null
                ? null
                : ((NomorKursi::meja($kursi) - 1) % NomorKursi::MEJA_PER_BARIS) + 1,
            'mapel' => $peserta->exam?->subject?->name,
            'ujian' => $peserta->exam?->title,
            'sesi' => $sesi?->label(),
            'jam' => $sesi === null
                ? null
                : $sesi->access_opens_at->format('H:i').'–'.$sesi->hard_end_at->format('H:i'),
            'tanggal' => $sesi?->access_opens_at?->format('d M'),
            // Apakah sesinya sudah berakhir. Dipakai laman untuk mengubah
            // kalimatnya - "tempat ujian" pada sesi yang sudah lewat terbaca
            // sebagai ajakan datang ke ruang yang sudah dikosongkan.
            'selesai' => $sesi !== null && $sesi->hard_end_at->lt(now()),
            'denah' => $this->denah($eventRoom, $kursi),
        ];
    }

    /**
     * Penempatan milik satu siswa yang menunjuk ruang.
     *
     * Event yang sudah diarsipkan atau dibatalkan tidak ikut - aturannya sama
     * dengan beranda, dan datang dari `EventStatus::tampilKeSiswa()`. Kalau
     * keduanya menyaring berbeda, blok "Tempat ujian saya" di Akun akan
     * menunjuk ujian yang sudah tidak ada di beranda.
     *
     * @return Collection<int, ExamParticipant>
     */
    private function penempatanSiswa(int $siswaId): Collection
    {
        return ExamParticipant::query()
            ->where('student_id', $siswaId)
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->whereHas('exam.event', fn ($q) => $q->whereIn(
                'status',
                array_map(fn (EventStatus $s) => $s->value, EventStatus::tampilKeSiswa()),
            ))
            ->with([
                'exam.subject:id,name',
                'session',
                'room.eventRoom.room:id,code,name,location,capacity',
            ])
            ->get()
            // Penempatan TANPA ruang tidak bisa menunjuk kursi apa pun -
            // ia belum ditempatkan, dan itu bukan "tempat ujian".
            ->filter(fn (ExamParticipant $p) => $p->room !== null);
    }

    /**
     * Seluruh kursi ruang itu, TANPA nama penghuni.
     *
     * Dihitung `PetaKursiEvent::sel()` - sumber yang sama dengan denah Admin,
     * tablo PDF, dan papan pengawas. Denah yang menyusun ulang geometrinya
     * sendiri adalah denah yang menaruh anak di kursi orang lain.
     *
     * @return array<string, mixed>
     */
    private function denah(EventRoom $eventRoom, ?int $kursi): array
    {
        $kapasitas = $eventRoom->kapasitasEfektif();
        $baris = app(PetaKursiEvent::class)->sel((int) $eventRoom->id, $kapasitas);

        return [
            'kapasitas' => $kapasitas,
            'kursi_per_meja' => NomorKursi::KURSI_PER_MEJA,
            'meja_per_baris' => NomorKursi::MEJA_PER_BARIS,
            'sel' => collect($baris)->map(fn (array $s) => [
                'kursi' => (int) $s['kursi'],
                'meja' => (int) $s['meja'],
                'baris' => (int) $s['baris'],
                'kolom' => (int) $s['kolom'],
                'terisi' => $s['peserta'] !== null,
                // `saya` dibandingkan dengan nomor kursi yang SAMA yang
                // ditampilkan di blok di atasnya - dua angka yang menjawab
                // "kursi mana saya" tidak boleh berasal dari dua hitungan.
                'saya' => $kursi !== null && (int) $s['kursi'] === $kursi,
            ])->all(),
        ];
    }
}
