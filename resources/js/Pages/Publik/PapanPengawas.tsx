import { Head, Link } from '@inertiajs/react';

/**
 * Papan publik - satu pengawas, ruang-ruangnya. Tanpa login.
 *
 * =====================================================================
 * Mengapa halaman per orang ada
 * =====================================================================
 * Pertanyaan di lorong menyebut NAMA: "Bu Ani di mana?". Daftar per ruang
 * menjawabnya, tetapi menuntut penanya memindai seluruh baris - dan pada
 * sekolah dengan 24 ruang itu 24 baris yang dibaca sambil berdiri.
 *
 * Halaman ini menjawabnya dalam satu layar. Tautannya datang dari nama
 * pengawas di papan utama, jadi tidak ada yang perlu mengetik id.
 *
 * =====================================================================
 * Pengawas yang tidak bertugas pada sesi aktif = 404
 * =====================================================================
 * Dan itu jawaban yang benar, bukan kegagalan: orangnya memang tidak
 * sedang mengawas. Halaman yang menjawab "tidak ada ruang" untuk SETIAP id
 * akan membuat penanya mengira papannya rusak, sementara 404 menyatakan
 * bahwa orang itu tidak ada di jadwal yang sedang berjalan.
 */

type Ruang = {
    kode: string | null;
    nama: string | null;
    lokasi: string | null;
    sesi: string;
    ujian: string | null;
    jam: string;
};

type Props = {
    sekolah: string;
    slug: string;
    nama: string | null;
    ruang: Ruang[];
    diperbarui: string;
};

export default function PapanPengawas({ sekolah, slug, nama, ruang, diperbarui }: Props) {
    return (
        <>
            <Head title={`${nama ?? 'Pengawas'} · ${sekolah}`} />

            <div className="min-h-screen bg-paper">
                <div className="mx-auto flex min-h-screen w-full max-w-[34rem] flex-col sm:border-x sm:border-line">
                    <header className="flex items-baseline gap-2 border-b border-line bg-surface px-3 py-2.5">
                        <Link href={`/papan/${slug}`} className="shrink-0 text-[11px] text-ink-3 hover:text-ink">
                            &#8592; Semua ruang
                        </Link>
                        <span className="ml-auto shrink-0 font-mono text-[11px] text-ink-3">
                            {diperbarui}
                        </span>
                    </header>

                    <main className="flex-1 space-y-3 px-3 py-4">
                        <section className="rounded-wf border border-line bg-surface px-4 py-4">
                            <p className="font-display text-lg font-semibold text-ink">
                                {nama ?? 'Pengawas'}
                            </p>
                            <p className="mt-1 text-xs text-ink-2">
                                {sekolah} · {ruang.length} ruang pada sesi yang berjalan
                            </p>
                        </section>

                        <section className="overflow-hidden rounded-wf border border-line bg-surface">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-line/60 text-left">
                                        <th className="px-3 py-1.5 text-[11px] font-medium text-ink-3">
                                            Ruang
                                        </th>
                                        <th className="hidden px-3 py-1.5 text-[11px] font-medium text-ink-3 sm:table-cell">
                                            Lokasi
                                        </th>
                                        <th className="px-3 py-1.5 text-right text-[11px] font-medium text-ink-3">
                                            Sesi
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {ruang.map((r, i) => (
                                        <tr key={`${r.kode}-${i}`} className="border-b border-line/40 last:border-0">
                                            <td className="px-3 py-2 align-top">
                                                <span className="font-mono text-xs font-medium text-ink">
                                                    {r.kode ?? 'R-?'}
                                                </span>
                                                {r.nama && (
                                                    <span className="mt-0.5 block text-[11px] text-ink-3">
                                                        {r.nama}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="hidden px-3 py-2 align-top text-[11px] text-ink-3 sm:table-cell">
                                                {r.lokasi ?? '—'}
                                            </td>
                                            <td className="px-3 py-2 align-top text-right">
                                                <span className="font-mono text-[11px] text-ink-2">
                                                    {r.sesi}
                                                </span>
                                                <span className="mt-0.5 block font-mono text-[11px] text-ink-3">
                                                    {r.jam}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </section>

                        <p className="px-1 pt-1 text-[11px] leading-relaxed text-ink-3">
                            Pengawas yang bertugas di lebih dari satu ruang biasanya bergiliran.
                            Urutannya mengikuti urutan ruang, bukan jadwal keliling - jadwal
                            keliling tidak pernah ditetapkan sistem.
                        </p>
                    </main>
                </div>
            </div>
        </>
    );
}
