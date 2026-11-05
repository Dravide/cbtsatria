<?php

namespace App\Domain\Event\Support;

/**
 * Tiga ambang pelanggaran siswa - wireframe plate 11.
 *
 * ---------------------------------------------------------------------
 * Mengapa kelas ini ada, padahal isinya cuma tiga angka
 * ---------------------------------------------------------------------
 * Blueprint memuat dua bentuk untuk hal yang sama: `exams.violation_thresholds`
 * (baris 538) dan `violation_thresholds_json` (baris 592). Kontradiksi itu
 * tidak ditunda sampai Fase 7 - ia diselesaikan sekarang, dan bentuk yang
 * menang adalah JSON, karena wireframe plate 11 menampilkan TIGA nilai yang
 * selalu muncul bersama, selalu dibaca bersama, dan tidak pernah di-query
 * sendiri-sendiri.
 *
 * Tetapi "tersimpan sebagai JSON" tidak berarti "dipakai sebagai array di
 * seluruh kode". Array asosiatif yang diteruskan ke sana-kemari menghasilkan
 * satu kelas bug yang sulit terlihat: `$ambang['peringatkan']` pada array
 * yang memakai kunci berbeda mengembalikan null, dan null pada perbandingan
 * `>=` adalah false - sehingga peringatan tidak pernah muncul dan tidak ada
 * yang tahu. Kelas ini memaksa ketiga kunci itu ada, bernama sama, dan
 * bertipe sama di setiap tempat yang menyentuhnya.
 *
 * ---------------------------------------------------------------------
 * Mengapa tiga, sementara Pengaturan sekolah menyimpan satu
 * ---------------------------------------------------------------------
 * Wireframe plate 23 menampilkan satu field: "Ambang pelanggaran bawaan = 5".
 * Plate 11 menampilkan tiga. Keduanya benar dan tidak bertentangan: yang
 * disimpan sekolah adalah toleransi UMUM, dan event baru memakai angka itu
 * untuk KETIGA ambangnya, lalu Admin mempersempit masing-masing bila jenis
 * ujiannya menuntut.
 *
 * Ujian harian dan ujian akhir memang tidak seharusnya memakai toleransi yang
 * sama - dan justru itu alasan ambangnya hidup di event, bukan di pengaturan
 * global. Lihat catatan yang sama di PengaturanBawaan::NILAI.
 *
 * ---------------------------------------------------------------------
 * Urutan ketiganya harus menaik, dan itu diperiksa
 * ---------------------------------------------------------------------
 * Peringatkan 3 / tandai pengawas 2 / kunci ujian 1 adalah konfigurasi yang
 * bisa diketik siapa saja dan tidak masuk akal: siswa dikunci sebelum
 * diperingatkan. Kesalahan itu tidak menimbulkan galat apa pun di kemudian
 * hari - ia hanya membuat satu dari tiga aturan tidak pernah tercapai. Karena
 * itu urutannya diperiksa di sini, sekali, alih-alih di setiap tempat yang
 * membandingkan.
 */
final class AmbangPelanggaran
{
    public function __construct(
        public readonly int $peringatkan,
        public readonly int $tandaiPengawas,
        public readonly int $kunciUjian,
    ) {}

    /**
     * Dari nilai tersimpan, dengan angka pengaturan sekolah sebagai cadangan.
     *
     * Satu angka sekolah dipakai untuk KETIGA ambang - itu yang membuat
     * "bawaan" di plate 23 masuk akal meski plate 11 menampilkan tiga field.
     * Sesudah Admin menyimpan satu kali, ketiganya punya nilainya sendiri dan
     * tidak lagi menunjuk ke pengaturan.
     *
     * @param  mixed  $tersimpan  Nilai kolom JSON apa adanya.
     */
    public static function dari(mixed $tersimpan, int $bawaan): self
    {
        $data = is_array($tersimpan) ? $tersimpan : [];

        return new self(
            peringatkan: (int) ($data['peringatkan'] ?? $bawaan),
            tandaiPengawas: (int) ($data['tandai_pengawas'] ?? $bawaan),
            kunciUjian: (int) ($data['kunci_ujian'] ?? $bawaan),
        );
    }

    /**
     * @return array{peringatkan: int, tandai_pengawas: int, kunci_ujian: int}
     */
    public function toArray(): array
    {
        return [
            'peringatkan' => $this->peringatkan,
            'tandai_pengawas' => $this->tandaiPengawas,
            'kunci_ujian' => $this->kunciUjian,
        ];
    }

    /**
     * Apakah urutannya masuk akal: peringatkan <= tandai <= kunci.
     *
     * Boleh sama. Sekolah yang ingin satu pelanggaran langsung mengunci ujian
     * menulis 1/1/1, dan itu konfigurasi yang sah - yang tidak sah adalah
     * penguncian yang lebih awal daripada peringatan.
     */
    public function masukAkal(): bool
    {
        return $this->peringatkan <= $this->tandaiPengawas
            && $this->tandaiPengawas <= $this->kunciUjian;
    }
}
