import { Head, Link } from '@inertiajs/react';

/**
 * Papan publik pengawas - `/papan/{slug}`, tanpa login.
 *
 * =====================================================================
 * Laman ini TIDAK memakai kerangka mana pun
 * =====================================================================
 * Ketiga kerangka yang ada (`KonsolLayout`, `KerangkaPonsel`,
 * `KerangkaSiswa`) mengandaikan pengguna yang sudah masuk: sidebar yang
 * menyala menurut peran, tabbar yang menunjuk laman milik sendiri, dan
 * tautan keluar. Tidak satu pun berlaku di sini - yang membuka laman ini
 * mungkin tidak punya akun, dan menawarinya "keluar" adalah janji palsu.
 *
 * Karena itu ia menggambar kerangkanya sendiri, pola yang sama dengan
 * `Pengawas/Pintu.tsx`: satu kolom sempit, kepala sendiri.
 *
 * =====================================================================
 * Urutannya: yang perlu dikejar DULU
 * =====================================================================
 * Ruang yang belum punya pengawas diletakkan di ATAS daftar, bukan
 * disembunyikan di antara baris. Pada pukul 07:25 itulah satu-satunya
 * baris yang perlu dibaca panitia - sisanya sudah beres sendiri. Ruang
 * tanpa pengawas tetap TAMPIL di daftarnya juga, dengan tanda "belum
 * ditetapkan", karena yang hilang dari daftar tidak bisa dicari.
 *
 * =====================================================================
 * Tidak ada satu tautan pun ke konsol
 * =====================================================================
 * Halaman ini tayang untuk seluruh internet. Tautan ke `/login` akan
 * mengundang siapa pun yang menemukannya untuk mencoba masuk, dan tautan
 * ke laman Admin adalah alamat yang tidak perlu dibagikan. Yang ada hanya
 * nama sekolah dan waktu pembaruan.
 */

type Ruang = {
    id: number;
    kode: string | null;
    nama: string | null;
    lokasi: string | null;
    pengawas: { id: number; nama: string }[];
};

type Sesi = {
    id: number;
    label: string;
    ujian: string | null;
    mapel: string | null;
    jam: string;
    status: string;
    ruang: Ruang[];
};

type Props = {
    sekolah: string;
    slug: string;
    sesi: Sesi[];
    tanpa_pengawas: string[];
    diperbarui: string;
};

export default function Papan({ sekolah, slug, sesi, tanpa_pengawas, diperbarui }: Props) {
    return (
        <>
            <Head title={`Pengawas per ruang · ${sekolah}`} />

            <div className="min-h-screen bg-paper">
                <div className="mx-auto flex min-h-screen w-full max-w-[34rem] flex-col sm:border-x sm:border-line">
                    <header className="flex items-baseline gap-2 border-b border-line bg-surface px-3 py-2.5">
                        <span className="truncate text-xs font-medium text-ink">{sekolah}</span>
                        <span className="ml-auto shrink-0 font-mono text-[11px] text-ink-3">
                            {diperbarui}
                        </span>
                    </header>

                    <main className="flex-1 space-y-3 px-3 py-4">
                        {sesi.length === 0 && (
                            <section className="rounded-wf border border-line bg-surface px-4 py-8 text-center">
                                <p className="text-sm text-ink">Belum ada sesi ujian yang berjalan.</p>
                                <p className="mt-1.5 text-xs leading-relaxed text-ink-3">
                                    Papan ini menampilkan sesi yang sedang berjalan, atau sesi
                                    terdekat yang belum berakhir.
                                </p>
                            </section>
                        )}

                        {tanpa_pengawas.length > 0 && (
                            <div className="rounded-wf border border-warn/40 bg-warn-bg px-3 py-2.5 text-xs leading-relaxed text-warn">
                                <strong>{tanpa_pengawas.length} ruang belum punya pengawas:</strong>{' '}
                                <span className="font-mono">{tanpa_pengawas.join(', ')}</span>.
                                Pengawasnya ditetapkan Admin di konsol.
                            </div>
                        )}

                        {sesi.map((s) => (
                            <section
                                key={s.id}
                                className="overflow-hidden rounded-wf border border-line bg-surface"
                            >
                                <header className="flex flex-wrap items-baseline gap-2 border-b border-line bg-surface-2/60 px-3 py-2">
                                    <span className="font-display text-sm font-semibold text-ink">
                                        {[s.mapel, s.ujian].filter(Boolean).join(' · ') || 'Ujian'}
                                    </span>
                                    <span className="ml-auto shrink-0 font-mono text-[11px] text-ink-2">
                                        {s.label} · {s.jam}
                                    </span>
                                </header>

                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-line/60 text-left">
                                            <th className="px-3 py-1.5 text-[11px] font-medium text-ink-3">
                                                Ruang
                                            </th>
                                            <th className="px-3 py-1.5 text-[11px] font-medium text-ink-3">
                                                Pengawas
                                            </th>
                                            <th className="hidden px-3 py-1.5 text-[11px] font-medium text-ink-3 sm:table-cell">
                                                Lokasi
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {s.ruang.map((r) => (
                                            <tr
                                                key={r.id}
                                                className="border-b border-line/40 last:border-0"
                                            >
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
                                                <td className="px-3 py-2 align-top">
                                                    {r.pengawas.length === 0 ? (
                                                        <span className="text-xs text-warn">
                                                            belum ditetapkan
                                                        </span>
                                                    ) : (
                                                        <span className="flex flex-wrap gap-x-2 gap-y-0.5">
                                                            {r.pengawas.map((p) => (
                                                                <Link
                                                                    key={p.id}
                                                                    href={`/papan/${slug}/pengawas/${p.id}`}
                                                                    className="text-xs text-ink underline decoration-line underline-offset-2 hover:decoration-ink"
                                                                >
                                                                    {p.nama}
                                                                </Link>
                                                            ))}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="hidden px-3 py-2 align-top text-[11px] text-ink-3 sm:table-cell">
                                                    {r.lokasi ?? '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </section>
                        ))}

                        <p className="px-1 pt-1 text-[11px] leading-relaxed text-ink-3">
                            Halaman ini hanya memuat nama ruang, nama pengawas, dan jam. Nama
                            siswa, nomor kursi, dan token tidak pernah tayang di sini.
                        </p>
                    </main>
                </div>
            </div>
        </>
    );
}
