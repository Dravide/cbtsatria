import { Link, router, usePage } from '@inertiajs/react';
import { ReactNode, useLayoutEffect, useRef } from 'react';
import Ikon, { NamaIkon } from '@/Components/Ikon';
import NotifikasiEventBel from '@/Components/NotifikasiEventBel';
import { PilihCari, UmpanBalik } from '@/Components/ui';
import { MEREK } from '@/merek';

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
    /**
     * Ikon menu. Opsional supaya berkas ini tidak menuntut setiap pemanggil
     * ikut berubah sekaligus; item tanpa ikon dirender dengan indentasi yang
     * sama supaya labelnya tetap lurus satu kolom.
     */
    ikon?: NamaIkon;
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
    tampilkanPemilihEvent?: boolean;
    /** Warnai bilah header mengikuti warna tema dari /admin/sekolah/identitas. */
    headerTema?: boolean;
    kelasPembungkus?: string;
    children: ReactNode;
};

/** Hitung luminansi relatif WCAG agar teks di atas warna tema tetap lolos kontras AA. */
function apakahWarnaGelap(hex: string): boolean {
    const bersih = hex.replace('#', '');

    if (!/^[0-9a-f]{6}$/i.test(bersih)) {
        return true;
    }

    const kanal = [0, 2, 4].map((i) => {
        const c = parseInt(bersih.slice(i, i + 2), 16) / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });

    const luminansi = 0.2126 * kanal[0] + 0.7152 * kanal[1] + 0.0722 * kanal[2];

    return luminansi < 0.22;
}

const NADA_MARKA: Record<'admin' | 'guru', string> = {
    admin: 'bg-accent text-on-accent',
    guru: 'bg-ok text-on-accent',
};

const KELAS_ITEM = (menyala: boolean) =>
    'flex items-center gap-2 rounded-wf px-2.5 py-1.5 text-sm whitespace-nowrap md:whitespace-normal ' +
    (menyala
        ? 'bg-accent-soft font-medium text-accent'
        : 'text-ink-2 hover:bg-surface-2 hover:text-ink');

export default function KonsolLayout({
    judul,
    breadcrumb,
    aksi,
    marka,
    kelompok,
    tampilkanPemilihEvent = false,
    headerTema = false,
    kelasPembungkus,
    children,
}: Props) {
    const { auth, tahunAktif, eventAktif, daftarEvent, identitasAplikasi } = usePage().props as unknown as {
        auth: { pengguna: { nama: string; peran_label: string } | null };
        tahunAktif: string | null;
        eventAktif: { id: number; kode: string; nama: string; status_label: string } | null;
        daftarEvent?: { id: number; kode: string; nama: string; status_label: string }[];
        identitasAplikasi?: { nama: string; logo: string | null; warna?: string };
    };

    const warnaHeader = headerTema ? identitasAplikasi?.warna || '#1e4fa3' : null;
    const nadaHeader: 'bawaan' | 'gelap' | 'terang' = warnaHeader
        ? apakahWarnaGelap(warnaHeader)
            ? 'gelap'
            : 'terang'
        : 'bawaan';

    const jalur = typeof window !== 'undefined' ? window.location.pathname : '';
    const navRef = useRef<HTMLElement | null>(null);
    const kunciGulir = `konsol-sidebar-scroll:${marka.nada}`;

    useLayoutEffect(() => {
        const el = navRef.current;
        if (!el) {
            return;
        }
        try {
            const tersimpan = window.sessionStorage.getItem(kunciGulir);
            if (tersimpan !== null) {
                el.scrollTop = Number(tersimpan) || 0;
            }
        } catch {
            // Abaikan bila sessionStorage tidak tersedia.
        }
        const aktif = el.querySelector<HTMLElement>('[aria-current="page"]');
        if (aktif) {
            const rNav = el.getBoundingClientRect();
            const rAktif = aktif.getBoundingClientRect();
            if (rAktif.top < rNav.top || rAktif.bottom > rNav.bottom) {
                aktif.scrollIntoView({ block: 'nearest' });
            }
        }
    }, [jalur, kunciGulir]);

    const menyala = (item: ItemNav) =>
        jalur === item.href || (item.awalan === true && jalur.startsWith(item.href + '/'));

    return (
        <div className={`flex min-h-screen bg-paper${kelasPembungkus ? ` ${kelasPembungkus}` : ''}`}>
            {/* Rails ikon + label. Di layar sempit ia menyusut jadi bar atas
                mendatar supaya isi laman tetap punya lebar penuh. */}
            <aside className="sticky top-0 flex h-auto w-full shrink-0 flex-col border-b border-line bg-surface md:h-screen md:w-60 md:border-r md:border-b-0">
                <div className="flex items-center gap-2 px-4 py-4">
                    {identitasAplikasi?.logo ? (
                        <>
                            <img
                                src={identitasAplikasi.logo}
                                alt={identitasAplikasi.nama || MEREK}
                                className="h-5 w-auto max-w-[136px] object-contain"
                            />
                            <span className="shrink-0 rounded border border-accent/30 bg-accent-soft px-1.5 py-0.5 font-mono text-[10px] font-semibold tracking-wider text-accent uppercase">
                                BETA
                            </span>
                        </>
                    ) : (
                        <>
                            <span
                                className={
                                    'grid size-7 shrink-0 place-items-center rounded-wf text-xs font-semibold ' +
                                    NADA_MARKA[marka.nada]
                                }
                            >
                                {marka.inisial}
                            </span>
                            <span className="truncate font-display text-sm font-semibold text-ink">
                                {identitasAplikasi?.nama || MEREK}
                            </span>
                        </>
                    )}
                </div>

                {/* `overflow-y-auto`: sidebar event Admin punya dua puluh item,
                    dan di layar 720 tinggi daftar itu lebih panjang daripada
                    jendelanya. Tanpa gulir di sini, "Sistem" tidak akan
                    pernah bisa dijangkau. */}
                <nav
                    ref={navRef}
                    onScroll={(e) => {
                        try {
                            window.sessionStorage.setItem(
                                kunciGulir,
                                String(e.currentTarget.scrollTop),
                            );
                        } catch {
                            // Abaikan.
                        }
                    }}
                    className="scrollbar-tipis flex flex-row gap-0.5 overflow-x-auto px-3 pb-3 md:min-h-0 md:flex-1 md:flex-col md:overflow-x-visible md:overflow-y-auto md:pb-3"
                >
                    {kelompok.map((k, i) => (
                        <div key={k.judul ?? `kelompok-${i}`} className="contents">
                            {k.judul && (
                                <p
                                    className={
                                        'hidden px-2 pb-1 text-[11px] font-medium text-ink-3 md:block ' +
                                        (i === 0 ? 'pt-1' : 'pt-4')
                                    }
                                >
                                    {k.judul}
                                </p>
                            )}

                            {k.item.map((item) => {
                                if (item.fase) {
                                    return (
                                        <span
                                            key={item.label}
                                            className="flex items-center gap-2 rounded-wf px-2.5 py-1.5 text-sm whitespace-nowrap text-ink-3/70 md:whitespace-normal"
                                            title={`Belum dibangun - ${item.fase}`}
                                        >
                                            {item.ikon ? (
                                                <Ikon nama={item.ikon} ukuran={16} className="opacity-60" />
                                            ) : (
                                                <span className="w-4 shrink-0" aria-hidden="true" />
                                            )}
                                            {item.label}
                                            <span className="ml-auto font-mono text-[9px] text-ink-3/60">
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
                                        aria-current={menyala(item) ? 'page' : undefined}
                                    >
                                        {item.ikon ? (
                                            <Ikon nama={item.ikon} ukuran={16} />
                                        ) : (
                                            <span className="w-4 shrink-0" aria-hidden="true" />
                                        )}
                                        {item.label}
                                    </Link>
                                );
                            })}
                        </div>
                    ))}
                </nav>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                {(usePage().props as unknown as {
                    impersonasi?: {
                        sekolah: string;
                        admin_username: string;
                        alasan: string;
                        sisa_menit: number;
                    } | null;
                }).impersonasi && (() => {
                    const imp = (usePage().props as unknown as {
                        impersonasi: {
                            sekolah: string;
                            admin_username: string;
                            alasan: string;
                            sisa_menit: number;
                        };
                    }).impersonasi;
                    return (
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-warn/40 bg-warn-bg px-4 py-2 text-xs text-warn">
                            <span>
                                <strong>MODE DUKUNG AKTIF:</strong> Masuk sebagai{' '}
                                <code className="font-mono">{imp.admin_username}</code> ({imp.sekolah}) ·{' '}
                                <em>{imp.alasan}</em> · sisa {imp.sisa_menit} mnt
                            </span>
                            <button
                                type="button"
                                onClick={() => router.post('/super/impersonasi/keluar')}
                                className="rounded-wf border border-warn/40 bg-surface px-2.5 py-1 text-[11px] font-semibold text-warn hover:bg-surface-2"
                            >
                                Keluar ke Super Admin
                            </button>
                        </div>
                    );
                })()}

                {((usePage().props as unknown as {
                    pengumumanPlatform?: { id: number; judul: string; isi: string; nada: string }[];
                }).pengumumanPlatform ?? []).map((p) => (
                    <div
                        key={p.id}
                        className={
                            'border-b px-4 py-2 text-xs ' +
                            (p.nada === 'penting'
                                ? 'border-warn/30 bg-warn-bg text-warn'
                                : 'border-info/25 bg-info-bg text-info')
                        }
                    >
                        <strong>{p.judul}:</strong> {p.isi}
                    </div>
                ))}

                {/* Bar atas - anatomi butir 3 dan 4.
                    Menempel di atas agar pemilih event dan penanda tahun ajaran
                    tidak pernah hilang di tengah tabel panjang. Di konsol Admin,
                    latar mengikuti warna tema dari /admin/sekolah/identitas. */}
                <header
                    style={warnaHeader ? { backgroundColor: warnaHeader } : undefined}
                    className={
                        'sticky top-0 z-20 flex items-center gap-3 border-b px-4 py-2.5 ' +
                        (nadaHeader === 'gelap'
                            ? 'border-black/15 text-white shadow-xs'
                            : nadaHeader === 'terang'
                              ? 'border-black/10 text-ink shadow-xs'
                              : 'border-line bg-surface/95 backdrop-blur')
                    }
                >
                    <span
                        className={
                            'min-w-0 truncate text-xs ' +
                            (nadaHeader === 'gelap'
                                ? 'font-medium text-white/90'
                                : nadaHeader === 'terang'
                                  ? 'font-medium text-ink/85'
                                  : 'text-ink-3')
                        }
                    >
                        {breadcrumb ?? judul}
                    </span>

                    <div className="ml-auto flex items-center gap-2">
                        {/* Pemilih event - anatomi butir 2, "penangkal
                            kesalahan paling mahal di sistem ini". Semua kontrol
                            di bar atas memakai tinggi seragam h-9 (36px). */}
                        {tampilkanPemilihEvent && eventAktif && (
                            <NotifikasiEventBel
                                eventId={eventAktif.id}
                                eventNama={eventAktif.nama}
                                nadaHeader={nadaHeader}
                            />
                        )}

                        {tampilkanPemilihEvent && (
                            <div className="w-44 sm:w-64">
                                <PilihCari
                                    label=""
                                    nilai={eventAktif?.id ?? null}
                                    pilihan={(daftarEvent ?? []).map((ev) => ({
                                        id: ev.id,
                                        label: ev.nama,
                                        ket: `${ev.kode} · ${ev.status_label}`,
                                    }))}
                                    padaPilih={(id) => {
                                        if (id !== null && id !== eventAktif?.id) {
                                            router.visit(`/admin/events/${id}`);
                                        }
                                    }}
                                    placeholder="Pilih event…"
                                    kosong="Belum ada event."
                                    kelasTombol={
                                        'flex h-9 w-full cursor-pointer items-center gap-2 rounded-wf border px-3 text-left text-xs font-medium outline-none transition-colors duration-150 ' +
                                        (nadaHeader === 'gelap'
                                            ? 'border-white/25 bg-white/10 text-white hover:border-white/40 hover:bg-white/20 focus-visible:outline-white'
                                            : nadaHeader === 'terang'
                                              ? 'border-black/15 bg-black/5 text-ink hover:border-black/25 hover:bg-black/10 focus-visible:outline-ink'
                                              : 'border-line bg-surface text-ink hover:border-ink-3/50 focus:border-accent focus:ring-2 focus:ring-accent/15')
                                    }
                                    kelasIkon={
                                        nadaHeader === 'gelap'
                                            ? 'text-white/80'
                                            : nadaHeader === 'terang'
                                              ? 'text-ink/70'
                                              : 'text-ink-3'
                                    }
                                />
                            </div>
                        )}

                        {/* Tahun ajaran aktif - wireframe plate 07 menaruhnya
                            di bar atas, bukan di dalam form, karena berganti
                            TA berarti berganti daftar, bukan menyaring. */}
                        <span
                            className={
                                'hidden h-9 items-center rounded-wf border px-3 font-mono text-xs whitespace-nowrap sm:inline-flex ' +
                                (nadaHeader === 'gelap'
                                    ? 'border-white/25 bg-white/10 text-white'
                                    : nadaHeader === 'terang'
                                      ? 'border-black/15 bg-black/5 text-ink'
                                      : 'border-line bg-surface text-ink-2')
                            }
                        >
                            {tahunAktif ? `TA ${tahunAktif}` : 'TA belum diatur'}
                        </span>
                        <span
                            className={
                                'inline-flex h-9 items-center rounded-wf border px-3 text-xs font-medium whitespace-nowrap ' +
                                (nadaHeader === 'gelap'
                                    ? 'border-white/25 bg-white/10 text-white'
                                    : nadaHeader === 'terang'
                                      ? 'border-black/15 bg-black/5 text-ink'
                                      : 'border-line bg-surface text-ink-2')
                            }
                        >
                            {auth.pengguna?.peran_label ?? marka.inisial}
                        </span>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            aria-label="Keluar"
                            title="Keluar"
                            className={
                                'inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-wf border text-xs font-medium whitespace-nowrap transition-colors sm:w-auto sm:px-3 ' +
                                (nadaHeader === 'gelap'
                                    ? 'border-white/25 bg-white/10 text-white hover:border-white/40 hover:bg-white/20 focus-visible:outline-white'
                                    : nadaHeader === 'terang'
                                      ? 'border-black/15 bg-black/5 text-ink hover:border-bad/40 hover:bg-bad-bg hover:text-bad focus-visible:outline-ink'
                                      : 'border-line bg-surface text-ink-2 hover:border-bad/30 hover:bg-bad-bg hover:text-bad')
                            }
                        >
                            <Ikon nama="keluar" ukuran={14} />
                            <span className="hidden sm:inline">Keluar</span>
                        </Link>
                    </div>
                </header>

                <main className="min-w-0 flex-1 px-4 py-5 md:px-6 md:py-6">
                    <div className="mb-4 flex flex-wrap items-center gap-3">
                        <h1 className="font-display text-xl font-semibold text-ink">
                            {judul}
                        </h1>
                        {aksi && <div className="ml-auto flex gap-2">{aksi}</div>}
                    </div>

                    <UmpanBalik />

                    {children}
                </main>

                <footer className="border-t border-line bg-surface/70 px-4 py-3 text-[11px] text-ink-3 md:px-6">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span>
                            &copy; {new Date().getFullYear()}{' '}
                            <span className="font-medium text-ink-2">
                                {identitasAplikasi?.nama || MEREK}
                            </span>{' '}
                            · Sistem Ujian Berbasis Komputer
                        </span>
                        <span className="font-mono">
                            {auth.pengguna ? `${auth.pengguna.nama} (${auth.pengguna.peran_label})` : ''}
                            {tahunAktif ? ` · TA ${tahunAktif}` : ''}
                        </span>
                    </div>
                </footer>
            </div>
        </div>
    );
}
