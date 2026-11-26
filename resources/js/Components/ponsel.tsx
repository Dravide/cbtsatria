import { Link } from '@inertiajs/react';
import { ReactNode, useEffect, useRef, useState } from 'react';
import { Chip } from '@/Components/ui';

export function BilahPonsel({
    kiri,
    kanan,
    lengket = true,
}: {
    kiri: ReactNode;
    kanan?: ReactNode;
    lengket?: boolean;
}) {
    return (
        <header
            className={
                'flex items-center gap-2 border-b border-line bg-surface/95 px-3 py-2 backdrop-blur ' +
                (lengket ? 'sticky top-0 z-20' : '')
            }
        >
            <div className="flex min-w-0 items-center gap-2">{kiri}</div>
            {kanan && <div className="ml-auto flex shrink-0 items-center gap-2">{kanan}</div>}
        </header>
    );
}

/** Hanya dirender pada halaman yang memiliki konteks ruang. */
const TAB = [
    { label: 'Presensi', segmen: 'presensi' },
    { label: 'Token', segmen: 'token' },
    { label: 'Monitoring', segmen: 'monitoring' },
    { label: 'Laporan', segmen: 'berita-acara' },
] as const;

function IkonTabPonsel({ segmen }: { segmen: (typeof TAB)[number]['segmen'] }) {
    switch (segmen) {
        case 'presensi':
            return (
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M9 11l3 3L22 4" />
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
                </svg>
            );
        case 'token':
            return (
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <circle cx="7.5" cy="15.5" r="4.5" />
                    <path d="M10.85 12.15L21 2" />
                    <path d="M18 5l3 3" />
                    <path d="M15 8l2 2" />
                </svg>
            );
        case 'monitoring':
            return (
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M3 12h4l3-7 4 14 3-7h4" />
                </svg>
            );
        case 'berita-acara':
            return (
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <rect x="4" y="4" width="16" height="16" rx="2" />
                    <path d="M9 9h6" />
                    <path d="M9 13h6" />
                    <path d="M9 17h3" />
                </svg>
            );
    }
}

export function TabbarPonsel({
    ruangId,
    aktif,
    tandaBeritaAcara = false,
}: {
    ruangId: number;
    aktif: string;
    tandaBeritaAcara?: boolean;
}) {
    return (
        // Selaras dengan TabbarSiswa (.tabbar & .sel-hidup di konsol-siswa.css).
        <nav className="tabbar grid grid-cols-4">
            {TAB.map((t) => {
                const on = t.segmen === aktif;
                const adaTanda = tandaBeritaAcara && t.segmen === 'berita-acara' && !on;

                return (
                    <Link
                        key={t.segmen}
                        href={`/pengawas/ruang/${ruangId}/${t.segmen}`}
                        aria-current={on ? 'page' : undefined}
                        className={
                            'relative flex-col gap-0.5 py-1.5 text-[10px] ' +
                            (on ? 'sel-hidup' : '')
                        }
                    >
                        <span className="relative inline-flex">
                            <IkonTabPonsel segmen={t.segmen} />
                            {adaTanda && (
                                <span
                                    className="absolute -top-0.5 -right-1.5 inline-block size-1.5 rounded-full bg-bad ring-1 ring-surface"
                                    aria-label="Berita acara belum dikirim"
                                />
                            )}
                        </span>
                        <span>{t.label}</span>
                    </Link>
                );
            })}
        </nav>
    );
}

/** Kelas `daftar` tidak bergaya di konsol pengawas; override tema siswa ada di `konsol-siswa.css`. */
export function KotakPonsel({
    judul,
    kanan,
    padat = false,
    solid = false,
    children,
}: {
    judul?: ReactNode;
    kanan?: ReactNode;
    padat?: boolean;
    solid?: boolean;
    children: ReactNode;
}) {
    return (
        <section
            className={
                'daftar rounded-wf border ' +
                (solid ? 'border-line bg-surface-2/60' : 'border-line bg-surface')
            }
        >
            {judul && (
                <header className="flex items-center gap-2 border-b border-line/70 px-3 py-2">
                    <span className="text-xs font-medium text-ink-2">{judul}</span>
                    {kanan && <span className="ml-auto flex items-center gap-2">{kanan}</span>}
                </header>
            )}
            <div className={padat ? 'p-3' : 'space-y-2 p-3'}>{children}</div>
        </section>
    );
}

export function KpiPonsel({
    label,
    nilai,
    nada,
}: {
    label: string;
    nilai: string | number;
    nada?: 'ok' | 'warn' | 'bad' | 'info';
}) {
    const warna = nada
        ? { ok: 'text-ok', warn: 'text-warn', bad: 'text-bad', info: 'text-info' }[nada]
        : 'text-ink';

    return (
        <div className="daftar min-w-0 rounded-wf border border-line bg-surface px-2 py-1.5">
            {/* Izinkan label membungkus agar lima ubin sebaris tidak meluber di layar 320px. */}
            <span className="block text-[11px] leading-tight break-words text-ink-2">{label}</span>
            <b className={'angka block font-display text-base leading-tight ' + warna}>{nilai}</b>
        </div>
    );
}

/** Tata letak dua tingkat tanpa gulir horizontal untuk penggunaan satu tangan di ponsel. */
export function BarisKursi({
    kursi,
    nama,
    identitas,
    chip,
    tindakan,
    nada,
}: {
    kursi: number | string;
    nama: string;
    identitas?: ReactNode;
    chip?: ReactNode;
    tindakan?: ReactNode;
    nada?: 'bad' | null;
}) {
    return (
        <div
            className={
                'flex items-start gap-2.5 border-b border-line/60 px-3 py-2.5 last:border-0 ' +
                (nada === 'bad' ? 'bg-bad-bg/40' : '')
            }
        >
            <span className="w-6 shrink-0 pt-0.5 font-mono text-[11px] text-ink-3">
                {typeof kursi === 'number' ? String(kursi).padStart(2, '0') : kursi}
            </span>

            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-ink">{nama}</p>
                {identitas && <p className="truncate text-[11px] text-ink-3">{identitas}</p>}
                {chip && <div className="mt-1 flex flex-wrap items-center gap-1">{chip}</div>}
            </div>

            {tindakan && <div className="flex shrink-0 items-center gap-1">{tindakan}</div>}
        </div>
    );
}

/** Lokasi diambil dari `rooms.location`, bukan geolokasi browser (tanpa izin GPS). */
export function PhotoPonsel({
    label,
    petunjuk,
    ada,
    url,
    meta,
    terlambat,
    namaBerkas,
    children,
}: {
    label: string;
    petunjuk: string;
    ada: boolean;
    url?: string | null;
    meta?: string;
    terlambat?: boolean;
    namaBerkas?: string | null;
    children?: ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <div className="flex items-center gap-2">
                <span className="text-xs font-medium text-ink-2">{label}</span>
                <span className="ml-auto">
                    <Chip nada={ada ? 'ok' : 'mute'}>{ada ? 'terunggah' : 'belum ada'}</Chip>
                </span>
            </div>

            {ada && url ? (
                <a
                    href={url}
                    target="_blank"
                    rel="noreferrer"
                    className="block overflow-hidden rounded-wf border border-line bg-surface-2"
                >
                    {/* Berkas dilayani dari disk privat lewat rute terotorisasi. */}
                    <img src={url} alt={label} className="h-40 w-full object-cover" />
                </a>
            ) : (
                <div className="grid h-24 place-items-center rounded-wf border border-dashed border-line bg-surface-2/50 px-3 text-center">
                    <span className="text-[11px] text-ink-3">{petunjuk}</span>
                </div>
            )}

            {ada && (meta || namaBerkas) && (
                <p className="font-mono text-[11px] text-ink-3">
                    {meta}
                    {namaBerkas ? ` · ${namaBerkas}` : ''}
                </p>
            )}

            {terlambat && (
                <p className="text-[11px] text-warn">
                    Foto ini diunggah lebih dari dua jam sesudah diambil.
                </p>
            )}

            {children && <div className="flex flex-wrap gap-1.5">{children}</div>}
        </div>
    );
}

/**
 * Kanvas tanda tangan yang disimpan sebagai data URL PNG.
 * Tombol simpan nonaktif sampai ada goresan agar kanvas kosong tidak tersimpan.
 */
export function TtdPonsel({
    peran,
    label,
    wajib,
    ada,
    url,
    nama,
    oleh,
    jam,
    bolehTtd,
    kirim,
    sedangKirim,
}: {
    peran: string;
    label: string;
    wajib: boolean;
    ada: boolean;
    url?: string | null;
    nama?: string | null;
    oleh?: string | null;
    jam?: string | null;
    bolehTtd: boolean;
    kirim: (data: { peran: string; nama: string; tanda_tangan: string }) => void;
    sedangKirim: boolean;
}) {
    const kanvas = useRef<HTMLCanvasElement>(null);
    const menggambar = useRef(false);
    const [namaTtd, setNamaTtd] = useState('');
    const [adaGoresan, setAdaGoresan] = useState(false);

    // Latar putih diperlukan agar hasil cetak PNG tidak transparan.
    useEffect(() => {
        const el = kanvas.current;

        if (el === null || ada) {
            return;
        }

        const ctx = el.getContext('2d');

        if (ctx === null) {
            return;
        }

        // Skalakan dengan devicePixelRatio agar goresan tetap tajam saat dicetak.
        const rasio = window.devicePixelRatio || 1;
        el.width = el.clientWidth * rasio;
        el.height = el.clientHeight * rasio;
        ctx.scale(rasio, rasio);
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#0f172a';
    }, [ada]);

    const titik = (e: React.PointerEvent<HTMLCanvasElement>) => {
        const kotak = e.currentTarget.getBoundingClientRect();

        return { x: e.clientX - kotak.left, y: e.clientY - kotak.top };
    };

    const mulai = (e: React.PointerEvent<HTMLCanvasElement>) => {
        e.currentTarget.setPointerCapture(e.pointerId);
        menggambar.current = true;

        const ctx = kanvas.current?.getContext('2d');
        const p = titik(e);

        ctx?.beginPath();
        ctx?.moveTo(p.x, p.y);
    };

    const gerak = (e: React.PointerEvent<HTMLCanvasElement>) => {
        if (!menggambar.current) {
            return;
        }

        const ctx = kanvas.current?.getContext('2d');
        const p = titik(e);

        ctx?.lineTo(p.x, p.y);
        ctx?.stroke();
        setAdaGoresan(true);
    };

    const henti = () => {
        menggambar.current = false;
    };

    const bersihkan = () => {
        const el = kanvas.current;

        if (el === null) {
            return;
        }

        el.getContext('2d')?.clearRect(0, 0, el.width, el.height);
        setAdaGoresan(false);
    };

    const simpan = () => {
        const data = kanvas.current?.toDataURL('image/png');

        if (!data) {
            return;
        }

        kirim({ peran, nama: namaTtd.trim(), tanda_tangan: data });
    };

    // Sembunyikan kanvas untuk admin (read-only) alih-alih hanya menolak di server.
    const tampilKanvas = bolehTtd && !ada;

    return (
        <div className="space-y-2">
            <div className="flex items-center gap-2">
                <span className="text-xs font-medium text-ink-2">{label}</span>
                <span className="ml-auto">
                    <Chip nada={ada ? 'ok' : wajib ? 'warn' : 'mute'}>
                        {ada ? 'ditandatangani' : wajib ? 'wajib' : 'opsional'}
                    </Chip>
                </span>
            </div>

            {ada && url ? (
                <a href={url} target="_blank" rel="noreferrer" className="block">
                    <img
                        src={url}
                        alt={`Tanda tangan ${label}`}
                        className="h-16 w-full rounded-wf border border-line bg-white object-contain"
                    />
                </a>
            ) : (
                !bolehTtd && (
                    <p className="rounded-wf border border-dashed border-line bg-surface-2/50 px-3 py-3 text-[11px] text-ink-3">
                        Admin hanya dapat membaca dan mengunduh berita acara. Tanda tangan
                        harus datang dari pengawas ruangnya.
                    </p>
                )
            )}

            {tampilKanvas && (
                <>
                    <input
                        value={namaTtd}
                        onChange={(e) => setNamaTtd(e.target.value)}
                        placeholder="Nama penanda tangan"
                        className="w-full rounded-wf border border-line bg-surface px-2.5 py-2.5 text-sm text-ink outline-none focus:border-accent"
                    />
                    <canvas
                        ref={kanvas}
                        onPointerDown={mulai}
                        onPointerMove={gerak}
                        onPointerUp={henti}
                        onPointerLeave={henti}
                        role="img"
                        aria-label={`Area tanda tangan ${label}. Gambar tanda tangan dengan jari.`}
                        className="h-24 w-full touch-none rounded-wf border border-line bg-white"
                    />
                    <div className="flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            onClick={simpan}
                            disabled={!adaGoresan || namaTtd.trim().length < 3 || sedangKirim}
                            className="inline-grid min-h-11 place-items-center rounded-wf border border-accent bg-accent px-3 text-xs font-medium text-on-accent disabled:cursor-not-allowed disabled:border-ink-3/40 disabled:bg-ink-3/40"
                        >
                            Simpan tanda tangan
                        </button>
                        <button
                            type="button"
                            onClick={bersihkan}
                            className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface px-3 text-xs text-ink-2"
                        >
                            Bersihkan
                        </button>
                    </div>
                </>
            )}

            {ada && (
                <p className="text-[11px] text-ink-3">
                    {nama}
                    {oleh ? ` · ${oleh}` : ''}
                    {jam ? ` · ${jam}` : ''}
                </p>
            )}
        </div>
    );
}
