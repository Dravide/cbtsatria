import { ReactNode } from 'react';
import KonsolLayout, { ItemNav, KelompokNav } from './KonsolLayout';

/**
 * Kerangka konsol Guru - wireframe-peran.html plate 01-08.
 *
 * ---------------------------------------------------------------------
 * Dua kelompok, dan yang kedua lahir di Fase 8
 * ---------------------------------------------------------------------
 * Wireframe `:339` menyebut sidebar Guru "lebih sempit" dari Admin: yang
 * berhubungan dengan SOAL, dan yang berhubungan dengan NILAI. Kelompok
 * pertama berdiri penuh di Fase 4. Kelompok kedua dibangun di Fase 8.
 *
 * ---------------------------------------------------------------------
 * "Publikasi" DIHAPUS dari kelompok Nilai, dan itu bukan kelalaian
 * ---------------------------------------------------------------------
 * Sampai Fase 7 di sini ada item keempat bertanda Fase 8:
 * `/guru/publikasi`. Fase 8 membangunnya, dan menemukan bahwa laman itu
 * memang tidak seharusnya ada: plate 21 (`/admin/events/{event}/hasil`)
 * menegaskan publikasi adalah aksi Admin, dan `GradingPolicy::terbitkan()`
 * menolak Guru secara eksplisit.
 *
 * Item menu yang menunjuk 403 dari perannya sendiri adalah menu yang
 * mengajari Guru untuk tidak mempercayai sidebar. Karena itu itemnya
 * dibuang, bukan diarahkan ke laman Admin - Guru memang tidak punya urusan
 * di sana.
 *
 * ---------------------------------------------------------------------
 * "Perlu koreksi" di plate 01 kini bisa dihitung, tetapi belum dirender
 * ---------------------------------------------------------------------
 * Angka itu sekarang benar-benar ada. Yang menahannya adalah `KpiGuru` di
 * beranda: angka nol yang benar tetap terlihat sama dengan angka nol yang
 * berbohong, dan beranda belum diubah untuk membedakannya. Yang menjawab
 * "berapa yang perlu dikoreksi" hari ini adalah plate 06 sendiri, dengan
 * kotak "Sisa" di kepalanya.
 */

type Props = {
    judul: string;
    breadcrumb?: string;
    aksi?: ReactNode;
    children: ReactNode;
};

const BANK_SOAL: ItemNav[] = [
    // Bank soal berdiri di luar event - soal adalah milik Guru dan sekolah,
    // bukan milik satu ujian (wireframe plate 02). Karena itu tidak ada
    // pemilih event di bar atas konsol ini, dan tautan di bawah ini tidak
    // pernah memuat id event.
    { label: 'Bank Soal', href: '/guru/bank-soal', awalan: false, ikon: 'bank-soal' },
    { label: 'Paket Soal', href: '/guru/paket', awalan: true, ikon: 'paket' },
    // Impor berada DI DALAM bank soal (/guru/bank-soal/impor) supaya Guru
    // yang sedang menyaring soal tidak kehilangan tempatnya. Menu tersendiri
    // ke laman yang sama akan membuat dua tautan menyalakan satu laman.
    { label: 'Impor Soal', href: '/guru/bank-soal/impor', awalan: false, ikon: 'impor' },
];

const NILAI: ItemNav[] = [
    // Ketiganya `awalan: false` karena ketiganya punya laman DETAIL di bawah
    // dirinya (`/guru/koreksi/{ujian}`, `/guru/nilai/{ujian}`,
    // `/guru/analisis/{ujian}`), dan menu utama harus tetap menyala saat
    // Guru sedang berada di salah satunya.
    { label: 'Koreksi Uraian', href: '/guru/koreksi', awalan: true, ikon: 'koreksi' },
    { label: 'Nilai Kelas', href: '/guru/nilai', awalan: false, ikon: 'nilai' },
    { label: 'Analisis Butir Soal', href: '/guru/analisis', awalan: false, ikon: 'analisis' },
];

const AKUN: ItemNav[] = [
    { label: 'Ganti Kata Sandi', href: '/guru/sandi', awalan: false, ikon: 'kunci' },
];

export default function GuruLayout({ judul, breadcrumb, aksi, children }: Props) {
    const kelompok: KelompokNav[] = [
        { judul: 'Bank Soal', item: BANK_SOAL },
        { judul: 'Penilaian', item: NILAI },
        { judul: 'Akun Saya', item: AKUN },
    ];

    return (
        <KonsolLayout
            judul={judul}
            breadcrumb={breadcrumb}
            aksi={aksi}
            marka={{ inisial: 'G', nada: 'guru' }}
            kelompok={kelompok}
            kelasPembungkus="konsol-guru"
        >
            {children}
        </KonsolLayout>
    );
}
