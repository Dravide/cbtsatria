import { Head, Link } from '@inertiajs/react';
import GuruLayout from '@/Layouts/GuruLayout';
import { Chip, Inbox } from '@/Components/ui';
import { formatPoin as format } from '@/Components/angka';

/**
 * Daftar antrean koreksi - wireframe-peran.html plate 06, versi daftar.
 *
 * ---------------------------------------------------------------------
 * Mengapa ada laman daftar, padahal plate 06 hanya menggambar satu jawaban
 * ---------------------------------------------------------------------
 * Wireframe menggambar satu JAWABAN pada satu waktu, dan itu memang bentuk
 * kerja mengoreksinya. Tetapi Guru dengan dua belas ujian perlu tahu ujian
 * mana yang menahan pekerjaannya - dan laman pertama menu "Koreksi uraian"
 * tidak bisa langsung membuka ujian yang entah mana. Daftar ini menjawab
 * "mulai dari mana", panel berikutnya menjawab "apa yang harus saya nilai".
 *
 * ---------------------------------------------------------------------
 * Angka di kepala tiap baris adalah ANTREAN, bukan nilai siswa
 * ---------------------------------------------------------------------
 * "Sisa" menghitung jawaban yang belum punya baris koreksi. Satu siswa bisa
 * meninggalkan dua uraian, dan "sisa 1" untuk siswa itu menyembunyikan
 * pekerjaan yang sebenarnya dua kali.
 */

type Baris = {
    ujian_id: number;
    mapel: string | null;
    judul: string;
    event: string | null;
    total: number;
    sudah: number;
    sisa: number;
    poin_diberikan: number;
    rata_rata: number | null;
    maksimal: number;
};

type Props = {
    ujian: Baris[];
    jumlah: { sisa: number; sudah: number; total: number; poin: number };
};

export default function KoreksiIndex({ ujian, jumlah }: Props) {
    return (
        <GuruLayout judul="Koreksi uraian" breadcrumb="/guru/koreksi">
            <Head title="Koreksi uraian" />

            {ujian.length === 0 ? (
                <div className="mb-3">
                    <Inbox>
                        Tidak ada uraian yang menunggu koreksi. Antrean muncul setelah siswa
                        mengumpulkan ujian yang punya butir uraian.
                    </Inbox>
                </div>
            ) : (
                <div className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <Kpi label="Sisa koreksi" nilai={jumlah.sisa} nada={jumlah.sisa > 0 ? 'warn' : 'ok'} />
                    <Kpi label="Sudah dikoreksi" nilai={jumlah.sudah} nada="ok" />
                    <Kpi label="Seluruh uraian" nilai={jumlah.total} />
                    <Kpi label="Poin diberikan" nilai={jumlah.poin} />
                </div>
            )}

            <div className="space-y-2">
                {ujian.map((u) => (
                    <Link
                        key={u.ujian_id}
                        href={`/guru/koreksi/${u.ujian_id}`}
                        className="block rounded-wf border border-line bg-surface px-4 py-3 transition-colors hover:bg-surface-2/40"
                    >
                        <div className="flex flex-wrap items-center gap-3">
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium text-ink">{u.judul}</p>
                                <p className="mt-0.5 truncate text-[11px] text-ink-3">
                                    {[u.mapel, u.event].filter(Boolean).join(' · ')}
                                </p>
                            </div>

                            {/* Dua angka, dan yang PENTING adalah "sisa" -
                                ujian yang sudah selesai dikoreksi tetap
                                ditampilkan supaya Guru bisa memeriksa ulang,
                                tetapi chip-nya hijau karena tidak ada lagi
                                yang menahan. */}
                            <div className="flex items-center gap-4 text-xs">
                                <span className="text-ink-3">
                                    <span className="font-mono text-ink">{u.sudah}</span>
                                    {' / '}
                                    <span className="font-mono">{u.total}</span>
                                </span>

                                <Chip nada={u.sisa > 0 ? 'warn' : 'ok'}>
                                    {u.sisa > 0 ? `${u.sisa} sisa` : 'selesai'}
                                </Chip>
                            </div>
                        </div>

                        <div className="mt-2 flex flex-wrap items-center gap-4 text-[11px] text-ink-3">
                            <span>
                                Rata-rata poin{' '}
                                <span className="font-mono text-ink-2">
                                    {u.rata_rata === null ? '—' : format(u.rata_rata)}
                                </span>
                                {' dari '}
                                <span className="font-mono">{format(u.maksimal)}</span>
                            </span>
                            <span>
                                Total poin{' '}
                                <span className="font-mono text-ink-2">{format(u.poin_diberikan)}</span>
                            </span>
                        </div>
                    </Link>
                ))}
            </div>
        </GuruLayout>
    );
}

/* ------------------------------------------------------------------ KPI */

/**
 * Kotak angka plate 06.
 *
 * Ditulis lokal, bukan diambil dari `ui.tsx`: Admin dan Guru punya kotak yang
 * berbeda ukuran dan nadanya, dan satu komponen bersama hanya akan menjadi
 * komponen dengan lima prop yang semuanya opsional.
 */
function Kpi({
    label,
    nilai,
    nada,
}: {
    label: string;
    nilai: number;
    nada?: 'ok' | 'warn';
}) {
    const warna =
        nada === 'ok' ? 'text-ok' : nada === 'warn' ? 'text-warn' : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-3 py-2">
            <span className="block text-[11px] text-ink-3">{label}</span>
            <span className={'mt-0.5 block font-mono text-lg font-semibold ' + warna}>
                {format(nilai)}
            </span>
        </div>
    );
}
