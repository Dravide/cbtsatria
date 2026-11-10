<?php

namespace App\Domain\Exam\Services;

use App\Domain\Exam\Models\ExamParticipant;
use Illuminate\Support\Collection;

/**
 * Nomor kursi peserta di ruangnya - SATU aturan untuk seluruh aplikasi.
 *
 * =====================================================================
 * Nomor kursi TIDAK disimpan, dan itu disengaja
 * =====================================================================
 * `exam_participants` tidak punya kolom kursi, dan tidak akan punya. Kolom
 * seperti itu harus disusun ulang setiap kali peserta ditambahkan, dilepas,
 * atau dikecualikan - dan nomor kursi yang salah lebih buruk daripada tidak
 * ada nomor kursi: pengawas memanggil nomor itu di depan kelas, dan anak
 * yang berdiri di kursi orang lain adalah kekacauan yang terjadi di depan
 * pintu, bukan di layar.
 *
 * Karena itu nomornya DIHITUNG, dari urutan yang sama setiap kali.
 *
 * =====================================================================
 * Mengapa kelas ini ada: sampai 2026-11-09 ada DUA aturan, dan keduanya
 * sudah saling bertentangan
 * =====================================================================
 * Tiga tempat menghitung `kursi` sebagai urutan-abjad-nama + 1:
 * `PresensiController::index()`, `PresensiController::koreksi()`, dan
 * `MonitoringController::index()`. Satu tempat menghitungnya sebagai
 * urutan-id + 1: `SiswaDasar::nomorKursi()`, yang dipakai laman siswa.
 *
 * Docblock `SiswaDasar` saat itu menyatakan bahwa urutannya "sama dengan
 * daftar hadir pengawas" - dan itu tidak benar. Akibatnya nomor yang dibaca
 * anak di ponselnya bisa berbeda dari nomor yang dibacakan pengawas di ruang
 * yang sama, dan tidak ada satu pun layar yang menunjukkan keduanya
 * berdampingan sehingga selisihnya tidak pernah terlihat sampai seseorang
 * berdiri di kursi yang salah.
 *
 * Kelas ini menggantikan keduanya. Yang menentukan nomor adalah `kunci()`,
 * dan setiap tempat yang membutuhkan nomor kursi memanggilnya - sehingga
 * tidak ada lagi "aturan kedua" yang bisa berbeda diam-diam.
 *
 * =====================================================================
 * Yang mengikat adalah ABJAD NAMA, bukan urutan id
 * =====================================================================
 * Alasan yang sudah tertulis di `KehadiranService::daftarRuang()` dan tetap
 * berlaku: pengawas memanggil nama dengan mata, dan daftar yang urutannya
 * berubah setiap kali halaman disegarkan membuatnya kehilangan tempat.
 * Urutan id juga tidak berarti apa-apa bagi manusia - ia urutan baris
 * dibuat, bukan urutan orang duduk.
 *
 * Id hanya dipakai sebagai PEMUTUS SERI: dua anak bernama sama persis harus
 * mendapat nomor yang stabil, bukan nomor yang bergantung urutan kueri.
 *
 * =====================================================================
 * Bentuk Z, dan mengapa ada di sini
 * =====================================================================
 * Meja disusun `MEJA_PER_BARIS` kolom, dan penomoran kursi menyusur satu
 * baris meja penuh lalu turun ke baris berikutnya - sehingga kursi 1..8
 * berurutan sepanjang satu jalur masuk. Pengawas yang menyusur dari pintu
 * tidak perlu menyeberang ruangan untuk menemukan kursi berikutnya.
 *
 * Aturan tata letaknya ada di kelas ini, bukan di React, karena nomor yang
 * sama juga dicetak di tablo PDF dan digambar di denah. Kalau peramban
 * menghitungnya sendiri, layar dan kertas bisa berbeda - dan yang salah baru
 * ketahuan saat tablo sudah dibagikan ke enam pengawas.
 */
final class NomorKursi
{
    /** Meja per baris pada denah. Bentuk Z: susur satu baris, lalu turun. */
    public const MEJA_PER_BARIS = 4;

    /** Kursi per meja. Meja ujian sekolah pada umumnya memuat dua orang. */
    public const KURSI_PER_MEJA = 2;

    /**
     * Pemisah antara nama dan id di dalam kunci urut.
     *
     * =====================================================================
     * Mengapa `\x01`, bukan `|` seperti versi pertamanya
     * =====================================================================
     * Versi pertama memakai `|`, dengan alasan yang ditulis di docblock-nya:
     * "tanpa pemisah, 'ani' dan 'ania' bisa saling menyusup". Alasannya benar
     * tetapi pemisahnya SALAH ARAH - `|` berkode 0x7C, dan itu LEBIH BESAR
     * daripada setiap huruf (a = 0x61). Akibatnya persis penyusupan yang
     * hendak dicegahnya, dan lebih buruk lagi:
     *
     *   "ani"  -> "ani|000000000001"
     *   "ania" -> "ania|000000000001"
     *
     * Pada karakter keempat `|` dibandingkan dengan `a`, dan `a` menang -
     * "ania" mendapat kursi sebelum "ani". Sama halnya dengan nama kosong,
     * yang kuncinya dimulai `|` sehingga terlempar ke BELAKANG seluruh
     * ruangan, bukan ke depan.
     *
     * `\x01` lebih kecil daripada setiap digit (0 = 0x30), huruf besar, dan
     * huruf kecil, sehingga pemisahnya selalu kalah lebih dulu - dan yang
     * dibandingkan menjadi benar-benar nama lebih dahulu, baru id. Nama tidak
     * pernah memuat byte ini, sehingga tidak ada nama yang bisa menirunya.
     */
    private const PEMISAH = "\x01";

    /**
     * Kunci urut yang MENGIKAT nomor kursi.
     *
     * Namanya dibandingkan lebih dulu secara utuh - lihat `PEMISAH` tentang
     * mengapa pemisahnya harus lebih kecil daripada setiap huruf.
     *
     * Id di sisi kanan di-`str_pad` supaya perbandingan teksnya sama dengan
     * perbandingan angka: "10" < "9" sebagai teks, dan itu akan menaruh anak
     * ke-sepuluh sebelum anak ke-sembilan.
     */
    public static function kunci(?string $nama, int $id): string
    {
        $bersih = mb_strtolower(trim((string) $nama, " \t\n\r\0\x0B."));

        return $bersih.self::PEMISAH.str_pad((string) $id, 12, '0', STR_PAD_LEFT);
    }

    /**
     * Urutkan penempatan ruang memakai kunci yang mengikat.
     *
     * @param  Collection<int, ExamParticipant>  $penempatan
     * @return Collection<int, ExamParticipant>
     */
    public function urutkan(Collection $penempatan): Collection
    {
        return $penempatan
            ->sortBy(fn (ExamParticipant $p) => self::kunci($p->student?->name, (int) $p->id))
            ->values();
    }

    /**
     * Nomor kursi setiap penempatan dalam satu ruang-sesi.
     *
     * Koleksinya harus sudah berisi penempatan SATU ruang saja - memanggilnya
     * dengan penempatan lintas ruang akan menomori seluruh sekolah dalam satu
     * deret, dan itu kesalahan yang tidak akan terlihat sampai angkanya
     * dibandingkan dengan daftar hadir.
     *
     * @param  Collection<int, ExamParticipant>  $penempatan
     * @return array<int, int> peserta_id => kursi (mulai 1)
     */
    public function peta(Collection $penempatan): array
    {
        return $this->beriNomor($penempatan, fn (ExamParticipant $p) => (int) $p->id);
    }

    /**
     * Penomoran yang sama, tetapi di-key menurut kolom pilihan pemanggil.
     *
     * =====================================================================
     * Mengapa ada, padahal `peta()` sudah cukup untuk satu ruang-sesi
     * =====================================================================
     * Sejak 2026-11-09 permukaan yang dilihat manusia - denah, tablo, papan
     * pengawas, laman siswa - menomori kursi atas RUANG EVENT, bukan atas
     * ruang-sesi. Satu siswa punya satu baris penempatan per ujian yang
     * diikutinya, dan seluruh baris itu menunjuk ruang yang sama; menomori
     * barisnya berarti siswa yang sama muncul di dua kursi. Karena itu
     * pemanggil itu perlu menomori menurut `student_id`, dan aturan urutannya
     * harus tetap yang satu ini - bukan salinan kedua yang bisa berbeda
     * diam-diam.
     *
     * `$kunci` mengembalikan id yang dipakai sebagai kunci hasil. Nilai
     * kembaliannya yang menentukan.
     *
     * @param  Collection<int, ExamParticipant>  $penempatan
     * @param  callable(ExamParticipant): int  $kunci
     * @return array<int, int> kunci => kursi (mulai 1)
     */
    public function beriNomor(Collection $penempatan, callable $kunci): array
    {
        $peta = [];

        foreach ($this->urutkan($penempatan) as $i => $p) {
            $peta[$kunci($p)] = $i + 1;
        }

        return $peta;
    }

    /**
     * Nomor kursi satu peserta, dihitung dari RUANG EVENT-nya.
     *
     * Dipakai laman siswa, yang hanya memegang satu penempatan dan tidak
     * memuat daftar ruangnya. Sejak nomornya berlaku sepanjang event, ruang
     * yang mengikat adalah ruang EVENT - bukan ruang-sesi - sehingga
     * perhitungannya diserahkan ke `PetaKursiEvent`, satu-satunya tempat yang
     * tahu cara menyusun penghuni ruang event.
     *
     * Yang tetap dipegang kelas ini: aturan URUTANNYA. `PetaKursiEvent`
     * memanggil `beriNomor()` di sini, jadi nomor di ponsel anak, di denah,
     * di tablo, dan di papan pengawas berasal dari satu aturan yang sama.
     */
    public function satu(ExamParticipant $peserta): ?int
    {
        return app(PetaKursiEvent::class)->kursiSatu($peserta);
    }

    /**
     * Nomor kursi SELURUH penghuni satu ruang event, sekaligus.
     *
     * Bentuk borongan dari `satu()`: pemanggilnya memegang daftar orang di
     * satu ruang dan perlu nomor untuk semuanya - papan pengawas, daftar
     * presensi, laman rincian Admin. Memanggil `satu()` per orang berarti
     * satu kueri per orang untuk menghitung himpunan yang sama, jadi yang
     * dikembalikan di sini adalah petanya sekali jalan.
     *
     * Kuncinya `student_id`, bukan id penempatan - satu siswa punya satu
     * baris penempatan per ujian, dan menomori barisnya akan menaruh siswa
     * yang sama di dua kursi. Alasan lengkapnya di `PetaKursiEvent`.
     *
     * @return array<int, int> student_id => kursi (mulai 1)
     */
    public function petaRuangEvent(int $eventRoomId): array
    {
        if ($eventRoomId <= 0) {
            return [];
        }

        return app(PetaKursiEvent::class)->kursi($eventRoomId);
    }

    /** Nomor meja sebuah kursi: kursi 1 dan 2 di meja 1, kursi 3 dan 4 di meja 2. */
    public static function meja(int $kursi): int
    {
        return (int) ceil($kursi / self::KURSI_PER_MEJA);
    }

    /**
     * Seluruh kursi satu ruang, termasuk yang kosong.
     *
     * Yang kosong ikut dikirim karena denah harus memperlihatkan ruang yang
     * belum penuh apa adanya: kursi kosong yang hilang dari gambar membuat
     * ruangan tampak lebih rapat daripada kenyataannya, dan pengawas yang
     * mencari kursi kosong untuk siswa pindahan akan menyusur meja yang
     * sebenarnya sudah terisi.
     *
     * @param  Collection<int, ExamParticipant>  $penempatan
     * @param  int  $kapasitas  Kursi yang disediakan ruang ini.
     * @return array<int, array{kursi: int, meja: int, kolom: int, baris: int, peserta: array<string, mixed>|null}>
     */
    public function denah(Collection $penempatan, int $kapasitas): array
    {
        $peta = $this->peta($penempatan);
        $olehId = $penempatan->keyBy('id');

        // Dibalik sekali: kursi -> penempatan. Versi sebelumnya menyusuri
        // seluruh peserta untuk setiap kursi, yang berarti empat puluh kali
        // empat puluh pembanding pada ruang terbesar - dan hasilnya sama.
        $olehKursi = [];

        foreach ($peta as $id => $n) {
            $olehKursi[$n] = $olehId->get($id);
        }

        // Kursi yang terisi di luar kapasitas tetap digambar. Keadaannya
        // seharusnya tidak terjadi - `PenempatanService::sebarKeRuang()`
        // menolaknya - tetapi denah yang menyembunyikan anak yang benar-benar
        // duduk di sana adalah denah yang berbohong tentang ruangannya.
        $jumlah = max($kapasitas, count($peta));

        $sel = [];

        for ($kursi = 1; $kursi <= $jumlah; $kursi++) {
            $meja = self::meja($kursi);

            /** @var ExamParticipant|null $p */
            $p = $olehKursi[$kursi] ?? null;

            $sel[] = [
                'kursi' => $kursi,
                'meja' => $meja,
                'baris' => intdiv($meja - 1, self::MEJA_PER_BARIS) + 1,
                'kolom' => (($meja - 1) % self::MEJA_PER_BARIS) + 1,
                'peserta' => $p === null ? null : [
                    'id' => (int) $p->id,
                    'nama' => $p->student?->name ?? 'Peserta',
                    'nis' => $p->student?->studentProfile?->nis,
                    'kelas' => $p->pesertaEvent?->kelas?->name,
                ],
            ];
        }

        return $sel;
    }
}
