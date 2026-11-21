import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

type Ruang = {
    id: number;
    kode: string | null;
    nama: string | null;
    lokasi: string | null;
    kelas?: string[];
    pengawas: { id: number; nama: string }[];
};

type Sesi = {
    id: number;
    label: string;
    ujian: string | null;
    mapel: string | null;
    hari_tanggal?: string | null;
    jam: string;
    status: string;
    ruang: Ruang[];
};

type Props = {
    sekolah: string;
    slug: string;
    sesi: Sesi;
    tanpa_pengawas: string[];
    diperbarui: string;
};

export default function PapanSesi({ sekolah, slug, sesi, tanpa_pengawas, diperbarui }: Props) {
    const [cari, setCari] = useState('');

    const q = cari.trim().toLowerCase();
    const ruangTampil =
        q === ''
            ? sesi.ruang
            : sesi.ruang.filter((r) => {
                  const teks = [
                      r.kode ?? '',
                      r.nama ?? '',
                      r.lokasi ?? '',
                      ...(r.kelas ?? []),
                      ...r.pengawas.map((p) => p.nama),
                  ]
                      .join(' ')
                      .toLowerCase();

                  return teks.includes(q);
              });

    const mapel = sesi.mapel?.trim() ?? '';
    const ujian = sesi.ujian?.trim() ?? '';
    const judulUjian =
        ujian !== '' && mapel !== '' && !ujian.toLowerCase().includes(mapel.toLowerCase())
            ? `${mapel} · ${ujian}`
            : ujian || mapel || 'Ujian';

    return (
        <>
            <Head title={`${judulUjian} · ${sesi.label} · ${sekolah}`} />

            <div className="min-h-screen bg-paper">
                <div className="mx-auto flex min-h-screen w-full max-w-[34rem] flex-col sm:border-x sm:border-line">
                    <header className="flex items-baseline gap-2 border-b border-line bg-surface px-3 py-2.5">
                        <Link
                            href={`/papan/${slug}`}
                            className="shrink-0 text-xs font-medium text-accent hover:underline"
                        >
                            ← Semua jadwal
                        </Link>
                        <span className="truncate text-xs text-ink-3">{sekolah}</span>
                        <span className="ml-auto shrink-0 font-mono text-[11px] text-ink-3">
                            {diperbarui}
                        </span>
                    </header>

                    <main className="flex-1 space-y-3 px-3 py-4">
                        <section className="rounded-wf border border-line bg-surface px-3.5 py-3">
                            <h1 className="font-display text-base font-semibold text-ink">
                                {judulUjian}
                            </h1>
                            <p className="mt-1 font-mono text-xs text-ink-2">
                                {sesi.hari_tanggal ? `${sesi.hari_tanggal} · ` : ''}
                                {sesi.label} · {sesi.jam} · {sesi.ruang.length} ruang
                            </p>
                        </section>

                        {tanpa_pengawas.length > 0 && (
                            <div
                                className="rounded-wf border border-warn/40 bg-warn-bg px-3 py-2.5 text-xs leading-relaxed text-warn"
                                title={tanpa_pengawas.length > 8 ? tanpa_pengawas.join(', ') : undefined}
                            >
                                <strong>{tanpa_pengawas.length} ruang belum punya pengawas:</strong>{' '}
                                <span className="font-mono">
                                    {tanpa_pengawas.length > 8
                                        ? `${tanpa_pengawas.slice(0, 6).join(', ')} +${tanpa_pengawas.length - 6} lainnya`
                                        : tanpa_pengawas.join(', ')}
                                </span>
                                . Pengawasnya ditetapkan Admin di konsol.
                            </div>
                        )}

                        {sesi.ruang.length > 6 && (
                            <input
                                value={cari}
                                onChange={(e) => setCari(e.target.value)}
                                placeholder="Cari ruang, lokasi, atau nama pengawas..."
                                className="w-full rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink outline-none placeholder:text-ink-3 focus:border-accent focus:ring-2 focus:ring-accent/15"
                            />
                        )}

                        <section className="overflow-hidden rounded-wf border border-line bg-surface">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-line bg-surface-2/50 text-left">
                                        <th className="px-3 py-2 text-[11px] font-medium text-ink-3">
                                            Ruang
                                        </th>
                                        <th className="px-3 py-2 text-[11px] font-medium text-ink-3">
                                            Pengawas
                                        </th>
                                        <th className="hidden px-3 py-2 text-[11px] font-medium text-ink-3 sm:table-cell">
                                            Lokasi
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {ruangTampil.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={3}
                                                className="px-3 py-6 text-center text-xs text-ink-3"
                                            >
                                                Tidak ada ruang atau pengawas yang cocok.
                                            </td>
                                        </tr>
                                    ) : (
                                        ruangTampil.map((r) => (
                                            <tr
                                                key={r.id}
                                                className="border-b border-line/40 last:border-0"
                                            >
                                                <td className="px-3 py-2 align-top">
                                                    <span className="font-mono text-xs font-medium text-ink">
                                                        {r.kode ?? 'R-?'}
                                                    </span>
                                                    {r.nama && (
                                                        <span className="mt-0.5 block text-[11px] text-ink-3">
                                                            {r.nama}
                                                        </span>
                                                    )}
                                                    {r.kelas && r.kelas.length > 0 && (
                                                        <span className="mt-0.5 block text-[11px] text-ink-2">
                                                            Kelas {r.kelas.join(', ')}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 align-top">
                                                    {r.pengawas.length === 0 ? (
                                                        <span className="text-xs text-warn">
                                                            belum ditetapkan
                                                        </span>
                                                    ) : (
                                                        <span className="flex flex-wrap gap-x-2 gap-y-0.5">
                                                            {r.pengawas.map((p) => (
                                                                <Link
                                                                    key={p.id}
                                                                    href={`/papan/${slug}/pengawas/${p.id}`}
                                                                    className="text-xs text-ink underline decoration-line underline-offset-2 hover:decoration-ink"
                                                                >
                                                                    {p.nama}
                                                                </Link>
                                                            ))}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="hidden px-3 py-2 align-top text-[11px] text-ink-3 sm:table-cell">
                                                    {r.lokasi ?? '—'}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </section>

                        <p className="px-1 pt-1 text-[11px] leading-relaxed text-ink-3">
                            Halaman ini hanya memuat nama ruang, nama pengawas, dan jam. Nama
                            siswa, nomor kursi, dan token tidak pernah tayang di sini.
                        </p>
                    </main>
                </div>
            </div>
        </>
    );
}
