import Ikon from '@/Components/Ikon';
import SuperLayout from '@/Layouts/SuperLayout';

type LogRow = {
    id: number;
    aksi: string;
    aktor: string;
    username: string | null;
    sekolah: string | null;
    ip: string | null;
    metadata: Record<string, unknown> | null;
    waktu: string | null;
};

type Props = {
    logs: LogRow[];
};

export default function Audit({ logs }: Props) {
    return (
        <SuperLayout
            judul="Audit Platform"
            breadcrumb="Super Admin / Audit Platform"
            subjudul="100 aktivitas Super Admin terbaru lintas sekolah"
        >
            <section className="overflow-hidden rounded-wf border border-line bg-surface">
                <div className="flex items-center gap-2 border-b border-line bg-surface-2/60 px-4 py-2.5">
                    <Ikon nama="daftar" ukuran={15} className="text-ink-3" />
                    <h2 className="text-xs font-semibold text-ink">Riwayat Aktivitas</h2>
                </div>
                {logs.length === 0 ? (
                    <p className="px-4 py-10 text-center text-xs text-ink-3">
                        Belum ada catatan aktivitas platform.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="border-b border-line bg-surface-2/40 font-mono text-[10px] uppercase text-ink-3">
                                <tr>
                                    <th className="px-4 py-2.5">Waktu</th>
                                    <th className="px-4 py-2.5">Aktor</th>
                                    <th className="px-4 py-2.5">Aksi</th>
                                    <th className="px-4 py-2.5">Sekolah</th>
                                    <th className="px-4 py-2.5">IP</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {logs.map((l) => (
                                    <tr key={l.id} className="hover:bg-surface-2/40">
                                        <td className="whitespace-nowrap px-4 py-2.5 font-mono text-[11px] text-ink-3">
                                            {l.waktu}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <span className="font-medium text-ink">{l.aktor}</span>
                                            {l.username && (
                                                <span className="ml-1 font-mono text-[10px] text-ink-3">
                                                    ({l.username})
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5 text-ink">{l.aksi}</td>
                                        <td className="px-4 py-2.5 text-ink-2">
                                            {l.sekolah ?? '—'}
                                        </td>
                                        <td className="px-4 py-2.5 font-mono text-[11px] text-ink-3">
                                            {l.ip ?? '—'}
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
