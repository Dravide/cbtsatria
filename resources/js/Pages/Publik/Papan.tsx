import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Papan publik pengawas - `/papan/{slug}`, tanpa login.
 *
 * Saat kolom pencarian kosong, tampilkan daftar jadwal/sesi yang ringkas.
 * Saat pengguna mencari nama guru/pengawas, ruang, kelas, atau mapel,
 * tampilkan langsung jadwal ruang & pengawas yang cocok agar pertanyaan
 * "Bu Ani ngawas di mana?" atau "7A pengawasnya siapa?" langsung terjawab.
 */

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
    sesi: Sesi[];
    tanpa_pengawas: string[];
    diperbarui: string;
};

function judulSesi(s: Sesi): string {
    const mapel = s.mapel?.trim() ?? '';
    const ujian = s.ujian?.trim() ?? '';

    if (ujian !== '' && mapel !== '' && !ujian.toLowerCase().includes(mapel.toLowerCase())) {
        return `${mapel} · ${ujian}`;
    }

    return ujian || mapel || 'Ujian';
}

export default function Papan({ sekolah, slug, sesi, tanpa_pengawas, diperbarui }: Props) {
    const [cari, setCari] = useState('');
    const q = cari.trim().toLowerCase();

    const hasilCari =
        q === ''
            ? []
            : sesi.flatMap((s) => {
                  const judul = judulSesi(s);
                  const cocokSesi = `${judul} ${s.label} ${s.hari_tanggal ?? ''}`
                      .toLowerCase()
                      .includes(q);

                  return s.ruang
                      .filter((r) => {
                          if (cocokSesi) {
                              return true;
                          }

                          const teksRuang = [
                              r.kode ?? '',
                              r.nama ?? '',
                              r.lokasi ?? '',
                              ...(r.kelas ?? []),
                              ...r.pengawas.map((p) => p.nama),
                          ]
                              .join(' ')
                              .toLowerCase();

                          return teksRuang.includes(q);
                      })
                      .map((r) => ({ sesi: s, judul, ruang: r }));
              });

    return (
        <>
            <Head title={`Jadwal pengawas · ${sekolah}`} />

            <div className="min-h-screen bg-paper">
                <div className="mx-auto flex min-h-screen w-full max-w-[34rem] flex-col sm:border-x sm:border-line">
                    <header className="flex items-baseline gap-2 border-b border-line bg-surface px-3 py-2.5">
                        <span className="truncate text-xs font-medium text-ink">{sekolah}</span>
                        <span className="ml-auto shrink-0 font-mono text-[11px] text-ink-3">
                            {diperbarui}
                        </span>
                    </header>

                    <main className="flex-1 space-y-2.5 px-3 py-4">
                        {sesi.length > 0 && (
                            <div className="flex items-center gap-2">
                                <input
                                    value={cari}
                                    onChange={(e) => setCari(e.target.value)}
                                    placeholder="Cari guru/pengawas, ruang, kelas, atau mapel..."
                                    aria-label="Cari pengawas, ruang, kelas, atau mapel"
                                    className="min-w-0 flex-1 rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink outline-none placeholder:text-ink-3 focus:border-accent focus:ring-2 focus:ring-accent/15"
                                />
                                {cari !== '' && (
                                    <button
                                        type="button"
                                        onClick={() => setCari('')}
                                        className="shrink-0 rounded-wf border border-line bg-surface px-2.5 py-2 text-xs text-ink-2 hover:bg-surface-2"
                                    >
                                        Reset
                                    </button>
                                )}
                            </div>
                        )}

                        {sesi.length === 0 && (
                            <section className="rounded-wf border border-line bg-surface px-4 py-8 text-center">
                                <p className="text-sm text-ink">Belum ada sesi ujian yang berjalan.</p>
                                <p className="mt-1.5 text-xs leading-relaxed text-ink-3">
                                    Papan ini menampilkan sesi yang sedang berjalan, atau sesi
                                    terdekat yang belum berakhir.
                                </p>
                            </section>
                        )}

                        {q === '' && tanpa_pengawas.length > 0 && (
                            <div className="rounded-wf border border-warn/40 bg-warn-bg px-3 py-2.5 text-xs leading-relaxed text-warn">
                                <strong>{tanpa_pengawas.length} ruang-sesi belum punya pengawas.</strong>{' '}
                                Ketuk jadwal di bawah untuk melihat ruangnya (ditetapkan Admin di konsol).
                            </div>
                        )}

                        {q !== '' ? (
                            hasilCari.length === 0 ? (
                                <section className="rounded-wf border border-line bg-surface px-4 py-6 text-center text-xs text-ink-3">
                                    Tidak ada jadwal guru, ruang, atau kelas yang cocok dengan &ldquo;{cari}&rdquo;.
                                </section>
                            ) : (
                                <div className="space-y-2">
                                    <p className="px-1 text-[11px] text-ink-3">
                                        {hasilCari.length} hasil ditemukan
                                    </p>

                                    {hasilCari.map(({ sesi: s, judul, ruang: r }) => (
                                        <div
                                            key={`${s.id}-${r.id}`}
                                            className="rounded-wf border border-line bg-surface px-3.5 py-2.5"
                                        >
                                            <div className="flex items-baseline justify-between gap-2">
                                                <Link
                                                    href={`/papan/${slug}/sesi/${s.id}`}
                                                    className="font-display text-sm font-semibold text-ink hover:text-accent hover:underline"
                                                >
                                                    {judul}
                                                </Link>
                                                <span className="shrink-0 font-mono text-[11px] text-ink-2">
                                                    {s.label}
                                                </span>
                                            </div>

                                            <p className="mt-0.5 font-mono text-[11px] text-ink-3">
                                                {s.hari_tanggal ? `${s.hari_tanggal} · ` : ''}
                                                {s.jam}
                                            </p>

                                            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-line/60 pt-2 text-xs">
                                                <span className="font-mono font-medium text-ink">
                                                    {r.kode ?? 'R-?'}
                                                    {r.nama ? ` · ${r.nama}` : ''}
                                                </span>

                                                {r.kelas && r.kelas.length > 0 && (
                                                    <span className="text-[11px] text-ink-2">
                                                        Kelas {r.kelas.join(', ')}
                                                    </span>
                                                )}

                                                {r.lokasi && (
                                                    <span className="text-[11px] text-ink-3">
                                                        {r.lokasi}
                                                    </span>
                                                )}
                                            </div>

                                            <div className="mt-1.5 text-xs">
                                                <span className="text-[11px] text-ink-3">Pengawas: </span>
                                                {r.pengawas.length === 0 ? (
                                                    <span className="text-warn">belum ditetapkan</span>
                                                ) : (
                                                    <span className="inline-flex flex-wrap gap-x-2 gap-y-0.5">
                                                        {r.pengawas.map((p) => (
                                                            <Link
                                                                key={p.id}
                                                                href={`/papan/${slug}/pengawas/${p.id}`}
                                                                className="font-medium text-accent underline decoration-line underline-offset-2 hover:decoration-accent"
                                                            >
                                                                {p.nama}
                                                            </Link>
                                                        ))}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )
                        ) : (
                            sesi.map((s) => {
                                const kurang = s.ruang.filter((r) => r.pengawas.length === 0).length;

                                return (
                                    <Link
                                        key={s.id}
                                        href={`/papan/${slug}/sesi/${s.id}`}
                                        className="block rounded-wf border border-line bg-surface px-3.5 py-3 transition-colors hover:border-ink-3/50 hover:bg-surface-2/40"
                                    >
                                        <div className="flex items-baseline justify-between gap-2">
                                            <span className="font-display text-sm font-semibold text-ink">
                                                {judulSesi(s)}
                                            </span>
                                            {s.status === 'berjalan' && (
                                                <span className="shrink-0 rounded-wf bg-ok-bg px-1.5 py-0.5 text-[10px] font-medium text-ok">
                                                    Berjalan
                                                </span>
                                            )}
                                        </div>

                                        <p className="mt-1 font-mono text-[11px] text-ink-2">
                                            {s.hari_tanggal ? `${s.hari_tanggal} · ` : ''}
                                            {s.jam}
                                        </p>

                                        <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
                                            <span className="font-mono text-[11px] text-ink-3">
                                                {s.label} · {s.ruang.length} ruang
                                            </span>

                                            {kurang > 0 ? (
                                                <span className="rounded-wf bg-warn-bg px-1.5 py-0.5 text-[11px] font-medium text-warn">
                                                    {kurang} tanpa pengawas
                                                </span>
                                            ) : (
                                                <span className="rounded-wf bg-ok-bg px-1.5 py-0.5 text-[11px] font-medium text-ok">
                                                    pengawas lengkap
                                                </span>
                                            )}

                                            <span className="ml-auto text-[11px] font-medium text-accent">
                                                Lihat ruang →
                                            </span>
                                        </div>
                                    </Link>
                                );
                            })
                        )}

                        <p className="px-1 pt-1 text-[11px] leading-relaxed text-ink-3">
                            Ketuk salah satu jadwal atau cari nama guru, ruang, dan kelas.
                            Nama siswa, nomor kursi, dan token tidak pernah tayang di sini.
                        </p>
                    </main>
                </div>
            </div>
        </>
    );
}
