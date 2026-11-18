import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DaftarMapel, { MapelEvent } from '@/Components/DaftarMapelEvent';
import { Chip, Inbox, Kotak, TombolTautan } from '@/Components/ui';

/**
 * Monitoring - daftar SESI event, titik masuk papan monitoring Admin.
 *
 * =====================================================================
 * Konteksnya sama dengan laman presensi
 * =====================================================================
 * Sejak 2026-11-17 `/presensi` adalah daftar sesi, dan `/monitoring`
 * mengikuti bentuk yang sama. Alasannya bukan kerapian: kartu per ruang
 * MENCAMPUR sesi - R-01 dipakai pagi dan siang - sehingga "ruang mana yang
 * tertinggal" dijawab untuk gabungan beberapa ujian sekaligus, dan Admin
 * tidak bisa tahu sesi mana yang penyebabnya.
 *
 * Urutan pertanyaannya sesi, lalu ruang di dalamnya. Papan seluruh-ruang
 * tetap hidup di `/monitoring/ruang` untuk pertanyaan "R-07 sekarang
 * bagaimana", yang tidak dijawab satu sesi.
 *
 * ---------------------------------------------------------------------
 * "Terputus" adalah keadaan yang DISIMPULKAN, bukan disimpan
 * ---------------------------------------------------------------------
 * Ia dihitung dari selisih `last_seen_at` - 90 detik tanpa kabar. Karena itu
 * angkanya bisa naik turun sendiri tanpa ada yang mengubah apa pun, dan itu
 * memang keadaannya. Peserta yang belum mulai TIDAK masuk hitungan ini:
 * mereka belum punya jam kabar sama sekali.
 */

type Baris = {
    id: number;
    label: string;
    ujian: string | null;
    judul_ujian: string | null;
    tanggal: string | null;
    jam: string | null;
    jam_selesai: string | null;
    status: string;
    status_label: string;
    susulan: boolean;
    ruang: number;
    ruang_tanpa_pengawas: string[];
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
        sesi: number;
        peserta: number;
        mengerjakan: number;
        selesai: number;
        terputus: number;
        pelanggaran: number;
        terkunci: number;
    };
    mapel: MapelEvent[];
    jalur_sesi: string;
    jalur_ruang: string;
};

export default function MonitoringDaftarSesi({
    event,
    baris,
    angka,
    mapel,
    jalur_sesi,
    jalur_ruang,
}: Props) {
    const adaAttempt = angka.mengerjakan > 0 || angka.selesai > 0;

    return (
        <AdminLayout
            judul="Monitoring"
            breadcrumb={`/admin/events · ${event.kode} · Monitoring`}
            aksi={
                <>
                    <TombolTautan
                        href={`/admin/events/${event.id}/monitoring/tidak-hadir`}
                        title="Daftar siswa tidak hadir beserta ruang dan kelasnya"
                    >
                        Rekap tidak hadir
                    </TombolTautan>

                    <TombolTautan
                        href={jalur_ruang}
                        title="Papan keadaan seluruh ruang event, lintas sesi"
                    >
                        Semua ruang
                    </TombolTautan>

                    <span className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink-2">
                        <span className="text-ink-3">Event:</span>
                        <span className="font-medium text-ink">{event.nama}</span>
                    </span>
                </>
            }
        >
            <Head title={`Monitoring · ${event.nama}`} />

            {/* Mapel lebih dulu, alasannya sama dengan laman Presensi: papan
                di bawah disusun dari ruang-sesi, sehingga mapel yang jadwalnya
                belum dibuat tidak muncul satu kali pun. */}
            <div className="mb-3">
                <DaftarMapel mapel={mapel} denganPresensi={false} />
            </div>

            <div className="mb-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-7">
                <KPI label="Sesi" nilai={angka.sesi} />
                <KPI label="Peserta" nilai={angka.peserta} />
                <KPI label="Mengerjakan" nilai={angka.mengerjakan} />
                <KPI label="Selesai" nilai={angka.selesai} nada="ok" />
                <KPI
                    label="Terputus"
                    nilai={angka.terputus}
                    nada={angka.terputus > 0 ? 'bad' : undefined}
                />
                <KPI
                    label="Terkunci"
                    nilai={angka.terkunci}
                    nada={angka.terkunci > 0 ? 'bad' : undefined}
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
                        Belum ada satu pun pengerjaan tercatat di event ini. Angka pengerjaan
                        baru terisi setelah mesin ujian menulisnya; yang bisa dilihat sekarang
                        adalah kesiapan dan insidennya.
                    </Inbox>
                </div>
            )}

            {baris.length === 0 ? (
                <Kotak>
                    <p className="py-6 text-center text-sm text-ink-3">
                        Event ini belum punya sesi ujian. Susun jadwalnya di laman{' '}
                        <strong>Jadwal &amp; sesi</strong>.
                    </p>
                </Kotak>
            ) : (
                <div className="space-y-2">
                    {baris.map((b) => (
                        <KartuSesi key={b.id} sesi={b} jalur_sesi={jalur_sesi} />
                    ))}
                </div>
            )}

            <div className="mt-3">
                <Inbox>
                    &ldquo;Terputus&rdquo; bukan keadaan yang disimpan, melainkan yang
                    disimpulkan: peserta yang tidak mengirim kabar lebih dari 90 detik. Peserta
                    yang belum mulai tidak dihitung. Mereka belum punya jam kabar sama
                    sekali.
                </Inbox>
            </div>
        </AdminLayout>
    );
}

function KartuSesi({ sesi, jalur_sesi }: { sesi: Baris; jalur_sesi: string }) {
    const kurang = sesi.ruang_tanpa_pengawas.length;
    const belumMulai = sesi.peserta - sesi.mengerjakan - sesi.selesai;

    return (
        <Link
            href={`${jalur_sesi}/${sesi.id}`}
            className="block rounded-wf border border-line bg-surface px-3 py-2.5 transition-colors hover:border-ink-3/40 hover:bg-surface-2/40"
        >
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <span className="font-mono text-sm font-medium text-ink">{sesi.label}</span>
                <span className="text-sm text-ink">{sesi.ujian ?? '—'}</span>
                {sesi.susulan && <Chip nada="info">susulan</Chip>}
                <Chip nada={sesi.status === 'berjalan' ? 'info' : 'mute'}>
                    {sesi.status_label}
                </Chip>

                <span className="font-mono text-[11px] text-ink-3">
                    {sesi.tanggal ?? '—'} · {sesi.jam ?? '—'}
                    {sesi.jam_selesai ? `–${sesi.jam_selesai}` : ''}
                </span>

                <span className="ml-auto flex items-center gap-2">
                    <span className="font-mono text-[11px] text-ink-3">
                        {sesi.ruang} ruang · {sesi.peserta} peserta
                    </span>

                    {sesi.terputus > 0 && <Chip nada="bad">{sesi.terputus} terputus</Chip>}
                    {sesi.terkunci > 0 && <Chip nada="bad">{sesi.terkunci} terkunci</Chip>}
                    {sesi.pelanggaran > 0 && (
                        <Chip nada="warn">{sesi.pelanggaran} pelanggaran</Chip>
                    )}

                    <span className="text-[11px] font-medium text-accent">Rincian →</span>
                </span>
            </div>

            <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-ink-3">
                <span>
                    Mengerjakan{' '}
                    <span className="font-mono text-info">{sesi.mengerjakan}</span>
                </span>
                <span>
                    Selesai{' '}
                    <span className="font-mono text-ok">{sesi.selesai}</span>
                </span>
                <span>
                    Belum mulai <span className="font-mono text-ink-2">{belumMulai}</span>
                </span>
                {sesi.insiden > 0 && (
                    <span className="text-warn">{sesi.insiden} insiden belum ditangani</span>
                )}
            </div>

            {kurang > 0 && (
                <p className="mt-1 text-[11px] text-bad">
                    Ruang tanpa pengawas: {sesi.ruang_tanpa_pengawas.join(', ')}
                </p>
            )}
        </Link>
    );
}

function KPI({
    label,
    nilai,
    nada,
}: {
    label: string;
    nilai: number;
    nada?: 'ok' | 'bad';
}) {
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
