import { Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type Pengumuman = {
    id: number;
    judul: string;
    isi: string;
    nada: 'info' | 'penting' | string;
    dibuat: string | null;
};

type SesiJadwal = {
    id: number;
    label: string;
    ujian: string | null;
    mapel: string | null;
    jam: string;
    durasi_menit: number;
    status: string;
    status_label: string;
    susulan: boolean;
    tingkat: string[];
    kelas: string[];
};

type HariJadwal = {
    tanggal: string;
    label: string;
    hari_ini: boolean;
    sesi: SesiJadwal[];
};

type EventJadwal = {
    id: number;
    nama: string;
    jenis: string | null;
    status: string;
    status_label: string;
    rentang: string | null;
    tingkat: string[];
    pengumuman: Pengumuman[];
    hari: HariJadwal[];
};

type Props = {
    sekolah: string;
    slug: string;
    events: EventJadwal[];
    diperbarui: string;
};

function judulSesi(s: SesiJadwal): string {
    const mapel = s.mapel?.trim() ?? '';
    const ujian = s.ujian?.trim() ?? '';

    if (ujian !== '' && mapel !== '' && !ujian.toLowerCase().includes(mapel.toLowerCase())) {
        return `${mapel} · ${ujian}`;
    }

    return ujian || mapel || 'Ujian';
}

function kelasBadgeStatus(status: string): string {
    switch (status) {
        case 'berjalan':
            return 'border-info/30 bg-info-bg text-info';
        case 'selesai':
            return 'border-ok/30 bg-ok-bg text-ok';
        case 'siap':
            return 'border-line bg-surface-2 text-ink-2';
        default:
            return 'border-line bg-surface-2 text-ink-3';
    }
}

export default function Jadwal({ sekolah, slug, events, diperbarui }: Props) {
    const { identitasAplikasi } = usePage().props as unknown as {
        identitasAplikasi?: { nama: string; logo: string | null };
    };

    const [eventTerpilihId, setEventTerpilihId] = useState<number | null>(
        events[0]?.id ?? null,
    );
    const [tingkatDipilih, setTingkatDipilih] = useState<string>('semua');
    const [cari, setCari] = useState('');

    const eventAktif = useMemo(
        () => events.find((e) => e.id === eventTerpilihId) ?? events[0] ?? null,
        [events, eventTerpilihId],
    );

    const q = cari.trim().toLowerCase();

    const hariTersaring = useMemo(() => {
        if (!eventAktif) {
            return [];
        }

        return eventAktif.hari
            .map((h) => ({
                ...h,
                sesi: h.sesi.filter((s) => {
                    if (
                        tingkatDipilih !== 'semua' &&
                        s.tingkat.length > 0 &&
                        !s.tingkat.includes(tingkatDipilih)
                    ) {
                        return false;
                    }

                    if (q === '') {
                        return true;
                    }

                    const teks = [
                        s.mapel ?? '',
                        s.ujian ?? '',
                        s.label,
                        s.jam,
                        h.label,
                        ...s.kelas,
                        ...s.tingkat,
                    ]
                        .join(' ')
                        .toLowerCase();

                    return teks.includes(q);
                }),
            }))
            .filter((h) => h.sesi.length > 0);
    }, [eventAktif, tingkatDipilih, q]);

    return (
        <>
            <Head title={`Jadwal & pengumuman · ${sekolah}`} />

            <div className="laman-publik min-h-screen bg-paper">
                <div className="mx-auto flex min-h-screen w-full max-w-[34rem] flex-col sm:border-x sm:border-line">
                    <header className="flex flex-col gap-2 border-b border-line bg-surface px-4 py-3">
                        {identitasAplikasi?.logo && (
                            <div className="flex justify-center border-b border-line/60 pb-2">
                                <img
                                    src={identitasAplikasi.logo}
                                    alt={identitasAplikasi.nama || sekolah}
                                    className="h-5 w-auto max-w-[144px] object-contain"
                                />
                            </div>
                        )}
                        <div className="flex items-baseline gap-2">
                            <div className="min-w-0 flex-1">
                                <h1 className="truncate text-sm font-semibold text-ink">{sekolah}</h1>
                                <p className="mt-0.5 text-[11px] text-ink-3">
                                    Jadwal ujian &amp; pengumuman resmi
                                </p>
                            </div>
                            <span className="shrink-0 font-mono text-[11px] text-ink-3">
                                {diperbarui}
                            </span>
                        </div>
                    </header>

                    <main className="flex-1 space-y-3 px-3 py-4">
                        {events.length === 0 ? (
                            <section className="rounded-wf border border-line bg-surface px-4 py-8 text-center">
                                <p className="text-sm text-ink">Belum ada jadwal event ujian yang tayang.</p>
                                <p className="mt-1.5 text-xs leading-relaxed text-ink-3">
                                    Jadwal akan muncul di sini setelah panitia menetapkan event
                                    berstatus siap atau berjalan.
                                </p>
                            </section>
                        ) : (
                            <>
                                {events.length > 1 && (
                                    <div className="flex flex-wrap gap-1.5">
                                        {events.map((ev) => {
                                            const aktif = ev.id === eventAktif?.id;
                                            return (
                                                <button
                                                    key={ev.id}
                                                    type="button"
                                                    onClick={() => {
                                                        setEventTerpilihId(ev.id);
                                                        setTingkatDipilih('semua');
                                                    }}
                                                    className={
                                                        'rounded-full border px-3 py-1 text-xs transition-colors ' +
                                                        (aktif
                                                            ? 'border-accent bg-accent text-white'
                                                            : 'border-line bg-surface text-ink-2 hover:bg-surface-2')
                                                    }
                                                >
                                                    {ev.nama}
                                                </button>
                                            );
                                        })}
                                    </div>
                                )}

                                {eventAktif && (
                                    <>
                                        <section className="rounded-wf border border-line bg-surface px-3.5 py-3">
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="min-w-0">
                                                    <h2 className="text-sm font-semibold text-ink">
                                                        {eventAktif.nama}
                                                    </h2>
                                                    {eventAktif.rentang && (
                                                        <p className="mt-0.5 text-[11px] text-ink-3">
                                                            {eventAktif.rentang}
                                                        </p>
                                                    )}
                                                </div>
                                                <span
                                                    className={
                                                        'shrink-0 rounded-full border px-2 py-0.5 font-mono text-[10px] ' +
                                                        kelasBadgeStatus(eventAktif.status)
                                                    }
                                                >
                                                    {eventAktif.status_label}
                                                </span>
                                            </div>
                                        </section>

                                        {eventAktif.pengumuman.length > 0 && (
                                            <section className="space-y-2" aria-label="Pengumuman panitia">
                                                {eventAktif.pengumuman.map((p) => {
                                                    const penting = p.nada === 'penting';
                                                    return (
                                                        <article
                                                            key={p.id}
                                                            className={
                                                                'rounded-wf border px-3.5 py-2.5 text-xs leading-relaxed ' +
                                                                (penting
                                                                    ? 'border-warn/40 bg-warn-bg text-warn'
                                                                    : 'border-info/25 bg-info-bg text-info')
                                                            }
                                                        >
                                                            <div className="flex items-baseline justify-between gap-2">
                                                                <strong className="font-semibold">
                                                                    {p.judul}
                                                                </strong>
                                                                {p.dibuat && (
                                                                    <span className="shrink-0 font-mono text-[10px] opacity-80">
                                                                        {p.dibuat}
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <p className="mt-1 whitespace-pre-line text-ink">
                                                                {p.isi}
                                                            </p>
                                                        </article>
                                                    );
                                                })}
                                            </section>
                                        )}

                                        <div className="space-y-2">
                                            <div className="flex items-center gap-2">
                                                <input
                                                    value={cari}
                                                    onChange={(e) => setCari(e.target.value)}
                                                    placeholder="Cari mata pelajaran, kelas, atau hari..."
                                                    aria-label="Cari mata pelajaran, kelas, atau hari"
                                                    className="min-w-0 flex-1 rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink outline-none placeholder:text-ink-3 focus:border-accent focus:ring-2 focus:ring-accent/15"
                                                />
                                                {cari !== '' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setCari('')}
                                                        className="shrink-0 rounded-wf border border-line bg-surface px-2.5 py-2 text-xs text-ink-2 hover:bg-surface-2"
                                                    >
                                                        Reset
                                                    </button>
                                                )}
                                            </div>

                                            {eventAktif.tingkat.length > 1 && (
                                                <div className="flex flex-wrap gap-1.5">
                                                    <button
                                                        type="button"
                                                        onClick={() => setTingkatDipilih('semua')}
                                                        className={
                                                            'rounded-full border px-2.5 py-1 text-[11px] transition-colors ' +
                                                            (tingkatDipilih === 'semua'
                                                                ? 'border-accent bg-accent/10 font-medium text-accent'
                                                                : 'border-line bg-surface text-ink-3 hover:text-ink')
                                                        }
                                                    >
                                                        Semua tingkat
                                                    </button>
                                                    {eventAktif.tingkat.map((t) => (
                                                        <button
                                                            key={t}
                                                            type="button"
                                                            onClick={() => setTingkatDipilih(t)}
                                                            className={
                                                                'rounded-full border px-2.5 py-1 text-[11px] transition-colors ' +
                                                                (tingkatDipilih === t
                                                                    ? 'border-accent bg-accent/10 font-medium text-accent'
                                                                    : 'border-line bg-surface text-ink-3 hover:text-ink')
                                                            }
                                                        >
                                                            Tingkat {t}
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        {hariTersaring.length === 0 ? (
                                            <section className="rounded-wf border border-line bg-surface px-4 py-6 text-center text-xs text-ink-3">
                                                Tidak ada jadwal yang cocok dengan penyaringan ini.
                                            </section>
                                        ) : (
                                            <div className="space-y-3">
                                                {hariTersaring.map((h) => (
                                                    <section
                                                        key={h.tanggal}
                                                        className="overflow-hidden rounded-wf border border-line bg-surface"
                                                    >
                                                        <div className="flex items-center justify-between gap-2 border-b border-line bg-surface-2 px-3.5 py-2">
                                                            <h3 className="text-xs font-semibold text-ink">
                                                                {h.label}
                                                            </h3>
                                                            {h.hari_ini && (
                                                                <span className="rounded-full border border-info/30 bg-info-bg px-2 py-0.5 font-mono text-[10px] text-info">
                                                                    hari ini
                                                                </span>
                                                            )}
                                                        </div>
                                                        <ul>
                                                            {h.sesi.map((s) => (
                                                                <li
                                                                    key={s.id}
                                                                    className="border-b border-line px-3.5 py-2.5 last:border-b-0"
                                                                >
                                                                    <div className="flex items-start justify-between gap-2">
                                                                        <div className="min-w-0 flex-1">
                                                                            <p className="text-xs font-semibold text-ink">
                                                                                {judulSesi(s)}
                                                                            </p>
                                                                            <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 font-mono text-[11px] text-ink-3">
                                                                                <span>{s.jam}</span>
                                                                                <span>·</span>
                                                                                <span>{s.label}</span>
                                                                                {s.durasi_menit > 0 && (
                                                                                    <>
                                                                                        <span>·</span>
                                                                                        <span>{s.durasi_menit} mnt</span>
                                                                                    </>
                                                                                )}
                                                                            </div>
                                                                            {s.kelas.length > 0 && (
                                                                                <p className="mt-1 text-[11px] text-ink-2">
                                                                                    Kelas: {s.kelas.join(', ')}
                                                                                </p>
                                                                            )}
                                                                        </div>
                                                                        <div className="flex shrink-0 flex-col items-end gap-1">
                                                                            <span
                                                                                className={
                                                                                    'rounded-full border px-2 py-0.5 font-mono text-[10px] ' +
                                                                                    kelasBadgeStatus(s.status)
                                                                                }
                                                                            >
                                                                                {s.status_label}
                                                                            </span>
                                                                            {s.susulan && (
                                                                                <span className="rounded-full border border-warn/30 bg-warn-bg px-2 py-0.5 font-mono text-[10px] text-warn">
                                                                                    susulan
                                                                                </span>
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </section>
                                                ))}
                                            </div>
                                        )}
                                    </>
                                )}
                            </>
                        )}
                    </main>

                    <footer className="border-t border-line bg-surface px-4 py-3">
                        <nav className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-[11px] text-ink-3">
                            <Link href={`/info/${slug}`} className="hover:text-ink">
                                Portal
                            </Link>
                            <span>·</span>
                            <Link href={`/ruang/${slug}`} className="text-accent hover:underline">
                                Cek ruang &amp; kursi
                            </Link>
                            <span>·</span>
                            <Link href={`/wali/${slug}`} className="hover:text-ink">
                                Pantau wali
                            </Link>
                        </nav>
                    </footer>
                </div>
            </div>
        </>
    );
}
