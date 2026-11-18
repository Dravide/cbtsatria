import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Kios per-PC ruang lab - `/lab/{slug}/{nomor}`, dibuka otomatis saat PC
 * dinyalakan.
 *
 * =====================================================================
 * Laman ini TIDAK memakai kerangka mana pun
 * =====================================================================
 * Ia menghadap ruangan, bukan satu orang: tidak ada sidebar, tidak ada
 * tabbar, tidak ada menu. Yang tampil hanya identitas PERANGKAT dan satu
 * kotak masuk - dan itu disengaja, karena layar ini difoto orang.
 *
 * Sebelum siswa masuk, yang tayang hanya: nama sekolah, kode ruang, nomor
 * PC, jam sesi. Tidak ada daftar nama, tidak ada nama siswa lain, tidak ada
 * token.
 *
 * =====================================================================
 * Login, lalu kembali ke sini
 * =====================================================================
 * Akun yang menentukan SIAPA - bukan nomor di URL. Karena itu tombolnya
 * mengarah ke `/login` dengan `redirect` kembali ke alamat ini: siswa yang
 * belum masuk harus bisa memakai kios yang sama tanpa mengetik ulang
 * alamatnya di layar sentuh.
 *
 * =====================================================================
 * Garis besar, bukan hiasan
 * =====================================================================
 * Layar lab dilihat dari jarak dua meter oleh anak yang baru masuk ruangan.
 * 16px pada monitor itu terbaca seperti 10px pada ponsel, sehingga seluruh
 * ukuran di sini satu tingkat lebih besar daripada konsol biasa.
 */

type Props = {
    sekolah: string | null;
    slug: string;
    nomor: number;
    ruang: {
        kode: string | null;
        nama: string | null;
        kapasitas: number;
        kursi_ber_pc: number;
    };
    /** Nomor di URL melebihi kursi yang benar-benar ada. */
    nomor_sah: boolean;
    sesi: {
        label: string | null;
        ujian: string | null;
        jam: string | null;
        berjalan: boolean;
    };
    pesan: string | null;
};

export default function KiosLab({ sekolah, slug, nomor, ruang, nomor_sah, sesi, pesan }: Props) {
    const { auth, flash } = usePage<{
        auth?: { user?: { name?: string } | null };
        flash?: { galat?: string | null };
    }>().props;

    const [kirim, setKirim] = useState(false);

    const user = auth?.user ?? null;
    const galat = flash?.galat ?? null;

    const bisaMasuk = user !== null && sesi.berjalan && !nomor_sah && pesan === null;

    const masuk = () => {
        setKirim(true);
        router.post(
            `/lab/${slug}/${nomor}`,
            {},
            { onFinish: () => setKirim(false) },
        );
    };

    const keluar = () => {
        router.post('/logout');
    };

    return (
        <>
            <Head title={`Kios PC ${nomor} · ${ruang.kode ?? 'Lab'}`} />

            {/* Latar penuh, satu kolom. `min-h-screen` + `grid place-items-center`
                menaruh isinya di tengah layar apa pun - dari monitor 24 inci
                sampai layar kecil di meja lab. */}
            <div className="min-h-screen bg-paper">
                <div className="mx-auto flex min-h-screen w-full max-w-3xl flex-col sm:border-x sm:border-line">
                    {/* ------------------------------------------------ kepala */}
                    <header className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-line bg-surface px-5 py-3">
                        <span className="rounded-wf bg-accent-soft px-2.5 py-1 font-mono text-sm font-medium text-accent">
                            {ruang.kode ?? 'Ruang'} · PC {String(nomor).padStart(2, '0')}
                        </span>

                        <span className="min-w-0 truncate text-sm text-ink-2">
                            {sekolah ?? ruang.nama ?? ''}
                        </span>

                        <span className="ml-auto flex items-center gap-2">
                            <Penanda berjalan={sesi.berjalan} />
                            {sesi.jam && (
                                <span className="font-mono text-xs text-ink-3">{sesi.jam}</span>
                            )}
                        </span>
                    </header>

                    <main className="flex flex-1 flex-col items-center justify-center gap-6 px-5 py-10 text-center">
                        {/* ---------------------------------------- keadaan galat */}
                        {pesan !== null ? (
                            <>
                                <span
                                    className="grid size-14 place-items-center rounded-full bg-warn-bg text-2xl text-warn"
                                    aria-hidden="true"
                                >
                                    !
                                </span>
                                <div>
                                    <h1 className="font-display text-xl font-semibold text-ink">
                                        Kios ini belum bisa dipakai
                                    </h1>
                                    <p className="mt-2 max-w-md text-sm leading-relaxed text-ink-2">
                                        {pesan}
                                    </p>
                                </div>
                            </>
                        ) : nomor_sah ? (
                            <>
                                <span
                                    className="grid size-14 place-items-center rounded-full bg-bad-bg text-2xl text-bad"
                                    aria-hidden="true"
                                >
                                    ×
                                </span>
                                <div>
                                    <h1 className="font-display text-xl font-semibold text-ink">
                                        PC {nomor} tidak ada di ruang ini
                                    </h1>
                                    <p className="mt-2 max-w-md text-sm leading-relaxed text-ink-2">
                                        Alamat di monitor ini menyebut nomor kursi yang tidak
                                        terdaftar. Panggil teknisi atau pengawas ruang. Anak
                                        tidak bisa masuk lewat layar ini sampai alamatnya benar.
                                    </p>
                                </div>
                            </>
                        ) : user === null ? (
                            <>
                                <div>
                                    <h1 className="font-display text-2xl font-semibold text-ink">
                                        Masuk dengan akun Anda
                                    </h1>
                                    <p className="mt-2 max-w-sm text-sm leading-relaxed text-ink-2">
                                        Kursi ini <strong>PC {String(nomor).padStart(2, '0')}</strong>.
                                        Sistem akan memeriksa bahwa Anda memang duduk di sini.
                                    </p>
                                </div>

                                {/* Tautan ke rute GERBANG, bukan langsung ke
                                    `/login`. Rute itu berada di dalam `auth`,
                                    sehingga middleware-nya menulis
                                    `url.intended` saat mengalihkan tamu - dan
                                    `LoginController` mengembalikan anak ke
                                    sini sesudah masuk. Tautan langsung ke
                                    `/login` tidak melewati pengalihan itu,
                                    dan anak mendarat di berandanya. */}
                                <a
                                    href={`/lab/${slug}/${nomor}/gerbang`}
                                    className="inline-grid min-h-14 place-items-center rounded-wf border border-accent bg-accent px-10 text-base font-medium text-on-accent transition-colors hover:bg-accent/90"
                                >
                                    Masuk
                                </a>

                                <p className="text-xs text-ink-3">
                                    Bukan kursi Anda? Panggil pengawas.
                                </p>
                            </>
                        ) : (
                            <>
                                <div>
                                    <h1 className="font-display text-2xl font-semibold text-ink">
                                        Halo, {user.name ?? 'siswa'}
                                    </h1>
                                    <p className="mt-2 max-w-sm text-sm leading-relaxed text-ink-2">
                                        {sesi.ujian ?? 'Ujian'}
                                        {sesi.label ? ` · ${sesi.label}` : ''}
                                    </p>
                                </div>

                                {galat && (
                                    <p className="max-w-md rounded-wf border border-bad/30 bg-bad-bg px-4 py-3 text-sm leading-relaxed text-bad">
                                        {galat}
                                    </p>
                                )}

                                <button
                                    type="button"
                                    onClick={masuk}
                                    disabled={kirim || !bisaMasuk}
                                    className="inline-grid min-h-14 place-items-center rounded-wf border border-accent bg-accent px-10 text-base font-medium text-on-accent transition-colors hover:bg-accent/90 disabled:cursor-not-allowed disabled:border-ink-3/40 disabled:bg-ink-3/40"
                                >
                                    {kirim ? 'Memeriksa kursi…' : 'Mulai ujian'}
                                </button>

                                {!sesi.berjalan && (
                                    <p className="max-w-sm text-xs leading-relaxed text-warn">
                                        Sesi ini tidak sedang berjalan. Tombolnya mati sampai
                                        jamnya dibuka, bukan karena kursinya salah.
                                    </p>
                                )}

                                <button
                                    type="button"
                                    onClick={keluar}
                                    className="text-xs text-ink-3 underline underline-offset-2 hover:text-ink"
                                >
                                    Bukan saya, keluar
                                </button>
                            </>
                        )}
                    </main>

                    {/* ------------------------------------------------- kaki */}
                    <footer className="border-t border-line bg-surface px-5 py-3">
                        <p className="text-[11px] leading-relaxed text-ink-3">
                            Layar ini hanya menyebut kode ruang, nomor PC, dan jam sesi. Nama
                            siswa dan nomor kursi penghuni lain tidak pernah tayang di sini.
                            {ruang.kursi_ber_pc > 0 && (
                                <>
                                    {' '}
                                    {ruang.kursi_ber_pc} dari {ruang.kapasitas} kursi di ruang
                                    ini ber-PC.
                                </>
                            )}
                        </p>
                    </footer>
                </div>
            </div>
        </>
    );
}

/** Penanda keadaan sesi - hijau berdenyut bila berjalan. */
function Penanda({ berjalan }: { berjalan: boolean }) {
    return (
        <span
            className={
                'inline-flex items-center gap-1.5 font-mono text-[10px] tracking-wide uppercase ' +
                (berjalan ? 'text-ok' : 'text-ink-3')
            }
        >
            <span
                className={
                    'inline-block size-1.5 rounded-full ' +
                    (berjalan ? 'animate-pulse bg-ok' : 'bg-ink-3/50')
                }
                aria-hidden="true"
            />
            {berjalan ? 'berjalan' : 'belum'}
        </span>
    );
}
