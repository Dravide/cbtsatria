import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Modal, Tombol, kelasInput, nadaStatus, pakaiFilter } from '@/Components/ui';

/**
 * Bank soal versi Admin (Laman 2: Daftar Bank Soal per Mapel + Modal Detail).
 */

type Baris = {
    id: number;
    kode: string | null;
    judul: string | null;
    cuplikan: string;
    jenis: string;
    jenis_label: string;
    jenis_pendek: string;
    tingkat: string | null;
    penulis: string | null;
    dipakai: number;
    status: string;
    status_label: string;
};

type Media = {
    id: number;
    tipe: string;
    mime: string | null;
    nama: string | null;
    ukuran: number;
    url: string;
    gambar: boolean;
};

type Opsi = {
    option_key: string;
    content_json: Record<string, unknown> | null;
    is_correct: boolean;
    position: number;
    media: Media[];
};

type Pratinjau = {
    id: number;
    kode: string | null;
    judul: string | null;
    jenis: string;
    jenis_label: string;
    tingkat: string | null;
    status: string;
    status_label: string;
    mapel: string | null;
    penulis: string | null;
    prompt: { teks?: string } | null;
    kunci: Record<string, unknown> | null;
    skor: Record<string, unknown> | null;
    pembahasan: { teks?: string } | null;
    pemakaian: number;
    opsi: Opsi[];
    butir: {
        item_key: string | null;
        item_group: string | null;
        content_json: Record<string, unknown> | null;
        position: number;
    }[];
    rubrik: { criterion: string; description: string | null; max_score: number }[];
    media: Media[];
};

type Props = {
    mapel: { id: number; kode: string | null; nama: string };
    baris: {
        data: Baris[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    terpilih: Pratinjau | null;
    filter: {
        cari: string;
        jenis: string;
        tingkat: string;
        status: string;
        soal: number | null;
    };
    jumlah: number;
    jenis: { nilai: string; label: string; pendek: string }[];
    status: { nilai: string; label: string }[];
    pengguna: string;
};

export default function BankSoalMapel({
    mapel,
    baris,
    terpilih,
    filter,
    jumlah,
    jenis,
    status,
}: Props) {
    const jalur = `/admin/bank-soal/mapel/${mapel.id}`;

    const f = pakaiFilter(jalur, {
        cari: filter.cari,
        jenis: filter.jenis,
        tingkat: filter.tingkat,
        status: filter.status,
    });

    const [dibuka, setDibuka] = useState<number | null>(filter.soal);

    useEffect(() => {
        setDibuka(filter.soal);
    }, [filter.soal]);

    const buka = (id: number | null) => {
        setDibuka(id);

        router.get(
            jalur,
            {
                ...(filter.cari ? { cari: filter.cari } : {}),
                ...(filter.jenis ? { jenis: filter.jenis } : {}),
                ...(filter.tingkat ? { tingkat: filter.tingkat } : {}),
                ...(filter.status ? { status: filter.status } : {}),
                ...(id === null ? {} : { soal: id }),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const indeksAktif =
        terpilih === null ? -1 : baris.data.findIndex((b) => b.id === terpilih.id);
    const soalSebelumnya = indeksAktif > 0 ? baris.data[indeksAktif - 1] : null;
    const soalBerikutnya =
        indeksAktif >= 0 && indeksAktif < baris.data.length - 1
            ? baris.data[indeksAktif + 1]
            : null;

    return (
        <AdminLayout
            judul={`Bank soal · ${mapel.nama}`}
            breadcrumb={`/admin · Bank soal · ${mapel.nama}`}
            aksi={
                <div className="flex items-center gap-2">
                    <span className="text-[11px] text-ink-3">
                        {jumlah.toLocaleString('id-ID')} soal
                    </span>
                    <Link
                        href="/admin/bank-soal"
                        className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
                    >
                        ← Semua mapel
                    </Link>
                </div>
            }
        >
            <Head title={`Bank soal · ${mapel.nama}`} />

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input
                    value={f.nilai.cari ?? ''}
                    onChange={(e) => f.ubah('cari', e.target.value)}
                    placeholder="Cari judul atau kode soal..."
                    className={kelasInput + ' max-w-xs'}
                />

                <select
                    value={f.nilai.jenis ?? ''}
                    onChange={(e) => f.ubah('jenis', e.target.value)}
                    className={kelasInput + ' w-auto'}
                >
                    <option value="">Semua jenis</option>
                    {jenis.map((j) => (
                        <option key={j.nilai} value={j.nilai}>
                            {j.label}
                        </option>
                    ))}
                </select>

                <select
                    value={f.nilai.status ?? ''}
                    onChange={(e) => f.ubah('status', e.target.value)}
                    className={kelasInput + ' w-auto'}
                >
                    <option value="">Semua status</option>
                    {status.map((s) => (
                        <option key={s.nilai} value={s.nilai}>
                            {s.label}
                        </option>
                    ))}
                </select>

                {mapel.kode && (
                    <span className="ml-auto font-mono text-[11px] text-ink-3">
                        Kode mapel: {mapel.kode}
                    </span>
                )}
            </div>

            <section className="overflow-hidden rounded-wf border border-line bg-surface">
                {baris.data.length === 0 ? (
                    <div className="px-4 py-8 text-center">
                        <p className="text-sm leading-relaxed text-ink-2">
                            Belum ada soal pada mata pelajaran ini atau tidak ada yang cocok dengan
                            penyaring.
                        </p>
                    </div>
                ) : (
                    <table className="w-full text-left text-xs">
                        <thead className="bg-surface-2/60 text-[10px] uppercase tracking-wide text-ink-3">
                            <tr>
                                <th className="w-24 px-3 py-2 font-medium">Kode</th>
                                <th className="px-3 py-2 font-medium">Soal</th>
                                <th className="w-20 px-3 py-2 font-medium">Jenis</th>
                                <th className="hidden w-20 px-3 py-2 font-medium sm:table-cell">
                                    Tingkat
                                </th>
                                <th className="hidden w-36 px-3 py-2 font-medium md:table-cell">
                                    Penulis
                                </th>
                                <th className="hidden w-16 px-3 py-2 text-right font-medium sm:table-cell">
                                    Dipakai
                                </th>
                                <th className="w-20 px-3 py-2 font-medium">Status</th>
                                <th className="w-20 px-3 py-2 text-right font-medium">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            {baris.data.map((b) => (
                                <tr
                                    key={b.id}
                                    onClick={() => buka(b.id)}
                                    className={
                                        'cursor-pointer border-t border-line/60 ' +
                                        (dibuka === b.id
                                            ? 'bg-accent-soft/40'
                                            : 'hover:bg-surface-2/50')
                                    }
                                >
                                    <td className="px-3 py-2 font-mono text-ink-2">
                                        {b.kode ?? '—'}
                                    </td>
                                    <td className="max-w-0 px-3 py-2">
                                        <span className="block truncate font-medium text-ink">
                                            {b.judul ?? 'Tanpa judul'}
                                        </span>
                                        <span className="block truncate text-[11px] text-ink-3">
                                            {b.cuplikan || 'Teks soal kosong'}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2">
                                        <Chip nada={b.jenis === 'uraian' ? 'warn' : 'mute'}>
                                            {b.jenis_pendek}
                                        </Chip>
                                    </td>
                                    <td className="hidden px-3 py-2 text-ink-2 sm:table-cell">
                                        {b.tingkat ?? '—'}
                                    </td>
                                    <td className="hidden truncate px-3 py-2 text-ink-2 md:table-cell">
                                        {b.penulis ?? '—'}
                                    </td>
                                    <td className="hidden px-3 py-2 text-right font-mono text-ink-2 sm:table-cell">
                                        {b.dipakai > 0 ? b.dipakai : '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <Chip nada={nadaStatus(b.status)}>{b.status_label}</Chip>
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <button
                                            type="button"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                buka(b.id);
                                            }}
                                            className="text-xs font-medium text-accent hover:underline"
                                        >
                                            Lihat
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                {baris.links.length > 3 && (
                    <div className="flex flex-wrap items-center gap-1 border-t border-line px-3 py-2 text-[11px]">
                        {baris.links
                            .filter((l) => l.url !== null)
                            .map((l, i) => (
                                <Link
                                    key={i}
                                    href={l.url as string}
                                    preserveScroll
                                    className={
                                        'rounded-wf border px-2 py-1 ' +
                                        (l.active
                                            ? 'border-accent bg-accent-soft text-accent'
                                            : 'border-line text-ink-2 hover:bg-surface-2')
                                    }
                                    dangerouslySetInnerHTML={{ __html: l.label }}
                                />
                            ))}
                    </div>
                )}
            </section>

            <Modal
                judul={
                    terpilih
                        ? `${terpilih.kode ? terpilih.kode + ' · ' : ''}${terpilih.judul ?? 'Detail bank soal'}`
                        : 'Detail bank soal'
                }
                terbuka={terpilih !== null}
                padaTutup={() => buka(null)}
            >
                {terpilih !== null && (
                    <IsiModalSoal
                        pratinjau={terpilih}
                        adaSebelumnya={soalSebelumnya !== null}
                        adaBerikutnya={soalBerikutnya !== null}
                        padaSebelumnya={() => soalSebelumnya && buka(soalSebelumnya.id)}
                        padaBerikutnya={() => soalBerikutnya && buka(soalBerikutnya.id)}
                        padaTutup={() => buka(null)}
                    />
                )}
            </Modal>
        </AdminLayout>
    );
}

function IsiModalSoal({
    pratinjau: s,
    adaSebelumnya,
    adaBerikutnya,
    padaSebelumnya,
    padaBerikutnya,
    padaTutup,
}: {
    pratinjau: Pratinjau;
    adaSebelumnya: boolean;
    adaBerikutnya: boolean;
    padaSebelumnya: () => void;
    padaBerikutnya: () => void;
    padaTutup: () => void;
}) {
    const teks = stripTag(s.prompt?.teks ?? '');
    const pembahasan = stripTag(s.pembahasan?.teks ?? '');

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-1.5">
                <Chip nada={s.jenis === 'uraian' ? 'warn' : 'mute'}>{s.jenis_label}</Chip>
                <Chip nada={nadaStatus(s.status)}>{s.status_label}</Chip>
                {s.tingkat && <Chip nada="mute">{s.tingkat}</Chip>}
            </div>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 rounded-wf border border-line bg-surface-2/40 px-3 py-2.5 text-xs sm:grid-cols-4">
                <div>
                    <dt className="text-[10px] uppercase tracking-wide text-ink-3">Mapel</dt>
                    <dd className="mt-0.5 font-medium text-ink">{s.mapel ?? '—'}</dd>
                </div>
                <div>
                    <dt className="text-[10px] uppercase tracking-wide text-ink-3">Penulis</dt>
                    <dd className="mt-0.5 font-medium text-ink">{s.penulis ?? 'Tanpa penulis'}</dd>
                </div>
                <div>
                    <dt className="text-[10px] uppercase tracking-wide text-ink-3">Tingkat</dt>
                    <dd className="mt-0.5 font-medium text-ink">{s.tingkat ?? '—'}</dd>
                </div>
                <div>
                    <dt className="text-[10px] uppercase tracking-wide text-ink-3">Dipakai</dt>
                    <dd className="mt-0.5 font-medium text-ink">
                        {s.pemakaian > 0 ? `${s.pemakaian} paket` : 'belum dipakai'}
                    </dd>
                </div>
            </dl>

            <Bagian judul="Teks soal">
                {teks ? (
                    <p className="whitespace-pre-wrap text-xs leading-relaxed text-ink">{teks}</p>
                ) : (
                    <p className="text-xs text-ink-3">Teks soal kosong.</p>
                )}
            </Bagian>

            {s.opsi.length > 0 && (
                <Bagian judul="Pilihan jawaban">
                    <ul className="space-y-1.5">
                        {[...s.opsi]
                            .sort((a, b) => a.position - b.position)
                            .map((o) => (
                                <li
                                    key={o.option_key}
                                    className={
                                        'flex items-start gap-2 rounded-wf border px-2.5 py-1.5 ' +
                                        (o.is_correct
                                            ? 'border-ok/40 bg-ok-bg'
                                            : 'border-line/60 bg-surface')
                                    }
                                >
                                    <span className="font-mono text-xs font-medium text-ink-3">
                                        {o.option_key.toUpperCase()}
                                    </span>
                                    <span className="min-w-0 flex-1 text-xs text-ink">
                                        {stripTag(String(o.content_json?.teks ?? '')) || '—'}
                                    </span>
                                    {o.is_correct && <Chip nada="ok">kunci</Chip>}
                                </li>
                            ))}
                    </ul>
                </Bagian>
            )}

            {s.butir.length > 0 && (
                <Bagian judul="Butir">
                    <ul className="space-y-1">
                        {[...s.butir]
                            .sort((a, b) => a.position - b.position)
                            .map((b, i) => (
                                <li key={i} className="text-xs text-ink">
                                    <span className="mr-1.5 font-mono text-[10px] text-ink-3">
                                        {b.item_key ?? b.position}
                                    </span>
                                    {stripTag(String(b.content_json?.teks ?? '')) || '—'}
                                </li>
                            ))}
                    </ul>
                </Bagian>
            )}

            <Bagian judul="Kunci">
                <Kunci kunci={s.kunci} />
            </Bagian>

            {s.rubrik.length > 0 && (
                <Bagian judul="Rubrik penilaian">
                    <ul className="space-y-1">
                        {s.rubrik.map((r, i) => (
                            <li key={i} className="flex items-baseline gap-2 text-xs">
                                <span className="min-w-0 flex-1 text-ink">{r.criterion}</span>
                                <span className="shrink-0 font-mono text-ink-3">
                                    maks {r.max_score}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Bagian>
            )}

            {s.media.length > 0 && (
                <Bagian judul="Media">
                    <div className="space-y-1">
                        {s.media.map((m) => (
                            <div key={m.id} className="text-xs text-ink-2">
                                <span className="font-mono uppercase text-ink-3">{m.tipe}</span>{' '}
                                <a
                                    href={m.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-accent hover:underline"
                                >
                                    {m.nama ?? 'berkas'}
                                </a>
                            </div>
                        ))}
                    </div>
                </Bagian>
            )}

            {pembahasan && (
                <Bagian judul="Pembahasan">
                    <p className="whitespace-pre-wrap text-xs leading-relaxed text-ink-2">
                        {pembahasan}
                    </p>
                </Bagian>
            )}

            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3">
                <div className="flex items-center gap-2">
                    <Tombol disabled={!adaSebelumnya} onClick={padaSebelumnya}>
                        ← Sebelumnya
                    </Tombol>
                    <Tombol disabled={!adaBerikutnya} onClick={padaBerikutnya}>
                        Berikutnya →
                    </Tombol>
                </div>
                <Tombol nada="pri" onClick={padaTutup}>
                    Tutup
                </Tombol>
            </div>
        </div>
    );
}

function Bagian({ judul, children }: { judul: string; children: React.ReactNode }) {
    return (
        <section className="border-t border-line/60 pt-2.5">
            <h3 className="mb-1.5 text-[10px] font-medium uppercase tracking-wide text-ink-3">
                {judul}
            </h3>
            {children}
        </section>
    );
}

function Kunci({ kunci }: { kunci: Record<string, unknown> | null }) {
    if (kunci === null || Object.keys(kunci).length === 0) {
        return <p className="text-xs text-bad">Kunci belum diisi.</p>;
    }

    const rumpang = kunci.rumpang;

    if (Array.isArray(rumpang)) {
        return (
            <ul className="space-y-1">
                {rumpang.map((varian, i) => (
                    <li key={i} className="flex items-baseline gap-2 text-xs">
                        <span className="w-5 shrink-0 text-center font-mono text-[10px] text-accent">
                            {i + 1}
                        </span>
                        <span className="min-w-0 flex-1 text-ink">
                            {Array.isArray(varian) ? varian.join(' / ') : String(varian)}
                        </span>
                    </li>
                ))}
            </ul>
        );
    }

    const nilai = Object.values(kunci)
        .map((v) => (Array.isArray(v) ? v.join(', ') : String(v)))
        .filter((v) => v !== '');

    return (
        <p className="break-words font-mono text-xs leading-relaxed text-ink">
            {nilai.length > 0 ? nilai.join(' · ') : '—'}
        </p>
    );
}

function stripTag(html: string): string {
    return html
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/(p|div|li)>/gi, '\n')
        .replace(/<[^>]+>/g, '')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}
