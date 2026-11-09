/**
 * Ikon garis untuk sidebar dan tombol.
 *
 * ---------------------------------------------------------------------
 * Mengapa digambar sendiri, bukan pustaka ikon
 * ---------------------------------------------------------------------
 * Proyek ini memegang satu aturan: nol dependensi tambahan. Pustaka ikon
 * berukuran beberapa ratus kilobyte demi dua puluh lima gambar yang seluruhnya
 * bisa ditulis dalam satu berkas - dan yang paling sering dipakai dari pustaka
 * semacam itu justru ikon yang bentuknya paling sederhana.
 *
 * Bentuknya satu keluarga: kotak 24, garis 1,6, sudut membulat, tanpa isian.
 * Keseragaman itu yang membuat deretan menu terbaca sebagai satu set, bukan
 * sebagai dua puluh lima gambar dari tiga sumber berbeda.
 *
 * ---------------------------------------------------------------------
 * Ukuran dan warna
 * ---------------------------------------------------------------------
 * Ikon mewarisi `currentColor`, jadi ia mengikuti warna teks di sebelahnya -
 * menu yang menyala tidak perlu ikon kedua. Garisnya `non-scaling-stroke`
 * supaya tetap 1,6 piksel pada ukuran apa pun; ikon 16 yang digambar dengan
 * garis 1,6 tampak sama tebalnya dengan ikon 20.
 */
import type { SVGProps } from 'react';

export type NamaIkon =
    | 'dashboard'
    | 'sekolah'
    | 'kalender'
    | 'kelas'
    | 'buku'
    | 'ruang'
    | 'pengguna'
    | 'bendera'
    | 'ringkasan'
    | 'panitia'
    | 'peserta'
    | 'paket'
    | 'jam'
    | 'perisai'
    | 'kunci'
    | 'presensi'
    | 'denyut'
    | 'grafik'
    | 'laporan'
    | 'daftar'
    | 'pengaturan'
    | 'bank-soal'
    | 'impor'
    | 'koreksi'
    | 'nilai'
    | 'analisis'
    | 'keluar'
    | 'cari'
    | 'tambah'
    | 'panah';

const JALUR: Record<NamaIkon, string> = {
    // Empat kotak - papan ringkasan.
    dashboard: 'M4 4h6v6H4zM14 4h6v4h-6zM14 12h6v8h-6zM4 14h6v6H4z',
    // Bangunan bersekat - profil sekolah.
    sekolah: 'M4 20V9l8-5 8 5v11M9 20v-6h6v6M4 20h16',
    kalender: 'M5 5h14v15H5zM5 9h14M9 3v4M15 3v4',
    // Tiga lapis - daftar kelas yang bertingkat.
    kelas: 'M12 4l8 4-8 4-8-4zM4 12l8 4 8-4M4 16l8 4 8-4',
    buku: 'M5 4h6a3 3 0 013 3v13a2.5 2.5 0 00-2.5-2.5H5zM19 4h-4a3 3 0 00-3 3v13a2.5 2.5 0 012.5-2.5H19z',
    ruang: 'M6 20V5a1 1 0 011-1h7a1 1 0 011 1v15M15 12h3v8M4 20h16M11 12h.01',
    pengguna: 'M9 11a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM3 20a6 6 0 0112 0M17 11a3 3 0 100-6M17.5 14a5 5 0 013.5 4.8',
    bendera: 'M6 21V4M6 4h11l-2 4 2 4H6',
    ringkasan: 'M4 20V6M4 20h16M8 17V11M12 17V8M16 17v-4',
    panitia: 'M9 11a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM3 20a6 6 0 0112 0M15 13l2 2 4-4',
    peserta: 'M7 10a3 3 0 100-6 3 3 0 000 6zM2 19a5 5 0 0110 0M16 10a3 3 0 100-6M17 14a4.5 4.5 0 015 4.5',
    paket: 'M8 4h9l3 3v3H8zM8 10h12v10H8zM4 4h4v16H4z',
    jam: 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7v5l3 2',
    perisai: 'M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6z',
    kunci: 'M15 9a4 4 0 10-3.3 3.9L9 16H7v2H5v2H3v-3l6.1-6.1A4 4 0 0115 9zM16.5 7.5h.01',
    presensi: 'M4 5h16v14H4zM8 13l2.5 2.5L16 9',
    denyut: 'M3 12h4l2.5-6 4 12 2.5-6H21',
    grafik: 'M4 20V6M4 20h16M7 16l4-5 3 3 5-7',
    laporan: 'M6 3h8l4 4v14H6zM14 3v4h4M9 12h6M9 16h6',
    daftar: 'M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01',
    pengaturan: 'M4 7h11M18 7h2M4 17h2M9 17h11M4 12h6M13 12h7M15 4.5V9.5M6 14.5v5M11 9.5v5',
    'bank-soal': 'M12 3c4 0 7 1.3 7 3s-3 3-7 3-7-1.3-7-3 3-3 7-3zM5 6v12c0 1.7 3 3 7 3s7-1.3 7-3V6M5 12c0 1.7 3 3 7 3s7-1.3 7-3',
    impor: 'M12 3v12M8 11l4 4 4-4M4 19h16',
    koreksi: 'M4 20h4L19 9a2 2 0 00-3-3L5 17zM14 8l2 2',
    nilai: 'M6 3h9l4 4v14H6zM14 3v4h4M9 13l2 2 3-4',
    analisis: 'M4 20h16M7 20V9M12 20V5M17 20v-8',
    keluar: 'M15 4h4v16h-4M11 8l-4 4 4 4M7 12h8',
    cari: 'M11 18a7 7 0 100-14 7 7 0 000 14zM16 16l4 4',
    tambah: 'M12 5v14M5 12h14',
    panah: 'M9 6l6 6-6 6',
};

type Props = SVGProps<SVGSVGElement> & {
    nama: NamaIkon;
    /** Sisi kotak dalam piksel. Bawaan 18 - sejajar tinggi baris menu. */
    ukuran?: number;
};

export default function Ikon({ nama, ukuran = 18, className, ...sisa }: Props) {
    return (
        <svg
            width={ukuran}
            height={ukuran}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.6}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
            // `shrink-0`: tanpa ini, label menu yang panjang memeras ikonnya
            // sampai selebar garis rambut.
            className={'shrink-0 ' + (className ?? '')}
            {...sisa}
        >
            <path d={JALUR[nama]} vectorEffect="non-scaling-stroke" />
        </svg>
    );
}
