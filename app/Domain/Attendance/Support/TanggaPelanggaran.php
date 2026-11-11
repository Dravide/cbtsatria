<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Event\Support\AmbangPelanggaran;
use Illuminate\Support\Collection;

/**
 * Tangga eskalasi pelanggaran - blueprint `:505-600`, wireframe plate 25 & 29.
 *
 * ---------------------------------------------------------------------
 * Kosakatanya bahasa Indonesia, dan itu keputusan yang mengikat
 * ---------------------------------------------------------------------
 * Blueprint menuliskan jenisnya dalam bahasa Inggris (`contextmenu`,
 * `copy`, `paste`, `tab_blur`, `fullscreen_exit`, `devtools_attempt`,
 * `print_attempt`, `guard_lost`, `heartbeat_gap`). Daftar itu TIDAK dipakai,
 * dan bukan karena selera: sejak Fase 6 `ExamViolation::JENIS` sudah berisi
 * delapan kunci Indonesia, dan seluruh aplikasi ini - `Role`, `EventStatus`,
 * `StatusPresensi`, `JenisSoal` - memakai nilai Indonesia tanpa terjemahan.
 *
 * Yang menentukan bukan mana yang lebih dulu ditulis, melainkan mana yang
 * punya PENULIS. Nama-nama Inggris itu tidak pernah dipakai satu baris kode
 * pun; menggantinya berarti menulis ulang label, riwayat, dan papan
 * monitoring yang sudah berjalan. Pemetaannya dicatat di sini supaya tidak
 * menjadi pertanyaan kelak:
 *
 *   contextmenu           -> klik_kanan          (tingkat 0, TIDAK dicatat)
 *   copy / cut / drag     -> salin_tempel        (hanya yang sampai ke jawaban)
 *   paste (ke kotak jawab)-> salin_tempel
 *   tab_blur              -> pindah_tab
 *   fullscreen_exit       -> keluar_layar_penuh
 *   devtools/print/Escape -> pintasan_terlarang
 *   heartbeat_gap         -> jaringan
 *   guard_lost            -> jaringan
 *   (baru)                -> sesi_ganda, perangkat_berubah
 *   split/floating window -> layar_terbagi
 *
 * `layar_terbagi` sengaja TIDAK dilipat ke `keluar_layar_penuh`. Layar penuh
 * yang hilang bisa terjadi karena satu sentuhan tombol kembali; layar yang
 * terbagi berarti separuh soal sudah tidak di layar itu. Dua kalimat yang
 * berbeda bagi pengawas, jadi dua jenis.
 *
 * ---------------------------------------------------------------------
 * Tingkat 0 TIDAK dicatat, dan itu diperiksa di sini
 * ---------------------------------------------------------------------
 * Blueprint `:505`: klik kanan, memilih teks, dan salin diblokir DIAM-DIAM
 * dan tidak menjadi catatan. Yang dijaga adalah rasio, bukan kerahasiaan:
 * siswa yang menekan klik kanan tiga puluh kali karena kebiasaan akan
 * menenggelamkan satu baris "berpindah aplikasi 40 detik" yang justru
 * satu-satunya yang perlu dibaca pengawas.
 *
 * Karena itu pemeriksaannya ada di SINI, bukan di peramban saja. Peramban
 * yang memutuskan berarti peramban versi lama - atau permintaan yang
 * ditempel langsung - bisa menulis baris yang seharusnya tidak ada.
 *
 * ---------------------------------------------------------------------
 * Dua tingkat tidak dihitung dari severity, melainkan dari JUMLAH
 * ---------------------------------------------------------------------
 * Blueprint Tingkat 2 berbunyi "jumlah tingkat-1 melewati ambang sekolah",
 * dan Tingkat 3 "ambang tingkat-2 terlewati". Artinya tingkat 2 dan 3 bukan
 * sifat satu peristiwa, melainkan sifat RANGKAIAN. Karena itu `severity`
 * di sini hanya menjawab "seberapa berat satu kejadian ini kalau dibaca
 * sendiri", dan tangganya dihitung dari `violation_count` terhadap
 * `AmbangPelanggaran` milik event.
 *
 * Satu pengecualian, dan ia disebut blueprint secara terpisah: perusakan
 * pelindung ujian yang tidak ambigu - dua sesi membuka attempt yang sama -
 * mengunci seketika, tanpa menunggu hitungan. Menunggu sampai ambang
 * terlewati berarti membiarkan orang kedua membaca soal yang sama sampai
 * hitungannya cukup.
 */
final class TanggaPelanggaran
{
    /**
     * Tingkat keberatan bawaan per jenis.
     *
     * Angka 1..5, dan ambang namanya sudah ada di `ExamViolation::labelTingkat()`.
     * `salin_tempel` sengaja 5 supaya terbaca "Berat" di plate 25 dan 29 -
     * wireframe menyebut "Percobaan menempel teks · berat" apa adanya, dan
     * angka yang lebih rendah akan membuat labelnya berbeda dari gambar.
     *
     * `jaringan` 1 dan bukan lebih: koneksi yang putus BUKAN perbuatan
     * siswa. Ia dicatat supaya pengawas tahu mengapa sebuah pengerjaan
     * terhenti, dan menaikkannya berarti menghukum anak karena sinyal.
     */
    public const TINGKAT = [
        'klik_kanan' => 0,
        'pindah_tab' => 1,
        'keluar_layar_penuh' => 1,
        'jaringan' => 1,
        'pintasan_terlarang' => 2,
        // Terbagi 3, bukan 1: layar yang terbagi berarti separuh soal sudah
        // tidak terlihat, dan itu bukan gangguan sepersekian detik seperti
        // berpindah tab. Tetapi juga BUKAN 5 dan bukan `SEGERA_KUNCI` -
        // ponsel lipat, rotasi, dan papan tik yang muncul semuanya bisa
        // menyerupainya, dan yang memutuskan tetap pengawas.
        'layar_terbagi' => 3,
        'perangkat_berubah' => 3,
        'salin_tempel' => 5,
        'sesi_ganda' => 5,
    ];

    /**
     * Jenis yang mengunci ujian SEKETIKA, tanpa menunggu hitungan.
     *
     * Hanya satu, dan itu disengaja. Setiap jenis yang ditambahkan ke sini
     * adalah satu jalan yang mencabut kesempatan siswa menyelesaikan ujian
     * tanpa seorang pun sempat memeriksanya - jadi yang masuk hanyalah
     * keadaan yang tidak punya penjelasan lain.
     *
     * `sesi_ganda` memenuhinya: attempt yang sama dibuka dari dua perangkat
     * berarti salinan soalnya ada di dua layar, dan tidak ada kekeliruan
     * yang menghasilkan itu.
     *
     * Yang TIDAK masuk, meski terberat: `salin_tempel`. Menempel teks ke
     * kotak jawaban bisa terjadi karena papan tik ponsel menyarankan teks.
     * Ia dicatat sebagai Berat, pengawas ditandai, dan pengawas yang
     * memutuskan - bukan mesin.
     */
    public const SEGERA_KUNCI = ['sesi_ganda'];

    /** Apakah jenis ini pernah menjadi baris `exam_violations`. */
    public static function dicatat(string $jenis): bool
    {
        return self::tingkat($jenis) > 0;
    }

    public static function tingkat(string $jenis): int
    {
        return self::TINGKAT[$jenis] ?? 1;
    }

    public static function segeraKunci(string $jenis): bool
    {
        return in_array($jenis, self::SEGERA_KUNCI, true);
    }

    /** Apakah jenis ini dikenal UI. Jenis asing tetap dicatat, apa adanya. */
    public static function dikenal(string $jenis): bool
    {
        return array_key_exists($jenis, ExamViolation::JENIS);
    }

    /**
     * Tangga untuk satu attempt SESUDAH pelanggaran ke-`jumlah` tercatat.
     *
     * `$segera` membawa keadaan "pelanggaran ini mengunci seketika", yang
     * ditentukan `segeraKunci()` - dan yang dipanggil pemanggilnya, bukan
     * dihitung ulang di sini, karena jawabannya bergantung pada jenis
     * pelanggaran yang baru masuk, bukan pada angka.
     *
     * Ambangnya diterima sebagai objek, bukan diambil dari event di sini.
     * Alasannya: `AmbangPelanggaran` sudah punya pemiliknya sendiri
     * (SchoolEvent), dan kelas ini yang menerima event berarti ia ikut tahu
     * cara membacanya - lalu ambang dibaca dua tempat dengan dua jalan.
     */
    public static function tindakan(
        AmbangPelanggaran $ambang,
        int $jumlah,
        int $terberat,
        bool $segera,
    ): TindakanPelanggaran {
        // Tingkat 3 - dikunci. Diperiksa lebih dulu daripada yang lain:
        // attempt yang sudah melewati ambang kunci tidak berubah menjadi
        // "hanya diperingatkan" karena satu baris berikutnya ringan.
        if ($segera || $jumlah >= $ambang->kunciUjian) {
            return TindakanPelanggaran::catat(true, true, true, 3, 0);
        }

        // Tingkat 2 - pengawas ditandai, ujian BERJALAN TERUS.
        if ($jumlah >= $ambang->tandaiPengawas) {
            return TindakanPelanggaran::catat(true, true, false, 2, $ambang->kunciUjian - $jumlah);
        }

        // Tingkat 1 - diperingatkan.
        if ($jumlah >= $ambang->peringatkan) {
            return TindakanPelanggaran::catat(true, false, false, 1, $ambang->kunciUjian - $jumlah);
        }

        return TindakanPelanggaran::catat(false, false, false, $terberat >= 3 ? 1 : 0, $ambang->kunciUjian - $jumlah);
    }

    /**
     * Ringkasan satu attempt yang sudah terkunci, untuk plate 25.
     *
     * Dipakai untuk baris yang TIDAK ikut tangga - tindakan pengawas, dan
     * baris yang sudah ditangani. Baris siswa yang biasa dijawab
     * `jejakTindakan()`.
     */
    public static function labelTindakan(ExamViolation $v): string
    {
        return match (true) {
            $v->violation_type === 'kunci_dibuka' => 'dibuka pengawas',
            $v->resolved_at !== null => 'ditangani',
            default => 'dicatat',
        };
    }

    /**
     * Tindakan pada SETIAP baris riwayat, dihitung ulang dari urutannya.
     *
     * ---------------------------------------------------------------------
     * Mengapa dihitung ulang, bukan disimpan sebagai kolom
     * ---------------------------------------------------------------------
     * Plate 16, 25, dan 29 semuanya menampilkan kolom "Tindakan", dan isinya
     * BUKAN sifat satu baris - ia sifat rangkaian. Baris ketiga berbunyi
     * "diperingatkan" hanya karena dua baris sebelumnya ada; kalau baris
     * pertama hilang, baris ketiga itu berbunyi "dicatat".
     *
     * Kolom tersimpan berarti kolom yang harus benar pada saat penulisan,
     * lalu tidak pernah bisa diperbaiki kalau ambangnya berubah - dan ambang
     * pelanggaran memang BERUBAH: ia milik event, dan Admin boleh
     * menyempitkannya sebelum ujian berjalan. Menyimpannya berarti riwayat
     * yang membeku dengan aturan versi lama sementara kepalanya dibaca dengan
     * aturan versi baru.
     *
     * Yang membuat perhitungan ulang ini sah: tangganya fungsi MURNI dari
     * (ambang, jumlah sampai baris itu, jenis baris itu). Tidak ada yang
     * hilang saat barisnya dibaca kembali, dan urutannya diketahui -
     * `occurred_at`, dengan `id` sebagai pemecah seri karena dua pelanggaran
     * bisa tercatat pada milidetik yang sama.
     *
     * ---------------------------------------------------------------------
     * Tindakan pengawas tidak ikut dihitung
     * ---------------------------------------------------------------------
     * `kunci_dibuka` dan baris yang sudah `resolved_at` diberi labelnya
     * sendiri dan TIDAK menaikkan penghitungnya. Membiarkannya menaikkan
     * hitungan akan membuat satu pembukaan kunci mendorong siswa yang sudah
     * dibebaskan melewati ambang kunci berikutnya - dan riwayatnya akan
     * berbunyi "ujian dikunci" pada pelanggaran yang terjadi SESUDAH
     * pembukaan, yang tidak pernah terjadi.
     *
     * @param  Collection<int, ExamViolation>  $pelanggaran  Baris satu attempt,
     *                                                       dalam urutan apa pun.
     * @return array<int, string> `[id pelanggaran => label tindakan]`
     */
    public static function jejakTindakan(Collection $pelanggaran, AmbangPelanggaran $ambang): array
    {
        $urut = $pelanggaran
            ->sortBy([['occurred_at', 'asc'], ['id', 'asc']])
            ->values();

        $jejak = [];
        $jumlah = 0;
        $terberat = 0;

        foreach ($urut as $baris) {
            // Tindakan pengawas: labelnya sendiri, dan tidak dihitung.
            if (in_array($baris->violation_type, ExamViolation::JENIS_PENGAWAS, true)) {
                $jejak[$baris->id] = self::labelTindakan($baris);

                continue;
            }

            $jumlah++;
            $terberat = max($terberat, (int) $baris->severity);

            $tindakan = self::tindakan($ambang, $jumlah, $terberat, self::segeraKunci($baris->violation_type));

            // Baris yang sudah ditangani pengawas tetap menampilkan
            // tindakannya sendiri: "ditangani" menjawab pertanyaan yang
            // berbeda dari "diperingatkan", dan keduanya bisa benar sekaligus.
            $jejak[$baris->id] = $baris->resolved_at !== null
                ? 'ditangani'
                : $tindakan->labelTindakan();
        }

        return $jejak;
    }
}
