import { Head, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';

type RuangTv = {
    id: number;
    kode: string;
    nama: string | null;
    lokasi: string | null;
    pengawas: string[];
    peserta: number;
    mengerjakan: number;
    selesai: number;
    terputus: number;
    terkunci: number;
    laporan: 'belum' | 'draf' | 'siap' | 'selesai';
};

type SesiTv = {
    id: number;
    label: string;
    ujian: string | null;
    mapel: string | null;
    tanggal: string | null;
    jam: string;
    selesai: string | null;
    status: string;
    status_label: string;
};

type PotretTv = {
    ada: boolean;
    keadaan: 'berjalan' | 'menunggu' | 'selesai' | 'kosong';
    sekolah: string;
    diperbarui_jam: string;
    sesi: SesiTv | null;
    ruang: RuangTv[];
    angka: {
        peserta: number;
        mengerjakan: number;
        selesai: number;
        terputus: number;
        terkunci: number;
        belum_mulai: number;
    };
    laporan: {
        selesai: number;
        total: number;
    };
};

type PengumumanTv = {
    id: number;
    judul: string;
    isi: string;
    nada: string;
    dibuat: string | null;
};

type SesiBerikutnya = {
    id: number;
    label: string;
    mapel: string | null;
    ujian: string | null;
    hari: string | null;
    jam: string;
};

type MuatanTv = {
    potret: PotretTv;
    selesai_iso: string | null;
    server_iso: string;
    pengumuman: PengumumanTv[];
    sesi_berikutnya: SesiBerikutnya[];
};

type Props = MuatanTv & {
    slug: string;
    denyut_url: string;
};

const RUANG_PER_SLIDE = 6;
const INTERVAL_SLIDE_MS = 10_000;
const INTERVAL_DENYUT_MS = 5_000;

function formatJam(ms: number): string {
    return new Date(ms).toLocaleTimeString('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });
}

function formatHitungMundur(sisaDetik: number): string {
    const detik = Math.max(0, Math.floor(sisaDetik));
    const jam = Math.floor(detik / 3600);
    const menit = Math.floor((detik % 3600) / 60);
    const sis = detik % 60;

    return [jam, menit, sis].map((v) => String(v).padStart(2, '0')).join(':');
}

function judulSesi(s: SesiTv | null): string {
    if (!s) {
        return 'Belum ada sesi aktif';
    }

    const mapel = s.mapel?.trim() ?? '';
    const ujian = s.ujian?.trim() ?? '';

    if (ujian !== '' && mapel !== '' && !ujian.toLowerCase().includes(mapel.toLowerCase())) {
        return `${mapel} · ${ujian}`;
    }

    return ujian || mapel || 'Ujian';
}

export default function Tv({
    potret: potretAwal,
    selesai_iso: selesaiAwal,
    server_iso: serverAwal,
    pengumuman: pengumumanAwal,
    sesi_berikutnya: berikutAwal,
    denyut_url,
}: Props) {
    const { identitasAplikasi } = usePage().props as unknown as {
        identitasAplikasi?: { nama: string; logo: string | null };
    };

    const [muatan, setMuatan] = useState<MuatanTv>({
        potret: potretAwal,
        selesai_iso: selesaiAwal,
        server_iso: serverAwal,
        pengumuman: pengumumanAwal,
        sesi_berikutnya: berikutAwal,
    });

    const [selisihJamMs, setSelisihJamMs] = useState<number>(
        () => Date.parse(serverAwal) - Date.now(),
    );
    const [sekarangMs, setSekarangMs] = useState<number>(() => Date.now());
    const [halaman, setHalaman] = useState(0);
    const [jedaSlide, setJedaSlide] = useState(false);
    const [gelap, setGelap] = useState(true);
    const [layarPenuh, setLayarPenuh] = useState(false);
    const [galatKoneksi, setGalatKoneksi] = useState<string | null>(null);

    const { potret, selesai_iso, pengumuman, sesi_berikutnya } = muatan;

    // Jam digital & hitung mundur tiap detik
    useEffect(() => {
        const id = window.setInterval(() => setSekarangMs(Date.now()), 1000);
        return () => window.clearInterval(id);
    }, []);

    // Sinkron status fullscreen
    useEffect(() => {
        const onChange = () => setLayarPenuh(document.fullscreenElement !== null);
        document.addEventListener('fullscreenchange', onChange);
        return () => document.removeEventListener('fullscreenchange', onChange);
    }, []);

    // Polling live tiap 5 detik
    const segarkan = useCallback(async () => {
        try {
            const res = await fetch(denyut_url, {
                headers: { Accept: 'application/json' },
            });

            if (!res.ok) {
                setGalatKoneksi(
                    res.status === 429
                        ? 'Batas muat ulang tercapai, mencoba lagi...'
                        : 'Koneksi terputus sementara',
                );
                return;
            }

            const data = (await res.json()) as MuatanTv;
            setMuatan(data);
            if (data.server_iso) {
                setSelisihJamMs(Date.parse(data.server_iso) - Date.now());
            }
            setGalatKoneksi(null);
        } catch {
            setGalatKoneksi('Koneksi terputus sementara');
        }
    }, [denyut_url]);

    useEffect(() => {
        const id = window.setInterval(() => {
            void segarkan();
        }, INTERVAL_DENYUT_MS);

        return () => window.clearInterval(id);
    }, [segarkan]);

    // Urutkan ruang yang punya kendala (terkunci/terputus) ke depan
    const ruangUrut = useMemo(() => {
        return [...potret.ruang].sort((a, b) => {
            const skorA = a.terkunci * 100 + a.terputus * 10;
            const skorB = b.terkunci * 100 + b.terputus * 10;
            if (skorA !== skorB) {
                return skorB - skorA;
            }
            return a.kode.localeCompare(b.kode, 'id');
        });
    }, [potret.ruang]);

    const totalHalaman = Math.max(1, Math.ceil(ruangUrut.length / RUANG_PER_SLIDE));
    const halamanAman = halaman % totalHalaman;

    useEffect(() => {
        if (jedaSlide || totalHalaman <= 1) {
            return;
        }

        const id = window.setInterval(() => {
            setHalaman((prev) => (prev + 1) % totalHalaman);
        }, INTERVAL_SLIDE_MS);

        return () => window.clearInterval(id);
    }, [jedaSlide, totalHalaman]);

    const ruangTampil = useMemo(() => {
        const awal = halamanAman * RUANG_PER_SLIDE;
        return ruangUrut.slice(awal, awal + RUANG_PER_SLIDE);
    }, [ruangUrut, halamanAman]);

    const waktuServerMs = sekarangMs + selisihJamMs;
    const sisaDetik = useMemo(() => {
        if (!selesai_iso || potret.keadaan !== 'berjalan') {
            return null;
        }
        const target = Date.parse(selesai_iso);
        if (Number.isNaN(target)) {
            return null;
        }
        return Math.max(0, Math.floor((target - waktuServerMs) / 1000));
    }, [selesai_iso, potret.keadaan, waktuServerMs]);

    const toggleFullscreen = async () => {
        try {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            } else {
                await document.documentElement.requestFullscreen();
            }
        } catch {
            // Abaikan bila peramban menolak fullscreen
        }
    };

    const totalKendala = potret.angka.terkunci + potret.angka.terputus;

    const tema = gelap
        ? {
              akar: 'bg-slate-950 text-slate-100',
              header: 'border-slate-800 bg-slate-900/95',
              kartu: 'border-slate-800 bg-slate-900/90',
              kartuSub: 'border-slate-800 bg-slate-950/70',
              teks2: 'text-slate-300',
              teks3: 'text-slate-400',
              tombol: 'border-slate-700 bg-slate-800 text-slate-200 hover:bg-slate-700',
              barBg: 'bg-slate-800',
              footer: 'border-slate-800 bg-slate-900/80 text-slate-400',
          }
        : {
              akar: 'bg-paper text-ink',
              header: 'border-line bg-surface',
              kartu: 'border-line bg-surface',
              kartuSub: 'border-line bg-surface-2',
              teks2: 'text-ink-2',
              teks3: 'text-ink-3',
              tombol: 'border-line bg-surface-2 text-ink hover:bg-line/40',
              barBg: 'bg-line/50',
              footer: 'border-line bg-surface text-ink-3',
          };

    return (
        <>
            <Head title={`Layar IFP · ${potret.sekolah}`} />

            <div className={`flex h-screen w-screen flex-col overflow-hidden ${tema.akar}`}>
                {/* Bar Atas IFP */}
                <header
                    className={`grid shrink-0 grid-cols-[1fr_auto_1fr] items-center gap-4 border-b px-6 py-3.5 ${tema.header}`}
                >
                    <div className="min-w-0">
                        <div className="flex items-center gap-2.5">
                            <h1 className="truncate text-xl font-bold tracking-tight lg:text-2xl">
                                {potret.sekolah}
                            </h1>
                            <span
                                className={
                                    'inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-0.5 font-mono text-xs font-semibold uppercase ' +
                                    (potret.keadaan === 'berjalan'
                                        ? 'bg-emerald-500/20 text-emerald-400 ring-1 ring-emerald-500/40'
                                        : potret.keadaan === 'menunggu'
                                          ? 'bg-amber-500/20 text-amber-400 ring-1 ring-amber-500/40'
                                          : 'bg-slate-500/20 text-slate-400 ring-1 ring-slate-500/40')
                                }
                            >
                                {potret.keadaan === 'berjalan' && (
                                    <span className="h-2 w-2 animate-pulse rounded-full bg-emerald-400" />
                                )}
                                {potret.keadaan === 'berjalan'
                                    ? 'LIVE'
                                    : potret.keadaan === 'menunggu'
                                      ? 'MENUNGGU'
                                      : potret.keadaan === 'selesai'
                                        ? 'SELESAI'
                                        : 'SIAGA'}
                            </span>
                        </div>
                        <p className={`mt-0.5 truncate text-sm lg:text-base ${tema.teks2}`}>
                            {judulSesi(potret.sesi)}
                            {potret.sesi && (
                                <span className={`ml-2 font-mono ${tema.teks3}`}>
                                    · {potret.sesi.label} · {potret.sesi.jam}
                                    {potret.sesi.selesai ? `–${potret.sesi.selesai}` : ''}
                                </span>
                            )}
                        </p>
                    </div>

                    <div className="flex items-center justify-center">
                        {identitasAplikasi?.logo && (
                            <img
                                src={identitasAplikasi.logo}
                                alt={identitasAplikasi.nama || potret.sekolah}
                                className="h-10 w-auto max-w-[180px] object-contain"
                            />
                        )}
                    </div>

                    <div className="flex shrink-0 items-center justify-end gap-4">
                        {sisaDetik !== null && (
                            <div
                                className={
                                    'rounded-xl border px-4 py-1.5 text-right ' +
                                    (sisaDetik <= 180
                                        ? 'border-rose-500/50 bg-rose-500/15 text-rose-300'
                                        : sisaDetik <= 600
                                          ? 'border-amber-500/50 bg-amber-500/15 text-amber-300'
                                          : 'border-sky-500/40 bg-sky-500/10 text-sky-300')
                                }
                            >
                                <p className="text-[10px] font-medium uppercase tracking-wider opacity-80">
                                    Sisa waktu sesi
                                </p>
                                <p className="font-mono text-2xl font-bold leading-tight lg:text-3xl">
                                    {formatHitungMundur(sisaDetik)}
                                </p>
                            </div>
                        )}

                        <div className="text-right">
                            <p className={`text-[10px] uppercase tracking-wider ${tema.teks3}`}>
                                Waktu server
                            </p>
                            <p className="font-mono text-2xl font-bold leading-tight lg:text-3xl">
                                {formatJam(waktuServerMs)}
                            </p>
                        </div>

                        <div className="flex items-center gap-1.5">
                            {totalHalaman > 1 && (
                                <button
                                    type="button"
                                    onClick={() => setJedaSlide((v) => !v)}
                                    className={`rounded-lg border px-3 py-2 text-xs font-medium ${tema.tombol}`}
                                    title={jedaSlide ? 'Lanjutkan rotasi slide' : 'Jeda rotasi slide'}
                                >
                                    {jedaSlide ? 'Lanjut' : 'Jeda'}
                                </button>
                            )}
                            <button
                                type="button"
                                onClick={() => setGelap((v) => !v)}
                                className={`rounded-lg border px-3 py-2 text-xs font-medium ${tema.tombol}`}
                                title="Ganti tema gelap/terang"
                            >
                                {gelap ? 'Terang' : 'Gelap'}
                            </button>
                            <button
                                type="button"
                                onClick={() => void toggleFullscreen()}
                                className={`rounded-lg border px-3 py-2 text-xs font-medium ${tema.tombol}`}
                                title="Layar penuh IFP"
                            >
                                {layarPenuh ? 'Keluar FS' : 'Layar Penuh'}
                            </button>
                        </div>
                    </div>
                </header>

                {/* Badan Utama 16:9 */}
                <main className="grid min-h-0 flex-1 grid-cols-12 gap-4 p-4 lg:gap-5 lg:p-5">
                    {/* Kolom Kiri (8/12): Grid Ruang */}
                    <section className="col-span-12 flex min-h-0 flex-col lg:col-span-8">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-sm font-semibold uppercase tracking-wider opacity-80">
                                Status Ruang Ujian ({potret.ruang.length} ruang)
                            </h2>
                            {totalHalaman > 1 && (
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setHalaman((h) => (h - 1 + totalHalaman) % totalHalaman)
                                        }
                                        className={`rounded-md border px-2.5 py-1 text-xs ${tema.tombol}`}
                                    >
                                        ‹
                                    </button>
                                    <span className={`font-mono text-xs ${tema.teks2}`}>
                                        Hal {halamanAman + 1} / {totalHalaman}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setHalaman((h) => (h + 1) % totalHalaman)
                                        }
                                        className={`rounded-md border px-2.5 py-1 text-xs ${tema.tombol}`}
                                    >
                                        ›
                                    </button>
                                </div>
                            )}
                        </div>

                        {ruangTampil.length === 0 ? (
                            <div
                                className={`flex flex-1 flex-col items-center justify-center rounded-2xl border p-8 text-center ${tema.kartu}`}
                            >
                                <p className="text-xl font-semibold">
                                    Belum ada sesi ujian yang sedang berjalan
                                </p>
                                <p className={`mt-2 max-w-md text-sm ${tema.teks3}`}>
                                    Layar ini otomatis menampilkan ruang ujian begitu sesi dibuka
                                    atau menjelang jadwal berikutnya.
                                </p>
                            </div>
                        ) : (
                            <div className="grid min-h-0 flex-1 grid-cols-2 grid-rows-3 gap-3 lg:grid-cols-3 lg:grid-rows-2 lg:gap-4">
                                {ruangTampil.map((r) => {
                                    const persenSelesai =
                                        r.peserta > 0
                                            ? Math.round((r.selesai / r.peserta) * 100)
                                            : 0;
                                    const persenAktif =
                                        r.peserta > 0
                                            ? Math.round(
                                                  ((r.mengerjakan + r.selesai) / r.peserta) * 100,
                                              )
                                            : 0;
                                    const adaTerkunci = r.terkunci > 0;
                                    const adaTerputus = r.terputus > 0;

                                    return (
                                        <article
                                            key={r.id}
                                            className={
                                                'flex flex-col justify-between rounded-2xl border p-4 transition-colors ' +
                                                (adaTerkunci
                                                    ? 'border-rose-500/60 bg-rose-950/35'
                                                    : adaTerputus
                                                      ? 'border-amber-500/50 bg-amber-950/25'
                                                      : tema.kartu)
                                            }
                                        >
                                            <div>
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="min-w-0">
                                                        <p className="font-mono text-2xl font-bold leading-none lg:text-3xl">
                                                            {r.kode}
                                                        </p>
                                                        {(r.nama || r.lokasi) && (
                                                            <p
                                                                className={`mt-1 truncate text-xs ${tema.teks3}`}
                                                            >
                                                                {[r.nama, r.lokasi]
                                                                    .filter(Boolean)
                                                                    .join(' · ')}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <span
                                                        className={
                                                            'shrink-0 rounded-full px-2.5 py-0.5 font-mono text-[11px] font-semibold ' +
                                                            (r.laporan === 'selesai'
                                                                ? 'bg-emerald-500/20 text-emerald-400'
                                                                : adaTerkunci
                                                                  ? 'bg-rose-500/25 text-rose-300'
                                                                  : adaTerputus
                                                                    ? 'bg-amber-500/25 text-amber-300'
                                                                    : 'bg-slate-500/20 text-slate-400')
                                                        }
                                                    >
                                                        {adaTerkunci
                                                            ? `${r.terkunci} terkunci`
                                                            : adaTerputus
                                                              ? `${r.terputus} terputus`
                                                              : r.laporan === 'selesai'
                                                                ? 'BA selesai'
                                                                : `${r.peserta} peserta`}
                                                    </span>
                                                </div>

                                                <p className={`mt-2 truncate text-xs ${tema.teks2}`}>
                                                    Pengawas:{' '}
                                                    {r.pengawas.length > 0
                                                        ? r.pengawas.join(', ')
                                                        : 'Belum ditetapkan'}
                                                </p>
                                            </div>

                                            <div className="mt-3 space-y-2.5">
                                                <div
                                                    className={`h-2.5 w-full overflow-hidden rounded-full ${tema.barBg}`}
                                                >
                                                    <div className="flex h-full w-full">
                                                        <div
                                                            className="bg-emerald-500 transition-all"
                                                            style={{ width: `${persenSelesai}%` }}
                                                        />
                                                        <div
                                                            className="bg-sky-500 transition-all"
                                                            style={{
                                                                width: `${Math.max(0, persenAktif - persenSelesai)}%`,
                                                            }}
                                                        />
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-4 gap-1.5 text-center">
                                                    <div
                                                        className={`rounded-lg border px-1.5 py-1.5 ${tema.kartuSub}`}
                                                    >
                                                        <p className="font-mono text-lg font-bold text-sky-400">
                                                            {r.mengerjakan}
                                                        </p>
                                                        <p className={`text-[10px] ${tema.teks3}`}>
                                                            Aktif
                                                        </p>
                                                    </div>
                                                    <div
                                                        className={`rounded-lg border px-1.5 py-1.5 ${tema.kartuSub}`}
                                                    >
                                                        <p className="font-mono text-lg font-bold text-emerald-400">
                                                            {r.selesai}
                                                        </p>
                                                        <p className={`text-[10px] ${tema.teks3}`}>
                                                            Selesai
                                                        </p>
                                                    </div>
                                                    <div
                                                        className={`rounded-lg border px-1.5 py-1.5 ${tema.kartuSub}`}
                                                    >
                                                        <p
                                                            className={
                                                                'font-mono text-lg font-bold ' +
                                                                (r.terputus > 0
                                                                    ? 'text-amber-400'
                                                                    : tema.teks3)
                                                            }
                                                        >
                                                            {r.terputus}
                                                        </p>
                                                        <p className={`text-[10px] ${tema.teks3}`}>
                                                            Putus
                                                        </p>
                                                    </div>
                                                    <div
                                                        className={`rounded-lg border px-1.5 py-1.5 ${tema.kartuSub}`}
                                                    >
                                                        <p
                                                            className={
                                                                'font-mono text-lg font-bold ' +
                                                                (r.terkunci > 0
                                                                    ? 'text-rose-400'
                                                                    : tema.teks3)
                                                            }
                                                        >
                                                            {r.terkunci}
                                                        </p>
                                                        <p className={`text-[10px] ${tema.teks3}`}>
                                                            Kunci
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </article>
                                    );
                                })}
                            </div>
                        )}
                    </section>

                    {/* Kolom Kanan (4/12): KPI + Pengumuman + Jadwal Berikutnya */}
                    <aside className="col-span-12 flex min-h-0 flex-col gap-4 lg:col-span-4">
                        {/* KPI Ringkas */}
                        <section className={`grid grid-cols-2 gap-2.5 rounded-2xl border p-3.5 ${tema.kartu}`}>
                            <div className={`rounded-xl border p-3 ${tema.kartuSub}`}>
                                <p className={`text-[11px] uppercase tracking-wider ${tema.teks3}`}>
                                    Total Peserta
                                </p>
                                <p className="mt-1 font-mono text-3xl font-bold">
                                    {potret.angka.peserta}
                                </p>
                            </div>
                            <div className={`rounded-xl border p-3 ${tema.kartuSub}`}>
                                <p className={`text-[11px] uppercase tracking-wider ${tema.teks3}`}>
                                    Mengerjakan
                                </p>
                                <p className="mt-1 font-mono text-3xl font-bold text-sky-400">
                                    {potret.angka.mengerjakan}
                                </p>
                            </div>
                            <div className={`rounded-xl border p-3 ${tema.kartuSub}`}>
                                <p className={`text-[11px] uppercase tracking-wider ${tema.teks3}`}>
                                    Selesai
                                </p>
                                <p className="mt-1 font-mono text-3xl font-bold text-emerald-400">
                                    {potret.angka.selesai}
                                </p>
                            </div>
                            <div
                                className={
                                    'rounded-xl border p-3 ' +
                                    (totalKendala > 0
                                        ? 'border-rose-500/50 bg-rose-500/15'
                                        : tema.kartuSub)
                                }
                            >
                                <p className={`text-[11px] uppercase tracking-wider ${tema.teks3}`}>
                                    Terkunci / Putus
                                </p>
                                <p
                                    className={
                                        'mt-1 font-mono text-3xl font-bold ' +
                                        (totalKendala > 0 ? 'text-rose-400' : tema.teks3)
                                    }
                                >
                                    {totalKendala}
                                </p>
                            </div>
                        </section>

                        {/* Pengumuman Panitia */}
                        <section
                            className={`flex min-h-0 flex-1 flex-col rounded-2xl border p-4 ${tema.kartu}`}
                        >
                            <h2 className="mb-2.5 text-xs font-semibold uppercase tracking-wider opacity-80">
                                Pengumuman Panitia
                            </h2>
                            {pengumuman.length === 0 ? (
                                <p className={`text-sm ${tema.teks3}`}>
                                    Tidak ada pengumuman aktif saat ini.
                                </p>
                            ) : (
                                <div className="space-y-2.5 overflow-hidden">
                                    {pengumuman.map((p) => (
                                        <article
                                            key={p.id}
                                            className={
                                                'rounded-xl border px-3.5 py-2.5 ' +
                                                (p.nada === 'penting'
                                                    ? 'border-amber-500/50 bg-amber-500/10'
                                                    : 'border-sky-500/35 bg-sky-500/10')
                                            }
                                        >
                                            <p className="text-sm font-semibold">{p.judul}</p>
                                            <p
                                                className={`mt-1 line-clamp-3 text-xs leading-relaxed ${tema.teks2}`}
                                            >
                                                {p.isi}
                                            </p>
                                        </article>
                                    ))}
                                </div>
                            )}
                        </section>

                        {/* Sesi Berikutnya */}
                        <section className={`rounded-2xl border p-4 ${tema.kartu}`}>
                            <h2 className="mb-2.5 text-xs font-semibold uppercase tracking-wider opacity-80">
                                Sesi Berikutnya
                            </h2>
                            {sesi_berikutnya.length === 0 ? (
                                <p className={`text-sm ${tema.teks3}`}>
                                    Belum ada jadwal sesi berikutnya.
                                </p>
                            ) : (
                                <ul className="space-y-2">
                                    {sesi_berikutnya.map((s) => (
                                        <li
                                            key={s.id}
                                            className={`flex items-center justify-between gap-2 rounded-xl border px-3 py-2 ${tema.kartuSub}`}
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold">
                                                    {s.mapel || s.ujian || 'Ujian'}
                                                </p>
                                                <p className={`text-xs ${tema.teks3}`}>
                                                    {s.hari} · {s.label}
                                                </p>
                                            </div>
                                            <span className="shrink-0 font-mono text-sm font-semibold text-sky-400">
                                                {s.jam}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </aside>
                </main>

                {/* Status Bawah */}
                <footer
                    className={`flex shrink-0 items-center justify-between border-t px-6 py-2 text-xs ${tema.footer}`}
                >
                    <span>
                        {galatKoneksi ? (
                            <span className="text-amber-400">{galatKoneksi}</span>
                        ) : (
                            <>
                                Tersambung · diperbarui{' '}
                                <span className="font-mono">{potret.diperbarui_jam}</span> · Berita
                                acara selesai{' '}
                                <span className="font-mono">
                                    {potret.laporan.selesai}/{potret.laporan.total}
                                </span>
                            </>
                        )}
                    </span>
                    <span>Layar IFP Publik · Tanpa data personal siswa</span>
                </footer>
            </div>
        </>
    );
}
