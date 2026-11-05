import { Head, Link } from '@inertiajs/react';
import GuruLayout from '@/Layouts/GuruLayout';
import { Chip, Inbox } from '@/Components/ui';
import { formatPoin } from '@/Components/angka';

/**
 * Daftar ujian bernilai - wireframe-peran.html plate 07, versi daftar.
 *
 * ---------------------------------------------------------------------
 * Yang ditampilkan adalah RATA-RATA, bukan angka per siswa
 * ---------------------------------------------------------------------
 * Plate 07 menggambar satu kelas. Guru dengan enam ujian membuka menu ini dan
 * harus memilih dulu - dan pilihan itu hanya bisa diambil dengan angka:
 * ujian mana yang rata-ratanya rendah, ujian mana yang masih menunggu
 * koreksi. Daftar tanpa angka memaksa Guru membuka keenamnya satu per satu.
 *
 * ---------------------------------------------------------------------
 * "Belum final" berdiri sendiri, bukan bagian dari rata-rata
 * ---------------------------------------------------------------------
 * Ujian yang separuh uraiannya belum dikoreksi punya rata-rata yang dihitung
 * dari baris final saja. Angka itu benar untuk baris yang ia wakili, tetapi
 * BUKAN gambaran kelasnya - karena itu jumlah yang belum final ikut
 * ditampilkan di sebelahnya, supaya Guru tahu berapa yang belum masuk hitungan.
 */

type Baris = {
    ujian_id: number;
    mapel: string | null;
    judul: string;
    event: string | null;
    hasil: number;
    rata_rata: number | null;
    tertinggi: number | null;
    remedial: number;
    belum_final: number;
};

type Props = { ujian: Baris[]; kkm: number };

export default function NilaiIndex({ ujian, kkm }: Props) {
    return (
        <GuruLayout judul="Nilai kelas" breadcrumb="/guru/nilai">
            <Head title="Nilai kelas" />

            <div className="mb-3">
                <Inbox>
                    KKM sekolah <strong>{kkm}</strong>. Nilai di bawah KKM ditandai remedial —
                    angkanya dibaca dari pengaturan sekolah, bukan diketik di laman ini.
                </Inbox>
            </div>

            {ujian.length === 0 ? (
                <div className="rounded-wf border border-line bg-surface px-4 py-8 text-center text-sm text-ink-3">
                    Belum ada nilai. Daftar ini terisi setelah siswa mengumpulkan ujiannya.
                </div>
            ) : (
                <div className="space-y-2">
                    {ujian.map((u) => (
                        <Link
                            key={u.ujian_id}
                            href={`/guru/nilai/${u.ujian_id}`}
                            className="block rounded-wf border border-line bg-surface px-4 py-3 transition-colors hover:bg-surface-2/40"
                        >
                            <div className="flex flex-wrap items-center gap-3">
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium text-ink">{u.judul}</p>
                                    <p className="mt-0.5 truncate text-[11px] text-ink-3">
                                        {[u.mapel, u.event].filter(Boolean).join(' · ')}
                                    </p>
                                </div>

                                <Chip nada={u.belum_final > 0 ? 'warn' : 'ok'}>
                                    {u.belum_final > 0
                                        ? `${u.belum_final} belum final`
                                        : 'semua final'}
                                </Chip>
                            </div>

                            <div className="mt-2 grid grid-cols-2 gap-3 text-[11px] text-ink-3 sm:grid-cols-4">
                                <span>
                                    Nilai tercatat{' '}
                                    <span className="font-mono text-ink-2">{u.hasil}</span>
                                </span>
                                <span>
                                    Rata-rata{' '}
                                    <span className="font-mono text-ink-2">
                                        {formatPoin(u.rata_rata)}
                                    </span>
                                </span>
                                <span>
                                    Tertinggi{' '}
                                    <span className="font-mono text-ink-2">
                                        {formatPoin(u.tertinggi)}
                                    </span>
                                </span>
                                <span>
                                    Remedial{' '}
                                    <span className="font-mono text-ink-2">{u.remedial}</span>
                                </span>
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </GuruLayout>
    );
}
