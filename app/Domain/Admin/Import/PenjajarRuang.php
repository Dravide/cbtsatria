<?php

namespace App\Domain\Admin\Import;

use App\Support\Impor\PenjajarKolom;

/**
 * Penjajar nama kolom berkas impor RUANG UJIAN.
 *
 * Seluruh algoritmanya tinggal di `App\Support\Impor\PenjajarKolom`; yang ada
 * di sini hanya DAFTAR KOLOMNYA.
 *
 * =====================================================================
 * `nama` opsional di sini, dan WAJIB di mapel
 * =====================================================================
 * Bukan kelalaian: di skemanya `rooms.name` memang nullable. Ruang yang cuma
 * punya kode - "R-01" - adalah ruang yang sah, dan menuntut namanya berarti
 * menolak berkas yang seluruh barisnya bisa disimpan.
 *
 * Yang WAJIB hanya `kode`, sama seperti di layarnya: `RoomController::store()`
 * menuntut `code` dan tidak menuntut `name`.
 *
 * =====================================================================
 * `pc` adalah nama kolomnya, bukan `device_count`
 * =====================================================================
 * `device_count` adalah nama kolom basis datanya; "PC" adalah kata yang
 * dipakai layar, filter, dan ringkasan di laman Ruang. Yang mengetik berkasnya
 * adalah Admin yang membaca laman itu, bukan yang membaca migrasinya.
 *
 * `device_count` tetap diterima lewat SINONIM, sehingga berkas yang disiapkan
 * dari dokumen teknis - atau dari ekspor basis data - tidak ditolak karena
 * memakai nama yang benar tetapi bukan nama yang lazim.
 *
 * =====================================================================
 * `byod` sengaja TIDAK ada sebagai kolom
 * =====================================================================
 * Ruang BYOD adalah `pc` bernilai 0 - sudah dijelaskan di `Room::isByod()`.
 * Kolom `byod` yang terpisah berarti dua sumber kebenaran untuk satu keadaan,
 * dan berkas yang mengisi `byod=ya` bersama `pc=32` adalah berkas yang
 * bertentangan dengan dirinya sendiri. Yang menentukan perangkatnya adalah
 * angka PC-nya.
 */
class PenjajarRuang extends PenjajarKolom
{
    /**
     * Nama kanonik kolom, dalam urutan yang dipakai template unduhan.
     *
     * @var array<int, string>
     */
    public const KOLOM = ['kode', 'nama', 'lokasi', 'kapasitas', 'pc', 'status'];

    /**
     * Kolom yang HARUS ada di berkasnya - hanya `kode`, lihat catatan kepala
     * kelas.
     *
     * @var array<int, string>
     */
    public const WAJIB = ['kode'];

    /**
     * Sinonim nama kolom, supaya Admin tidak perlu menghafal template.
     *
     * @var array<string, string>
     */
    protected const SINONIM = [
        'kode_ruang' => 'kode',
        'koderuang' => 'kode',
        'code' => 'kode',
        'room' => 'kode',
        'nama_ruang' => 'nama',
        'namaruang' => 'nama',
        'name' => 'nama',
        'lokasi_ruang' => 'lokasi',
        'gedung' => 'lokasi',
        'location' => 'lokasi',
        'daya_tampung' => 'kapasitas',
        'kapasitas_ruang' => 'kapasitas',
        'capacity' => 'kapasitas',
        'jumlah_pc' => 'pc',
        'jumlah_komputer' => 'pc',
        'komputer' => 'pc',
        'device_count' => 'pc',
        'device' => 'pc',
        'perangkat' => 'pc',
        'is_active' => 'status',
        'aktif' => 'status',
        'kondisi' => 'status',
    ];
}
