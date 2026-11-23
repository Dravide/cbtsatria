import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import Ikon from '@/Components/Ikon';
import { Chip, Field, Tombol } from '@/Components/ui';
import SuperLayout from '@/Layouts/SuperLayout';

type AdminRow = {
    id: number;
    nama: string;
    username: string;
    email: string | null;
    aktif: boolean;
    terakhir_masuk: string | null;
};

type Props = {
    sekolah: {
        id: number;
        kode: string;
        nama: string;
        npsn: string;
        email: string | null;
        phone: string | null;
        address: string | null;
        timezone: string;
        status: string;
        papan_slug: string | null;
        kuota: {
            maks_siswa: number;
            maks_storage_mb: number;
        };
        modul: {
            ai: boolean;
            publik: boolean;
            waktu_luang: boolean;
        };
    };
    statistik: {
        siswa: number;
        guru: number;
        event: number;
    };
    admins: AdminRow[];
};

const INPUT =
    'w-full rounded-wf border border-line bg-surface px-3 py-1.5 text-xs text-ink outline-none placeholder:text-ink-3 focus:border-accent focus:ring-2 focus:ring-accent/15';

export default function SekolahShow({ sekolah, statistik, admins }: Props) {
    const formEdit = useForm({
        name: sekolah.nama,
        npsn: sekolah.npsn,
        status: sekolah.status,
        timezone: sekolah.timezone,
        maks_siswa: sekolah.kuota.maks_siswa,
        maks_storage_mb: sekolah.kuota.maks_storage_mb,
        modul_ai: sekolah.modul.ai,
        modul_publik: sekolah.modul.publik,
        modul_waktu_luang: sekolah.modul.waktu_luang,
    });

    const formAdmin = useForm({
        name: '',
        username: '',
        email: '',
    });

    const formImpersonasi = useForm({
        alasan: '',
    });

    const simpanPengaturan = (e: FormEvent) => {
        e.preventDefault();
        formEdit.patch(`/super/sekolah/${sekolah.id}`, { preserveScroll: true });
    };

    const tambahAdmin = (e: FormEvent) => {
        e.preventDefault();
        formAdmin.post(`/super/sekolah/${sekolah.id}/admin`, {
            preserveScroll: true,
            onSuccess: () => formAdmin.reset(),
        });
    };

    const mulaiImpersonasi = (e: FormEvent) => {
        e.preventDefault();
        formImpersonasi.post(`/super/sekolah/${sekolah.id}/impersonasi`);
    };

    return (
        <SuperLayout
            judul={sekolah.nama}
            breadcrumb={`Super Admin / Sekolah / ${sekolah.kode}`}
            subjudul={`NPSN ${sekolah.npsn} · ${statistik.siswa} siswa · ${statistik.guru} guru · ${statistik.event} event`}
            aksi={
                <div className="flex items-center gap-2">
                    <Chip nada={sekolah.status === 'aktif' ? 'ok' : 'warn'}>{sekolah.status}</Chip>
                    <Link
                        href="/super/sekolah"
                        className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs text-ink-2 hover:bg-surface-2"
                    >
                        &larr; Daftar Sekolah
                    </Link>
                </div>
            }
        >
            <div className="grid gap-4 lg:grid-cols-2">
                <form
                    onSubmit={simpanPengaturan}
                    className="space-y-3 rounded-wf border border-line bg-surface p-4"
                >
                    <div className="flex items-center gap-2 border-b border-line pb-2.5">
                        <Ikon nama="pengaturan" ukuran={15} className="text-ink-3" />
                        <h2 className="text-xs font-semibold text-ink">
                            Profil, Status, Kuota &amp; Saklar Modul
                        </h2>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Nama Sekolah" galat={formEdit.errors.name}>
                            <input
                                value={formEdit.data.name}
                                onChange={(e) => formEdit.setData('name', e.target.value)}
                                className={INPUT}
                            />
                        </Field>
                        <Field label="NPSN (koreksi Super Admin)" galat={formEdit.errors.npsn}>
                            <input
                                value={formEdit.data.npsn}
                                onChange={(e) => formEdit.setData('npsn', e.target.value)}
                                className={`${INPUT} font-mono`}
                            />
                        </Field>
                        <Field label="Status Tenant">
                            <select
                                value={formEdit.data.status}
                                onChange={(e) => formEdit.setData('status', e.target.value)}
                                className={INPUT}
                            >
                                <option value="aktif">Aktif</option>
                                <option value="ditangguhkan">Ditangguhkan (blokir login)</option>
                            </select>
                        </Field>
                        <Field label="Zona Waktu">
                            <select
                                value={formEdit.data.timezone}
                                onChange={(e) => formEdit.setData('timezone', e.target.value)}
                                className={INPUT}
                            >
                                <option value="Asia/Jakarta">Asia/Jakarta</option>
                                <option value="Asia/Makassar">Asia/Makassar</option>
                                <option value="Asia/Jayapura">Asia/Jayapura</option>
                            </select>
                        </Field>
                        <Field label="Kuota Maks Siswa (0 = tanpa batas)">
                            <input
                                type="number"
                                min={0}
                                value={formEdit.data.maks_siswa}
                                onChange={(e) =>
                                    formEdit.setData('maks_siswa', Number(e.target.value))
                                }
                                className={`${INPUT} font-mono`}
                            />
                        </Field>
                        <Field label="Kuota Storage MB (0 = tanpa batas)">
                            <input
                                type="number"
                                min={0}
                                value={formEdit.data.maks_storage_mb}
                                onChange={(e) =>
                                    formEdit.setData('maks_storage_mb', Number(e.target.value))
                                }
                                className={`${INPUT} font-mono`}
                            />
                        </Field>
                    </div>

                    <div className="space-y-2 border-t border-line pt-3">
                        <label className="flex items-center justify-between text-xs text-ink">
                            <span>Fitur AI (Generate Soal &amp; Usulan Koreksi)</span>
                            <input
                                type="checkbox"
                                checked={formEdit.data.modul_ai}
                                onChange={(e) => formEdit.setData('modul_ai', e.target.checked)}
                            />
                        </label>
                        <label className="flex items-center justify-between text-xs text-ink">
                            <span>Laman Publik (Portal, Papan, Pantau, TV)</span>
                            <input
                                type="checkbox"
                                checked={formEdit.data.modul_publik}
                                onChange={(e) => formEdit.setData('modul_publik', e.target.checked)}
                            />
                        </label>
                        <label className="flex items-center justify-between text-xs text-ink">
                            <span>Permainan Waktu Luang &amp; Lomba</span>
                            <input
                                type="checkbox"
                                checked={formEdit.data.modul_waktu_luang}
                                onChange={(e) =>
                                    formEdit.setData('modul_waktu_luang', e.target.checked)
                                }
                            />
                        </label>
                    </div>

                    <Tombol nada="pri" type="submit" disabled={formEdit.processing}>
                        {formEdit.processing ? 'Menyimpan...' : 'Simpan Pengaturan'}
                    </Tombol>
                </form>

                <div className="space-y-4">
                    <form
                        onSubmit={mulaiImpersonasi}
                        className="space-y-2.5 rounded-wf border border-warn/40 bg-warn-bg/60 p-4"
                    >
                        <div className="flex items-center gap-2 text-warn">
                            <Ikon nama="perisai" ukuran={16} />
                            <h2 className="text-xs font-semibold">
                                Mode Dukung (Masuk sebagai Admin Sekolah)
                            </h2>
                        </div>
                        <p className="text-xs text-ink-2">
                            Berlaku 30 menit, menampilkan banner kuning di konsol sekolah, dan
                            tercatat di log audit platform.
                        </p>
                        <div className="flex gap-2">
                            <input
                                value={formImpersonasi.data.alasan}
                                onChange={(e) => formImpersonasi.setData('alasan', e.target.value)}
                                placeholder="Tulis alasan dukungan (min. 5 karakter)..."
                                required
                                className={INPUT}
                            />
                            <Tombol nada="pri" type="submit" disabled={formImpersonasi.processing}>
                                Masuk
                            </Tombol>
                        </div>
                        {formImpersonasi.errors.alasan && (
                            <p className="text-[11px] text-bad">{formImpersonasi.errors.alasan}</p>
                        )}
                    </form>

                    <section className="overflow-hidden rounded-wf border border-line bg-surface">
                        <div className="flex items-center gap-2 border-b border-line bg-surface-2/60 px-4 py-2.5">
                            <Ikon nama="pengguna" ukuran={15} className="text-ink-3" />
                            <h2 className="text-xs font-semibold text-ink">
                                Akun Admin Sekolah ({admins.length})
                            </h2>
                        </div>
                        <ul className="divide-y divide-line">
                            {admins.map((a) => (
                                <li
                                    key={a.id}
                                    className="flex items-center justify-between gap-2 px-4 py-2.5 text-xs"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium text-ink">{a.nama}</p>
                                        <p className="font-mono text-[11px] text-ink-3">
                                            {a.username}
                                            {a.terakhir_masuk
                                                ? ` · masuk ${a.terakhir_masuk}`
                                                : ''}
                                        </p>
                                    </div>
                                    <Tombol
                                        onClick={() =>
                                            router.post(
                                                `/super/sekolah/${sekolah.id}/admin/${a.id}/reset-sandi`,
                                            )
                                        }
                                    >
                                        <Ikon nama="kunci" ukuran={13} />
                                        Reset Sandi
                                    </Tombol>
                                </li>
                            ))}
                        </ul>

                        <form
                            onSubmit={tambahAdmin}
                            className="space-y-2 border-t border-line bg-surface-2/40 p-3.5"
                        >
                            <p className="text-[11px] font-semibold text-ink">
                                Tambah Admin Sekolah Baru
                            </p>
                            <div className="grid gap-2 sm:grid-cols-2">
                                <input
                                    value={formAdmin.data.name}
                                    onChange={(e) => formAdmin.setData('name', e.target.value)}
                                    placeholder="Nama Admin"
                                    required
                                    className={INPUT}
                                />
                                <input
                                    value={formAdmin.data.username}
                                    onChange={(e) => formAdmin.setData('username', e.target.value)}
                                    placeholder="username.admin"
                                    required
                                    className={`${INPUT} font-mono`}
                                />
                            </div>
                            <Tombol nada="pri" type="submit" disabled={formAdmin.processing}>
                                <Ikon nama="tambah" ukuran={13} />
                                Tambah Admin
                            </Tombol>
                        </form>
                    </section>
                </div>
            </div>
        </SuperLayout>
    );
}
