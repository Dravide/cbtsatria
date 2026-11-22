import { router } from '@inertiajs/react';
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
            subjudul={`PHP ${sistem.php} · Laravel ${sistem.laravel} · Lingkungan ${sistem.env}`}
            aksi={
                <>
                    <button
                        type="button"
                        onClick={() => router.post('/super/sistem/pangkas-ekspor')}
                        className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
                    >
                        Pangkas Ekspor Lama
                    </button>
                    {sistem.failed_count > 0 && (
                        <button
                            type="button"
                            onClick={() => router.post('/super/sistem/retry-jobs')}
                            className="rounded-wf bg-accent px-3 py-1.5 text-xs font-medium text-white hover:opacity-90"
                        >
                            Retry Semua Job Gagal ({sistem.failed_count})
                        </button>
                    )}
                </>
            }
        >
            <section className="overflow-hidden rounded-wf border border-line bg-surface">
                <div className="border-b border-line bg-surface-2 px-4 py-2.5">
                    <h2 className="text-xs font-semibold text-ink">Checklist Kesiapan Server</h2>
                </div>
                <ul className="divide-y divide-line text-xs">
                    <li className="flex items-center justify-between px-4 py-3">
                        <div>
                            <p className="font-semibold text-ink">
                                Database ({sistem.db_driver.toUpperCase()})
                            </p>
                            <p className="text-ink-3">
                                {sistem.db_latensi_ms !== null
                                    ? `Latensi ping ${sistem.db_latensi_ms} ms`
                                    : 'Tidak dapat terhubung'}
                            </p>
                        </div>
                        <span
                            className={
                                'rounded-full px-2.5 py-0.5 font-mono text-[10px] ' +
                                (sistem.db_ok ? 'bg-ok-bg text-ok' : 'bg-danger-bg text-danger')
                            }
                        >
                            {sistem.db_ok ? 'Sehat' : 'Gangguan'}
                        </span>
                    </li>

                    <li className="flex items-center justify-between px-4 py-3">
                        <div>
                            <p className="font-semibold text-ink">Antrean Worker (Queue)</p>
                            <p className="text-ink-3">
                                {sistem.queued_jobs} menunggu · {sistem.failed_count} gagal
                            </p>
                        </div>
                        <span
                            className={
                                'rounded-full px-2.5 py-0.5 font-mono text-[10px] ' +
                                (sistem.failed_count === 0
                                    ? 'bg-ok-bg text-ok'
                                    : 'bg-warn-bg text-warn')
                            }
                        >
                            {sistem.failed_count === 0 ? 'Bersih' : `${sistem.failed_count} Gagal`}
                        </span>
                    </li>

                    <li className="flex items-center justify-between px-4 py-3">
                        <div>
                            <p className="font-semibold text-ink">Symlink public/storage (Logo)</p>
                            <p className="text-ink-3">
                                Dibutuhkan agar logo sekolah tampil di laman publik &amp; pintu ruang
                            </p>
                        </div>
                        <span
                            className={
                                'rounded-full px-2.5 py-0.5 font-mono text-[10px] ' +
                                (sistem.storage_link
                                    ? 'bg-ok-bg text-ok'
                                    : 'bg-warn-bg text-warn')
                            }
                        >
                            {sistem.storage_link ? 'Ada' : 'Belum di-link'}
                        </span>
                    </li>

                    <li className="flex items-center justify-between px-4 py-3">
                        <div>
                            <p className="font-semibold text-ink">Penanda public/hot (Vite Dev)</p>
                            <p className="text-ink-3">
                                Di produksi berkas ini harus tidak ada agar tidak memicu penantian SSR
                            </p>
                        </div>
                        <span
                            className={
                                'rounded-full px-2.5 py-0.5 font-mono text-[10px] ' +
                                (!sistem.public_hot ? 'bg-ok-bg text-ok' : 'bg-warn-bg text-warn')
                            }
                        >
                            {sistem.public_hot ? 'Terdeteksi (Dev)' : 'Bersih'}
                        </span>
                    </li>

                    {sistem.disk_bebas_gb !== null && sistem.disk_total_gb !== null && (
                        <li className="flex items-center justify-between px-4 py-3">
                            <div>
                                <p className="font-semibold text-ink">Kapasitas Disk Storage</p>
                                <p className="text-ink-3">
                                    Tersisa {sistem.disk_bebas_gb} GB dari {sistem.disk_total_gb} GB
                                </p>
                            </div>
                            <span className="rounded-full bg-ok-bg px-2.5 py-0.5 font-mono text-[10px] text-ok">
                                {Math.round((sistem.disk_bebas_gb / sistem.disk_total_gb) * 100)}% bebas
                            </span>
                        </li>
                    )}
                </ul>
            </section>

            {failed_jobs.length > 0 && (
                <section className="overflow-hidden rounded-wf border border-line bg-surface">
                    <div className="border-b border-line bg-surface-2 px-4 py-2.5">
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
