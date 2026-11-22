import { Head, Link, usePage } from '@inertiajs/react';
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

function IkonTab({ segmen }: { segmen: (typeof TAB)[number]['segmen'] }) {
    switch (segmen) {
        case 'ujian':
            return (
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <rect x="4" y="4" width="16" height="16" rx="2" />
                    <path d="M9 9h6" />
                    <path d="M9 13h6" />
                    <path d="M9 17h3" />
                </svg>
            );
        case 'nilai':
            return (
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M3 3v18h18" />
                    <path d="M7 15l4-4 4 3 5-6" />
                </svg>
            );
        case 'pelanggaran':
            return (
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M12 3l8 4v5c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V7l8-4z" />
                    <path d="M12 9v4" />
                    <path d="M12 16h.01" />
                </svg>
            );
        case 'akun':
            return (
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <circle cx="12" cy="8" r="4" />
                    <path d="M4 20c0-3.8 3.6-6 8-6s8 2.2 8 6" />
                </svg>
            );
    }
}

/**
 * Tiga sel tabbar: tab kiri, tab Main di TENGAH, tab kanan.
 *
 * =====================================================================
 * Tab Main hilang HANYA saat anak itu sedang ujian
 * =====================================================================
 * Sejak 2026-11-15 server mengirim `luang` bernilai true untuk hampir semua
 * keadaan - siswa tanpa ujian, siswa di sela ujian, dan siswa yang baru
 * mengumpulkan ujian terakhirnya. Yang membuatnya false hanya satu: attempt
 * yang belum ditutup.
 *
 * Jumlah kolomnya karena itu 4 atau 5, dan tab lain bergeser setengah sel
 * saat ia muncul. Pergeseran itu disengaja: alternatifnya adalah menyediakan
 * tempat kosong permanen di tengah tabbar, dan tombol yang kadang-kadang
 * tidak melakukan apa pun lebih membingungkan daripada tab yang
 * kadang-kadang tidak ada.
 *
 * =====================================================================
 * Mengapa ikon, bukan label seperti tiga tab lain
 * =====================================================================
 * Ia bukan tujuan yang dicari anak - ia pengisi jeda. Label kelima di tabbar
 * 26rem memaksa tiga label lain memendek, dan seluruh tabbarnya menjadi
 * deretan kata yang berdesakan. Ikonnya menggambar bentuk yang sama dengan
 * pemain di panggungnya (kotak di atas garis tanah), supaya anak yang sudah
 * pernah membukanya mengenalinya tanpa membaca apa pun.
 */
function TabMain({ aktif = false }: { aktif?: boolean }) {
    return (
        <Link
            href="/siswa/luang"
            className={
                'flex min-h-[3rem] items-center justify-center transition-colors duration-150 ' +
                (aktif ? 'sel-hidup' : 'text-ink-3 hover:text-ink')
            }
            aria-current={aktif ? 'page' : undefined}
            aria-label="Waktu luang - main"
            title="Waktu luang"
        >
            <span
                className={
                    'relative grid size-7 place-items-center rounded-md border bg-surface ' +
                    (aktif ? 'border-accent text-accent' : 'border-line')
                }
            >
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path
                        d="M2 12.5h12"
                        stroke="currentColor"
                        strokeWidth="1.4"
                        strokeLinecap="round"
                    />
                    <rect x="3.2" y="7" width="3.4" height="5.5" rx="0.6" fill="currentColor" />
                    <rect
                        x="9.4"
                        y="5"
                        width="2.6"
                        height="7.5"
                        rx="0.6"
                        fill="currentColor"
                        opacity=".55"
                    />
                </svg>
                {/* Titik penanda: "ada jeda, dan pintunya terbuka". Dibaca
                    sekali sebagai sinyal, bukan sebagai label. */}
                <span className="absolute -top-1 -right-1 size-1.5 rounded-full bg-accent ring-1 ring-surface" />
            </span>
        </Link>
    );
}

export function TabbarSiswa({ aktif, luang = false }: { aktif: string; luang?: boolean }) {
    // Tiga tab pertama dan terakhir tetap; tab Main disisipkan di tengah
    // hanya bila ada jeda - lihat catatan `TabMain`.
    const kiri = TAB.slice(0, 2);
    const kanan = TAB.slice(2);

    const sel = (t: (typeof TAB)[number]) => {
        const on = t.segmen === aktif;

        return (
            <Link
                key={t.segmen}
                href={t.href}
                aria-current={on ? 'page' : undefined}
                aria-label={t.label}
                title={t.label}
                className={on ? 'sel-hidup' : undefined}
            >
                <IkonTab segmen={t.segmen} />
            </Link>
        );
    };

    return (
        <nav className={'tabbar grid ' + (luang ? 'grid-cols-5' : 'grid-cols-4')}>
            {kiri.map(sel)}
            {luang && <TabMain aktif={aktif === 'luang'} />}
            {kanan.map(sel)}
        </nav>
    );
}

type Props = {
    judul: string;
    kiri?: ReactNode;
    kanan?: ReactNode;
    /** Segmen tab yang sedang menyala; tidak diisi berarti tabbarnya disembunyikan. */
    tabbarAktif?: string;
    /**
     * Apakah tab "Main" dirender - dikirim server lewat prop `luang`.
     *
     * Bawaannya `false`, dan itu sisi yang aman: tab yang muncul padahal
     * anaknya sedang ujian membawa anak ke laman yang langsung mengalihkannya
     * kembali, sementara tab yang hilang pada saat yang salah hanya
     * menyembunyikan satu tombol.
     */
    luang?: boolean;
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
    luang = false,
    children,
    aksiBawah,
}: Props) {
    const { identitasAplikasi } = usePage().props as unknown as {
        identitasAplikasi?: { nama: string; logo: string | null };
    };
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
                        <header
                            className={
                                'sticky top-0 z-20 border-b border-line px-3 ' +
                                (!gelap && identitasAplikasi?.logo
                                    ? 'border-t-2 border-t-accent bg-surface/95 pb-2 pt-2 shadow-[0_1px_4px_rgba(16,22,32,0.05)] backdrop-blur-sm'
                                    : 'bg-surface py-2')
                            }
                        >
                            {!gelap && identitasAplikasi?.logo ? (
                                <div className="flex flex-col gap-2">
                                    <div className="flex justify-center border-b border-line/60 pb-2">
                                        <img
                                            src={identitasAplikasi.logo}
                                            alt={identitasAplikasi.nama}
                                            className="h-5 w-auto max-w-[144px] object-contain"
                                        />
                                    </div>
                                    <div className="flex items-center justify-between gap-2">
                                        <h1 className="bilah-judul min-w-0 flex-1 text-ink-2">
                                            {kiri ?? judul}
                                        </h1>
                                        {kanan && (
                                            <div className="ml-auto flex shrink-0 items-center gap-1.5">
                                                {kanan}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <div className="flex items-center gap-2">
                                    <div className="flex min-w-0 items-center gap-2">
                                        <h1 className="bilah-judul min-w-0">
                                            {kiri ?? judul}
                                        </h1>
                                    </div>
                                    {kanan && (
                                        <div className="ml-auto flex shrink-0 items-center gap-1.5">
                                            {kanan}
                                        </div>
                                    )}
                                </div>
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
                                <TabbarSiswa aktif={tabbarAktif} luang={luang} />
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
