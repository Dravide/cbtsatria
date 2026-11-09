<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Daftar-filter-tabel dengan paginasi, pencarian, dan urutan di sisi server.
 *
 * ---------------------------------------------------------------------
 * Mengapa di sisi server, dan bukan di peramban
 * ---------------------------------------------------------------------
 * Wireframe plate 05 menampilkan tepi halaman "1-50 dari 1.284". Seribu dua
 * ratus akun tidak pernah dikirim ke peramban sekaligus: itu berarti setiap
 * kali laman Pengguna dibuka, seluruh daftar siswa satu sekolah melintas di
 * jaringan - termasuk yang tidak sedang dilihat siapa pun, dan termasuk bagi
 * pengguna yang tidak berhak melihatnya.
 *
 * Menyaring di sisi klien juga membuat "cari NIS" berarti mengunduh dulu
 * seluruh daftar, lalu menyaringnya. Yang tampak seperti pencarian adalah
 * pengiriman data.
 *
 * ---------------------------------------------------------------------
 * Mengapa kolom urutan dibatasi daftar putih
 * ---------------------------------------------------------------------
 * `?urut=id` yang diteruskan apa adanya ke orderBy adalah cara paling mudah
 * membuka kolom yang tidak seharusnya, dan pada beberapa bentuk query, lebih
 * dari itu. Daftar putihnya diberikan pemanggil, per laman, dan apa pun di
 * luar daftar itu diabaikan diam-diam - bukan diganti dengan urutan default
 * yang menipu, tetapi memang tidak menghasilkan urutan apa pun sampai
 * pemanggilnya menentukan.
 *
 * ---------------------------------------------------------------------
 * Mengapa penyaringnya dipisah dari paginasi
 * ---------------------------------------------------------------------
 * Laman yang mengelompokkan barisnya - "Tingkat 7 · 12 mapel" - tidak bisa
 * memberi angka di kepala kelompoknya kalau yang dibaca hanya satu halaman:
 * kepala kelompok yang berbunyi "12 mapel" sementara di bawahnya tampil tiga
 * baris adalah angka yang salah, dan Admin membacanya sebagai data hilang.
 *
 * Karena itu laman seperti itu mengambil SELURUH hasil saringannya. Tetapi
 * arti "cari" harus tetap satu: `%kata%` di tiga kolom, nilai kosong berarti
 * tidak menyaring. Dua salinan aturan itu akan berbeda suatu hari - dan yang
 * berbeda adalah halaman yang Admin pakai mencari, bukan kode yang belum
 * pernah ditulis.
 */
class TabelQuery
{
    /** Berapa baris per halaman. Angka ini juga yang muncul di "1-50 dari ...". */
    public const PER_HALAMAN = 50;

    /**
     * Terapkan pencarian dan penyaring, tanpa memaginasi.
     *
     * Dipakai `buat()` sendiri; dipisah supaya laman yang mengelompokkan
     * seluruh barisnya menyaring dengan aturan yang sama persis.
     *
     * @param  Builder<*>  $query
     * @param  array<int, string>  $cariDi  Kolom yang boleh dicari.
     * @param  array<string, string>  $penyaring  Peta param => kolom, penyaring kesamaan.
     * @return Builder<*>
     */
    public static function saring(
        Builder $query,
        Request $request,
        array $cariDi = [],
        array $penyaring = [],
    ): Builder {
        $cari = trim((string) $request->query('cari', ''));

        if ($cari !== '' && $cariDi !== []) {
            $query->where(function (Builder $q) use ($cari, $cariDi) {
                foreach ($cariDi as $kolom) {
                    // LIKE '%...%' tidak bisa memakai index, dan itu disadari.
                    // Pada skala satu sekolah (ribuan baris, bukan jutaan),
                    // indeks penuh tidak sebanding dengan kesederhanaannya.
                    // Bila suatu saat daftarnya menjadi puluhan ribu, yang
                    // menggantikannya adalah full-text index, bukan penebakan.
                    $q->orWhere($kolom, 'like', '%'.$cari.'%');
                }
            });
        }

        foreach ($penyaring as $param => $kolom) {
            $nilai = $request->query($param);

            if ($nilai !== null && $nilai !== '') {
                $query->where($kolom, $nilai);
            }
        }

        return $query;
    }

    /**
     * @param  Builder<*>  $query
     * @param  array<int, string>  $cariDi  Kolom yang boleh dicari.
     * @param  array<int, string>  $bolehDiurut  Kolom yang boleh jadi kunci urutan.
     * @param  array<string, string>  $penyaring  Peta param => kolom, penyaring kesamaan.
     */
    public static function buat(
        Builder $query,
        Request $request,
        array $cariDi = [],
        array $bolehDiurut = ['id'],
        array $penyaring = [],
        string $urutBawaan = 'id',
        string $arahBawaan = 'asc',
    ): LengthAwarePaginator {
        $query = self::saring($query, $request, $cariDi, $penyaring);

        $urut = (string) $request->query('urut', $urutBawaan);
        $arah = strtolower((string) $request->query('arah', $arahBawaan)) === 'desc' ? 'desc' : 'asc';

        if (! in_array($urut, $bolehDiurut, true)) {
            $urut = $urutBawaan;
        }

        return $query
            ->orderBy($urut, $arah)
            // Urutan kedua yang stabil. Tanpa ini, dua baris dengan nilai
            // urut yang sama bisa bertukar tempat di antara dua halaman -
            // dan pada daftar bernomor halaman itu terlihat sebagai baris
            // yang hilang, atau muncul dua kali.
            ->orderBy('id', 'asc')
            ->paginate(self::PER_HALAMAN)
            ->withQueryString();
    }
}
