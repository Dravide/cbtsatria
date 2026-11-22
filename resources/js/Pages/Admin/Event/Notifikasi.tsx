import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Kotak, Tombol, kelasInput } from '@/Components/ui';

type Baris = {
    id: number;
    jenis: string;
    tingkat: string;
    judul: string;
    pesan: string;
    konteks_label: string | null;
    tautan: string | null;
    dibaca: boolean;
    waktu: string;
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    angka: {
        total: number;
        belum_dibaca: number;
        terkunci: number;
        ruang_terdampak: number;
    };
    baris: Baris[];
    saring: { jenis: string; status: string };
};

export default function EventNotifikasi({ event, angka, baris, saring }: Props) {
    const jalur = `/admin/events/${event.id}/notifikasi`;

    const terapkan = (baru: Partial<typeof saring>) => {
        router.get(
            jalur,
            { ...saring, ...baru },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AdminLayout
            judul="Log notifikasi event"
            breadcrumb={`/admin/events · ${event.kode} · Notifikasi`}
            aksi={
                angka.belum_dibaca > 0 ? (
                    <Tombol
                        nada="pri"
                        onClick={() =>
                            router.post(`${jalur}/baca-semua`, {}, { preserveScroll: true })
                        }
                    >
                        Tandai semua dibaca
                    </Tombol>
                ) : null
            }
        >
            <Head title={`Notifikasi · ${event.nama}`} />

            <div className="mb-3 grid gap-3 sm:grid-cols-4">
                <KPI label="Total kejadian" nilai={angka.total} />
                <KPI
                    label="Belum dibaca"
                    nilai={angka.belum_dibaca}
                    nada={angka.belum_dibaca > 0 ? 'bad' : 'ok'}
                />
                <KPI
                    label="Peserta terkunci"
                    nilai={angka.terkunci}
                    nada={angka.terkunci > 0 ? 'bad' : 'ok'}
                />
                <KPI label="Ruang terdampak" nilai={angka.ruang_terdampak} />
            </div>

            <div className="mb-3">
                <Kotak>
                    <div className="flex flex-wrap items-center gap-2">
                    <select
                        value={saring.jenis}
                        onChange={(e) => terapkan({ jenis: e.target.value })}
                        className={kelasInput + ' w-48'}
                    >
                        <option value="">Semua jenis</option>
                        <option value="terkunci">Peserta terkunci</option>
                        <option value="pelanggaran">Mendekati ambang</option>
                        <option value="terputus_massal">Terputus massal</option>
                        <option value="insiden">Insiden pengawas</option>
                    </select>

                    <select
                        value={saring.status}
                        onChange={(e) => terapkan({ status: e.target.value })}
                        className={kelasInput + ' w-44'}
                    >
                        <option value="">Semua status</option>
                        <option value="belum">Belum dibaca</option>
                        <option value="dibaca">Sudah dibaca</option>
                    </select>
                </div>
                </Kotak>
            </div>

            <div className="overflow-x-auto rounded-wf border border-line bg-surface">
                <table className="w-full border-collapse text-left text-xs">
                    <thead>
                        <tr className="border-b border-line bg-surface-2/60 text-[11px] uppercase text-ink-3">
                            <th className="px-3 py-2">Waktu</th>
                            <th className="px-3 py-2">Jenis</th>
                            <th className="px-3 py-2">Keterangan</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {baris.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="px-3 py-8 text-center text-ink-3">
                                    Belum ada catatan notifikasi anomali pada event ini.
                                </td>
                            </tr>
                        ) : (
                            baris.map((b) => (
                                <tr key={b.id} className={b.dibaca ? '' : 'bg-accent-soft/35'}>
                                    <td className="px-3 py-2.5 font-mono text-ink-3">{b.waktu}</td>
                                    <td className="px-3 py-2.5">
                                        <Chip
                                            nada={
                                                b.tingkat === 'bad'
                                                    ? 'bad'
                                                    : b.tingkat === 'warn'
                                                      ? 'warn'
                                                      : 'info'
                                            }
                                        >
                                            {b.konteks_label ?? b.judul}
                                        </Chip>
                                    </td>
                                    <td className="px-3 py-2.5">
                                        <div className="font-medium text-ink">{b.judul}</div>
                                        <div className="text-ink-2">{b.pesan}</div>
                                    </td>
                                    <td className="px-3 py-2.5">
                                        <Chip nada={b.dibaca ? 'ok' : 'warn'}>
                                            {b.dibaca ? 'Dibaca' : 'Belum dibaca'}
                                        </Chip>
                                    </td>
                                    <td className="px-3 py-2.5 text-right">
                                        {b.tautan ? (
                                            <Link
                                                href={b.tautan}
                                                className="font-medium text-accent hover:underline"
                                            >
                                                Periksa →
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </AdminLayout>
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
        nada === 'ok' ? 'text-ok' : nada === 'warn' ? 'text-warn' : nada === 'bad' ? 'text-bad' : 'text-ink';
    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className={'mt-1 font-mono text-2xl font-medium ' + warna}>
                {nilai.toLocaleString('id-ID')}
            </p>
        </div>
    );
}
