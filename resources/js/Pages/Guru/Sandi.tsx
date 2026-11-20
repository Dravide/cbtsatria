import { Head, Link, useForm } from '@inertiajs/react';
import GuruLayout from '@/Layouts/GuruLayout';
import { Kotak, Peringatan, Tombol, kelasInput } from '@/Components/ui';

type Props = {
    nama: string;
    username: string;
    sandi_bawaan: boolean;
};

export default function GuruSandi({ nama, username, sandi_bawaan }: Props) {
    const { data, setData, put, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const kirim = (e: React.FormEvent) => {
        e.preventDefault();
        put('/guru/sandi', {
            onSuccess: () => reset(),
        });
    };

    return (
        <GuruLayout judul="Ganti sandi" breadcrumb="/guru · Ganti sandi">
            <Head title="Ganti sandi" />

            <div className="max-w-lg space-y-4">
                {sandi_bawaan && (
                    <Peringatan>
                        Akun <strong>{username}</strong> ({nama}) masih memakai sandi bawaan dari sekolah.
                        Segera ganti dengan sandi pribadi yang mudah Anda ingat dan aman.
                    </Peringatan>
                )}

                <Kotak judul="Ubah sandi akun">
                    <form onSubmit={kirim} className="space-y-3">
                        <div>
                            <label className="mb-1 block text-xs font-medium text-ink-2">
                                Sandi saat ini
                            </label>
                            <input
                                type="password"
                                value={data.current_password}
                                onChange={(e) => setData('current_password', e.target.value)}
                                autoComplete="current-password"
                                className={kelasInput}
                            />
                            {errors.current_password && (
                                <p className="mt-1 text-xs text-bad">{errors.current_password}</p>
                            )}
                        </div>

                        <div>
                            <label className="mb-1 block text-xs font-medium text-ink-2">
                                Sandi baru (minimal 8 karakter)
                            </label>
                            <input
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                autoComplete="new-password"
                                className={kelasInput}
                            />
                            {errors.password && (
                                <p className="mt-1 text-xs text-bad">{errors.password}</p>
                            )}
                        </div>

                        <div>
                            <label className="mb-1 block text-xs font-medium text-ink-2">
                                Ulangi sandi baru
                            </label>
                            <input
                                type="password"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                autoComplete="new-password"
                                className={kelasInput}
                            />
                        </div>

                        <div className="flex flex-wrap items-center gap-2 pt-2">
                            <Tombol type="submit" nada="pri" disabled={processing}>
                                Simpan sandi baru
                            </Tombol>
                            <Link
                                href="/guru"
                                className="inline-grid min-h-9 place-items-center rounded-wf border border-line bg-surface px-3 text-xs text-ink-2 hover:bg-surface-2"
                            >
                                Kembali
                            </Link>
                        </div>
                    </form>
                </Kotak>
            </div>
        </GuruLayout>
    );
}
