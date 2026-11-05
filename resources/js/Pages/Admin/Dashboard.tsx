import { Head } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';

/**
 * Laman 03 - Dashboard sekolah. Wireframe plate 03.
 *
 * Tesis produk terlihat di sini: tanpa event aktif, konsol event belum
 * muncul, dan aksi utama adalah membuat event. Fase 1 menampilkan angkanya
 * apa adanya - sebagian nol karena master datanya memang belum ada.
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

export default function Dashboard({ sekolah, ringkasan }: Props) {
    const kpi = [
        { label: 'Guru', nilai: ringkasan.guru },
        { label: 'Siswa aktif', nilai: ringkasan.siswa },
        { label: 'Pengawas', nilai: ringkasan.pengawas },
        { label: 'Ruang', nilai: ringkasan.ruang },
    ];

    return (
        <AdminLayout judul="Ringkasan sekolah" breadcrumb={sekolah.nama}>
            <Head title="Dashboard" />

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {kpi.map((k) => (
                    <div
                        key={k.label}
                        className="rounded-wf border border-line bg-surface px-4 py-3"
                    >
                        <p className="text-xs text-ink-3">{k.label}</p>
                        <p className="mt-1 font-mono text-2xl font-medium text-ink">
                            {k.nilai}
                        </p>
                    </div>
                ))}
            </div>

            <div className="mt-4 rounded-wf border border-line bg-surface p-4">
                <p className="text-xs font-medium tracking-wide text-ink-2 uppercase">
                    Event berjalan &amp; mendatang
                </p>
                <p className="mt-3 text-sm text-ink-3">
                    Belum ada event. Laman daftar dan pembuatan event dibangun di
                    Fase 3 - sebelum itu, angka di atas adalah seluruh isi konsol.
                </p>
            </div>

            <div className="mt-4 grid gap-3 lg:grid-cols-2">
                <div className="rounded-wf border border-line bg-surface p-4">
                    <p className="text-xs font-medium tracking-wide text-ink-2 uppercase">
                        Kesiapan data master
                    </p>
                    <ul className="mt-3 space-y-2 text-sm text-ink-2">
                        <li>Sekolah &amp; akun admin &mdash; siap</li>
                        <li>Tahun ajaran, kelas, mapel, ruang &mdash; Fase 2</li>
                    </ul>
                </div>

                <div className="rounded-wf border border-line bg-surface p-4">
                    <p className="text-xs font-medium tracking-wide text-ink-2 uppercase">
                        Aktivitas terakhir
                    </p>
                    <p className="mt-3 text-sm text-ink-3">
                        Laman log aktivitas dibangun di Fase 2.
                    </p>
                </div>
            </div>
        </AdminLayout>
    );
}
