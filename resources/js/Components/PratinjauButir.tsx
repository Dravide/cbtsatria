import { ReactNode } from 'react';
import { Chip } from '@/Components/ui';

/**
 * Pratinjau satu butir soal - dipakai laman pratinjau soal dan paket.
 *
 * =====================================================================
 * Mengapa satu komponen untuk dua laman
 * =====================================================================
 * Pratinjau soal menampilkan satu butir; pratinjau paket menampilkan
 * seluruhnya sebagai satu daftar panjang. Yang berbeda hanya jumlahnya, dan
 * menyalin perendernya berarti dua tempat yang bisa berbeda diam-diam pada
 * hal yang justru paling menentukan di sini: bagaimana soal terlihat.
 *
 * Dua pratinjau yang berbeda penampilan membuat Guru yang memeriksa satu per
 * satu melihat bentuk yang BUKAN bentuk yang akan ia lihat saat memeriksa
 * paketnya - dan itulah kesalahan yang paling mahal di laman ini.
 *
 * =====================================================================
 * HTML dari server dirender apa adanya, dan itu aman karena SATU alasan
 * =====================================================================
 * `prompt_json.teks` dan isi opsi memuat HTML - `<b>`, `<sup>`, `<table>` -
 * supaya soal matematika bisa ditulis. Ia disimpan lewat `PembersihHtml`
 * (whitelist tag, tanpa `script`/`iframe`/`on*`), dan karena itu dirender
 * dengan `dangerouslySetInnerHTML`.
 *
 * Yang membuat itu bukan lubang: pembersihannya dilakukan di SERVER saat
 * MENYIMPAN, bukan di sini saat menampilkan. Peramban ini menerima apa yang
 * sudah tersaring, dan HTML yang sama juga akan sampai ke peramban 300 siswa
 * lewat mesin ujian Fase 7. Menampilkannya di sini sebagai teks biasa berarti
 * Guru tidak pernah melihat soal yang sebenarnya dikirim ke siswa.
 *
 * Yang TIDAK dirender sebagai HTML adalah `judul` dan nama kolom - keduanya
 * ditulis Guru di form biasa, bukan di editor berformat.
 */

export type MediaButir = {
    id: number;
    tipe: string;
    mime: string | null;
    nama: string | null;
    ukuran: number;
    metadata: Record<string, unknown> | null;
    url: string;
    gambar: boolean;
};

export type ButirPratinjau = {
    id: number;
    kode: string | null;
    judul: string;
    jenis: string;
    jenis_label: string;
    jenis_pendek: string;
    tingkat: string | null;
    status: string;
    mapel: string | null;
    penulis: string | null;
    prompt: Record<string, unknown> | null;
    kunci: Record<string, unknown> | null;
    skor: Record<string, unknown> | null;
    pembahasan: Record<string, unknown> | null;
    opsi: { option_key: string; content_json: Record<string, unknown>; is_correct: boolean; position: number }[];
    butir: { item_key: string; item_group: string; content_json: Record<string, unknown>; position: number }[];
    rubrik: { criterion: string; description: string | null; max_score: number; position: number }[];
    media: MediaButir[];
    /** Hanya ada di pratinjau paket. */
    nomor?: number;
    poin?: number;
    poin_override?: number | null;
};

/** Isi satu bidang teks berformat, atau rangka kosong bila tidak ada. */
export function Html({ nilai, kosong = '—' }: { nilai?: unknown; kosong?: string }) {
    const teks = typeof nilai === 'string' ? nilai : '';

    if (teks.trim() === '') {
        return <span className="text-ink-3">{kosong}</span>;
    }

    return (
        <div
            className="text-sm leading-relaxed text-ink [&_b]:font-semibold [&_blockquote]:border-l-2 [&_blockquote]:border-line [&_blockquote]:pl-3 [&_i]:italic [&_ol]:ml-5 [&_ol]:list-decimal [&_p]:my-1.5 [&_sub]:align-sub [&_sub]:text-[0.75em] [&_sup]:align-super [&_sup]:text-[0.75em] [&_table]:my-2 [&_table]:border-collapse [&_td]:border [&_td]:border-line [&_td]:px-2 [&_td]:py-1 [&_th]:border [&_th]:border-line [&_th]:bg-surface-2 [&_th]:px-2 [&_th]:py-1 [&_ul]:ml-5 [&_ul]:list-disc"
            dangerouslySetInnerHTML={{ __html: teks }}
        />
    );
}

function teksDari(nilai: unknown): string {
    return typeof nilai === 'string' ? nilai : '';
}

/* =================================================================== Butir */

export function KartuButir({
    butir,
    aksi,
}: {
    butir: ButirPratinjau;
    aksi?: ReactNode;
}) {
    const kiri = butir.butir.filter((b) => b.item_group === 'kiri');
    const kanan = butir.butir.filter((b) => b.item_group === 'kanan');
    const kunciOpsi = Array.isArray(butir.kunci?.opsi) ? (butir.kunci.opsi as string[]) : [];
    const bobot = typeof butir.skor?.bobot === 'number' ? (butir.skor.bobot as number) : null;

    return (
        <article className="rounded-wf border border-line bg-surface">
            <header className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2.5">
                {/* Nomor butir hanya ada di pratinjau paket. Di pratinjau satu
                    soal, "1" di kepala kartu adalah nomor yang tidak berarti
                    apa pun - soalnya berdiri sendiri, bukan bagian dari
                    urutan. */}
                {butir.nomor !== undefined && (
                    <span className="flex size-6 shrink-0 items-center justify-center rounded-wf bg-surface-2 font-mono text-[11px] text-ink-2">
                        {butir.nomor}
                    </span>
                )}
                <h3 className="min-w-0 truncate text-sm font-medium text-ink">{butir.judul}</h3>
                <span className="flex flex-wrap items-center gap-2">
                    <Chip nada="mute">{butir.jenis_pendek}</Chip>
                    {butir.tingkat && <Chip nada="mute">{butir.tingkat}</Chip>}
                    {bobot !== null && (
                        <span className="font-mono text-[11px] text-ink-3">{bobot} poin</span>
                    )}
                    {butir.poin_override != null && (
                        <span className="font-mono text-[11px] text-info">
                            timpaan {butir.poin} poin
                        </span>
                    )}
                </span>
                <span className="ml-auto flex shrink-0 items-center gap-2">
                    {aksi}
                </span>
            </header>

            <div className="space-y-3 px-4 py-3">
                <Html nilai={butir.prompt?.teks} kosong="Soal ini belum punya teks." />

                {/* Media dirender sebagai GAMBAR, bukan sebagai nama berkas.
                    Yaltu seluruh gunanya pratinjau: soal yang gambarnya tidak
                    termuat terlihat di sini, bukan di layar siswa. */}
                {butir.media.length > 0 && (
                    <div className="flex flex-wrap gap-3">
                        {butir.media.map((m) => (
                            <figure
                                key={m.id}
                                className="min-w-0 rounded-wf border border-line bg-surface-2/40 p-2"
                            >
                                {m.gambar ? (
                                    <img
                                        src={m.url}
                                        alt={m.nama ?? `Media ${m.id}`}
                                        className="max-h-64 max-w-full rounded-wf"
                                    />
                                ) : (
                                    <a
                                        href={m.url}
                                        className="text-xs text-accent hover:underline"
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        {m.nama ?? `Berkas ${m.id}`}
                                    </a>
                                )}
                                <figcaption className="mt-1 text-[11px] text-ink-3">
                                    {m.nama} · {Math.max(1, Math.round(m.ukuran / 1024))} KB
                                </figcaption>
                            </figure>
                        ))}
                    </div>
                )}

                {/* Pilihan jawaban. Yang benar DITANDAI - dan itu satu-satunya
                    perbedaan dengan layar siswa, yang tidak pernah menerima
                    `kunci_json` sama sekali (ExamQuestion::untukSiswa). */}
                {butir.opsi.length > 0 && (
                    <ul className="space-y-1.5">
                        {butir.opsi.map((o) => (
                            <li
                                key={o.option_key}
                                className={
                                    'flex items-start gap-2 rounded-wf border px-2.5 py-1.5 text-sm ' +
                                    (o.is_correct
                                        ? 'border-ok/40 bg-ok-bg'
                                        : 'border-line bg-surface-2/30')
                                }
                            >
                                <span className="mt-px font-mono text-[11px] text-ink-3 uppercase">
                                    {o.option_key}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <Html nilai={o.content_json?.teks} />
                                </span>
                                {o.is_correct && <Chip nada="ok">kunci</Chip>}
                            </li>
                        ))}
                    </ul>
                )}

                {/* Dua daftar berdampingan, dan itu bentuk yang menentukan:
                    soal menjodohkan yang itemnya tercampur satu daftar
                    kehilangan justru hal yang diperiksa Guru - apakah jumlah
                    kiri dan kanannya seimbang. */}
                {butir.butir.length > 0 && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {kiri.length > 0 && (
                            <div>
                                <span className="mb-1 block text-[11px] tracking-wide text-ink-3 uppercase">
                                    Item kiri
                                </span>
                                <ul className="space-y-1">
                                    {kiri.map((b) => (
                                        <li
                                            key={b.item_key}
                                            className="flex items-start gap-2 text-sm"
                                        >
                                            <span className="font-mono text-[11px] text-ink-3">
                                                {b.item_key}
                                            </span>
                                            <Html nilai={b.content_json?.teks} />
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {kanan.length > 0 && (
                            <div>
                                <span className="mb-1 block text-[11px] tracking-wide text-ink-3 uppercase">
                                    Item kanan
                                </span>
                                <ul className="space-y-1">
                                    {kanan.map((b) => (
                                        <li
                                            key={b.item_key}
                                            className="flex items-start gap-2 text-sm"
                                        >
                                            <span className="font-mono text-[11px] text-ink-3">
                                                {b.item_key}
                                            </span>
                                            <Html nilai={b.content_json?.teks} />
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}

                {/* Kunci non-opsi: isian, jodohkan, urut, angka. Bentuknya
                    berbeda-beda per jenis, dan ditampilkan sebagai JSON yang
                    terbaca alih-alih diterjemahkan per jenis - yang membaca
                    ini adalah penyusunnya sendiri, dan ia tahu apa yang ia
                    tetapkan. */}
                {kunciOpsi.length === 0 && butir.kunci && Object.keys(butir.kunci).length > 0 && (
                    <div className="rounded-wf border border-line bg-surface-2/40 px-3 py-2">
                        <span className="mb-1 block text-[11px] tracking-wide text-ink-3 uppercase">
                            Kunci jawaban
                        </span>
                        <pre className="overflow-x-auto font-mono text-[11px] text-ink-2">
                            {JSON.stringify(butir.kunci, null, 2)}
                        </pre>
                    </div>
                )}

                {butir.rubrik.length > 0 && (
                    <div className="rounded-wf border border-line bg-surface-2/40 px-3 py-2">
                        <span className="mb-1 block text-[11px] tracking-wide text-ink-3 uppercase">
                            Rubrik · total {butir.rubrik.reduce((t, r) => t + r.max_score, 0)} poin
                        </span>
                        <ul className="space-y-1">
                            {butir.rubrik.map((r) => (
                                <li key={r.criterion} className="flex items-start gap-2 text-xs">
                                    <span className="font-mono text-ink-3">{r.max_score}</span>
                                    <span className="min-w-0">
                                        <span className="text-ink">{r.criterion}</span>
                                        {r.description && (
                                            <span className="block text-ink-3">{r.description}</span>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {butir.pembahasan && teksDari(butir.pembahasan.teks) !== '' && (
                    <div className="rounded-wf border border-info/25 bg-info-bg px-3 py-2">
                        <span className="mb-1 block text-[11px] tracking-wide text-info uppercase">
                            Pembahasan
                        </span>
                        <Html nilai={butir.pembahasan.teks} />
                    </div>
                )}

                <p className="text-[11px] text-ink-3">
                    <span className="font-mono">{butir.kode ?? '—'}</span>
                    {butir.mapel && <> · {butir.mapel}</>}
                    {butir.penulis && <> · ditulis {butir.penulis}</>}
                </p>
            </div>
        </article>
    );
}
