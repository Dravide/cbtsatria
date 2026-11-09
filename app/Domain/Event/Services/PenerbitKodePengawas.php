<?php

namespace App\Domain\Event\Services;

use App\Domain\Event\Models\EventCommitteeMember;

/**
 * Penerbitan kode pengawas enam angka - satu kode per ORANG.
 *
 * =====================================================================
 * Kelas ini adalah PEMINDAHAN, bukan penambahan
 * =====================================================================
 * Ia menggantikan paruh penerbitan `RoomEntryLinkService`, yang sampai
 * 2026-11-07 menyimpan kode di baris RUANG (`room_entry_links.code_hash`).
 * Pemindahannya bukan kerapian: selama kodenya menempel pada ruang, satu
 * kode membuka satu ruang untuk siapa pun yang memegangnya - dan karena
 * kodenya dicetak pada kartu yang sama dengan tautannya, memfoto kartu di
 * daun pintu sudah cukup untuk masuk. Blueprint `:995` menuliskan pembagian
 * yang seharusnya sejak awal: tautan menentukan RUANG, kode menentukan
 * ORANG.
 *
 * Karena itu paruh pemeriksanya TIDAK ikut ke sini. Ia pindah ke
 * `Attendance\Services\PencocokKodePengawas`, karena hanya modul itu yang
 * tahu ruang-sesi mana yang sedang dibuka di pintu. Kelas ini tidak pernah
 * tahu ruang mana pun - ia hanya menerbitkan kredensial untuk satu orang.
 *
 * =====================================================================
 * Kodenya TERBACA KEMBALI, tetapi hanya oleh aplikasi
 * =====================================================================
 * Sempat kode ini disimpan sebagai HASH satu arah, dengan doktrin "tampil
 * sekali". Pemilik produk menuntut sebaliknya: kode pengawas harus selalu
 * terlihat di laman Kepanitiaan, karena ia mencetaknya menjadi kartu
 * pengawas - dan kode yang hanya berupa hash tidak bisa ditampilkan lagi.
 *
 * Karena itu `terbitkan()` menulis kolom `code` dengan cast `encrypted`, dan
 * TIDAK lagi mengembalikan nilai mentahnya untuk dipindahkan ke flash. Kode
 * yang bisa dibaca kembali memang tidak perlu di-flash: ia dibaca dari model
 * setiap kali laman Kepanitiaan dirender.
 *
 * Doktrinnya sendiri tidak berubah: kredensial tidak boleh ada di basis data
 * dalam bentuk yang terbaca siapa pun yang memegang dump-nya. Enkripsi
 * (APP_KEY) memenuhi keduanya sekaligus - lihat catatan di
 * `EventCommitteeMember`.
 *
 * =====================================================================
 * Kode itu STABIL, dan itu yang membuat kartunya bisa dicetak
 * =====================================================================
 * Menerbitkan ulang TIDAK mengganti kode yang sudah ada kecuali diminta
 * lewat `putar()`. Kalau setiap tekan tombol menghasilkan enam angka baru,
 * kartu yang sudah tercetak di meja pengawas berhenti berlaku - dan itu
 * persis kebalikan dari gunanya mencetak kartu.
 *
 * Jadi ada dua niat yang berbeda, dan keduanya punya methodnya sendiri:
 *
 *   - `terbitkan()` - pastikan orang ini PUNYA kode. Kalau sudah punya, kode
 *     itu TIDAK disentuh. Inilah yang dipanggil seeder dan pembuatan massal.
 *   - `putar()` - GANTI kodenya, mis. karena kartunya bocor atau hilang.
 *     Inilah yang dipanggil tombol "Terbitkan ulang" di laman Kepanitiaan.
 */
class PenerbitKodePengawas
{
    /**
     * Pastikan baris ini punya kode, tanpa mengganti kode yang sudah ada.
     *
     * Aman dipanggil berulang: pemanggilan kedua tidak mengubah apa pun bila
     * kodenya sudah terbit. Itulah yang membuat seeder dan pembuatan massal
     * bisa memanggilnya tanpa memikirkan urutan.
     *
     * @return string Kode enam angka yang berlaku sesudah pemanggilan ini.
     */
    public function terbitkan(EventCommitteeMember $panitia): string
    {
        if ($panitia->code !== null) {
            return $panitia->code;
        }

        return $this->simpanKodeBaru($panitia);
    }

    /**
     * Terbitkan kode BARU, mengganti yang lama.
     *
     * Dipisahkan dari `terbitkan()` dengan sengaja: menekan "Terbitkan
     * ulang" berarti Admin memang ingin kode lama mati. Menggabungkan
     * keduanya menjadi satu method membuat tombol itu diam-diam menjadi
     * "pastikan punya kode", dan Admin yang menekannya karena kartunya bocor
     * tidak mendapat perlindungan apa pun.
     *
     * @return string Kode enam angka yang baru.
     */
    public function putar(EventCommitteeMember $panitia): string
    {
        return $this->simpanKodeBaru($panitia);
    }

    /**
     * Apakah baris ini boleh punya kode pintu ruang.
     *
     * Diserahkan ke model, bukan disusun ulang di sini. Laman Kepanitiaan
     * perlu menanyakan hal yang sama untuk memutuskan tombolnya dirender
     * atau tidak, dan dua tempat yang masing-masing menyusun syaratnya
     * sendiri adalah cara paling mudah membuat tombol yang menjanjikan
     * sesuatu yang pasti ditolak server.
     */
    public function berlakuUntuk(EventCommitteeMember $panitia): bool
    {
        return $panitia->bolehPunyaKode();
    }

    /**
     * Tulis kode baru ke barisnya.
     *
     * `forceFill` dipakai karena `code_issued_at` tidak selalu boleh diisi
     * dari luar; yang penting adalah nilainya, bukan jalur pengisiannya.
     * Cast `encrypted` pada model yang mengubahnya menjadi teks acak saat
     * ditulis - jadi baris ini TIDAK pernah menaruh enam angka sebagai teks
     * biasa di basis data.
     *
     * @return string Kode enam angka dalam bentuk terbaca.
     */
    private function simpanKodeBaru(EventCommitteeMember $panitia): string
    {
        $kode = $this->kodeAcak();

        $panitia->forceFill([
            'code' => $kode,
            'code_issued_at' => now(),
        ])->save();

        return $kode;
    }

    /**
     * Kode enam digit.
     *
     * Enam digit karena itu yang bisa diketik di depan pintu sambil berdiri
     * (wireframe: "482916"). Kode yang lebih panjang lebih aman, tetapi akan
     * ditulis di kertas dan ditempel di meja - dan kertas di meja adalah
     * kredensial yang jauh lebih bocor daripada enam digit.
     *
     * `str_pad` menutup satu kasus yang mudah terlewat: `random_int` bisa
     * mengembalikan angka di bawah 100000, dan tanpa padding kode itu menjadi
     * lima digit - panjangnya berbeda dari yang tertulis di instruksi.
     *
     * Bentuknya sengaja sama persis dengan yang dulu ada di
     * `RoomEntryLinkService::kodeAcak()`, dan method itu sudah dihapus
     * bersama kolomnya. Menyalinnya tanpa menghapus yang lama akan
     * meninggalkan dua sumber enam-angka di repo ini yang bisa berbeda
     * diam-diam.
     */
    private function kodeAcak(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
