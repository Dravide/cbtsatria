import { Link, router, usePage } from '@inertiajs/react';
import { ReactNode, useEffect, useState } from 'react';

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
                'rounded-wf border px-3 py-1.5 text-xs font-medium transition-colors disabled:cursor-not-allowed ' +
                TOMBOL[nada]
            }
        >
            {children}
        </button>
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
    'w-full rounded-wf border border-line bg-surface px-2.5 py-1.5 text-sm text-ink outline-none focus:border-accent disabled:bg-surface-2 disabled:text-ink-3';

/* ----------------------------------------------------------------- Kotak */

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
        <section className="rounded-wf border border-line bg-surface">
            {judul && (
                <header className="flex items-center gap-3 border-b border-line px-4 py-2.5">
                    <h2 className="text-xs font-medium tracking-wide text-ink-2 uppercase">
                        {judul}
                    </h2>
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
 * Status singkat setelah aksi yang mengubah data - anatomi wireframe butir 7.
 *
 * Ia hilang sendiri setelah beberapa detik, tetapi TIDAK pernah menutup
 * pesan yang membawa kode pengawas: kode itu hanya ada di satu respons, dan
 * menghilangkannya otomatis berarti Admin kehilangan satu-satunya
 * kesempatannya membacanya.
 */
export function UmpanBalik() {
    const { flash } = usePage().props as unknown as {
        flash: {
            sukses?: string;
            galat?: string;
            kode_pengawas?: string;
            slug_baru?: string;
        };
    };

    const [tampil, setTampil] = useState(true);
    const [tampilGalat, setTampilGalat] = useState(true);

    // Kode pengawas TIDAK dihilangkan otomatis di sini. Laman yang
    // menerbitkannya menampilkannya sendiri (RuangDetail) karena ia perlu
    // menjelaskan artinya di tempat itu - dan pesan sukses yang hilang
    // sendiri sementara kodenya masih dibutuhkan justru berbahaya.
    const adaKode = Boolean(flash?.kode_pengawas);

    useEffect(() => {
        setTampil(true);
    }, [flash?.sukses, flash?.kode_pengawas]);

    useEffect(() => {
        if (!flash?.sukses || adaKode) {
            return;
        }

        const t = setTimeout(() => setTampil(false), 6000);

        return () => clearTimeout(t);
    }, [flash?.sukses, adaKode]);

    // Galat TIDAK pernah hilang sendiri.
    //
    // Pesan sukses boleh memudar karena yang membacanya sudah tahu apa yang
    // baru saja ia lakukan. Galat tidak: ia menjelaskan sesuatu yang TIDAK
    // terjadi, dan pengawas yang sedang berdiri di depan 32 siswa bisa
    // teralihkan satu menit lalu kembali ke layar yang sudah bersih tanpa
    // pernah tahu bahwa penandaannya gagal.
    useEffect(() => {
        setTampilGalat(true);
    }, [flash?.galat]);

    return (
        <>
            {flash?.galat && tampilGalat && (
                <div className="mb-4">
                    <div className="flex items-start gap-2 rounded-wf border border-bad/25 bg-bad-bg px-3 py-2.5 text-xs leading-relaxed text-bad">
                        <span>{flash.galat}</span>
                        <button
                            onClick={() => setTampilGalat(false)}
                            className="ml-auto shrink-0 px-1 text-bad/60 hover:text-bad"
                            aria-label="Tutup"
                        >
                            &#10005;
                        </button>
                    </div>
                </div>
            )}

            {flash?.sukses && tampil && (
                <div className="mb-4">
                    <div className="flex items-start gap-2 rounded-wf border border-ok/25 bg-ok-bg px-3 py-2.5 text-xs text-ok">
                        <span>{flash.sukses}</span>
                        <button
                            onClick={() => setTampil(false)}
                            className="ml-auto shrink-0 px-1 text-ok/60 hover:text-ok"
                            aria-label="Tutup"
                        >
                            &#10005;
                        </button>
                    </div>
                </div>
            )}
        </>
    );
}

/**
 * Kode pengawas, ditampilkan sekali.
 *
 * Diberi bingkai sendiri dan tidak ikut menghilang, karena ia satu-satunya
 * saat kode itu bisa dibaca. Sesudah respons ini, yang tersisa hanya hash.
 */
export function KodePengawas({ kode, slug }: { kode: string; slug?: string }) {
    return (
        <div className="rounded-wf border border-warn/30 bg-warn-bg px-3 py-2.5 text-xs text-warn">
            <p className="font-medium">Kode pengawas - hanya tampil sekali</p>
            <p className="mt-1.5 font-mono text-lg tracking-[0.2em] text-ink">{kode}</p>
            {slug && <p className="mt-1 font-mono text-[11px] opacity-80">/p/{slug}</p>}
            <p className="mt-1.5 leading-relaxed">
                Muat ulang halaman ini dan kode itu hilang. Ia tidak disimpan dalam bentuk
                terbaca di mana pun. Bila hilang, terbitkan ulang &mdash; tautan lama ikut
                dicabut.
            </p>
        </div>
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
    kolom: { judul: string; kelas?: string; render: (baris: T) => ReactNode }[];
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
                        <tr className="border-b border-line bg-surface-2/60 text-left">
                            {kolom.map((k) => (
                                <th
                                    key={k.judul}
                                    className={
                                        'px-3 py-2 text-[11px] font-medium tracking-wide text-ink-2 uppercase ' +
                                        (k.kelas ?? '')
                                    }
                                >
                                    {k.judul}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {baris.length === 0 && (
                            <tr>
                                <td
                                    colSpan={kolom.length}
                                    className="px-3 py-8 text-center text-sm text-ink-3"
                                >
                                    {kosong}
                                </td>
                            </tr>
                        )}

                        {baris.map((b) => (
                            <tr
                                key={kunci(b)}
                                className="border-b border-line/60 last:border-0 hover:bg-surface-2/40"
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
 */
export function Modal({
    judul,
    terbuka,
    padaTutup,
    children,
}: {
    judul: string;
    terbuka: boolean;
    padaTutup: () => void;
    children: ReactNode;
}) {
    if (!terbuka) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-ink/30 p-4 sm:p-8">
            <div className="w-full max-w-lg rounded-wf border border-line bg-surface shadow-wf">
                <header className="flex items-center border-b border-line px-4 py-3">
                    <h2 className="font-display text-sm font-semibold text-ink">{judul}</h2>
                    <button
                        onClick={padaTutup}
                        className="ml-auto text-ink-3 hover:text-ink"
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
