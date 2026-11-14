import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, kelasInput, nadaStatus, pakaiFilter } from '@/Components/ui';

/**
 * Bank soal versi Admin - BACA SAJA. `/admin/bank-soal`, 2026-11-14.
 *
 * =====================================================================
 * Mengapa laman ini ada, padahal `/guru/bank-soal` sudah ada
 * =====================================================================
 * Admin yang memasang paket ke event dan menandai event Siap bertanggung
 * jawab atas isi paket yang ia setujui - tetapi satu-satunya pintu ke bank
 * soal adalah konsol Guru: sidebar Guru, tombol Builder, Impor, dan Generate
 * AI. Ketiganya server TOLAK untuk Admin (PRD §3.1: "lihat semua", bukan
 * "tulis soal"), sehingga Admin mendarat di konsol orang lain dan melihat
 * menu yang mengajarinya untuk tidak memercayai layarnya sendiri.
 *
 * Laman ini pintu itu tanpa konsol Guru: daftar soal sekolah, penyaring, dan
 * panel pratinjau. TIDAK ada builder, impor, AI, atau tombol ubah.
 *
 * =====================================================================
 * Daftar + panel, bukan dua laman
 * =====================================================================
 * `PratinjauController` Guru memakai rute terpisah per soal. Di sini panelnya
 * menyatu dengan daftarnya, dan itu bukan penghematan: yang dikerjakan Admin
 * adalah MEMBANDINGKAN soal - "yang mana yang kuncinya hilang", "yang mana
 * yang bobotnya aneh" - dan berpindah laman untuk setiap perbandingan berarti
 * kehilangan tempat di daftar setiap kali.
 *
 * Panelnya karena itu juga MENDORONG, bukan menutupi: `<aside>` yang menempel
 * dan daftarnya menyempit, sehingga baris yang sedang dibandingkan tetap
 * terlihat di sebelahnya.
 *
 * =====================================================================
 * Yang tidak dikirim server: `bolehUbah`
 * =====================================================================
 * Laman ini tidak punya satu pun tombol tulis, jadi ia tidak meminta izin
 * tulis. Laman yang menyimpan izin yang tidak dipakainya adalah laman yang
 * mengundang tombol itu ditambahkan nanti tanpa memikirkan policy-nya.
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
    mapel: string | null;
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
    baris: { data: Baris[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    terpilih: Pratinjau | null;
    filter: {
        cari: string;
        mapel: string;
        jenis: string;
        tingkat: string;
        status: string;
        soal: number | null;
    };
    jumlah: number;
    mapel: { id: number; nama: string }[];
    jenis: { nilai: string; label: string; pendek: string }[];
    status: { nilai: string; label: string }[];
    pengguna: string;
};

export default function BankSoalAdmin({
    baris,
    terpilih,
    filter,
    jumlah,
    mapel,
    jenis,
    status,
    pengguna,
}: Props) {
    const f = pakaiFilter('/admin/bank-soal', {
        cari: filter.cari,
        mapel: filter.mapel,
        jenis: filter.jenis,
        tingkat: filter.tingkat,
        status: filter.status,
    });

    // Baris yang sedang dibuka ditandai, dan id-nya ada DI ALAMAT - sehingga
    // tautan ke satu soal bisa ditempel ke pesan. Panel yang hanya hidup di
    // state React tidak bisa dikirim ke siapa pun.
    const [dibuka, setDibuka] = useState<number | null>(filter.soal);

    useEffect(() => {
        setDibuka(filter.soal);
    }, [filter.soal]);

    const buka = (id: number) => {
        const baru = dibuka === id ? null : id;

        setDibuka(baru);

        router.get(
            '/admin/bank-soal',
            {
                ...(filter.cari ? { cari: filter.cari } : {}),
                ...(filter.mapel ? { mapel: filter.mapel } : {}),
                ...(filter.jenis ? { jenis: filter.jenis } : {}),
                ...(filter.tingkat ? { tingkat: filter.tingkat } : {}),
                ...(filter.status ? { status: filter.status } : {}),
                ...(baru === null ? {} : { soal: baru }),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AdminLayout
            judul="Bank soal"
            breadcrumb={`/admin · Bank soal · ${pengguna}`}
            aksi={
                <span className="text-[11px] text-ink-3">
                    {jumlah.toLocaleString('id-ID')} soal
                </span>
            }
        >
            <Head title="Bank soal" />

            {/*
             * Kalimat batas peran, dan ia ditulis karena Admin yang datang
             * dari laman Guru akan MENCARI tombol Tambah. Kalimat yang
             * menyebutkan bahwa menulis soal bukan tugasnya lebih berguna
             * daripada laman yang diam saja tentang tombol yang tidak ada.
             */}
            <p className="mb-3 rounded-wf border border-info/25 bg-info-bg px-3 py-2.5 text-xs leading-relaxed text-info">
                Bank soal adalah aset sekolah. <strong>Melihat</strong> seluruh soal di sini, dan
                yang menyusun serta mengubahnya adalah Guru mata pelajaran lewat konsolnya.
            </p>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input
                    value={f.nilai.cari ?? ''}
                    onChange={(e) => f.ubah('cari', e.target.value)}
                    placeholder="Cari judul atau kode soal..."
                    className={kelasInput + ' max-w-xs'}
                />

                <select
                    value={f.nilai.mapel ?? ''}
                    onChange={(e) => f.ubah('mapel', e.target.value)}
                    className={kelasInput + ' w-auto'}
                >
                    <option value="">Semua mapel</option>
                    {mapel.map((m) => (
                        <option key={m.id} value={m.id}>
                            {m.nama}
                        </option>
                    ))}
                </select>

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
            </div>

            <div className={'grid gap-3 ' + (terpilih !== null ? 'lg:grid-cols-[1.4fr_1fr]' : '')}>
                <section className="overflow-hidden rounded-wf border border-line bg-surface">
                    {baris.data.length === 0 ? (
                        <div className="px-3 py-6">
                            <p className="text-sm leading-relaxed text-ink-2">
                                Tidak ada soal yang cocok dengan penyaring ini.
                            </p>
                        </div>
                    ) : (
                        <table className="w-full text-left text-xs">
                            <thead className="bg-surface-2/60 text-[10px] uppercase tracking-wide text-ink-3">
                                <tr>
                                    <th className="w-20 px-2 py-1.5 font-medium">Kode</th>
                                    <th className="px-2 py-1.5 font-medium">Soal</th>
                                    <th className="w-16 px-2 py-1.5 font-medium">Jenis</th>
                                    <th className="w-14 px-2 py-1.5 font-medium">Tingkat</th>
                                    <th className="w-28 px-2 py-1.5 font-medium">Mapel</th>
                                    <th className="w-14 px-2 py-1.5 text-right font-medium">Dipakai</th>
                                    <th className="w-16 px-2 py-1.5 font-medium">Status</th>
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
                                        <td className="px-2 py-1.5 font-mono text-ink-2">
                                            {b.kode ?? '—'}
                                        </td>
                                        <td className="max-w-0 px-2 py-1.5">
                                            {/* Dua tingkat: judul, lalu cuplikan
                                                teksnya. Admin yang memeriksa
                                                kunci perlu melihat SOALNYA
                                                sekilas untuk tahu yang mana -
                                                dan judul soal sering hanya
                                                "Konversi Biner (1)". */}
                                            <span className="block truncate font-medium text-ink">
                                                {b.judul ?? 'Tanpa judul'}
                                            </span>
                                            <span className="block truncate text-[11px] text-ink-3">
                                                {b.cuplikan || 'Teks soal kosong'}
                                            </span>
                                        </td>
                                        <td className="px-2 py-1.5">
                                            <Chip nada={b.jenis === 'uraian' ? 'warn' : 'mute'}>
                                                {b.jenis_pendek}
                                            </Chip>
                                        </td>
                                        <td className="px-2 py-1.5 text-ink-2">
                                            {b.tingkat ?? '—'}
                                        </td>
                                        <td className="truncate px-2 py-1.5 text-ink-2">
                                            {b.mapel ?? '—'}
                                        </td>
                                        <td className="px-2 py-1.5 text-right font-mono text-ink-2">
                                            {b.dipakai > 0 ? b.dipakai : '—'}
                                        </td>
                                        <td className="px-2 py-1.5">
                                            <Chip nada={nadaStatus(b.status)}>
                                                {b.status_label}
                                            </Chip>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    {baris.links.length > 3 && (
                        <div className="flex flex-wrap items-center gap-1 border-t border-line px-2 py-2 text-[11px]">
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

                {terpilih !== null && (
                    <aside className="lg:sticky lg:top-3 lg:self-start">
                        <PanelSoal pratinjau={terpilih} padaTutup={() => buka(terpilih.id)} />
                    </aside>
                )}
            </div>
        </AdminLayout>
    );
}

/**
 * Panel pratinjau satu soal.
 *
 * Urutannya mengikuti pertanyaan yang dibawa Admin: apa soalnya, apa
 * kuncinya, bagaimana dinilainya, lalu dari mana asal-usulnya. Pembahasan
 * duduk paling bawah karena ia satu-satunya yang tidak menentukan apakah
 * paketnya layak diujikan.
 */
function PanelSoal({ pratinjau: s, padaTutup }: { pratinjau: Pratinjau; padaTutup: () => void }) {
    const teks = stripTag(s.prompt?.teks ?? '');
    const pembahasan = stripTag(s.pembahasan?.teks ?? '');

    return (
        <div className="overflow-hidden rounded-wf border border-line bg-surface">
            <header className="flex flex-wrap items-center gap-2 border-b border-line px-3 py-2">
                <span className="font-mono text-[11px] text-ink-3">{s.kode ?? '—'}</span>
                <b className="text-[13px] font-medium text-ink">{s.judul ?? 'Tanpa judul'}</b>
                <button
                    type="button"
                    onClick={padaTutup}
                    className="ml-auto text-[11px] text-ink-3 hover:text-ink"
                    aria-label="Tutup panel"
                >
                    tutup
                </button>
            </header>

            <div className="space-y-3 px-3 py-3">
                <div className="flex flex-wrap items-center gap-1.5">
                    <Chip nada={s.jenis === 'uraian' ? 'warn' : 'mute'}>{s.jenis_label}</Chip>
                    <Chip nada={nadaStatus(s.status)}>{s.status_label}</Chip>
                    {s.tingkat && <Chip nada="mute">{s.tingkat}</Chip>}
                </div>

                <dl className="grid grid-cols-[80px_minmax(0,1fr)] gap-x-3 gap-y-1 text-[11px]">
                    <dt className="text-ink-3">Mapel</dt>
                    <dd className="text-ink">{s.mapel ?? '—'}</dd>
                    <dt className="text-ink-3">Penulis</dt>
                    <dd className="text-ink">{s.penulis ?? 'Tanpa penulis'}</dd>
                    <dt className="text-ink-3">Dipakai</dt>
                    <dd className="text-ink">
                        {s.pemakaian > 0 ? `${s.pemakaian} paket` : 'belum dipakai'}
                    </dd>
                </dl>

                <Bagian judul="Teks soal">
                    {teks ? (
                        <p className="whitespace-pre-wrap text-[12px] leading-relaxed text-ink">
                            {teks}
                        </p>
                    ) : (
                        <p className="text-[11px] text-ink-3">Teks soal kosong.</p>
                    )}
                </Bagian>

                {s.opsi.length > 0 && (
                    <Bagian judul="Pilihan jawaban">
                        <ul className="space-y-1">
                            {s.opsi
                                .sort((a, b) => a.position - b.position)
                                .map((o) => (
                                    <li
                                        key={o.option_key}
                                        className={
                                            'flex items-start gap-2 rounded-wf border px-2 py-1 ' +
                                            (o.is_correct
                                                ? 'border-ok/40 bg-ok-bg'
                                                : 'border-line/60')
                                        }
                                    >
                                        <span className="font-mono text-[11px] font-medium text-ink-3">
                                            {o.option_key.toUpperCase()}
                                        </span>
                                        <span className="min-w-0 flex-1 text-[12px] text-ink">
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
                            {s.butir
                                .sort((a, b) => a.position - b.position)
                                .map((b, i) => (
                                    <li key={i} className="text-[12px] text-ink">
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
                                <li key={i} className="flex items-baseline gap-2 text-[12px]">
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
                                <div key={m.id} className="text-[11px] text-ink-2">
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
                        <p className="whitespace-pre-wrap text-[12px] leading-relaxed text-ink-2">
                            {pembahasan}
                        </p>
                    </Bagian>
                )}
            </div>
        </div>
    );
}

/** Satu bagian berjudul di panel. */
function Bagian({ judul, children }: { judul: string; children: React.ReactNode }) {
    return (
        <section className="border-t border-line/60 pt-2.5">
            <h3 className="mb-1 text-[10px] font-medium uppercase tracking-wide text-ink-3">
                {judul}
            </h3>
            {children}
        </section>
    );
}

/**
 * Kunci jawaban, dengan bentuk yang bergantung jenisnya.
 *
 * Kunci hidup di JSON dengan bentuk yang berbeda per jenis - `rumpang` adalah
 * daftar varian, `urut` adalah urutan, pilihan tunggal adalah satu huruf.
 * Menampilkan `JSON.stringify` mentahnya akan benar dan tidak berguna; yang
 * dibutuhkan Admin adalah membacanya dalam satu pandangan.
 */
function Kunci({ kunci }: { kunci: Record<string, unknown> | null }) {
    if (kunci === null || Object.keys(kunci).length === 0) {
        return <p className="text-[11px] text-bad">Kunci belum diisi.</p>;
    }

    const rumpang = kunci.rumpang;

    if (Array.isArray(rumpang)) {
        return (
            <ul className="space-y-1">
                {rumpang.map((varian, i) => (
                    <li key={i} className="flex items-baseline gap-2 text-[12px]">
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
        <p className="font-mono text-[12px] leading-relaxed text-ink break-words">
            {nilai.length > 0 ? nilai.join(' · ') : '—'}
        </p>
    );
}

/** Teks soal disimpan sebagai HTML ringan; panel ini menampilkannya telanjang. */
function stripTag(html: string): string {
    return html
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/(p|div|li)>/gi, '\n')
        .replace(/<[^>]+>/g, '')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}
