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
    /**
     * Yang perlu diperhatikan pada ujian yang waktunya sudah dekat atau
     * sedang berjalan. KOSONG berarti tidak ada yang perlu dikerjakan - dan
     * itulah keadaan yang paling sering.
     */
    peringatan: {
        kunci: string;
        judul: string;
        pesan: string;
        nada: string;
        href: string;
    }[];
    event_ringkasan?: {
        id: number;
        angka: {
            sesi: number;
            peserta: number;
            mengerjakan: number;
            selesai: number;
            terkunci: number;
            notifikasi: number;
        };
        sesi_terdekat: {
            id: number;
            label: string;
            ujian: string | null;
            tanggal: string;
            jam: string;
            status: string;
            status_label: string;
        }[];
        notifikasi_terbaru: {
            id: number;
            tingkat: string;
            judul: string;
            pesan: string;
            konteks_label: string | null;
            tautan: string | null;
            dibaca: boolean;
            waktu: string;
        }[];
    } | null;
    aktivitas?: {
        id: number;
        aksi: string;
        aktor: string;
        waktu: string;
    }[];
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

export default function Dashboard({
    sekolah,
    ringkasan,
    peringatan,
    event_ringkasan = null,
    aktivitas = [],
}: Props) {
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

            {/* Peringatan hari-H berdiri DI ATAS "Event berjalan", dan
                urutannya disengaja: kartu di bawahnya menjawab "event mana",
                panel ini menjawab "apa yang menghambatnya sekarang". Admin
                yang membuka beranda pada hari-H datang untuk jawaban kedua.

                Panelnya HILANG sepenuhnya saat tidak ada peringatan - bukan
                kosong dengan judul. Kotak "tidak ada masalah" mengajari orang
                melewatinya, dan yang dilewati adalah kotak yang sama. */}
            {peringatan.length > 0 && (
                <section className="mt-3 rounded-wf border border-warn/40 bg-warn-bg/40 p-4">
                    <div className="flex items-center gap-2">
                        <Ikon nama="denyut" ukuran={16} className="text-warn" />
                        <h2 className="font-display text-sm font-semibold text-ink">
                            Perlu perhatian sebelum ujian berjalan
                        </h2>
                        <Chip nada="warn">{peringatan.length}</Chip>
                    </div>

                    <ul className="mt-3 space-y-2">
                        {peringatan.map((p) => (
                            <li
                                key={p.kunci}
                                className="rounded-wf border border-line bg-surface px-3 py-2.5"
                            >
                                <div className="flex flex-wrap items-start gap-x-3 gap-y-1">
                                    <div className="min-w-0 flex-1">
                                        <p
                                            className={
                                                'text-sm font-medium ' +
                                                (p.nada === 'bad' ? 'text-bad' : 'text-ink')
                                            }
                                        >
                                            {p.judul}
                                        </p>
                                        <p className="mt-0.5 text-xs leading-relaxed text-ink-2">
                                            {p.pesan}
                                        </p>
                                    </div>
                                    <Link
                                        href={p.href}
                                        className="shrink-0 rounded-wf border border-line px-2.5 py-1.5 text-xs font-medium text-accent transition-colors duration-150 hover:border-accent/50 hover:bg-accent-soft/40"
                                    >
                                        Perbaiki
                                    </Link>
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <div className="mt-3 grid gap-3 lg:grid-cols-2">
                <section className="rounded-wf border border-line bg-surface p-4">
                    <div className="flex items-center gap-2">
                        <Ikon nama="bendera" ukuran={16} className="text-ink-3" />
                        <h2 className="font-display text-sm font-semibold text-ink">
                            Event berjalan
                        </h2>
                    </div>

                    {eventAktif ? (
                        <div className="mt-3 space-y-3">
                            <div>
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

                            {event_ringkasan && (
                                <div className="grid grid-cols-3 gap-2">
                                    <Link
                                        href={`/admin/events/${eventAktif.id}/jadwal`}
                                        className="rounded-wf border border-line bg-wf-soft px-2.5 py-2 hover:border-accent/40"
                                    >
                                        <p className="text-[11px] text-ink-3">Sesi</p>
                                        <p className="font-mono text-base font-medium text-ink">
                                            {event_ringkasan.angka.sesi}
                                        </p>
                                    </Link>
                                    <Link
                                        href={`/admin/events/${eventAktif.id}/monitoring`}
                                        className="rounded-wf border border-line bg-wf-soft px-2.5 py-2 hover:border-accent/40"
                                    >
                                        <p className="text-[11px] text-ink-3">Mengerjakan</p>
                                        <p className="font-mono text-base font-medium text-ink">
                                            {event_ringkasan.angka.mengerjakan}
                                        </p>
                                    </Link>
                                    <Link
                                        href={`/admin/events/${eventAktif.id}/monitoring/terkunci`}
                                        className="rounded-wf border border-line bg-wf-soft px-2.5 py-2 hover:border-bad/50"
                                    >
                                        <p className="text-[11px] text-ink-3">Terkunci</p>
                                        <p
                                            className={
                                                'font-mono text-base font-medium ' +
                                                (event_ringkasan.angka.terkunci > 0
                                                    ? 'text-bad'
                                                    : 'text-ink')
                                            }
                                        >
                                            {event_ringkasan.angka.terkunci}
                                        </p>
                                    </Link>
                                </div>
                            )}

                            <div className="flex flex-wrap gap-1.5 pt-1">
                                {[
                                    ['Monitoring', `/admin/events/${eventAktif.id}/monitoring`],
                                    ['Siswa Terkunci', `/admin/events/${eventAktif.id}/monitoring/terkunci`],
                                    ['Jadwal & Sesi', `/admin/events/${eventAktif.id}/jadwal`],
                                    ['Jadwal Pengawas', `/admin/events/${eventAktif.id}/pengawas`],
                                    ['Token Ujian', `/admin/events/${eventAktif.id}/token`],
                                ].map(([lbl, href]) => (
                                    <Link
                                        key={href}
                                        href={href}
                                        className="rounded-wf border border-line px-2 py-1 text-[11px] font-medium text-ink-2 hover:border-accent/40 hover:bg-accent-soft/40 hover:text-accent"
                                    >
                                        {lbl}
                                    </Link>
                                ))}
                            </div>
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

            {eventAktif && event_ringkasan && (
                <div className="mt-3 grid gap-3 lg:grid-cols-2">
                    <section className="rounded-wf border border-line bg-surface p-4">
                        <div className="flex items-center justify-between gap-2">
                            <div className="flex items-center gap-2">
                                <Ikon nama="jam" ukuran={16} className="text-ink-3" />
                                <h2 className="font-display text-sm font-semibold text-ink">
                                    Jadwal sesi terdekat
                                </h2>
                            </div>
                            <Link
                                href={`/admin/events/${eventAktif.id}/jadwal`}
                                className="text-xs font-medium text-accent hover:underline"
                            >
                                Semua jadwal →
                            </Link>
                        </div>

                        {event_ringkasan.sesi_terdekat.length === 0 ? (
                            <p className="mt-3 text-xs text-ink-3">
                                Belum ada sesi ujian dijadwalkan pada event ini.
                            </p>
                        ) : (
                            <ul className="mt-3 divide-y divide-line">
                                {event_ringkasan.sesi_terdekat.map((s) => (
                                    <li
                                        key={s.id}
                                        className="flex items-center justify-between gap-2 py-2 text-xs first:pt-0 last:pb-0"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate font-medium text-ink">
                                                {s.label} · {s.ujian ?? 'Ujian'}
                                            </p>
                                            <p className="font-mono text-[11px] text-ink-3">
                                                {s.tanggal} · {s.jam}
                                            </p>
                                        </div>
                                        <Chip
                                            nada={
                                                s.status === 'berlangsung'
                                                    ? 'ok'
                                                    : s.status === 'siap'
                                                      ? 'info'
                                                      : 'mute'
                                            }
                                        >
                                            {s.status_label}
                                        </Chip>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="rounded-wf border border-line bg-surface p-4">
                        <div className="flex items-center justify-between gap-2">
                            <div className="flex items-center gap-2">
                                <Ikon nama="denyut" ukuran={16} className="text-ink-3" />
                                <h2 className="font-display text-sm font-semibold text-ink">
                                    Notifikasi anomali terbaru
                                </h2>
                                {event_ringkasan.angka.notifikasi > 0 && (
                                    <Chip nada="bad">{event_ringkasan.angka.notifikasi} baru</Chip>
                                )}
                            </div>
                            <Link
                                href={`/admin/events/${eventAktif.id}/notifikasi`}
                                className="text-xs font-medium text-accent hover:underline"
                            >
                                Lihat semua →
                            </Link>
                        </div>

                        {event_ringkasan.notifikasi_terbaru.length === 0 ? (
                            <p className="mt-3 text-xs text-ink-3">
                                Belum ada notifikasi anomali pada event ini.
                            </p>
                        ) : (
                            <ul className="mt-3 divide-y divide-line">
                                {event_ringkasan.notifikasi_terbaru.map((n) => (
                                    <li
                                        key={n.id}
                                        className="flex items-start justify-between gap-2 py-2 text-xs first:pt-0 last:pb-0"
                                    >
                                        <div className="min-w-0">
                                            <p className="font-medium text-ink">{n.judul}</p>
                                            <p className="truncate text-[11px] text-ink-2">
                                                {n.pesan}
                                            </p>
                                        </div>
                                        <span className="shrink-0 font-mono text-[10px] text-ink-3">
                                            {n.waktu}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            )}

            <div className="mt-3 grid gap-3 lg:grid-cols-2">
                <section className="rounded-wf border border-line bg-surface p-4">
                    <div className="flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <Ikon nama="daftar" ukuran={16} className="text-ink-3" />
                            <h2 className="font-display text-sm font-semibold text-ink">
                                Aktivitas terbaru
                            </h2>
                        </div>
                        <Link
                            href="/admin/log"
                            className="text-xs font-medium text-accent hover:underline"
                        >
                            Log aktivitas →
                        </Link>
                    </div>

                    {aktivitas.length === 0 ? (
                        <p className="mt-3 text-xs text-ink-3">Belum ada aktivitas tercatat.</p>
                    ) : (
                        <ul className="mt-3 divide-y divide-line">
                            {aktivitas.map((a) => (
                                <li
                                    key={a.id}
                                    className="flex items-center justify-between gap-2 py-2 text-xs first:pt-0 last:pb-0"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-medium text-ink">{a.aksi}</p>
                                        <p className="text-[11px] text-ink-3">{a.aktor}</p>
                                    </div>
                                    <span className="shrink-0 font-mono text-[10px] text-ink-3">
                                        {a.waktu}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="rounded-wf border border-line bg-surface p-4">
                    <div className="flex items-center gap-2">
                        <Ikon nama="daftar" ukuran={16} className="text-ink-3" />
                        <h2 className="font-display text-sm font-semibold text-ink">
                            Pintasan cepat
                        </h2>
                    </div>
                    <div className="mt-3 flex flex-wrap gap-2">
                        {[
                            { label: 'Profil Sekolah', href: '/admin/sekolah', ikon: 'sekolah' as NamaIkon },
                            { label: 'Identitas & Logo', href: '/admin/sekolah/identitas', ikon: 'pengaturan' as NamaIkon },
                            { label: 'Data Kelas', href: '/admin/kelas', ikon: 'kelas' as NamaIkon },
                            { label: 'Mata Pelajaran', href: '/admin/mapel', ikon: 'buku' as NamaIkon },
                            { label: 'Bank Soal', href: '/admin/bank-soal', ikon: 'paket' as NamaIkon },
                            { label: 'Akun & Pengguna', href: '/admin/pengguna', ikon: 'pengguna' as NamaIkon },
                            { label: 'Pengaturan Sekolah', href: '/admin/pengaturan', ikon: 'pengaturan' as NamaIkon },
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
            </div>
        </AdminLayout>
    );
}
