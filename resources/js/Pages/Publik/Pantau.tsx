import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Chip, Kotak, PilihCari, Tombol, kelasInput } from '@/Components/ui';

/**
 * Pantau publik aktivitas ujian - `/pantau/{slug}`, tanpa login.
 *
 * =====================================================================
 * Laman ini menggambar kerangkanya sendiri
 * =====================================================================
 * Alasannya sama dengan `Publik/Papan.tsx`: kerangka aplikasi mengandaikan
 * pengguna yang sudah masuk, dan yang membuka laman ini mungkin tidak punya
 * akun. Menawarinya "keluar" atau sidebar adalah janji palsu.
 *
 * =====================================================================
 * Angkanya datang dari DENYUT, bukan dari props halaman
 * =====================================================================
 * Props halaman dipakai untuk potret PERTAMA - jadi papan sudah lengkap
 * sebelum satu permintaan tambahan pun berjalan, dan itu yang menentukan
 * tampilan pertama (termasuk pratinjau tautan). Sesudahnya `fetch` tiap
 * lima detik mengganti potretnya tanpa memuat ulang halaman.
 *
 * Inertia tidak dipakai untuk penyegaran berkala: ia mengirim SELURUH props
 * setiap kali, menyimpan riwayat kunjungan, dan `replace` berulang akan
 * mengotori riwayat peramban orang yang sedang membaca papan ini.
 *
 * =====================================================================
 * Toast lahir dari SELISIH, bukan dari keadaan
 * =====================================================================
 * Server tidak menyimpan "sudah diberitahukan". Peramban membandingkan
 * potret sekarang dengan potret sebelumnya, dan yang BERTAMBAH yang
 * diberitakan:
 *
 *   terkunci      naik  -> satu toast per ruang, jenis bahaya
 *   terputus      naik  -> satu toast per ruang, jenis peringatan
 *   laporan       jadi "selesai" -> satu toast kabar baik
 *
 * Toast yang dibangun dari KEADAAN akan muncul lagi setiap penyegaran
 * sampai ada yang menutupnya - dan papan yang memunculkan pemberitahuan
 * yang sama tiap lima detik berhenti dibaca orang.
 *
 * =====================================================================
 * Yang TIDAK ada di laman ini: nama siswa
 * =====================================================================
 * Batasnya ditegakkan di `PantauPublik`. Di sini pun tidak ada tempat untuk
 * menampilkannya - dan itu disengaja: laman yang tidak punya kolomnya tidak
 * bisa "lupa" menyaring.
 */

type Ruang = {
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

type Sesi = {
    id: number;
    label: string;
    ujian: string | null;
    mapel: string | null;
    tanggal: string | null;
    jam: string;
    selesai: string | null;
    status: string;
    status_label: string;
    keadaan?: 'berjalan' | 'menunggu' | 'selesai';
};

type PesertaRuang = {
    id: number;
    nama: string;
    nis: string | null;
    presensi: string;
    presensi_label: string;
    presensi_pendek: string;
    presensi_nada: 'ok' | 'info' | 'warn' | 'bad' | 'mute';
    status: string | null;
    status_label: string;
    status_nada: 'ok' | 'info' | 'warn' | 'bad' | 'mute';
    terhubung: boolean | null;
    terkunci: boolean;
    kunci_alasan: string | null;
    pelanggaran: number;
    denyut: string | null;
    nomor: number | null;
    progres: string | null;
};

type BeritaAcaraRuang = {
    keadaan: 'belum' | 'draf' | 'siap' | 'selesai';
    dibuka: boolean;
    final: boolean;
    dikirim_pada: string | null;
    catatan: string | null;
    pernyataan_presensi: boolean;
    pernyataan_keluar: boolean;
    foto: number;
    ttd_pengawas: boolean;
    ttd_oleh: string | null;
    kurang: string[];
};

type DetailRuang = {
    ruang: {
        id: number;
        kode: string;
        nama: string | null;
        lokasi: string | null;
        sesi: string | null;
        ujian: string | null;
        mapel: string | null;
        jam: string | null;
        pengawas: string[];
    };
    angka: {
        peserta: number;
        hadir: number;
        belum_presensi: number;
        tidak_hadir: number;
        mengerjakan: number;
        selesai: number;
        terputus: number;
        terkunci: number;
        pelanggaran: number;
    };
    peserta: PesertaRuang[];
    berita_acara: BeritaAcaraRuang;
    admin_monitoring_url: string;
    diperbarui_jam: string;
};

type Potret = {
    ada: boolean;
    keadaan: 'kosong' | 'berjalan' | 'menunggu' | 'selesai';
    sekolah: string;
    event?: { id: number; kode: string; nama: string } | null;
    daftar_sesi?: Sesi[];
    diperbarui: string;
    diperbarui_jam: string;
    sesi: Sesi | null;
    ruang: Ruang[];
    angka: {
        peserta: number;
        mengerjakan: number;
        selesai: number;
        belum_mulai: number;
        terputus: number;
        terkunci: number;
    };
    laporan: { selesai: number; total: number };
};

type Props = {
    slug: string;
    potret: Potret | null;
    denyut_url: string;
    ruang_url_template?: string | null;
    papan_url?: string | null;
    kembali_url?: string | null;
};

type Toast = {
    id: number;
    jenis: 'bahaya' | 'peringatan' | 'kabar';
    judul: string;
    pesan: string;
};

const SELANG_DENYUT_MS = 5000;
const UMUR_TOAST_MS = 12000;
const MAKS_TOAST = 3;

const LABEL_LAPORAN: Record<Ruang['laporan'], { teks: string; nada: 'ok' | 'warn' | 'mute' | 'info' }> = {
    belum: { teks: 'belum', nada: 'mute' },
    draf: { teks: 'draf', nada: 'warn' },
    siap: { teks: 'siap', nada: 'info' },
    selesai: { teks: 'selesai', nada: 'ok' },
};

export default function Pantau({ slug, potret: awal, denyut_url, ruang_url_template, kembali_url }: Props) {
    const [potret, setPotret] = useState<Potret | null>(awal);
    const [toast, setToast] = useState<Toast[]>([]);
    const [galat, setGalat] = useState<string | null>(null);
    const [sesiId, setSesiId] = useState<number | null>(awal?.sesi?.id ?? null);
    const [jeda, setJeda] = useState(false);
    const [layarPenuh, setLayarPenuh] = useState(false);
    const [cariRuang, setCariRuang] = useState('');
    const [filterRuang, setFilterRuang] = useState<'semua' | 'terkunci' | 'terputus' | 'laporan'>('semua');

    const [ruangAktif, setRuangAktif] = useState<Ruang | null>(null);
    const [detailRuang, setDetailRuang] = useState<DetailRuang | null>(null);
    const [memuatRuang, setMemuatRuang] = useState(false);
    const [galatRuang, setGalatRuang] = useState<string | null>(null);

    // Potret sebelumnya, di ref: pembandingnya tidak boleh memicu render,
    // dan ia harus bertahan antar-jadwal tanpa masuk daftar dependensi.
    const sebelum = useRef<Potret | null>(awal);
    const nomorToast = useRef(0);

    const tambahToast = useCallback((baru: Toast[]) => {
        if (baru.length === 0) {
            return;
        }

        setToast((lama) => [...baru, ...lama].slice(0, MAKS_TOAST));
    }, []);

    /**
     * Bandingkan dua potret, dan kembalikan pemberitahuan yang LAHIR.
     *
     * Hanya yang NAIK. Angka yang turun - peserta yang kuncinya dibuka,
     * jaringan yang tersambung lagi - tidak diberitakan: papan ini dibuka
     * untuk mencari yang tertahan, bukan untuk mengabarkan yang sudah beres.
     */
    const selisih = useCallback((lama: Potret | null, baru: Potret): Toast[] => {
        if (lama === null || !lama.ada || !baru.ada) {
            return [];
        }

        // Sesi berganti: seluruh angka dibandingkan dengan sesi lain, dan
        // selisihnya bukan kejadian melainkan sesi baru. Tidak diberitakan.
        if (lama.sesi?.id !== baru.sesi?.id) {
            return [];
        }

        const ruangLama = new Map(lama.ruang.map((r) => [r.id, r]));
        const hasil: Toast[] = [];

        for (const r of baru.ruang) {
            const l = ruangLama.get(r.id);

            if (l === undefined) {
                continue;
            }

            if (r.terkunci > l.terkunci) {
                hasil.push({
                    id: ++nomorToast.current,
                    jenis: 'bahaya',
                    judul: `Peserta terkunci · ${r.kode}`,
                    pesan: `${baru.sesi?.label ?? 'Sesi'} · ${baru.diperbarui_jam} — pengawas ruang sedang menangani.`,
                });
            }

            if (r.terputus > l.terputus) {
                hasil.push({
                    id: ++nomorToast.current,
                    jenis: 'peringatan',
                    judul: `${r.terputus} peserta terputus · ${r.kode}`,
                    pesan: `${baru.sesi?.label ?? 'Sesi'} · ${baru.diperbarui_jam} — tanpa kabar lebih dari 90 detik.`,
                });
            }

            if (r.laporan === 'selesai' && l.laporan !== 'selesai') {
                hasil.push({
                    id: ++nomorToast.current,
                    jenis: 'kabar',
                    judul: `Laporan lengkap · ${r.kode}`,
                    pesan: `${baru.sesi?.label ?? 'Sesi'} · ${baru.diperbarui_jam} — berita acara ditandatangani dan dikirim.`,
                });
            }
        }

        return hasil;
    }, []);

    /* ------------------------------------------------------------- denyut */

    const tarikPotret = useCallback(
        (targetSesiId: number | null) => {
            const url = targetSesiId ? `${denyut_url}?sesi=${targetSesiId}` : denyut_url;

            return fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
                .then((baru: Potret) => {
                    tambahToast(selisih(sebelum.current, baru));
                    sebelum.current = baru;
                    setPotret(baru);
                    setGalat(null);
                })
                .catch(() => {
                    // Jaringan putus BUKAN galat yang ditampilkan di papan:
                    // papan yang menampilkan kotak merah karena Wi-Fi lorong
                    // lemah akan membuat orang mengira ujiannya bermasalah.
                    // Angka terakhirnya tetap terbaca, dan penanda "diperbarui"
                    // yang menua adalah kabarnya - lihat `menua` di bawah.
                    setGalat('sebelumnya');
                });
        },
        [denyut_url, selisih, tambahToast],
    );

    useEffect(() => {
        if (jeda) {
            return;
        }

        const jam = window.setInterval(() => {
            void tarikPotret(sesiId);
        }, SELANG_DENYUT_MS);

        return () => window.clearInterval(jam);
    }, [jeda, sesiId, tarikPotret]);

    const pilihSesi = (id: number) => {
        setSesiId(id);
        setRuangAktif(null);
        setDetailRuang(null);
        void tarikPotret(id);
    };

    const toggleLayarPenuh = () => {
        if (!document.fullscreenElement) {
            void document.documentElement.requestFullscreen?.().then(() => setLayarPenuh(true));
        } else {
            void document.exitFullscreen?.().then(() => setLayarPenuh(false));
        }
    };

    useEffect(() => {
        const dengar = () => setLayarPenuh(Boolean(document.fullscreenElement));
        document.addEventListener('fullscreenchange', dengar);

        return () => document.removeEventListener('fullscreenchange', dengar);
    }, []);

    const muatDetailRuang = useCallback(
        (r: Ruang) => {
            if (!ruang_url_template) {
                return;
            }

            setRuangAktif(r);
            setMemuatRuang(true);
            setGalatRuang(null);

            const url = ruang_url_template.replace('__RUANG__', String(r.id));

            fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((res) => (res.ok ? res.json() : Promise.reject(new Error(String(res.status)))))
                .then((isi: DetailRuang) => {
                    setDetailRuang(isi);
                    setGalatRuang(null);
                })
                .catch(() => {
                    setGalatRuang('Gagal memuat detail ruangan. Coba segarkan kembali.');
                })
                .finally(() => {
                    setMemuatRuang(false);
                });
        },
        [ruang_url_template],
    );

    // Toast menutup sendiri. Papan ini dibaca sambil berdiri; pemberitahuan
    // yang menumpuk sampai ditutup satu per satu menjadi daftar yang harus
    // dibersihkan sebelum angkanya bisa dibaca.
    useEffect(() => {
        if (toast.length === 0) {
            return;
        }

        const jam = window.setTimeout(() => {
            setToast((lama) => lama.slice(0, -1));
        }, UMUR_TOAST_MS);

        return () => window.clearTimeout(jam);
    }, [toast]);

    const angka = potret?.angka;
    const sesi = potret?.sesi;

    const progres = useMemo(() => {
        if (angka === undefined || angka.peserta === 0) {
            return 0;
        }

        return Math.round(((angka.mengerjakan + angka.selesai) / angka.peserta) * 100);
    }, [angka]);

    const ruangTersaring = useMemo(() => {
        const daftar = potret?.ruang ?? [];
        const q = cariRuang.trim().toLowerCase();

        return daftar.filter((r) => {
            if (filterRuang === 'terkunci' && r.terkunci <= 0) {
                return false;
            }
            if (filterRuang === 'terputus' && r.terputus <= 0) {
                return false;
            }
            if (filterRuang === 'laporan' && r.laporan === 'selesai') {
                return false;
            }
            if (q !== '') {
                const cocok =
                    r.kode.toLowerCase().includes(q) ||
                    (r.nama ?? '').toLowerCase().includes(q) ||
                    r.pengawas.some((p) => p.toLowerCase().includes(q));
                if (!cocok) {
                    return false;
                }
            }

            return true;
        });
    }, [potret?.ruang, cariRuang, filterRuang]);

    const daftarSesi = potret?.daftar_sesi ?? [];
    const interaktif = Boolean(ruang_url_template);

    return (
        <>
            <Head title={`Pantau ujian · ${potret?.sekolah ?? slug}`} />

            <div className="min-h-screen bg-paper">
                <div className="mx-auto flex min-h-screen w-full max-w-[64rem] flex-col sm:border-x sm:border-line">
                    <header className="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-line bg-surface px-3 py-2.5">
                        {kembali_url && (
                            <Link
                                href={kembali_url}
                                className="text-xs text-ink-3 hover:text-ink"
                            >
                                ← Kembali ke event
                            </Link>
                        )}

                        <span className="truncate text-xs font-medium text-ink">
                            {potret?.event
                                ? `${potret.sekolah} · ${potret.event.nama}`
                                : (potret?.sekolah ?? slug)}
                        </span>

                        {sesi && (
                            <Chip nada="info">
                                {[sesi.mapel, sesi.label].filter(Boolean).join(' · ')}
                            </Chip>
                        )}

                        <span className="ml-auto flex flex-wrap items-center gap-2">
                            <PenandaHidup keadaan={jeda ? 'kosong' : (potret?.keadaan ?? 'kosong')} />
                            <span className="font-mono text-[11px] text-ink-3">
                                {potret?.diperbarui_jam ?? '—'}
                            </span>
                            {interaktif && (
                                <span className="flex items-center gap-1 border-l border-line pl-2">
                                    <button
                                        type="button"
                                        onClick={() => void tarikPotret(sesiId)}
                                        className="rounded-wf border border-line bg-surface px-2 py-0.5 text-[11px] text-ink-2 hover:bg-surface-2"
                                        title="Segarkan data sekarang"
                                    >
                                        Segarkan
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setJeda((v) => !v)}
                                        className="rounded-wf border border-line bg-surface px-2 py-0.5 text-[11px] text-ink-2 hover:bg-surface-2"
                                        title={jeda ? 'Lanjutkan pembaruan otomatis' : 'Jeda pembaruan otomatis'}
                                    >
                                        {jeda ? 'Lanjut' : 'Jeda'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={toggleLayarPenuh}
                                        className="hidden rounded-wf border border-line bg-surface px-2 py-0.5 text-[11px] text-ink-2 hover:bg-surface-2 sm:inline-block"
                                        title="Mode layar penuh untuk Layar PID"
                                    >
                                        {layarPenuh ? 'Keluar penuh' : 'Layar penuh'}
                                    </button>
                                </span>
                            )}
                        </span>
                    </header>

                    {daftarSesi.length > 1 && (
                        <div className="border-b border-line bg-surface-2/40 px-3 py-2">
                            <div className="max-w-md">
                                <PilihCari
                                    label="Sesi"
                                    nilai={sesi?.id ?? sesiId}
                                    pilihan={daftarSesi.map((s) => ({
                                        id: s.id,
                                        label: [s.mapel, s.label].filter(Boolean).join(' · '),
                                        ket: [s.tanggal, s.jam].filter(Boolean).join(' · ') || null,
                                    }))}
                                    padaPilih={(id) => {
                                        if (id !== null) {
                                            pilihSesi(id);
                                        }
                                    }}
                                    placeholder="Pilih sesi ujian…"
                                    kosong="Sesi tidak ditemukan."
                                />
                            </div>
                        </div>
                    )}

                    <main className="relative flex-1 px-3 py-4">
                        {potret === null || !potret.ada ? (
                            <Kotak>
                                <p className="py-8 text-center text-sm text-ink">
                                    Belum ada sesi ujian yang bisa ditampilkan.
                                </p>
                                <p className="pb-6 text-center text-xs leading-relaxed text-ink-3">
                                    Papan ini menampilkan sesi yang sedang berjalan, sesi
                                    terdekat, atau sesi terakhir bila semuanya sudah ditutup.
                                </p>
                            </Kotak>
                        ) : (
                            <div className="space-y-3">
                                {/* ---------- kepala sesi ---------- */}
                                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                    <span className="font-display text-sm font-semibold text-ink">
                                        {(() => {
                                            const mapel = sesi?.mapel?.trim() ?? '';
                                            const ujian = sesi?.ujian?.trim() ?? '';
                                            if (ujian && mapel && ujian.toLowerCase().includes(mapel.toLowerCase())) {
                                                return ujian;
                                            }
                                            return [mapel, ujian].filter(Boolean).join(' · ') || 'Ujian';
                                        })()}
                                    </span>
                                    <span className="font-mono text-[11px] text-ink-2">
                                        {sesi?.label} · {sesi?.tanggal} · {sesi?.jam}
                                        {sesi?.selesai ? `–${sesi.selesai}` : ''}
                                    </span>
                                </div>

                                {/* ---------- angka ---------- */}
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                                    <KPI label="Peserta" nilai={angka?.peserta ?? 0} />
                                    <KPI
                                        label="Mengerjakan"
                                        nilai={angka?.mengerjakan ?? 0}
                                        nada="info"
                                    />
                                    <KPI label="Selesai" nilai={angka?.selesai ?? 0} nada="ok" />
                                    <KPI label="Belum mulai" nilai={angka?.belum_mulai ?? 0} />
                                    <KPI
                                        label="Terputus"
                                        nilai={angka?.terputus ?? 0}
                                        nada={(angka?.terputus ?? 0) > 0 ? 'warn' : undefined}
                                    />
                                    <KPI
                                        label="Terkunci"
                                        nilai={angka?.terkunci ?? 0}
                                        nada={(angka?.terkunci ?? 0) > 0 ? 'bad' : undefined}
                                    />
                                </div>

                                {/* ---------- bilah ---------- */}
                                <div>
                                    <div className="h-1.5 overflow-hidden rounded-full bg-surface-2">
                                        <div
                                            className="h-full bg-accent transition-[width] duration-500"
                                            style={{ width: `${progres}%` }}
                                        />
                                    </div>
                                    <p className="mt-1 text-[11px] text-ink-3">
                                        {progres}% dari {angka?.peserta ?? 0} peserta sudah
                                        memegang atau menyelesaikan soalnya.
                                    </p>
                                </div>

                                {potret.keadaan === 'menunggu' && (
                                    <Kotak>
                                        <p className="text-sm text-ink">
                                            {sesi?.label} dibuka pukul {sesi?.jam}.
                                        </p>
                                        <p className="mt-1 text-xs leading-relaxed text-ink-3">
                                            Angka nol di atas adalah keadaan yang sebenarnya.
                                            Papan ini mulai bergerak saat peserta pertama
                                            menekan Mulai.
                                        </p>
                                    </Kotak>
                                )}

                                {potret.keadaan === 'selesai' && (
                                    <Kotak>
                                        <p className="text-sm text-ink">
                                            {sesi?.label} sudah ditutup
                                            {sesi?.selesai ? ` pukul ${sesi.selesai}` : ''}.
                                        </p>
                                        <p className="mt-1 text-xs leading-relaxed text-ink-3">
                                            Angka di atas adalah keadaan terakhirnya dan tidak
                                            berubah lagi.
                                        </p>
                                    </Kotak>
                                )}

                                {/* ---------- filter ruang ---------- */}
                                {interaktif && potret.ruang.length > 0 && (
                                    <div className="flex flex-wrap items-center gap-2">
                                        <input
                                            type="search"
                                            value={cariRuang}
                                            onChange={(e) => setCariRuang(e.target.value)}
                                            placeholder="Cari kode/nama ruang atau pengawas..."
                                            className={kelasInput + ' max-w-xs py-1 text-xs'}
                                        />
                                        <div className="flex flex-wrap items-center gap-1">
                                            {(
                                                [
                                                    ['semua', 'Semua'],
                                                    ['terkunci', 'Ada terkunci'],
                                                    ['terputus', 'Ada terputus'],
                                                    ['laporan', 'Laporan belum selesai'],
                                                ] as const
                                            ).map(([kunci, label]) => (
                                                <button
                                                    key={kunci}
                                                    type="button"
                                                    onClick={() => setFilterRuang(kunci)}
                                                    className={
                                                        'rounded-wf border px-2.5 py-1 text-[11px] ' +
                                                        (filterRuang === kunci
                                                            ? 'border-accent bg-accent-soft font-medium text-accent'
                                                            : 'border-line bg-surface text-ink-2 hover:bg-surface-2')
                                                    }
                                                >
                                                    {label}
                                                </button>
                                            ))}
                                        </div>
                                        <span className="ml-auto text-[11px] text-ink-3">
                                            Klik baris ruang untuk cek presensi, status siswa, dan berita acara.
                                        </span>
                                    </div>
                                )}

                                {/* ---------- per ruang ---------- */}
                                <div className="overflow-hidden rounded-wf border border-line bg-surface">
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[34rem] text-sm">
                                            <thead>
                                                <tr className="border-b border-line bg-surface-2/50 text-left">
                                                    <Th>Ruang</Th>
                                                    <Th className="hidden sm:table-cell">
                                                        Pengawas
                                                    </Th>
                                                    <Th className="hidden md:table-cell">
                                                        Progres
                                                    </Th>
                                                    <Th className="text-right">Kerja</Th>
                                                    <Th className="text-right">Selesai</Th>
                                                    <Th className="text-right">Terputus</Th>
                                                    <Th className="text-right">Kunci</Th>
                                                    <Th>Laporan</Th>
                                                    {interaktif && <Th className="w-20 text-right"> </Th>}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {ruangTersaring.length === 0 ? (
                                                    <tr>
                                                        <td
                                                            colSpan={interaktif ? 9 : 8}
                                                            className="px-3 py-6 text-center text-xs text-ink-3"
                                                        >
                                                            Tidak ada ruang yang cocok dengan saringan.
                                                        </td>
                                                    </tr>
                                                ) : (
                                                    ruangTersaring.map((r) => {
                                                        const label = LABEL_LAPORAN[r.laporan];

                                                        return (
                                                            <tr
                                                                key={r.id}
                                                                onClick={
                                                                    interaktif
                                                                        ? () => muatDetailRuang(r)
                                                                        : undefined
                                                                }
                                                                className={
                                                                    'border-b border-line/50 last:border-0 ' +
                                                                    (interaktif
                                                                        ? 'cursor-pointer transition-colors hover:bg-surface-2/70'
                                                                        : '')
                                                                }
                                                            >
                                                            <td className="px-3 py-2">
                                                                <span className="font-mono text-xs font-medium text-ink">
                                                                    {r.kode}
                                                                </span>
                                                                {r.nama && (
                                                                    <span className="mt-0.5 block text-[11px] text-ink-3">
                                                                        {r.nama}
                                                                    </span>
                                                                )}
                                                            </td>
                                                            <td className="hidden px-3 py-2 text-xs sm:table-cell">
                                                                {r.pengawas.length === 0 ? (
                                                                    <span className="text-warn">
                                                                        belum ditetapkan
                                                                    </span>
                                                                ) : (
                                                                    <span className="text-ink-2">
                                                                        {r.pengawas.join(', ')}
                                                                    </span>
                                                                )}
                                                            </td>
                                                            <td className="hidden px-3 py-2 md:table-cell">
                                                                <Bilah
                                                                    bagian={r.mengerjakan + r.selesai}
                                                                    total={r.peserta}
                                                                />
                                                            </td>
                                                            <td className="px-3 py-2 text-right font-mono text-xs text-ink-2">
                                                                {r.mengerjakan}
                                                            </td>
                                                            <td className="px-3 py-2 text-right font-mono text-xs text-ink-2">
                                                                {r.selesai}
                                                            </td>
                                                            <td className="px-3 py-2 text-right font-mono text-xs">
                                                                <span className={r.terputus > 0 ? 'text-warn' : 'text-ink-3'}>
                                                                    {r.terputus}
                                                                </span>
                                                            </td>
                                                            <td className="px-3 py-2 text-right font-mono text-xs">
                                                                <span className={r.terkunci > 0 ? 'text-bad font-semibold' : 'text-ink-3'}>
                                                                    {r.terkunci}
                                                                </span>
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <Chip nada={label.nada}>
                                                                    {label.teks}
                                                                </Chip>
                                                            </td>
                                                            {interaktif && (
                                                                <td className="px-3 py-2 text-right">
                                                                    <span className="text-xs font-medium text-accent">
                                                                        Detail →
                                                                    </span>
                                                                </td>
                                                            )}
                                                        </tr>
                                                    );
                                                })
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-ink-3">
                                    <span>
                                        Laporan lengkap:{' '}
                                        <span className="font-mono text-ink-2">
                                            {potret.laporan.selesai}/{potret.laporan.total}
                                        </span>{' '}
                                        ruang
                                    </span>
                                    <span>
                                        &ldquo;Terputus&rdquo; dihitung dari kabar terakhir
                                        peserta, lebih dari 90 detik tanpa kabar.
                                    </span>
                                </div>

                                <p className="px-1 pt-1 text-[11px] leading-relaxed text-ink-3">
                                    Halaman ini hanya memuat angka, kode ruang, nama pengawas,
                                    jam, dan keadaan berita acara. Nama siswa, nomor kursi,
                                    token, dan isi berita acara tidak pernah tayang di sini.
                                </p>
                            </div>
                        )}

                        {galat && (
                            <p className="mt-3 text-[11px] text-ink-3">
                                Angka di atas belum diperbarui. Jaringan papan ini terputus.
                                Ia akan menyambung sendiri begitu tersedia lagi.
                            </p>
                        )}

                        {/* Toast: melayang di kanan bawah pada layar lebar, di
                            atas pada ponsel. Tumpukannya dibatasi tiga - papan
                            ini dibuka untuk melihat ANGKA. */}
                        <div
                            className="pointer-events-none fixed inset-x-3 bottom-3 z-20 flex flex-col items-end gap-2 sm:inset-x-auto sm:right-4 sm:bottom-4 sm:left-auto sm:w-[22rem]"
                            aria-live="polite"
                            aria-atomic="false"
                        >
                            {toast.map((t) => (
                                <div
                                    key={t.id}
                                    className={
                                        'pointer-events-auto w-full rounded-wf border bg-surface px-3 py-2 shadow-wf ' +
                                        (t.jenis === 'bahaya'
                                            ? 'border-bad/40 border-l-[3px] border-l-bad'
                                            : t.jenis === 'peringatan'
                                              ? 'border-warn/40 border-l-[3px] border-l-warn'
                                              : 'border-ok/40 border-l-[3px] border-l-ok')
                                    }
                                >
                                    <div className="flex items-start gap-2">
                                        <span
                                            className={
                                                'mt-0.5 grid size-4 shrink-0 place-items-center rounded-full font-mono text-[9px] font-bold text-on-accent ' +
                                                (t.jenis === 'bahaya'
                                                    ? 'bg-bad'
                                                    : t.jenis === 'peringatan'
                                                      ? 'bg-warn'
                                                      : 'bg-ok')
                                            }
                                            aria-hidden="true"
                                        >
                                            {t.jenis === 'bahaya' ? '!' : t.jenis === 'peringatan' ? '~' : '✓'}
                                        </span>

                                        <div className="min-w-0 flex-1">
                                            <p className="text-xs font-semibold text-ink">
                                                {t.judul}
                                            </p>
                                            <p className="mt-0.5 text-[11px] leading-relaxed text-ink-2">
                                                {t.pesan}
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() =>
                                                setToast((lama) => lama.filter((x) => x.id !== t.id))
                                            }
                                            className="shrink-0 rounded px-1 text-xs text-ink-3 hover:text-ink"
                                            aria-label="Tutup pemberitahuan"
                                        >
                                            ×
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </main>
                </div>
            </div>

            {ruangAktif && (
                <ModalDetailRuang
                    ruang={ruangAktif}
                    detail={detailRuang}
                    memuat={memuatRuang}
                    galat={galatRuang}
                    padaSegarkan={() => muatDetailRuang(ruangAktif)}
                    padaTutup={() => {
                        setRuangAktif(null);
                        setDetailRuang(null);
                        setGalatRuang(null);
                    }}
                />
            )}
        </>
    );
}

/* ------------------------------------------------------------- potongan */

function PenandaHidup({ keadaan }: { keadaan: Potret['keadaan'] }) {
    const hidup = keadaan === 'berjalan';

    return (
        <span
            className={
                'inline-flex items-center gap-1.5 font-mono text-[10px] tracking-wide uppercase ' +
                (hidup ? 'text-bad' : 'text-ink-3')
            }
        >
            <span
                className={
                    'inline-block size-1.5 rounded-full ' +
                    (hidup ? 'animate-pulse bg-bad' : 'bg-ink-3/50')
                }
                aria-hidden="true"
            />
            {hidup ? 'langsung' : keadaan === 'selesai' ? 'berhenti' : 'menunggu'}
        </span>
    );
}

function KPI({
    label,
    nilai,
    nada,
}: {
    label: string;
    nilai: number;
    nada?: 'ok' | 'warn' | 'bad' | 'info';
}) {
    const warna = nada
        ? { ok: 'text-ok', warn: 'text-warn', bad: 'text-bad', info: 'text-info' }[nada]
        : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-3 py-2">
            <p className="text-[11px] leading-tight text-ink-3">{label}</p>
            <p className={'mt-0.5 font-mono text-xl font-medium tabular-nums ' + warna}>
                {nilai.toLocaleString('id-ID')}
            </p>
        </div>
    );
}

function Bilah({ bagian, total }: { bagian: number; total: number }) {
    const persen = total === 0 ? 0 : Math.round((bagian / total) * 100);

    return (
        <span className="block h-1 w-16 overflow-hidden rounded-full bg-surface-2">
            <span className="block h-full bg-accent" style={{ width: `${persen}%` }} />
        </span>
    );
}

function Th({ children, className }: { children: React.ReactNode; className?: string }) {
    return (
        <th
            className={
                'px-3 py-2 text-[11px] font-medium text-ink-3 ' + (className ?? '')
            }
        >
            {children}
        </th>
    );
}

function ModalDetailRuang({
    ruang,
    detail,
    memuat,
    galat,
    padaSegarkan,
    padaTutup,
}: {
    ruang: Ruang;
    detail: DetailRuang | null;
    memuat: boolean;
    galat: string | null;
    padaSegarkan: () => void;
    padaTutup: () => void;
}) {
    const [tab, setTab] = useState<'peserta' | 'berita'>('peserta');
    const [cari, setCari] = useState('');
    const [saring, setSaring] = useState<
        'semua' | 'hadir' | 'belum_presensi' | 'mengerjakan' | 'selesai' | 'terputus' | 'terkunci'
    >('semua');

    useEffect(() => {
        const dengar = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                padaTutup();
            }
        };

        document.addEventListener('keydown', dengar);

        return () => document.removeEventListener('keydown', dengar);
    }, [padaTutup]);

    const pesertaTersaring = useMemo(() => {
        const daftar = detail?.peserta ?? [];
        const q = cari.trim().toLowerCase();

        return daftar.filter((p) => {
            if (saring === 'hadir' && p.presensi !== 'hadir' && p.presensi !== 'terlambat') {
                return false;
            }
            if (saring === 'belum_presensi' && p.presensi !== 'pending') {
                return false;
            }
            if (saring === 'mengerjakan' && p.status !== 'berjalan') {
                return false;
            }
            if (saring === 'selesai' && p.status !== 'dikirim' && p.status !== 'kedaluwarsa') {
                return false;
            }
            if (saring === 'terputus' && p.terhubung !== false) {
                return false;
            }
            if (saring === 'terkunci' && !p.terkunci) {
                return false;
            }
            if (q !== '') {
                const cocok =
                    p.nama.toLowerCase().includes(q) ||
                    (p.nis ?? '').toLowerCase().includes(q);
                if (!cocok) {
                    return false;
                }
            }

            return true;
        });
    }, [detail?.peserta, cari, saring]);

    const ba = detail?.berita_acara;
    const labelBa = ba ? LABEL_LAPORAN[ba.keadaan] : LABEL_LAPORAN[ruang.laporan];

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-3 sm:p-5"
            onClick={padaTutup}
            role="dialog"
            aria-modal="true"
            aria-label={`Detail ruang ${ruang.kode}`}
        >
            <div
                className="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-wf border border-line bg-surface shadow-xl"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-mono text-sm font-semibold text-ink">
                                {ruang.kode}
                            </span>
                            {ruang.nama && (
                                <span className="text-sm font-medium text-ink">{ruang.nama}</span>
                            )}
                            {detail?.ruang.lokasi && (
                                <span className="text-xs text-ink-3">· {detail.ruang.lokasi}</span>
                            )}
                            <Chip nada={labelBa.nada}>Laporan: {labelBa.teks}</Chip>
                        </div>
                        <p className="mt-0.5 text-xs text-ink-3">
                            Pengawas:{' '}
                            <span className="text-ink-2">
                                {ruang.pengawas.length > 0 ? ruang.pengawas.join(', ') : 'belum ditetapkan'}
                            </span>
                            {detail?.ruang.jam && (
                                <span className="ml-2 font-mono">· {detail.ruang.jam}</span>
                            )}
                            {detail?.diperbarui_jam && (
                                <span className="ml-2 font-mono">· diperbarui {detail.diperbarui_jam}</span>
                            )}
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Tombol onClick={padaSegarkan} disabled={memuat}>
                            {memuat ? 'Memuat...' : 'Segarkan'}
                        </Tombol>
                        {detail?.admin_monitoring_url && (
                            <a
                                href={detail.admin_monitoring_url}
                                target="_blank"
                                rel="noreferrer"
                                className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
                            >
                                Monitoring Admin ↗
                            </a>
                        )}
                        <button
                            type="button"
                            onClick={padaTutup}
                            className="rounded-wf border border-line px-2.5 py-1 text-sm text-ink-3 hover:bg-surface-2 hover:text-ink"
                            aria-label="Tutup detail ruang"
                        >
                            ×
                        </button>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto p-4">
                    {galat && (
                        <div className="mb-3 rounded-wf border border-bad/40 bg-bad/5 px-3 py-2 text-xs text-bad">
                            {galat}
                        </div>
                    )}

                    {memuat && !detail ? (
                        <p className="py-12 text-center text-xs text-ink-3">
                            Memuat presensi siswa, status pengerjaan, dan berita acara...
                        </p>
                    ) : detail ? (
                        <div className="space-y-3">
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-8">
                                <KPI label="Peserta" nilai={detail.angka.peserta} />
                                <KPI label="Hadir" nilai={detail.angka.hadir} nada="ok" />
                                <KPI label="Belum presensi" nilai={detail.angka.belum_presensi} />
                                <KPI
                                    label="Tidak hadir"
                                    nilai={detail.angka.tidak_hadir}
                                    nada={detail.angka.tidak_hadir > 0 ? 'warn' : undefined}
                                />
                                <KPI label="Kerja" nilai={detail.angka.mengerjakan} nada="info" />
                                <KPI label="Selesai" nilai={detail.angka.selesai} nada="ok" />
                                <KPI
                                    label="Terputus"
                                    nilai={detail.angka.terputus}
                                    nada={detail.angka.terputus > 0 ? 'warn' : undefined}
                                />
                                <KPI
                                    label="Terkunci"
                                    nilai={detail.angka.terkunci}
                                    nada={detail.angka.terkunci > 0 ? 'bad' : undefined}
                                />
                            </div>

                            <div className="flex gap-1 border-b border-line">
                                {(
                                    [
                                        ['peserta', `Presensi & Status Siswa (${detail.peserta.length})`],
                                        ['berita', 'Berita Acara'],
                                    ] as const
                                ).map(([kunci, teks]) => (
                                    <button
                                        key={kunci}
                                        type="button"
                                        onClick={() => setTab(kunci)}
                                        className={
                                            '-mb-px border-b-2 px-3 py-2 text-xs font-medium ' +
                                            (tab === kunci
                                                ? 'border-accent text-accent'
                                                : 'border-transparent text-ink-3 hover:text-ink')
                                        }
                                    >
                                        {teks}
                                    </button>
                                ))}
                            </div>

                            {tab === 'peserta' ? (
                                <div className="space-y-2.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <input
                                            type="search"
                                            value={cari}
                                            onChange={(e) => setCari(e.target.value)}
                                            placeholder="Cari nama atau NIS siswa..."
                                            className={kelasInput + ' max-w-xs py-1 text-xs'}
                                        />
                                        <div className="flex flex-wrap gap-1">
                                            {(
                                                [
                                                    ['semua', 'Semua'],
                                                    ['hadir', 'Hadir'],
                                                    ['belum_presensi', 'Belum presensi'],
                                                    ['mengerjakan', 'Mengerjakan'],
                                                    ['selesai', 'Selesai'],
                                                    ['terputus', 'Terputus'],
                                                    ['terkunci', 'Terkunci'],
                                                ] as const
                                            ).map(([kunci, teks]) => (
                                                <button
                                                    key={kunci}
                                                    type="button"
                                                    onClick={() => setSaring(kunci)}
                                                    className={
                                                        'rounded-wf border px-2 py-0.5 text-[11px] ' +
                                                        (saring === kunci
                                                            ? 'border-accent bg-accent-soft font-medium text-accent'
                                                            : 'border-line bg-surface text-ink-2 hover:bg-surface-2')
                                                    }
                                                >
                                                    {teks}
                                                </button>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="overflow-hidden rounded-wf border border-line">
                                        <div className="overflow-x-auto">
                                            <table className="w-full min-w-[34rem] text-xs">
                                                <thead>
                                                    <tr className="border-b border-line bg-surface-2/50 text-left">
                                                        <Th>Siswa</Th>
                                                        <Th>Presensi</Th>
                                                        <Th>Status Ujian</Th>
                                                        <Th>Progres</Th>
                                                        <Th className="text-right">Pelanggaran</Th>
                                                        <Th className="text-right">Denyut</Th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {pesertaTersaring.length === 0 ? (
                                                        <tr>
                                                            <td
                                                                colSpan={6}
                                                                className="px-3 py-6 text-center text-ink-3"
                                                            >
                                                                Tidak ada peserta yang cocok.
                                                            </td>
                                                        </tr>
                                                    ) : (
                                                        pesertaTersaring.map((p) => (
                                                            <tr
                                                                key={p.id}
                                                                className="border-b border-line/50 last:border-0"
                                                            >
                                                                <td className="px-3 py-2">
                                                                    <div className="font-medium text-ink">
                                                                        {p.nama}
                                                                    </div>
                                                                    {p.nis && (
                                                                        <div className="font-mono text-[10px] text-ink-3">
                                                                            {p.nis}
                                                                        </div>
                                                                    )}
                                                                </td>
                                                                <td className="px-3 py-2">
                                                                    <Chip nada={p.presensi_nada}>
                                                                        {p.presensi_label}
                                                                    </Chip>
                                                                </td>
                                                                <td className="px-3 py-2">
                                                                    <div className="flex flex-wrap items-center gap-1">
                                                                        <Chip nada={p.status_nada}>
                                                                            {p.status_label}
                                                                        </Chip>
                                                                        {p.terkunci && (
                                                                            <Chip nada="bad">Terkunci</Chip>
                                                                        )}
                                                                        {p.terhubung === false && (
                                                                            <Chip nada="warn">Terputus</Chip>
                                                                        )}
                                                                    </div>
                                                                </td>
                                                                <td className="px-3 py-2 font-mono text-ink-2">
                                                                    {p.progres ?? (p.nomor ? `No. ${p.nomor}` : '—')}
                                                                </td>
                                                                <td className="px-3 py-2 text-right font-mono">
                                                                    <span
                                                                        className={
                                                                            p.pelanggaran > 0
                                                                                ? 'font-medium text-warn'
                                                                                : 'text-ink-3'
                                                                        }
                                                                    >
                                                                        {p.pelanggaran}
                                                                    </span>
                                                                </td>
                                                                <td className="px-3 py-2 text-right font-mono text-ink-3">
                                                                    {p.denyut ?? '—'}
                                                                </td>
                                                            </tr>
                                                        ))
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className="space-y-3 rounded-wf border border-line bg-surface-2/30 p-4 text-xs">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium text-ink">Status Berita Acara:</span>
                                            <Chip nada={labelBa.nada}>{labelBa.teks}</Chip>
                                        </div>
                                        {detail.berita_acara.dikirim_pada && (
                                            <span className="font-mono text-ink-3">
                                                Dikirim: {detail.berita_acara.dikirim_pada}
                                            </span>
                                        )}
                                    </div>

                                    <dl className="grid gap-2 sm:grid-cols-2">
                                        <div className="rounded-wf border border-line bg-surface px-3 py-2">
                                            <dt className="text-[11px] text-ink-3">Tanda tangan pengawas</dt>
                                            <dd className="mt-0.5 font-medium text-ink">
                                                {detail.berita_acara.ttd_pengawas
                                                    ? `Sudah (${detail.berita_acara.ttd_oleh ?? 'Pengawas'})`
                                                    : 'Belum ditandatangani'}
                                            </dd>
                                        </div>
                                        <div className="rounded-wf border border-line bg-surface px-3 py-2">
                                            <dt className="text-[11px] text-ink-3">Foto dokumentasi</dt>
                                            <dd className="mt-0.5 font-mono font-medium text-ink">
                                                {detail.berita_acara.foto} berkas
                                            </dd>
                                        </div>
                                        <div className="rounded-wf border border-line bg-surface px-3 py-2">
                                            <dt className="text-[11px] text-ink-3">Pernyataan presensi</dt>
                                            <dd className="mt-0.5 text-ink">
                                                {detail.berita_acara.pernyataan_presensi ? 'Disetujui' : 'Belum'}
                                            </dd>
                                        </div>
                                        <div className="rounded-wf border border-line bg-surface px-3 py-2">
                                            <dt className="text-[11px] text-ink-3">Pernyataan ruang kosong</dt>
                                            <dd className="mt-0.5 text-ink">
                                                {detail.berita_acara.pernyataan_keluar ? 'Disetujui' : 'Belum'}
                                            </dd>
                                        </div>
                                    </dl>

                                    <div className="rounded-wf border border-line bg-surface px-3 py-2">
                                        <p className="text-[11px] text-ink-3">Catatan pengawas</p>
                                        <p className="mt-1 whitespace-pre-wrap text-sm text-ink">
                                            {detail.berita_acara.catatan?.trim() || 'Belum ada catatan.'}
                                        </p>
                                    </div>

                                    {detail.berita_acara.kurang.length > 0 && (
                                        <div className="rounded-wf border border-warn/40 bg-warn/5 px-3 py-2 text-warn">
                                            <p className="font-medium">Yang masih kurang:</p>
                                            <ul className="mt-1 list-disc pl-4">
                                                {detail.berita_acara.kurang.map((k, i) => (
                                                    <li key={i}>{k}</li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
