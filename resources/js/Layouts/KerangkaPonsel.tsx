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
    /**
     * Pelat gelap (`.konsol-pengawas`). Sengaja per-laman, bukan per-peran:
     * beranda pengawas sudah di-redesign ke pelat gelap, laman-laman lainnya
     * menyusul satu per satu - prop inilah saklarnya.
     */
    gelap?: boolean;
    children: ReactNode;
    aksiBawah?: ReactNode;
};

export default function KerangkaPonsel({
    judul,
    kiri,
    kanan,
    tabbar,
    bilah = true,
    gelap = false,
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

            <div className={'min-h-screen bg-paper' + (gelap ? ' konsol-pengawas' : '')}>
                <div className="mx-auto flex min-h-screen w-full max-w-[26rem] flex-col border-line bg-paper sm:border-x">
                    {bilah && (
                        <header className="sticky top-0 z-20 flex items-center gap-2 border-b border-line bg-surface/95 px-3 py-2 backdrop-blur">
                            <div className="flex min-w-0 items-center gap-2">
                                {kiri ?? (
                                    <Chip nada={lewatTautan ? 'info' : 'mute'}>
                                        {pengawasRuang?.ruang ?? auth.pengguna?.peran_label ?? 'Pengawas'}
                                    </Chip>
                                )}
                                <span className="flex min-w-0 flex-1 items-center gap-1.5 truncate text-[11px] text-ink-3">
                                    {pengawasRuang ? (
                                        // Pemisahnya GARIS TEGAK kecil, bukan titik
                                        // tengah: gate 63 menyebut rangkaian titik
                                        // sebagai tanda baca halaman generator, dan
                                        // kepala ini bisa memuat tiga segmen. Nama
                                        // pengawas yang membuka pintu ini disebut
                                        // sejak 2026-11-07 - kodenya menempel pada
                                        // orang. Chip di kiri tetap menyebut KODE
                                        // ruangnya, jadi kepalanya menjawab dua
                                        // pertanyaan sekaligus: ruang mana, dan siapa.
                                        <>
                                            {pengawasRuang.pengawas && (
                                                <>
                                                    <span className="shrink-0">
                                                        {pengawasRuang.pengawas}
                                                    </span>
                                                    <i className="h-3 w-px shrink-0 bg-line" />
                                                </>
                                            )}
                                            <span className="min-w-0 truncate">
                                                {[pengawasRuang.ruang_nama, pengawasRuang.sesi]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>
                                        </>
                                    ) : (
                                        judul
                                    )}
                                </span>
                            </div>

                            <div className="ml-auto flex shrink-0 items-center gap-2">
                                {kanan}
                                {lewatTautan && (
                                    <Link
                                        href={`/p/${pengawasRuang.slug}/keluar`}
                                        method="post"
                                        as="button"
                                        // `min-h-11` = 44px sasaran, tetapi
                                        // `-my-2` menariknya kembali ke dalam
                                        // padding kepala (8px atas + 8px
                                        // bawah). Tanpa itu kepala yang
                                        // lengket tumbuh 34px -> 60px dan
                                        // memakan sepersepuluh layar ponsel
                                        // hanya untuk satu tautan teks.
                                        className="inline-grid min-h-11 -my-2 place-items-center rounded-wf px-2 text-[11px] text-ink-3 hover:text-bad"
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
                        // Melayang dengan alasan yang sama seperti tabbar di
                        // bawah - lihat catatannya.
                        // `pb-[max(0.75rem,env(safe-area-inset-bottom))]`:
                        // 12px biasa, atau tinggi bilah gestur ponsel bertepi
                        // lengkung - mana yang lebih besar. Tanpa itu tombol
                        // paling bawah duduk tepat di bawah garis geser dan
                        // tersentuh saat pengguna ingin pulang.
                        <div className="sticky bottom-0 z-20 px-3 pt-1 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                            <div className="space-y-1.5 overflow-hidden rounded-wf border border-line bg-surface/95 p-2 shadow-lg backdrop-blur">
                                {aksiBawah}
                            </div>
                        </div>
                    )}

                    {tabbar && (
                        // Tabbar MELAYANG, bukan menempel penuh di tepi bawah.
                        //
                        // Menempel penuh berarti bilah setinggi 44px duduk
                        // menutupi baris terakhir daftar kursi - dan baris
                        // terakhir itulah yang paling sering dibaca pengawas,
                        // karena satu-satunya kursi yang belum ditandai
                        // biasanya tersisa di sana. Jarak tepi membuat isi
                        // laman terbaca sampai habis sebelum bilahnya mulai.
                        //
                        // `pb-3` + `px-3` bukan kemewahan: tanpa jarak bawah,
                        // ponsel bertepi lengkung memotong sudut bilahnya.
                        <div className="sticky bottom-0 z-20 px-3 pt-1 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                            <div className="overflow-hidden rounded-wf border border-line bg-surface/95 shadow-lg backdrop-blur">
                                <TabbarPonsel ruangId={tabbar.ruangId} aktif={tabbar.aktif} />
                            </div>
                        </div>
                    )}

                    {!tabbar && !lewatTautan && (
                        <div className="sticky bottom-0 z-20 px-3 pt-1 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                            <div className="overflow-hidden rounded-wf border border-line bg-surface p-2 text-center shadow-lg">
                                <Link
                                    href="/logout"
                                    method="post"
                                    as="button"
                                    className="inline-grid min-h-11 w-full place-items-center text-[11px] text-ink-3 hover:text-bad"
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
