import { useEffect, useRef } from 'react';
import { Chip, Field, Kotak, Tombol, kelasInput } from '@/Components/ui';

/**
 * Penyunting teks berformat untuk soal, tanpa pustaka editor.
 *
 * =====================================================================
 * Mengapa contenteditable, dan mengapa tanpa pustaka
 * =====================================================================
 * Soal berisi tabel, pangkat, dan daftar - dan Guru harus bisa menyusunnya.
 * Pustaka editor (TinyMCE, CKEditor, TipTap) menyelesaikan itu dalam satu
 * baris impor, dan harganya: ratusan kilobyte, satu sistem tema yang harus
 * dijinakkan agar cocok dengan wireframe, dan satu lapisan yang menyimpan
 * HTML dengan bentuknya SENDIRI - yang tetap harus dibersihkan server-side
 * karena peramban bisa mengirim apa saja.
 *
 * Karena pembersihan itu wajib apa pun editornya, pustaka editor tidak
 * membeli keamanan; ia membeli toolbar. Toolbar itu yang ditulis di sini.
 *
 * =====================================================================
 * Nilainya TIDAK terkendali (uncontrolled), dan itu keputusan sadar
 * =====================================================================
 * `contenteditable` yang nilainya diikat ke state React dan ditulis balik
 * setiap ketikan memindahkan kursor ke awal pada setiap huruf. Itu bukan
 * bug yang bisa diperbaiki dengan `useLayoutEffect` - itu pertarungan antara
 * dua pemilik DOM yang sama.
 *
 * Karena itu: DOM di sini dimiliki PERAMBAN. React hanya membaca isinya saat
 * berubah (`onInput`) dan menulis isinya SEKALI saat dipasang. Kuncinya
 * adalah `key` di pemanggilnya: berpindah tab jenis soal memasang ulang
 * komponen ini, sehingga isi yang baru masuk tanpa ada penulisan di tengah
 * ketikan.
 *
 * Hasilnya tetap berupa string HTML di state Guru, dan string itulah yang
 * dikirim ke server - lalu dibersihkan PembersihHtml sebelum disimpan.
 */
export function EditorSoal({
    nilai,
    padaUbah,
    label = 'Teks soal',
    galat,
    tinggi = 'min-h-32',
    placeholder,
}: {
    nilai: string;
    padaUbah: (html: string) => void;
    label?: string;
    galat?: string;
    tinggi?: string;
    placeholder?: string;
}) {
    const ref = useRef<HTMLDivElement>(null);

    // Tulis SEKALI saat dipasang. Tidak ada dependensi `nilai` di sini, dan
    // itu disengaja - lihat catatan kelas di atas.
    useEffect(() => {
        if (ref.current && ref.current.innerHTML !== nilai) {
            ref.current.innerHTML = nilai;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /** Jalankan perintah format pada seleksi yang sedang aktif. */
    const perintah = (cmd: string, arg?: string) => {
        ref.current?.focus();
        document.execCommand(cmd, false, arg);
        if (ref.current) {
            padaUbah(ref.current.innerHTML);
        }
    };

    const tombol: { label: string; judul: string; cmd: string; arg?: string }[] = [
        { label: 'B', judul: 'Tebal', cmd: 'bold' },
        { label: 'I', judul: 'Miring', cmd: 'italic' },
        { label: 'U', judul: 'Garis bawah', cmd: 'underline' },
        { label: 'x²', judul: 'Pangkat', cmd: 'superscript' },
        { label: 'x₂', judul: 'Indeks bawah', cmd: 'subscript' },
        { label: '• Daftar', judul: 'Daftar butir', cmd: 'insertUnorderedList' },
        { label: '1. Daftar', judul: 'Daftar bernomor', cmd: 'insertOrderedList' },
        { label: 'Kiri', judul: 'Rata kiri', cmd: 'justifyLeft' },
        { label: 'Tengah', judul: 'Rata tengah', cmd: 'justifyCenter' },
        { label: 'Kanan', judul: 'Rata kanan', cmd: 'justifyRight' },
    ];

    return (
        <Field label={label} galat={galat}>
            <div className="overflow-hidden rounded-wf border border-line bg-surface focus-within:border-accent">
                <div className="flex flex-wrap items-center gap-1 border-b border-line bg-surface-2/60 px-2 py-1.5">
                    {tombol.map((t) => (
                        <button
                            key={t.cmd}
                            type="button"
                            title={t.judul}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => perintah(t.cmd)}
                            className="rounded-wf border border-line bg-surface px-2 py-0.5 text-[11px] font-medium text-ink-2 hover:bg-surface-2 hover:text-ink"
                        >
                            {t.label}
                        </button>
                    ))}
                    <button
                        type="button"
                        title="Sisipkan tabel 3×3"
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={() =>
                            perintah(
                                'insertHTML',
                                '<table><tbody>' +
                                    '<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>'.repeat(3) +
                                    '</tbody></table><p><br></p>',
                            )
                        }
                        className="rounded-wf border border-line bg-surface px-2 py-0.5 text-[11px] font-medium text-ink-2 hover:bg-surface-2 hover:text-ink"
                    >
                        Tabel
                    </button>
                </div>

                <div
                    ref={ref}
                    contentEditable
                    suppressContentEditableWarning
                    role="textbox"
                    aria-multiline="true"
                    data-placeholder={placeholder ?? ''}
                    onInput={(e) => padaUbah((e.target as HTMLDivElement).innerHTML)}
                    onBlur={(e) => padaUbah((e.target as HTMLDivElement).innerHTML)}
                    className={
                        'px-3 py-2 text-sm text-ink outline-none ' +
                        tinggi +
                        ' [&_table]:my-2 [&_table]:border-collapse [&_td]:border [&_td]:border-line [&_td]:px-2 [&_td]:py-1 [&_ol]:ml-5 [&_ol]:list-decimal [&_ul]:ml-5 [&_ul]:list-disc'
                    }
                />
            </div>
        </Field>
    );
}

/* =================================================================== Opsi */

export type BarisOpsi = { option_key: string; teks: string };

const HURUF = 'abcdefghijklmnopqrstuvwxyz'.split('');

/**
 * Penyusun pilihan jawaban.
 *
 * `kunci` adalah daftar huruf yang benar, dan komponen ini TIDAK menyimpan
 * `is_correct` sendiri: server menurunkannya dari kunci (lihat
 * BankSoalService::tulisAnak). Menyimpannya di dua tempat berarti dua nilai
 * yang bisa berbeda, dan yang dinilai mesin adalah kunci - sehingga pilihan
 * yang tampak tercentang di layar bisa saja tidak dihitung benar.
 */
export function OpsiBuilder({
    opsi,
    kunci,
    padaUbah,
    padaKunci,
    tunggal,
    galat,
}: {
    opsi: BarisOpsi[];
    kunci: string[];
    padaUbah: (opsi: BarisOpsi[]) => void;
    padaKunci: (kunci: string[]) => void;
    /** Satu benar (pilihan ganda) atau boleh banyak (jawaban ganda). */
    tunggal: boolean;
    galat?: string;
}) {
    const ubahTeks = (i: number, teks: string) =>
        padaUbah(opsi.map((o, j) => (j === i ? { ...o, teks } : o)));

    const tambah = () =>
        padaUbah([...opsi, { option_key: HURUF[opsi.length] ?? 'z', teks: '' }]);

    const buang = (i: number) => {
        const sisa = opsi
            .filter((_, j) => j !== i)
            // Hurufnya dinomori ulang supaya celah tidak menumpuk: menghapus
            // pilihan B dari A-B-C-D menyisakan A-C-D, dan kunci "c" akan
            // menunjuk pilihan yang tadi D.
            .map((o, j) => ({ ...o, option_key: HURUF[j] }));
        padaUbah(sisa);
        padaKunci(kunci.filter((k) => sisa.some((o) => o.option_key === k)));
    };

    const tandai = (huruf: string) => {
        if (tunggal) {
            padaKunci([huruf]);
            return;
        }
        padaKunci(kunci.includes(huruf) ? kunci.filter((k) => k !== huruf) : [...kunci, huruf]);
    };

    return (
        <Kotak
            judul={tunggal ? 'Pilihan jawaban · satu benar' : 'Pilihan jawaban · boleh lebih dari satu'}
            aksi={
                <Tombol onClick={tambah} disabled={opsi.length >= 26}>
                    Tambah pilihan
                </Tombol>
            }
        >
            {galat && <p className="mb-2 text-[11px] text-bad">{galat}</p>}

            <div className="space-y-2">
                {opsi.map((o, i) => (
                    <div key={i} className="flex items-start gap-2">
                        <button
                            type="button"
                            onClick={() => tandai(o.option_key)}
                            title={tunggal ? 'Tandai sebagai jawaban benar' : 'Tandai jawaban benar'}
                            className={
                                'mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center border text-[10px] font-medium uppercase ' +
                                (tunggal ? 'rounded-full' : 'rounded-wf') +
                                ' ' +
                                (kunci.includes(o.option_key)
                                    ? 'border-accent bg-accent text-on-accent'
                                    : 'border-line bg-surface text-ink-3 hover:border-accent')
                            }
                        >
                            {o.option_key}
                        </button>

                        <input
                            value={o.teks}
                            onChange={(e) => ubahTeks(i, e.target.value)}
                            placeholder={`Pilihan ${o.option_key.toUpperCase()}`}
                            className={kelasInput}
                        />

                        <Tombol
                            nada="bahaya"
                            onClick={() => buang(i)}
                            disabled={opsi.length <= 2}
                            title={opsi.length <= 2 ? 'Soal pilihan butuh sekurang-kurangnya dua pilihan' : 'Buang pilihan ini'}
                        >
                            Buang
                        </Tombol>
                    </div>
                ))}
            </div>
        </Kotak>
    );
}

/* ================================================================= Butir */

export type BarisButir = {
    item_key: string;
    item_group: 'kiri' | 'kanan';
    teks: string;
    correct_match_key: string;
};

/** Penyusun item jodoh - dua lajur, satu pasangan per baris kiri. */
export function ButirJodohBuilder({
    butir,
    pasangan,
    padaUbah,
    padaPasangan,
    galat,
}: {
    butir: BarisButir[];
    pasangan: Record<string, string>;
    padaUbah: (butir: BarisButir[]) => void;
    padaPasangan: (pasangan: Record<string, string>) => void;
    galat?: string;
}) {
    const kiri = butir.filter((b) => b.item_group === 'kiri');
    const kanan = butir.filter((b) => b.item_group === 'kanan');

    const tambah = (grup: 'kiri' | 'kanan') => {
        const n = butir.filter((b) => b.item_group === grup).length + 1;
        padaUbah([
            ...butir,
            { item_key: `${grup[0]}${n}`, item_group: grup, teks: '', correct_match_key: '' },
        ]);
    };

    const ubahTeks = (key: string, teks: string) =>
        padaUbah(butir.map((b) => (b.item_key === key ? { ...b, teks } : b)));

    /**
     * Menghapus item kiri ikut mencabut pasangannya.
     *
     * Tanpa itu, kunci menyimpan pasangan menunjuk item yang sudah tidak ada -
     * dan SkemaJenisSoal menolaknya sebagai "pasangan menggantung", sehingga
     * Guru menerima galat yang menyebut item yang tidak ia lihat di layar.
     */
    const buang = (key: string) => {
        padaUbah(butir.filter((b) => b.item_key !== key));
        const salinan = { ...pasangan };
        delete salinan[key];
        padaPasangan(salinan);
    };

    const lajur = (grup: 'kiri' | 'kanan', isi: BarisButir[]) => (
        <div className="space-y-2">
            {isi.map((b) => (
                <div key={b.item_key} className="flex items-start gap-2">
                    <span className="mt-2 w-6 shrink-0 font-mono text-[11px] text-ink-3">
                        {b.item_key}
                    </span>
                    <input
                        value={b.teks}
                        onChange={(e) => ubahTeks(b.item_key, e.target.value)}
                        placeholder={grup === 'kiri' ? 'Item kiri' : 'Item kanan'}
                        className={kelasInput}
                    />
                    {grup === 'kiri' ? (
                        <select
                            value={pasangan[b.item_key] ?? ''}
                            onChange={(e) =>
                                padaPasangan({ ...pasangan, [b.item_key]: e.target.value })
                            }
                            className={kelasInput + ' max-w-[9rem]'}
                            title="Pasangan yang benar"
                        >
                            <option value="">pasangan…</option>
                            {kanan.map((k) => (
                                <option key={k.item_key} value={k.item_key}>
                                    {k.item_key} — {k.teks.slice(0, 18) || '(kosong)'}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <span className="mt-2 w-9 text-[11px] text-ink-3">pasangan</span>
                    )}
                    <Tombol nada="bahaya" onClick={() => buang(b.item_key)}>
                        Buang
                    </Tombol>
                </div>
            ))}
        </div>
    );

    return (
        <Kotak judul="Item menjodohkan">
            {galat && <p className="mb-2 text-[11px] text-bad">{galat}</p>}

            <div className="grid gap-4 lg:grid-cols-2">
                <div>
                    <div className="mb-2 flex items-center gap-2">
                        <span className="text-xs font-medium text-ink-2">Lajur kiri</span>
                        <Chip nada="mute">{kiri.length}</Chip>
                        <Tombol onClick={() => tambah('kiri')}>Tambah</Tombol>
                    </div>
                    {lajur('kiri', kiri)}
                </div>

                <div>
                    <div className="mb-2 flex items-center gap-2">
                        <span className="text-xs font-medium text-ink-2">Lajur kanan</span>
                        <Chip nada="mute">{kanan.length}</Chip>
                        <Tombol onClick={() => tambah('kanan')}>Tambah</Tombol>
                    </div>
                    {lajur('kanan', kanan)}
                </div>
            </div>
        </Kotak>
    );
}

/** Penyusun urutan - satu daftar, disusun ulang dengan tombol, bukan seret. */
export function UrutanBuilder({
    butir,
    urutan,
    padaUbah,
    padaUrutan,
    galat,
}: {
    butir: BarisButir[];
    urutan: string[];
    padaUbah: (butir: BarisButir[]) => void;
    padaUrutan: (urutan: string[]) => void;
    galat?: string;
}) {
    const tambah = () =>
        padaUbah([
            ...butir,
            {
                item_key: `u${butir.length + 1}`,
                item_group: 'kiri',
                teks: '',
                correct_match_key: '',
            },
        ]);

    /**
     * Memindahkan satu item satu langkah.
     *
     * Tombol, bukan seret-lepas: menyusun urutan adalah pekerjaan yang
     * dilakukan sambil membaca teksnya, dan seret-lepas menuntut Guru
     * melepaskan pandangan dari teks untuk mengarahkan tetikus. Tombol naik
     * dan turun menjaga matanya di tempat yang sama.
     */
    const geser = (key: string, arah: -1 | 1) => {
        const i = butir.findIndex((b) => b.item_key === key);
        const j = i + arah;

        if (i < 0 || j < 0 || j >= butir.length) {
            return;
        }

        const salinan = [...butir];
        [salinan[i], salinan[j]] = [salinan[j], salinan[i]];

        // Urutan yang benar mengikuti perpindahan itu juga: Guru sedang
        // menyusun JAWABAN, bukan sekadar tampilan, dan memisahkan keduanya
        // berarti ia harus menyusun dua kali.
        padaUbah(salinan);
        padaUrutan(salinan.map((b) => b.item_key));
    };

    const buang = (key: string) => {
        const sisa = butir.filter((b) => b.item_key !== key);
        padaUbah(sisa);
        padaUrutan(sisa.map((b) => b.item_key));
    };

    return (
        <Kotak
            judul="Langkah · urutan di bawah ini ADALAH jawaban benarnya"
            aksi={<Tombol onClick={tambah}>Tambah langkah</Tombol>}
        >
            {galat && <p className="mb-2 text-[11px] text-bad">{galat}</p>}

            <div className="space-y-2">
                {butir.map((b, i) => (
                    <div key={b.item_key} className="flex items-center gap-2">
                        <span className="w-6 shrink-0 font-mono text-xs text-ink-3">{i + 1}</span>
                        <input
                            value={b.teks}
                            onChange={(e) =>
                                padaUbah(
                                    butir.map((x) =>
                                        x.item_key === b.item_key ? { ...x, teks: e.target.value } : x,
                                    ),
                                )
                            }
                            placeholder="Langkah"
                            className={kelasInput}
                        />
                        <Tombol onClick={() => geser(b.item_key, -1)} disabled={i === 0}>
                            Naik
                        </Tombol>
                        <Tombol onClick={() => geser(b.item_key, 1)} disabled={i === butir.length - 1}>
                            Turun
                        </Tombol>
                        <Tombol nada="bahaya" onClick={() => buang(b.item_key)}>
                            Buang
                        </Tombol>
                    </div>
                ))}
            </div>

            {urutan.length === 0 && (
                <p className="mt-2 text-[11px] text-ink-3">
                    Tambahkan sekurang-kurangnya dua langkah — soal mengurutkan membutuhkan
                    urutan yang lengkap.
                </p>
            )}
        </Kotak>
    );
}

/* ================================================================ Rubrik */

export type BarisRubrik = { criterion: string; description: string; max_score: string };

export function RubrikBuilder({
    rubrik,
    padaUbah,
    galat,
}: {
    rubrik: BarisRubrik[];
    padaUbah: (rubrik: BarisRubrik[]) => void;
    galat?: string;
}) {
    const tambah = () =>
        padaUbah([...rubrik, { criterion: '', description: '', max_score: '1' }]);

    const ubah = (i: number, patch: Partial<BarisRubrik>) =>
        padaUbah(rubrik.map((r, j) => (j === i ? { ...r, ...patch } : r)));

    const total = rubrik.reduce((n, r) => n + (parseFloat(r.max_score) || 0), 0);

    return (
        <Kotak
            judul="Rubrik penilaian"
            aksi={
                <>
                    <Chip nada={total > 0 ? 'info' : 'warn'}>
                        total maks {total.toLocaleString('id-ID')}
                    </Chip>
                    <Tombol onClick={tambah}>Tambah kriteria</Tombol>
                </>
            }
        >
            {galat && <p className="mb-2 text-[11px] text-bad">{galat}</p>}

            <div className="space-y-3">
                {rubrik.map((r, i) => (
                    <div key={i} className="grid gap-2 sm:grid-cols-[1fr_1fr_7rem_auto]">
                        <input
                            value={r.criterion}
                            onChange={(e) => ubah(i, { criterion: e.target.value })}
                            placeholder="Kriteria, mis. langkah lengkap"
                            className={kelasInput}
                        />
                        <input
                            value={r.description}
                            onChange={(e) => ubah(i, { description: e.target.value })}
                            placeholder="Keterangan (opsional)"
                            className={kelasInput}
                        />
                        <input
                            value={r.max_score}
                            onChange={(e) => ubah(i, { max_score: e.target.value })}
                            inputMode="decimal"
                            placeholder="Skor maks"
                            className={kelasInput}
                        />
                        <Tombol
                            nada="bahaya"
                            onClick={() => padaUbah(rubrik.filter((_, j) => j !== i))}
                        >
                            Buang
                        </Tombol>
                    </div>
                ))}
            </div>

            {rubrik.length === 0 && (
                <p className="text-[11px] text-ink-3">
                    Soal uraian wajib punya rubrik: tanpa itu dua pengoreksi akan memberi nilai
                    berbeda untuk jawaban yang sama.
                </p>
            )}
        </Kotak>
    );
}
