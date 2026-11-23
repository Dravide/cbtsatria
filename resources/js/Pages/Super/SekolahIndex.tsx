import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Ikon from '@/Components/Ikon';
import { Chip, Field, Tombol } from '@/Components/ui';
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

const INPUT =
    'w-full rounded-wf border border-line bg-surface px-3 py-1.5 text-xs text-ink outline-none placeholder:text-ink-3 focus:border-accent focus:ring-2 focus:ring-accent/15';

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
            breadcrumb="Super Admin / Sekolah"
            subjudul={`${sekolah.length} sekolah terdaftar di platform`}
            aksi={
                <Tombol nada="pri" onClick={() => setTampilForm((v) => !v)}>
                    <Ikon nama="tambah" ukuran={14} />
                    {tampilForm ? 'Tutup Form' : 'Daftarkan Sekolah'}
                </Tombol>
            }
        >
            {tampilForm && (
                <form
                    onSubmit={simpanSekolah}
                    className="mb-4 space-y-3 rounded-wf border border-accent/40 bg-surface p-4"
                >
                    <div className="flex items-center gap-2">
                        <Ikon nama="sekolah" ukuran={16} className="text-accent" />
                        <div>
                            <h2 className="text-sm font-semibold text-ink">
                                Daftarkan Sekolah Baru (1 Transaksi)
                            </h2>
                            <p className="text-xs text-ink-3">
                                Otomatis membuat Sekolah, Admin pertama (sandi acak tampil sekali),
                                Tahun Ajaran aktif, serta Semester Ganjil &amp; Genap.
                            </p>
                        </div>
                    </div>

                    <div className="grid gap-3 md:grid-cols-3">
                        <Field label="Kode Sekolah" galat={form.errors.code}>
                            <input
                                value={form.data.code}
                                onChange={(e) => form.setData('code', e.target.value)}
                                placeholder="SMKN1"
                                required
                                className={`${INPUT} font-mono`}
                            />
                        </Field>
                        <Field label="NPSN (8 digit)" galat={form.errors.npsn}>
                            <input
                                value={form.data.npsn}
                                onChange={(e) => form.setData('npsn', e.target.value)}
                                placeholder="20512345"
                                required
                                className={`${INPUT} font-mono`}
                            />
                        </Field>
                        <Field label="Zona Waktu">
                            <select
                                value={form.data.timezone}
                                onChange={(e) => form.setData('timezone', e.target.value)}
                                className={INPUT}
                            >
                                <option value="Asia/Jakarta">Asia/Jakarta (WIB)</option>
                                <option value="Asia/Makassar">Asia/Makassar (WITA)</option>
                                <option value="Asia/Jayapura">Asia/Jayapura (WIT)</option>
                            </select>
                        </Field>
                    </div>

                    <div className="grid gap-3 md:grid-cols-3">
                        <Field label="Nama Lengkap Sekolah" galat={form.errors.name}>
                            <input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="SMK Negeri 1 Sukamaju"
                                required
                                className={INPUT}
                            />
                        </Field>
                        <Field label="Nama Admin Pertama" galat={form.errors.admin_name}>
                            <input
                                value={form.data.admin_name}
                                onChange={(e) => form.setData('admin_name', e.target.value)}
                                placeholder="Budi Santoso"
                                required
                                className={INPUT}
                            />
                        </Field>
                        <Field label="Username Admin Pertama" galat={form.errors.admin_username}>
                            <input
                                value={form.data.admin_username}
                                onChange={(e) => form.setData('admin_username', e.target.value)}
                                placeholder="admin.smkn1"
                                required
                                className={`${INPUT} font-mono`}
                            />
                        </Field>
                    </div>

                    <div className="flex justify-end gap-2 pt-1">
                        <Tombol onClick={() => setTampilForm(false)}>Batal</Tombol>
                        <Tombol nada="pri" type="submit" disabled={form.processing}>
                            {form.processing ? 'Mendaftarkan...' : 'Daftarkan Sekolah'}
                        </Tombol>
                    </div>
                </form>
            )}

            <form onSubmit={terapkanFilter} className="mb-4 flex flex-wrap items-center gap-2">
                <div className="relative min-w-[240px] flex-1">
                    <Ikon
                        nama="cari"
                        ukuran={14}
                        className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3"
                    />
                    <input
                        value={cari}
                        onChange={(e) => setCari(e.target.value)}
                        placeholder="Cari nama sekolah, kode, atau NPSN..."
                        className="w-full rounded-wf border border-line bg-surface py-1.5 pr-3 pl-8 text-xs text-ink outline-none placeholder:text-ink-3 focus:border-accent focus:ring-2 focus:ring-accent/15"
                    />
                </div>
                <Tombol type="submit">Cari</Tombol>
            </form>

            <section className="overflow-hidden rounded-wf border border-line bg-surface">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs">
                        <thead className="border-b border-line bg-surface-2/60 font-mono text-[10px] uppercase text-ink-3">
                            <tr>
                                <th className="px-4 py-2.5">Sekolah</th>
                                <th className="px-4 py-2.5">Pengguna</th>
                                <th className="px-4 py-2.5">Event</th>
                                <th className="px-4 py-2.5">Portal Publik</th>
                                <th className="px-4 py-2.5">Status</th>
                                <th className="px-4 py-2.5 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {sekolah.map((s) => (
                                <tr key={s.id} className="hover:bg-surface-2/40">
                                    <td className="px-4 py-3">
                                        <p className="font-medium text-ink">{s.nama}</p>
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
                                            <Chip nada="mute">Mati</Chip>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Chip nada={s.status === 'aktif' ? 'ok' : 'warn'}>
                                            {s.status}
                                        </Chip>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Link
                                            href={`/super/sekolah/${s.id}`}
                                            className="inline-flex items-center gap-1 rounded-wf border border-line bg-surface px-2.5 py-1 text-[11px] font-medium text-accent hover:bg-surface-2"
                                        >
                                            Kelola
                                            <Ikon nama="panah" ukuran={12} />
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
