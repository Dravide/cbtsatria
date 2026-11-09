import { Head, Link, usePage } from '@inertiajs/react';
import Ikon, { NamaIkon } from '@/Components/Ikon';
import { Chip } from '@/Components/ui';
import AdminLayout from '@/Layouts/AdminLayout';

/**
 * Laman 03 - Dashboard sekolah. Wireframe plate 03.
 *
 * Tesis produk terlihat di sini: tanpa event aktif, konsol event belum
 * muncul, dan aksi utama adalah membuat event. Angkanya ditampilkan apa
 * adanya - sebagian nol karena master datanya memang belum ada.
 *
 * ---------------------------------------------------------------------
 * Setiap angka adalah pintu masuk
 * ---------------------------------------------------------------------
 * Kartu "Guru: 12" yang tidak bisa diklik memaksa Admin menebak di menu mana
 * dua belas orang itu diurus. Keempat kartu di sini menuju lamannya sendiri,
 * karena pertanyaan yang muncul setelah membaca angka selalu "yang mana?".
 */
type Props = {
    sekolah: { nama: string; npsn: string };
    ringkasan: {
        guru: number;
        siswa: number;
        pengawas: number;
        ruang: number;
    };
};

const KPI: { kunci: keyof Props['ringkasan']; label: string; ikon: NamaIkon; href: string }[] = [
    { kunci: 'guru', label: 'Guru', ikon: 'pengguna', href: '/admin/pengguna?tab=teacher' },
    { kunci: 'siswa', label: 'Siswa aktif', ikon: 'peserta', href: '/admin/pengguna?tab=student' },
    {
        kunci: 'pengawas',
        label: 'Pengawas',
        ikon: 'perisai',
        href: '/admin/pengguna?tab=supervisor',
    },
    { kunci: 'ruang', label: 'Ruang aktif', ikon: 'ruang', href: '/admin/ruang' },
];

export default function Dashboard({ sekolah, ringkasan }: Props) {
    // Kedua prop ini sudah dikirim server ke SETIAP laman konsol; membaca
    // keduanya di sini lebih murah daripada menambah satu query untuk
    // pertanyaan yang jawabannya sudah ada.
    const { eventAktif, tahunAktif } = usePage().props as unknown as {
        eventAktif: { id: number; kode: string; nama: string; status_label: string } | null;
        tahunAktif: string | null;
    };

    return (
        <AdminLayout judul="Ringkasan sekolah" breadcrumb={sekolah.nama}>
            <Head title="Dashboard" />

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {KPI.map((k) => (
                    <Link
                        key={k.kunci}
                        href={k.href}
                        className="group rounded-wf border border-line bg-surface px-4 py-3 transition-colors duration-150 hover:border-accent/40 hover:bg-accent-soft/30"
                    >
                        <div className="flex items-center gap-2 text-ink-3">
                            <Ikon nama={k.ikon} ukuran={16} />
                            <p className="text-xs">{k.label}</p>
                        </div>
                        <p className="mt-1.5 font-mono text-2xl font-medium text-ink">
                            {ringkasan[k.kunci].toLocaleString('id-ID')}
                        </p>
                    </Link>
                ))}
            </div>

            <div className="mt-3 grid gap-3 lg:grid-cols-2">
                <section className="rounded-wf border border-line bg-surface p-4">
                    <div className="flex items-center gap-2">
                        <Ikon nama="bendera" ukuran={16} className="text-ink-3" />
                        <h2 className="font-display text-sm font-semibold text-ink">
                            Event berjalan
                        </h2>
                    </div>

                    {eventAktif ? (
                        <div className="mt-3">
                            <Link
                                href={`/admin/events/${eventAktif.id}`}
                                className="font-medium text-accent hover:underline"
                            >
                                {eventAktif.nama}
                            </Link>
                            <p className="mt-1 flex items-center gap-2 font-mono text-xs text-ink-3">
                                {eventAktif.kode}
                                <Chip nada="info">{eventAktif.status_label}</Chip>
                            </p>
                        </div>
                    ) : (
                        <div className="mt-3">
                            <p className="text-sm text-ink-2">
                                Belum ada event yang dipilih. Buka daftar event untuk memilih
                                atau membuat yang baru.
                            </p>
                            <Link
                                href="/admin/events"
                                className="mt-2 inline-block text-xs font-medium text-accent hover:underline"
                            >
                                Buka daftar event
                            </Link>
                        </div>
                    )}
                </section>

                <section className="rounded-wf border border-line bg-surface p-4">
                    <div className="flex items-center gap-2">
                        <Ikon nama="kalender" ukuran={16} className="text-ink-3" />
                        <h2 className="font-display text-sm font-semibold text-ink">
                            Tahun ajaran
                        </h2>
                    </div>

                    <p className="mt-3 font-mono text-lg text-ink">
                        {tahunAktif ?? 'Belum diatur'}
                    </p>
                    <p className="mt-1 text-xs text-ink-3">
                        Berganti tahun ajaran berarti berganti daftar kelas dan peserta, bukan
                        menyaring yang sedang tampil.
                    </p>
                    <Link
                        href="/admin/tahun-ajaran"
                        className="mt-2 inline-block text-xs font-medium text-accent hover:underline"
                    >
                        Kelola tahun ajaran
                    </Link>
                </section>
            </div>

            <section className="mt-3 rounded-wf border border-line bg-surface p-4">
                <div className="flex items-center gap-2">
                    <Ikon nama="daftar" ukuran={16} className="text-ink-3" />
                    <h2 className="font-display text-sm font-semibold text-ink">
                        Yang paling sering dibuka
                    </h2>
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                    {[
                        { label: 'Profil sekolah', href: '/admin/sekolah', ikon: 'sekolah' as NamaIkon },
                        { label: 'Kelas', href: '/admin/kelas', ikon: 'kelas' as NamaIkon },
                        { label: 'Mata pelajaran', href: '/admin/mapel', ikon: 'buku' as NamaIkon },
                        { label: 'Pengguna', href: '/admin/pengguna', ikon: 'pengguna' as NamaIkon },
                        { label: 'Log aktivitas', href: '/admin/log', ikon: 'daftar' as NamaIkon },
                        { label: 'Pengaturan', href: '/admin/pengaturan', ikon: 'pengaturan' as NamaIkon },
                    ].map((t) => (
                        <Link
                            key={t.href}
                            href={t.href}
                            className="inline-flex items-center gap-1.5 rounded-wf border border-line px-2.5 py-1.5 text-xs text-ink-2 transition-colors duration-150 hover:border-accent/40 hover:bg-accent-soft/40 hover:text-accent"
                        >
                            <Ikon nama={t.ikon} ukuran={14} />
                            {t.label}
                        </Link>
                    ))}
                </div>
            </section>
        </AdminLayout>
    );
}
