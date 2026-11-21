import { Head, Link } from '@inertiajs/react';

/**
 * Papan publik pengawas - `/papan/{slug}`, tanpa login.
 *
 * Menampilkan daftar jadwal/sesi yang ringkas agar tidak menumpuk ke bawah.
 * Ketuk salah satu sesi untuk melihat daftar ruang dan pengawasnya di
 * `/papan/{slug}/sesi/{id}`.
 */

type Ruang = {
    id: number;
    kode: string | null;
    nama: string | null;
    lokasi: string | null;
    pengawas: { id: number; nama: string }[];
};

type Sesi = {
    id: number;
    label: string;
    ujian: string | null;
    mapel: string | null;
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

export default function Papan({ sekolah, slug, sesi, tanpa_pengawas, diperbarui }: Props) {
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
                        {sesi.length === 0 && (
                            <section className="rounded-wf border border-line bg-surface px-4 py-8 text-center">
                                <p className="text-sm text-ink">Belum ada sesi ujian yang berjalan.</p>
                                <p className="mt-1.5 text-xs leading-relaxed text-ink-3">
                                    Papan ini menampilkan sesi yang sedang berjalan, atau sesi
                                    terdekat yang belum berakhir.
                                </p>
                            </section>
                        )}

                        {tanpa_pengawas.length > 0 && (
                            <div className="rounded-wf border border-warn/40 bg-warn-bg px-3 py-2.5 text-xs leading-relaxed text-warn">
                                <strong>{tanpa_pengawas.length} ruang belum punya pengawas:</strong>{' '}
                                <span className="font-mono">{tanpa_pengawas.join(', ')}</span>.
                                Pengawasnya ditetapkan Admin di konsol.
                            </div>
                        )}

                        {sesi.map((s) => {
                            const kurang = s.ruang.filter((r) => r.pengawas.length === 0).length;

                            return (
                                <Link
                                    key={s.id}
                                    href={`/papan/${slug}/sesi/${s.id}`}
                                    className="block rounded-wf border border-line bg-surface px-3.5 py-3 transition-colors hover:border-ink-3/50 hover:bg-surface-2/40"
                                >
                                    <div className="flex items-baseline justify-between gap-2">
                                        <span className="font-display text-sm font-semibold text-ink">
                                            {[s.mapel, s.ujian].filter(Boolean).join(' · ') || 'Ujian'}
                                        </span>
                                        <span className="shrink-0 font-mono text-[11px] text-ink-2">
                                            {s.jam}
                                        </span>
                                    </div>

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
                        })}

                        <p className="px-1 pt-1 text-[11px] leading-relaxed text-ink-3">
                            Ketuk salah satu jadwal untuk melihat daftar ruang dan pengawasnya.
                            Nama siswa, nomor kursi, dan token tidak pernah tayang di sini.
                        </p>
                    </main>
                </div>
            </div>
        </>
    );
}
