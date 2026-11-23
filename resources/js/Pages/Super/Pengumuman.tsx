import { router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import Ikon from '@/Components/Ikon';
import { Chip, Field, Tombol } from '@/Components/ui';
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

const INPUT =
    'w-full rounded-wf border border-line bg-surface px-3 py-1.5 text-xs text-ink outline-none placeholder:text-ink-3 focus:border-accent focus:ring-2 focus:ring-accent/15';

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
            judul="Pengumuman Global"
            breadcrumb="Super Admin / Pengumuman Global"
            subjudul="Tayang sebagai banner di seluruh konsol Admin & Guru lintas sekolah"
        >
            <form
                onSubmit={kirim}
                className="mb-4 space-y-3 rounded-wf border border-line bg-surface p-4"
            >
                <div className="flex items-center gap-2 border-b border-line pb-2.5">
                    <Ikon nama="bendera" ukuran={15} className="text-ink-3" />
                    <h2 className="text-xs font-semibold text-ink">Buat Pengumuman Baru</h2>
                </div>

                <div className="grid gap-3 md:grid-cols-3">
                    <div className="md:col-span-2">
                        <Field label="Judul Pengumuman" galat={form.errors.title}>
                            <input
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                placeholder="Mis. Jadwal pemeliharaan server Minggu 22:00 WIB"
                                required
                                className={INPUT}
                            />
                        </Field>
                    </div>
                    <Field label="Nada">
                        <select
                            value={form.data.nada}
                            onChange={(e) => form.setData('nada', e.target.value)}
                            className={INPUT}
                        >
                            <option value="info">Info (biru)</option>
                            <option value="penting">Penting (kuning)</option>
                        </select>
                    </Field>
                </div>

                <Field label="Pesan" galat={form.errors.body}>
                    <textarea
                        rows={2}
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                        placeholder="Tulis informasi singkat untuk seluruh Admin & Guru..."
                        required
                        className={INPUT}
                    />
                </Field>

                <div className="flex flex-wrap items-end justify-between gap-3">
                    <Field label="Berlaku sampai (opsional)">
                        <input
                            type="datetime-local"
                            value={form.data.expires_at}
                            onChange={(e) => form.setData('expires_at', e.target.value)}
                            className={`${INPUT} font-mono`}
                        />
                    </Field>
                    <Tombol nada="pri" type="submit" disabled={form.processing}>
                        {form.processing ? 'Menayangkan...' : 'Tayangkan Pengumuman'}
                    </Tombol>
                </div>
            </form>

            <section className="overflow-hidden rounded-wf border border-line bg-surface">
                <div className="flex items-center justify-between border-b border-line bg-surface-2/60 px-4 py-2.5">
                    <h2 className="text-xs font-semibold text-ink">
                        Riwayat Pengumuman ({pengumuman.length})
                    </h2>
                </div>
                {pengumuman.length === 0 ? (
                    <p className="px-4 py-8 text-center text-xs text-ink-3">
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
                                        <Chip nada={p.berlaku ? 'ok' : 'mute'}>
                                            {p.berlaku ? 'tayang' : 'berakhir'}
                                        </Chip>
                                    </div>
                                    <p className="mt-1 text-ink-2">{p.isi}</p>
                                    <p className="mt-1 font-mono text-[10px] text-ink-3">
                                        Dibuat {p.dibuat}
                                        {p.berakhir ? ` · berakhir ${p.berakhir}` : ''}
                                    </p>
                                </div>
                                <Tombol
                                    nada="bahaya"
                                    onClick={() => router.delete(`/super/pengumuman/${p.id}`)}
                                >
                                    Hapus
                                </Tombol>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </SuperLayout>
    );
}
