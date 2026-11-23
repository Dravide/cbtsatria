import { Link } from '@inertiajs/react';
import Ikon, { NamaIkon } from '@/Components/Ikon';
import { Chip } from '@/Components/ui';
import SuperLayout from '@/Layouts/SuperLayout';

type RadarItem = {
    id: number;
    sekolah_id: number;
    sekolah: string;
    papan_slug: string | null;
    event: string | null;
    mapel: string | null;
    label: string;
    jam: string;
    ruang: number;
    mengerjakan: number;
    terkunci: number;
    terputus: number;
};

type Props = {
    kpi: {
        total_sekolah: number;
        sekolah_aktif: number;
        sekolah_tangguh: number;
        ujian_berjalan: number;
        ruang_aktif: number;
        peserta_konkuren: number;
        terkunci: number;
        terputus: number;
        failed_jobs: number;
        queued_jobs: number;
    };
    radar: RadarItem[];
    diperbarui: string;
};

export default function Dashboard({ kpi, radar, diperbarui }: Props) {
    const kartuKpi: {
        label: string;
        nilai: string | number;
        ket: string;
        ikon: NamaIkon;
        href: string;
        warna?: string;
    }[] = [
        {
            label: 'Sekolah terdaftar',
            nilai: kpi.total_sekolah,
            ket: `${kpi.sekolah_aktif} aktif · ${kpi.sekolah_tangguh} ditangguhkan`,
            ikon: 'sekolah',
            href: '/super/sekolah',
        },
        {
            label: 'Sesi berjalan',
            nilai: kpi.ujian_berjalan,
            ket: `${kpi.ruang_aktif} ruang aktif saat ini`,
            ikon: 'denyut',
            href: '/super/sekolah',
            warna: kpi.ujian_berjalan > 0 ? 'text-ok' : undefined,
        },
        {
            label: 'Peserta konkuren',
            nilai: kpi.peserta_konkuren,
            ket: `${kpi.terkunci} terkunci · ${kpi.terputus} terputus`,
            ikon: 'peserta',
            href: '/super/sekolah',
            warna: kpi.peserta_konkuren > 0 ? 'text-accent' : undefined,
        },
        {
            label: 'Antrean & job gagal',
            nilai: kpi.failed_jobs,
            ket: `${kpi.queued_jobs} menunggu di antrean`,
            ikon: 'pengaturan',
            href: '/super/sistem',
            warna: kpi.failed_jobs > 0 ? 'text-bad' : 'text-ok',
        },
    ];

    return (
        <SuperLayout
            judul="Command Center Platform"
            breadcrumb="Super Admin / Beranda"
            subjudul={`Ringkasan lintas ${kpi.total_sekolah} sekolah · diperbarui ${diperbarui}`}
            aksi={
                <>
                    <Link
                        href="/super/pengumuman"
                        className="inline-flex items-center gap-1.5 rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
                    >
                        <Ikon nama="bendera" ukuran={14} />
                        Broadcast Info
                    </Link>
                    <Link
                        href="/super/sekolah"
                        className="inline-flex items-center gap-1.5 rounded-wf border border-accent bg-accent px-3 py-1.5 text-xs font-medium text-on-accent hover:bg-accent/90"
                    >
                        <Ikon nama="tambah" ukuran={14} />
                        Kelola Sekolah
                    </Link>
                </>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {kartuKpi.map((k) => (
                    <Link
                        key={k.label}
                        href={k.href}
                        className="group rounded-wf border border-line bg-surface px-4 py-3 transition-colors duration-150 hover:border-accent/40 hover:bg-accent-soft/30"
                    >
                        <div className="flex items-center gap-2 text-ink-3">
                            <Ikon nama={k.ikon} ukuran={16} />
                            <p className="text-xs">{k.label}</p>
                        </div>
                        <p
                            className={
                                'mt-1.5 font-mono text-2xl font-medium ' + (k.warna ?? 'text-ink')
                            }
                        >
                            {k.nilai}
                        </p>
                        <p className="mt-1 text-[11px] text-ink-3">{k.ket}</p>
                    </Link>
                ))}
            </div>

            <section className="mt-5 overflow-hidden rounded-wf border border-line bg-surface">
                <div className="flex items-center justify-between border-b border-line bg-surface-2/60 px-4 py-2.5">
                    <div className="flex items-center gap-2">
                        <Ikon nama="denyut" ukuran={15} className="text-ink-3" />
                        <h2 className="text-xs font-semibold text-ink">
                            Radar Ujian Berjalan Lintas Sekolah
                        </h2>
                    </div>
                    <Chip nada={radar.length > 0 ? 'info' : 'mute'}>{radar.length} sesi</Chip>
                </div>

                {radar.length === 0 ? (
                    <div className="px-4 py-10 text-center text-xs text-ink-3">
                        Belum ada sesi ujian yang sedang berjalan di sekolah mana pun saat ini.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="border-b border-line bg-surface-2/40 font-mono text-[10px] uppercase text-ink-3">
                                <tr>
                                    <th className="px-4 py-2.5">Sekolah &amp; Sesi</th>
                                    <th className="px-4 py-2.5">Jam</th>
                                    <th className="px-4 py-2.5">Ruang</th>
                                    <th className="px-4 py-2.5">Mengerjakan</th>
                                    <th className="px-4 py-2.5">Kendala</th>
                                    <th className="px-4 py-2.5 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {radar.map((r) => (
                                    <tr key={r.id} className="hover:bg-surface-2/40">
                                        <td className="px-4 py-3">
                                            <p className="font-medium text-ink">{r.sekolah}</p>
                                            <p className="text-[11px] text-ink-3">
                                                {[r.event, r.label, r.mapel].filter(Boolean).join(' · ')}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3 font-mono text-ink-2">{r.jam}</td>
                                        <td className="px-4 py-3 font-mono text-ink-2">{r.ruang}</td>
                                        <td className="px-4 py-3 font-mono font-semibold text-accent">
                                            {r.mengerjakan}
                                        </td>
                                        <td className="px-4 py-3">
                                            {r.terkunci > 0 ? (
                                                <Chip nada="bad">{r.terkunci} terkunci</Chip>
                                            ) : r.terputus > 0 ? (
                                                <Chip nada="warn">{r.terputus} putus</Chip>
                                            ) : (
                                                <Chip nada="ok">Normal</Chip>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="inline-flex gap-1.5">
                                                {r.papan_slug && (
                                                    <a
                                                        href={`/pantau/${r.papan_slug}`}
                                                        target="_blank"
                                                        rel="noopener"
                                                        className="rounded-wf border border-line bg-surface px-2.5 py-1 text-[11px] text-ink hover:bg-surface-2"
                                                    >
                                                        Pantau
                                                    </a>
                                                )}
                                                <Link
                                                    href={`/super/sekolah/${r.sekolah_id}`}
                                                    className="rounded-wf border border-line bg-surface px-2.5 py-1 text-[11px] text-accent hover:bg-surface-2"
                                                >
                                                    Detail
                                                </Link>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </SuperLayout>
    );
}
