import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import SuperLayout from '@/Layouts/SuperLayout';

type SekolahRow = {
    id: number;
    kode: string;
    nama: string;
    npsn: string;
    status: string;
    papan_slug: string | null;
    siswa: number;
    guru: number;
    admin: number;
    event: number;
    dibuat: string | null;
};

type Props = {
    sekolah: SekolahRow[];
    filter: {
        cari: string;
        status: string;
    };
};

export default function SekolahIndex({ sekolah, filter }: Props) {
    const [cari, setCari] = useState(filter.cari);
    const [tampilForm, setTampilForm] = useState(false);

    const form = useForm({
        code: '',
        name: '',
        npsn: '',
        timezone: 'Asia/Jakarta',
        email: '',
        phone: '',
        address: '',
        admin_name: '',
        admin_username: '',
    });

    const terapkanFilter = (e: FormEvent) => {
        e.preventDefault();
        router.get('/super/sekolah', { cari, status: filter.status }, { preserveState: true });
    };

    const simpanSekolah = (e: FormEvent) => {
        e.preventDefault();
        form.post('/super/sekolah', {
            onSuccess: () => {
                form.reset();
                setTampilForm(false);
            },
        });
    };

    return (
        <SuperLayout
            judul="Manajemen Sekolah"
            subjudul={`${sekolah.length} sekolah terdaftar di platform`}
            aksi={
                <button
                    type="button"
                    onClick={() => setTampilForm((v) => !v)}
                    className="rounded-wf bg-accent px-3 py-1.5 text-xs font-medium text-white hover:opacity-90"
                >
                    {tampilForm ? 'Tutup Form' : '+ Daftarkan Sekolah Baru'}
                </button>
            }
        >
            {tampilForm && (
                <form
                    onSubmit={simpanSekolah}
                    className="space-y-3 rounded-wf border border-accent/40 bg-surface p-4"
                >
                    <div>
                        <h2 className="text-sm font-semibold text-ink">
                            Daftarkan Sekolah Baru (1 Transaksi)
                        </h2>
                        <p className="mt-0.5 text-xs text-ink-3">
                            Otomatis membuat Sekolah, Admin pertama (sandi acak tampil sekali),
                            Tahun Ajaran aktif, serta Semester Ganjil &amp; Genap.
                        </p>
                    </div>

                    <div className="grid gap-3 md:grid-cols-3">
                        <div>
                            <label className="mb-1 block text-xs font-medium text-ink">
                                Kode Sekolah
                            </label>
                            <input
                                value={form.data.code}
                                onChange={(e) => form.setData('code', e.target.value)}
                                placeholder="SMKN1"
                                required
                                className="w-full rounded-wf border border-line bg-surface px-3 py-2 font-mono text-xs text-ink"
                            />
                            {form.errors.code && (
                                <p className="mt-1 text-[11px] text-danger">{form.errors.code}</p>
                            )}
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-ink">
                                NPSN (8 digit)
                            </label>
                            <input
                                value={form.data.npsn}
                                onChange={(e) => form.setData('npsn', e.target.value)}
                                placeholder="20512345"
                                required
                                className="w-full rounded-wf border border-line bg-surface px-3 py-2 font-mono text-xs text-ink"
                            />
                            {form.errors.npsn && (
                                <p className="mt-1 text-[11px] text-danger">{form.errors.npsn}</p>
                            )}
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-ink">
                                Zona Waktu
                            </label>
                            <select
                                value={form.data.timezone}
                                onChange={(e) => form.setData('timezone', e.target.value)}
                                className="w-full rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink"
                            >
                                <option value="Asia/Jakarta">Asia/Jakarta (WIB)</option>
                                <option value="Asia/Makassar">Asia/Makassar (WITA)</option>
                                <option value="Asia/Jayapura">Asia/Jayapura (WIT)</option>
                            </select>
                        </div>
                    </div>

                    <div className="grid gap-3 md:grid-cols-3">
                        <div className="md:col-span-1">
                            <label className="mb-1 block text-xs font-medium text-ink">
                                Nama Lengkap Sekolah
                            </label>
                            <input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="SMK Negeri 1 Sukamaju"
                                required
                                className="w-full rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink"
                            />
                            {form.errors.name && (
                                <p className="mt-1 text-[11px] text-danger">{form.errors.name}</p>
                            )}
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-ink">
                                Nama Admin Pertama
                            </label>
                            <input
                                value={form.data.admin_name}
                                onChange={(e) => form.setData('admin_name', e.target.value)}
                                placeholder="Budi Santoso"
                                required
                                className="w-full rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink"
                            />
                            {form.errors.admin_name && (
                                <p className="mt-1 text-[11px] text-danger">
                                    {form.errors.admin_name}
                                </p>
                            )}
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-ink">
                                Username Admin Pertama
                            </label>
                            <input
                                value={form.data.admin_username}
                                onChange={(e) => form.setData('admin_username', e.target.value)}
                                placeholder="admin.smkn1"
                                required
                                className="w-full rounded-wf border border-line bg-surface px-3 py-2 font-mono text-xs text-ink"
                            />
                            {form.errors.admin_username && (
                                <p className="mt-1 text-[11px] text-danger">
                                    {form.errors.admin_username}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={() => setTampilForm(false)}
                            className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs text-ink-2 hover:bg-surface-2"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-wf bg-accent px-4 py-1.5 text-xs font-medium text-white hover:opacity-90 disabled:opacity-50"
                        >
                            {form.processing ? 'Mendaftarkan...' : 'Daftarkan Sekolah'}
                        </button>
                    </div>
                </form>
            )}

            <form onSubmit={terapkanFilter} className="flex flex-wrap items-center gap-2">
                <input
                    value={cari}
                    onChange={(e) => setCari(e.target.value)}
                    placeholder="Cari nama sekolah, kode, atau NPSN..."
                    className="min-w-[220px] flex-1 rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink"
                />
                <button
                    type="submit"
                    className="rounded-wf border border-line bg-surface px-3 py-2 text-xs font-medium text-ink hover:bg-surface-2"
                >
                    Cari
                </button>
            </form>

            <section className="overflow-hidden rounded-wf border border-line bg-surface">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs">
                        <thead className="border-b border-line bg-surface-2 font-mono text-[10px] uppercase text-ink-3">
                            <tr>
                                <th className="px-4 py-2.5">Sekolah</th>
                                <th className="px-4 py-2.5">Pengguna</th>
                                <th className="px-4 py-2.5">Event</th>
                                <th className="px-4 py-2.5">Portal Publik</th>
                                <th className="px-4 py-2.5">Status</th>
                                <th className="px-4 py-2.5 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sekolah.map((s) => (
                                <tr key={s.id} className="border-b border-line last:border-b-0">
                                    <td className="px-4 py-3">
                                        <p className="font-semibold text-ink">{s.nama}</p>
                                        <p className="font-mono text-[11px] text-ink-3">
                                            {s.kode} · NPSN {s.npsn}
                                        </p>
                                    </td>
                                    <td className="px-4 py-3 font-mono text-ink-2">
                                        {s.siswa} siswa · {s.guru} guru · {s.admin} admin
                                    </td>
                                    <td className="px-4 py-3 font-mono text-ink-2">{s.event}</td>
                                    <td className="px-4 py-3">
                                        {s.papan_slug ? (
                                            <a
                                                href={`/info/${s.papan_slug}`}
                                                target="_blank"
                                                rel="noopener"
                                                className="font-mono text-[11px] text-accent hover:underline"
                                            >
                                                /info/{s.papan_slug}
                                            </a>
                                        ) : (
                                            <span className="text-ink-3">Mati</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span
                                            className={
                                                'rounded-full px-2 py-0.5 font-mono text-[10px] ' +
                                                (s.status === 'aktif'
                                                    ? 'bg-ok-bg text-ok'
                                                    : 'bg-warn-bg text-warn')
                                            }
                                        >
                                            {s.status}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Link
                                            href={`/super/sekolah/${s.id}`}
                                            className="rounded-wf border border-line bg-surface px-2.5 py-1 text-[11px] font-medium text-accent hover:bg-surface-2"
                                        >
                                            Kelola
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </SuperLayout>
    );
}
