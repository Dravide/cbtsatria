import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    status: number;
    errorCode?: string | null;
    portal?: string | null;
};

const PESAN: Record<number, { judul: string; keterangan: string; lencana: string }> = {
    404: {
        lencana: '404 · Tidak Ditemukan',
        judul: 'Halaman tidak ditemukan',
        keterangan:
            'Alamat yang Anda buka mungkin keliru, tautan ruang sudah kedaluwarsa/dicabut, atau data sudah tidak tersedia.',
    },
    403: {
        lencana: '403 · Akses Ditolak',
        judul: 'Anda tidak memiliki akses ke halaman ini',
        keterangan:
            'Halaman ini hanya dapat dibuka oleh peran yang berwenang. Pastikan Anda masuk dengan akun yang sesuai.',
    },
    419: {
        lencana: '419 · Sesi Kedaluwarsa',
        judul: 'Sesi halaman telah berakhir',
        keterangan:
            'Halaman terlalu lama tidak aktif. Muat ulang halaman lalu kirim ulang permintaan Anda.',
    },
    503: {
        lencana: '503 · Pemeliharaan',
        judul: 'Layanan sedang dalam pemeliharaan',
        keterangan:
            'Sistem sedang diperbarui sebentar. Silakan muat ulang beberapa saat lagi.',
    },
    500: {
        lencana: '500 · Gangguan Sistem',
        judul: 'Terjadi kesalahan pada server',
        keterangan:
            'Permintaan Anda belum dapat diproses saat ini. Silakan coba lagi atau kirimkan kode referensi di bawah ini ke pengelola sistem.',
    },
};

export default function Galat({ status, errorCode = null, portal = null }: Props) {
    const [tersalin, setTersalin] = useState(false);
    const adalahSiswa = portal === 'SSW';

    const info =
        adalahSiswa && status === 500
            ? {
                  lencana: '500 · Kendala Teknis',
                  judul: 'Halaman mengalami kendala sementara',
                  keterangan:
                      'Jawaban yang sudah tersimpan tetap aman. Muat ulang halaman, atau tunjukkan kode ini kepada pengawas ruang bila kendala berlanjut.',
              }
            : (PESAN[status] ?? {
                  lencana: `${status} · Galat`,
                  judul: 'Halaman tidak dapat dimuat',
                  keterangan: 'Terjadi kendala saat membuka halaman ini. Silakan kembali ke beranda.',
              });

    const salinKode = async () => {
        if (!errorCode) {
            return;
        }
        try {
            await navigator.clipboard.writeText(errorCode);
            setTersalin(true);
            window.setTimeout(() => setTersalin(false), 2000);
        } catch {
            // Abaikan bila clipboard tidak tersedia
        }
    };

    return (
        <>
            <Head title={`${status} — ${info.judul}`} />
            <div className="flex min-h-screen items-center justify-center bg-paper px-4 py-10 text-ink">
                <div className="w-full max-w-md rounded-wf border border-line bg-surface p-6 shadow-sm">
                    <span className="inline-block rounded-wf border border-line bg-surface-2 px-2.5 py-1 font-mono text-[11px] font-medium text-ink-2">
                        {info.lencana}
                    </span>

                    <h1 className="mt-4 font-display text-xl font-bold tracking-tight text-ink">
                        {info.judul}
                    </h1>

                    <p className="mt-2 text-sm leading-relaxed text-ink-2">{info.keterangan}</p>

                    {errorCode && (
                        <div className="mt-4 flex items-center justify-between gap-3 rounded-wf border border-line bg-surface-2/60 px-3.5 py-2.5">
                            <div>
                                <p className="font-mono text-[10px] tracking-wider text-ink-3 uppercase">
                                    {adalahSiswa ? 'Kode Untuk Pengawas' : 'Kode Referensi Galat'}
                                </p>
                                <p className="mt-0.5 font-mono text-base font-bold tracking-wide text-bad">
                                    {errorCode}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={salinKode}
                                className="rounded-wf border border-line bg-surface px-2.5 py-1.5 text-[11px] font-medium text-ink-2 hover:bg-surface-2"
                            >
                                {tersalin ? 'Tersalin' : 'Salin Kode'}
                            </button>
                        </div>
                    )}

                    <div className="mt-6 flex flex-wrap items-center gap-2">
                        <Link
                            href="/"
                            className="inline-grid min-h-10 place-items-center rounded-wf border border-accent bg-accent px-4 text-xs font-medium text-on-accent hover:opacity-95"
                        >
                            Ke Beranda
                        </Link>
                        <button
                            type="button"
                            onClick={() => window.history.back()}
                            className="inline-grid min-h-10 place-items-center rounded-wf border border-line bg-surface px-4 text-xs font-medium text-ink-2 hover:bg-surface-2"
                        >
                            Kembali
                        </button>
                    </div>
                </div>
            </div>
        </>
    );
}
