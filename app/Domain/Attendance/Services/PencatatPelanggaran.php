<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Support\TanggaPelanggaran;
use App\Domain\Attendance\Support\TindakanPelanggaran;
use App\Domain\Tenancy\Support\PengaturanBawaan;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya penulis `exam_violations` - blueprint `:505-600`, plate 16 & 29.
 *
 * ---------------------------------------------------------------------
 * Mengapa satu kelas, padahal penulisnya satu endpoint
 * ---------------------------------------------------------------------
 * Endpoint pelanggaran memang cuma satu, tetapi ada DUA peristiwa yang
 * menghasilkan baris di tabel ini: laporan peramban siswa, dan pembukaan
 * kunci oleh pengawas - yang ditulis di controller lain. Menyebar
 * penulisannya berarti dua tempat yang bisa lupa menaikkan
 * `violation_count`, dan angka itu yang dibaca papan monitoring SETIAP kali
 * disegarkan.
 *
 * ---------------------------------------------------------------------
 * Tingkat 0 tidak menulis baris, dan itu diperiksa di sini
 * ---------------------------------------------------------------------
 * Peramban sudah memblokirnya diam-diam. Yang membuat pemeriksaan ini tetap
 * perlu: peramban adalah kode yang berjalan di perangkat orang lain, dan
 * permintaan yang ditempel langsung - atau peramban versi lama - akan
 * mengirim apa saja. Yang dijaga di sini karena itu bukan "apakah siswa
 * menekan klik kanan", melainkan "apakah tabel ini tetap berisi hal-hal
 * yang layak dibaca".
 *
 * ---------------------------------------------------------------------
 * Penghitung dan penguncian ditulis dalam SATU transaksi
 * ---------------------------------------------------------------------
 * `lockForUpdate()` pada baris attempt, lalu hitung ulang, lalu tulis
 * barisnya. Dua laporan yang datang bersamaan - hal biasa saat siswa
 * membuka dua tab - akan berbaris di sini, dan yang kedua membaca
 * `violation_count` yang sudah dinaikkan yang pertama. Tanpa itu, dua
 * pelanggaran bisa menghasilkan hitungan satu, dan siswa yang seharusnya
 * sudah terkunci tidak terkunci.
 *
 * ---------------------------------------------------------------------
 * Yang mengunci adalah mesin, tetapi yang MEMUTUSKAN adalah event
 * ---------------------------------------------------------------------
 * Ambangnya dibaca dari `SchoolEvent::ambang()`, bukan dari pengaturan
 * sekolah. Bedanya bukan detail: pengaturan sekolah adalah bawaan untuk
 * event BARU, dan membaca balik ke sana berarti mengubah ambang di tengah
 * ujian yang sedang berjalan ikut mengubah aturan main anak yang sedang
 * mengerjakan. Lihat SchoolEvent dan PengaturanBawaan.
 */
class PencatatPelanggaran
{
    /**
     * Catat satu pelanggaran dan kembalikan tindakan yang berlaku.
     *
     * @param  string  $jenis  Kunci `ExamViolation::JENIS`.
     * @param  int|null  $durasiMs  Berapa lama keadaannya berlangsung.
     * @param  array<string, mixed>  $metadata
     */
    public function catat(
        ExamAttempt $attempt,
        string $jenis,
        ?int $durasiMs = null,
        array $metadata = [],
        bool $dariPeramban = true,
    ): TindakanPelanggaran {
        // Tingkat 0 - diblokir di peramban, dan tidak pernah menjadi baris.
        if (! TanggaPelanggaran::dicatat($jenis)) {
            return TindakanPelanggaran::halus();
        }

        // Attempt yang sudah ditutup tidak lagi menerima pelanggaran baru.
        // Laporan yang datang sesudah waktu habis adalah laporan tentang
        // layar yang sudah tidak lagi menampilkan ujian, dan mencatatnya
        // berarti menambah catatan perilaku dari kejadian yang tidak ada.
        if ($attempt->status !== ExamAttempt::STATUS_BERJALAN) {
            return TindakanPelanggaran::halus();
        }

        return DB::transaction(function () use ($attempt, $jenis, $durasiMs, $metadata, $dariPeramban) {
            $terkunci = ExamAttempt::query()
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $sekarang = now();
            $severity = TanggaPelanggaran::tingkat($jenis);

            ExamViolation::create([
                'school_id' => $terkunci->school_id,
                'exam_attempt_id' => $terkunci->id,
                'exam_participant_id' => $terkunci->exam_participant_id,
                'violation_type' => $jenis,
                'severity' => $severity,
                'occurred_at' => $sekarang,
                'duration_ms' => $durasiMs,
                'metadata_json' => $metadata ?: null,
                'reported_by_client' => $dariPeramban,
                'created_at' => $sekarang,
            ]);

            $jumlah = $terkunci->violation_count + 1;
            $terberat = max($terkunci->violation_worst_severity, $severity);

            $tindakan = $this->tindakanUntuk($terkunci, $jenis, $jumlah, $terberat);

            $kolom = [
                'violation_count' => $jumlah,
                'violation_worst_severity' => $terberat,
            ];

            // Keadaan yang mengunci seketika disimpan sebagai penanda attempt,
            // bukan dihitung ulang dari riwayat: pertanyaannya "apakah
            // attempt ini pernah dibuka dari dua perangkat", dan jawabannya
            // tidak berubah sesudah kejadian itu.
            if (TanggaPelanggaran::segeraKunci($jenis)) {
                $kolom['guard_flags_json'] = [
                    ...($terkunci->guard_flags_json ?? []),
                    'sesi_ganda' => true,
                ];
            }

            if ($tindakan->kunci && ! $terkunci->dikunci()) {
                $kolom['status'] = ExamAttempt::STATUS_DIKUNCI;
                $kolom['lock_reason'] = $this->alasanKunci($jenis, $jumlah);
                $kolom['locked_at'] = $sekarang;
            }

            $terkunci->forceFill($kolom)->save();

            // Model yang dikembalikan pemanggil adalah yang sudah diperbarui,
            // supaya layar siswa langsung membaca keadaan barunya tanpa
            // memuat ulang.
            $attempt->setRawAttributes($terkunci->getAttributes(), true);

            return $tindakan;
        });
    }

    /**
     * Tulis baris pembukaan kunci - siapa, kapan, dan atas alasan apa.
     *
     * Jenisnya `kunci_dibuka` dan tingkatnya 0, sehingga ia TIDAK menambah
     * `violation_count` dan tidak pernah terbaca sebagai pelanggaran siswa.
     * Yang membuatnya tetap harus ada: tanpa baris ini, satu-satunya bukti
     * bahwa sebuah penguncian pernah terjadi adalah kolom `locked_at` yang
     * sudah dikosongkan.
     */
    public function catatPembukaan(
        ExamAttempt $attempt,
        string $pelakuLabel,
        string $alasan,
        ?int $pelakuId = null,
    ): ExamViolation {
        return DB::transaction(function () use ($attempt, $pelakuLabel, $alasan, $pelakuId) {
            $sekarang = now();

            $baris = ExamViolation::create([
                'school_id' => $attempt->school_id,
                'exam_attempt_id' => $attempt->id,
                'exam_participant_id' => $attempt->exam_participant_id,
                'violation_type' => 'kunci_dibuka',
                'severity' => 0,
                'occurred_at' => $sekarang,
                'duration_ms' => null,
                'metadata_json' => [
                    'oleh' => $pelakuLabel,
                    'alasan' => $alasan,
                    'jumlah_pelanggaran' => $attempt->violation_count,
                ],
                // Bukan laporan peramban: yang menuliskannya pengawas, dan
                // perbedaan itu menentukan siapa yang bisa membantahnya.
                'reported_by_client' => false,
                // Boleh null, dan itu memang keadaan pemegang tautan ruang:
                // ia tidak punya akun sama sekali. Identitasnya dibawa
                // `pelakuLabel` ("R-01 (tautan ruang)"), dan kolom ini kosong
                // lebih jujur daripada mengarang akun pengawas mana pun -
                // alasan yang sama dengan keputusan KonteksRuang.
                'resolved_by' => $pelakuId,
                'resolved_at' => $sekarang,
                'created_at' => $sekarang,
            ]);

            // Penguncian dilepas, penghitungnya TIDAK direset. Reset berarti
            // siswa kembali dengan nol pelanggaran, dan ambang yang sama harus
            // dilampaui dari awal lagi - sehingga membuka kunci sekali
            // menghapus seluruh riwayatnya secara praktis.
            $attempt->forceFill([
                'status' => ExamAttempt::STATUS_BERJALAN,
                'lock_reason' => null,
                'locked_at' => null,
            ])->save();

            return $baris;
        });
    }

    /**
     * Tangga eskalasi untuk keadaan attempt sekarang.
     *
     * Ambangnya dibaca dari event lewat `ExamParticipant → exam → event`.
     * Bila rantai itu putus - penempatan tanpa ujian, ujian tanpa event -
     * yang dipakai adalah bawaan pengaturan sekolah, bukan kegagalan:
     * siswa yang tidak bisa dikunci karena data induknya tidak lengkap
     * adalah akibat yang jauh lebih buruk daripada ambang yang memakai
     * angka umum.
     */
    private function tindakanUntuk(
        ExamAttempt $attempt,
        string $jenis,
        int $jumlah,
        int $terberat,
    ): TindakanPelanggaran {
        $bawaan = (int) (PengaturanBawaan::NILAI['ambang_pelanggaran'] ?? 5);

        return TanggaPelanggaran::tindakan(
            $attempt->ambang($bawaan),
            $jumlah,
            $terberat,
            $this->segeraKunci($attempt, $jenis),
        );
    }

    /**
     * Apakah attempt ini harus dikunci tanpa menunggu hitungan.
     *
     * Dua sumber, dan keduanya harus diperiksa: pelanggaran yang BARU masuk,
     * dan penanda yang sudah tersimpan di attempt. Yang kedua penting untuk
     * rangkaian - attempt yang pernah dibuka dari dua perangkat tetap
     * terkunci pada pelanggaran berikutnya, apa pun jenisnya.
     */
    private function segeraKunci(ExamAttempt $attempt, string $jenis): bool
    {
        return TanggaPelanggaran::segeraKunci($jenis)
            || (bool) ($attempt->guard_flags_json['sesi_ganda'] ?? false);
    }

    /**
     * Kalimat yang dibaca siswa di plate 25 dan pengawas di plate 16.
     *
     * Menyebut jumlahnya, karena pertanyaan pertama setiap orang yang
     * membacanya adalah "berapa banyak" - dan riwayatnya sendiri baru bisa
     * dibuka pengawas, bukan siswa yang sedang menatap layar terkunci.
     */
    private function alasanKunci(string $jenis, int $jumlah): string
    {
        $label = ExamViolation::JENIS[$jenis] ?? $jenis;

        return match (true) {
            TanggaPelanggaran::segeraKunci($jenis) => "Ujian dikunci: {$label}.",
            default => "Terdeteksi {$jumlah} pelanggaran, terakhir {$label}.",
        };
    }
}
