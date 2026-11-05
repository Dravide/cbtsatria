import { Head, Link } from '@inertiajs/react';
import { ReactNode } from 'react';
import { UmpanBalik } from '@/Components/ui';

/**
 * Kerangka ponsel Siswa - wireframe-peran.html kelompok K (plate 19-29).
 *
 * =====================================================================
 * Mengapa kerangka KETIGA, bukan KerangkaPonsel yang dipakai ulang
 * =====================================================================
 * `KerangkaPonsel` adalah kerangka PENGAWAS, dan dua hal membuatnya tidak
 * bisa dipinjam apa adanya:
 *
 *   1. Kepalanya membaca prop bersama `pengawasRuang`, lalu menampilkan
 *      kode ruang pemegang tautan. Siswa tidak pernah memegang tautan ruang,
 *      dan seorang pengawas yang membuka `/siswa` di ponselnya sendiri akan
 *      melihat R-01 di atas jadwal ujian anak. Yang mencegahnya di server
 *      adalah middleware `siswa`; yang mencegahnya di sini adalah kepala
 *      yang memang tidak punya urusan dengan ruang.
 *   2. Tabbarnya menunjuk ruang. Empat tab siswa menunjuk DIRINYA sendiri -
 *      ujian, nilai, pelanggaran, akun - dan tidak satu pun butuh `{ruang}`.
 *
 * Yang DIPINJAM adalah ukuran dan iramanya: lebar `26rem` di layar lebar,
 * bilah atas yang lengket, isi yang bisa digulir, tabbar yang dijangkau
 * jempol. Wireframe `:971` menyatakan alasannya - "supaya tidak ada dua
 * bentuk tampilan yang harus dipelajari anak yang sama".
 *
 * =====================================================================
 * Rangka GELAP untuk pengerjaan, dan itu keadaan halaman
 * =====================================================================
 * Plate 22: "Rangka ujian dibuat gelap dan berbeda dari rangka lain, supaya
 * siswa yang membuka tab lain lalu kembali langsung mengenali bahwa ia
 * sedang di dalam ujian."
 *
 * `gelap` karena itu sebuah PROP, bukan varian `dark:` Tailwind. Varian
 * `dark:` mengikuti setelan sistem ponsel - sehingga separuh peserta akan
 * melihat rangka terang dan separuhnya gelap untuk ujian yang sama, dan
 * justru pembeda yang diandalkan plate 22 itu hilang pada perangkat yang
 * memakai mode terang. Nilai tokennya ada di `app.css` (`.rangka-ujian`).
 *
 * ---------------------------------------------------------------------
 * Rangka gelap TIDAK menawarkan jalan keluar
 * ---------------------------------------------------------------------
 * `tanpaKeluar` dipakai laman pengerjaan, dan alasannya menyangkut anak:
 * tautan "Keluar" di tengah ujian adalah satu ketukan yang memindahkan anak
 * dari soal yang sedang ia baca - dan anak yang menekannya karena penasaran
 * akan kembali ke soal pertama.
 *
 * Tombol yang MEMANG ada di rangka gelap adalah bilah bawah `aksiBawah`
 * (Mundur / Ragu / Maju), karena itu navigasi di dalam ujian, bukan keluar
 * dari ujian.
 */

/** Empat tab siswa - dan yang keempat belum punya laman. */
const TAB = [
    { label: 'Ujian', segmen: 'ujian', href: '/siswa' },
    { label: 'Nilai', segmen: 'nilai', href: '/siswa/nilai' },
    { label: 'Pelanggaran', segmen: 'pelanggaran', href: '/siswa/pelanggaran' },
    // "Akun" tidak punya laman di Fase 7, dan ia TIDAK dihilangkan. Alasan
    // yang sama dengan item bertanda fase di sidebar Guru: tabbar yang
    // tumbuh satu tab setiap fase membuat anak mempelajari ulang letak
    // navigasinya, dan tab yang muncul sendiri tanpa penjelasan adalah tab
    // yang dicari orang dua kali. Ia karena itu dirender sebagai teks abu
    // bertanda fase - bukan sebagai tautan mati.
    { label: 'Akun', segmen: 'akun', href: null, fase: 'Fase 8' },
] as const;

export function TabbarSiswa({ aktif }: { aktif: string }) {
    return (
        <nav className="sticky bottom-0 z-20 grid grid-cols-4 border-t border-line bg-surface">
            {TAB.map((t) => {
                const on = t.segmen === aktif;

                if (t.href === null) {
                    return (
                        <span
                            key={t.segmen}
                            title={`Belum dibangun - ${t.fase}`}
                            className="border-t-2 border-transparent py-2.5 text-center text-[11px] text-ink-3/50"
                        >
                            {t.label}
                        </span>
                    );
                }

                return (
                    <Link
                        key={t.segmen}
                        href={t.href}
                        className={
                            'border-t-2 py-2.5 text-center text-[11px] font-medium transition-colors ' +
                            (on
                                ? 'border-accent text-accent'
                                : 'border-transparent text-ink-2 hover:text-ink')
                        }
                    >
                        {t.label}
                    </Link>
                );
            })}
        </nav>
    );
}

type Props = {
    judul: string;
    kiri?: ReactNode;
    kanan?: ReactNode;
    /** Segmen tab yang sedang menyala; tidak diisi berarti tabbarnya disembunyikan. */
    tabbarAktif?: string;
    /** Bilah atas disembunyikan pada laman yang menggambar kepalanya sendiri. */
    bilah?: boolean;
    /** Rangka ujian - lihat catatan berkas ini. */
    gelap?: boolean;
    /** Menyembunyikan tautan keluar; dipakai laman di tengah ujian. */
    tanpaKeluar?: boolean;
    children: ReactNode;
    aksiBawah?: ReactNode;
};

export default function KerangkaSiswa({
    judul,
    kiri,
    kanan,
    tabbarAktif,
    bilah = true,
    gelap = false,
    tanpaKeluar = false,
    children,
    aksiBawah,
}: Props) {
    return (
        <>
            <Head title={judul} />

            <div className={'min-h-screen ' + (gelap ? 'rangka-ujian' : 'bg-paper')}>
                <div
                    className={
                        'mx-auto flex min-h-screen w-full max-w-[26rem] flex-col border-line sm:border-x ' +
                        (gelap ? 'rangka-ujian' : 'bg-paper')
                    }
                >
                    {bilah && (
                        <header className="sticky top-0 z-20 flex items-center gap-2 border-b border-line bg-surface/95 px-3 py-2 backdrop-blur">
                            <div className="flex min-w-0 items-center gap-2">
                                {kiri ?? (
                                    <span className="truncate text-[12px] font-medium text-ink">
                                        {judul}
                                    </span>
                                )}
                            </div>

                            {kanan && (
                                <div className="ml-auto flex shrink-0 items-center gap-2">{kanan}</div>
                            )}
                        </header>
                    )}

                    <main className="min-w-0 flex-1 space-y-3 px-3 py-3">
                        <UmpanBalik />
                        {children}
                    </main>

                    {aksiBawah && (
                        <div className="sticky bottom-0 z-20 space-y-1.5 border-t border-line bg-surface px-3 py-2">
                            {aksiBawah}
                        </div>
                    )}

                    {tabbarAktif && <TabbarSiswa aktif={tabbarAktif} />}

                    {/* Keluar dari akun hanya muncul di beranda siswa yang tabbarnya
                        ada. Di tengah ujian ia tidak muncul - lihat `tanpaKeluar`. */}
                    {!tabbarAktif && !tanpaKeluar && (
                        <div className="border-t border-line bg-surface px-3 py-2 text-center">
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                className="text-[11px] text-ink-3 hover:text-bad"
                            >
                                Keluar dari akun
                            </Link>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
