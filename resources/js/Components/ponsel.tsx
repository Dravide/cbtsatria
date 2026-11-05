import { Link } from '@inertiajs/react';
import { ReactNode, useEffect, useRef, useState } from 'react';
import { Chip } from '@/Components/ui';

/**
 * Kosakata rangka ponsel - wireframe-peran.html kelompok J.
 *
 * =====================================================================
 * Mengapa komponen React, bukan kelas CSS `.wd .scr .notch`
 * =====================================================================
 * Wireframe menggambarnya sebagai CSS karena wireframe itu GAMBAR. Yang
 * dipakai pengawas adalah aplikasi, dan tiga hal membuat salinan kelasnya
 * salah:
 *
 *   1. Tabbar itu NAVIGASI. Ia harus tahu rute mana yang sedang dibuka dan
 *      merender Link. CSS tidak bisa memutuskan apa pun.
 *   2. `.wd .hd .sp { margin-left:auto }` adalah aturan keturunan yang
 *      bersandar pada selektor bersarang; Tailwind 4 tidak punya cara wajar
 *      menuliskannya sebagai @utility.
 *   3. Seluruh primitif UI lain sudah tinggal di Components/ui.tsx. Menaruh
 *      separuhnya di CSS berarti dua tempat yang harus dibaca untuk
 *      memahami satu laman.
 *
 * Ukurannya pun berbeda dengan gambar: wireframe memakai .wd selebar 238px
 * karena ia memperlihatkan sebuah ponsel di dalam halaman. Aplikasi ini
 * DIRENDER di dalam ponsel, jadi bingkai ponsel palsu di dalam ponsel
 * sungguhan membuang layar yang justru paling sempit.
 */

/* --------------------------------------------------------------- Bilah */

/**
 * Bilah atas - padanan `.wd .hd`.
 *
 * `kiri` menerima chip (kode ruang) atau crumb; `kanan` menerima chip
 * keadaan, timer, atau satu tombol. `sp` yang mendorong ke kanan diperoleh
 * dari `ml-auto` pada wadah kanan, bukan dari elemen kosong - di wireframe
 * elemen kosong itu ada karena CSS tidak bisa mengukur sisa lebar.
 */
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

/* -------------------------------------------------------------- Tabbar */

/**
 * Tabbar - empat tab, dan tiap-tiapnya menunjuk ruang yang SEDANG dibuka.
 *
 * Keempat tab butuh `{ruang}` di alamatnya, sehingga tabbar tidak bisa
 * dirender tanpa ruang. Di laman yang memang tidak punya ruang - beranda
 * pengawas, pintu ruang - tabbar tidak dirender sama sekali, bukan
 * dirender dengan tautan mati.
 */
const TAB = [
    { label: 'Presensi', segmen: 'presensi' },
    { label: 'Token', segmen: 'token' },
    { label: 'Monitoring', segmen: 'monitoring' },
    { label: 'Laporan', segmen: 'berita-acara' },
] as const;

export function TabbarPonsel({ ruangId, aktif }: { ruangId: number; aktif: string }) {
    return (
        <nav className="sticky bottom-0 z-20 grid grid-cols-4 border-t border-line bg-surface">
            {TAB.map((t) => {
                const on = t.segmen === aktif;

                return (
                    <Link
                        key={t.segmen}
                        href={`/pengawas/ruang/${ruangId}/${t.segmen}`}
                        className={
                            'py-2.5 text-center text-[11px] font-medium transition-colors ' +
                            (on
                                ? 'border-t-2 border-accent text-accent'
                                : 'border-t-2 border-transparent text-ink-2 hover:text-ink')
                        }
                    >
                        {t.label}
                    </Link>
                );
            })}
        </nav>
    );
}

/* --------------------------------------------------------------- Kotak */

/**
 * Kotak isi ponsel - padanan `.box`.
 *
 * `solid` = kotak terisi (kepala ruang, blok utama); tanpa `solid` ia kotak
 * biasa dengan latar permukaan. Bedanya dipakai wireframe untuk memisahkan
 * "ini isi pokoknya" dari "ini daftar di bawahnya".
 */
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
                'rounded-wf border ' +
                (solid ? 'border-line bg-surface-2/60' : 'border-line bg-surface')
            }
        >
            {judul && (
                <header className="flex items-center gap-2 border-b border-line/70 px-3 py-2">
                    <span className="text-[11px] font-medium tracking-wide text-ink-2 uppercase">
                        {judul}
                    </span>
                    {kanan && <span className="ml-auto flex items-center gap-2">{kanan}</span>}
                </header>
            )}
            <div className={padat ? 'p-3' : 'space-y-2 p-3'}>{children}</div>
        </section>
    );
}

/* ----------------------------------------------------------------- KPI */

/**
 * Angka ringkas - padanan `.kpi`.
 *
 * Nilainya `string` juga, bukan hanya `number`: "0 / 0" dan "28 / 32" adalah
 * nilai yang sah di wireframe, dan memaksanya jadi angka berarti memaksa
 * pemanggilnya menyusun ulang tampilannya di luar komponen ini.
 */
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
        <div className="rounded-wf border border-line bg-surface px-2 py-1.5">
            <span className="block text-[10px] tracking-wide text-ink-3 uppercase">{label}</span>
            <b className={'block font-display text-base leading-tight ' + warna}>{nilai}</b>
        </div>
    );
}

/* ---------------------------------------------------------- Baris kursi */

/**
 * Satu kursi - DUA TINGKAT, bukan tabel lima kolom.
 *
 * =====================================================================
 * Aturan yang wajib dituruti, dan sebabnya
 * =====================================================================
 * Wireframe `:726` menulis kepalanya `24px minmax(0,1fr) auto` dengan tiga
 * kolom: nomor kursi, nama + chip, dan tindakan. Yang di tengah itu adalah
 * SATU sel berisi nama di atas dan chip presensi/pengerjaan di bawahnya -
 * bukan lima kolom yang harus digeser ke samping.
 *
 * Bedanya bukan selera. Pengawas memegang ponsel dengan satu tangan sambil
 * berdiri; laman yang menuntut geseran mendatar dibaca sambil menggeser, dan
 * baris yang tergeser membuat orang salah menandai. Salah tandai di laman
 * ini berarti siswa yang hadir tercatat tidak hadir - dan presensi itulah
 * yang membuka gerbang ujiannya.
 *
 * Karena itu `chip` menerima daftar, dan `tindakan` selalu ada di kanan
 * pada tingkat kedua yang sama.
 */
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

/* ---------------------------------------------------------------- Foto */

/**
 * Kotak foto berita acara - padanan `.photo`.
 *
 * Lokasi diambil dari `rooms.location`, BUKAN dari geolokasi peramban. Ini
 * koreksi yang disengaja terhadap plate 18, yang meminta "waktu pengambilan
 * dan lokasi": PRD §12.4 menetapkan MVP tanpa GPS tracking, dan meminta izin
 * lokasi di hari-H untuk sebuah berkas adalah izin yang diminta pada saat
 * paling buruk. Nama ruangnya sudah cukup untuk menjawab "di mana ini
 * diambil" - dan itu data yang memang sudah ada.
 *
 * Dilarang "memperbaiki" ini menjadi gelokasi di fase berikutnya tanpa
 * mengubah PRD-nya lebih dulu.
 */
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
                    {/* Berkasnya dari disk privat lewat rute berwenang, jadi
                        gambar ini tidak bisa dibuka tanpa sesi ruangnya. */}
                    <img src={url} alt={label} className="h-40 w-full object-cover" />
                </a>
            ) : (
                <div className="grid h-24 place-items-center rounded-wf border border-dashed border-line bg-surface-2/50 px-3 text-center">
                    <span className="text-[11px] text-ink-3">{petunjuk}</span>
                </div>
            )}

            {ada && (meta || namaBerkas) && (
                <p className="font-mono text-[10px] text-ink-3">
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

/* ----------------------------------------------------------- Tanda tangan */

/**
 * Kanvas tanda tangan - padanan `.sig`.
 *
 * ==== Mengapa kanvas, bukan unggahan berkas ====
 * Tanda tangan digambar dengan jari di layar. Tidak ada berkas yang pernah
 * ada, dan memaksa satu muncul berarti menyuruh pengawas memotret tanda
 * tangannya di atas kertas lalu memilihnya dari galeri - pekerjaan yang
 * hasilnya bukan tanda tangan melainkan foto tanda tangan.
 *
 * ==== Menyimpan apa ====
 * Data URL PNG, bukan blob. Bentuknya sama dengan yang digambar, bisa
 * disimpan sebagai teks di kolom `tanda_tangan`, dan tidak menuntut unggahan
 * multipart untuk satu goresan.
 *
 * ==== Yang diukur ====
 * `toDataURL` mengembalikan kanvas KOSONG bila belum ada goresan, dan itu
 * keadaan yang bisa dideteksi sebelum dikirim. Tombol simpan dimatikan
 * selama belum ada goresan - kalau tidak, tanda tangan kosong tersimpan dan
 * laporan yang menuntutnya akan menganggap dirinya lengkap.
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

    // Kanvas dengan latar putih dan garis 2px. Latar putihnya bukan hiasan:
    // PNG transparan di atas kertas putih terlihat sama, tetapi berkasnya
    // berbeda saat dicetak, dan berita acara ini memang untuk dicetak.
    useEffect(() => {
        const el = kanvas.current;

        if (el === null || ada) {
            return;
        }

        const ctx = el.getContext('2d');

        if (ctx === null) {
            return;
        }

        // Lebar piksel sebenarnya, bukan lebar CSS: kanvas yang diskalakan
        // menghasilkan goresan yang pecah saat dicetak.
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

    // Admin boleh membaca dan mengunduh, dan TIDAK boleh menandatangani.
    // Kalimatnya dinyatakan di UI, bukan hanya ditolak server, karena
    // tombol yang pasti ditolak lebih baik tidak muncul (blueprint `:962`).
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
                        className="w-full rounded-wf border border-line bg-surface px-2.5 py-1.5 text-sm text-ink outline-none focus:border-accent"
                    />
                    <canvas
                        ref={kanvas}
                        onPointerDown={mulai}
                        onPointerMove={gerak}
                        onPointerUp={henti}
                        onPointerLeave={henti}
                        className="h-24 w-full touch-none rounded-wf border border-line bg-white"
                    />
                    <div className="flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            onClick={simpan}
                            disabled={!adaGoresan || namaTtd.trim().length < 3 || sedangKirim}
                            className="rounded-wf border border-accent bg-accent px-3 py-1.5 text-xs font-medium text-on-accent disabled:cursor-not-allowed disabled:border-ink-3/40 disabled:bg-ink-3/40"
                        >
                            Simpan tanda tangan
                        </button>
                        <button
                            type="button"
                            onClick={bersihkan}
                            className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs text-ink-2"
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
