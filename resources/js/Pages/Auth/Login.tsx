import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { MEREK } from '@/merek';

/**
 * Laman 01 - Login. Wireframe plate 01.
 *
 * Satu form untuk keempat peran. Dua kolom isian saja: username dan password.
 * Tidak ada NPSN, tidak ada kode sekolah, tidak ada pemilih konteks - role
 * hanya menentukan halaman tujuan setelah autentikasi berhasil.
 *
 * Pesan gagal selalu tunggal dan datang dari server. Laman ini tidak pernah
 * menyusun pesannya sendiri, supaya tidak ada kesempatan menambahkan
 * perbedaan yang membocorkan apakah username atau password yang keliru.
 */
export default function Login({ errors }: { errors: Record<string, string> }) {
    const { data, setData, post, processing } = useForm({
        username: '',
        password: '',
        remember: false,
    });

    function kirim(e: FormEvent) {
        e.preventDefault();
        post('/login');
    }

    return (
        <>
            <Head title="Masuk" />

            <div className="flex min-h-screen flex-col items-center justify-center bg-wf-soft px-4 py-10">
                <form
                    onSubmit={kirim}
                    className="w-full max-w-sm rounded-wf border border-line bg-surface p-6 shadow-wf"
                >
                    <p className="font-display text-lg font-semibold text-accent">
                        {MEREK}
                    </p>
                    <p className="mt-1 text-xs text-ink-3">
                        Masuk dengan akun sekolah Anda.
                    </p>

                    <label
                        htmlFor="username"
                        className="mt-6 block text-xs font-medium tracking-wide text-ink-2 uppercase"
                    >
                        Username
                    </label>
                    <input
                        id="username"
                        name="username"
                        type="text"
                        autoComplete="username"
                        autoFocus
                        required
                        value={data.username}
                        onChange={(e) => setData('username', e.target.value)}
                        placeholder="admin.sman1"
                        className="mt-1.5 w-full rounded-wf border border-line bg-surface px-3 py-2 font-mono text-sm text-ink outline-none focus:border-accent focus:ring-2 focus:ring-accent-soft"
                    />

                    <label
                        htmlFor="password"
                        className="mt-4 block text-xs font-medium tracking-wide text-ink-2 uppercase"
                    >
                        Password
                    </label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autoComplete="current-password"
                        required
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        className="mt-1.5 w-full rounded-wf border border-line bg-surface px-3 py-2 font-mono text-sm text-ink outline-none focus:border-accent focus:ring-2 focus:ring-accent-soft"
                    />

                    {errors.username && (
                        <p
                            role="alert"
                            className="mt-3 rounded-wf border border-bad/30 bg-bad-bg px-3 py-2 text-sm text-bad"
                        >
                            {errors.username}
                        </p>
                    )}

                    <div className="mt-5 flex items-center gap-4">
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-wf bg-accent px-4 py-2 text-sm font-medium text-on-accent transition-opacity hover:opacity-90 disabled:opacity-50"
                        >
                            Masuk
                        </button>

                        <Link
                            href="/lupa-password"
                            className="text-sm text-ink-3 hover:text-accent hover:underline"
                        >
                            Lupa password?
                        </Link>
                    </div>
                </form>

                <p className="mt-5 max-w-sm text-center text-xs leading-relaxed text-ink-3">
                    Username tidak ada atau password salah &mdash; pesannya sama,
                    supaya tidak bisa dipakai menebak akun mana yang ada.
                </p>
            </div>
        </>
    );
}
