import { router } from '@inertiajs/react';
import Ikon from '@/Components/Ikon';
import { Chip, Tombol } from '@/Components/ui';
import SuperLayout from '@/Layouts/SuperLayout';

type Props = {
    sistem: {
        php: string;
        laravel: string;
        env: string;
        db_ok: boolean;
        db_latensi_ms: number | null;
        db_driver: string;
        queued_jobs: number;
        failed_count: number;
        storage_link: boolean;
        public_hot: boolean;
        disk_bebas_gb: number | null;
        disk_total_gb: number | null;
    };
    failed_jobs: { id: number; uuid: string; queue: string; failed_at: string }[];
};

export default function Sistem({ sistem, failed_jobs }: Props) {
    return (
        <SuperLayout
            judul="Kesehatan Sistem"
            breadcrumb="Super Admin / Kesehatan Sistem"
            subjudul={`PHP ${sistem.php} · Laravel ${sistem.laravel} · Lingkungan ${sistem.env}`}
            aksi={
                <>
                    <Tombol onClick={() => router.post('/super/sistem/pangkas-ekspor')}>
                        Pangkas Ekspor Lama
                    </Tombol>
                    {sistem.failed_count > 0 && (
                        <Tombol nada="pri" onClick={() => router.post('/super/sistem/retry-jobs')}>
                            Retry Job Gagal ({sistem.failed_count})
                        </Tombol>
                    )}
                </>
            }
        >
            <section className="overflow-hidden rounded-wf border border-line bg-surface">
                <div className="flex items-center gap-2 border-b border-line bg-surface-2/60 px-4 py-2.5">
                    <Ikon nama="denyut" ukuran={15} className="text-ink-3" />
                    <h2 className="text-xs font-semibold text-ink">Checklist Kesiapan Server</h2>
                </div>
                <ul className="divide-y divide-line text-xs">
                    <li className="flex items-center justify-between px-4 py-3">
                        <div>
                            <p className="font-medium text-ink">
                                Database ({sistem.db_driver.toUpperCase()})
                            </p>
                            <p className="text-ink-3">
                                {sistem.db_latensi_ms !== null
                                    ? `Latensi ping ${sistem.db_latensi_ms} ms`
                                    : 'Tidak dapat terhubung'}
                            </p>
                        </div>
                        <Chip nada={sistem.db_ok ? 'ok' : 'bad'}>
                            {sistem.db_ok ? 'Sehat' : 'Gangguan'}
                        </Chip>
                    </li>

                    <li className="flex items-center justify-between px-4 py-3">
                        <div>
                            <p className="font-medium text-ink">Antrean Worker (Queue)</p>
                            <p className="text-ink-3">
                                {sistem.queued_jobs} menunggu · {sistem.failed_count} gagal
                            </p>
                        </div>
                        <Chip nada={sistem.failed_count === 0 ? 'ok' : 'warn'}>
                            {sistem.failed_count === 0 ? 'Bersih' : `${sistem.failed_count} Gagal`}
                        </Chip>
                    </li>

                    <li className="flex items-center justify-between px-4 py-3">
                        <div>
                            <p className="font-medium text-ink">Symlink public/storage (Logo)</p>
                            <p className="text-ink-3">
                                Dibutuhkan agar logo sekolah tampil di laman publik &amp; pintu ruang
                            </p>
                        </div>
                        <Chip nada={sistem.storage_link ? 'ok' : 'warn'}>
                            {sistem.storage_link ? 'Ada' : 'Belum di-link'}
                        </Chip>
                    </li>

                    <li className="flex items-center justify-between px-4 py-3">
                        <div>
                            <p className="font-medium text-ink">Penanda public/hot (Vite Dev)</p>
                            <p className="text-ink-3">
                                Di produksi berkas ini harus tidak ada agar tidak memicu penantian SSR
                            </p>
                        </div>
                        <Chip nada={!sistem.public_hot ? 'ok' : 'warn'}>
                            {sistem.public_hot ? 'Terdeteksi (Dev)' : 'Bersih'}
                        </Chip>
                    </li>

                    {sistem.disk_bebas_gb !== null && sistem.disk_total_gb !== null && (
                        <li className="flex items-center justify-between px-4 py-3">
                            <div>
                                <p className="font-medium text-ink">Kapasitas Disk Storage</p>
                                <p className="text-ink-3">
                                    Tersisa {sistem.disk_bebas_gb} GB dari {sistem.disk_total_gb} GB
                                </p>
                            </div>
                            <Chip nada="ok">
                                {Math.round((sistem.disk_bebas_gb / sistem.disk_total_gb) * 100)}%
                                bebas
                            </Chip>
                        </li>
                    )}
                </ul>
            </section>

            {failed_jobs.length > 0 && (
                <section className="mt-4 overflow-hidden rounded-wf border border-line bg-surface">
                    <div className="border-b border-line bg-surface-2/60 px-4 py-2.5">
                        <h2 className="text-xs font-semibold text-ink">10 Job Gagal Terbaru</h2>
                    </div>
                    <ul className="divide-y divide-line font-mono text-xs">
                        {failed_jobs.map((j) => (
                            <li key={j.id} className="flex justify-between px-4 py-2.5">
                                <span className="text-ink">
                                    #{j.id} · {j.queue} · {j.uuid}
                                </span>
                                <span className="text-ink-3">{j.failed_at}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </SuperLayout>
    );
}
