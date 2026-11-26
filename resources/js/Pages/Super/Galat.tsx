import { Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Ikon from '@/Components/Ikon';
import { Chip, Tombol, kelasInput } from '@/Components/ui';
import SuperLayout from '@/Layouts/SuperLayout';

type GalatRow = {
    id: number;
    code: string;
    portal: string;
    portal_label: string;
    status_code: number;
    exception_class: string;
    message: string;
    file: string | null;
    line: number | null;
    url: string | null;
    method: string | null;
    sekolah: string | null;
    pengguna: string | null;
    user_role: string | null;
    occurrences: number;
    last_seen_at: string | null;
    last_seen_human: string | null;
    resolved: boolean;
};

type Props = {
    kpi: {
        terbuka: number;
        hari_ini: number;
        siswa_hari_ini: number;
        selesai_pekan: number;
    };
    items: GalatRow[];
    filter: {
        q: string;
        portal: string;
        status: string;
        http: string;
    };
    portals: Record<string, string>;
};

export default function Galat({ kpi, items, filter, portals }: Props) {
    const [q, setQ] = useState(filter.q);
    const [portal, setPortal] = useState(filter.portal);
    const [status, setStatus] = useState(filter.status);
    const [http, setHttp] = useState(filter.http);

    const terapkan = (next?: Partial<typeof filter>) => {
        const params = {
            q: next?.q ?? q,
            portal: next?.portal ?? portal,
            status: next?.status ?? status,
            http: next?.http ?? http,
        };

        router.get(
            '/super/galat',
            Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '')),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const cari = (e: FormEvent) => {
        e.preventDefault();
        terapkan();
    };

    const pangkas = () => {
        router.post('/super/galat/pangkas', {}, { preserveScroll: true });
    };

    return (
        <SuperLayout
            judul="Log Galat Aplikasi"
            breadcrumb="Super Admin / Log Galat"
            subjudul="Catatan galat terpusat dengan kode referensi per portal (ER-ADM, ER-GR, ER-PGW, ER-SSW, ER-SUP, ER-PUB)"
            aksi={
                <Tombol nada="sek" onClick={pangkas}>
                    Bersihkan selesai (&gt;30 hari)
                </Tombol>
            }
        >
            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <KPI label="Insiden Terbuka" nilai={kpi.terbuka} nada={kpi.terbuka > 0 ? 'bad' : 'ok'} />
                <KPI label="Kejadian Hari Ini" nilai={kpi.hari_ini} />
                <KPI
                    label="Portal Siswa Hari Ini"
                    nilai={kpi.siswa_hari_ini}
                    nada={kpi.siswa_hari_ini > 0 ? 'warn' : 'ok'}
                />
                <KPI label="Selesai Pekan Ini" nilai={kpi.selesai_pekan} nada="ok" />
            </div>

            <form onSubmit={cari} className="mb-4 flex flex-wrap items-center gap-2">
                <input
                    type="search"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Cari kode (ER-ADM-001), pesan, file, atau URL..."
                    className={kelasInput + ' max-w-sm min-w-[220px] flex-1'}
                />
                <select
                    value={portal}
                    onChange={(e) => {
                        setPortal(e.target.value);
                        terapkan({ portal: e.target.value });
                    }}
                    className={kelasInput + ' w-auto'}
                >
                    <option value="">Semua Portal</option>
                    {Object.entries(portals).map(([kode, label]) => (
                        <option key={kode} value={kode}>
                            ER-{kode} · {label}
                        </option>
                    ))}
                </select>
                <select
                    value={status}
                    onChange={(e) => {
                        setStatus(e.target.value);
                        terapkan({ status: e.target.value });
                    }}
                    className={kelasInput + ' w-auto'}
                >
                    <option value="terbuka">Status: Terbuka</option>
                    <option value="selesai">Status: Selesai</option>
                    <option value="semua">Semua Status</option>
                </select>
                <select
                    value={http}
                    onChange={(e) => {
                        setHttp(e.target.value);
                        terapkan({ http: e.target.value });
                    }}
                    className={kelasInput + ' w-auto'}
                >
                    <option value="">Semua HTTP</option>
                    <option value="500">500 Server Error</option>
                    <option value="419">419 Session Expired</option>
                    <option value="403">403 Forbidden</option>
                    <option value="503">503 Maintenance</option>
                </select>
                <Tombol nada="pri" type="submit">
                    Cari
                </Tombol>
            </form>

            <section className="overflow-hidden rounded-wf border border-line bg-surface">
                <div className="flex items-center gap-2 border-b border-line bg-surface-2/60 px-4 py-2.5">
                    <Ikon nama="perisai" ukuran={15} className="text-ink-3" />
                    <h2 className="text-xs font-semibold text-ink">
                        Daftar Insiden ({items.length})
                    </h2>
                </div>

                {items.length === 0 ? (
                    <p className="px-4 py-12 text-center text-xs text-ink-3">
                        Tidak ada catatan galat yang cocok dengan filter ini.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="border-b border-line bg-surface-2/40 font-mono text-[10px] text-ink-3 uppercase">
                                <tr>
                                    <th className="px-4 py-2.5">Kode</th>
                                    <th className="px-4 py-2.5">Portal</th>
                                    <th className="px-4 py-2.5">Galat &amp; Lokasi</th>
                                    <th className="px-4 py-2.5">URL &amp; Konteks</th>
                                    <th className="px-4 py-2.5">Jumlah</th>
                                    <th className="px-4 py-2.5">Terakhir</th>
                                    <th className="px-4 py-2.5">Status</th>
                                    <th className="px-4 py-2.5">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {items.map((item) => (
                                    <tr key={item.id} className="hover:bg-surface-2/40">
                                        <td className="whitespace-nowrap px-4 py-2.5">
                                            <Link
                                                href={`/super/galat/${item.id}`}
                                                className="font-mono text-[11px] font-bold text-bad hover:underline"
                                            >
                                                {item.code}
                                            </Link>
                                            <div className="mt-0.5 font-mono text-[10px] text-ink-3">
                                                HTTP {item.status_code}
                                            </div>
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-2.5">
                                            <Chip nada="mute">{item.portal}</Chip>
                                        </td>
                                        <td className="max-w-md px-4 py-2.5">
                                            <div className="font-semibold text-ink">
                                                {item.exception_class}
                                            </div>
                                            <div className="line-clamp-2 text-ink-2">{item.message}</div>
                                            {item.file && (
                                                <div className="mt-0.5 font-mono text-[10px] text-ink-3">
                                                    {item.file}
                                                    {item.line !== null ? `:${item.line}` : ''}
                                                </div>
                                            )}
                                        </td>
                                        <td className="max-w-xs px-4 py-2.5">
                                            {item.url && (
                                                <div className="truncate font-mono text-[11px] text-ink">
                                                    {item.method ? `${item.method} ` : ''}
                                                    {item.url}
                                                </div>
                                            )}
                                            <div className="mt-0.5 text-[11px] text-ink-3">
                                                {[item.sekolah, item.pengguna].filter(Boolean).join(' · ') || '—'}
                                            </div>
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-2.5 font-mono font-semibold text-ink">
                                            {item.occurrences}×
                                        </td>
                                        <td
                                            className="whitespace-nowrap px-4 py-2.5 font-mono text-[11px] text-ink-3"
                                            title={item.last_seen_at ?? undefined}
                                        >
                                            {item.last_seen_human ?? item.last_seen_at ?? '—'}
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-2.5">
                                            {item.resolved ? (
                                                <Chip nada="ok">Selesai</Chip>
                                            ) : (
                                                <Chip nada="bad">Terbuka</Chip>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-2.5">
                                            <Link
                                                href={`/super/galat/${item.id}`}
                                                className="rounded-wf border border-line bg-surface px-2.5 py-1 text-[11px] font-medium text-ink-2 hover:bg-surface-2"
                                            >
                                                Detail
                                            </Link>
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

function KPI({
    label,
    nilai,
    nada,
}: {
    label: string;
    nilai: number;
    nada?: 'ok' | 'warn' | 'bad';
}) {
    const warna =
        nada === 'bad'
            ? 'text-bad'
            : nada === 'warn'
              ? 'text-warn'
              : nada === 'ok'
                ? 'text-ok'
                : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className={`mt-1 font-mono text-2xl font-semibold ${warna}`}>{nilai}</p>
        </div>
    );
}
