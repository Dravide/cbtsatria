import { Link } from '@inertiajs/react';
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
    return (
        <SuperLayout
            judul="Command Center Platform"
            subjudul={`Ringkasan lintas ${kpi.total_sekolah} sekolah · diperbarui ${diperbarui}`}
            aksi={
                <>
                    <Link
                        href="/super/pengumuman"
                        className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
                    >
                        Broadcast Info
                    </Link>
                    <Link
                        href="/super/sekolah"
                        className="rounded-wf bg-accent px-3 py-1.5 text-xs font-medium text-white hover:opacity-90"
                    >
                        + Kelola Sekolah
                    </Link>
                </>
            }
        >
            <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div className="rounded-wf border border-line bg-surface p-4">
                    <p className="font-mono text-[10px] uppercase tracking-wider text-ink-3">
                        Sekolah Terdaftar
                    </p>
                    <p className="mt-1 text-2xl font-bold text-ink">{kpi.total_sekolah}</p>
                    <p className="mt-1 text-xs text-ink-3">
                        {kpi.sekolah_aktif} aktif · {kpi.sekolah_tangguh} ditangguhkan
                    </p>
                </div>

                <div className="rounded-wf border border-line bg-surface p-4">
                    <p className="font-mono text-[10px] uppercase tracking-wider text-ink-3">
                        Sesi Berjalan
                    </p>
                    <p className="mt-1 text-2xl font-bold text-ok">{kpi.ujian_berjalan}</p>
                    <p className="mt-1 text-xs text-ink-3">{kpi.ruang_aktif} ruang aktif saat ini</p>
                </div>

                <div className="rounded-wf border border-line bg-surface p-4">
                    <p className="font-mono text-[10px] uppercase tracking-wider text-ink-3">
                        Peserta Konkuren
                    </p>
                    <p className="mt-1 text-2xl font-bold text-accent">{kpi.peserta_konkuren}</p>
                    <p className="mt-1 text-xs text-ink-3">
                        {kpi.terkunci} terkunci · {kpi.terputus} terputus
                    </p>
                </div>

                <div className="rounded-wf border border-line bg-surface p-4">
                    <p className="font-mono text-[10px] uppercase tracking-wider text-ink-3">
                        Antrean &amp; Job Gagal
                    </p>
                    <p
                        className={
                            'mt-1 text-2xl font-bold ' +
                            (kpi.failed_jobs > 0 ? 'text-danger' : 'text-ok')
                        }
                    >
                        {kpi.failed_jobs} gagal
                    </p>
                    <p className="mt-1 text-xs text-ink-3">{kpi.queued_jobs} job menunggu</p>
                </div>
            </section>

            <section className="overflow-hidden rounded-wf border border-line bg-surface">
                <div className="flex items-center justify-between border-b border-line bg-surface-2 px-4 py-2.5">
                    <h2 className="text-xs font-semibold text-ink">
                        Radar Ujian Berjalan Lintas Sekolah
                    </h2>
                    <span className="font-mono text-[11px] text-ink-3">{radar.length} sesi</span>
                </div>

                {radar.length === 0 ? (
                    <div className="px-4 py-8 text-center text-xs text-ink-3">
                        Belum ada sesi ujian yang sedang berjalan di sekolah mana pun saat ini.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="border-b border-line bg-surface-2 font-mono text-[10px] uppercase text-ink-3">
                                <tr>
                                    <th className="px-4 py-2.5">Sekolah &amp; Sesi</th>
                                    <th className="px-4 py-2.5">Jam</th>
                                    <th className="px-4 py-2.5">Ruang</th>
                                    <th className="px-4 py-2.5">Mengerjakan</th>
                                    <th className="px-4 py-2.5">Kendala</th>
                                    <th className="px-4 py-2.5 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {radar.map((r) => (
                                    <tr key={r.id} className="border-b border-line last:border-b-0">
                                        <td className="px-4 py-3">
                                            <p className="font-semibold text-ink">{r.sekolah}</p>
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
                                                <span className="rounded-full bg-danger-bg px-2 py-0.5 font-mono text-[10px] text-danger">
                                                    {r.terkunci} terkunci
                                                </span>
                                            ) : r.terputus > 0 ? (
                                                <span className="rounded-full bg-warn-bg px-2 py-0.5 font-mono text-[10px] text-warn">
                                                    {r.terputus} putus
                                                </span>
                                            ) : (
                                                <span className="rounded-full bg-ok-bg px-2 py-0.5 font-mono text-[10px] text-ok">
                                                    Normal
                                                </span>
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
