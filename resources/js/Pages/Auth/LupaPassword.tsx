import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

/**
 * Laman 02 - Lupa password. Wireframe plate 02.
 *
 * Siswa tidak selalu punya email. Untuk mereka reset dilakukan Admin lewat
 * laman Pengguna, bukan lewat laman ini - dan itulah alasan laman ini
 * menyebut Admin sekolah secara eksplisit, bukan sekadar memasang form lalu
 * diam.
 *
 * Fase 1 belum mengirim surel apa pun. Form ini sengaja tidak punya rute
 * kirim: alur yang belum bisa diuji tidak dibangun setengah jadi.
 */
export default function LupaPassword() {
    const { data, setData } = useForm({ username: '' });

    function kirim(e: FormEvent) {
        e.preventDefault();
    }

    return (
        <>
            <Head title="Lupa password" />

            <div className="flex min-h-screen flex-col items-center justify-center bg-wf-soft px-4 py-10">
                <div className="w-full max-w-sm rounded-wf border border-line bg-surface p-6 shadow-wf">
                    <p className="font-display text-lg font-semibold text-accent">
                        Lupa password
                    </p>
                    <p className="mt-1 text-xs leading-relaxed text-ink-3">
                        Masukkan username atau email akun Anda.
                    </p>

                    <label
                        htmlFor="username"
                        className="mt-6 block text-xs font-medium tracking-wide text-ink-2 uppercase"
                    >
                        Username atau email
                    </label>
                    <input
                        id="username"
                        name="username"
                        type="text"
                        autoComplete="username"
                        value={data.username}
                        onChange={(e) => setData('username', e.target.value)}
                        className="mt-1.5 w-full rounded-wf border border-line bg-surface px-3 py-2 font-mono text-sm text-ink outline-none focus:border-accent focus:ring-2 focus:ring-accent-soft"
                    />

                    <button
                        type="submit"
                        onClick={kirim}
                        className="mt-5 rounded-wf bg-accent px-4 py-2 text-sm font-medium text-on-accent transition-opacity hover:opacity-90"
                    >
                        Kirim tautan
                    </button>

                    <p className="mt-5 border-t border-line pt-4 text-xs leading-relaxed text-ink-3">
                        Email tidak masuk? Hubungi Admin sekolah Anda. Untuk siswa
                        tanpa email, reset password dilakukan Admin lewat laman
                        Pengguna.
                    </p>

                    <Link
                        href="/login"
                        className="mt-4 inline-block text-sm text-accent hover:underline"
                    >
                        &larr; Kembali ke login
                    </Link>
                </div>
            </div>
        </>
    );
}
