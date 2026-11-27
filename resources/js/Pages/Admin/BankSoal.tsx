import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { kelasInput, pakaiFilter } from '@/Components/ui';

/**
 * Bank soal versi Admin (Laman 1: Daftar Mata Pelajaran) - BACA SAJA.
 *
 * Alur tiga tingkat:
 * 1. `/admin/bank-soal` -> Daftar mata pelajaran
 * 2. `/admin/bank-soal/mapel/{id}` -> Daftar bank soal milik mapel terpilih
 * 3. Modal detail bank soal -> Pratinjau lengkap soal (baca-saja)
 */

type MapelItem = {
    id: number;
    kode: string | null;
    nama: string;
    total: number;
    siap: number;
    draft: number;
    arsip: number;
    guru: number;
    dipakai: number;
    sebaran: Record<string, number>;
};

type Props = {
    mapel: MapelItem[];
    ringkasan: {
        mapel: number;
        soal: number;
        siap: number;
        dipakai: number;
    };
    filter: {
        cari: string;
    };
    pengguna: string;
};

const WARNA_JENIS: { kunci: string; label: string; kelas: string }[] = [
    { kunci: 'pg', label: 'PG', kelas: 'bg-accent' },
    { kunci: 'pg_kompleks', label: 'PGK', kelas: 'bg-accent/65' },
    { kunci: 'benar_salah', label: 'B/S', kelas: 'bg-accent/40' },
    { kunci: 'isian', label: 'Isian', kelas: 'bg-info/70' },
    { kunci: 'menjodohkan', label: 'Jodoh', kelas: 'bg-info/40' },
    { kunci: 'uraian', label: 'Uraian', kelas: 'bg-warn' },
];

export default function BankSoalAdmin({ mapel, ringkasan, filter, pengguna }: Props) {
    const f = pakaiFilter('/admin/bank-soal', {
        cari: filter.cari,
    });

    return (
        <AdminLayout
            judul="Bank soal"
            breadcrumb={`/admin · Bank soal · ${pengguna}`}
            aksi={
                <span className="text-[11px] text-ink-3">
                    {ringkasan.mapel.toLocaleString('id-ID')} mapel ·{' '}
                    {ringkasan.soal.toLocaleString('id-ID')} soal
                </span>
            }
        >
            <Head title="Bank soal" />

            <p className="mb-3 rounded-wf border border-info/25 bg-info-bg px-3 py-2.5 text-xs leading-relaxed text-info">
                Bank soal adalah aset sekolah. <strong>Melihat</strong> seluruh soal di sini per
                mata pelajaran, dan yang menyusun serta mengubahnya adalah Guru mata pelajaran lewat
                konsolnya.
            </p>

            <div className="mb-4 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                <KpiCard label="Total Mapel" nilai={ringkasan.mapel} />
                <KpiCard label="Total Soal" nilai={ringkasan.soal} />
                <KpiCard label="Status Siap" nilai={ringkasan.siap} nada="ok" />
                <KpiCard label="Dipakai di Event" nilai={ringkasan.dipakai} />
            </div>

            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <input
                    value={f.nilai.cari ?? ''}
                    onChange={(e) => f.ubah('cari', e.target.value)}
                    placeholder="Cari kode atau nama mata pelajaran..."
                    className={kelasInput + ' max-w-xs'}
                />

                <div className="flex flex-wrap items-center gap-3 text-[11px] text-ink-3">
                    {WARNA_JENIS.map((j) => (
                        <span key={j.kunci} className="inline-flex items-center gap-1.5">
                            <span className={`inline-block size-2 rounded-sm ${j.kelas}`} />
                            {j.label}
                        </span>
                    ))}
                </div>
            </div>

            {mapel.length === 0 ? (
                <div className="rounded-wf border border-line bg-surface px-4 py-8 text-center text-sm text-ink-2">
                    Tidak ada mata pelajaran yang cocok dengan pencarian ini.
                </div>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {mapel.map((m) => (
                        <Link
                            key={m.id}
                            href={`/admin/bank-soal/mapel/${m.id}`}
                            className="group flex flex-col justify-between gap-3 rounded-wf border border-line bg-surface p-3.5 transition-colors hover:border-accent hover:bg-surface-2/30"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    {m.kode && (
                                        <span className="inline-block rounded bg-accent-soft px-1.5 py-0.5 font-mono text-[10px] font-medium text-accent">
                                            {m.kode}
                                        </span>
                                    )}
                                    <h2 className="mt-1 truncate text-sm font-semibold text-ink group-hover:text-accent">
                                        {m.nama}
                                    </h2>
                                </div>
                                <div className="shrink-0 text-right">
                                    <div className="font-mono text-lg font-semibold leading-none text-ink">
                                        {m.total.toLocaleString('id-ID')}
                                    </div>
                                    <span className="text-[10px] text-ink-3">soal</span>
                                </div>
                            </div>

                            <div className="space-y-2">
                                <div
                                    className="flex h-1.5 overflow-hidden rounded-full bg-surface-2"
                                    title="Sebaran jenis soal"
                                >
                                    {m.total > 0 &&
                                        WARNA_JENIS.map((j) => {
                                            const n = m.sebaran[j.kunci] ?? 0;
                                            if (n <= 0) {
                                                return null;
                                            }
                                            return (
                                                <span
                                                    key={j.kunci}
                                                    className={j.kelas}
                                                    style={{ width: `${(n / m.total) * 100}%` }}
                                                />
                                            );
                                        })}
                                </div>

                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-ink-3">
                                    <span>
                                        <strong className="font-medium text-ok">{m.siap}</strong> siap
                                    </span>
                                    <span>·</span>
                                    <span>{m.draft} draf</span>
                                    {m.arsip > 0 && (
                                        <>
                                            <span>·</span>
                                            <span>{m.arsip} arsip</span>
                                        </>
                                    )}
                                    <span>·</span>
                                    <span>{m.guru} guru</span>
                                    <span>·</span>
                                    <span>{m.dipakai} dipakai</span>
                                </div>
                            </div>

                            <div className="flex items-center justify-between border-t border-line/60 pt-2 text-xs font-medium text-accent">
                                <span>Lihat daftar bank soal</span>
                                <span aria-hidden="true">→</span>
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </AdminLayout>
    );
}

function KpiCard({
    label,
    nilai,
    nada,
}: {
    label: string;
    nilai: number;
    nada?: 'ok';
}) {
    return (
        <div className="rounded-wf border border-line bg-surface px-3 py-2.5">
            <div className="text-[10px] font-medium uppercase tracking-wide text-ink-3">
                {label}
            </div>
            <div
                className={
                    'mt-1 font-mono text-lg font-semibold leading-none ' +
                    (nada === 'ok' ? 'text-ok' : 'text-ink')
                }
            >
                {nilai.toLocaleString('id-ID')}
            </div>
        </div>
    );
}
