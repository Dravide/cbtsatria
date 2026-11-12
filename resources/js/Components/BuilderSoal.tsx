import { useEffect, useRef } from 'react';
import { Chip, Field, Kotak, Tombol, kelasInput } from '@/Components/ui';
import { MediaButir, MediaSoal } from '@/Components/MediaSoal';

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
 * Nilainya milik PERAMBAN, dan React menulis hanya saat kotaknya TIDAK fokus
 * =====================================================================
 * `contenteditable` yang nilainya diikat ke state React dan ditulis balik
 * setiap ketikan memindahkan kursor ke awal pada setiap huruf - itu
 * pertarungan antara dua pemilik DOM yang sama, bukan bug yang bisa
 * diperbaiki dengan `useLayoutEffect`.
 *
 * Karena itu penulisannya dijaga dua syarat, dan keduanya wajib:
 *
 *   1. Kotak ini BUKAN elemen yang sedang difokuskan. Menulis ke dalamnya
 *      saat Guru mengetik adalah tepat penyebab kursor melompat itu.
 *   2. Isinya memang berbeda dari `nilai`.
 *
 * Syarat pertama menutup ketikan; syarat KEDUA membuka jalan bagi perubahan
 * dari LUAR - hasil Generate AI, menghapus satu pilihan di atasnya, atau
 * memuat soal yang sudah ada. Tanpa itu, `contenteditable` yang dipasang
 * sekali akan terus menampilkan huruf lama setelah datanya diganti.
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
    ringkas = false,
}: {
    nilai: string;
    padaUbah: (html: string) => void;
    label?: string;
    galat?: string;
    tinggi?: string;
    placeholder?: string;
    /**
     * Bentuk ringkas untuk dipakai SEBARIS, di dalam baris pilihan jawaban:
     * toolbar lebih sedikit, tanpa label di atasnya, tanpa bingkai Field, dan
     * melebar mengisi sisa baris. Tanpa ini, tiap pilihan jawaban membawa
     * kotak "Teks soal" bertinggi 128px - empat di antaranya menutupi seluruh
     * layar sebelum Guru sempat menulis pertanyaannya.
     */
    ringkas?: boolean;
}) {
    const ref = useRef<HTMLDivElement>(null);

    // Lihat catatan kelas: tulis hanya bila kotaknya tidak sedang difokuskan
    // dan isinya memang berbeda. Efek ini juga yang mengisi isi saat pertama
    // dipasang.
    useEffect(() => {
        const el = ref.current;

        if (el === null || document.activeElement === el) {
            return;
        }

        if (el.innerHTML !== nilai) {
            el.innerHTML = nilai;
        }
    }, [nilai]);

    /** Jalankan perintah format pada seleksi yang sedang aktif. */
    const perintah = (cmd: string, arg?: string) => {
        ref.current?.focus();
        document.execCommand(cmd, false, arg);
        if (ref.current) {
            padaUbah(ref.current.innerHTML);
        }
    };

    const semuaTombol: { label: string; judul: string; cmd: string; arg?: string }[] = [
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

    // Saat ringkas, ratakan hanya yang muat di baris sempit: format huruf,
    // pangkat/indeks, dan dua daftar. Perataan dan tabel tetap ada di editor
    // teks soal - pilihan jawaban jarang butuh tabel, dan toolbar penuh di
    // setiap baris pilihan membuat barisnya setinggi form.
    const tombol = ringkas
        ? semuaTombol.filter((t) =>
              ['bold', 'italic', 'underline', 'superscript', 'subscript', 'insertUnorderedList', 'insertOrderedList'].includes(t.cmd),
          )
        : semuaTombol;

    const gayaTombol = ringkas
        ? 'rounded-wf border border-line bg-surface px-1.5 py-0.5 text-[10px] font-medium text-ink-2 hover:bg-surface-2 hover:text-ink'
        : 'rounded-wf border border-line bg-surface px-2 py-0.5 text-[11px] font-medium text-ink-2 hover:bg-surface-2 hover:text-ink';

    const kotak = (
        <div
            className={
                'overflow-hidden rounded-wf border border-line bg-surface focus-within:border-accent' +
                (ringkas ? ' min-w-0 flex-1' : '')
            }
        >
            <div
                className={
                    'flex flex-wrap items-center gap-1 border-b border-line bg-surface-2/60 ' +
                    (ringkas ? 'px-1.5 py-1' : 'px-2 py-1.5')
                }
            >
                {tombol.map((t) => (
                    <button
                        key={t.cmd}
                        type="button"
                        title={t.judul}
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={() => perintah(t.cmd)}
                        className={gayaTombol}
                    >
                        {t.label}
                    </button>
                ))}
                {!ringkas && (
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
                        className={gayaTombol}
                    >
                        Tabel
                    </button>
                )}
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
                    (ringkas ? 'px-2.5 py-1.5' : 'px-3 py-2') +
                    ' text-sm text-ink outline-none ' +
                    tinggi +
                    ' [&_table]:my-2 [&_table]:border-collapse [&_td]:border [&_td]:border-line [&_td]:px-2 [&_td]:py-1 [&_ol]:ml-5 [&_ol]:list-decimal [&_ul]:ml-5 [&_ul]:list-disc'
                }
            />
        </div>
    );

    if (ringkas) {
        return kotak;
    }

    return (
        <Field label={label} galat={galat}>
            {kotak}
        </Field>
    );
}

/* =================================================================== Opsi */

export type BarisOpsi = { option_key: string; teks: string; media?: MediaButir[] };

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
    galatOpsi,
    soalId = null,
    bolehUbah = true,
}: {
    opsi: BarisOpsi[];
    kunci: string[];
    padaUbah: (opsi: BarisOpsi[]) => void;
    padaKunci: (kunci: string[]) => void;
    /** Satu benar (pilihan ganda) atau boleh banyak (jawaban ganda). */
    tunggal: boolean;
    galat?: string;
    /**
     * Galat validasi untuk SATU pilihan, dipanggil dengan INDEKS baris (bukan
     * `option_key`) karena server menamai galatnya menurut posisi larik:
     * `opsi.0.content_json.teks`, `opsi.1.…`. Menampilkannya tepat di bawah
     * pilihan yang bersangkutan, bukan di satu kotak di atas form: pilihan
     * jawaban yang kosong adalah penyebab paling sering "Simpan tidak terjadi
     * apa-apa", dan pesannya harus muncul persis di tempat Guru harus mengetik.
     */
    galatOpsi?: (indeks: number) => string | undefined;
    /** Id soal tersimpan, atau null untuk soal baru (media draf). */
    soalId?: number | null;
    bolehUbah?: boolean;
}) {
    const ubahTeks = (i: number, teks: string) =>
        padaUbah(opsi.map((o, j) => (j === i ? { ...o, teks } : o)));

    const ubahMedia = (i: number, media: MediaButir[]) =>
        padaUbah(opsi.map((o, j) => (j === i ? { ...o, media } : o)));

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

            <div className="space-y-3">
                {opsi.map((o, i) => (
                    <div key={i} className="space-y-1">
                        <div className="flex items-start gap-2">
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

                            {/* Isi pilihan memakai editor berformat yang SAMA
                                dengan teks soal, bukan `<input>` polos. Alasannya
                                bukan kemewahan: pilihan jawaban matematika butuh
                                pangkat (x², H₂O), dan Bahasa Indonesia butuh
                                miring untuk judul - keduanya mustahil ditulis di
                                kotak teks satu baris. Bentuk `ringkas` menjaga
                                barisnya tetap setinggi daftar, bukan setinggi
                                form. */}
                            <EditorSoal
                                ringkas
                                nilai={o.teks}
                                padaUbah={(html) => ubahTeks(i, html)}
                                tinggi="min-h-[2.25rem]"
                                placeholder={`Pilihan ${o.option_key.toUpperCase()}`}
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

                        {/* Media pilihan jawaban, langsung di bawah PILIHAN INI
                            sendiri - bukan dikumpulkan di satu blok jauh di bawah
                            seluruh daftar. Dengan begitu gambar yang menempel
                            pada pilihan B tampil tepat di bawah teks B, sehingga
                            Guru tidak pernah ragu gambar itu milik pilihan yang
                            mana. */}
                        <div className="flex items-start gap-2 pl-7">
                            <span className="mt-1 w-4 shrink-0 font-mono text-[10px] text-ink-3 uppercase">
                                {o.option_key}
                            </span>
                            <MediaSoal
                                ringkas
                                media={o.media ?? []}
                                padaUbah={(m) => ubahMedia(i, m)}
                                soalId={soalId}
                                optionKey={o.option_key}
                                bolehUbah={bolehUbah}
                            />
                        </div>

                        {/* Galat pilihan ini. Ditaruh paling bawah barisnya,
                            di bawah kotak teks dan media, supaya kalimatnya
                            terbaca sebagai milik pilihan A/B/C - bukan milik
                            pilihan sesudahnya. */}
                        {galatOpsi?.(i) && (
                            <p className="pl-7 text-[11px] text-bad">{galatOpsi(i)}</p>
                        )}
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

/* ============================================================== Rumpang */

/**
 * Nomor penanda `{{n}}` di dalam teks soal, sesuai urutan kemunculannya.
 *
 * Cermin `SkemaJenisSoal::penandaRumpang()`: yang dihitung di layar dan yang
 * dihitung di server harus sama, karena selisihnya adalah galat yang baru
 * muncul setelah menekan Simpan. Polanya menerima spasi di dalam kurung
 * kurawal - guru mengetik `{{ 1 }}` lebih sering daripada yang diduga.
 */
function penandaRumpang(html: string): number[] {
    const hasil: number[] = [];
    const pola = /\{\{\s*(\d+)\s*\}\}/g;
    let cocok: RegExpExecArray | null;

    while ((cocok = pola.exec(html)) !== null) {
        hasil.push(Number.parseInt(cocok[1], 10));
    }

    return hasil;
}

/**
 * Penyunting kunci isian rumpang - satu baris per lubang.
 *
 * =====================================================================
 * Jumlahnya tidak ditambah-tambah sendiri, melainkan MENGIKUTI teks
 * =====================================================================
 * Baris kunci disusun dari penanda yang benar-benar ada di teks soal, bukan
 * dari tombol "tambah lubang". Kalau keduanya bisa bertambah sendiri-sendiri,
 * keadaan yang paling sering terjadi adalah teks dengan empat penanda dan
 * kunci berisi dua baris - dan itu ditolak saat simpan, setelah Guru
 * mengetik seluruh jawabannya.
 *
 * Karena itu urutannya: tulis soalnya, sisipkan `{{n}}`, lalu isi kuncinya.
 * Tombol "Selaraskan" menambah atau membuang baris tanpa menyentuh isi yang
 * sudah ada, dan itu yang dipakai Guru yang menyunting soalnya belakangan.
 *
 * =====================================================================
 * Penanda disisipkan dari SINI, bukan diketik di dalam editor
 * =====================================================================
 * `<input>` di dalam `contenteditable` akan rusak oleh perintah format
 * toolbar, dan yang lebih buruk: ia bisa terhapus tanpa Guru sadar.
 * Penyisipan lewat `padaTeks` menambahkan `{{n}}` di AKHIR teks - bentuk
 * yang bisa disunting dan dipindahkan seperti teks biasa.
 */
export function RumpangBuilder({
    teks,
    rumpang,
    padaUbah,
    padaTeks,
    galat,
    bolehUbah = true,
}: {
    teks: string;
    rumpang: string[][];
    padaUbah: (rumpang: string[][]) => void;
    padaTeks: (html: string) => void;
    galat?: string;
    bolehUbah?: boolean;
}) {
    const penanda = penandaRumpang(teks);
    const jumlah = penanda.length;
    // Baris yang DITAMPILKAN selalu sebanyak penandanya, atau sebanyak
    // kuncinya - mana yang lebih banyak. Dengan begitu Guru melihat baris
    // kosong tambahan (butuh kunci) DAN baris berisi yang tidak terpakai
    // (kunci berlebih) alih-alih salah satunya hilang diam-diam.
    const baris = Math.max(jumlah, rumpang.length);
    const selaras = jumlah > 0 && jumlah === rumpang.length;

    const ubahVarian = (lubang: number, varian: number, nilai: string) => {
        const salinan = rumpang.map((v) => [...v]);

        while (salinan.length <= lubang) {
            salinan.push(['']);
        }

        salinan[lubang][varian] = nilai;
        padaUbah(salinan);
    };

    const tambahVarian = (lubang: number) => {
        const salinan = rumpang.map((v) => [...v]);

        while (salinan.length <= lubang) {
            salinan.push(['']);
        }

        salinan[lubang] = [...salinan[lubang], ''];
        padaUbah(salinan);
    };

    const buangVarian = (lubang: number, varian: number) => {
        const salinan = rumpang.map((v) => [...v]);

        salinan[lubang] = salinan[lubang].filter((_, j) => j !== varian);
        padaUbah(salinan);
    };

    /** Samakan jumlah baris dengan jumlah penanda, tanpa menyentuh isinya. */
    const selaraskan = () => {
        const salinan = rumpang.map((v) => [...v]);

        while (salinan.length < jumlah) {
            salinan.push(['']);
        }

        padaUbah(salinan.slice(0, jumlah));
    };

    /** Sisipkan penanda berikutnya di akhir teks soal. */
    const sisipkan = () => {
        const berikut = (jumlah > 0 ? Math.max(...penanda) : 0) + 1;
        const pemisah = teks.trim() === '' ? '' : ' ';

        padaTeks(`${teks}${pemisah}{{${berikut}}}`);
    };

    return (
        <Kotak
            judul="Kunci isian rumpang"
            aksi={
                <>
                    <Chip nada={selaras ? 'info' : 'warn'}>
                        {jumlah} rumpang · {rumpang.length} kunci
                    </Chip>
                    {bolehUbah && (
                        <Tombol onClick={sisipkan} title="Sisipkan penanda berikutnya di akhir teks soal">
                            Sisipkan {`{{${(jumlah > 0 ? Math.max(...penanda) : 0) + 1}}}`}
                        </Tombol>
                    )}
                </>
            }
        >
            <p className="mb-2 text-[11px] text-ink-3">
                Sisipkan <code className="font-mono">{'{{1}}'}</code>,{' '}
                <code className="font-mono">{'{{2}}'}</code>, dan seterusnya pada kata yang
                dikosongkan di teks soal. Tulis setiap ejaan yang harus dianggap benar pada baris
                lubangnya; pencocokan mengabaikan huruf besar/kecil dan spasi di tepi.
            </p>

            {galat && <p className="mb-2 text-[11px] text-bad">{galat}</p>}

            {jumlah === 0 && (
                <div className="mb-2 rounded-wf border border-warn bg-warn-bg px-3 py-2 text-[11px] text-ink">
                    Teks soal belum punya penanda rumpang, jadi soal ini belum bisa disimpan.
                    Tekan tombol di kanan atas untuk menyisipkan{' '}
                    <code className="font-mono">{'{{1}}'}</code>, lalu pindahkan ke kata yang
                    ingin dikosongkan.
                </div>
            )}

            {jumlah > 0 && jumlah !== rumpang.length && bolehUbah && (
                <div className="mb-2 flex flex-wrap items-center gap-2 rounded-wf border border-warn bg-warn-bg px-3 py-2 text-[11px] text-ink">
                    <span>
                        Teks soal memuat {jumlah} rumpang, tetapi kuncinya baru {rumpang.length}.
                    </span>
                    <Tombol onClick={selaraskan}>Selaraskan kunci</Tombol>
                </div>
            )}

            <div className="space-y-2">
                {Array.from({ length: baris }).map((_, lubang) => {
                    const varianLubang = rumpang[lubang] ?? [''];
                    const kosong = varianLubang.every((v) => v.trim() === '');
                    const lebih = lubang >= jumlah;

                    return (
                        <div
                            key={lubang}
                            className={
                                'rounded-wf border p-2 ' +
                                (lebih || kosong ? 'border-bad bg-bad-bg/40' : 'border-line')
                            }
                        >
                            <div className="mb-1.5 flex items-center gap-2">
                                <span className="grid size-5 shrink-0 place-items-center rounded-wf border border-accent bg-accent-soft font-mono text-[10px] text-accent">
                                    {lubang + 1}
                                </span>
                                <span className="font-mono text-[11px] text-ink-3">
                                    {`{{${lubang + 1}}}`}
                                </span>
                                {lebih && (
                                    <span className="text-[11px] text-bad">
                                        tidak dipakai - teks soal tidak punya penanda ini
                                    </span>
                                )}
                                {bolehUbah && (
                                    <Tombol
                                        nada="sek"
                                        onClick={() => tambahVarian(lubang)}
                                        title="Tambah ejaan lain yang juga dianggap benar"
                                    >
                                        + varian
                                    </Tombol>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                {varianLubang.map((v, j) => (
                                    <div key={j} className="flex gap-2">
                                        <input
                                            value={v}
                                            onChange={(e) => ubahVarian(lubang, j, e.target.value)}
                                            placeholder={
                                                j === 0
                                                    ? `Jawaban untuk rumpang ${lubang + 1}`
                                                    : 'Ejaan lain yang juga benar'
                                            }
                                            disabled={!bolehUbah}
                                            className={kelasInput}
                                        />
                                        <Tombol
                                            nada="bahaya"
                                            onClick={() => buangVarian(lubang, j)}
                                            disabled={!bolehUbah || varianLubang.length <= 1}
                                        >
                                            Buang
                                        </Tombol>
                                    </div>
                                ))}
                            </div>
                        </div>
                    );
                })}
            </div>
        </Kotak>
    );
}

/* ================================================================ Rubrik */
export type BarisRubrik = { criterion: string; description: string; max_score: string };

export function RubrikBuilder({
    rubrik,
    padaUbah,
    galat,
    bobot,
}: {
    rubrik: BarisRubrik[];
    padaUbah: (rubrik: BarisRubrik[]) => void;
    galat?: string;
    /** Bobot butir, dibandingkan dengan total rubrik. */
    bobot?: number;
}) {
    const tambah = () =>
        padaUbah([...rubrik, { criterion: '', description: '', max_score: '1' }]);

    const ubah = (i: number, patch: Partial<BarisRubrik>) =>
        padaUbah(rubrik.map((r, j) => (j === i ? { ...r, ...patch } : r)));

    const total = rubrik.reduce((n, r) => n + (parseFloat(r.max_score) || 0), 0);

    // Server menolak rubrik yang jumlahnya berbeda dari bobot butir, dan
    // tanpa penanda di sini Guru baru tahu setelah menekan Simpan. Diperiksa
    // hanya bila bobotnya memang dikirim - pemanggil lain boleh melewatkannya.
    const selisih = bobot !== undefined && rubrik.length > 0 && Math.abs(total - bobot) > 0.01;

    return (
        <Kotak
            judul="Rubrik penilaian"
            aksi={
                <>
                    <Chip nada={rubrik.length === 0 ? 'warn' : selisih ? 'bad' : 'info'}>
                        total maks {total.toLocaleString('id-ID')}
                        {selisih ? ` · bobot ${bobot.toLocaleString('id-ID')}` : ''}
                    </Chip>
                    <Tombol onClick={tambah}>Tambah kriteria</Tombol>
                </>
            }
        >
            {galat && <p className="mb-2 text-[11px] text-bad">{galat}</p>}

            {selisih && (
                <p className="mb-2 text-[11px] text-bad">
                    Jumlah skor rubrik ({total.toLocaleString('id-ID')}) harus sama dengan bobot
                    butir ({bobot.toLocaleString('id-ID')}). Rubrik adalah definisi nilai soal
                    uraian: bila jumlahnya berbeda, ada poin yang tidak bisa diberikan atau poin
                    yang tidak bisa diraih.
                </p>
            )}

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
