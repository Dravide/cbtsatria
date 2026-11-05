import { Head } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Inbox, Kotak, Tabel } from '@/Components/ui';

/**
 * Papan keadaan sesi lintas ruang, dari konsol Admin.
 *
 * =====================================================================
 * Sama seperti papan pengawas: ia membaca exam_attempts yang belum punya
 * penulis sampai Fase 7
 * =====================================================================
 * Bentuknya sudah benar sekarang, sehingga Fase 7 tinggal mengisi
 * kolomnya. Satu hal yang karena itu harus jujur: kolom "Mengerjakan" dan
 * "Selesai" bernilai nol untuk seluruh ruangan sampai mesin ujian ada, dan
 * angka yang dikarang akan jauh lebih buruk daripada papan yang mengaku
 * kosong.
 *
 * ---------------------------------------------------------------------
 * Kolom TERPUTUS dihitung dari `last_seen_at`, bukan dari kolom tersendiri
 * ---------------------------------------------------------------------
 * "Terputus" bukan keadaan yang disimpan; ia keadaan yang DISIMPULKAN dari
 * selisih waktu - 90 detik tanpa kabar. Menyimpannya sebagai kolom berarti
 * kolom yang harus diperbarui oleh sesuatu yang mungkin tidak berjalan
 * justru saat jaringan putus.
 *
 * ---------------------------------------------------------------------
 * Yang TIDAK ada di laman ini: koreksi
 * ---------------------------------------------------------------------
 * Admin mengoreksi PRESENSI, dan itu ada di laman Presensi. Papan ini tidak
 * mengubah apa pun - ia hanya menjawab "bagaimana keadaan sesi sekarang".
 * Menaruh tombol yang mengubah data di papan yang sedang dibaca sambil
 * memantau berarti mengambil keputusan dari ringkasan, bukan dari orangnya.
 */
type Baris = {
    ruang_id: number;
    ruang: string;
    ruang_nama: string | null;
    sesi: string | null;
    ujian: string | null;
    status_sesi: string | null;
    status_label: string | null;
    peserta: number;
    mengerjakan: number;
    selesai: number;
    terputus: number;
    pelanggaran: number;
    terkunci: number;
    insiden: number;
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    baris: Baris[];
    angka: {
        ruang: number;
        peserta: number;
        mengerjakan: number;
        selesai: number;
        terputus: number;
        pelanggaran: number;
    };
};

export default function EventMonitoring({ event, baris, angka }: Props) {
    const terkunci = baris.filter((b) => b.terkunci > 0);
    const adaAttempt = angka.mengerjakan > 0 || angka.selesai > 0;

    return (
        <AdminLayout
            judul="Monitoring"
            breadcrumb={`/admin/events · ${event.kode} · Monitoring`}
            aksi={
                <span className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink-2">
                    <span className="text-ink-3">Event:</span>
                    <span className="font-medium text-ink">{event.nama}</span>
                </span>
            }
        >
            <Head title={`Monitoring · ${event.nama}`} />

            <div className="mb-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <KPI label="Ruang-sesi" nilai={angka.ruang} />
                <KPI label="Peserta" nilai={angka.peserta} />
                <KPI label="Mengerjakan" nilai={angka.mengerjakan} />
                <KPI label="Selesai" nilai={angka.selesai} nada="ok" />
                <KPI
                    label="Terputus"
                    nilai={angka.terputus}
                    nada={angka.terputus > 0 ? 'bad' : undefined}
                />
                <KPI
                    label="Pelanggaran"
                    nilai={angka.pelanggaran}
                    nada={angka.pelanggaran > 0 ? 'bad' : undefined}
                />
            </div>

            {!adaAttempt && (
                <div className="mb-3">
                    <Inbox>
                        Belum ada satu pun pengerjaan tercatat di event ini. Angka
                        pengerjaan baru terisi setelah mesin ujian menulisnya; yang bisa
                        dilihat sekarang adalah kesiapan dan insidennya.
                    </Inbox>
                </div>
            )}

            {terkunci.length > 0 && (
                <div className="mb-3">
                    <Inbox>
                        Ada peserta terkunci di{' '}
                        <span className="font-mono">
                            {terkunci.map((b) => b.ruang).join(', ')}
                        </span>
                        . Pembukaan kunci dilakukan pengawas ruangnya setelah berbicara
                        dengan yang bersangkutan.
                    </Inbox>
                </div>
            )}

            <Tabel<Baris>
                hal={{ data: baris, total: baris.length }}
                kunci={(b) => b.ruang_id}
                kosong="Event ini belum punya sesi yang menempatkan peserta di ruang mana pun."
                kolom={[
                    {
                        judul: 'Ruang',
                        render: (b) => (
                            <div>
                                <p className="font-medium text-ink">{b.ruang}</p>
                                <p className="text-[11px] text-ink-3">{b.ruang_nama}</p>
                            </div>
                        ),
                    },
                    {
                        judul: 'Sesi',
                        render: (b) => (
                            <div>
                                <p className="font-mono text-ink">{b.sesi ?? '—'}</p>
                                <p className="text-[11px] text-ink-3">{b.ujian ?? '—'}</p>
                            </div>
                        ),
                    },
                    {
                        judul: 'Status',
                        render: (b) =>
                            b.status_label ? (
                                <Chip nada={b.status_sesi === 'berjalan' ? 'info' : 'mute'}>
                                    {b.status_label}
                                </Chip>
                            ) : (
                                <span className="text-ink-3">—</span>
                            ),
                    },
                    {
                        judul: 'Peserta',
                        kelas: 'text-right',
                        render: (b) => <span className="font-mono">{b.peserta}</span>,
                    },
                    {
                        judul: 'Mengerjakan',
                        kelas: 'text-right',
                        render: (b) => (
                            <span className="font-mono">{b.mengerjakan}</span>
                        ),
                    },
                    {
                        judul: 'Selesai',
                        kelas: 'text-right',
                        render: (b) => <span className="font-mono text-ok">{b.selesai}</span>,
                    },
                    {
                        judul: 'Terputus',
                        kelas: 'text-right',
                        render: (b) =>
                            b.terputus > 0 ? (
                                <Chip nada="bad">{b.terputus}</Chip>
                            ) : (
                                <span className="font-mono text-ink-3">—</span>
                            ),
                    },
                    {
                        judul: 'Pelanggaran',
                        kelas: 'text-right',
                        render: (b) =>
                            b.pelanggaran > 0 ? (
                                <Chip nada="warn">{b.pelanggaran}</Chip>
                            ) : (
                                <span className="font-mono text-ink-3">—</span>
                            ),
                    },
                    {
                        judul: 'Terkunci',
                        kelas: 'text-right',
                        render: (b) =>
                            b.terkunci > 0 ? (
                                <Chip nada="bad">{b.terkunci}</Chip>
                            ) : (
                                <span className="font-mono text-ink-3">—</span>
                            ),
                    },
                    {
                        judul: 'Insiden',
                        kelas: 'text-right',
                        render: (b) =>
                            b.insiden > 0 ? (
                                <Chip nada="warn">{b.insiden} terbuka</Chip>
                            ) : (
                                <span className="text-ink-3">—</span>
                            ),
                    },
                ]}
            />

            <div className="mt-3">
                <Kotak judul="Catatan pembacaan papan ini">
                    <p className="text-xs leading-relaxed text-ink-2">
                        &ldquo;Terputus&rdquo; bukan keadaan yang disimpan, melainkan yang
                        disimpulkan: peserta yang tidak mengirim kabar lebih dari 90 detik.
                        Karena itu angkanya bisa naik turun sendiri tanpa ada yang
                        mengubah apa pun &mdash; dan itu memang keadaannya.
                    </p>
                </Kotak>
            </div>
        </AdminLayout>
    );
}

function KPI({ label, nilai, nada }: { label: string; nilai: number; nada?: 'ok' | 'bad' }) {
    const warna = nada === 'ok' ? 'text-ok' : nada === 'bad' ? 'text-bad' : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className={'mt-1 font-mono text-2xl font-medium ' + warna}>
                {nilai.toLocaleString('id-ID')}
            </p>
        </div>
    );
}
