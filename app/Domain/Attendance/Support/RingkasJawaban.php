<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Question\Enums\JenisSoal;

/**
 * Jawaban satu butir, dalam bentuk yang bisa DIBACA laman riwayat.
 *
 * ---------------------------------------------------------------------
 * Mengapa bentuk dirakit di sini, bukan dikirim mentah ke peramban
 * ---------------------------------------------------------------------
 * `answer_json` dan `kunci_json` memakai sepuluh bentuk yang berbeda, dan
 * laman yang merakitnya sendiri akan menyalin kesepuluh bentuk itu ke
 * TypeScript - dua tempat yang harus setuju tentang arti `rumpang`,
 * `pasangan`, dan `urutan`. Yang kedua akan terlupakan.
 *
 * Karena itu perakitnya satu kelas PHP, dan yang keluar sudah berupa
 * kalimat atau daftar yang tinggal digambar: `dipilih` berisi huruf opsi,
 * `teks` berisi jawaban yang diketik, dan `pasangan` berisi baris
 * kiri-kanan. Peramban tidak perlu tahu satu pun nama kunci.
 *
 * ---------------------------------------------------------------------
 * Yang dirakitnya hanya BENTUK, bukan kebenaran
 * ---------------------------------------------------------------------
 * "Benar atau salah" TIDAK dihitung di sini. Angka itu sudah tersimpan di
 * `student_answers.is_correct` sejak penilaian berjalan, dan menghitungnya
 * ulang di laman riwayat berarti mesin penilai kedua - yang akan berbeda
 * dari yang pertama begitu satu kunci diperbaiki Admin lewat tombol "Hitung
 * ulang". Yang dikirim adalah jawabannya; kebenarannya dibaca dari barisnya.
 *
 * Alasan yang sama untuk `kunci_json`: ia TIDAK dirakit di sini. Kunci
 * jawaban hanya boleh keluar bila Admin membuka pembahasan
 * (`show_explanations`), dan yang memutuskan itu laman, bukan kelas ini -
 * kelas ini tidak punya akses ke event dan tidak boleh menebaknya.
 */
class RingkasJawaban
{
    /**
     * Jawaban siswa untuk satu butir.
     *
     * @param  array<string, mixed>|null  $jawaban  `student_answers.answer_json`.
     * @param  array<string, mixed>|null  $butir  `exam_questions.butir_json` -
     *                                            dipakai menerjemahkan kunci
     *                                            butir menjadi teksnya.
     * @return array<string, mixed>
     */
    public function jawaban(JenisSoal $jenis, ?array $jawaban, ?array $butir): array
    {
        $jawaban ??= [];

        return [
            // Opsi yang dipilih - pilihan ganda, jawaban ganda, benar/salah,
            // dan soal media yang memakai mekanisme pilihan.
            'dipilih' => $this->daftarOpsi($jawaban),

            // Jawaban yang DIKETIK - isian singkat dan angka. Angka dikirim
            // apa adanya sebagai teks: "3,14" yang ditulis anak adalah
            // jawaban yang ia lihat sendiri, dan mengubahnya menjadi 3.14 di
            // laman riwayat membuatnya mengira sistem menerima yang lain.
            'teks' => $this->teksDiketik($jawaban),

            // Satu isian per lubang, berurutan.
            'rumpang' => $this->daftarRumpang($jawaban),

            // Baris kiri-kanan hasil penjodohan, sudah dipetakan ke TEKS
            // butirnya - `pasangan` di jawaban menyimpan kunci butir, dan
            // kunci butir bukan sesuatu yang bisa dibaca anak.
            'pasangan' => $this->pasangan($jenis, $jawaban, $butir),

            // Urutan yang disusun anak, sudah dipetakan ke teks butirnya.
            'urutan' => $this->urutan($jenis, $jawaban, $butir),

            // Uraian: isi mentahnya, tanpa rubrik. Rubrik adalah pedoman
            // korektor dan memuat kata kunci yang dicari - lihat
            // ExamQuestion::untukSiswa().
            'uraian' => $jenis === JenisSoal::Uraian ? $this->uraian($jawaban) : null,
        ];
    }

    /**
     * Kunci jawaban dalam bentuk yang sama - HANYA dipanggil ketika Admin
     * membuka pembahasan.
     *
     * @param  array<string, mixed>|null  $kunci
     * @param  array<string, mixed>|null  $butir
     * @return array<string, mixed>
     */
    public function kunci(JenisSoal $jenis, ?array $kunci, ?array $butir): array
    {
        $kunci ??= [];

        return [
            'dipilih' => $this->daftarOpsi($kunci),
            'teks' => $this->teksDiketik($kunci) ?? $this->varianPertama($kunci),
            'rumpang' => $this->daftarRumpang($kunci),
            'pasangan' => $this->pasanganKunci($kunci, $butir),
            'urutan' => $this->urutanKunci($kunci, $butir),
            'angka' => $this->angkaKunci($kunci),
            'uraian' => $jenis === JenisSoal::Uraian ? $this->uraianKunci($kunci) : null,
        ];
    }

    /* ------------------------------------------------------------- Pembantu */

    /**
     * @param  array<string, mixed>  $isi
     * @return array<int, string>
     */
    private function daftarOpsi(array $isi): array
    {
        return $this->daftarNilai($isi['opsi'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $isi
     */
    private function teksDiketik(array $isi): ?string
    {
        $teks = $isi['teks'] ?? null;

        if (is_string($teks) && trim($teks) !== '') {
            return trim($teks);
        }

        // Soal angka menyimpan jawabannya di `nilai`, bukan `teks` - lihat
        // PenilaiButir::angka().
        $angka = $isi['nilai'] ?? null;

        if (is_string($angka) && trim($angka) !== '') {
            return trim($angka);
        }

        if (is_int($angka) || is_float($angka)) {
            return (string) $angka;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $isi
     * @return array<int, string>
     */
    private function daftarRumpang(array $isi): array
    {
        $rumpang = $isi['rumpang'] ?? null;

        if (! is_array($rumpang)) {
            return [];
        }

        return array_map(
            fn ($satu) => is_string($satu) ? trim($satu) : (is_numeric($satu) ? (string) $satu : ''),
            array_values($rumpang),
        );
    }

    /**
     * @param  mixed  $nilai
     * @return array<int, string>
     */
    private function daftarNilai($nilai): array
    {
        if (! is_array($nilai)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                fn ($satu) => is_string($satu) ? trim($satu) : (is_numeric($satu) ? (string) $satu : null),
                $nilai,
            ),
            fn (?string $satu) => $satu !== null && $satu !== '',
        ));
    }

    /**
     * Hasil penjodohan, dipetakan ke teks butirnya.
     *
     * `pasangan` di jawaban berbentuk `{kunci_kiri: kunci_kanan}`, dan
     * `butir_json` snapshot memuat `kunci`, `teks`, dan `pasangan` (kunci
     * kanan yang benar) per baris. Yang dikirim adalah TEKS kiri dan TEKS
     * kanan; kunci butirnya tidak berarti apa pun bagi anak yang membaca
     * lamannya.
     *
     * @param  array<string, mixed>  $jawaban
     * @param  array<string, mixed>|null  $butir
     * @return array<int, array{kiri: string, kanan: string}>
     */
    private function pasangan(JenisSoal $jenis, array $jawaban, ?array $butir): array
    {
        if ($jenis !== JenisSoal::Menjodohkan) {
            return [];
        }

        $dipasangkan = $jawaban['pasangan'] ?? null;

        if (! is_array($dipasangkan)) {
            return [];
        }

        $peta = $this->petaButir($butir);

        $baris = [];

        foreach ($dipasangkan as $kiri => $kanan) {
            $baris[] = [
                'kiri' => $this->teksButir($peta[(string) $kiri] ?? null, (string) $kiri),
                // Belum dipasangkan berarti kanannya kosong, dan baris
                // setengah jadi itu memang harus TERLIHAT - bagian yang
                // belum dijawab adalah bagian yang paling dicari anak di
                // laman riwayat.
                'kanan' => is_string($kanan) && $kanan !== ''
                    ? $this->teksButir($peta[$kanan] ?? null, $kanan)
                    : '',
            ];
        }

        return $baris;
    }

    /**
     * Kunci penjodohan, dalam bentuk baris yang sama.
     *
     * `kunci_json.pasangan` berbentuk `{kunci_kiri: kunci_kanan}`, sehingga
     * teks keduanya bisa dipetakan dari `butir_json` snapshot.
     *
     * @param  array<string, mixed>  $kunci
     * @param  array<string, mixed>|null  $butir
     * @return array<int, array{kiri: string, kanan: string}>
     */
    private function pasanganKunci(array $kunci, ?array $butir): array
    {
        $pasangan = $kunci['pasangan'] ?? null;

        if (! is_array($pasangan)) {
            return [];
        }

        $peta = $this->petaButir($butir);

        $baris = [];

        foreach ($pasangan as $kiri => $kanan) {
            $baris[] = [
                'kiri' => $this->teksButir($peta[(string) $kiri] ?? null, (string) $kiri),
                'kanan' => is_string($kanan) && $kanan !== ''
                    ? $this->teksButir($peta[$kanan] ?? null, $kanan)
                    : '',
            ];
        }

        return $baris;
    }

    /**
     * Urutan yang disusun anak, sebagai teks butir.
     *
     * @param  array<string, mixed>  $jawaban
     * @param  array<string, mixed>|null  $butir
     * @return array<int, string>
     */
    private function urutan(JenisSoal $jenis, array $jawaban, ?array $butir): array
    {
        if ($jenis !== JenisSoal::Mengurutkan) {
            return [];
        }

        return $this->petakanButir($this->daftarNilai($jawaban['urutan'] ?? null), $butir);
    }

    /**
     * @param  array<string, mixed>  $kunci
     * @param  array<string, mixed>|null  $butir
     * @return array<int, string>
     */
    private function urutanKunci(array $kunci, ?array $butir): array
    {
        return $this->petakanButir($this->daftarNilai($kunci['urutan'] ?? null), $butir);
    }

    /**
     * @param  array<int, string>  $kunciButir
     * @param  array<string, mixed>|null  $butir
     * @return array<int, string>
     */
    private function petakanButir(array $kunciButir, ?array $butir): array
    {
        $peta = $this->petaButir($butir);

        return array_map(fn (string $k) => $this->teksButir($peta[$k] ?? null, $k), $kunciButir);
    }

    /**
     * Teks satu butir, dari `content_json` snapshotnya.
     *
     * `butir_json` disalin dari `QuestionItem::content_json`, yang berbentuk
     * `['teks' => '...']` - BUKAN string. Membacanya sebagai string
     * menghasilkan baris kosong untuk setiap butir, dan itu hanya terlihat
     * pada soal menjodohkan atau mengurutkan.
     *
     * @param  array<string, mixed>|null  $butir
     */
    private function teksButir(?array $butir, string $cadangan): string
    {
        $teks = $butir['teks'] ?? null;

        if (is_array($teks)) {
            $teks = $teks['teks'] ?? null;
        }

        return is_string($teks) && trim($teks) !== '' ? trim($teks) : $cadangan;
    }

    /**
     * Butir snapshot, dipetakan dari kuncinya - untuk menerjemahkan kunci
     * butir menjadi teks yang dibaca anak.
     *
     * `butir_json` bukan milik jawaban DAN bukan milik kunci: keduanya
     * merujuk padanya. Peta kosong membuat kuncinya ditampilkan apa adanya,
     * yang lebih baik daripada barisnya hilang.
     *
     * @param  array<string, mixed>|null  $butir
     * @return array<string, array<string, mixed>>
     */
    private function petaButir(?array $butir): array
    {
        if ($butir === null) {
            return [];
        }

        $peta = [];

        foreach ($butir as $satu) {
            if (! is_array($satu) || ! isset($satu['kunci'])) {
                continue;
            }

            $peta[(string) $satu['kunci']] = $satu;
        }

        return $peta;
    }

    /**
     * @param  array<string, mixed>  $kunci
     */
    private function varianPertama(array $kunci): ?string
    {
        $varian = $this->daftarNilai($kunci['teks'] ?? null);

        return $varian[0] ?? null;
    }

    /**
     * Angka beserta toleransinya, karena jawaban yang meleset 0,005 dari
     * kunci tetap dinilai benar dan anak berhak tahu mengapa.
     *
     * @param  array<string, mixed>  $kunci
     * @return array{nilai: string, toleransi: string}|null
     */
    private function angkaKunci(array $kunci): ?array
    {
        if (! array_key_exists('nilai', $kunci)) {
            return null;
        }

        return [
            'nilai' => (string) $kunci['nilai'],
            'toleransi' => (string) ($kunci['toleransi'] ?? '0'),
        ];
    }

    /**
     * @param  array<string, mixed>  $jawaban
     */
    private function uraian(array $jawaban): ?string
    {
        $teks = $jawaban['teks'] ?? null;

        return is_string($teks) && trim($teks) !== '' ? trim($teks) : null;
    }

    /**
     * Uraian tidak punya kunci - ia punya rubrik, dan rubrik TIDAK dikirim
     * ke siswa. Null di sini adalah jawaban yang benar, bukan kekurangan.
     *
     * @param  array<string, mixed>  $kunci
     */
    private function uraianKunci(array $kunci): ?string
    {
        return null;
    }
}
