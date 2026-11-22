import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Kotak, Tombol, TombolTautan, kelasInput } from '@/Components/ui';

type Baris = {
    peserta_id: number;
    nama: string;
    ruang_id: number;
    ruang: string;
    sesi: string;
    ujian: string | null;
    alasan: string | null;
    pelanggaran: number;
    dikunci_jam: string | null;
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    baris: Baris[];
    angka: {
        terkunci: number;
        ruang_terdampak: number;
    };
};

export default function MonitoringTerkunci({ event, baris, angka }: Props) {
    const [cari, setCari] = useState('');

    const tersaring = baris.filter((b) => {
        const q = cari.trim().toLowerCase();
        if (!q) {
            return true;
        }
        return (
            b.nama.toLowerCase().includes(q) ||
            b.ruang.toLowerCase().includes(q) ||
            b.sesi.toLowerCase().includes(q) ||
            (b.ujian ?? '').toLowerCase().includes(q)
        );
    });

    return (
        <AdminLayout
            judul="Siswa terkunci (ke-block)"
            breadcrumb={`/admin/events · ${event.kode} · Monitoring · Siswa terkunci`}
            aksi={
                <TombolTautan href={`/admin/events/${event.id}/monitoring`}>
                    Kembali ke monitoring
                </TombolTautan>
            }
        >
            <Head title={`Siswa terkunci · ${event.nama}`} />

            <div className="mb-3 grid gap-3 sm:grid-cols-2">
                <div className="rounded-wf border border-line bg-surface px-4 py-3">
                    <p className="text-xs text-ink-3">Siswa terkunci saat ini</p>
                    <p
                        className={
                            'mt-1 font-mono text-2xl font-medium ' +
                            (angka.terkunci > 0 ? 'text-bad' : 'text-ok')
                        }
                    >
                        {angka.terkunci.toLocaleString('id-ID')}
                    </p>
                </div>
                <div className="rounded-wf border border-line bg-surface px-4 py-3">
                    <p className="text-xs text-ink-3">Ruang terdampak</p>
                    <p className="mt-1 font-mono text-2xl font-medium text-ink">
                        {angka.ruang_terdampak.toLocaleString('id-ID')}
                    </p>
                </div>
            </div>

            <div className="mb-3">
                <Kotak>
                    <input
                        type="search"
                        value={cari}
                        onChange={(e) => setCari(e.target.value)}
                        placeholder="Cari nama siswa, ruang (R-01), sesi, atau mapel..."
                        className={kelasInput + ' max-w-md'}
                    />
                </Kotak>
            </div>

            <div className="overflow-x-auto rounded-wf border border-line bg-surface">
                <table className="w-full border-collapse text-left text-xs">
                    <thead>
                        <tr className="border-b border-line bg-surface-2/60 text-[11px] uppercase text-ink-3">
                            <th className="px-3 py-2">Siswa</th>
                            <th className="px-3 py-2">Sesi &amp; Ruang</th>
                            <th className="px-3 py-2">Alasan Terkunci</th>
                            <th className="px-3 py-2">Jam</th>
                            <th className="px-3 py-2 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {tersaring.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="px-3 py-8 text-center text-ink-3">
                                    {baris.length === 0
                                        ? 'Tidak ada siswa yang sedang terkunci (ke-block) pada event ini.'
                                        : 'Tidak ada siswa terkunci yang cocok dengan pencarian.'}
                                </td>
                            </tr>
                        ) : (
                            tersaring.map((b) => (
                                <tr key={b.peserta_id}>
                                    <td className="px-3 py-2.5 font-medium text-ink">{b.nama}</td>
                                    <td className="px-3 py-2.5">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <Chip nada="mute">
                                                {b.sesi} · {b.ruang}
                                            </Chip>
                                            {b.ujian && (
                                                <span className="text-ink-2">{b.ujian}</span>
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2.5 text-ink-2">
                                        {b.alasan ?? 'Melebihi ambang pelanggaran'} (
                                        <span className="font-mono">{b.pelanggaran}×</span>)
                                    </td>
                                    <td className="px-3 py-2.5 font-mono text-ink-3">
                                        {b.dikunci_jam ?? '—'}
                                    </td>
                                    <td className="px-3 py-2.5 text-right">
                                        <div className="inline-flex items-center gap-2">
                                            <Link
                                                href={`/admin/events/${event.id}/monitoring/ruang/${b.ruang_id}`}
                                                className="font-medium text-accent hover:underline"
                                            >
                                                Lihat ruang →
                                            </Link>
                                            <Tombol
                                                nada="pri"
                                                onClick={() =>
                                                    router.post(
                                                        `/admin/events/${event.id}/monitoring/peserta/${b.peserta_id}/buka`,
                                                        { alasan: 'Dibuka dari daftar siswa terkunci' },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Buka kunci
                                            </Tombol>
                                        </div>
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
