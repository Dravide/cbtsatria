<?php

namespace App\Domain\Tenancy\Support;

/**
 * Bawaan pengaturan sekolah - wireframe plate 23.
 *
 * ---------------------------------------------------------------------
 * Mengapa di Tenancy, bukan di controller Pengaturan
 * ---------------------------------------------------------------------
 * Nilainya hidup di kolom `settings` milik tabel `schools`, jadi tempatnya
 * memang di sini. Tetapi alasan yang lebih keras: ia dibaca DUA modul, dan
 * keduanya tidak boleh saling menunjuk.
 *
 *   Admin   membacanya untuk mengisi form plate 23.
 *   Event   membacanya untuk mengisi tiga ambang pelanggaran plate 11 saat
 *           sebuah event dibuat.
 *
 * Selama konstanta ini tinggal di SchoolSettingController, modul Event harus
 * mengimpor Admin\Http - dan itu tepat yang dilarang
 * DomainBoundariesTest: "tidak ada modul yang menyentuh Http modul lain."
 * Aturan itu benar dan bukan formalitas: controller adalah lapisan yang boleh
 * berubah kapan saja mengikuti bentuk formnya, dan nilai yang dibaca modul
 * lain tidak boleh ikut berguncang karenanya.
 *
 * ---------------------------------------------------------------------
 * Nilai di sini adalah BAWAAN untuk event BARU
 * ---------------------------------------------------------------------
 * Kalimat di kotak bawah plate 23 bukan hiasan, dan ia satu-satunya alasan
 * laman itu aman diubah kapan saja:
 *
 *   "Nilai di sini adalah bawaan untuk event baru. Event yang sudah dibuat
 *    menyimpan nilainya sendiri dan tidak ikut berubah."
 *
 * Konsekuensinya di data: `settings` di schools hanya dibaca saat sebuah
 * event DIBUAT, lalu nilainya disalin ke baris event itu. Tidak ada event
 * yang membaca balik ke sini sesudahnya. Kalau suatu saat ada yang
 * merancang sebaliknya - mis. event mewarisi ambang pelanggaran secara
 * dinamis - maka mengubah ambang di tengah ujian yang sedang berjalan akan
 * mengubah aturan main peserta yang sedang mengerjakan, dan kalimat di laman
 * itu menjadi bohong.
 */
final class PengaturanBawaan
{
    /** @var array<string, mixed> */
    public const NILAI = [
        'durasi_token_menit' => 15,

        // Satu angka, dipakai untuk KETIGA ambang di plate 11 saat event
        // dibuat. Plate 23 menampilkan satu field ("Ambang pelanggaran
        // bawaan"); plate 11 menampilkan tiga. Keduanya benar dan tidak
        // bertentangan: yang disimpan sekolah adalah toleransi UMUM, dan
        // Admin mempersempit masing-masing bila jenis ujiannya menuntut.
        'ambang_pelanggaran' => 5,

        'simpan_otomatis_detik' => 20,
        'kkm' => 70,
        'kunci_klik_kanan' => true,
        'peringatkan_keluar_tab' => true,
        'kunci_setelah_ambang' => true,
        'izinkan_tempel_uraian' => false,

        // Tiga penjaga layar - lihat `SchoolEvent` dan
        // `Siswa/Kerjakan.tsx`. Nilainya disalin ke event saat event DIBUAT,
        // sama seperti semua kunci di atas: mengubahnya di tengah ujian yang
        // sedang berjalan tidak boleh mengubah aturan main peserta.
        //
        // Ketiganya TRUE karena itulah perilaku sebelum tombolnya ada.
        // Bawaan yang mematikan akan memadamkan tiga penjagaan di seluruh
        // sekolah pada satu kali penyimpanan, tanpa seorang pun memintanya.
        'deteksi_layar_terbagi' => true,
        'deteksi_jendela_mengambang' => true,
        'tanda_air_ujian' => true,
    ];

    /**
     * Nilai sekolah yang sudah di-merge dengan bawaannya.
     *
     * array_merge dalam urutan ini: bawaan dulu, lalu nilai sekolah. Sekolah
     * yang baru punya sebagian kunci tidak kehilangan sisanya, dan kunci baru
     * yang ditambahkan kelak langsung punya nilai tanpa migrasi data.
     *
     * @param  array<string, mixed>|null  $tersimpan
     * @return array<string, mixed>
     */
    public static function gabung(?array $tersimpan): array
    {
        return array_merge(self::NILAI, $tersimpan ?? []);
    }
}
