<?php

namespace App\Domain\Event\Contracts;

use App\Domain\Event\Models\SchoolEvent;

/**
 * Butir soal per mata pelajaran yang hanya bisa disusun oleh modul ujian.
 *
 * =====================================================================
 * Mengapa kontrak di sini, bukan service yang dipanggil langsung
 * =====================================================================
 * Peta modul (`DomainBoundariesTest`) mencatat `Event -> Exam` sebagai arah
 * yang TIDAK ADA, dan ketiadaannya yang membuat modul Event bisa diuji tanpa
 * menjalankan satu ujian pun.
 *
 * Yang menyusun daftar ini harus membaca DUA hal yang bukan milik Event:
 *
 *   exam_questions   snapshot butir - modul Exam
 *   questions        bank soal hidup, untuk menilai "basi" - modul Question
 *
 * Modul Event tidak boleh mengimpor model keduanya. Kalau ia memanggil
 * `Exam\Services\ButirSoalPaket` secara langsung, panah `Event -> Exam`
 * lahir di peta - dan panah itu yang selama ini dihindari justru karena
 * arah sebaliknya sudah ada.
 *
 * Karena itu bentuknya meniru `ButirKesiapanUjian` persis: Event yang
 * MENDeklarasikan jawaban yang ia butuhkan, Exam yang menyediakannya lewat
 * provider. Event tidak pernah menyebut `App\Domain\Exam\*`.
 *
 * ---------------------------------------------------------------------
 * Yang dikembalikan hanya skalar, bukan model
 * ---------------------------------------------------------------------
 * Alasan yang sama dengan `PenghitungButir`: begitu modelnya menyeberang
 * batas, pemanggilnya bisa memanggil relasi, menyimpan, dan menghapusnya.
 * Yang kembali dari sini adalah teks, angka, dan boolean.
 */
interface ButirSoalPerMapel
{
    /**
     * Seluruh mapel event beserta soal yang akan dikerjakan siswa.
     *
     * Bentuknya daftar KELOMPOK per mapel - bukan daftar baris berkolom
     * mapel - karena pertanyaan yang dibawa Admin ke laman ini adalah "mapel
     * ini sudah beres belum", dan tabel berkolom mapel memaksanya memindai
     * ulang seluruh baris untuk menjawab satu mapel.
     *
     * Setiap kelompok membawa `keadaan` tersendiri, dan itu yang membuat
     * laman ini bukan sekadar pratinjau:
     *
     *   terpasang       paket terpasang, ujiannya sudah dibekukan.
     *   belum_dibekukan paket terpasang tetapi belum ada ujian yang
     *                   membekukannya. Yang tampil isi paketnya, dan itu
     *                   yang AKAN dikerjakan - bukan yang tersimpan.
     *   kosong          paket terpasang tetapi belum berisi butir.
     *   belum           mapel belum dipasangi paket.
     *
     * @return array{
     *     kelompok: array<int, array{
     *         mapel: array{id: int, kode: ?string, nama: ?string},
     *         paket: ?array{nama: string, status: string},
     *         keadaan: string,
     *         butir: int,
     *         poin: float,
     *         uraian: int,
     *         diarsipkan: int,
     *         basi: int,
     *         jenis: array<int, array{jenis: string, pendek: string, jumlah: int}>,
     *         baris: array<int, array<string, mixed>>
     *     }>,
     *     angka: array{mapel: int, butir: int, poin: float, uraian: int, diarsipkan: int, basi: int}
     * }
     */
    public function susun(SchoolEvent $event): array;
}
