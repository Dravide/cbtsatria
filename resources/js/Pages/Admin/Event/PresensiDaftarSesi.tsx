import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DaftarMapel, { MapelEvent } from '@/Components/DaftarMapelEvent';
import { Chip, Inbox, Kotak, TombolTautan } from '@/Components/ui';

/**
 * Presensi - daftar SESI event, titik masuk laman presensi Admin.
 *
 * =====================================================================
 * Mengapa sesi lebih dulu, bukan ruang
 * =====================================================================
 * Sampai 2026-11-17 `/presensi` langsung menampilkan tiga puluh tiga kartu
 * ruang, dan dua hal salah sekaligus:
 *
 *   1. Kartunya MENCAMPUR sesi. Sesi 1 dan sesi 5 memakai R-01, sehingga
 *      angka "belum tuntas" satu kartu adalah gabungan dua ujian yang
 *      tidak punya hubungan apa pun.
 *   2. Pertanyaan pertama Admin - "sesi mana yang belum tuntas" - hanya
 *      bisa dijawab dengan menjumlahkan tiga puluh tiga kartu sendiri.
 *
 * Urutan pertanyaannya sesi, lalu ruang di dalamnya. Karena itu halaman ini
 * satu baris per sesi, dan rinciannya di laman sesinya.
 *
 * ---------------------------------------------------------------------
 * Papan seluruh-ruang TETAP hidup, di `/presensi/ruang`
 * ---------------------------------------------------------------------
 * Ada satu pertanyaan yang tidak dijawab satu sesi: "R-07 hari ini
 * bagaimana". Admin yang mendampingi satu pengawas lewat telepon melihat
 * satu ruangan, bukan satu sesi. Menghapus papan itu berarti ia harus
 * membuka dua belas sesi untuk menemukan satu ruangan.
 *
 * ---------------------------------------------------------------------
 * `pending` di sini adalah "belum dipresensi", bukan "tidak datang"
 * ---------------------------------------------------------------------
 * Peserta yang belum punya baris presensi dihitung `pending`, sama seperti
 * di papan ruang. Menghitungnya sebagai tidak hadir akan menjadikan sesi
 * yang belum dimulai terbaca sebagai sesi yang seluruh siswanya alpa.
 */

type Rekap = {
    total: number;
    hadir: number;
    terlambat: number;
    tidak_hadir: number;
    izin: number;
    pending: number;
};

type Baris = {
    id: number;
    label: string;
    ujian: string | null;
    judul_ujian: string | null;
    tanggal: string | null;
    jam: string | null;
    selesai: string | null;
    status: string;
    status_label: string;
    jenis: string;
    susulan: boolean;
    ruang: number;
    ruang_tanpa_pengawas: string[];
    rekap: Rekap;
    insiden: number;
    berita_acara: { ada: number; final: number; dibuka: number };
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    baris: Baris[];
    angka: {
        sesi: number;
        belum_tuntas: number;
        peserta: number;
        hadir: number;
        tanpa_pengawas: number;
        insiden: number;
    };
    mapel: MapelEvent[];
    jalur_sesi: string;
    jalur_ruang: string;
};

export default function PresensiDaftarSesi({
    event,
    baris,
    angka,
    mapel,
    jalur_sesi,
    jalur_ruang,
}: Props) {
    return (
        <AdminLayout
            judul="Presensi"
            breadcrumb={`/admin/events · ${event.kode} · Presensi`}
            aksi={
                <>
                    {/* Papan seluruh ruang. Alarm yang dibunyikan Admin saat
                        satu pengawas menelepon biasanya tentang satu RUANGAN,
                        bukan satu sesi - dan papan itulah yang menjawabnya
                        dalam sekali buka. */}
                    <TombolTautan
                        href={jalur_ruang}
                        title="Papan presensi seluruh ruang event, lintas sesi"
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
            <Head title={`Presensi · ${event.nama}`} />

            {/* Mapel lebih dulu: pertanyaan pertama Admin adalah "mapel apa
                saja yang diujikan", bukan "sesi mana yang tertinggal". Daftar
                ini juga yang membuat mapel TANPA jadwal tetap terlihat -
                `baris` di bawah hanya memuat yang sudah punya sesi. */}
            <div className="mb-3">
                <DaftarMapel mapel={mapel} denganPresensi={false} />
            </div>

            <div className="mb-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <KPI label="Sesi" nilai={angka.sesi} />
                <KPI
                    label="Sesi belum tuntas"
                    nilai={angka.belum_tuntas}
                    nada={angka.belum_tuntas > 0 ? 'bad' : 'ok'}
                />
                <KPI label="Hadir" nilai={angka.hadir} nada="ok" />
                <KPI label="Peserta" nilai={angka.peserta} />
                <KPI
                    label="Ruang tanpa pengawas"
                    nilai={angka.tanpa_pengawas}
                    nada={angka.tanpa_pengawas > 0 ? 'bad' : 'ok'}
                />
                <KPI
                    label="Insiden"
                    nilai={angka.insiden}
                    nada={angka.insiden > 0 ? 'bad' : undefined}
                />
            </div>

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
                    Angka &ldquo;belum&rdquo; berarti <strong>belum dipresensi</strong>, bukan
                    tidak hadir. Peserta yang belum ditandai pengawasnya masuk ke sana, dan
                    mereka masih mungkin duduk di kursinya.
                </Inbox>
            </div>
        </AdminLayout>
    );
}

function KartuSesi({ sesi, jalur_sesi }: { sesi: Baris; jalur_sesi: string }) {
    const kurangPengawas = sesi.ruang_tanpa_pengawas.length;
    const tuntas = sesi.rekap.pending === 0 && sesi.rekap.total > 0;

    return (
        <Link
            href={`${jalur_sesi}/${sesi.id}`}
            className="block rounded-wf border border-line bg-surface px-3 py-2.5 transition-colors hover:border-ink-3/40 hover:bg-surface-2/40"
        >
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <span className="font-mono text-sm font-medium text-ink">{sesi.label}</span>
                <span className="text-sm text-ink">{sesi.ujian ?? '—'}</span>
                {sesi.susulan && <Chip nada="info">susulan</Chip>}

                <span className="font-mono text-[11px] text-ink-3">
                    {sesi.tanggal ?? '—'} · {sesi.jam ?? '—'}
                    {sesi.selesai ? `–${sesi.selesai}` : ''}
                </span>

                <span className="ml-auto flex items-center gap-2">
                    <span className="font-mono text-[11px] text-ink-3">
                        {sesi.ruang} ruang · {sesi.rekap.total} peserta
                    </span>

                    {sesi.rekap.pending > 0 ? (
                        <Chip nada="warn">{sesi.rekap.pending} belum</Chip>
                    ) : sesi.rekap.total === 0 ? (
                        <Chip nada="mute">tanpa peserta</Chip>
                    ) : (
                        <Chip nada="ok">tuntas</Chip>
                    )}

                    {/* Berita acara: "belum dibuka" ikut dihitung belum,
                        karena ruang yang belum disentuh pengawasnya sama
                        belum selesainya dengan ruang yang fotonya kurang. */}
                    <Chip
                        nada={
                            sesi.berita_acara.ada === 0
                                ? 'mute'
                                : sesi.berita_acara.final === sesi.berita_acara.ada
                                  ? 'ok'
                                  : 'warn'
                        }
                    >
                        BA {sesi.berita_acara.final}/{sesi.ruang}
                    </Chip>

                    <span className="text-[11px] font-medium text-accent">Rincian →</span>
                </span>
            </div>

            <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-ink-3">
                <span>
                    Hadir <span className="font-mono text-ink-2">{sesi.rekap.hadir}</span>
                </span>
                <span>
                    Terlambat <span className="font-mono text-ink-2">{sesi.rekap.terlambat}</span>
                </span>
                <span>
                    Tidak hadir{' '}
                    <span className="font-mono text-ink-2">{sesi.rekap.tidak_hadir}</span>
                </span>
                <span>
                    Izin <span className="font-mono text-ink-2">{sesi.rekap.izin}</span>
                </span>
                <span className={tuntas ? 'text-ok' : 'text-warn'}>
                    Belum <span className="font-mono">{sesi.rekap.pending}</span>
                </span>

                {sesi.insiden > 0 && <span className="text-warn">{sesi.insiden} insiden</span>}
            </div>

            {/* Ruang tanpa pengawas disebut NAMANYA: sesi yang ruangnya tidak
                terjaga tidak akan bisa dibuka siswanya, dan "3 ruang" tidak
                menjawab ruang mana yang harus dicari. */}
            {kurangPengawas > 0 && (
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
