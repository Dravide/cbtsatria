<?php

namespace App\Domain\Exam\Services;

use App\Domain\Event\Models\EventRoom;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;

/**
 * Susunan kursi satu RUANG EVENT - bahan denah (layar) dan tablo (PDF).
 *
 * =====================================================================
 * Satu kelas, dua permukaan
 * =====================================================================
 * Denah dan tablo menggambar kursi yang SAMA. Kalau keduanya menyusun
 * datanya sendiri-sendiri, keduanya bisa berbeda - dan yang pertama
 * menyadarinya adalah pengawas yang memegang tablo sambil melihat denah
 * yang ditempel di meja. Karena itu penyusunannya ada di sini, sekali.
 *
 * =====================================================================
 * Yang disusun adalah penghuni RUANG EVENT, bukan peserta satu sesi
 * =====================================================================
 * Sampai 2026-11-09 kelas ini menyusun penempatan yang menunjuk
 * `exam_session_room_id` ruang ini - yaitu peserta UJIAN yang sedang
 * berjalan. Bentuk itu membuat kursi anak bergeser tiap mapel: pada ujian
 * yang diikuti sebagian kelas, ruang berkapasitas 35 hanya memuat sebelas
 * orang, lalu sebelas orang itu dinomori 1..11 - dan pada mapel berikutnya
 * nomornya berubah lagi.
 *
 * Meja di ruang tidak disusun ulang antar sesi, jadi nomornya juga tidak.
 * Sejak 2026-11-09 yang disusun adalah seluruh penghuni ruang EVENT,
 * lewat `PetaKursiEvent` - satu aturan dengan papan pengawas, laman siswa,
 * dan pratinjau penempatan. Karena itu pula `terisi` di sini berarti
 * penghuni ruangnya, bukan peserta ujian yang kebetulan sedang berjalan.
 *
 * Kolom `exam_session_room_id` sendiri tidak disentuh: ia tetap satu-satunya
 * penunjuk ruang, dan ruang SESI itu yang menunjuk `event_room_id`.
 *
 * =====================================================================
 * Jumlah kursi diambil dari KAPASITAS ruang, bukan dari jumlah peserta
 * =====================================================================
 * Denah dengan kursi sebanyak peserta yang kebetulan ada akan menampakkan
 * ruangan yang selalu penuh. Kursi kosong harus terlihat kosong: pengawas
 * yang mencari tempat untuk siswa pindahan perlu tahu di mana yang benar-
 * benar belum dipakai.
 *
 * `kapasitasEfektif()` menelusuri capacity_override -> eventRoom.capacity_-
 * override -> room.capacity, sehingga ruang tanpa `capacity` yang dimuat
 * terbaca berkapasitas NOL. Jebakan itulah sebabnya pemanggil WAJIB memuat
 * `eventRoom.room:id,code,name,capacity` - lihat `ruangSesi()` di controller.
 *
 * =====================================================================
 * Yang kosong bukan sebuah baris di basis data
 * =====================================================================
 * Seluruh kursi yang tersedia dibangun dengan mengulang 1..kapasitas, lalu
 * mencocokkan penempatan yang ada. Tidak ada baris `exam_participant` yang
 * dibuat untuk kursi kosong - kursi kosong adalah ketiadaan, bukan data.
 */
final class PapanKursi
{
    public function __construct(
        private readonly NomorKursi $kursi,
        private readonly PetaKursiEvent $peta,
    ) {}

    /**
     * Susun satu ruang event, dengan judul sesi bila ada.
     *
     * Judulnya - ujian, sesi, tanggal, jam - tetap datang dari ruang SESI
     * yang diberikan pemanggil, karena tablo memang dicetak per sesi. Yang
     * berlaku sepanjang event adalah KURSINYA, dan itu yang disusun di sini.
     *
     * @return array<string, mixed>
     */
    public function susun(ExamSessionRoom $ruang): array
    {
        $eventRoom = $ruang->eventRoom;

        if ($eventRoom === null) {
            return $this->rakit(null, $ruang->session, 0);
        }

        return $this->rakit($eventRoom, $ruang->session, $ruang->kapasitasEfektif());
    }

    /**
     * Susun satu ruang event tanpa memandang sesi - bentuk laman denah.
     *
     * Denah menggambar kursi yang berlaku sepanjang event, jadi ia tidak
     * memilih sesi dan tidak punya judul ujian untuk ditulis. Kapasitasnya
     * kapasitas ruang EVENT - angka yang sama yang membentuk petanya.
     *
     * `$sesi` opsional dan hanya memasok judul; penghuninya tetap penghuni
     * ruang event, di sesi mana pun.
     *
     * @return array<string, mixed>
     */
    public function susunEvent(EventRoom $ruang, ?ExamSession $sesi = null): array
    {
        return $this->rakit($ruang, $sesi, $ruang->kapasitasEfektif());
    }

    /**
     * Bentuk bersama kedua permukaan - bedanya hanya judul dan kapasitas.
     *
     * Penghuninya SELALU dari ruang event: kursi anak tidak berpindah antar
     * sesi, jadi `sesi` di sini hanya memasok judul yang dicetak di kepala
     * tablo, bukan himpunan orangnya.
     *
     * @return array<string, mixed>
     */
    private function rakit(?EventRoom $eventRoom, ?ExamSession $sesi, int $kapasitas): array
    {
        $idRuang = (int) ($eventRoom?->id ?? 0);

        // Penghuninya dihitung ulang dari benih tersimpan - lihat catatan
        // panjang di `PetaKursiEvent` tentang mengapa baris
        // `exam_participants` saja tidak cukup: ujian yang belum punya sesi
        // tidak pernah menulis kursi, dan ruangnya lalu tampak kosong.
        // `terisi` karena itu juga datang dari peta yang sama dengan `sel`,
        // sehingga angka di kepala ruang mustahil berbeda dari kotaknya.
        $anggota = $eventRoom === null ? null : $this->peta->anggotaBenih($idRuang);

        $fisik = $eventRoom?->room;

        return [
            'id' => $idRuang,
            'kode' => $fisik?->code ?? ($eventRoom === null ? 'R-?' : "R-{$eventRoom->id}"),
            'nama' => $fisik?->name,
            'ujian' => $sesi?->exam?->subject?->name ?? $sesi?->exam?->title,
            'sesi' => $sesi?->label(),
            'tanggal' => $sesi?->access_opens_at?->format('d M Y'),
            'jam' => $sesi === null || $sesi->access_opens_at === null
                ? null
                : $sesi->access_opens_at->format('H:i').'–'.$sesi->hard_end_at->format('H:i'),
            'kapasitas' => $kapasitas,
            'terisi' => $anggota === null
                ? $this->peta->anggota($idRuang)->count()
                : $anggota->unique('student_id')->count(),
            'kursi_per_meja' => NomorKursi::KURSI_PER_MEJA,
            'meja_per_baris' => NomorKursi::MEJA_PER_BARIS,
            'sel' => $this->peta->sel($idRuang, $kapasitas),
            'baris' => $this->peta->barisRuang($idRuang),
        ];
    }
}
