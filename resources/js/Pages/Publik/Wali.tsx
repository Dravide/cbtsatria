import { Head, Link } from '@inertiajs/react';

/**
 * Pantau wali - daftar kelas yang dibuka - `/wali/{slug}`, tanpa login.
 *
 * =====================================================================
 * Laman ini TIDAK memakai kerangka mana pun
 * =====================================================================
 * Alasan yang sama dengan `Publik/Papan.tsx`: yang membukanya tidak punya
 * akun, dan menawarinya sidebar atau tombol keluar adalah janji palsu.
 * Kerangkanya digambar sendiri - satu kolom sempit, kepala sendiri.
 *
 * =====================================================================
 * Kelas yang belum dibuka TIDAK muncul
 * =====================================================================
 * Bukan muncul dengan gembok. Alamat yang tidak pernah lahir lebih kuat
 * daripada alamat yang menolak: yang kedua membuktikan alamatnya ada, dan
 * yang pertama tidak membocorkan apa pun.
 *
 * =====================================================================
 * "Diperbarui" ada di setiap laman publik, dan itu bukan hiasan
 * =====================================================================
 * Orang tua yang membuka laman ini pada pukul 09:41 perlu tahu apakah yang
 * ia baca masih berlaku. Tanpa cap waktu, laman yang tidak menyegarkan diri
 * terbaca sebagai keadaan yang sedang berlangsung - dan angka yang mandek
 * selama sepuluh menit akan ditafsirkan sebagai anaknya yang mandek.
 */
type Kelas = {
    id: number;
    nama: string;
    slug: string;
    peserta: number;
    berjalan: boolean;
};

type Props = {
    sekolah: string;
    slug: string;
    kelas: Kelas[];
};

export default function Wali({ sekolah, slug, kelas }: Props) {
    return (
        <>
            <Head title={`Pantau kelas · ${sekolah}`} />

            <div className="min-h-screen bg-paper">
                <div className="mx-auto flex min-h-screen w-full max-w-[26rem] flex-col sm:border-x sm:border-line">
                    <header className="border-b border-line bg-surface px-4 py-3">
                        <h1 className="text-sm font-semibold text-ink">{sekolah}</h1>
                        <p className="mt-0.5 text-[11px] text-ink-3">
                            Pantau kelas · untuk wali murid
                        </p>
                    </header>

                    <main className="flex-1 px-3 py-3">
                        <div className="mb-3 rounded-wf border border-info/25 bg-info-bg px-3 py-2.5 text-[11px] leading-relaxed text-info">
                            Pilih kelas anak Anda. Bila kelasnya tidak ada di sini, minta wali
                            kelas membukanya.
                        </div>

                        {kelas.length === 0 ? (
                            <div className="rounded-wf border border-line bg-surface px-4 py-8 text-center">
                                <p className="text-xs text-ink-2">
                                    Belum ada kelas yang dibuka di sekolah ini.
                                </p>
                                <p className="mt-1 text-[11px] text-ink-3">
                                    Hubungi wali kelas atau bagian akademik.
                                </p>
                            </div>
                        ) : (
                            <ul className="overflow-hidden rounded-wf border border-line bg-surface">
                                {kelas.map((k) => (
                                    <li key={k.id} className="border-b border-line last:border-b-0">
                                        <Link
                                            href={`/wali/${slug}/kelas/${k.id}`}
                                            className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-surface-2"
                                        >
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-xs font-semibold text-ink">
                                                    {k.nama}
                                                </span>
                                                <span className="text-[11px] text-ink-3">
                                                    {k.peserta > 0
                                                        ? `${k.peserta} peserta ujian`
                                                        : 'belum ada jadwal ujian'}
                                                </span>
                                            </span>
                                            <span
                                                className={
                                                    'shrink-0 rounded-full border px-2 py-0.5 font-mono text-[10px] ' +
                                                    (k.berjalan
                                                        ? 'border-info/30 bg-info-bg text-info'
                                                        : 'border-line bg-surface-2 text-ink-3')
                                                }
                                            >
                                                {k.berjalan ? 'berjalan' : 'belum'}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <p className="mt-3 text-center text-[11px] text-ink-3">
                            Diperbarui {new Date().toLocaleTimeString('id-ID', {
                                hour: '2-digit',
                                minute: '2-digit',
                            })}
                        </p>
                    </main>

                    <footer className="border-t border-line bg-surface px-4 py-2.5">
                        <p className="text-center text-[10px] leading-relaxed text-ink-3">
                            Laman ini tidak menampilkan nilai. Nilai hanya bisa dibaca setelah
                            sekolah menerbitkannya.
                        </p>
                    </footer>
                </div>
            </div>
        </>
    );
}
