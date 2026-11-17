import { Link, router, usePage } from '@inertiajs/react';
import { ReactNode, useEffect, useRef, useState } from 'react';
import { pesan } from '@/Components/dialog';
import Ikon from '@/Components/Ikon';

/**
 * Komponen bersama untuk delapan laman master data.
 *
 * Ditulis tangan, bukan diambil dari pustaka komponen: sistem visualnya sudah
 * ditetapkan wireframe-admin.html, dan menambahkan lapisan komponen pihak
 * ketiga berarti setiap penyesuaian harus diterjemahkan dua kali. Tokennya pun
 * sudah ada di @theme Tailwind 4.
 */

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

/* ------------------------------------------------------------------ Chip */

type NadaChip = 'ok' | 'warn' | 'bad' | 'info' | 'mute';

const NADA: Record<NadaChip, string> = {
    ok: 'bg-ok-bg text-ok',
    warn: 'bg-warn-bg text-warn',
    bad: 'bg-bad-bg text-bad',
    info: 'bg-info-bg text-info',
    mute: 'bg-surface-2 text-ink-3',
};

export function Chip({ nada = 'mute', children }: { nada?: NadaChip; children: ReactNode }) {
    // `rounded-wf` dan bukan `rounded-full`: chip ini dipakai empat peran,
    // termasuk rangka GELAP ujian (Terkunci.tsx) yang tidak boleh berubah
    // oleh redesign konsol siswa. Yang meluruskan sudutnya di konsol siswa
    // adalah `.konsol-siswa .rounded-full { border-radius: 0 }` di
    // konsol-siswa.css, dan aturan itu hanya berlaku di dalam rangka terang.
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

/**
 * Nada chip dari status.
 *
 * Pemetaannya dikumpulkan di satu tempat supaya "aktif" hijau di semua laman
 * tanpa harus diingat ulang di sembilan berkas.
 *
 * Status event ikut di sini meski kosakatanya berbeda dari status master -
 * lihat EventStatus - karena pertanyaannya sama: apa warna chip untuk nilai
 * ini. Yang membedakan hanya nama nilainya, bukan aturannya.
 */
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

/* --------------------------------------------------------------- Tombol */

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
                // `active:translate-y-px` - satu-satunya gerak pada tombol,
                // dan hanya `transform`: menekan tombol harus terasa, tetapi
                // menggeser tata letak akan menggeser seluruh baris tabel di
                // sebelahnya.
                'inline-flex items-center justify-center gap-1.5 rounded-wf border px-3 py-1.5 text-xs font-medium ' +
                'transition-colors duration-150 active:translate-y-px disabled:cursor-not-allowed disabled:active:translate-y-0 ' +
                TOMBOL[nada]
            }
        >
            {children}
        </button>
    );
}

/**
 * Tautan yang tampak seperti tombol - untuk unduhan (Excel, PDF).
 *
 * ---------------------------------------------------------------------
 * Mengapa `<a>`, bukan `onClick` yang memindahkan `window.location`
 * ---------------------------------------------------------------------
 * Unduhan adalah navigasi, dan `<a href>` memberi peramban tiga hal yang
 * tombol tidak bisa: ia bisa dibuka di tab baru, diklik-kanan lalu "Simpan
 * sebagai", dan alamatnya bisa disalin untuk dipakai lagi besok tanpa
 * membuka laman ini. `target="_blank"` dipakai supaya PDF yang dirender
 * inline tidak menggantikan laman yang sedang dibaca Admin.
 *
 * Gayanya sengaja meminjam `TOMBOL`, supaya unduhan dan tombol aksi di
 * sebelahnya tidak tampak berasal dari dua sistem desain.
 */
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

/* --------------------------------------------------------------- Field */

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
            {/* Galat ditampilkan di bawah field-nya sendiri, bukan di satu
                kotak di atas form: pada form dua kolom, kotak ringkasan di
                atas memaksa Admin menebak field mana yang dimaksud. */}
            {galat && <span className="mt-1 block text-[11px] text-bad">{galat}</span>}
        </label>
    );
}

export const kelasInput =
    'w-full rounded-wf border border-line bg-surface px-2.5 py-1.5 text-sm text-ink outline-none ' +
    'transition-colors duration-150 placeholder:text-ink-3/70 ' +
    'hover:border-ink-3/50 focus:border-accent focus:ring-2 focus:ring-accent/15 ' +
    'disabled:bg-surface-2 disabled:text-ink-3';

/* ------------------------------------------------- PilihCari (pilihan cari) */

export type PilihanCari<T extends string | number = number> = {
    id: T;
    /** Teks yang dicari dan ditampilkan, mis. "MAT-10 Matematika". */
    label: string;
    /** Keterangan kanan yang ikut dicari, mis. kode mapelnya. */
    ket?: string | null;
};

/**
 * Pilihan tunggal yang bisa DICARI - pengganti `<select>` untuk daftar panjang.
 *
 * ---------------------------------------------------------------------
 * Mengapa bukan `select2` atau pustaka combobox
 * ---------------------------------------------------------------------
 * `select2` memerlukan jQuery, dan proyek ini tidak memuatnya sama sekali:
 * menambahkannya berarti satu pustaka baru yang harus hidup berdampingan
 * dengan React hanya untuk satu isian. Pustaka combobox React menambah
 * dependensi lain lagi, dengan gayanya sendiri yang harus ditimpa satu per
 * satu. Komponen ini memakai token yang sudah ada (`--color-line`,
 * `--color-accent`), jadi ia tidak membawa satu pun warna baru.
 *
 * ---------------------------------------------------------------------
 * Mengapa `<div>`, bukan `<label>` seperti `Field`
 * ---------------------------------------------------------------------
 * `Field` membungkus isinya dengan `<label>`, dan `<label>` tidak boleh
 * memuat tombol - tombol adalah labelable element, dan label hanya boleh
 * menunjuk satu kontrol. Di sini kontrolnya adalah tombol pembuka, sehingga
 * labelnya dirender sebagai teks terpisah dengan `aria-labelledby`.
 *
 * ---------------------------------------------------------------------
 * Nilai KOSONG, untuk penyaring
 * ---------------------------------------------------------------------
 * `izinkanKosong` menambahkan satu baris "Semua …" yang mengembalikan nilai
 * kosong. Tanpa itu, penyaring yang punya pilihan "semua kelas" harus tetap
 * memakai `<select>` - dan satu laman yang memakai dua bentuk pemilih untuk
 * dua isian yang bersebelahan terbaca sebagai dua sistem.
 *
 * ---------------------------------------------------------------------
 * Keyboard
 * ---------------------------------------------------------------------
 * Tombol membuka daftar; panah atas/bawah memindahkan sorotan; Enter memilih;
 * Escape menutup tanpa mengubah nilai. Fokus kembali ke tombol setelah
 * dipilih, sehingga pengguna keyboard tidak "jatuh" ke awal halaman.
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
}) {
    const idDasar = `pilih-cari-${label.replace(/\W+/g, '-').toLowerCase()}`;
    const [terbuka, setTerbuka] = useState(false);
    const [cari, setCari] = useState('');
    const [sorot, setSorot] = useState(0);

    const akar = useRef<HTMLDivElement | null>(null);
    const inputCari = useRef<HTMLInputElement | null>(null);
    const tombol = useRef<HTMLButtonElement | null>(null);

    // Baris "Semua …" ikut di daftar yang sama dengan pilihan lain, supaya
    // sorotan keyboard dan Enter bekerja untuknya tanpa cabang tersendiri.
    // Baris itu ditandai `kosongBaris: true`, dan itulah yang membuat
    // `pilih()` mengembalikan `null`.
    type Baris = { id: T | null; label: string; ket?: string | null; kosongBaris?: boolean };

    const terpilih = pilihan.find((p) => p.id === nilai) ?? null;

    const q = cari.trim().toLowerCase();
    const cocok = q === ''
        ? pilihan
        : pilihan.filter((p) => `${p.label} ${p.ket ?? ''}`.toLowerCase().includes(q));

    // "Semua …" selalu di paling atas, dan hanya muncul bila kotak cari kosong
    // atau teksnya memang cocok - menyembunyikannya saat mencari membuat Admin
    // tidak bisa kembali ke "semua" tanpa menghapus ketikan.
    const barisKosong: Baris[] =
        izinkanKosong && (q === '' || labelKosong.toLowerCase().includes(q))
            ? [{ id: null, label: labelKosong, kosongBaris: true }]
            : [];

    const tersaring: Baris[] = [
        ...barisKosong,
        ...cocok.map((p) => ({ ...p }) as Baris),
    ];

    // Sorotan tidak boleh menunjuk baris yang sudah tidak ada setelah dicari.
    useEffect(() => {
        setSorot(0);
    }, [cari, terbuka]);

    // Klik di luar menutup daftar - pola yang sama dengan menu baris jadwal.
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

    // Fokus pindah ke kotak cari begitu daftar terbuka: pengguna yang menekan
    // tombol untuk MENCARI tidak perlu satu klik lagi di kotak cari.
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
        // Fokus dikembalikan ke tombol, bukan dibiarkan hilang bersama
        // daftar yang ditutup - pengguna keyboard harus tetap di isian ini.
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
            <span id={`${idDasar}-label`} className="mb-1 block text-xs font-medium text-ink-2">
                {label}
            </span>

            <button
                ref={tombol}
                type="button"
                role="combobox"
                aria-expanded={terbuka}
                aria-controls={`${idDasar}-daftar`}
                aria-labelledby={`${idDasar}-label`}
                aria-haspopup="listbox"
                disabled={disabled}
                onClick={() => (terbuka ? setTerbuka(false) : buka())}
                onKeyDown={padaTombol}
                className={kelasInput + ' flex items-center gap-2 text-left'}
            >
                <span
                    className={
                        'min-w-0 flex-1 truncate ' + (terpilih ? 'text-ink' : 'text-ink-3')
                    }
                >
                    {terpilih?.label ?? placeholder}
                </span>
                {/* Ikon keluarga yang sama dengan sidebar (`Ikon`), bukan
                    karakter ▲/▼: karakter itu bergantung pada font, dan lebar
                    glyph-nya berbeda antar sistem. */}
                <Ikon
                    nama="panah"
                    ukuran={12}
                    className={
                        'shrink-0 text-ink-3 transition-transform duration-150 ' +
                        (terbuka ? 'rotate-180' : '')
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
                            // Sorotan keyboard disampaikan lewat
                            // `aria-activedescendant`: fokus tetap di kotak
                            // cari, sehingga pembaca layar membacakan baris
                            // yang disorot tanpa memindahkan fokus.
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
                                // Baris kosong punya `id` null, sehingga id
                                // DOM-nya tidak boleh dibentuk dari nilainya -
                                // dua baris akan menjadi "opsi-null" yang sama.
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
                                            // `onMouseDown` dan bukan `onClick`:
                                            // klik tombol di dalam dropdown
                                            // memicu blur pada kotak cari, dan
                                            // daftar yang tertutup oleh blur
                                            // tidak pernah menerima kliknya.
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

/* -------------------------------------- DaftarCentangCari (multi + cari) */

/**
 * Daftar CENTANG yang bisa dicari - pengganti deretan kotak centang panjang.
 *
 * ---------------------------------------------------------------------
 * Mengapa bukan `PilihCari`
 * ---------------------------------------------------------------------
 * `PilihCari` memilih SATU nilai; kelas sasaran ujian memilih BANYAK. Dua
 * komponen, bukan satu dengan sakelar: bentuk penyimpanannya berbeda
 * (satu id vs daftar id), dan komponen yang melayani keduanya lewat
 * percabangan akan lebih panjang daripada keduanya digabung.
 *
 * ---------------------------------------------------------------------
 * Yang dicari adalah nama dan tingkatnya
 * ---------------------------------------------------------------------
 * "X-1" dan "XI-1" berbagi awalan, sehingga penyaringan yang hanya mencocokkan
 * nama mulai akan menyisakan keduanya saat Admin mengetik "X-1". Karena itu
 * tingkatnya ikut dicocokkan terpisah - dan ditampilkan, supaya dua kelas
 * bernama sama di tingkat berbeda tidak lagi membingungkan.
 */
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

/* ------------------------------------------------- Kotak */

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
        /* Bayangannya satu token, bukan nilai mentah di dalam kelas: nilai
           mentah di kelas tidak bisa dibaca dari blok token, tidak ikut
           berganti bila temanya berganti, dan gate 48 menolaknya. */
        <section className="rounded-wf border border-line bg-surface shadow-kotak">
            {judul && (
                <header className="flex items-center gap-3 border-b border-line px-4 py-2.5">
                    {/* Judul kotak memakai `font-display`, bukan huruf kecil
                        berkapit: ia nama bagian, dan bagian-bagian di konsol
                        ini dibaca dengan cara yang sama seperti judul laman. */}
                    <h2 className="font-display text-sm font-semibold text-ink">{judul}</h2>
                    {aksi && <div className="ml-auto flex gap-2">{aksi}</div>}
                </header>
            )}
            <div className="p-4">{children}</div>
        </section>
    );
}

/* ----------------------------------------------------------------- Inbox */

/**
 * Kotak penjelasan yang tidak bisa ditutup.
 *
 * Ia bukan notifikasi - ia kalimat yang harus dibaca sebelum Admin mengambil
 * keputusan di laman itu. "Menutup tahun ajaran tidak menghapus data" adalah
 * contohnya: yang perlu dicegah bukan salah klik, melainkan keyakinan yang
 * salah sebelum mengklik.
 */
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

/* ------------------------------------------------------------ UmpanBalik */

/**
 * Objek `flash` terakhir yang sudah ditampilkan, disimpan di LEVEL MODUL.
 *
 * Bukan `useRef` di dalam komponen: `UmpanBalik` ikut terpasang ulang setiap
 * kali tata letak berpindah, dan ref yang lahir kembali dari `null` akan
 * menampilkan ulang pesan yang sama pada setiap pemasangan - toast kembar
 * untuk satu penyimpanan.
 *
 * Identitas objeknya yang dibandingkan, bukan teksnya. Dua penyimpanan
 * berturut-turut boleh berbunyi sama persis ("Presensi tersimpan." dua kali)
 * dan keduanya harus tampil; sementara SATU respons Inertia selalu membawa
 * objek `flash` yang baru, sehingga identitasnya cukup untuk membedakan
 * "pesan ini baru" dari "efek ini berjalan dua kali".
 */
let flashTerakhir: object | null = null;

/**
 * Status singkat setelah aksi yang mengubah data - anatomi wireframe butir 7.
 *
 * Tampil sebagai toast SweetAlert2 di atas layar, bukan kotak di dalam
 * `<main>`. Alasannya menyangkut laman yang paling banyak memakainya: kotak
 * inline menggeser seluruh isi ke bawah setiap kali muncul, sehingga daftar
 * kursi yang sedang ditandai pengawas melompat tepat setelah ia menekan satu
 * baris. Toast tidak menggeser apa pun - lihat `pesan()` di `dialog.tsx`.
 *
 * Sukses memudar sendiri, galat tidak: galat menjelaskan sesuatu yang TIDAK
 * terjadi, dan pengawas yang teralihkan satu menit harus tetap bisa
 * menemukannya saat kembali.
 */
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

        // Galat didahulukan: bila keduanya datang, yang menjelaskan kegagalan
        // adalah yang harus dibaca, dan dua toast bertumpuk membuat yang atas
        // menutupi yang bawah.
        void (galat === null ? pesan(sukses as string, 'sukses') : pesan(galat, 'galat'));
    }, [flash]);

    return null;
}

/**
 * Kode pengawas sebagai teks mono yang selalu tampil.
 *
 * Sejak kode itu disimpan terenkripsi dan selalu dikirim sebagai prop, ia
 * tidak lagi "tampil sekali". Komponen ini hanya alat tampil: enam angka
 * dalam huruf mono berjarak lebar, supaya mudah dibaca dan disalin ke kartu
 * pengawas.
 *
 * Kodenya milik ORANG, bukan ruang: satu pengawas memakainya di ruang mana
 * pun yang ditugaskan kepadanya. Yang disimpan di basis data adalah bentuk
 * terenkripsinya - lihat `EventCommitteeMember`.
 */
export function KodeTeks({ kode }: { kode: string }) {
    return (
        <span className="font-mono text-sm text-ink">{kode}</span>
    );
}

/* ----------------------------------------------------------- Bilah Filter */

/**
 * Bilah penyaring, selalu di antara angka ringkasan dan tabel.
 *
 * Posisinya adalah bagian dari spesifikasi, bukan selera: Admin harus melihat
 * angkanya SEBELUM menyaring, bukan sesudah. Kalau bilahnya di atas angka,
 * angka pertama yang dilihatnya sudah tersaring, dan ia kehilangan ukuran
 * seberapa besar yang sedang ia persempit.
 */
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

/* ------------------------------------------------------------------ Tabel */

/**
 * Tabel dengan paginasi di sisi server.
 *
 * Tidak ada penyaringan di peramban. Setiap perubahan filter dan setiap
 * perpindahan halaman adalah permintaan ke server, karena daftar siswa satu
 * sekolah tidak pernah dikirim seluruhnya ke peramban - dan tepi halaman
 * "1-50 dari 1.284" adalah janji bahwa yang dikirim hanya 50.
 */
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
                                    // Kepala tabel: huruf badan satu tingkat
                                    // lebih kecil, BUKAN kapital berjarak.
                                    // Kapital berjarak menaikkan tinggi baris
                                    // kepala tanpa menambah satu informasi pun,
                                    // dan pada tabel delapan kolom ia menjadi
                                    // deret huruf yang lebih lambat dibaca
                                    // daripada nama kolomnya sendiri.
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

            {/* Tepi halaman. Angkanya ditulis apa adanya - "1.284" bukan
                angka yang perlu disembunyikan; jumlah besar adalah hal biasa
                di sekolah, dan menyembunyikannya justru membuat Admin ragu
                apakah datanya benar. */}
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

/* ------------------------------------------------------------- Filter hook */

/**
 * Kirim filter ke server lewat URL.
 *
 * Nilai kosong dibuang dari query string, bukan dikirim sebagai string
 * kosong: URL `?cari=&status=` adalah URL yang tidak bisa dibedakan dari URL
 * yang tidak punya filter, dan menyimpannya berarti tombol "kembali" di
 * peramban berhenti punya arti.
 */
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

/* ------------------------------------------------------------------ Modal */

/**
 * Panel form yang muncul di atas tabel, bukan laman terpisah.
 *
 * Untuk master data, laman tambah sendiri berarti setiap penambahan kelas
 * memindahkan Admin keluar dari daftar yang sedang ia lihat, lalu kembali
 * dengan posisi gulir yang hilang. Panel menjaga konteksnya.
 *
 * ---------------------------------------------------------------------
 * `gelap` mengubah lapisannya, dan hanya lapisannya
 * ---------------------------------------------------------------------
 * Di dalam `.rangka-ujian` setiap token warna dipetakan ulang - termasuk
 * `--color-ink`, yang di sana nyaris putih. Lapisan `bg-ink/40` karena itu
 * berubah menjadi kabut TERANG di atas laman gelap, dan panelnya sendiri
 * tetap benar (`bg-surface` memetakan ke permukaan gelap). Yang salah hanya
 * lapisannya, dan itulah satu-satunya yang diganti `gelap`: tinta gelap
 * sungguhan dengan opasitas lebih tinggi.
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
    // Escape menutup, dan hanya di sini - bukan di setiap laman yang memakai
    // Modal. Panel ini menutupi seluruh layar, jadi papan ketik yang tidak
    // punya jalan keluar selain mencari tombol silang adalah jebakan.
    //
    // Pendengarnya di `document`, bukan `onKeyDown` pada pembungkus: fokus
    // awal ada di `body`, dan peristiwa tombol tidak merambat ke elemen yang
    // tidak sedang difokuskan.
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
                // `bg-paper` dan bukan `bg-black`: seluruh warna di berkas ini
                // lewat token, dan `paper` di dalam `.rangka-ujian` adalah
                // gelapnya laman itu sendiri - lapisan yang sama warnanya
                // dengan latar, hanya digelapkan, itulah yang dibaca sebagai
                // "di belakang".
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
                        // 44x44: silang penutup adalah satu-satunya jalan
                        // keluar dari lapisan ini selain Escape, dan `p-1`
                        // memberinya 24px. Di ponsel tidak ada Escape.
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
