import { Head, Link, usePage } from '@inertiajs/react';
import { ReactNode } from 'react';
import { Chip, UmpanBalik } from '@/Components/ui';
import { TabbarPonsel } from '@/Components/ponsel';

/**
 * Kerangka ponsel Pengawas - wireframe-peran.html kelompok J.
 *
 * =====================================================================
 * Mengapa kerangka KEDUA, bukan PeranLayout yang dilebarkan
 * =====================================================================
 * PeranLayout adalah kartu tengah-layar: satu kotak, judul, keterangan.
 * Bentuk itu benar untuk Guru dan Siswa yang belum dibangun, dan salah
 * untuk Pengawas karena pengawas memegang ponselnya sambil berdiri -
 * yang dibutuhkan bukan kartu, melainkan bilah atas yang terus menyebut
 * ruang mana yang sedang dibuka, isi yang bisa digulir, dan tabbar yang
 * bisa dijangkau jempol.
 *
 * ---------------------------------------------------------------------
 * Kepala laman SELALU menyebut ruangnya - dan, bila diketahui, orangnya
 * ---------------------------------------------------------------------
 * Satu ponsel bisa dipakai dua orang bergantian di dua ruang, dan laman
 * yang tidak menyebut ruangnya membuat orang menandai presensi di ruang
 * yang salah tanpa sadar. Karena itu namanya datang dari prop bersama
 * `pengawasRuang` - bukan dari prop halaman, yang bisa lupa dikirim.
 *
 * Sejak 2026-11-07 kode pintu menempel pada ORANG, sehingga nama pengawasnya
 * diketahui dan ikut disebut. Yang dijaga keduanya adalah hal yang sama:
 * ponsel yang berpindah tangan tanpa penanda yang berpindah bersamanya.
 *
 * Pengawas berakun tidak punya satu ruang, sehingga prop itu null; baginya
 * kepalanya menyebut nama pengguna, dan ruangnya disebut oleh halaman yang
 * memang tentang satu ruang.
 *
 * ---------------------------------------------------------------------
 * Lebar: penuh di ponsel, 26rem di layar lebar
 * ---------------------------------------------------------------------
 * Wireframe menggambar `.wd` selebar 238px karena itu GAMBAR ponsel. Aplikasi
 * ini dirender di dalam ponsel sungguhan, jadi merender bingkai ponsel palsu
 * membuang layar yang justru paling sempit. Di layar lebar, `max-w-[26rem]`
 * menjaga barisnya tetap sepanjang yang nyaman dibaca dan tidak melebar jadi
 * tabel.
 *
 * ---------------------------------------------------------------------
 * Bilah bawah: keluar, dan hanya untuk pemegang tautan
 * ---------------------------------------------------------------------
 * "Keluar" di sini melepas HIBAT SESI, bukan logout akun. Ia hanya muncul
 * bagi pemegang tautan ruang: pengawas berakun keluar lewat akunnya
 * sendiri, dan menampilkan dua tombol keluar yang berbeda arti pada satu
 * layar adalah cara membuat orang keluar dari yang bukan maksudnya.
 */
type Props = {
    judul: string;
    kiri?: ReactNode;
    kanan?: ReactNode;
    /** Diisi bila laman ini milik satu ruang; memunculkan tabbar di bawah. */
    tabbar?: { ruangId: number; aktif: string };
    /** Bilah atas disembunyikan pada laman yang menggambar kepalanya sendiri. */
    bilah?: boolean;
    children: ReactNode;
    aksiBawah?: ReactNode;
};

export default function KerangkaPonsel({
    judul,
    kiri,
    kanan,
    tabbar,
    bilah = true,
    children,
    aksiBawah,
}: Props) {
    const { auth, pengawasRuang } = usePage().props as unknown as {
        auth: { pengguna: { nama: string; peran_label: string } | null };
        pengawasRuang: {
            ruang_id: number;
            ruang: string | null;
            ruang_nama: string | null;
            sesi: string | null;
            event: string | null;
            sekolah: string | null;
            slug: string;
            pengawas: string | null;
        } | null;
    };

    const lewatTautan = pengawasRuang !== null;

    return (
        <>
            <Head title={judul} />

            <div className="min-h-screen bg-paper">
                <div className="mx-auto flex min-h-screen w-full max-w-[26rem] flex-col border-line bg-paper sm:border-x">
                    {bilah && (
                        <header className="sticky top-0 z-20 flex items-center gap-2 border-b border-line bg-surface/95 px-3 py-2 backdrop-blur">
                            <div className="flex min-w-0 items-center gap-2">
                                {kiri ?? (
                                    <Chip nada={lewatTautan ? 'info' : 'mute'}>
                                        {pengawasRuang?.ruang ?? auth.pengguna?.peran_label ?? 'Pengawas'}
                                    </Chip>
                                )}
                                <span className="min-w-0 truncate text-[11px] text-ink-3">
                                    {pengawasRuang
                                        ? [
                                              // Nama pengawas yang membuka pintu ini,
                                              // sejak 2026-11-07. Chip di sebelah kiri
                                              // tetap menyebut KODE ruangnya, jadi
                                              // kepalanya menjawab dua pertanyaan
                                              // sekaligus: ruang mana, dan siapa.
                                              pengawasRuang.pengawas
                                                  ? `Pengawas: ${pengawasRuang.pengawas}`
                                                  : null,
                                              pengawasRuang.ruang_nama,
                                              pengawasRuang.sesi,
                                          ]
                                              .filter(Boolean)
                                              .join(' · ')
                                        : judul}
                                </span>
                            </div>

                            <div className="ml-auto flex shrink-0 items-center gap-2">
                                {kanan}
                                {lewatTautan && (
                                    <Link
                                        href={`/p/${pengawasRuang.slug}/keluar`}
                                        method="post"
                                        as="button"
                                        className="rounded-wf px-1.5 py-1 text-[11px] text-ink-3 hover:text-bad"
                                        title="Lepaskan sesi ruang ini dari ponsel"
                                    >
                                        Keluar
                                    </Link>
                                )}
                            </div>
                        </header>
                    )}

                    <main className="min-w-0 flex-1 space-y-3 px-3 py-3">
                        <UmpanBalik />
                        {children}
                    </main>

                    {aksiBawah && (
                        <div className="sticky bottom-0 z-20 border-t border-line bg-surface px-3 py-2">
                            {aksiBawah}
                        </div>
                    )}

                    {tabbar && <TabbarPonsel ruangId={tabbar.ruangId} aktif={tabbar.aktif} />}

                    {!tabbar && !lewatTautan && (
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
