import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Inbox, Tombol, kelasInput } from '@/Components/ui';
import { formatUkuran } from '@/Components/angka';

/**
 * Laporan & ekspor - wireframe-admin.html plate 22.
 *
 * ---------------------------------------------------------------------
 * Layar dan berkas berbagi satu sumber, jadi tabelnya juga
 * ---------------------------------------------------------------------
 * `LaporanData` di server menyajikan baris yang sama untuk layar dan untuk
 * berkas. Laman ini tidak menyusun kolomnya sendiri: `kolom` datang sebagai
 * prop, dan setiap sel membaca kunci yang sama dari barisnya. Menambah satu
 * kolom di laporan nilai berarti satu perubahan di `LaporanData::kolom()`,
 * dan tabel di sini ikut berubah tanpa disentuh.
 *
 * ---------------------------------------------------------------------
 * Ekspor adalah antrean, dan laman ini mengatakannya
 * ---------------------------------------------------------------------
 * Kalimat di kaki plate 22: "berkas besar: disiapkan di latar belakang".
 * Tombol XLSX/CSV/PDF menulis satu baris `report_exports` lalu kembali; yang
 * membuat berkasnya adalah worker. Karena itu ada daftar "Ekspor terakhir" di
 * bawah tabel - tanpa daftar itu, Admin yang menekan tombol lalu berpindah
 * laman tidak punya cara menemukan berkasnya.
 */

type Kolom = { kunci: string; label: string };

type BarisLaporan = Record<string, string | number | null>;

type Ekspor = {
    id: number;
    jenis: string;
    format: string;
    status: string;
    status_label: string;
    status_nada: string;
    total_baris: number | null;
    ukuran: number | null;
    nama_berkas: string | null;
    pesan_gagal: string | null;
    peminta: string | null;
    diminta_pada: string | null;
    selesai_pada: string | null;
};

type Props = {
    event: { id: number; nama: string; kode: string; terbit: boolean } | null;
    jenis: string;
    daftar_jenis: { kunci: string; label: string }[];
    format: { kunci: string; label: string }[];
    kolom: Kolom[];
    pilihan_kelas: { id: string; label: string }[];
    pilihan_mapel: { id: string; label: string }[];
    filter: { kelas: string; mapel: string };
    tabel: {
        data: BarisLaporan[];
        dari: number;
        sampai: number;
        total: number;
        halaman_kini: number;
        halaman_akhir: number;
        tautan: { url: string | null; label: string; active: boolean }[];
    };
    total: number;
    ekspor: Ekspor[];
};

export default function Laporan({
    event,
    jenis,
    daftar_jenis,
    format,
    kolom,
    pilihan_kelas,
    pilihan_mapel,
    filter,
    tabel,
    total,
    ekspor,
}: Props) {
    const [kirim, setKirim] = useState<string | null>(null);

    /**
     * Berganti tab membuang penyaringnya.
     *
     * Kelas dan mata pelajaran bermakna berbeda antar laporan - rekap kelas
     * tidak punya kolom mapel, analisis butir tidak punya kolom kelas - dan
     * penyaring yang terbawa ke tab yang tidak memakainya akan menghasilkan
     * tabel kosong yang tidak bisa dijelaskan.
     */
    const keTab = (kunci: string) => {
        router.get('/admin/laporan', { jenis: kunci }, { preserveScroll: true, replace: true });
    };

    const atur = (kunci: 'kelas' | 'mapel', nilai: string) => {
        const muatan: Record<string, string> = { jenis };

        if (kunci === 'kelas' ? nilai : filter.kelas) {
            muatan.kelas = kunci === 'kelas' ? nilai : filter.kelas;
        }

        if (kunci === 'mapel' ? nilai : filter.mapel) {
            muatan.mapel = kunci === 'mapel' ? nilai : filter.mapel;
        }

        router.get('/admin/laporan', muatan, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const mintaEkspor = (formatKunci: string) => {
        if (kirim !== null || event === null) {
            return;
        }

        setKirim(formatKunci);

        router.post(
            '/admin/laporan',
            {
                jenis,
                format: formatKunci,
                kelas: filter.kelas || null,
                mapel: filter.mapel || null,
            },
            {
                preserveScroll: true,
                onFinish: () => setKirim(null),
            },
        );
    };

    const kosong = useMemo(
        () =>
            event === null
                ? 'Pilih event aktif lebih dulu. Laporan selalu milik satu event.'
                : 'Tidak ada baris untuk penyaring ini.',
        [event],
    );

    return (
        <AdminLayout
            judul="Laporan"
            breadcrumb="Laporan"
            aksi={
                // Tiga tombol format di kanan atas, sesuai bilah plate 22.
                // Diletakkan di sini - bukan di kepala tabel - karena ia
                // berlaku untuk SELURUH laporan yang sedang dibuka, bukan
                // untuk satu halaman tabel.
                <>
                    {format.map((f) => (
                        <Tombol
                            key={f.kunci}
                            disabled={event === null || kirim !== null}
                            onClick={() => mintaEkspor(f.kunci)}
                            title={
                                event === null
                                    ? 'Pilih event aktif lebih dulu'
                                    : `Siapkan berkas ${f.label} di latar belakang`
                            }
                        >
                            {kirim === f.kunci ? 'Menyiapkan…' : f.label}
                        </Tombol>
                    ))}
                </>
            }
        >
            <Head title="Laporan & ekspor" />

            {event === null && (
                <div className="mb-3">
                    <Inbox>
                        Laporan selalu milik <strong>satu event</strong>. Pilih event aktif dari
                        sidebar lebih dulu — nilai lintas event tidak punya arti, karena kelas yang
                        sama mengerjakan ujian yang berbeda.
                    </Inbox>
                </div>
            )}

            {event !== null && (
                <div className="mb-3 flex flex-wrap items-center gap-2 text-xs text-ink-3">
                    <span className="text-ink-2">
                        Event: <strong className="font-medium text-ink">{event.nama}</strong>
                    </span>
                    <span className="text-ink-3/50">·</span>
                    <span className="font-mono">{event.kode}</span>
                    {!event.terbit && (
                        <Chip nada="warn">hasil belum dipublikasikan</Chip>
                    )}
                </div>
            )}

            {/* --------------------------------------------------- Tab */}
            <div className="mb-3 flex flex-wrap gap-1 border-b border-line">
                {daftar_jenis.map((j) => (
                    <button
                        key={j.kunci}
                        onClick={() => keTab(j.kunci)}
                        className={
                            '-mb-px border-b-2 px-3 py-2 text-xs font-medium transition-colors ' +
                            (j.kunci === jenis
                                ? 'border-accent text-accent'
                                : 'border-transparent text-ink-3 hover:text-ink')
                        }
                    >
                        {j.label}
                    </button>
                ))}
            </div>

            {/* ----------------------------------------------- Penyaring */}
            <div className="mb-3 flex flex-wrap items-end gap-2">
                <label className="block">
                    <span className="mb-1 block text-[11px] text-ink-3">Kelas</span>
                    <select
                        value={filter.kelas}
                        onChange={(e) => atur('kelas', e.target.value)}
                        disabled={pilihan_kelas.length === 0}
                        className={kelasInput + ' max-w-[14rem]'}
                    >
                        <option value="">Semua kelas</option>
                        {pilihan_kelas.map((k) => (
                            <option key={k.id} value={k.id}>
                                {k.label}
                            </option>
                        ))}
                    </select>
                </label>

                <label className="block">
                    <span className="mb-1 block text-[11px] text-ink-3">Mata pelajaran</span>
                    <select
                        value={filter.mapel}
                        onChange={(e) => atur('mapel', e.target.value)}
                        disabled={pilihan_mapel.length === 0}
                        className={kelasInput + ' max-w-[14rem]'}
                    >
                        <option value="">Semua mapel</option>
                        {pilihan_mapel.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.label}
                            </option>
                        ))}
                    </select>
                </label>

                {(filter.kelas || filter.mapel) && (
                    <Tombol onClick={() => router.get('/admin/laporan', { jenis }, { replace: true })}>
                        Reset
                    </Tombol>
                )}

                {/* Bilah penyaring tidak punya tombol "Terapkan" karena setiap
                    pemilih langsung mengirim - dan itu disengaja: dua penyaring
                    saja, dan menunggu tombol hanya menambah satu klik untuk
                    setiap perubahan. */}
            </div>

            {/* -------------------------------------------------- Tabel */}
            <div className="overflow-hidden rounded-wf border border-line bg-surface">
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[40rem] text-sm">
                        <thead>
                            <tr className="border-b border-line bg-surface-2/60 text-left">
                                {kolom.map((k) => (
                                    <th
                                        key={k.kunci}
                                        className="px-3 py-2 text-[11px] font-medium text-ink-3"
                                    >
                                        {k.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {tabel.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={kolom.length || 1}
                                        className="px-3 py-8 text-center text-sm text-ink-3"
                                    >
                                        {kosong}
                                    </td>
                                </tr>
                            )}

                            {tabel.data.map((b, i) => (
                                <tr
                                    key={i}
                                    className="border-b border-line/60 last:border-0 hover:bg-surface-2/40"
                                >
                                    {kolom.map((k) => (
                                        <td key={k.kunci} className="px-3 py-2 text-xs text-ink-2">
                                            <Sel kolom={k.kunci} nilai={b[k.kunci]} />
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap items-center gap-3 border-t border-line px-3 py-2.5 text-xs text-ink-3">
                    <span>
                        {tabel.dari}&ndash;{tabel.sampai} dari {formatAngka(tabel.total)}
                    </span>

                    <span className="ml-auto flex items-center gap-2">
                        {/* Chip kaki plate 22. Ia TIDAK muncul saat barisnya
                            sedikit: kalimat "berkas besar: disiapkan di latar
                            belakang" pada 12 baris membuat Admin mengira proses
                            yang sama beratnya, dan menunggu tanpa alasan. */}
                        {total > 50 && <Chip nada="mute">disiapkan di latar belakang</Chip>}
                    </span>
                </div>
            </div>

            {/* ------------------------------------------ Ekspor terakhir */}
            {ekspor.length > 0 && (
                <section className="mt-3 rounded-wf border border-line bg-surface">
                    <header className="border-b border-line px-4 py-2.5">
                        <h2 className="font-display text-sm font-semibold text-ink">
                            Ekspor terakhir
                        </h2>
                    </header>

                    <div className="divide-y divide-line/60">
                        {ekspor.map((e) => (
                            <div
                                key={e.id}
                                className="flex flex-wrap items-center gap-3 px-4 py-2.5 text-xs"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-ink">
                                        {e.jenis}{' '}
                                        <span className="ml-1 font-mono text-[11px] text-ink-3">
                                            .{e.format.toLowerCase()}
                                        </span>
                                    </p>
                                    <p className="mt-0.5 truncate text-[11px] text-ink-3">
                                        {[
                                            e.peminta && `oleh ${e.peminta}`,
                                            e.diminta_pada,
                                            e.total_baris !== null && `${formatAngka(e.total_baris)} baris`,
                                            e.ukuran !== null && formatUkuran(e.ukuran),
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                    {e.pesan_gagal && (
                                        <p className="mt-1 line-clamp-2 text-[11px] text-bad">
                                            {e.pesan_gagal}
                                        </p>
                                    )}
                                </div>

                                <Chip nada={nadaChip(e.status_nada)}>{e.status_label}</Chip>

                                {e.status === 'selesai' ? (
                                    <Link
                                        href={`/admin/laporan/berkas/${e.id}`}
                                        className="rounded-wf border border-line px-2.5 py-1 text-[11px] font-medium text-accent hover:bg-surface-2"
                                    >
                                        Unduh
                                    </Link>
                                ) : (
                                    // Tombol tiada saat belum selesai. Tautan
                                    // unduh yang berakhir 404 pada ekspor yang
                                    // masih menunggu adalah tautan yang membuat
                                    // Admin menyimpulkan berkasnya hilang.
                                    <span className="w-[3.5rem]" />
                                )}
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </AdminLayout>
    );
}

/* ------------------------------------------------------------------ Sel */

/**
 * Satu sel tabel laporan.
 *
 * Kolomnya dinamis - jenis laporan menentukan kuncinya - sehingga sel ini
 * harus bisa merender lima bentuk tanpa tahu artinya. Yang dikenali: angka
 * (dijustifikasi kanan, pemisah ribuan) dan null (tanda hubung). Selain itu
 * dicetak apa adanya.
 */
function Sel({ kolom, nilai }: { kolom: string; nilai: string | number | null }) {
    if (nilai === null || nilai === undefined || nilai === '') {
        // Tanda hubung, bukan sel kosong: pada tabel berbingkai, sel kosong
        // terbaca sebagai baris yang gagal dirender.
        return <span className="text-ink-3">&mdash;</span>;
    }

    // Kolom status mendapat chip, karena "tuntas" / "remedial" / "belum
    // dinilai" adalah keadaan, bukan teks.
    if (kolom === 'status') {
        const teks = String(nilai);

        return (
            <Chip nada={teks === 'tuntas' ? 'ok' : teks === 'remedial' ? 'warn' : 'mute'}>
                {teks}
            </Chip>
        );
    }

    if (kolom === 'putusan') {
        const teks = String(nilai);

        return (
            <Chip
                nada={
                    teks === 'baik'
                        ? 'ok'
                        : teks === 'periksa kunci' || teks === 'terlalu mudah'
                          ? 'bad'
                          : 'warn'
                }
            >
                {teks}
            </Chip>
        );
    }

    if (typeof nilai === 'number') {
        return <span className="font-mono">{formatAngka(nilai)}</span>;
    }

    return <span>{nilai}</span>;
}

/* -------------------------------------------------------------- Pembantu */

/** Pemisah ribuan titik - kebiasaan Indonesia, tanpa bergantung pada ICU. */
function formatAngka(n: number): string {
    const bulat = Math.round(n);

    return String(bulat).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function nadaChip(nada: string): 'ok' | 'warn' | 'bad' | 'info' | 'mute' {
    switch (nada) {
        case 'ok':
        case 'warn':
        case 'bad':
        case 'info':
            return nada;
        default:
            return 'mute';
    }
}
