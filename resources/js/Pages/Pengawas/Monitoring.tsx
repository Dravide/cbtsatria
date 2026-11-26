import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import KerangkaPonsel from '@/Layouts/KerangkaPonsel';
import { Chip, Inbox, Modal, Peringatan } from '@/Components/ui';
import { KpiPonsel, KotakPonsel } from '@/Components/ponsel';
import PemindaiQrTeks from '@/Components/PemindaiQrTeks';

/**
 * Plate 15 - monitoring ruang. `/pengawas/ruang/{id}/monitoring`.
 *
 * =====================================================================
 * Fase 6 MEMBACA; yang menulis exam_attempts adalah Fase 7
 * =====================================================================
 * Laman ini dibangun terhadap tabel yang belum punya penulis, dan itu
 * keputusan yang dikunci di awal fase: bentuk yang benar untuk "peserta
 * yang sedang mengerjakan" sudah bisa ditetapkan sekarang, dan
 * menetapkannya sekarang berarti Fase 7 tinggal mengisi kolomnya - bukan
 * mengubah tata letak papan yang sudah dipakai orang di hari-H.
 *
 * Satu hal yang karena itu harus jujur: papan ini menampilkan "belum mulai"
 * untuk semua orang sampai mesin ujian ada. Angka yang dikarang akan jauh
 * lebih buruk daripada papan yang mengaku kosong.
 *
 * ---------------------------------------------------------------------
 * Pengawas hanya melihat siswa di ruangnya
 * ---------------------------------------------------------------------
 * "Nama siswa di ruang lain tidak muncul sama sekali, bukan disamarkan."
 * Daftarnya dibangun dari penempatan di ruang INI - bukan dari daftar
 * attempt seluruh sekolah yang lalu disaring, karena daftar yang disaring
 * adalah daftar yang suatu hari lupa disaring.
 *
 * ---------------------------------------------------------------------
 * Progres dikirim sebagai `null`, bukan 0
 * ---------------------------------------------------------------------
 * "Belum mulai" dan "0 dari 40" adalah dua keadaan yang berbeda, dan yang
 * membedakannya hanya satu hal: apakah ada attempt. Menampilkan 0/40 untuk
 * anak yang belum membuka ujiannya akan membuat pengawas mencarinya.
 */
type Kursi = {
    peserta_id: number;
    kursi: number;
    nama: string;
    nis: string | null;
    kelas: string | null;
    status: string | null;
    status_label: string;
    status_nada: string;
    terhubung: boolean | null;
    progres: string | null;
    nomor_soal?: number | null;
    denyut_pada?: string | null;
    pelanggaran: number;
    terberat: number;
    jenis_pelanggaran: string[];
    terkunci: boolean;
    kunci_alasan: string | null;
};

type Insiden = {
    id: number;
    jenis: string;
    keterangan: string;
    peserta: string | null;
    pelaku: string;
    jam: string | null;
    tanggal: string | null;
    status: string;
    terbuka: boolean;
};

type Props = {
    ruang: {
        id: number;
        kode: string;
        nama: string | null;
        sesi: string | null;
        ujian: string | null;
        berakhir_pada: string | null;
        meja_per_baris?: number;
        kursi_per_meja?: number;
        kapasitas?: number;
    };
    pelaku: string;
    angka: {
        peserta: number;
        mengerjakan: number;
        selesai: number;
        terputus: number;
        pelanggaran: number;
        insiden: number;
    };
    kursi: Kursi[];
    insiden: Insiden[];
    jenis_insiden: Record<string, string>;
    radar_anomali?: {
        kursi_a: number;
        nama_a: string;
        kursi_b: number;
        nama_b: string;
        alasan: string;
    }[];
    sinyal?: {
        id: number;
        jenis: string;
        label: string;
        catatan: string | null;
        status: string;
        jam: string | null;
    }[];
    jenis_sinyal?: Record<string, string>;
};

export default function Monitoring({
    ruang,
    angka,
    kursi,
    insiden,
    jenis_insiden,
    radar_anomali = [],
    sinyal = [],
    jenis_sinyal = {},
}: Props) {
    const [bukaInsiden, setBukaInsiden] = useState(false);
    const [jenis, setJenis] = useState('lain');
    const [paketDarurat, setPaketDarurat] = useState('');
    const [keterangan, setKeterangan] = useState('');
    const [pesertaId, setPesertaId] = useState<string>('');
    const [kirim, setKirim] = useState(false);
    const [tampilan, setTampilan] = useState<'denah' | 'daftar'>('denah');
    const [terbalik, setTerbalik] = useState(false);
    const [autoSegar, setAutoSegar] = useState(true);
    const [terpilihId, setTerpilihId] = useState<number | null>(null);
    const [bukaRadar, setBukaRadar] = useState(false);

    useEffect(() => {
        if (!autoSegar) {
            return;
        }

        const timer = window.setInterval(() => {
            router.reload({
                only: ['angka', 'kursi', 'radar_anomali', 'sinyal'],
            });
        }, 10000);

        return () => window.clearInterval(timer);
    }, [autoSegar]);

    const terkunci = kursi.filter((k) => k.terkunci);
    const terpilih = kursi.find((k) => k.peserta_id === terpilihId) ?? null;

    const perMeja = ruang.kursi_per_meja ?? 2;
    const perBaris = ruang.meja_per_baris ?? 4;
    const maksKursi = Math.max(
        ruang.kapasitas ?? 0,
        ...kursi.map((k) => k.kursi ?? 0),
    );
    const petaKursi = new Map<number, Kursi>();
    for (const k of kursi) {
        if (k.kursi) {
            petaKursi.set(k.kursi, k);
        }
    }
    const jumlahMeja = Math.ceil(maksKursi / perMeja);
    const jumlahBaris = Math.ceil(jumlahMeja / perBaris);
    const urutanBaris = Array.from({ length: jumlahBaris }, (_, i) => i + 1);
    if (terbalik) {
        urutanBaris.reverse();
    }

    const simpanInsiden = (e: React.FormEvent) => {
        e.preventDefault();

        if (keterangan.trim().length < 4) {
            return;
        }

        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/insiden`,
            {
                jenis,
                keterangan,
                peserta_id: pesertaId === '' ? null : Number(pesertaId),
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setKirim(false);
                    setKeterangan('');
                    setPesertaId('');
                    setBukaInsiden(false);
                },
            },
        );
    };

    return (
        <KerangkaPonsel
            judul="Monitoring ruang"
            gelap
            tabbar={{ ruangId: ruang.id, aktif: 'monitoring' }}
            kiri={
                <>
                    <Chip nada="info">{ruang.kode}</Chip>
                    <span className="min-w-0 truncate text-[11px] text-ink-3">
                        {[ruang.ujian, ruang.sesi].filter(Boolean).join(' · ')}
                    </span>
                </>
            }
        >
            <div className="grid grid-cols-2 gap-1">
                <KpiPonsel label="Mengerjakan" nilai={angka.mengerjakan} nada="info" />
                <KpiPonsel label="Selesai" nilai={angka.selesai} nada="ok" />
                <KpiPonsel
                    label="Terputus"
                    nilai={angka.terputus}
                    nada={angka.terputus > 0 ? 'bad' : undefined}
                />
                <KpiPonsel
                    label="Pelanggaran"
                    nilai={angka.pelanggaran}
                    nada={angka.pelanggaran > 0 ? 'warn' : undefined}
                />
            </div>

            <div className="flex flex-wrap items-center justify-between gap-1.5 rounded-wf border border-line bg-surface px-2.5 py-1.5">
                <div className="flex items-center gap-1">
                    {(['denah', 'daftar'] as const).map((m) => (
                        <button
                            key={m}
                            type="button"
                            onClick={() => setTampilan(m)}
                            className={
                                'rounded-wf px-2.5 py-1 text-[11px] font-medium ' +
                                (tampilan === m
                                    ? 'bg-accent text-on-accent'
                                    : 'bg-surface-2 text-ink-2')
                            }
                        >
                            {m === 'denah' ? 'Denah' : 'Daftar'}
                        </button>
                    ))}
                    {tampilan === 'denah' && (
                        <button
                            type="button"
                            onClick={() => setTerbalik((v) => !v)}
                            className={
                                'rounded-wf border px-2 py-1 text-[11px] ' +
                                (terbalik
                                    ? 'border-accent bg-accent/10 text-accent'
                                    : 'border-line bg-surface text-ink-2')
                            }
                            title="Balik perspektif depan/belakang kelas"
                        >
                            Putar 180°
                        </button>
                    )}
                </div>

                <button
                    type="button"
                    onClick={() => setAutoSegar((v) => !v)}
                    className={
                        'rounded-wf border px-2 py-1 text-[10px] font-medium ' +
                        (autoSegar
                            ? 'border-ok/40 bg-ok-bg text-ok'
                            : 'border-line bg-surface-2 text-ink-3')
                    }
                >
                    {autoSegar ? '● Live 10s' : 'Jeda'}
                </button>
            </div>

            {tampilan === 'denah' ? (
                <KotakPonsel solid padat>
                    {kursi.length === 0 ? (
                        <p className="px-1 py-6 text-center text-xs text-ink-3">
                            Belum ada peserta yang ditempatkan di ruang ini.
                        </p>
                    ) : (
                        <div className="space-y-2">
                            {!terbalik && (
                                <div className="rounded-wf border border-dashed border-line bg-surface-2 py-1 text-center text-[10px] font-medium uppercase tracking-wider text-ink-3">
                                    Depan · Meja Pengawas
                                </div>
                            )}

                            <div className="space-y-1.5">
                                {urutanBaris.map((b) => {
                                    const mejaDiBaris = Array.from(
                                        { length: perBaris },
                                        (_, idx) => (b - 1) * perBaris + idx + 1,
                                    ).filter((m) => (m - 1) * perMeja < maksKursi);

                                    if (terbalik) {
                                        mejaDiBaris.reverse();
                                    }

                                    return (
                                        <div
                                            key={b}
                                            className="grid gap-1.5"
                                            style={{
                                                gridTemplateColumns: `repeat(${perBaris}, minmax(0, 1fr))`,
                                            }}
                                        >
                                            {mejaDiBaris.map((m) => {
                                                const kursiMeja = Array.from(
                                                    { length: perMeja },
                                                    (_, kIdx) => (m - 1) * perMeja + kIdx + 1,
                                                ).filter((no) => no <= maksKursi);

                                                if (terbalik) {
                                                    kursiMeja.reverse();
                                                }

                                                return (
                                                    <div
                                                        key={m}
                                                        className="grid grid-cols-2 gap-1 rounded-wf border border-line/70 bg-surface-2/60 p-1"
                                                    >
                                                        {kursiMeja.map((no) => {
                                                            const k = petaKursi.get(no);
                                                            if (!k) {
                                                                return (
                                                                    <div
                                                                        key={no}
                                                                        className="flex min-h-11 flex-col items-center justify-center rounded-wf border border-dashed border-line/60 text-[10px] text-ink-3/60"
                                                                    >
                                                                        <span>{String(no).padStart(2, '0')}</span>
                                                                        <span>·</span>
                                                                    </div>
                                                                );
                                                            }

                                                            const aktif = terpilihId === k.peserta_id;
                                                            let warna = 'border-line bg-surface text-ink-3';
                                                            let isiTengah = '—';

                                                            if (k.terkunci) {
                                                                warna = 'border-bad bg-bad-bg text-bad';
                                                                isiTengah = '🔒';
                                                            } else if (k.terhubung === false) {
                                                                warna = 'border-warn bg-warn-bg text-warn';
                                                                isiTengah = '⚡';
                                                            } else if (k.status === 'berjalan') {
                                                                warna = 'border-info bg-info-bg text-info';
                                                                isiTengah = k.nomor_soal ? `#${k.nomor_soal}` : '…';
                                                            } else if (k.status === 'dikirim' || k.status === 'kedaluwarsa') {
                                                                warna = 'border-ok bg-ok-bg text-ok';
                                                                isiTengah = '✓';
                                                            }

                                                            return (
                                                                <button
                                                                    key={no}
                                                                    type="button"
                                                                    onClick={() =>
                                                                        setTerpilihId((id) =>
                                                                            id === k.peserta_id ? null : k.peserta_id,
                                                                        )
                                                                    }
                                                                    className={
                                                                        'flex min-h-11 flex-col items-center justify-center rounded-wf border px-0.5 py-0.5 text-center transition ' +
                                                                        warna +
                                                                        (aktif ? ' ring-2 ring-accent' : '')
                                                                    }
                                                                >
                                                                    <span className="font-mono text-[9px] leading-none opacity-75">
                                                                        {String(no).padStart(2, '0')}
                                                                    </span>
                                                                    <span className="mt-0.5 font-mono text-[11px] font-bold leading-none">
                                                                        {isiTengah}
                                                                    </span>
                                                                </button>
                                                            );
                                                        })}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    );
                                })}
                            </div>

                            {terbalik && (
                                <div className="rounded-wf border border-dashed border-line bg-surface-2 py-1 text-center text-[10px] font-medium uppercase tracking-wider text-ink-3">
                                    Depan · Meja Pengawas
                                </div>
                            )}

                            <div className="flex flex-wrap items-center gap-2 pt-1 text-[10px] text-ink-3">
                                <span><strong className="text-info">#N</strong> Soal aktif</span>
                                <span><strong className="text-warn">⚡</strong> Terputus</span>
                                <span><strong className="text-bad">🔒</strong> Terkunci</span>
                                <span><strong className="text-ok">✓</strong> Selesai</span>
                            </div>
                        </div>
                    )}

                    {terpilih && (
                        <div className="mt-2.5 rounded-wf border border-accent bg-surface p-3">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="text-xs font-semibold text-ink">
                                        Kursi {String(terpilih.kursi).padStart(2, '0')} · {terpilih.nama}
                                    </p>
                                    <p className="text-[11px] text-ink-3">
                                        {[terpilih.nis, terpilih.kelas].filter(Boolean).join(' · ') || 'Peserta'}
                                    </p>
                                </div>
                                <Chip nada={terpilih.status_nada as 'mute'}>
                                    {terpilih.status_label}
                                </Chip>
                            </div>

                            <div className="mt-2 flex flex-wrap gap-1.5 text-[11px] text-ink-2">
                                {terpilih.nomor_soal != null && (
                                    <Chip nada="info">Sedang di Soal #{terpilih.nomor_soal}</Chip>
                                )}
                                {terpilih.progres && (
                                    <Chip nada="mute">Progres {terpilih.progres}</Chip>
                                )}
                                {terpilih.denyut_pada && (
                                    <Chip nada="mute">Denyut {terpilih.denyut_pada}</Chip>
                                )}
                                {terpilih.terhubung === false && <Chip nada="bad">Terputus</Chip>}
                                {terpilih.terkunci && <Chip nada="bad">Diblokir / Terkunci</Chip>}
                            </div>

                            {terpilih.kunci_alasan && (
                                <p className="mt-1.5 text-[11px] text-bad">
                                    Alasan kunci: {terpilih.kunci_alasan}
                                </p>
                            )}

                            <div className="mt-2.5 flex items-center justify-between gap-2">
                                <Link
                                    href={`/pengawas/ruang/${ruang.id}/siswa/${terpilih.peserta_id}`}
                                    className="inline-grid min-h-9 place-items-center rounded-wf border border-accent bg-accent px-3 text-[11px] font-medium text-on-accent"
                                >
                                    {terpilih.terkunci ? 'Detail & Buka Kunci' : 'Lihat Detail Siswa'}
                                </Link>
                                <button
                                    type="button"
                                    onClick={() => setTerpilihId(null)}
                                    className="text-[11px] text-ink-3"
                                >
                                    Tutup
                                </button>
                            </div>
                        </div>
                    )}
                </KotakPonsel>
            ) : (
                <KotakPonsel padat>
                {/* Kepala daftar menyebut kolom yang BENAR-BENAR ada di
                    barisnya. Sebelumnya ia punya kolom "Flag" selebar
                    `shrink-0` yang tidak berpasangan dengan apa pun -
                    penanda (terputus, pelanggaran, dikunci) dirender sebagai
                    chip di bawah nama, bukan sebagai kolom. Ruang `w-6` di
                    kiri memakai lebar kolom nomor kursi supaya kepalanya
                    sejajar dengan barisnya, bukan menggeser satu kolom. */}
                <div className="flex items-center gap-2.5 border-b border-line/70 px-3 py-1.5 text-[11px] text-ink-3">
                    <span className="w-6 shrink-0">No</span>
                    <span className="min-w-0 flex-1">Nama</span>
                    <span className="w-12 shrink-0 text-right">Progres</span>
                </div>

                {kursi.length === 0 && (
                    <p className="px-1 py-6 text-center text-xs text-ink-3">
                        Belum ada peserta yang ditempatkan di ruang ini.
                    </p>
                )}

                {kursi.map((k) => (
                    <div
                        key={k.peserta_id}
                        className={
                            // `relative` + tautan yang melapisi SELURUH baris:
                            // nama siswa adalah satu-satunya jalan ke lamannya,
                            // dan nama selebar ~20px di ponsel adalah sasaran
                            // yang harus dibidik. Barisnya sendiri sudah 52px,
                            // jadi yang diperluas adalah area tekannya, bukan
                            // tata letaknya - kolom progres tetap di atasnya
                            // lewat `relative z-10`.
                            'relative flex items-start gap-2.5 border-b border-line/60 px-3 py-2 last:border-0 ' +
                            (k.terkunci ? 'bg-bad-bg/40' : '')
                        }
                    >
                        <span className="w-6 shrink-0 pt-0.5 font-mono text-[11px] text-ink-3">
                            {String(k.kursi).padStart(2, '0')}
                        </span>

                        <div className="min-w-0 flex-1">
                            <Link
                                href={`/pengawas/ruang/${ruang.id}/siswa/${k.peserta_id}`}
                                className="block truncate text-sm font-medium text-ink after:absolute after:inset-0 after:content-[''] hover:text-accent active:text-accent"
                            >
                                {k.nama}
                            </Link>
                            <div className="mt-1 flex flex-wrap items-center gap-1">
                                <Chip nada={k.status_nada as 'mute'}>{k.status_label}</Chip>
                                {k.terhubung === false && <Chip nada="bad">terputus</Chip>}
                                {k.pelanggaran > 0 && (
                                    <Chip nada={k.terberat >= 3 ? 'bad' : 'warn'}>
                                        {k.jenis_pelanggaran[0] ?? `${k.pelanggaran} pelanggaran`}
                                    </Chip>
                                )}
                                {k.terkunci && <Chip nada="bad">dikunci</Chip>}
                            </div>
                        </div>

                        <span className="w-12 shrink-0 pt-0.5 text-right font-mono text-[11px] text-ink-2">
                            {k.progres ?? '—'}
                        </span>
                    </div>
                ))}
                </KotakPonsel>
            )}

            {terkunci.length > 0 && (
                <Peringatan>
                    {terkunci.map((k) => k.nama).join(', ')} terkunci setelah{' '}
                    {terkunci[0].pelanggaran} pelanggaran. Buka kunci hanya setelah
                    berbicara dengan yang bersangkutan.
                </Peringatan>
            )}

            <KotakPonsel
                padat
                judul="Insiden ruang"
                kanan={
                    <Chip nada={angka.insiden > 0 ? 'warn' : 'mute'}>
                        {angka.insiden} terbuka
                    </Chip>
                }
            >
                {insiden.length === 0 && (
                    <p className="px-1 py-4 text-center text-xs text-ink-3">
                        Belum ada insiden dicatat di ruang ini.
                    </p>
                )}

                {insiden.slice(0, 20).map((i) => (
                    <div
                        key={i.id}
                        className="border-b border-line/60 py-2 last:border-0"
                    >
                        <div className="flex items-center gap-2">
                            <span className="font-mono text-[11px] text-ink-3">{i.jam}</span>
                            <span className="text-xs font-medium text-ink">{i.jenis}</span>
                            <span className="ml-auto">
                                <Chip nada={i.terbuka ? 'warn' : 'ok'}>
                                    {i.terbuka ? 'terbuka' : 'ditangani'}
                                </Chip>
                            </span>
                        </div>
                        <p className="mt-0.5 text-[11px] text-ink-2">{i.keterangan}</p>
                        <p className="text-[11px] text-ink-3">
                            {[i.peserta, i.pelaku, i.tanggal].filter(Boolean).join(' · ')}
                        </p>
                    </div>
                ))}

                {bukaInsiden ? (
                    <form onSubmit={simpanInsiden} className="space-y-1.5 pt-1">
                        <select
                            value={jenis}
                            onChange={(e) => setJenis(e.target.value)}
                            className="min-h-11 w-full rounded-wf border border-line bg-surface px-2 text-xs text-ink"
                        >
                            {Object.entries(jenis_insiden).map(([nilai, label]) => (
                                <option key={nilai} value={nilai}>
                                    {label}
                                </option>
                            ))}
                        </select>

                        <select
                            value={pesertaId}
                            onChange={(e) => setPesertaId(e.target.value)}
                            className="min-h-11 w-full rounded-wf border border-line bg-surface px-2 text-xs text-ink"
                        >
                            <option value="">Seluruh ruang (bukan satu peserta)</option>
                            {kursi.map((k) => (
                                <option key={k.peserta_id} value={k.peserta_id}>
                                    {k.nama}
                                </option>
                            ))}
                        </select>

                        <textarea
                            value={keterangan}
                            onChange={(e) => setKeterangan(e.target.value)}
                            rows={3}
                            placeholder="Apa yang terjadi?"
                            className="w-full rounded-wf border border-line bg-surface px-2 py-1.5 text-xs text-ink outline-none focus:border-accent"
                        />

                        <div className="flex flex-wrap gap-1.5">
                            <button
                                type="submit"
                                disabled={kirim || keterangan.trim().length < 4}
                                className="inline-grid min-h-11 place-items-center rounded-wf border border-accent bg-accent px-3 text-xs font-medium text-on-accent disabled:opacity-50"
                            >
                                Catat insiden
                            </button>
                            <button
                                type="button"
                                onClick={() => setBukaInsiden(false)}
                                className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface px-3 text-xs text-ink-2"
                            >
                                Batal
                            </button>
                        </div>
                    </form>
                ) : (
                    <button
                        type="button"
                        onClick={() => setBukaInsiden(true)}
                        className="inline-grid min-h-11 w-full place-items-center rounded-wf border border-line bg-surface px-3 text-xs text-ink-2"
                    >
                        Catat insiden
                    </button>
                )}
            </KotakPonsel>

            <Inbox>
                Pengawas hanya melihat siswa di ruangnya. Nama siswa di ruang lain tidak
                muncul sama sekali, bukan disamarkan.
            </Inbox>

            {radar_anomali.length > 0 && (
                <>
                    <button
                        type="button"
                        onClick={() => setBukaRadar(true)}
                        className="flex min-h-11 w-full items-center justify-between gap-2 rounded-wf border border-warn bg-warn-bg px-3 py-2 text-left text-xs text-ink"
                    >
                        <span className="font-medium text-warn">
                            Radar anomali denah kursi ({radar_anomali.length})
                        </span>
                        <span className="shrink-0 rounded-wf border border-warn/40 bg-surface px-2.5 py-1 text-[11px] font-medium text-warn">
                            Lihat
                        </span>
                    </button>

                    <Modal
                        judul={`Radar anomali denah kursi (${radar_anomali.length})`}
                        terbuka={bukaRadar}
                        padaTutup={() => setBukaRadar(false)}
                    >
                        <div className="max-h-[65vh] space-y-2 overflow-y-auto pr-1">
                            {radar_anomali.map((r, idx) => (
                                <div
                                    key={`${r.kursi_a}-${r.kursi_b}-${idx}`}
                                    className="rounded-wf border border-warn bg-warn/10 px-3 py-2 text-xs text-ink"
                                >
                                    <p className="font-medium">
                                        Kursi {r.kursi_a} ({r.nama_a}) &amp; Kursi {r.kursi_b} ({r.nama_b})
                                    </p>
                                    <p className="mt-0.5 text-[11px] text-ink-2">{r.alasan}</p>
                                </div>
                            ))}
                        </div>
                    </Modal>
                </>
            )}

            <KotakPonsel
                solid
                padat
                judul="Sinyal teknisi / panitia"
                kanan={<Chip nada="mute">1 ketuk</Chip>}
            >
                <div className="flex flex-wrap gap-1.5">
                    {Object.entries(jenis_sinyal).map(([kode, label]) => (
                        <button
                            key={kode}
                            type="button"
                            disabled={kirim}
                            onClick={() => {
                                setKirim(true);
                                router.post(
                                    `/pengawas/ruang/${ruang.id}/sinyal`,
                                    { jenis: kode },
                                    { preserveScroll: true, onFinish: () => setKirim(false) },
                                );
                            }}
                            className="inline-grid min-h-10 place-items-center rounded-wf border border-line bg-surface-2 px-2.5 text-[11px] font-medium text-ink hover:border-accent"
                        >
                            {label}
                        </button>
                    ))}
                </div>
                {sinyal.length > 0 && (
                    <div className="mt-2 space-y-1">
                        {sinyal.map((s) => (
                            <div
                                key={s.id}
                                className="flex items-center justify-between rounded-wf border border-line bg-surface px-2.5 py-1.5 text-[11px]"
                            >
                                <span>
                                    {s.label} · {s.jam}
                                </span>
                                <Chip nada={s.status === 'aktif' ? 'warn' : 'ok'}>
                                    {s.status === 'aktif' ? 'aktif' : 'ditangani'}
                                </Chip>
                            </div>
                        ))}
                    </div>
                )}
            </KotakPonsel>

            <KotakPonsel solid padat judul="Terima paket darurat offline">
                <p className="text-[11px] text-ink-3">
                    Jika perangkat siswa putus Wi-Fi total, pindai QR darurat atau tempel kode dari layar siswa.
                </p>
                <div className="mt-2">
                    <PemindaiQrTeks
                        labelTombol="Scan QR Darurat"
                        disabled={kirim}
                        padaTeks={(isi) => {
                            setPaketDarurat(isi);
                            setKirim(true);
                            router.post(
                                `/pengawas/ruang/${ruang.id}/qr-darurat`,
                                { paket: isi },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setPaketDarurat(''),
                                    onFinish: () => setKirim(false),
                                },
                            );
                        }}
                    />
                </div>
                <textarea
                    value={paketDarurat}
                    onChange={(e) => setPaketDarurat(e.target.value)}
                    rows={2}
                    placeholder="Atau tempel kode paket darurat siswa..."
                    className="mt-2 w-full rounded-wf border border-line bg-surface px-2 py-1.5 font-mono text-[11px] text-ink outline-none focus:border-accent"
                />
                <button
                    type="button"
                    disabled={kirim || paketDarurat.trim().length < 10}
                    onClick={() => {
                        setKirim(true);
                        router.post(
                            `/pengawas/ruang/${ruang.id}/qr-darurat`,
                            { paket: paketDarurat.trim() },
                            {
                                preserveScroll: true,
                                onSuccess: () => setPaketDarurat(''),
                                onFinish: () => setKirim(false),
                            },
                        );
                    }}
                    className="mt-1.5 inline-grid min-h-10 place-items-center rounded-wf border border-line bg-surface-2 px-3 text-xs font-medium text-ink disabled:opacity-50"
                >
                    Sinkronkan kode teks
                </button>
            </KotakPonsel>
        </KerangkaPonsel>
    );
}
