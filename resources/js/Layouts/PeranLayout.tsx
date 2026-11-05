import { Head } from '@inertiajs/react';
import { ReactNode } from 'react';

/**
 * Kerangka peran non-Admin.
 *
 * Sengaja belum menyerupai konsol apa pun. Guru mendapat konsol desktop
 * bertebaran (Fase 4); Pengawas dan Siswa mendapat kerangka telepon
 * (Fase 6-7) karena mereka mengerjakan dan mengawasi ujian dari HP.
 * Menyeragamkan keempatnya sekarang berarti membangun tiga tata letak yang
 * pasti akan dibongkar pada fasenya masing-masing.
 */
type Props = {
    judul: string;
    peran: string;
    fase: string;
    children?: ReactNode;
};

export default function PeranLayout({ judul, peran, fase, children }: Props) {
    return (
        <>
            <Head title={judul} />

            <div className="flex min-h-screen flex-col items-center justify-center bg-paper px-4 py-10">
                <div className="w-full max-w-md rounded-wf border border-line bg-surface p-6 shadow-wf">
                    <p className="font-display text-lg font-semibold text-ink">
                        {judul}
                    </p>
                    <p className="mt-2 text-sm text-ink-2">
                        Anda masuk sebagai <strong>{peran}</strong>.
                    </p>
                    <p className="mt-4 border-t border-line pt-4 font-mono text-xs text-ink-3">
                        Laman {peran.toLowerCase()} dibangun pada {fase}. Yang ada
                        sekarang: autentikasi, isolasi sekolah, dan log aktivitas.
                    </p>
                </div>

                {children}
            </div>
        </>
    );
}
