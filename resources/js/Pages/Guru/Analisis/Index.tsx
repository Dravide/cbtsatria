import { Head, Link } from '@inertiajs/react';
import GuruLayout from '@/Layouts/GuruLayout';
import { Chip, Inbox } from '@/Components/ui';

/**
 * Daftar ujian yang bisa dianalisis - wireframe-peran.html plate 08, versi daftar.
 *
 * ---------------------------------------------------------------------
 * "setelah ujian" adalah label di bilah atas plate 08, bukan syarat tombol
 * ---------------------------------------------------------------------
 * Analisis butir tidak boleh dihitung di tengah ujian - peringkat kelas
 * berubah setiap kali satu siswa mengumpulkan, dan daya beda yang dihitung
 * dari separuh kelas adalah angka yang salah. Karena itu angka "dianalisis"
 * di sini dibaca dari tabel hasil (`question_analyses`), dan ujian yang belum
 * pernah dihitung tampil dengan "belum dihitung" - bukan dengan nol.
 */

type Baris = {
    ujian_id: number;
    mapel: string | null;
    judul: string;
    event: string | null;
    butir: number;
    dianalisis: number;
};

type Props = { ujian: Baris[]; ada_analisis: boolean };

export default function AnalisisIndex({ ujian, ada_analisis }: Props) {
    return (
        <GuruLayout judul="Analisis butir" breadcrumb="/guru/analisis">
            <Head title="Analisis butir" />

            {!ada_analisis && (
                <div className="mb-3">
                    <Inbox>
                        Belum ada butir yang dianalisis. Analisis dihitung setelah ujian selesai —
                        dayanya baru berarti bila seluruh kelas sudah mengumpulkan.
                    </Inbox>
                </div>
            )}

            {ujian.length === 0 ? (
                <div className="rounded-wf border border-line bg-surface px-4 py-8 text-center text-sm text-ink-3">
                    Belum ada ujian untuk mata pelajaran yang Anda ampu.
                </div>
            ) : (
                <div className="space-y-2">
                    {ujian.map((u) => (
                        <Link
                            key={u.ujian_id}
                            href={`/guru/analisis/${u.ujian_id}`}
                            className="block rounded-wf border border-line bg-surface px-4 py-3 transition-colors hover:bg-surface-2/40"
                        >
                            <div className="flex flex-wrap items-center gap-3">
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium text-ink">{u.judul}</p>
                                    <p className="mt-0.5 truncate text-[11px] text-ink-3">
                                        {[u.mapel, u.event].filter(Boolean).join(' · ')}
                                    </p>
                                </div>

                                <span className="font-mono text-xs text-ink-2">
                                    {u.dianalisis} / {u.butir}
                                </span>

                                <Chip nada={u.dianalisis > 0 ? 'ok' : 'mute'}>
                                    {u.dianalisis > 0 ? 'dianalisis' : 'belum dihitung'}
                                </Chip>
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </GuruLayout>
    );
}
