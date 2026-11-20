import { Head, Link } from '@inertiajs/react';

type Props = {
    status: number;
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
            'Permintaan Anda belum dapat diproses saat ini. Silakan coba lagi atau hubungi panitia/admin.',
    },
};

export default function Galat({ status }: Props) {
    const info = PESAN[status] ?? {
        lencana: `${status} · Galat`,
        judul: 'Halaman tidak dapat dimuat',
        keterangan: 'Terjadi kendala saat membuka halaman ini. Silakan kembali ke beranda.',
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
