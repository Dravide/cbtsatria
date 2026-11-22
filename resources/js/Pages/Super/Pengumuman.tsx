import { router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import SuperLayout from '@/Layouts/SuperLayout';

type PengumumanItem = {
    id: number;
    judul: string;
    isi: string;
    nada: string;
    berlaku: boolean;
    berakhir: string | null;
    dibuat: string | null;
    penulis: string | null;
};

type Props = {
    pengumuman: PengumumanItem[];
};

export default function Pengumuman({ pengumuman }: Props) {
    const form = useForm({
        title: '',
        body: '',
        nada: 'info',
        expires_at: '',
    });

    const kirim = (e: FormEvent) => {
        e.preventDefault();
        form.post('/super/pengumuman', {
            onSuccess: () => form.reset(),
        });
    };

    return (
        <SuperLayout
            judul="Pengumuman Global Platform"
            subjudul="Tayang sebagai banner di seluruh konsol Admin & Guru lintas sekolah"
        >
            <form
                onSubmit={kirim}
                className="space-y-3 rounded-wf border border-line bg-surface p-4"
            >
                <div className="grid gap-3 md:grid-cols-3">
                    <div className="md:col-span-2">
                        <label className="mb-1 block text-xs font-medium text-ink">
                            Judul Pengumuman
                        </label>
                        <input
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            placeholder="Mis. Jadwal pemeliharaan server Minggu 22:00 WIB"
                            required
                            className="w-full rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink"
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-ink">Nada</label>
                        <select
                            value={form.data.nada}
                            onChange={(e) => form.setData('nada', e.target.value)}
                            className="w-full rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink"
                        >
                            <option value="info">Info (biru)</option>
                            <option value="penting">Penting (kuning)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label className="mb-1 block text-xs font-medium text-ink">Pesan</label>
                    <textarea
                        rows={2}
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                        placeholder="Tulis informasi singkat untuk seluruh Admin & Guru..."
                        required
                        className="w-full rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink"
                    />
                </div>

                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <label className="mb-1 block text-xs font-medium text-ink">
                            Berlaku sampai (opsional)
                        </label>
                        <input
                            type="datetime-local"
                            value={form.data.expires_at}
                            onChange={(e) => form.setData('expires_at', e.target.value)}
                            className="rounded-wf border border-line bg-surface px-3 py-1.5 font-mono text-xs text-ink"
                        />
                    </div>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-wf bg-accent px-4 py-2 text-xs font-medium text-white hover:opacity-90 disabled:opacity-50"
                    >
                        {form.processing ? 'Menayangkan...' : 'Tayangkan Pengumuman'}
                    </button>
                </div>
            </form>

            <section className="overflow-hidden rounded-wf border border-line bg-surface">
                <div className="border-b border-line bg-surface-2 px-4 py-2.5">
                    <h2 className="text-xs font-semibold text-ink">
                        Riwayat Pengumuman ({pengumuman.length})
                    </h2>
                </div>
                {pengumuman.length === 0 ? (
                    <p className="px-4 py-6 text-center text-xs text-ink-3">
                        Belum ada pengumuman global.
                    </p>
                ) : (
                    <ul className="divide-y divide-line">
                        {pengumuman.map((p) => (
                            <li
                                key={p.id}
                                className="flex items-start justify-between gap-3 px-4 py-3 text-xs"
                            >
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <strong className="text-ink">{p.judul}</strong>
                                        <span
                                            className={
                                                'rounded-full px-2 py-0.5 font-mono text-[10px] ' +
                                                (p.berlaku
                                                    ? 'bg-ok-bg text-ok'
                                                    : 'bg-surface-2 text-ink-3')
                                            }
                                        >
                                            {p.berlaku ? 'tayang' : 'berakhir'}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-ink-2">{p.isi}</p>
                                    <p className="mt-1 font-mono text-[10px] text-ink-3">
                                        Dibuat {p.dibuat}
                                        {p.berakhir ? ` · berakhir ${p.berakhir}` : ''}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => router.delete(`/super/pengumuman/${p.id}`)}
                                    className="shrink-0 rounded-wf border border-line px-2.5 py-1 text-[11px] text-danger hover:bg-danger-bg"
                                >
                                    Hapus
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </SuperLayout>
    );
}
