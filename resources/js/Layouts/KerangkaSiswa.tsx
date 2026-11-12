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
 * Material konsol - `.konsol-siswa`, dan mengapa ia kelas dan bukan :root
 * =====================================================================
 * Konsol siswa memakai tema Terrazzo: kertas gading, lembar putih bergaris
 * tinta, Syne pada kepala dan Onest pada badan, mono hanya untuk waktu dan
 * kode, dan rust sebagai satu-satunya sinyal.
 *
 * Tokennya TIDAK boleh tinggal di `:root`: `app.css` dipakai lima peran -
 * Admin, Guru, Pengawas, Siswa dan pintu ujian - dan tema di `:root` akan
 * menata ulang empat peran yang tidak diminta berubah. Karena itu seluruhnya
 * hidup di bawah `.konsol-siswa`, dan kelas itu dipasang DI SINI, di rangka
 * terang saja.
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
 * Rangka gelap TIDAK memakai `.konsol-siswa`. Itu disengaja: mata yang sudah
 * menyesuaikan diri dengan layar gelap tidak boleh disambut huruf mono
 * bergaris 2px saat anak berpindah ke halaman ujian, dan sebaliknya. Dua
 * rangka itu memang dua tempat yang berbeda.
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

/**
 * Empat tab siswa. Keempatnya punya laman sejak laman Akun dibangun.
 *
 * Tab "Akun" sempat dirender sebagai teks abu bertanda fase selama lamanya
 * belum ada - niatnya supaya tabbar tidak tumbuh satu tab setiap fase dan
 * anak tidak perlu mempelajari ulang letak navigasinya. Penanda itu kini
 * basi: lamannya ada di `/siswa/akun`, jadi keempatnya tautan.
 */
const TAB = [
    { label: 'Ujian', segmen: 'ujian', href: '/siswa' },
    { label: 'Nilai', segmen: 'nilai', href: '/siswa/nilai' },
    { label: 'Pelanggaran', segmen: 'pelanggaran', href: '/siswa/pelanggaran' },
    { label: 'Akun', segmen: 'akun', href: '/siswa/akun' },
] as const;

export function TabbarSiswa({ aktif }: { aktif: string }) {
    return (
        <nav className="tabbar grid grid-cols-4">
            {TAB.map((t) => {
                const on = t.segmen === aktif;

                return (
                    <Link
                        key={t.segmen}
                        href={t.href}
                        aria-current={on ? 'page' : undefined}
                        className={on ? 'sel-hidup' : undefined}
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
    // Material papan hanya di rangka terang - lihat catatan berkas ini.
    const rangka = gelap ? 'rangka-ujian' : 'konsol-siswa bg-paper';

    return (
        <>
            <Head title={judul} />

            <div className={'min-h-screen ' + rangka}>
                <div
                    className={
                        'mx-auto flex min-h-screen w-full max-w-[26rem] flex-col border-line sm:border-x ' +
                        rangka
                    }
                >
                    {bilah && (
                        <header className="sticky top-0 z-20 flex items-center gap-2 border-b border-line bg-surface px-3 py-2">
                            <div className="flex min-w-0 items-center gap-2">
                                {/*
                                 * H1, dan ukurannya sengaja TIDAK naik.
                                 *
                                 * Sebelumnya baris ini `<span>`, dan seluruh
                                 * konsol menjadi empat laman tanpa satu pun
                                 * judul - pembaca layar mengumumkan isi tanpa
                                 * pernah menyebut laman mana yang sedang
                                 * dibuka, dan garis besar dokumennya kosong.
                                 *
                                 * Yang salah bukan ukurannya, melainkan
                                 * elemennya: ponsel 26rem dengan bilah 12px
                                 * adalah bentuk yang sudah dipilih, dan judul
                                 * raksasa di atas daftar jadwal akan
                                 * menggeser satu-satunya ujian yang penting
                                 * ke bawah lipatan. Jadi yang diganti
                                 * elemennya, dan kelas `bilah-judul`
                                 * mengembalikan huruf badannya.
                                 */}
                                <h1 className="bilah-judul min-w-0">
                                    {kiri ?? judul}
                                </h1>
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
                        // Bilah aksi MELAYANG, alasannya sama dengan tabbar di
                        // bawah: bilah yang menempel penuh di tepi bawah
                        // menutupi baris terakhir isi laman. Di pengerjaan,
                        // baris terakhir itu adalah kalimat terakhir soal -
                        // yang justru dibaca anak paling akhir sebelum menjawab.
                        <div className="sticky bottom-0 z-20 px-3 pb-3 pt-1">
                            <div className="space-y-1.5 overflow-hidden rounded-wf border border-line bg-surface p-2 shadow-lg">
                                {aksiBawah}
                            </div>
                        </div>
                    )}

                    {tabbarAktif && (
                        // Tabbar MELAYANG - alasannya sama dengan
                        // `KerangkaPonsel`: bilah yang menempel penuh menutupi
                        // baris terakhir daftar di ponsel layar pendek.
                        <div className="sticky bottom-0 z-20 px-3 pb-3 pt-1">
                            <div className="overflow-hidden rounded-wf border border-line bg-surface shadow-lg">
                                <TabbarSiswa aktif={tabbarAktif} />
                            </div>
                        </div>
                    )}

                    {/* Keluar dari akun hanya muncul di beranda siswa yang tabbarnya
                        ada. Di tengah ujian ia tidak muncul - lihat `tanpaKeluar`. */}
                    {!tabbarAktif && !tanpaKeluar && (
                        <div className="sticky bottom-0 z-20 px-3 pb-3 pt-1">
                            <div className="overflow-hidden rounded-wf border border-line bg-surface p-2 text-center shadow-lg">
                                <Link
                                    href="/logout"
                                    method="post"
                                    as="button"
                                    className="catatan font-medium hover:text-bad"
                                >
                                    Keluar dari akun
                                </Link>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
