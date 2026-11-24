import { Link, router, usePage } from '@inertiajs/react';
import { ReactNode, useEffect, useRef, useState } from 'react';
import { pesan } from '@/Components/dialog';
import Ikon from '@/Components/Ikon';

/** Komponen UI bersama berbasis token `@theme` Tailwind 4. */

export type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

export type Opsi = { nilai: string; label: string };

type NadaChip = 'ok' | 'warn' | 'bad' | 'info' | 'mute';

const NADA: Record<NadaChip, string> = {
    ok: 'bg-ok-bg text-ok',
    warn: 'bg-warn-bg text-warn',
    bad: 'bg-bad-bg text-bad',
    info: 'bg-info-bg text-info',
    mute: 'bg-surface-2 text-ink-3',
};

export function Chip({ nada = 'mute', children }: { nada?: NadaChip; children: ReactNode }) {
    // Pakai rounded-wf karena chip ini juga dipakai di rangka gelap ujian.
    return (
        <span
            className={
                'inline-flex items-center rounded-wf px-2 py-0.5 text-[11px] font-medium whitespace-nowrap ' +
                NADA[nada]
            }
        >
            {children}
        </span>
    );
}

/** Peta nada chip untuk status master data dan status event. */
export function nadaStatus(status: string): NadaChip {
    switch (status) {
        case 'aktif':
        case 'selesai':
            return 'ok';
        case 'perbaikan':
        case 'belum dicetak':
        case 'persiapan':
        case 'belum konfirmasi':
            return 'warn';
        case 'nonaktif':
        case 'dicabut':
        case 'kedaluwarsa':
        case 'batal':
            return 'bad';
        case 'berjalan':
        case 'siap':
            return 'info';
        case 'terdaftar':
            return 'ok';
        case 'dikecualikan':
            return 'bad';
        case 'manual':
            return 'info';
        default:
            return 'mute';
    }
}

type PropsTombol = {
    children: ReactNode;
    nada?: 'pri' | 'sek' | 'bahaya';
    type?: 'button' | 'submit';
    onClick?: () => void;
    disabled?: boolean;
    title?: string;
};

const TOMBOL = {
    pri: 'border-accent bg-accent text-on-accent hover:bg-accent/90 disabled:bg-ink-3/40 disabled:border-ink-3/40',
    sek: 'border-line bg-surface text-ink hover:bg-surface-2 disabled:text-ink-3/50',
    bahaya: 'border-bad/30 bg-surface text-bad hover:bg-bad-bg',
};

export function Tombol({
    children,
    nada = 'sek',
    type = 'button',
    onClick,
    disabled,
    title,
}: PropsTombol) {
    return (
        <button
            type={type}
            onClick={onClick}
            disabled={disabled}
            title={title}
            className={
                'inline-flex items-center justify-center gap-1.5 rounded-wf border px-3 py-1.5 text-xs font-medium ' +
                'transition-colors duration-150 active:translate-y-px disabled:cursor-not-allowed disabled:active:translate-y-0 ' +
                TOMBOL[nada]
            }
        >
            {children}
        </button>
    );
}

/** Tautan bergaya tombol untuk unduhan (Excel/PDF) agar mendukung klik kanan dan buka di tab baru. */
export function TombolTautan({
    children,
    href,
    nada = 'sek',
    title,
}: {
    children: ReactNode;
    href: string;
    nada?: 'pri' | 'sek' | 'bahaya';
    title?: string;
}) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noopener"
            title={title}
            className={
                'inline-flex items-center justify-center gap-1.5 rounded-wf border px-3 py-1.5 text-xs font-medium ' +
                'transition-colors duration-150 active:translate-y-px ' +
                TOMBOL[nada]
            }
        >
            {children}
        </a>
    );
}

export function Field({
    label,
    galat,
    petunjuk,
    children,
}: {
    label: string;
    galat?: string;
    petunjuk?: string;
    children: ReactNode;
}) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-ink-2">{label}</span>
            {children}
            {petunjuk && !galat && <span className="mt-1 block text-[11px] text-ink-3">{petunjuk}</span>}
            {galat && <span className="mt-1 block text-[11px] text-bad">{galat}</span>}
        </label>
    );
}

export const kelasInput =
    'w-full rounded-wf border border-line bg-surface px-2.5 py-1.5 text-sm text-ink outline-none ' +
    'transition-colors duration-150 placeholder:text-ink-3/70 ' +
    'hover:border-ink-3/50 focus:border-accent focus:ring-2 focus:ring-accent/15 ' +
    'disabled:bg-surface-2 disabled:text-ink-3';

export type PilihanCari<T extends string | number = number> = {
    id: T;
    /** Teks yang dicari dan ditampilkan, mis. "MAT-10 Matematika". */
    label: string;
    /** Keterangan kanan yang ikut dicari, mis. kode mapelnya. */
    ket?: string | null;
};

/**
 * Combobox pencarian tunggal pengganti `<select>`.
 * Memakai `<div>` dengan `aria-labelledby` (bukan `<label>`) karena kontrol utamanya `<button>`.
 */
export function PilihCari<T extends string | number = number>({
    label,
    galat,
    petunjuk,
    nilai,
    pilihan,
    padaPilih,
    disabled = false,
    kosong = 'Belum ada pilihan.',
    placeholder = 'Pilih…',
    izinkanKosong = false,
    labelKosong = 'Semua',
    kelasTombol,
    kelasIkon,
}: {
    label: string;
    galat?: string;
    petunjuk?: string;
    nilai: T | null;
    pilihan: PilihanCari<T>[];
    padaPilih: (id: T | null) => void;
    disabled?: boolean;
    kosong?: string;
    placeholder?: string;
    /** Tampilkan baris "Semua …" yang mengembalikan `null`. */
    izinkanKosong?: boolean;
    /** Teks baris kosong itu, mis. "Semua kelas". */
    labelKosong?: string;
    kelasTombol?: string;
    kelasIkon?: string;
}) {
    const idDasar = `pilih-cari-${label.replace(/\W+/g, '-').toLowerCase()}`;
    const [terbuka, setTerbuka] = useState(false);
    const [cari, setCari] = useState('');
    const [sorot, setSorot] = useState(0);

    const akar = useRef<HTMLDivElement | null>(null);
    const inputCari = useRef<HTMLInputElement | null>(null);
    const tombol = useRef<HTMLButtonElement | null>(null);

    // Sertakan baris kosong di daftar yang sama agar navigasi keyboard konsisten.
    type Baris = { id: T | null; label: string; ket?: string | null; kosongBaris?: boolean };

    const terpilih = pilihan.find((p) => p.id === nilai) ?? null;

    const q = cari.trim().toLowerCase();
    const cocok = q === ''
        ? pilihan
        : pilihan.filter((p) => `${p.label} ${p.ket ?? ''}`.toLowerCase().includes(q));

    const barisKosong: Baris[] =
        izinkanKosong && (q === '' || labelKosong.toLowerCase().includes(q))
            ? [{ id: null, label: labelKosong, kosongBaris: true }]
            : [];

    const tersaring: Baris[] = [
        ...barisKosong,
        ...cocok.map((p) => ({ ...p }) as Baris),
    ];

    // Reset sorotan saat hasil pencarian berubah.
    useEffect(() => {
        setSorot(0);
    }, [cari, terbuka]);

    useEffect(() => {
        if (!terbuka) {
            return;
        }

        const padaKlik = (e: MouseEvent) => {
            if (akar.current && !akar.current.contains(e.target as Node)) {
                setTerbuka(false);
            }
        };

        document.addEventListener('mousedown', padaKlik);

        return () => document.removeEventListener('mousedown', padaKlik);
    }, [terbuka]);

    useEffect(() => {
        if (terbuka) {
            inputCari.current?.focus();
        }
    }, [terbuka]);

    const buka = () => {
        if (disabled) {
            return;
        }

        setCari('');
        setTerbuka(true);
    };

    const pilih = (p: Baris) => {
        padaPilih(p.kosongBaris ? null : (p.id as T));
        setTerbuka(false);
        // Kembalikan fokus ke tombol agar pengguna keyboard tidak kehilangan posisi.
        tombol.current?.focus();
    };

    const padaTombol = (e: React.KeyboardEvent<HTMLButtonElement>) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            buka();
        }
    };

    const padaKunciCari = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setSorot((n) => Math.min(tersaring.length - 1, n + 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setSorot((n) => Math.max(0, n - 1));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const p = tersaring[sorot];

            if (p) {
                pilih(p);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            setTerbuka(false);
            tombol.current?.focus();
        }
    };

    return (
        <div ref={akar} className="relative min-w-0">
            {label && (
                <span id={`${idDasar}-label`} className="mb-1 block text-xs font-medium text-ink-2">
                    {label}
                </span>
            )}

            <button
                ref={tombol}
                type="button"
                role="combobox"
                aria-expanded={terbuka}
                aria-controls={`${idDasar}-daftar`}
                aria-labelledby={label ? `${idDasar}-label` : undefined}
                aria-label={label ? undefined : placeholder}
                aria-haspopup="listbox"
                disabled={disabled}
                onClick={() => (terbuka ? setTerbuka(false) : buka())}
                onKeyDown={padaTombol}
                className={
                    kelasTombol ??
                    kelasInput + ' flex cursor-pointer items-center gap-2 text-left'
                }
            >
                <span
                    className={
                        kelasTombol
                            ? 'min-w-0 flex-1 truncate'
                            : 'min-w-0 flex-1 truncate ' + (terpilih ? 'text-ink' : 'text-ink-3')
                    }
                >
                    {terpilih?.label ?? placeholder}
                </span>
                <Ikon
                    nama="panah"
                    ukuran={12}
                    className={
                        'shrink-0 transition-transform duration-150 ' +
                        (kelasIkon ?? 'text-ink-3') +
                        (terbuka ? ' rotate-180' : '')
                    }
                />
            </button>

            {terbuka && (
                <div className="absolute z-30 mt-1 w-full min-w-0 overflow-hidden rounded-wf border border-line bg-surface shadow-kotak">
                    <div className="border-b border-line p-1.5">
                        <input
                            ref={inputCari}
                            value={cari}
                            onChange={(e) => setCari(e.target.value)}
                            onKeyDown={padaKunciCari}
                            placeholder="Ketik untuk mencari…"
                            aria-label={`Cari ${label.toLowerCase()}`}
                            aria-controls={`${idDasar}-daftar`}
                            // Fokus tetap di input cari; pembaca layar membaca opsi tersorot lewat aria-activedescendant.
                            aria-activedescendant={
                                tersaring[sorot]
                                    ? `${idDasar}-opsi-${
                                          tersaring[sorot].kosongBaris ? 'kosong' : tersaring[sorot].id
                                      }`
                                    : undefined
                            }
                            className="w-full rounded-wf border border-line bg-surface px-2 py-1 text-sm text-ink outline-none placeholder:text-ink-3/70 focus:border-accent focus:ring-2 focus:ring-accent/15"
                        />
                    </div>

                    <ul
                        id={`${idDasar}-daftar`}
                        role="listbox"
                        aria-labelledby={`${idDasar}-label`}
                        className="max-h-56 overflow-y-auto py-1"
                    >
                        {tersaring.length === 0 ? (
                            <li className="px-2.5 py-2 text-xs text-ink-3">{kosong}</li>
                        ) : (
                            tersaring.map((p, i) => {
                                // Hindari id DOM "opsi-null" untuk baris kosong.
                                const kunci = p.kosongBaris ? 'kosong' : String(p.id);
                                const aktif = p.kosongBaris ? nilai === null : p.id === nilai;
                                const disorot = i === sorot;

                                return (
                                    <li key={kunci} role="none">
                                        <button
                                            type="button"
                                            role="option"
                                            id={`${idDasar}-opsi-${kunci}`}
                                            aria-selected={aktif}
                                            // Pakai onMouseDown agar pilihan tereksekusi sebelum blur menutup dropdown.
                                            onMouseDown={(e) => {
                                                e.preventDefault();
                                                pilih(p);
                                            }}
                                            onMouseEnter={() => setSorot(i)}
                                            className={
                                                'flex w-full items-center gap-2 px-2.5 py-1.5 text-left text-sm ' +
                                                (disorot ? 'bg-surface-2 ' : '') +
                                                (aktif ? 'text-accent' : 'text-ink')
                                            }
                                        >
                                            <span className="min-w-0 flex-1 truncate">{p.label}</span>
                                            {p.ket && (
                                                <span className="shrink-0 font-mono text-[11px] text-ink-3">
                                                    {p.ket}
                                                </span>
                                            )}
                                            {aktif && (
                                                <span className="shrink-0 text-[11px] text-accent">
                                                    ✓
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                );
                            })
                        )}
                    </ul>
                </div>
            )}

            {petunjuk && !galat && (
                <span className="mt-1 block text-[11px] text-ink-3">{petunjuk}</span>
            )}
            {galat && <span className="mt-1 block text-[11px] text-bad">{galat}</span>}
        </div>
    );
}

/** Daftar centang dengan pencarian nama dan tingkat untuk pilihan banyak. */
export function DaftarCentangCari<T extends string | number = number>({
    label,
    petunjuk,
    nilai,
    pilihan,
    padaUbah,
    kosong = 'Belum ada pilihan.',
}: {
    label: string;
    petunjuk?: string;
    nilai: T[];
    pilihan: (PilihanCari<T> & { tingkat?: string | null })[];
    padaUbah: (nilai: T[]) => void;
    kosong?: string;
}) {
    const [cari, setCari] = useState('');

    const q = cari.trim().toLowerCase();
    const tersaring =
        q === ''
            ? pilihan
            : pilihan.filter((p) =>
                  `${p.label} ${p.tingkat ?? ''} ${p.ket ?? ''}`.toLowerCase().includes(q),
              );

    const ubah = (id: T, aktif: boolean) =>
        padaUbah(aktif ? [...nilai, id] : nilai.filter((x) => x !== id));

    const semuaTersaringDipilih =
        tersaring.length > 0 && tersaring.every((p) => nilai.includes(p.id));

    const daftarTingkat = [
        ...new Set(
            pilihan
                .map((p) => p.tingkat?.trim())
                .filter((t): t is string => Boolean(t)),
        ),
    ].sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));

    const semuaDipilih =
        pilihan.length > 0 && pilihan.every((p) => nilai.includes(p.id));

    const pilihHanyaTingkat = (tingkat: string) => {
        const idsTingkat = pilihan
            .filter((p) => p.tingkat?.trim() === tingkat)
            .map((p) => p.id);

        const hanyaTingkatIni =
            idsTingkat.length > 0 &&
            nilai.length === idsTingkat.length &&
            idsTingkat.every((id) => nilai.includes(id));

        padaUbah(hanyaTingkatIni ? [] : idsTingkat);
    };

    return (
        <div>
            <p className="mb-1.5 text-xs font-medium text-ink-2">{label}</p>
            {petunjuk && <p className="mb-2 text-[11px] leading-relaxed text-ink-3">{petunjuk}</p>}

            <div className="overflow-hidden rounded-wf border border-line">
                <div className="flex items-center gap-2 border-b border-line p-1.5">
                    <input
                        value={cari}
                        onChange={(e) => setCari(e.target.value)}
                        placeholder="Ketik untuk mencari kelas…"
                        aria-label={`Cari ${label.toLowerCase()}`}
                        className="min-w-0 flex-1 rounded-wf border border-line bg-surface px-2 py-1 text-sm text-ink outline-none placeholder:text-ink-3/70 focus:border-accent focus:ring-2 focus:ring-accent/15"
                    />

                    {tersaring.length > 0 && (
                        <button
                            type="button"
                            onClick={() =>
                                semuaTersaringDipilih
                                    ? padaUbah(
                                          nilai.filter((id) => !tersaring.some((p) => p.id === id)),
                                      )
                                    : padaUbah([
                                          ...nilai,
                                          ...tersaring.map((p) => p.id).filter((id) => !nilai.includes(id)),
                                      ])
                            }
                            className="shrink-0 text-[11px] font-medium text-accent hover:underline"
                        >
                            {semuaTersaringDipilih ? 'Batal pilih semua' : 'Pilih semua'}
                        </button>
                    )}
                </div>

                {(pilihan.length > 0 || daftarTingkat.length > 0) && (
                    <div className="flex flex-wrap items-center gap-1.5 border-b border-line bg-wf-soft/60 px-2 py-1.5">
                        <button
                            type="button"
                            onClick={() =>
                                padaUbah(semuaDipilih ? [] : pilihan.map((p) => p.id))
                            }
                            className={
                                'rounded-wf border px-2 py-0.5 text-[11px] font-medium transition-colors ' +
                                (semuaDipilih
                                    ? 'border-accent bg-accent-soft text-accent'
                                    : 'border-line bg-surface text-ink-2 hover:bg-surface-2')
                            }
                        >
                            Semua kelas
                        </button>

                        {daftarTingkat.map((tingkat) => {
                            const idsTingkat = pilihan
                                .filter((p) => p.tingkat?.trim() === tingkat)
                                .map((p) => p.id);
                            const aktif =
                                idsTingkat.length > 0 &&
                                nilai.length === idsTingkat.length &&
                                idsTingkat.every((id) => nilai.includes(id));

                            return (
                                <button
                                    key={tingkat}
                                    type="button"
                                    onClick={() => pilihHanyaTingkat(tingkat)}
                                    className={
                                        'rounded-wf border px-2 py-0.5 text-[11px] font-medium transition-colors ' +
                                        (aktif
                                            ? 'border-accent bg-accent-soft text-accent'
                                            : 'border-line bg-surface text-ink-2 hover:bg-surface-2')
                                    }
                                >
                                    Tingkat {tingkat}
                                </button>
                            );
                        })}

                        {nilai.length > 0 && (
                            <button
                                type="button"
                                onClick={() => padaUbah([])}
                                className="ml-auto text-[11px] text-ink-3 hover:text-ink hover:underline"
                            >
                                Kosongkan
                            </button>
                        )}
                    </div>
                )}

                <div className="max-h-44 overflow-y-auto p-1">
                    {tersaring.length === 0 ? (
                        <p className="px-2 py-2 text-xs text-ink-3">{kosong}</p>
                    ) : (
                        tersaring.map((p) => (
                            <label
                                key={p.id}
                                className="flex cursor-pointer items-center gap-2 rounded-wf px-2 py-1 text-sm hover:bg-surface-2/60"
                            >
                                <input
                                    type="checkbox"
                                    checked={nilai.includes(p.id)}
                                    onChange={(e) => ubah(p.id, e.target.checked)}
                                />
                                <span className="min-w-0 flex-1 truncate text-ink">{p.label}</span>
                                {p.tingkat && (
                                    <span className="shrink-0 font-mono text-[11px] text-ink-3">
                                        {p.tingkat}
                                    </span>
                                )}
                            </label>
                        ))
                    )}
                </div>
            </div>

            {nilai.length > 0 && (
                <p className="mt-1 text-[11px] text-ink-3">{nilai.length} dipilih</p>
            )}
        </div>
    );
}

export function Kotak({
    judul,
    aksi,
    children,
}: {
    judul?: string;
    aksi?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="rounded-wf border border-line bg-surface shadow-kotak">
            {judul && (
                <header className="flex items-center gap-3 border-b border-line px-4 py-2.5">
                    <h2 className="font-display text-sm font-semibold text-ink">{judul}</h2>
                    {aksi && <div className="ml-auto flex gap-2">{aksi}</div>}
                </header>
            )}
            <div className="p-4">{children}</div>
        </section>
    );
}

/** Kotak penjelasan permanen (tidak bisa ditutup) sebelum pengguna mengambil tindakan. */
export function Inbox({ children }: { children: ReactNode }) {
    return (
        <div className="rounded-wf border border-info/25 bg-info-bg px-3 py-2.5 text-xs leading-relaxed text-info">
            {children}
        </div>
    );
}

export function Peringatan({ children }: { children: ReactNode }) {
    return (
        <div className="rounded-wf border border-warn/30 bg-warn-bg px-3 py-2.5 text-xs leading-relaxed text-warn">
            {children}
        </div>
    );
}

/** Disimpan di level modul agar pesan flash tidak tampil ulang saat layout di-remount; dibandingkan dengan referensi objek. */
let flashTerakhir: object | null = null;

/** Tampilkan flash sukses/galat sebagai toast SweetAlert2 agar tidak menggeser tata letak. */
export function UmpanBalik() {
    const { flash } = usePage().props as unknown as {
        flash?: {
            sukses?: string;
            galat?: string;
        };
    };

    useEffect(() => {
        if (flash === undefined || flash === null || flashTerakhir === flash) {
            return;
        }

        const galat = flash.galat ?? null;
        const sukses = flash.sukses ?? null;

        if (galat === null && sukses === null) {
            return;
        }

        flashTerakhir = flash;

        // Prioritaskan galat bila keduanya ada agar tidak menumpuk dua toast.
        void (galat === null ? pesan(sukses as string, 'sukses') : pesan(galat, 'galat'));
    }, [flash]);

    return null;
}

export function KodeTeks({ kode }: { kode: string }) {
    return (
        <span className="font-mono text-sm text-ink">{kode}</span>
    );
}

export function BilahFilter({
    children,
    padaKirim,
}: {
    children: ReactNode;
    padaKirim?: () => void;
}) {
    return (
        <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                padaKirim?.();
            }}
        >
            {children}
        </form>
    );
}

/** Tabel dengan paginasi sisi server. */
export function Tabel<T>({
    hal,
    kolom,
    kunci,
    kosong,
}: {
    hal: Paginator<T> | { data: T[]; total: number };
    kolom: {
        judul: string;
        /** Isi kepala kolom bila berbeda dari judulnya - mis. tombol urut. */
        kepala?: ReactNode;
        kelas?: string;
        render: (baris: T) => ReactNode;
    }[];
    kunci: (baris: T) => string | number;
    kosong: string;
}) {
    const halaman = hal as Paginator<T>;
    const baris = hal.data ?? [];

    const gayaKolom = (k?: string) => 'px-3 py-2 ' + (k ?? '');

    return (
        <div className="overflow-hidden rounded-wf border border-line bg-surface">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[36rem] text-sm">
                    <thead>
                        <tr className="border-b border-line bg-surface-2/50 text-left">
                            {kolom.map((k) => (
                                <th
                                    key={k.judul}
                                    className={
                                        'px-3 py-2 text-[11px] font-medium text-ink-3 ' +
                                        (k.kelas ?? '')
                                    }
                                >
                                    {k.kepala ?? k.judul}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {baris.length === 0 && (
                            <tr>
                                <td
                                    colSpan={kolom.length}
                                    className="px-3 py-10 text-center text-sm text-ink-3"
                                >
                                    {kosong}
                                </td>
                            </tr>
                        )}

                        {baris.map((b) => (
                            <tr
                                key={kunci(b)}
                                className="border-b border-line/50 transition-colors duration-100 last:border-0 hover:bg-accent-soft/40"
                            >
                                {kolom.map((k) => (
                                    <td key={k.judul} className={gayaKolom(k.kelas)}>
                                        {k.render(b)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {halaman.from !== undefined && (
                <div className="flex flex-wrap items-center gap-3 border-t border-line px-3 py-2.5 text-xs text-ink-3">
                    <span>
                        {halaman.from ?? 0}&ndash;{halaman.to ?? 0} dari{' '}
                        {(halaman.total ?? 0).toLocaleString('id-ID')}
                    </span>

                    {halaman.last_page > 1 && (
                        <nav className="ml-auto flex flex-wrap items-center gap-1">
                            {halaman.links.map((l, i) =>
                                l.url ? (
                                    <Link
                                        key={i}
                                        href={l.url}
                                        preserveScroll
                                        className={
                                            'rounded-wf border px-2 py-1 ' +
                                            (l.active
                                                ? 'border-accent bg-accent-soft font-medium text-accent'
                                                : 'border-line text-ink-2 hover:bg-surface-2')
                                        }
                                        dangerouslySetInnerHTML={{ __html: l.label }}
                                    />
                                ) : (
                                    <span
                                        key={i}
                                        className="rounded-wf border border-line px-2 py-1 text-ink-3/50"
                                        dangerouslySetInnerHTML={{ __html: l.label }}
                                    />
                                ),
                            )}
                        </nav>
                    )}
                </div>
            )}
        </div>
    );
}

/** Sinkronkan filter ke query string URL; nilai kosong dibuang agar riwayat navigasi bersih. */
export function pakaiFilter(jalur: string, awal: Record<string, string>) {
    const [nilai, setNilai] = useState<Record<string, string>>(awal);

    return {
        nilai,
        ubah(kunci: string, isi: string) {
            setNilai((n) => ({ ...n, [kunci]: isi }));
        },
        terapkan() {
            const bersih = Object.fromEntries(
                Object.entries(nilai).filter(([, v]) => v !== '' && v !== undefined),
            );

            router.get(jalur, bersih, { preserveState: true, preserveScroll: true, replace: true });
        },
        reset() {
            setNilai(awal);
            router.get(jalur, {}, { preserveState: true, preserveScroll: true, replace: true });
        },
    };
}

/**
 * Dialog modal. Prop `gelap` mengganti backdrop ke `bg-paper/85` karena di `.rangka-ujian`
 * token `--color-ink` berwarna terang.
 */
export function Modal({
    judul,
    terbuka,
    padaTutup,
    gelap = false,
    children,
}: {
    judul: string;
    terbuka: boolean;
    padaTutup: () => void;
    gelap?: boolean;
    children: ReactNode;
}) {
    // Dengar Escape di document karena fokus awal mungkin masih di body.
    useEffect(() => {
        if (!terbuka) {
            return;
        }

        const dengar = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                padaTutup();
            }
        };

        document.addEventListener('keydown', dengar);

        return () => document.removeEventListener('keydown', dengar);
    }, [terbuka, padaTutup]);

    if (!terbuka) {
        return null;
    }

    return (
        <div
            className={
                'fixed inset-0 z-40 flex items-start justify-center overflow-y-auto p-4 backdrop-blur-[2px] sm:p-8 ' +
                (gelap ? 'bg-paper/85' : 'bg-ink/40')
            }
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label={judul}
                className="my-auto w-full max-w-lg rounded-wf border border-line bg-surface shadow-wf"
            >
                <header className="flex items-center border-b border-line px-4 py-3">
                    <h2 className="font-display text-sm font-semibold text-ink">{judul}</h2>
                    <button
                        type="button"
                        onClick={padaTutup}
                        // Area ketuk 44x44px untuk perangkat sentuh.
                        className="ml-auto grid size-11 place-items-center rounded-wf text-ink-3 hover:bg-surface-2 hover:text-ink"
                        aria-label="Tutup"
                    >
                        &#10005;
                    </button>
                </header>
                <div className="p-4">{children}</div>
            </div>
        </div>
    );
}
