import { Head, Link, usePage } from '@inertiajs/react';
import { ReactNode, useState } from 'react';
import Ikon, { NamaIkon } from '@/Components/Ikon';
import { UmpanBalik } from '@/Components/ui';
import { MEREK } from '@/merek';

type ItemMenu = {
    label: string;
    href: string;
    ikon: NamaIkon;
    eksak?: boolean;
};

type KelompokMenu = {
    judul: string;
    item: ItemMenu[];
};

const KELOMPOK: KelompokMenu[] = [
    {
        judul: 'Utama',
        item: [
            { label: 'Command Center', href: '/super', ikon: 'dashboard', eksak: true },
            { label: 'Sekolah', href: '/super/sekolah', ikon: 'sekolah' },
        ],
    },
    {
        judul: 'Operasional',
        item: [
            { label: 'Kesehatan Sistem', href: '/super/sistem', ikon: 'denyut' },
            { label: 'Log Galat', href: '/super/galat', ikon: 'perisai' },
            { label: 'Pengumuman Global', href: '/super/pengumuman', ikon: 'bendera' },
        ],
    },
    {
        judul: 'Tata Kelola',
        item: [
            { label: 'Audit Platform', href: '/super/audit', ikon: 'daftar' },
        ],
    },
];

type Props = {
    judul: string;
    breadcrumb?: string;
    subjudul?: string;
    aksi?: ReactNode;
    children: ReactNode;
};

export default function SuperLayout({ judul, breadcrumb, subjudul, aksi, children }: Props) {
    const page = usePage();
    const props = page.props as unknown as {
        auth?: { pengguna?: { nama: string; peran_label: string } | null };
        identitasAplikasi?: { nama: string; logo: string | null };
        flash?: {
            sandi_baru?: { username: string; sandi: string } | null;
        };
    };

    const [disalin, setDisalin] = useState(false);

    const url = page.url.split('?')[0];

    const menyala = (item: ItemMenu) =>
        item.eksak ? url === item.href : url === item.href || url.startsWith(item.href + '/');

    const salinSandi = async (username: string, sandi: string) => {
        try {
            await navigator.clipboard.writeText(`Username: ${username}\nSandi: ${sandi}`);
            setDisalin(true);
            window.setTimeout(() => setDisalin(false), 2000);
        } catch {
            // Abaikan bila clipboard tidak tersedia
        }
    };

    return (
        <>
            <Head title={`${judul} · Super Admin`} />

            <div className="flex min-h-screen flex-col bg-paper text-ink md:flex-row">
                {/* Sidebar tetap GELAP dengan proporsi & struktur persis konsol Admin */}
                <aside className="sticky top-0 z-30 flex h-auto w-full shrink-0 flex-col border-b border-slate-800 bg-slate-950 text-slate-200 md:h-screen md:w-60 md:border-r md:border-b-0">
                    <div className="flex items-center gap-2 px-4 py-4">
                        {props.identitasAplikasi?.logo ? (
                            <>
                                <img
                                    src={props.identitasAplikasi.logo}
                                    alt={props.identitasAplikasi.nama || MEREK}
                                    className="h-5 w-auto max-w-[128px] object-contain brightness-0 invert"
                                />
                                <span className="shrink-0 rounded border border-sky-400/30 bg-sky-500/15 px-1.5 py-0.5 font-mono text-[10px] font-semibold tracking-wider text-sky-300 uppercase">
                                    SUPER
                                </span>
                            </>
                        ) : (
                            <>
                                <span className="grid size-7 shrink-0 place-items-center rounded-wf bg-sky-500 text-xs font-semibold text-slate-950">
                                    SA
                                </span>
                                <span className="truncate font-display text-sm font-semibold text-white">
                                    {props.identitasAplikasi?.nama || MEREK}
                                </span>
                                <span className="ml-auto shrink-0 rounded border border-sky-400/30 bg-sky-500/15 px-1.5 py-0.5 font-mono text-[9px] font-semibold tracking-wider text-sky-300 uppercase">
                                    SUPER
                                </span>
                            </>
                        )}
                    </div>

                    <nav className="scrollbar-tipis flex flex-row gap-0.5 overflow-x-auto px-3 pb-3 md:min-h-0 md:flex-1 md:flex-col md:overflow-x-visible md:overflow-y-auto">
                        {KELOMPOK.map((k, i) => (
                            <div key={k.judul} className="contents">
                                <p
                                    className={
                                        'hidden px-2 pb-1 text-[11px] font-medium text-slate-500 md:block ' +
                                        (i === 0 ? 'pt-1' : 'pt-4')
                                    }
                                >
                                    {k.judul}
                                </p>

                                {k.item.map((item) => {
                                    const aktif = menyala(item);
                                    return (
                                        <Link
                                            key={item.href}
                                            href={item.href}
                                            aria-current={aktif ? 'page' : undefined}
                                            className={
                                                'flex items-center gap-2 rounded-wf px-2.5 py-1.5 text-sm whitespace-nowrap transition-colors md:whitespace-normal ' +
                                                (aktif
                                                    ? 'bg-sky-500/20 font-medium text-white'
                                                    : 'text-slate-300 hover:bg-slate-800/80 hover:text-white')
                                            }
                                        >
                                            <Ikon nama={item.ikon} ukuran={16} />
                                            {item.label}
                                        </Link>
                                    );
                                })}
                            </div>
                        ))}
                    </nav>

                    <div className="hidden border-t border-slate-800 px-4 py-3 text-[11px] text-slate-400 md:block">
                        <p className="truncate font-medium text-slate-200">
                            {props.auth?.pengguna?.nama ?? 'Super Admin'}
                        </p>
                        <p className="mt-0.5 font-mono text-[10px] text-slate-500">
                            Operator Lintas Sekolah
                        </p>
                    </div>
                </aside>

                {/* Badan utama — mengikuti anatomi KonsolLayout Admin */}
                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="sticky top-0 z-20 flex items-center gap-3 border-b border-line bg-surface/95 px-4 py-2.5 backdrop-blur">
                        <span className="min-w-0 truncate text-xs text-ink-3">
                            {breadcrumb ?? `Super Admin / ${judul}`}
                        </span>

                        <div className="ml-auto flex items-center gap-2">
                            <span className="rounded-wf border border-line px-2.5 py-1 text-xs text-ink-2">
                                Super Admin
                            </span>
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                className="flex items-center gap-1.5 rounded-wf px-2 py-1 text-xs text-ink-3 hover:bg-surface-2 hover:text-bad"
                            >
                                <Ikon nama="keluar" ukuran={14} />
                                <span className="hidden sm:inline">Keluar</span>
                            </Link>
                        </div>
                    </header>

                    <main className="min-w-0 flex-1 px-4 py-5 md:px-6 md:py-6">
                        <div className="mb-4 flex flex-wrap items-center gap-3">
                            <div>
                                <h1 className="font-display text-xl font-semibold text-ink">
                                    {judul}
                                </h1>
                                {subjudul && (
                                    <p className="mt-0.5 text-xs text-ink-3">{subjudul}</p>
                                )}
                            </div>
                            {aksi && <div className="ml-auto flex flex-wrap gap-2">{aksi}</div>}
                        </div>

                        <UmpanBalik />

                        {props.flash?.sandi_baru && (
                            <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-wf border border-warn/40 bg-warn-bg px-4 py-3 text-xs text-warn">
                                <div>
                                    <p className="font-semibold">
                                        Sandi acak tampil SEKALI — segera salin dan serahkan ke Admin sekolah:
                                    </p>
                                    <p className="mt-1 font-mono text-sm text-ink">
                                        Username: <strong>{props.flash.sandi_baru.username}</strong> · Sandi:{' '}
                                        <strong>{props.flash.sandi_baru.sandi}</strong>
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() =>
                                        void salinSandi(
                                            props.flash!.sandi_baru!.username,
                                            props.flash!.sandi_baru!.sandi,
                                        )
                                    }
                                    className="rounded-wf border border-warn/40 bg-surface px-3 py-1.5 text-xs font-medium text-warn hover:bg-surface-2"
                                >
                                    {disalin ? 'Tersalin' : 'Salin kredensial'}
                                </button>
                            </div>
                        )}

                        {children}
                    </main>

                    <footer className="border-t border-line bg-surface/70 px-4 py-3 text-[11px] text-ink-3 md:px-6">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span>
                                &copy; {new Date().getFullYear()}{' '}
                                <span className="font-medium text-ink-2">
                                    {props.identitasAplikasi?.nama || MEREK}
                                </span>{' '}
                                · Panel Super Admin Platform
                            </span>
                            <span className="font-mono">
                                {props.auth?.pengguna?.nama ?? 'Super Admin'}
                            </span>
                        </div>
                    </footer>
                </div>
            </div>
        </>
    );
}
