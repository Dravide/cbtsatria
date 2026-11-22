import { Head, Link, usePage } from '@inertiajs/react';

type SesiRingkas = {
    berjalan: boolean;
    event: string | null;
    mapel: string | null;
    ujian: string | null;
    label: string;
    tanggal: string | null;
    jam: string;
    jumlah_ruang: number;
};

type Pengumuman = {
    id: number;
    judul: string;
    isi: string;
    nada: string;
    dibuat: string | null;
};

type Props = {
    sekolah: string;
    slug: string;
    diperbarui: string;
    keadaan: 'berjalan' | 'siaga';
    sesi_ringkas: SesiRingkas | null;
    pengumuman: Pengumuman | null;
    jumlah_kelas_wali: number;
};

type ItemMenu = {
    kode: string;
    judul: string;
    deskripsi: string;
    href: string;
    lencana?: string;
    aksen?: boolean;
};

export default function Portal({
    sekolah,
    slug,
    diperbarui,
    keadaan,
    sesi_ringkas,
    pengumuman,
    jumlah_kelas_wali,
}: Props) {
    const { identitasAplikasi } = usePage().props as unknown as {
        identitasAplikasi?: { nama: string; logo: string | null };
    };

    const menuSiswaWali: ItemMenu[] = [
        {
            kode: 'JDW',
            judul: 'Jadwal & Pengumuman',
            deskripsi: 'Jadwal ujian lintas hari, mapel, dan pengumuman resmi panitia',
            href: `/jadwal/${slug}`,
            aksen: true,
        },
        {
            kode: 'KRS',
            judul: 'Cek Ruang & Nomor Kursi',
            deskripsi: 'Cari lokasi ruang dan nomor meja memakai Kelas + NIS',
            href: `/ruang/${slug}`,
            aksen: true,
        },
        ...(jumlah_kelas_wali > 0
            ? [
                  {
                      kode: 'WLI',
                      judul: 'Pantau Wali per Kelas',
                      deskripsi: 'Pantau kehadiran & waktu pengerjaan anak (pakai kode kelas)',
                      href: `/wali/${slug}`,
                      lencana: `${jumlah_kelas_wali} kelas`,
                      aksen: true,
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title={`Portal informasi ujian · ${sekolah}`} />

            <div className="laman-publik min-h-screen bg-paper">
                <div className="mx-auto flex min-h-screen w-full max-w-[30rem] flex-col sm:border-x sm:border-line">
                    <header className="flex flex-col gap-2 border-b border-line bg-surface px-4 py-3">
                        {identitasAplikasi?.logo && (
                            <div className="flex justify-center border-b border-line/60 pb-2">
                                <img
                                    src={identitasAplikasi.logo}
                                    alt={identitasAplikasi.nama || sekolah}
                                    className="h-5 w-auto max-w-[144px] object-contain"
                                />
                            </div>
                        )}
                        <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0 flex-1">
                                <h1 className="truncate text-sm font-semibold text-ink">{sekolah}</h1>
                                <p className="mt-0.5 text-[11px] text-ink-3">
                                    Portal informasi ujian · tanpa login · {diperbarui}
                                </p>
                            </div>
                            <span
                                className={
                                    'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-mono text-[10px] ' +
                                    (keadaan === 'berjalan'
                                        ? 'border-ok/30 bg-ok-bg text-ok'
                                        : 'border-line bg-surface-2 text-ink-3')
                                }
                            >
                                {keadaan === 'berjalan' && (
                                    <span className="h-1.5 w-1.5 rounded-full bg-ok" />
                                )}
                                {keadaan === 'berjalan' ? 'Berjalan' : 'Siaga'}
                            </span>
                        </div>
                    </header>

                    <main className="flex-1 space-y-3.5 px-3 py-4">
                        {pengumuman && (
                            <Link
                                href={`/jadwal/${slug}`}
                                className={
                                    'block rounded-wf border px-3.5 py-2.5 text-xs leading-relaxed transition-opacity hover:opacity-90 ' +
                                    (pengumuman.nada === 'penting'
                                        ? 'border-warn/40 bg-warn-bg text-warn'
                                        : 'border-info/30 bg-info-bg text-info')
                                }
                            >
                                <div className="flex items-baseline justify-between gap-2">
                                    <strong className="font-semibold">
                                        Pengumuman · {pengumuman.judul}
                                    </strong>
                                    {pengumuman.dibuat && (
                                        <span className="shrink-0 font-mono text-[10px] opacity-80">
                                            {pengumuman.dibuat}
                                        </span>
                                    )}
                                </div>
                                <p className="mt-1 line-clamp-2 text-ink">{pengumuman.isi}</p>
                            </Link>
                        )}

                        <section className="flex items-center justify-between gap-2 rounded-wf border border-line bg-surface px-3.5 py-2.5">
                            {sesi_ringkas ? (
                                <>
                                    <div className="min-w-0">
                                        <p className="truncate text-xs font-semibold text-ink">
                                            {sesi_ringkas.event || sesi_ringkas.ujian || 'Ujian'}
                                        </p>
                                        <p className="mt-0.5 truncate font-mono text-[11px] text-ink-3">
                                            {sesi_ringkas.berjalan
                                                ? `${sesi_ringkas.label} · ${sesi_ringkas.mapel || sesi_ringkas.ujian || 'Ujian'} · ${sesi_ringkas.jam}`
                                                : `Berikutnya: ${sesi_ringkas.tanggal ?? ''} · ${sesi_ringkas.jam}`}
                                        </p>
                                    </div>
                                    <span
                                        className={
                                            'shrink-0 rounded-full border px-2 py-0.5 font-mono text-[10px] ' +
                                            (sesi_ringkas.berjalan
                                                ? 'border-info/30 bg-info-bg text-info'
                                                : 'border-line bg-surface-2 text-ink-3')
                                        }
                                    >
                                        {sesi_ringkas.berjalan
                                            ? `${sesi_ringkas.jumlah_ruang} ruang`
                                            : 'Siap'}
                                    </span>
                                </>
                            ) : (
                                <div className="min-w-0">
                                    <p className="text-xs font-semibold text-ink">
                                        Belum ada sesi ujian yang berjalan
                                    </p>
                                    <p className="mt-0.5 text-[11px] text-ink-3">
                                        Pilih menu di bawah untuk melihat jadwal atau cek ruang.
                                    </p>
                                </div>
                            )}
                        </section>

                        <section className="space-y-1.5">
                            <h2 className="px-1 font-mono text-[10px] uppercase tracking-wider text-ink-3">
                                Untuk Siswa &amp; Orang Tua
                            </h2>
                            <ul className="overflow-hidden rounded-wf border border-line bg-surface">
                                {menuSiswaWali.map((item) => (
                                    <li
                                        key={item.href}
                                        className="border-b border-line last:border-b-0"
                                    >
                                        <Link
                                            href={item.href}
                                            className="flex items-center gap-3 px-3.5 py-3 transition-colors hover:bg-surface-2"
                                        >
                                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-wf bg-accent/10 font-mono text-[11px] font-semibold text-accent">
                                                {item.kode}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="flex items-center gap-1.5 text-xs font-semibold text-ink">
                                                    <span className="truncate">{item.judul}</span>
                                                    {item.lencana && (
                                                        <span className="shrink-0 rounded-full border border-line bg-surface-2 px-1.5 py-0.5 font-mono text-[10px] font-normal text-ink-3">
                                                            {item.lencana}
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="mt-0.5 block text-[11px] leading-snug text-ink-3">
                                                    {item.deskripsi}
                                                </span>
                                            </span>
                                            <span className="shrink-0 font-mono text-sm text-ink-3">
                                                &rsaquo;
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </main>

                    <footer className="space-y-1.5 border-t border-line bg-surface px-4 py-3 text-center">
                        <p className="text-[10px] leading-relaxed text-ink-3">
                            Portal ini tidak menampilkan nilai, token, atau data personal siswa.
                        </p>
                        <Link
                            href="/login"
                            className="inline-block text-[11px] font-medium text-accent hover:underline"
                        >
                            Punya akun? Masuk ke CBT &rarr;
                        </Link>
                    </footer>
                </div>
            </div>
        </>
    );
}
