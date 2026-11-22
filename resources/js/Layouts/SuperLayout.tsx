import { Head, Link, router, usePage } from '@inertiajs/react';
import { ReactNode } from 'react';

type Props = {
    judul: string;
    subjudul?: string;
    aksi?: ReactNode;
    children: ReactNode;
};

const MENU = [
    { label: 'Command Center', href: '/super', eksak: true },
    { label: 'Sekolah', href: '/super/sekolah', eksak: false },
    { label: 'Kesehatan Sistem', href: '/super/sistem', eksak: false },
    { label: 'Pengumuman Global', href: '/super/pengumuman', eksak: false },
    { label: 'Audit Platform', href: '/super/audit', eksak: false },
];

export default function SuperLayout({ judul, subjudul, aksi, children }: Props) {
    const page = usePage();
    const props = page.props as unknown as {
        auth?: { pengguna?: { nama: string } | null };
        flash?: {
            sukses?: string | null;
            galat?: string | null;
            sandi_baru?: { username: string; sandi: string } | null;
        };
    };

    const url = page.url.split('?')[0];

    const aktif = (href: string, eksak: boolean) =>
        eksak ? url === href : url === href || url.startsWith(href + '/');

    return (
        <>
            <Head title={`${judul} · Super Admin`} />

            <div className="flex min-h-screen flex-col bg-paper text-ink md:flex-row">
                <aside className="flex shrink-0 flex-col bg-slate-950 text-slate-200 md:w-60">
                    <div className="flex items-center justify-between border-b border-slate-800 px-4 py-3.5 md:block">
                        <div>
                            <p className="text-sm font-bold tracking-tight text-white">CBT Satria</p>
                            <span className="mt-1 inline-block rounded-full border border-sky-500/30 bg-sky-500/15 px-2 py-0.5 font-mono text-[10px] text-sky-300">
                                SUPER ADMIN
                            </span>
                        </div>
                        <button
                            type="button"
                            onClick={() => router.post('/logout')}
                            className="rounded-wf border border-slate-700 px-2.5 py-1 text-xs text-slate-300 hover:bg-slate-800 md:hidden"
                        >
                            Keluar
                        </button>
                    </div>

                    <nav className="flex gap-1 overflow-x-auto px-3 py-2.5 md:flex-1 md:flex-col md:overflow-visible">
                        {MENU.map((item) => {
                            const menyala = aktif(item.href, item.eksak);
                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className={
                                        'whitespace-nowrap rounded-wf px-3 py-2 text-xs font-medium transition-colors ' +
                                        (menyala
                                            ? 'bg-sky-500/20 text-white'
                                            : 'text-slate-300 hover:bg-slate-800/80 hover:text-white')
                                    }
                                >
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>

                    <div className="hidden border-t border-slate-800 px-4 py-3 md:block">
                        <p className="truncate text-xs font-medium text-slate-200">
                            {props.auth?.pengguna?.nama ?? 'Super Admin'}
                        </p>
                        <button
                            type="button"
                            onClick={() => router.post('/logout')}
                            className="mt-2 w-full rounded-wf border border-slate-700 bg-slate-900 px-3 py-1.5 text-left text-xs text-slate-300 hover:bg-slate-800"
                        >
                            Keluar
                        </button>
                    </div>
                </aside>

                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-surface px-5 py-3.5">
                        <div className="min-w-0">
                            <h1 className="truncate text-base font-semibold text-ink">{judul}</h1>
                            {subjudul && (
                                <p className="mt-0.5 text-xs text-ink-3">{subjudul}</p>
                            )}
                        </div>
                        {aksi && <div className="flex flex-wrap items-center gap-2">{aksi}</div>}
                    </header>

                    <main className="mx-auto w-full max-w-6xl flex-1 space-y-4 p-5">
                        {props.flash?.sukses && (
                            <div className="rounded-wf border border-ok/30 bg-ok-bg px-4 py-2.5 text-xs text-ok">
                                {props.flash.sukses}
                            </div>
                        )}
                        {props.flash?.galat && (
                            <div className="rounded-wf border border-danger/30 bg-danger-bg px-4 py-2.5 text-xs text-danger">
                                {props.flash.galat}
                            </div>
                        )}
                        {props.flash?.sandi_baru && (
                            <div className="rounded-wf border border-warn/40 bg-warn-bg px-4 py-3 text-xs text-warn">
                                <p className="font-semibold">
                                    Sandi acak tampil SEKALI — segera salin dan serahkan ke Admin sekolah:
                                </p>
                                <p className="mt-1 font-mono text-sm text-ink">
                                    Username: <strong>{props.flash.sandi_baru.username}</strong> · Sandi:{' '}
                                    <strong>{props.flash.sandi_baru.sandi}</strong>
                                </p>
                            </div>
                        )}

                        {children}
                    </main>
                </div>
            </div>
        </>
    );
}
