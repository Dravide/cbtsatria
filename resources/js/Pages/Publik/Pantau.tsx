import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Chip, Kotak } from '@/Components/ui';

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
};

type Potret = {
    ada: boolean;
    keadaan: 'kosong' | 'berjalan' | 'menunggu' | 'selesai';
    sekolah: string;
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
    papan_url: string;
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

export default function Pantau({ slug, potret: awal, denyut_url }: Props) {
    const [potret, setPotret] = useState<Potret | null>(awal);
    const [toast, setToast] = useState<Toast[]>([]);
    const [galat, setGalat] = useState<string | null>(null);

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

    useEffect(() => {
        let batal = false;
        let jam: number | undefined;

        const tarik = () => {
            fetch(denyut_url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
                .then((baru: Potret) => {
                    if (batal) {
                        return;
                    }

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
                    if (!batal) {
                        setGalat('sebelumnya');
                    }
                });
        };

        jam = window.setInterval(tarik, SELANG_DENYUT_MS);

        return () => {
            batal = true;
            if (jam !== undefined) {
                window.clearInterval(jam);
            }
        };
    }, [denyut_url, selisih, tambahToast]);

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

    return (
        <>
            <Head title={`Pantau ujian · ${potret?.sekolah ?? slug}`} />

            <div className="min-h-screen bg-paper">
                <div className="mx-auto flex min-h-screen w-full max-w-[64rem] flex-col sm:border-x sm:border-line">
                    <header className="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-line bg-surface px-3 py-2.5">
                        <span className="truncate text-xs font-medium text-ink">
                            {potret?.sekolah ?? slug}
                        </span>

                        {sesi && (
                            <Chip nada="info">
                                {[sesi.mapel, sesi.label].filter(Boolean).join(' · ')}
                            </Chip>
                        )}

                        <span className="ml-auto flex items-center gap-2">
                            <PenandaHidup keadaan={potret?.keadaan ?? 'kosong'} />
                            <span className="font-mono text-[11px] text-ink-3">
                                {potret?.diperbarui_jam ?? '—'}
                            </span>
                        </span>
                    </header>

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
                                        {[sesi?.mapel, sesi?.ujian].filter(Boolean).join(' · ') ||
                                            'Ujian'}
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
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {potret.ruang.map((r) => {
                                                    const label = LABEL_LAPORAN[r.laporan];

                                                    return (
                                                        <tr
                                                            key={r.id}
                                                            className="border-b border-line/50 last:border-0"
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
                                                        </tr>
                                                    );
                                                })}
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
                                        peserta &mdash; lebih dari 90 detik tanpa kabar.
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
                                Angka di atas belum diperbarui. Jaringan papan ini terputus
                                &mdash; ia akan menyambung sendiri begitu tersedia lagi.
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
