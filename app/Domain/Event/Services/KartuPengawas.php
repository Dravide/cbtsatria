<?php

namespace App\Domain\Event\Services;

use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\SchoolEvent;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Kartu panitia dan pengawas sebagai PDF siap cetak - PRD §6.6.
 *
 * ---------------------------------------------------------------------
 * Mengapa dikirim sebagai BERKAS, bukan dicetak dari peramban
 * ---------------------------------------------------------------------
 * Kartu ini dicetak di printer sekolah, sering oleh orang yang bukan Admin
 * yang membukanya, dan sering beberapa hari sebelum ujian. Berkas yang
 * tersimpan bisa dicetak ulang berkali-kali, dikirim lewat WhatsApp ke
 * pengawas yang tidak sempat ke sekolah, dan disimpan sebagai arsip - tiga
 * hal yang tidak bisa dilakukan layar yang hanya punya `window.print()`.
 *
 * PDF-nya juga membuat ukurannya pasti: kartu ini dicetak pada kertas yang
 * sudah dipotong atau dilaminasi, dan tata letak yang ditentukan server
 * tidak bergantung pada pengaturan margin peramban masing-masing orang.
 *
 * ---------------------------------------------------------------------
 * Satu kartu untuk SETIAP baris, bukan hanya yang punya kode
 * ---------------------------------------------------------------------
 * Pemilik produk memintanya untuk "pengawas dan kepanitiaan lainnya": ketua
 * dan koordinator juga berdiri di depan pintu dan ditanya "ini siapa" oleh
 * pengawas lain. Karena itu setiap baris mendapat kartu, dan yang membedakan
 * hanya isinya - kode pintu hanya muncul untuk baris yang menurut
 * `bolehPunyaKode()` memang memilikinya.
 *
 * ---------------------------------------------------------------------
 * Tautan TIDAK pernah masuk ke kartu ini
 * ---------------------------------------------------------------------
 * Yang tercetak hanyalah kode, dan kode menentukan ORANG - bukan ruang.
 * Tautan pintu (`/p/{slug}`) ditempel di daun pintu, bukan dipegang orang,
 * dan kartu yang memuat keduanya akan mengulang persis kebocoran yang
 * ditutup pada 2026-11-07: memfoto satu kertas sudah cukup untuk masuk.
 */
class KartuPengawas
{
    /**
     * Kartu per halaman, dan itu menentukan ukurannya.
     *
     * A4 adalah satu-satunya ukuran yang dijamin ada di printer sekolah.
     * Delapan kartu (dua kolom, empat baris) menghabiskan satu halaman penuh
     * tanpa sisa, sehingga satu event dengan 31 pengawas memakai empat
     * lembar - jumlah yang bisa dihitung Admin sebelum menekan tombolnya.
     */
    public const PER_HALAMAN = 8;

    public const UKURAN = 'a4';

    /**
     * Render seluruh kartu event ini sebagai satu PDF.
     *
     * @param  iterable<int, EventCommitteeMember>  $panitia
     * @param  array{nama: string, npsn: string|null, logo: string|null}  $sekolah
     */
    public function buat(SchoolEvent $event, iterable $panitia, array $sekolah): string
    {
        return Pdf::loadView('kartu.pengawas', [
            'sekolah' => $sekolah,
            'event' => [
                'nama' => $event->name,
                'kode' => $event->code,
            ],
            'kartu' => $this->kartu($panitia),
        ])->setPaper(self::UKURAN, 'portrait')->output();
    }

    public function namaBerkas(SchoolEvent $event): string
    {
        return 'kartu-panitia-'.$event->code.'.pdf';
    }

    /**
     * Satu kartu sebagai array siap render.
     *
     * Dihitung di sini, bukan di blade: blade adalah templat tata letak, dan
     * keputusan seperti "apakah baris ini boleh punya kode" adalah aturan
     * domain yang sudah hidup di modelnya. Menyalinnya ke blade berarti dua
     * tempat yang harus tetap sepakat tentang siapa yang berhak memegang
     * kode.
     *
     * Public karena inilah yang diuji: kartu yang salah memuat kode adalah
     * kebocoran yang tidak terlihat pada PDF, dan menguji PDF-nya sendiri
     * berarti memeriksa teks terkompresi. Data kartunya diuji, tata letaknya
     * tidak.
     *
     * @param  iterable<int, EventCommitteeMember>  $panitia
     * @return array<int, array<string, string|null>>
     */
    public function kartu(iterable $panitia): array
    {
        $kartu = [];

        foreach ($panitia as $p) {
            $kartu[] = [
                'nama' => $p->name,
                'jabatan' => $p->position,
                'cakupan' => $p->labelCakupan(),
                // Kode ditulis hanya bila baris ini memang memegangnya.
                // `bolehPunyaKode()` adalah syarat yang sama dengan yang
                // dipakai laman Kepanitiaan untuk menampilkan kolomnya -
                // sehingga kartu dan layarnya tidak bisa berselisih.
                'kode' => $p->bolehPunyaKode() ? $p->code : null,
                'akun' => $p->user?->username,
            ];
        }

        return $kartu;
    }
}
