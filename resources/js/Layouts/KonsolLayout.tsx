import { Link, usePage } from '@inertiajs/react';
import { ReactNode } from 'react';
import { UmpanBalik } from '@/Components/ui';

/**
 * Badan konsol bersidebar - dipakai Admin dan Guru.
 *
 * ---------------------------------------------------------------------
 * Mengapa dipisahkan, dan mengapa baru sekarang
 * ---------------------------------------------------------------------
 * Sampai Fase 3 hanya Admin punya konsol, dan layoutnya berdiri sendiri
 * karena memang hanya satu. Fase 4 menambah konsol kedua, dan pilihannya
 * adalah menyalin 200 baris atau memindahkannya ke satu tempat. Menyalin
 * berarti dua sidebar yang akan berbeda dalam tiga fase, dan yang berbeda
 * bukan gayanya melainkan letak menunya - guru yang pernah memakai konsol
 * Admin akan mencari "mata pelajaran" di tempat yang sama, dan tidak akan
 * menemukannya.
 *
 * Yang BERBEDA antara keduanya hanya daftar menunya. Seluruh anatomi lain -
 * bar atas, pemilih event, penanda tahun ajaran, umpan balik - sama, dan
 * karena itu tinggal di sini.
 *
 * ---------------------------------------------------------------------
 * Yang belum dibangun diberi penanda fase, bukan disembunyikan
 * ---------------------------------------------------------------------
 * Item bertanda `fase` TIDAK dirender sebagai tautan, melainkan sebagai teks
 * abu. Tautan mati yang bisa diklik lebih buruk daripada tidak ada sama
 * sekali - ia menjanjikan sesuatu lalu memberi halaman galat. Tetapi
 * menyembunyikannya juga salah: daftar yang tumbuh satu item setiap fase
 * berarti pemakainya mempelajari ulang letak menunya empat kali.
 *
 * ---------------------------------------------------------------------
 * `awalan` menentukan satu item atau beberapa
 * ---------------------------------------------------------------------
 * "/admin/ruang" harus tetap menyala saat Admin membuka detail satu ruang
 * ("/admin/ruang/R-01"), karena rincian adalah isi dari menu itu. Tetapi
 * "/admin/events/12" dan "/admin/events/12/jadwal" adalah dua menu yang
 * berbeda, dan yang pertama tidak boleh menyala saat yang kedua dibuka.
 * Perbedaan itu dinyatakan per item lewat `awalan`, bukan ditebak.
 */

export type ItemNav = {
    label: string;
    href: string;
    /** Menyala juga untuk jalur di bawahnya. Lihat catatan di atas. */
    awalan?: boolean;
    /** Penanda fase untuk item yang belum dibangun. */
    fase?: string;
};

export type KelompokNav = {
    /** Judul kelompok; kelompok pertama tanpa judul dirender apa adanya. */
    judul?: string;
    /** Render kartu "Event aktif" sebelum itemnya (anatomi butir 1). */
    kartuEvent?: boolean;
    item: ItemNav[];
};

type Props = {
    judul: string;
    breadcrumb?: string;
    aksi?: ReactNode;
    /** Kotak penanda di kiri atas - "S" untuk Admin, "G" untuk Guru. */
    marka: { inisial: string; nada: 'admin' | 'guru' };
    kelompok: KelompokNav[];
    children: ReactNode;
};

const NADA_MARKA: Record<'admin' | 'guru', string> = {
    admin: 'bg-accent text-on-accent',
    guru: 'bg-ok text-on-accent',
};

const KELAS_ITEM = (menyala: boolean) =>
    'rounded-wf px-2.5 py-1.5 text-sm whitespace-nowrap md:whitespace-normal ' +
    (menyala ? 'bg-accent-soft font-medium text-accent' : 'text-ink-2 hover:bg-surface-2');

export default function KonsolLayout({
    judul,
    breadcrumb,
    aksi,
    marka,
    kelompok,
    children,
}: Props) {
    const { auth, tahunAktif, eventAktif } = usePage().props as unknown as {
        auth: { pengguna: { nama: string; peran_label: string } | null };
        tahunAktif: string | null;
        eventAktif: { id: number; kode: string; nama: string; status_label: string } | null;
    };

    const jalur = typeof window !== 'undefined' ? window.location.pathname : '';

    // Pemilih event di bar atas hanya punya arti di konsol yang sidebar-nya
    // BERBICARA TENTANG satu event, dan itulah yang dinyatakan `kartuEvent`.
    // Konsol Guru tidak pernah menyatakannya - bank soal berdiri di luar
    // event - sehingga pemilihnya tidak pernah muncul, bukan muncul karena
    // sesi kebetulan menyimpan event terakhir yang dibuka Admin.
    const bicaraSoalEvent = kelompok.some((k) => k.kartuEvent === true);

    const menyala = (item: ItemNav) =>
        jalur === item.href || (item.awalan === true && jalur.startsWith(item.href + '/'));

    return (
        <div className="flex min-h-screen bg-paper">
            {/* Rail ikon + label. Di layar sempit ia menyusut jadi bar atas
                mendatar supaya isi laman tetap punya lebar penuh. */}
            <aside className="flex w-full shrink-0 flex-col border-b border-line bg-surface md:w-60 md:border-r md:border-b-0">
                <div className="flex items-center gap-2.5 px-4 py-4">
                    <span
                        className={
                            'grid size-7 place-items-center rounded-wf text-xs font-semibold ' +
                            NADA_MARKA[marka.nada]
                        }
                    >
                        {marka.inisial}
                    </span>
                    <span className="font-display text-sm font-semibold text-ink">
                        School CBT
                    </span>
                </div>

                <nav className="flex flex-row gap-1 overflow-x-auto px-3 pb-3 md:flex-col md:overflow-visible md:pb-0">
                    {kelompok.map((k, i) => (
                        <div key={k.judul ?? `kelompok-${i}`} className="contents">
                            {k.judul && (
                                <p
                                    className={
                                        'hidden px-2 pb-1.5 text-[11px] font-medium tracking-wide text-ink-3 uppercase md:block ' +
                                        (i === 0 ? 'pt-1' : 'pt-3')
                                    }
                                >
                                    {k.judul}
                                </p>
                            )}

                            {/* Kartu "Event aktif" - hanya saat satu event
                                dibuka. Saat tidak ada, kelompok ini tidak
                                dirender sama sekali oleh pemanggilnya, bukan
                                dirender kosong. */}
                            {k.kartuEvent && eventAktif && (
                                <div className="hidden px-2 pt-3 md:block">
                                    <div className="rounded-wf border border-accent/25 bg-accent-soft px-2.5 py-2">
                                        <p className="text-[10px] font-medium tracking-wide text-accent uppercase">
                                            Event aktif
                                        </p>
                                        <p className="mt-0.5 truncate text-xs font-medium text-ink">
                                            {eventAktif.nama}
                                        </p>
                                        <p className="font-mono text-[10px] text-ink-3">
                                            {eventAktif.kode} &middot; {eventAktif.status_label}
                                        </p>
                                    </div>
                                </div>
                            )}

                            {k.item.map((item) => {
                                if (item.fase) {
                                    return (
                                        <span
                                            key={item.label}
                                            className="flex items-center gap-1.5 rounded-wf px-2.5 py-1.5 text-sm whitespace-nowrap text-ink-3/70 md:whitespace-normal"
                                            title={`Belum dibangun - ${item.fase}`}
                                        >
                                            {item.label}
                                            <span className="font-mono text-[9px] text-ink-3/60">
                                                {item.fase}
                                            </span>
                                        </span>
                                    );
                                }

                                return (
                                    <Link
                                        key={item.label}
                                        href={item.href}
                                        className={KELAS_ITEM(menyala(item))}
                                    >
                                        {item.label}
                                    </Link>
                                );
                            })}
                        </div>
                    ))}
                </nav>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                {/* Bar atas - anatomi butir 3 dan 4 */}
                <header className="flex items-center gap-3 border-b border-line bg-surface px-4 py-3">
                    <span className="min-w-0 truncate text-xs text-ink-3">
                        {breadcrumb ?? judul}
                    </span>

                    <div className="ml-auto flex items-center gap-2">
                        {/* Pemilih event - anatomi butir 2, "penangkal
                            kesalahan paling mahal di sistem ini". Ia muncul
                            di SETIAP laman event dan diberi warna aksen saat
                            aktif, supaya Admin yang sedang menetapkan peserta
                            melihat event mana yang sedang ia isi tanpa harus
                            menengok sidebar.

                            Hanya di konsol yang bicara soal event; lihat
                            `bicaraSoalEvent` di atas. */}
                        {bicaraSoalEvent && eventAktif && (
                            <Link
                                href={`/admin/events/${eventAktif.id}`}
                                className="hidden max-w-[16rem] items-center gap-1.5 rounded-wf border border-accent/40 bg-accent-soft px-2.5 py-1 text-xs text-accent sm:flex"
                                title={`Event aktif: ${eventAktif.nama}`}
                            >
                                <span className="truncate">{eventAktif.nama}</span>
                                <span className="shrink-0 opacity-60">&#9662;</span>
                            </Link>
                        )}

                        {/* Tahun ajaran aktif - wireframe plate 07 menaruhnya
                            di bar atas, bukan di dalam form, karena berganti
                            TA berarti berganti daftar, bukan menyaring. */}
                        <span className="hidden rounded-wf border border-line px-2.5 py-1 font-mono text-xs text-ink-2 sm:inline">
                            {tahunAktif ? `TA ${tahunAktif}` : 'TA belum diatur'}
                        </span>
                        <span className="rounded-wf border border-line px-2.5 py-1 text-xs text-ink-2">
                            {auth.pengguna?.peran_label ?? marka.inisial} &#9662;
                        </span>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="rounded-wf px-2.5 py-1 text-xs text-ink-3 hover:text-bad"
                        >
                            Keluar
                        </Link>
                    </div>
                </header>

                <main className="min-w-0 flex-1 p-4 md:p-6">
                    <div className="mb-5 flex flex-wrap items-center gap-3">
                        <h1 className="font-display text-xl font-semibold text-ink">
                            {judul}
                        </h1>
                        {aksi && <div className="ml-auto flex gap-2">{aksi}</div>}
                    </div>

                    <UmpanBalik />

                    {children}
                </main>
            </div>
        </div>
    );
}
