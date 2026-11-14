import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Ikon from '@/Components/Ikon';
import { kelasInput } from '@/Components/ui';
import { MEREK } from '@/merek';

/**
 * Laman 01 - Login. Wireframe plate 01.
 *
 * Dua panel: kiri keterangan, kanan formulir. Dipisah begitu karena laman ini
 * adalah pintu untuk EMPAT peran, dan yang pertama kali dibaca seseorang yang
 * baru pertama masuk bukanlah kolom isian melainkan "username saya yang mana?"
 * - pertanyaan yang dijawab panel kiri, bukan oleh placeholder.
 *
 * Satu form untuk keempat peran. Dua kolom isian saja: username dan password.
 * Tidak ada NPSN, tidak ada kode sekolah, tidak ada pemilih konteks - role
 * hanya menentukan halaman tujuan setelah autentikasi berhasil.
 *
 * Pesan gagal selalu tunggal dan datang dari server. Laman ini tidak pernah
 * menyusun pesannya sendiri, supaya tidak ada kesempatan menambahkan
 * perbedaan yang membocorkan apakah username atau password yang keliru.
 *
 * ---------------------------------------------------------------------
 * Mengapa sandi bisa ditampilkan, padahal ia pintu masuk
 * ---------------------------------------------------------------------
 * Tombol mata ada di sini karena laman ini dipakai HARI UJIAN, oleh pengawas
 * yang membacakan akun siswa di depan ruang dan oleh siswa yang baru pertama
 * kali memegang perangkat. Mengetik sandi buta di situ bukan kenyamanan yang
 * dikorbankan demi keamanan - sandi itu sudah dibacakan dengan suara keras di
 * ruang yang sama. Yang benar-benar melindungi akun ada di server: satu pesan
 * untuk semua kegagalan, dan penguncian setelah tiga percobaan.
 *
 * Bawaannya tetap TERSEMBUNYI. Yang berubah hanyalah bahwa menyembunyikannya
 * tidak lagi wajib.
 */
export default function Login({ errors }: { errors: Record<string, string> }) {
    const { data, setData, post, processing } = useForm({
        username: '',
        password: '',
        remember: false,
    });

    const [sandiTerlihat, setSandiTerlihat] = useState(false);

    function kirim(e: FormEvent) {
        e.preventDefault();
        post('/login');
    }

    return (
        <>
            <Head title="Masuk" />

            <div className="flex min-h-screen items-center justify-center bg-wf-soft px-4 py-10">
                <div className="grid w-full max-w-3xl overflow-hidden rounded-wf border border-line bg-surface shadow-wf md:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]">
                    {/* Panel kiri - keterangan. Di layar sempit ia menjadi
                        kepala kartu, bukan panel yang mendorong form ke bawah
                        lipatan. */}
                    <aside className="border-b border-line bg-wf-soft px-6 py-7 md:border-r md:border-b-0 md:px-7 md:py-9">
                        <p className="font-display text-xl font-semibold tracking-tight text-accent">
                            {MEREK}
                        </p>
                        <p className="mt-2 text-sm leading-relaxed text-ink-2">
                            Satu platform, banyak sekolah.
                        </p>

                        {/* Daftar ini HANYA untuk layar lebar. Di ponsel ia
                            mendorong kolom sandi ke bawah lipatan, dan laman
                            ini dibuka di hari ujian oleh orang yang sedang
                            berdiri memegang perangkat. Yang perlu terbaca
                            tanpa menggulir adalah kolom isiannya. */}
                        <dl className="mt-7 hidden space-y-3 text-xs leading-relaxed md:block">
                            <div>
                                <dt className="font-medium text-ink-2">Isi username Anda</dt>
                                <dd className="mt-0.5 text-ink-3">
                                    NIS untuk siswa, NIP untuk guru, atau akun staf
                                    yang diberikan Admin.
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium text-ink-2">Bukan alamat surel</dt>
                                <dd className="mt-0.5 text-ink-3">
                                    Meski bentuknya mirip, yang diminta adalah username.
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium text-ink-2">Tidak bisa masuk?</dt>
                                <dd className="mt-0.5 text-ink-3">
                                    Hubungi Admin sekolah. Sandi tidak bisa diatur
                                    ulang dari sini.
                                </dd>
                            </div>
                        </dl>
                    </aside>

                    {/* Panel kanan - formulir. */}
                    <div className="px-6 py-7 md:px-7 md:py-9">
                        <h1 className="font-display text-lg font-semibold text-ink">Masuk</h1>
                        <p className="mt-1 text-xs text-ink-3">
                            Masuk dengan akun sekolah Anda.
                        </p>

                        <form onSubmit={kirim} className="mt-6">
                            <label
                                htmlFor="username"
                                className="block text-xs font-medium tracking-wide text-ink-2 uppercase"
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
                                aria-invalid={errors.username ? true : undefined}
                                aria-describedby={errors.username ? 'galat-username' : undefined}
                                className={'mt-1.5 ' + kelasInput + ' font-mono'}
                            />
                            {errors.username && (
                                <p
                                    id="galat-username"
                                    role="alert"
                                    className="mt-1.5 text-[11px] text-bad"
                                >
                                    {errors.username}
                                </p>
                            )}

                            <label
                                htmlFor="password"
                                className="mt-4 block text-xs font-medium tracking-wide text-ink-2 uppercase"
                            >
                                Password
                            </label>
                            {/* `relative` pada pembungkus, bukan pada input:
                                tombolnya harus menempel di dalam kotak isian,
                                dan kotak itu yang menentukan tingginya. */}
                            <div className="relative mt-1.5">
                                <input
                                    id="password"
                                    name="password"
                                    type={sandiTerlihat ? 'text' : 'password'}
                                    autoComplete="current-password"
                                    required
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    aria-invalid={errors.password ? true : undefined}
                                    aria-describedby={
                                        errors.password ? 'galat-password' : undefined
                                    }
                                    // `pr-11` menahan teks agar tidak masuk ke
                                    // bawah tombolnya saat sandinya panjang.
                                    className={kelasInput + ' pr-11 font-mono'}
                                />
                                <button
                                    type="button"
                                    onClick={() => setSandiTerlihat((v) => !v)}
                                    aria-label={
                                        sandiTerlihat ? 'Sembunyikan sandi' : 'Tampilkan sandi'
                                    }
                                    aria-pressed={sandiTerlihat}
                                    title={sandiTerlihat ? 'Sembunyikan sandi' : 'Tampilkan sandi'}
                                    className="absolute top-1/2 right-1 flex size-8 -translate-y-1/2 items-center justify-center rounded-wf text-ink-3 transition-colors hover:bg-surface-2 hover:text-ink focus-visible:ring-2 focus-visible:ring-accent/40 focus-visible:outline-none"
                                >
                                    <Ikon nama={sandiTerlihat ? 'mata-coret' : 'mata'} />
                                </button>
                            </div>
                            {errors.password && (
                                <p
                                    id="galat-password"
                                    role="alert"
                                    className="mt-1.5 text-[11px] text-bad"
                                >
                                    {errors.password}
                                </p>
                            )}

                            <div className="mt-4 flex items-center justify-between gap-4">
                                <label className="flex cursor-pointer items-center gap-2 text-xs text-ink-2 select-none">
                                    <input
                                        type="checkbox"
                                        name="remember"
                                        checked={data.remember}
                                        onChange={(e) => setData('remember', e.target.checked)}
                                        className="size-3.5 rounded-sm border-line text-accent focus:ring-2 focus:ring-accent/30"
                                    />
                                    Ingat saya
                                </label>

                                <Link
                                    href="/lupa-password"
                                    className="text-xs text-ink-3 hover:text-accent hover:underline"
                                >
                                    Lupa password?
                                </Link>
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="mt-6 w-full rounded-wf bg-accent px-4 py-2.5 text-sm font-medium text-on-accent transition-opacity hover:opacity-90 disabled:opacity-50"
                            >
                                {processing ? 'Memeriksa…' : 'Masuk'}
                            </button>
                        </form>

                        <p className="mt-5 border-t border-line pt-4 text-[11px] leading-relaxed text-ink-3">
                            Username tidak ada atau password salah &mdash; pesannya sama,
                            supaya tidak bisa dipakai menebak akun mana yang ada. Tiga
                            kali gagal, akun terkunci sementara 60 detik.
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
