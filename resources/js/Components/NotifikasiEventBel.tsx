import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Chip } from '@/Components/ui';

type ItemNotifikasi = {
    id: number;
    jenis: string;
    tingkat: 'bad' | 'warn' | 'info' | string;
    judul: string;
    pesan: string;
    konteks_label: string | null;
    tautan: string | null;
    dibaca: boolean;
    waktu: string;
    tanggal: string;
};

type Ringkas = {
    belum_dibaca: number;
    kritis_belum_dibaca: number;
    suara: boolean;
    daftar: ItemNotifikasi[];
};

function bunyiKritis(): void {
    try {
        const AudioCtx = window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
        if (!AudioCtx) {
            return;
        }
        const ctx = new AudioCtx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = 660;
        gain.gain.value = 0.06;
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.16);
    } catch {
        // Abaikan bila peramban memblokir autoplay audio.
    }
}

function xsrfToken(): string {
    const cocok = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return cocok ? decodeURIComponent(cocok[1]) : '';
}

export default function NotifikasiEventBel({
    eventId,
    eventNama,
}: {
    eventId: number;
    eventNama: string;
}) {
    const [terbuka, setTerbuka] = useState(false);
    const [tab, setTab] = useState<'semua' | 'belum' | 'kritis'>('semua');
    const [ringkas, setRingkas] = useState<Ringkas>({
        belum_dibaca: 0,
        kritis_belum_dibaca: 0,
        suara: false,
        daftar: [],
    });
    const [toast, setToast] = useState<ItemNotifikasi | null>(null);
    const terakhirId = useRef<number | null>(null);
    const akar = useRef<HTMLDivElement | null>(null);

    const muat = useCallback(async () => {
        try {
            const res = await fetch(`/admin/events/${eventId}/notifikasi/ringkas`, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!res.ok) {
                return;
            }
            const data = (await res.json()) as Ringkas;
            setRingkas(data);

            const terbaru = data.daftar[0];
            if (terbaru) {
                if (
                    terakhirId.current !== null &&
                    terbaru.id > terakhirId.current &&
                    !terbaru.dibaca &&
                    terbaru.tingkat === 'bad'
                ) {
                    setToast(terbaru);
                    if (data.suara) {
                        bunyiKritis();
                    }
                }
                terakhirId.current = Math.max(terakhirId.current ?? 0, terbaru.id);
            } else if (terakhirId.current === null) {
                terakhirId.current = 0;
            }
        } catch {
            // Polling diam-diam: abaikan gangguan jaringan sementara.
        }
    }, [eventId]);

    useEffect(() => {
        terakhirId.current = null;
        setToast(null);
        void muat();
        const jam = window.setInterval(() => void muat(), 12_000);
        return () => window.clearInterval(jam);
    }, [muat]);

    useEffect(() => {
        const tutup = (e: MouseEvent) => {
            if (akar.current && !akar.current.contains(e.target as Node)) {
                setTerbuka(false);
            }
        };
        document.addEventListener('mousedown', tutup);
        return () => document.removeEventListener('mousedown', tutup);
    }, []);

    const tandaiDibaca = async (item: ItemNotifikasi) => {
        if (!item.dibaca) {
            await fetch(`/admin/events/${eventId}/notifikasi/${item.id}/baca`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            }).catch(() => null);
            void muat();
        }
        setTerbuka(false);
        setToast(null);
        if (item.tautan) {
            router.visit(item.tautan);
        }
    };

    const tandaiSemua = async () => {
        await fetch(`/admin/events/${eventId}/notifikasi/baca-semua`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
        }).catch(() => null);
        void muat();
    };

    const tersaring = ringkas.daftar.filter((n) => {
        if (tab === 'belum') {
            return !n.dibaca;
        }
        if (tab === 'kritis') {
            return n.tingkat === 'bad';
        }
        return true;
    });

    return (
        <>
            <div ref={akar} className="relative">
                <button
                    type="button"
                    onClick={() => setTerbuka((v) => !v)}
                    aria-label="Notifikasi event"
                    title={`Notifikasi ${eventNama}`}
                    className={
                        'relative inline-flex size-8 items-center justify-center rounded-wf border transition-colors ' +
                        (ringkas.belum_dibaca > 0
                            ? 'border-accent/40 bg-accent-soft text-accent'
                            : 'border-line bg-surface text-ink-2 hover:bg-surface-2')
                    }
                >
                    <svg
                        viewBox="0 0 20 20"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.6"
                        className="size-4"
                        aria-hidden="true"
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M10 3a4.5 4.5 0 0 0-4.5 4.5v2.3c0 .6-.2 1.2-.6 1.7L3.8 13h12.4l-1.1-1.5a2.8 2.8 0 0 1-.6-1.7V7.5A4.5 4.5 0 0 0 10 3Zm-1.8 12a1.8 1.8 0 0 0 3.6 0"
                        />
                    </svg>
                    {ringkas.belum_dibaca > 0 && (
                        <span className="absolute -top-1.5 -right-1.5 min-w-[17px] rounded-full bg-bad px-1 text-center font-mono text-[10px] leading-[17px] font-semibold text-white">
                            {ringkas.belum_dibaca > 99 ? '99+' : ringkas.belum_dibaca}
                        </span>
                    )}
                </button>

                {terbuka && (
                    <div className="absolute right-0 z-40 mt-2 w-80 overflow-hidden rounded-wf border border-line bg-surface shadow-wf sm:w-96">
                        <div className="flex items-center justify-between gap-2 border-b border-line bg-surface-2/60 px-3.5 py-2.5">
                            <span className="truncate text-xs font-semibold text-ink">
                                Notifikasi · {eventNama}
                            </span>
                            {ringkas.belum_dibaca > 0 && (
                                <button
                                    type="button"
                                    onClick={() => void tandaiSemua()}
                                    className="shrink-0 text-[11px] font-medium text-accent hover:underline"
                                >
                                    Tandai semua dibaca
                                </button>
                            )}
                        </div>

                        <div className="flex gap-1 border-b border-line bg-wf-soft px-3 py-1.5">
                            {(
                                [
                                    ['semua', `Semua (${ringkas.daftar.length})`],
                                    ['belum', `Belum dibaca (${ringkas.belum_dibaca})`],
                                    ['kritis', `Kritis (${ringkas.kritis_belum_dibaca})`],
                                ] as const
                            ).map(([kunci, label]) => (
                                <button
                                    key={kunci}
                                    type="button"
                                    onClick={() => setTab(kunci)}
                                    className={
                                        'rounded px-2 py-1 text-[11px] ' +
                                        (tab === kunci
                                            ? 'border border-line bg-surface font-semibold text-ink'
                                            : 'text-ink-3 hover:text-ink')
                                    }
                                >
                                    {label}
                                </button>
                            ))}
                        </div>

                        <div className="max-h-80 divide-y divide-line overflow-y-auto">
                            {tersaring.length === 0 ? (
                                <p className="px-4 py-6 text-center text-xs text-ink-3">
                                    Tidak ada notifikasi pada filter ini.
                                </p>
                            ) : (
                                tersaring.map((n) => (
                                    <button
                                        key={n.id}
                                        type="button"
                                        onClick={() => void tandaiDibaca(n)}
                                        className={
                                            'flex w-full items-start gap-2.5 px-3.5 py-2.5 text-left transition-colors hover:bg-surface-2/60 ' +
                                            (n.dibaca ? '' : 'bg-accent-soft/45')
                                        }
                                    >
                                        <span
                                            className={
                                                'mt-1.5 size-2 shrink-0 rounded-full ' +
                                                (n.tingkat === 'bad'
                                                    ? 'bg-bad'
                                                    : n.tingkat === 'warn'
                                                      ? 'bg-warn'
                                                      : 'bg-info')
                                            }
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-center justify-between gap-2">
                                                <span className="truncate text-xs font-semibold text-ink">
                                                    {n.judul}
                                                </span>
                                                <span className="shrink-0 font-mono text-[10px] text-ink-3">
                                                    {n.waktu}
                                                </span>
                                            </span>
                                            <span className="mt-0.5 block text-[11px] leading-relaxed text-ink-2">
                                                {n.pesan}
                                            </span>
                                            {n.konteks_label && (
                                                <span className="mt-1 inline-block">
                                                    <Chip
                                                        nada={
                                                            n.tingkat === 'bad'
                                                                ? 'bad'
                                                                : n.tingkat === 'warn'
                                                                  ? 'warn'
                                                                  : 'info'
                                                        }
                                                    >
                                                        {n.konteks_label}
                                                    </Chip>
                                                </span>
                                            )}
                                        </span>
                                    </button>
                                ))
                            )}
                        </div>

                        <div className="border-t border-line bg-wf-soft px-3.5 py-2 text-right">
                            <Link
                                href={`/admin/events/${eventId}/notifikasi`}
                                onClick={() => setTerbuka(false)}
                                className="text-[11px] font-medium text-accent hover:underline"
                            >
                                Lihat semua log notifikasi →
                            </Link>
                        </div>
                    </div>
                )}
            </div>

            {toast && (
                <div className="fixed right-4 bottom-4 z-50 w-80 rounded-wf border border-bad border-l-4 bg-surface p-3.5 shadow-wf sm:w-96">
                    <div className="flex items-center justify-between gap-2">
                        <span className="font-display text-xs font-semibold text-bad">
                            {toast.judul}
                        </span>
                        <span className="font-mono text-[10px] text-ink-3">{toast.waktu}</span>
                    </div>
                    <p className="mt-1 text-xs leading-relaxed text-ink-2">{toast.pesan}</p>
                    <div className="mt-2.5 flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={() => setToast(null)}
                            className="rounded-wf border border-line px-2.5 py-1 text-[11px] text-ink-2 hover:bg-surface-2"
                        >
                            Tutup
                        </button>
                        <button
                            type="button"
                            onClick={() => void tandaiDibaca(toast)}
                            className="rounded-wf bg-accent px-2.5 py-1 text-[11px] font-medium text-on-accent hover:opacity-95"
                        >
                            Periksa →
                        </button>
                    </div>
                </div>
            )}
        </>
    );
}
