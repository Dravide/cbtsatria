import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
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
            subjudul={`Kode ${sekolah.kode} · NPSN ${sekolah.npsn} · ${statistik.siswa} siswa · ${statistik.guru} guru · ${statistik.event} event`}
            aksi={
                <Link
                    href="/super/sekolah"
                    className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs text-ink-2 hover:bg-surface-2"
                >
                    &larr; Daftar Sekolah
                </Link>
            }
        >
            <div className="grid gap-4 lg:grid-cols-2">
                {/* Pengaturan & Kuota */}
                <form
                    onSubmit={simpanPengaturan}
                    className="space-y-3 rounded-wf border border-line bg-surface p-4"
                >
                    <h2 className="text-sm font-semibold text-ink">
                        Profil, Status, Kuota &amp; Saklar Modul
                    </h2>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-xs font-medium text-ink">
                                Nama Sekolah
                            </label>
                            <input
                                value={formEdit.data.name}
                                onChange={(e) => formEdit.setData('name', e.target.value)}
                                className="w-full rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink"
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-ink">
                                NPSN (koreksi Super Admin)
                            </label>
                            <input
                                value={formEdit.data.npsn}
                                onChange={(e) => formEdit.setData('npsn', e.target.value)}
                                className="w-full rounded-wf border border-line bg-surface px-3 py-2 font-mono text-xs text-ink"
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-ink">
                                Status Tenant
                            </label>
                            <select
                                value={formEdit.data.status}
                                onChange={(e) => formEdit.setData('status', e.target.value)}
                                className="w-full rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink"
                            >
                                <option value="aktif">Aktif</option>
                                <option value="ditangguhkan">Ditangguhkan (blokir login)</option>
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-ink">
                                Zona Waktu
                            </label>
                            <select
                                value={formEdit.data.timezone}
                                onChange={(e) => formEdit.setData('timezone', e.target.value)}
                                className="w-full rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink"
                            >
                                <option value="Asia/Jakarta">Asia/Jakarta</option>
                                <option value="Asia/Makassar">Asia/Makassar</option>
                                <option value="Asia/Jayapura">Asia/Jayapura</option>
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-ink">
                                Kuota Maks Siswa (0 = tanpa batas)
                            </label>
                            <input
                                type="number"
                                min={0}
                                value={formEdit.data.maks_siswa}
                                onChange={(e) =>
                                    formEdit.setData('maks_siswa', Number(e.target.value))
                                }
                                className="w-full rounded-wf border border-line bg-surface px-3 py-2 font-mono text-xs text-ink"
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-ink">
                                Kuota Storage MB (0 = tanpa batas)
                            </label>
                            <input
                                type="number"
                                min={0}
                                value={formEdit.data.maks_storage_mb}
                                onChange={(e) =>
                                    formEdit.setData('maks_storage_mb', Number(e.target.value))
                                }
                                className="w-full rounded-wf border border-line bg-surface px-3 py-2 font-mono text-xs text-ink"
                            />
                        </div>
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

                    <button
                        type="submit"
                        disabled={formEdit.processing}
                        className="rounded-wf bg-accent px-4 py-1.5 text-xs font-medium text-white hover:opacity-90 disabled:opacity-50"
                    >
                        {formEdit.processing ? 'Menyimpan...' : 'Simpan Pengaturan'}
                    </button>
                </form>

                {/* Kanan: Impersonate + Admin Sekolah */}
                <div className="space-y-4">
                    <form
                        onSubmit={mulaiImpersonasi}
                        className="space-y-2.5 rounded-wf border border-warn/40 bg-warn-bg/60 p-4"
                    >
                        <h2 className="text-sm font-semibold text-warn">
                            Mode Dukung (Masuk sebagai Admin Sekolah)
                        </h2>
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
                                className="min-w-0 flex-1 rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink"
                            />
                            <button
                                type="submit"
                                disabled={formImpersonasi.processing}
                                className="shrink-0 rounded-wf bg-warn px-3 py-2 text-xs font-semibold text-white hover:opacity-90"
                            >
                                Masuk Admin
                            </button>
                        </div>
                        {formImpersonasi.errors.alasan && (
                            <p className="text-[11px] text-danger">
                                {formImpersonasi.errors.alasan}
                            </p>
                        )}
                    </form>

                    <section className="overflow-hidden rounded-wf border border-line bg-surface">
                        <div className="border-b border-line bg-surface-2 px-4 py-2.5">
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
                                        <p className="font-semibold text-ink">{a.nama}</p>
                                        <p className="font-mono text-[11px] text-ink-3">
                                            {a.username}
                                            {a.terakhir_masuk
                                                ? ` · masuk ${a.terakhir_masuk}`
                                                : ''}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.post(
                                                `/super/sekolah/${sekolah.id}/admin/${a.id}/reset-sandi`,
                                            )
                                        }
                                        className="shrink-0 rounded-wf border border-line bg-surface px-2.5 py-1 text-[11px] text-ink hover:bg-surface-2"
                                    >
                                        Reset Sandi
                                    </button>
                                </li>
                            ))}
                        </ul>

                        <form
                            onSubmit={tambahAdmin}
                            className="space-y-2 border-t border-line bg-surface-2/60 p-3.5"
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
                                    className="rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink"
                                />
                                <input
                                    value={formAdmin.data.username}
                                    onChange={(e) => formAdmin.setData('username', e.target.value)}
                                    placeholder="username.admin"
                                    required
                                    className="rounded-wf border border-line bg-surface px-2.5 py-1.5 font-mono text-xs text-ink"
                                />
                            </div>
                            <button
                                type="submit"
                                disabled={formAdmin.processing}
                                className="rounded-wf bg-accent px-3 py-1.5 text-xs font-medium text-white hover:opacity-90"
                            >
                                + Tambah Admin
                            </button>
                        </form>
                    </section>
                </div>
            </div>
        </SuperLayout>
    );
}
