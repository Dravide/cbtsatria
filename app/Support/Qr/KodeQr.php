<?php

namespace App\Support\Qr;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Penghasil QR untuk berkas yang DICETAK.
 *
 * ---------------------------------------------------------------------
 * Mengapa di Support, bukan di Facility
 * ---------------------------------------------------------------------
 * Yang memakainya sekarang hanya kartu pintu ruang, tetapi yang dihasilkan
 * kelas ini bukan milik modul mana pun: alamat apa pun bisa menjadi QR, dan
 * menaruhnya di Facility berarti modul berikutnya yang butuh QR harus
 * mengimpor Facility - sebuah ketergantungan yang tidak berarti apa-apa
 * tentang ruang.
 *
 * `app/Support` sudah menjadi tempat komponen generik seperti ini
 * (`PembacaBerkas`, `TabelQuery`).
 *
 * ---------------------------------------------------------------------
 * Data URI, supaya DomPDF tidak perlu mengambil gambar dari jaringan
 * ---------------------------------------------------------------------
 * DomPDF memuat gambar lewat `isRemoteEnabled`, yang secara bawaan MATI -
 * dan menyalakannya berarti PDF yang dirender server akan melakukan
 * permintaan HTTP ke alamat mana pun yang tertulis di dalam dokumen.
 * Data URI (`data:image/png;base64,...`) melewati seluruh jalur itu: gambarnya
 * sudah ada di dalam HTML-nya, dan tidak ada permintaan jaringan sama sekali.
 *
 * Itu juga yang membuat kartunya tetap benar saat dicetak di mesin yang
 * tidak bisa menjangkau server.
 */
final class KodeQr
{
    /**
     * QR sebagai data URI PNG.
     *
     * Koreksi galat MEDIUM, bukan bawaan `Low`. Kartu pintu ditempel di daun
     * pintu dan difoto pengawas dengan tangan yang tidak selalu diam; `Low`
     * adalah tingkat yang paling cepat rusak begitu sebagian kodenya kotor
     * atau memantulkan cahaya. `High` lebih tahan lagi, tetapi memadatkan
     * modulnya sampai batas yang membuat pemindai ponsel murah kesulitan -
     * dan yang memindai di sini adalah ponsel biasa, bukan pemindai khusus.
     */
    public static function dataUri(string $isi, int $ukuran = 320): string
    {
        $qr = new QrCode(
            data: $isi,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $ukuran,
            margin: 4,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        return (new PngWriter)->write($qr)->getDataUri();
    }
}
