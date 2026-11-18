import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Inbox, Kotak, Tabel, TombolTautan } from '@/Components/ui';

/**
 * Rekap siswa TIDAK HADIR - nama, ruang, kelas.
 *
 * ---------------------------------------------------------------------
 * Pertanyaan yang dijawab, dan mengapa bukan papan monitoring
 * ---------------------------------------------------------------------
 * Papan monitoring menjawab "ruang mana yang tertinggal". Laman ini menjawab
 * pertanyaan yang berbeda: "siapa saja yang tidak hadir, di ruang apa, dan
 * kelas apa". Yang membacanya wali kelas, guru BK, dan Admin yang menyiapkan
 * ujian susulan - dan mereka membutuhkan DAFTAR NAMA, bukan angka per ruang.
 *
 * ---------------------------------------------------------------------
 * `tidak_hadir` dan `izin` dipisah, bukan digabung
 * ---------------------------------------------------------------------
 * Keduanya bukan kehadiran, tetapi menuntut tindakan yang berbeda: yang alpa
 * dipanggil dan mungkin butuh ujian susulan, yang izin sudah memberi kabar.
 * Satu kolom gabungan "tidak hadir" untuk keduanya akan membuat wali kelas
 * menelepon orang tua yang anaknya sudah berpamitan.
 *
 * ---------------------------------------------------------------------
 * Siswa `pending` TIDAK masuk rekap ini
 * ---------------------------------------------------------------------
 * Belum dipresensi bukan "tidak hadir". Memasukkannya berarti menuduh anak
 * yang mungkin sedang duduk di kursinya, hanya karena pengawasnya belum
 * sampai ke namanya.
 */

type Baris = {
    peserta_id: number;
    nis: string | null;
    nama: string | null;
    kelas: string | null;
    tingkat: string | null;
    ruang: string | null;
    ruang_nama: string | null;
    sesi: string | null;
    ujian: string | null;
    status: string;
    status_label: string;
    diverifikasi_pada: string | null;
    diverifikasi_oleh: string | null;
    catatan: string | null;
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    baris: Baris[];
    per_kelas: {
        kelas: string;
        tingkat: string | null;
        tidak_hadir: number;
        izin: number;
        total: number;
    }[];
    angka: { tidak_hadir: number; izin: number; kelas: number };
    monitoring_url: string;
    presensi_url: string;
};

export default function MonitoringTidakHadir({
    event,
    baris,
    per_kelas,
    angka,
    monitoring_url,
    presensi_url,
}: Props) {
    // Penyaring status: "semua", "tidak_hadir", atau "izin". Dua keadaan itu
    // menuntut tindakan berbeda, dan Admin yang menyiapkan ujian susulan hanya
    // perlu yang alpa.
    const [status, setStatus] = useState<'semua' | 'tidak_hadir' | 'izin'>('semua');
    const [cari, setCari] = useState('');

    const tersaring = useMemo(() => {
        const q = cari.trim().toLowerCase();

        return baris.filter((b) => {
            if (status !== 'semua' && b.status !== status) {
                return false;
            }

            if (q === '') {
                return true;
            }

            return `${b.nama ?? ''} ${b.nis ?? ''} ${b.kelas ?? ''} ${b.ruang ?? ''}`
                .toLowerCase()
                .includes(q);
        });
    }, [baris, status, cari]);

    return (
        <AdminLayout
            judul="Rekap tidak hadir"
            breadcrumb={`/admin/events · ${event.kode} · Monitoring · Tidak hadir`}
            aksi={
                <>
                    <TombolTautan href={monitoring_url}>Papan monitoring</TombolTautan>
                    <TombolTautan href={presensi_url}>Presensi &amp; koreksi</TombolTautan>
                </>
            }
        >
            <Head title={`Tidak hadir · ${event.nama}`} />

            <div className="mb-3 grid gap-3 sm:grid-cols-3">
                <KPI label="Tidak hadir" nilai={angka.tidak_hadir} nada="bad" />
                <KPI label="Izin" nilai={angka.izin} nada="warn" />
                <KPI label="Kelas terdampak" nilai={angka.kelas} />
            </div>

            {baris.length === 0 ? (
                <Kotak>
                    <Inbox>
                        Belum ada siswa yang ditandai <strong>tidak hadir</strong> atau{' '}
                        <strong>izin</strong> pada event ini. Rekap terbentuk dari presensi yang
                        sudah <em>diverifikasi</em> pengawas, siswa yang belum dipresensi
                        tidak dihitung sebagai tidak hadir.
                    </Inbox>
                </Kotak>
            ) : (
                <>
                    {/* ------------------------------------------ per kelas */}
                    {/* Ringkasan per kelas menjawab pertanyaan wali kelas tanpa
                        membuatnya menghitung baris sendiri. */}
                    {per_kelas.length > 0 && (
                        <Kotak judul="Ringkasan per kelas">
                            <div className="flex flex-wrap gap-2">
                                {per_kelas.map((k) => (
                                    <div
                                        key={k.kelas}
                                        className="rounded-wf border border-line bg-surface px-3 py-2"
                                    >
                                        <p className="font-mono text-xs font-medium text-ink">
                                            {k.kelas}
                                        </p>
                                        <p className="mt-0.5 text-[11px] text-ink-2">
                                            {k.tidak_hadir > 0 && (
                                                <span className="text-bad">
                                                    {k.tidak_hadir} alpa
                                                </span>
                                            )}
                                            {k.tidak_hadir > 0 && k.izin > 0 && ' · '}
                                            {k.izin > 0 && (
                                                <span className="text-warn">{k.izin} izin</span>
                                            )}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </Kotak>
                    )}

                    {/* --------------------------------------------- penyaring */}
                    <div className="mt-3 flex flex-wrap items-center gap-2 rounded-wf border border-line bg-surface px-3 py-2">
                        <input
                            value={cari}
                            onChange={(e) => setCari(e.target.value)}
                            placeholder="Cari nama, NIS, kelas, atau ruang"
                            className="min-w-0 flex-1 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink outline-none placeholder:text-ink-3 focus:border-accent focus:ring-2 focus:ring-accent/15 sm:max-w-xs"
                        />

                        <div className="flex overflow-hidden rounded-wf border border-line">
                            {(
                                [
                                    { kunci: 'semua', label: `Semua (${baris.length})` },
                                    {
                                        kunci: 'tidak_hadir',
                                        label: `Alpa (${angka.tidak_hadir})`,
                                    },
                                    { kunci: 'izin', label: `Izin (${angka.izin})` },
                                ] as const
                            ).map((t) => (
                                <button
                                    key={t.kunci}
                                    type="button"
                                    onClick={() => setStatus(t.kunci)}
                                    className={
                                        'px-2.5 py-1 text-[11px] font-medium transition-colors ' +
                                        (status === t.kunci
                                            ? 'bg-accent text-on-accent'
                                            : 'bg-surface text-ink-2 hover:bg-surface-2')
                                    }
                                >
                                    {t.label}
                                </button>
                            ))}
                        </div>

                        <span className="ml-auto text-[11px] text-ink-3">
                            {tersaring.length === baris.length
                                ? `${baris.length} siswa`
                                : `${tersaring.length} dari ${baris.length} siswa`}
                        </span>
                    </div>

                    <div className="mt-3">
                        <Tabel<Baris>
                            hal={{ data: tersaring, total: tersaring.length }}
                            kunci={(b) => b.peserta_id}
                            kosong="Tidak ada siswa yang cocok dengan penyaring ini."
                            kolom={[
                                {
                                    judul: 'NIS',
                                    kelas: 'w-24',
                                    render: (b) => (
                                        <span className="font-mono text-xs text-ink-2">
                                            {b.nis ?? '—'}
                                        </span>
                                    ),
                                },
                                {
                                    judul: 'Nama',
                                    render: (b) => (
                                        <span className="text-sm text-ink">{b.nama ?? '—'}</span>
                                    ),
                                },
                                {
                                    judul: 'Kelas',
                                    kelas: 'w-24',
                                    render: (b) => (
                                        <span className="font-mono text-xs text-ink-2">
                                            {b.kelas ?? '—'}
                                        </span>
                                    ),
                                },
                                {
                                    judul: 'Ruang',
                                    kelas: 'w-32',
                                    render: (b) => (
                                        <span>
                                            <span className="font-mono text-xs text-ink-2">
                                                {b.ruang ?? '—'}
                                            </span>
                                            {b.sesi && (
                                                <span className="ml-1.5 font-mono text-[11px] text-ink-3">
                                                    {b.sesi}
                                                </span>
                                            )}
                                        </span>
                                    ),
                                },
                                {
                                    judul: 'Ujian',
                                    render: (b) => (
                                        <span className="text-xs text-ink-2">{b.ujian ?? '—'}</span>
                                    ),
                                },
                                {
                                    judul: 'Status',
                                    kelas: 'w-28',
                                    render: (b) => (
                                        <Chip
                                            nada={
                                                b.status === 'tidak_hadir' ? 'bad' : 'warn'
                                            }
                                        >
                                            {b.status_label}
                                        </Chip>
                                    ),
                                },
                                {
                                    judul: 'Diverifikasi',
                                    kelas: 'w-40',
                                    render: (b) => (
                                        <span className="text-[11px] text-ink-3">
                                            {b.diverifikasi_pada ?? '—'}
                                            {b.diverifikasi_oleh && (
                                                <> · {b.diverifikasi_oleh}</>
                                            )}
                                        </span>
                                    ),
                                },
                            ]}
                        />
                    </div>

                    <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                        Data ini berasal dari presensi yang sudah <strong>diverifikasi</strong>{' '}
                        pengawas. Siswa yang belum dipresensi tidak muncul di sini. Ia masih
                        berstatus <em>belum diverifikasi</em>, dan memasukkannya berarti menuduh
                        anak yang mungkin sedang duduk di kursinya. Perbaiki presensinya di{' '}
                        <Link href={presensi_url} className="font-medium text-ink underline">
                            laman presensi
                        </Link>
                        .
                    </p>
                </>
            )}
        </AdminLayout>
    );
}

function KPI({
    label,
    nilai,
    nada,
}: {
    label: string;
    nilai: number;
    nada?: 'ok' | 'warn' | 'bad';
}) {
    const warna =
        nada === 'ok'
            ? 'text-ok'
            : nada === 'warn'
              ? 'text-warn'
              : nada === 'bad'
                ? 'text-bad'
                : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className={'mt-1 font-mono text-2xl font-medium ' + warna}>
                {nilai.toLocaleString('id-ID')}
            </p>
        </div>
    );
}
