import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    BilahFilter,
    Chip,
    Paginator,
    Tabel,
    Tombol,
    kelasInput,
    nadaStatus,
    pakaiFilter,
} from '@/Components/ui';

/**
 * Laman 10 - Daftar event. Wireframe plate 10.
 *
 * ---------------------------------------------------------------------
 * Tab bukan penyaring status yang sama
 * ---------------------------------------------------------------------
 * Plate 10 menampilkan empat tab: Berjalan / Draft / Selesai / Arsip. Yang
 * mudah salah adalah menganggap "Berjalan" berarti status = berjalan. Bukan:
 * tab itu menjawab "apa yang masih perlu saya kerjakan", dan event berstatus
 * persiapan atau siap juga belum selesai dikerjakan. Kalau ia hanya berisi
 * status berjalan, seorang Admin dengan tiga event di tahap persiapan melihat
 * tab itu kosong dan menyimpulkan tidak ada pekerjaan.
 *
 * Karena itu tab ini mencakup keempat status yang belum tuntas sekaligus,
 * sementara angkanya dihitung dari SELURUH sekolah - bukan dari halaman yang
 * kebetulan terlihat.
 *
 * ---------------------------------------------------------------------
 * Status "berjalan", bukan "aktif"
 * ---------------------------------------------------------------------
 * Wireframe plate 10 menandaskannya, dan kosakatanya dipakai konsisten: tidak
 * ada satu pun label di laman ini yang memakai kata "aktif" untuk event.
 */
type Baris = {
    id: number;
    kode: string;
    nama: string;
    periode: string | null;
    peserta: number;
    status: string;
    status_label: string;
    tahun: string | null;
    semester: string | null;
};

type Props = {
    tab: string;
    tab_angka: Record<string, number>;
    baris: Paginator<Baris>;
    filter: { cari: string };
};

const TAB = [
    { kunci: 'berjalan', label: 'Berjalan' },
    { kunci: 'draft', label: 'Draft' },
    { kunci: 'selesai', label: 'Selesai' },
    { kunci: 'arsip', label: 'Arsip' },
];

export default function EventIndex({ tab, tab_angka, baris, filter }: Props) {
    const f = pakaiFilter('/admin/events', { cari: filter.cari, tab });

    return (
        <AdminLayout
            judul="Event"
            breadcrumb="/admin/events"
            aksi={
                <Link href="/admin/events/create">
                    <Tombol nada="pri">Buat event</Tombol>
                </Link>
            }
        >
            <Head title="Event" />

            {/* Tab di atas tabel, angka di bawahnya. Urutan ini disengaja:
                Admin memilih cakupan dulu, lalu melihat berapa banyak di
                dalamnya. Angka KPI di plate 10 memang duduk di bawah tabel
                sebagai ringkasan seluruh sekolah, bukan ringkasan tab. */}
            <div className="mb-3 flex flex-wrap items-center gap-2">
                {TAB.map((t) => {
                    const on = t.kunci === tab;

                    return (
                        <button
                            key={t.kunci}
                            // Tab dikirim langsung lewat router, bukan lewat
                            // pakaiFilter. pakaiFilter menyimpan nilainya di
                            // state React, dan setState belum berlaku pada
                            // baris berikutnya - sehingga terapkan() akan
                            // mengirim tab yang LAMA. Satu klik akan terlihat
                            // tidak berbuat apa-apa, dan Admin mengkliknya dua
                            // kali sebelum menyadari tabnya memang berpindah.
                            onClick={() =>
                                router.get(
                                    '/admin/events',
                                    { ...(filter.cari ? { cari: filter.cari } : {}), tab: t.kunci },
                                    { preserveState: false, preserveScroll: true, replace: true },
                                )
                            }
                            className={
                                'rounded-wf border px-3 py-1.5 text-xs font-medium ' +
                                (on
                                    ? 'border-accent bg-accent-soft text-accent'
                                    : 'border-line bg-surface text-ink-2 hover:bg-surface-2')
                            }
                        >
                            {t.label}
                        </button>
                    );
                })}
            </div>

            <div className="mb-3">
                <BilahFilter padaKirim={f.terapkan}>
                    <input
                        value={f.nilai.cari ?? ''}
                        onChange={(e) => f.ubah('cari', e.target.value)}
                        placeholder="Cari nama atau kode event..."
                        className={kelasInput + ' max-w-xs'}
                    />
                    <Tombol type="submit" nada="pri">
                        Cari
                    </Tombol>
                </BilahFilter>
            </div>

            <Tabel<Baris>
                hal={baris}
                kunci={(b) => b.id}
                kosong={
                    tab === 'draft'
                        ? 'Belum ada event draft. Buat event untuk memulai.'
                        : 'Belum ada event pada tab ini.'
                }
                kolom={[
                    {
                        judul: 'Nama event',
                        render: (b) => (
                            <span>
                                <Link
                                    href={`/admin/events/${b.id}`}
                                    className="font-medium text-accent hover:underline"
                                >
                                    {b.nama}
                                </Link>
                                <span className="ml-2 font-mono text-[11px] text-ink-3">
                                    {b.kode}
                                </span>
                                {(b.tahun || b.semester) && (
                                    <span className="mt-0.5 block text-[11px] text-ink-3">
                                        {[b.tahun, b.semester].filter(Boolean).join(' · ')}
                                    </span>
                                )}
                            </span>
                        ),
                    },
                    {
                        judul: 'Periode',
                        kelas: 'w-28',
                        render: (b) => (
                            // Tanda pisah, bukan tanggal karangan. Event draft
                            // belum punya periode, dan menampilkan tanggal
                            // untuknya berarti mengarang data.
                            <span className="font-mono text-xs text-ink-2">
                                {b.periode ?? '—'}
                            </span>
                        ),
                    },
                    {
                        judul: 'Peserta',
                        kelas: 'w-24',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">
                                {b.peserta > 0 ? b.peserta.toLocaleString('id-ID') : '—'}
                            </span>
                        ),
                    },
                    {
                        judul: 'Status',
                        kelas: 'w-28',
                        render: (b) => (
                            <Chip nada={nadaStatus(b.status)}>{b.status_label}</Chip>
                        ),
                    },
                ]}
            />

            <div className="mt-3 grid gap-3 sm:grid-cols-4">
                <KPI label="Berjalan" nilai={tab_angka.berjalan} nada="info" />
                <KPI label="Draft" nilai={tab_angka.draft} />
                <KPI label="Selesai" nilai={tab_angka.selesai} nada="ok" />
                <KPI label="Arsip" nilai={tab_angka.arsip} />
            </div>

            <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                Event adalah induk seluruh aktivitas ujian. Komponennya &mdash; peserta, ruang,
                jadwal, pengawas, token &mdash; disusun di dalam event, dan tidak ada komponen
                yang bisa berdiri sendiri di luarnya.
            </p>
        </AdminLayout>
    );
}

function KPI({ label, nilai, nada }: { label: string; nilai: number; nada?: 'ok' | 'info' }) {
    const warna = nada === 'ok' ? 'text-ok' : nada === 'info' ? 'text-info' : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className={'mt-1 font-mono text-2xl font-medium ' + warna}>{nilai}</p>
        </div>
    );
}
