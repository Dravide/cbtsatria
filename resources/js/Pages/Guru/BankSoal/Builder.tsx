import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import GuruLayout from '@/Layouts/GuruLayout';
import {
    ButirJodohBuilder,
    BarisButir,
    BarisOpsi,
    BarisRubrik,
    EditorSoal,
    OpsiBuilder,
    RubrikBuilder,
    RumpangBuilder,
    UrutanBuilder,
} from '@/Components/BuilderSoal';
import { Chip, Field, Inbox, Kotak, Peringatan, Tombol, kelasInput } from '@/Components/ui';
import { MediaButir, MediaSoal } from '@/Components/MediaSoal';

/**
 * Laman Guru 03 - builder soal. Wireframe-peran.html plate 03.
 *
 * =====================================================================
 * Sepuluh jenis sebagai TAB, bukan menu turun
 * =====================================================================
 * Alasan wireframe `:434` dikutip apa adanya karena ia yang menentukan
 * bentuk laman ini: Guru harus melihat seluruh jenis yang tersedia sekaligus,
 * karena pilihan itulah yang menentukan bentuk form di bawahnya. Menu turun
 * menyembunyikan sembilan dari sepuluh, sehingga Guru yang mencari "soal yang
 * jawabannya diurutkan" harus membuka menu itu lebih dulu untuk tahu bahwa
 * jenisnya ada.
 *
 * =====================================================================
 * SATU state untuk sepuluh bentuk, bukan sepuluh form terpisah
 * =====================================================================
 * Godaannya adalah sepuluh komponen form yang masing-masing mengurus
 * payloadnya. Yang membuat itu salah: berpindah tab TIDAK boleh menghapus
 * apa yang sudah ditulis. Guru yang menulis teks soal di tab Pilihan ganda,
 * lalu menyadari jawabannya seharusnya diurutkan, harus menemukan teksnya
 * masih ada di tab Mengurutkan.
 *
 * Karena itu seluruh bentuk hidup di satu state di sini, dan `key` pada
 * editor dipakai untuk memasang ulang DOM-nya saat berpindah tab - lihat
 * EditorSoal untuk mengapa itu perlu.
 *
 * =====================================================================
 * Yang dikirim adalah `kunci`, dan `is_correct` tidak pernah dikirim
 * =====================================================================
 * Kolom `is_correct` di `question_options` DITURUNKAN server dari kunci
 * (BankSoalService::tulisAnak). Mengirimnya dari sini berarti dua sumber
 * untuk satu fakta - dan yang dinilai mesin adalah kuncinya, sehingga
 * centang yang tampak benar di layar bisa saja tidak dihitung.
 */
type Mapel = { id: number; nama: string; kode: string };

type SoalAda = {
    id: number;
    kode: string;
    judul: string;
    jenis: string;
    tingkat: string | null;
    status: string;
    mapel_id: number;
    prompt: Record<string, unknown> | null;
    kunci: Record<string, unknown> | null;
    skor: Record<string, unknown> | null;
    pembahasan: Record<string, unknown> | null;
    media?: MediaButir[];
    opsi: {
        option_key: string;
        content_json: { teks?: string };
        position: number;
        media?: MediaButir[];
    }[];
    butir: {
        item_key: string;
        item_group: string | null;
        content_json: { teks?: string };
        correct_match_key: string | null;
        position: number;
    }[];
    rubrik: { criterion: string; description: string | null; max_score: number; position: number }[];
    penulis: string | null;
};

type Props = {
    soal: SoalAda | null;
    bolehUbah?: boolean;
    mapel: Mapel[];
    jenis: { nilai: string; label: string; pendek: string }[];
    paketTujuan: number | null;
};

/**
 * Ubah `mapelId` (string dari `<select>`) menjadi angka yang aman dikirim.
 *
 * Mengapa tidak `Number(mapelId)` saja: `Number('')` adalah **0**, dan 0 lolos
 * ke server sebagai `subject_id` yang sah menurut tipe. Server lalu menolaknya
 * di `AmpuanMapel` dengan "Anda tidak mengampu mata pelajaran tersebut." -
 * kalimat yang menuduh Guru atas mata pelajaran yang bahkan tidak ada, dan
 * membuatnya mencari kesalahan di izin, bukan di daftar yang kosong.
 *
 * Keadaan itu nyata: Guru yang belum ditugaskan ke mapel mana pun membuka
 * laman ini dengan daftar mapel kosong, sehingga `mapel[0]` tidak ada dan
 * `mapelId` tetap `''`.
 */
const idMapel = (nilai: string): number | null => {
    const angka = Number.parseInt(nilai, 10);

    return Number.isFinite(angka) && angka > 0 ? angka : null;
};

/** Ambil teks dari kolom JSON apa pun bentuknya. */
const teks = (j: unknown): string => {
    if (j && typeof j === 'object' && 'teks' in (j as Record<string, unknown>)) {
        return String((j as { teks?: unknown }).teks ?? '');
    }
    return '';
};

const BENAR_SALAH: BarisOpsi[] = [
    { option_key: 'b', teks: 'Benar' },
    { option_key: 's', teks: 'Salah' },
];

export default function Builder({ soal, bolehUbah = true, mapel, jenis, paketTujuan }: Props) {
    const baru = soal === null;
    const kunciAwal = (soal?.kunci ?? {}) as Record<string, unknown>;

    // ------------------------------------------------------------- State
    const [tab, setTab] = useState(soal?.jenis ?? 'pilihan_ganda');
    const [mapelId, setMapelId] = useState(String(soal?.mapel_id ?? mapel[0]?.id ?? ''));
    const [judul, setJudul] = useState(soal?.judul ?? '');
    const [kode, setKode] = useState(soal?.kode ?? '');
    const [tingkat, setTingkat] = useState(soal?.tingkat ?? 'sedang');
    const [status, setStatus] = useState(soal?.status ?? 'draft');
    const [prompt, setPrompt] = useState(teks(soal?.prompt));
    const [pembahasan, setPembahasan] = useState(teks(soal?.pembahasan));

    // Media pada SOAL (gambar/audio/video stimulus) - terpisah dari media
    // pilihan jawaban yang hidup di dalam tiap `BarisOpsi`. Daftar id-nya
    // ikut terkirim saat simpan (`media_draf` / `media_opsi_draf`) supaya
    // media draf tertaut ke soal yang baru dibuat.
    const [mediaSoal, setMediaSoal] = useState<MediaButir[]>(soal?.media ?? []);

    const [opsi, setOpsi] = useState<BarisOpsi[]>(
        soal?.opsi?.length
            ? soal.opsi.map((o) => ({
                  option_key: o.option_key,
                  teks: teks(o.content_json),
                  media: o.media ?? [],
              }))
            : [
                  { option_key: 'a', teks: '' },
                  { option_key: 'b', teks: '' },
                  { option_key: 'c', teks: '' },
                  { option_key: 'd', teks: '' },
              ],
    );
    const [kunciOpsi, setKunciOpsi] = useState<string[]>(
        ((kunciAwal.opsi as string[] | undefined) ?? []).map((s) => s.toLowerCase()),
    );
    const [varian, setVarian] = useState<string[]>(
        ((kunciAwal.teks as string[] | undefined) ?? ['']).length
            ? ((kunciAwal.teks as string[]) ?? [])
            : [''],
    );
    // Satu baris = satu lubang, satu baris berisi varian jawaban lubang itu.
    // Daftar, BUKAN objek berkunci nomor: lihat SkemaJenisSoal::rumpang.
    const [rumpang, setRumpang] = useState<string[][]>(
        Array.isArray(kunciAwal.rumpang)
            ? (kunciAwal.rumpang as string[][]).map((v) => (v.length ? v : ['']))
            : [['']],
    );
    const [nilai, setNilai] = useState(String(kunciAwal.nilai ?? ''));
    const [toleransi, setToleransi] = useState(String(kunciAwal.toleransi ?? '0'));
    const [satuan, setSatuan] = useState(String(kunciAwal.satuan ?? ''));

    const [butir, setButir] = useState<BarisButir[]>(
        soal?.butir?.length
            ? soal.butir.map((b) => ({
                  item_key: b.item_key,
                  item_group: (b.item_group as 'kiri' | 'kanan') ?? 'kiri',
                  teks: teks(b.content_json),
                  correct_match_key: b.correct_match_key ?? '',
              }))
            : [],
    );
    const [pasangan, setPasangan] = useState<Record<string, string>>(
        ((kunciAwal.pasangan as Record<string, string> | undefined) ?? {}),
    );
    const [urutan, setUrutan] = useState<string[]>(
        (kunciAwal.urutan as string[] | undefined) ?? [],
    );

    const [rubrik, setRubrik] = useState<BarisRubrik[]>(
        soal?.rubrik?.length
            ? soal.rubrik.map((r) => ({
                  criterion: r.criterion,
                  description: r.description ?? '',
                  max_score: String(r.max_score),
              }))
            : [],
    );

    const skorAwal = (soal?.skor ?? {}) as Record<string, unknown>;
    const [bobot, setBobot] = useState(String(skorAwal.bobot ?? '1'));
    const [skorParsial, setSkorParsial] = useState(Boolean(skorAwal.skor_parsial));
    const [mekanisme, setMekanisme] = useState(String(skorAwal.mekanisme ?? 'pilihan'));



    // ----------------------------------------------------------- Kiriman
    //
    // Tipe payload-nya ditulis lengkap, bukan `Record<string, unknown>`, dan
    // itu bukan kerapian: `useForm<T>` di Inertia 3 menuntut T memenuhi
    // FormDataType, dan Record<string, unknown> tidak memenuhinya - sehingga
    // formulir ini tidak bisa dikompilasi sama sekali. Bentuknya ditulis apa
    // adanya di sini, dan itu justru berguna: seluruh field yang dikirim
    // server terbaca dari satu tempat.
    type Kiriman = {
        // `number` saat ada mapel yang dipilih, `''` saat daftarnya kosong.
        // Bentuk kedua itu disengaja: yang dikirim adalah "belum ada pilihan",
        // bukan angka 0 yang terlihat seperti id yang sah.
        subject_id: number | '';
        code: string | null;
        title: string;
        question_type: string;
        difficulty: string;
        status: string;
        prompt_json: { teks: string };
        explanation_json: { teks: string } | null;
        // Kunci, bentuknya berbeda per jenis. Ditulis sebagai bentuk yang
        // mungkin, bukan `Record<string, unknown>`, karena Inertia 3 menuntut
        // tipenya memenuhi FormDataType - dan `Record<string, unknown>` tidak
        // memenuhinya. Bentuk gabungan ini justru lebih jujur: dari lima
        // kemungkinan, tidak ada satu pun yang memuat keduanya sekaligus.
        answer_key_json:
            | { opsi: string[] }
            | { teks: string[] }
            | { rumpang: string[][] }
            | { pasangan: Record<string, string> }
            | { urutan: string[] }
            | { nilai: number | null; toleransi: number; satuan?: string }
            | null;
        scoring_config_json: {
            bobot: number;
            skor_parsial?: boolean;
            mekanisme?: string;
        };
        opsi: { option_key: string; content_json: { teks: string }; position: number }[];
        butir: {
            item_key: string;
            item_group: string;
            content_json: { teks: string };
            correct_match_key: string | null;
            position: number;
        }[];
        rubrik: {
            criterion: string;
            description: string | null;
            max_score: number;
            position: number;
        }[];
        // Id media yang sudah diunggah di builder (draf bila soal baru).
        // Server menautkannya ke soal yang baru disimpan; lihat
        // MediaSoal::tautkanDraf / tautkanOpsiDraf.
        media_draf: number[];
        media_opsi_draf: number[];
    };

    const form = useForm<Kiriman>({
        subject_id: '',
        code: null,
        title: '',
        question_type: 'pilihan_ganda',
        difficulty: 'sedang',
        status: 'draft',
        prompt_json: { teks: '' },
        explanation_json: null,
        answer_key_json: null,
        scoring_config_json: { bobot: 1 },
        opsi: [],
        butir: [],
        rubrik: [],
        media_draf: [],
        media_opsi_draf: [],
    });

    /** Susun `answer_key_json` menurut jenis yang SEDANG dipilih. */
    const susunKunci = (): Record<string, unknown> | null => {
        switch (tab) {
            case 'pilihan_ganda':
            case 'jawaban_ganda':
            case 'benar_salah':
                return { opsi: kunciOpsi };
            case 'isian_singkat':
                return { teks: varian.filter((v) => v.trim() !== '') };
            case 'isian_rumpang':
                // Varian kosong dibuang per lubang; lubang yang seluruhnya
                // kosong tetap dikirim kosong supaya server bisa menyebut
                // NOMORNYA. Membuangnya di sini akan menggeser penomoran dan
                // membuat galatnya menunjuk lubang yang salah.
                return {
                    rumpang: rumpang.map((v) => v.map((x) => x.trim()).filter((x) => x !== '')),
                };
            case 'menjodohkan':
                return { pasangan };
            case 'mengurutkan':
                return { urutan };
            case 'angka':
                return {
                    nilai: nilai === '' ? null : parseFloat(nilai),
                    toleransi: parseFloat(toleransi) || 0,
                    ...(satuan ? { satuan } : {}),
                };
            case 'media':
                // Media meminjam mekanisme yang sudah ada; kuncinya mengikuti.
                return mekanisme === 'isian'
                    ? { teks: varian.filter((v) => v.trim() !== '') }
                    : mekanisme === 'urut'
                      ? { urutan }
                      : { opsi: kunciOpsi };
            case 'uraian':
                return null;
            default:
                return null;
        }
    };

    const kirim = () => {
        // Penjaga terakhir. Tombolnya sudah mati saat daftar mapel kosong, dan
        // ini menutup jalan lain ke fungsi yang sama - termasuk `Enter` di
        // dalam field, yang tetap men-submit formnya.
        if (idMapel(mapelId) === null) {
            return;
        }

        const kunci = susunKunci();
        const pakaiOpsi = ['pilihan_ganda', 'jawaban_ganda', 'benar_salah'].includes(tab) ||
            (tab === 'media' && mekanisme === 'pilihan');

        form.transform(() => ({
            subject_id: idMapel(mapelId) ?? '',
            code: kode || null,
            title: judul,
            question_type: tab,
            difficulty: tingkat,
            status,
            prompt_json: { teks: prompt },
            explanation_json: pembahasan ? { teks: pembahasan } : null,
            answer_key_json: kunci,
            scoring_config_json: {
                bobot: parseFloat(bobot) || 1,
                ...(tab === 'jawaban_ganda' ? { skor_parsial: skorParsial } : {}),
                ...(tab === 'media' ? { mekanisme } : {}),
            },
            opsi: pakaiOpsi
                ? opsi.map((o, i) => ({
                      option_key: o.option_key,
                      content_json: { teks: o.teks },
                      position: i,
                  }))
                : [],
            butir: butir.map((b, i) => ({
                item_key: b.item_key,
                item_group: b.item_group,
                content_json: { teks: b.teks },
                correct_match_key: b.correct_match_key || null,
                position: i,
            })),
            rubrik: rubrik.map((r, i) => ({
                criterion: r.criterion,
                description: r.description || null,
                max_score: parseFloat(r.max_score) || 1,
                position: i,
            })),
            // Id media yang sudah diunggah lewat builder. `media_draf` untuk
            // media tingkat soal; `media_opsi_draf` untuk media pilihan.
            // Pada soal BARU keduanya masih draf (question_id NULL) dan
            // ditautkan server saat simpan. Pada soal yang sudah ada, media
            // itu sudah bertuan - server hanya menautkan yang masih NULL,
            // jadi mengirimnya ulang tidak berbahaya.
            media_draf: mediaSoal.map((m) => m.id),
            media_opsi_draf: opsi.flatMap((o) => (o.media ?? []).map((m) => m.id)),
        }));

        // Tidak ada penanda AI yang perlu dibersihkan setelah simpan: hasil
        // AI tidak pernah mengisi form ini (lihat GenerateAi.tsx).
        if (baru) {
            form.post('/guru/bank-soal');
            return;
        }
        form.put(`/guru/bank-soal/${soal.id}`);
    };

    const galat = (k: string) => (form.errors as Record<string, string>)[k];

    /**
     * Galat pertama yang kuncinya BERAWALAN dengan `awalan`.
     *
     * Galat datang dari dua tempat dan tidak selalu menunjuk field teratas -
     * `prompt_json` dari SkemaJenisSoal untuk soal rumpang, `opsi.0...` untuk
     * satu baris pilihan - sementara kotak yang menampilkannya cuma satu.
     * Tanpa pencarian berawalan, Guru melihat "Soal belum tersimpan" di atas
     * form dan tidak menemukan satu pun tanda merah di mana pun.
     */
    const galatAwalan = (...awalan: string[]): string | undefined => {
        const e = form.errors as Record<string, string>;

        for (const a of awalan) {
            const kunci = Object.keys(e).find((k) => k === a || k.startsWith(a + '.'));

            if (kunci !== undefined) {
                return e[kunci];
            }
        }

        return undefined;
    };

    /**
     * Galat validasi satu pilihan jawaban.
     *
     * Server memberi nama galat menurut POSISI larik (`opsi.0.content_json.teks`),
     * jadi indeks barisnya yang dipakai - bukan `option_key`. Teks pilihan itu
     * satu-satunya yang bisa kosong pada satu baris (kunci/media tidak wajib),
     * sehingga satu kunci itulah yang dibaca, dengan cadangan ke galat tingkat
     * baris untuk bentuk yang lebih jarang.
     */
    const galatOpsi = (indeks: number): string | undefined => {
        const e = form.errors as Record<string, string>;
        return (
            e[`opsi.${indeks}.content_json.teks`] ??
            e[`opsi.${indeks}.content_json`] ??
            e[`opsi.${indeks}.option_key`]
        );
    };

    // Ada galat berarti Inertia TIDAK berpindah laman: ia kembali ke builder
    // yang sama dengan `form.errors` terisi. Tanpa satu tanda di atas form,
    // satu-satunya yang berubah hanyalah ketukan balik yang tak terlihat - dan
    // guru menyimpulkan "Simpan tidak terjadi apa-apa", padahal permintaannya
    // terkirim, ditolak, dan alasannya memang ada di layar.
    const adaGalat = Object.keys(form.errors).length > 0;

    // ------------------------------------------------------------ Render
    return (
        <GuruLayout
            judul={baru ? 'Soal baru' : `Soal ${soal.kode}`}
            breadcrumb={`/guru/bank-soal${baru ? ' / baru' : ' / ' + soal.kode}`}
            aksi={
                <>
                    <Link
                        href="/guru/bank-soal"
                        className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
                    >
                        Batal
                    </Link>
                    <Tombol
                        nada="pri"
                        onClick={kirim}
                        disabled={form.processing || !bolehUbah || idMapel(mapelId) === null}
                        title={
                            idMapel(mapelId) === null
                                ? 'Belum ada mata pelajaran yang bisa dipilih'
                                : undefined
                        }
                    >
                        {baru ? 'Simpan ke bank' : 'Simpan perubahan'}
                    </Tombol>
                </>
            }
        >
            <Head title={baru ? 'Soal baru' : `Soal ${soal.kode}`} />

            {/* Simpan yang ditolak validasi TIDAK berpindah laman - Inertia
                mengembalikan builder yang sama dengan galat per field. Satu
                kalimat di sini memastikan Guru tahu soalnya BELUM tersimpan,
                lalu mencari tanda merah di field yang disebut. */}
            {adaGalat && (
                <div className="mb-3">
                    <Peringatan>
                        Soal belum tersimpan. Periksa isian yang bertanda merah di bawah
                        &mdash; biasanya ada pilihan jawaban yang masih kosong.
                    </Peringatan>
                </div>
            )}

            {/* Soal Guru lain boleh dibuka, tetapi TIDAK boleh disimpan - dan
                itu dinyatakan di layar, bukan baru ketahuan saat menekan
                Simpan. Yang menegakkannya tetap QuestionPolicy (403). */}
            {!bolehUbah && (
                <div className="mb-3">
                    <Inbox>
                        Soal ini ditulis{' '}
                        <b>{soal?.penulis ?? 'Guru lain'}</b>. Anda dapat membacanya dan
                        memakainya di paket, tetapi hanya penulisnya yang dapat mengubahnya.
                        Bila perlu disesuaikan, duplikat dulu dari laman bank soal.
                    </Inbox>
                </div>
            )}

            <div className="mb-3 flex flex-wrap gap-1 rounded-wf border border-line bg-surface p-1">
                {jenis.map((j) => (
                    <button
                        key={j.nilai}
                        type="button"
                        disabled={!bolehUbah}
                        onClick={() => setTab(j.nilai)}
                        className={
                            'rounded-wf px-3 py-1.5 text-xs font-medium transition-colors disabled:cursor-not-allowed ' +
                            (tab === j.nilai
                                ? 'bg-accent-soft text-accent'
                                : 'text-ink-2 hover:bg-surface-2 disabled:text-ink-3/50')
                        }
                    >
                        {j.label}
                    </button>
                ))}
            </div>

            {/* Guru tanpa satu pun penugasan mengajar. Daftar mapelnya kosong,
                dan tanpa kalimat ini ia hanya melihat `<select>` hampa lalu
                menekan Simpan dan menerima penolakan yang tidak bisa
                ditindaklanjuti dari sisinya - yang harus menugaskannya adalah
                Admin, di laman Mata pelajaran. */}
            {bolehUbah && mapel.length === 0 && (
                <div className="mb-3">
                    <Inbox>
                        Anda belum ditugaskan ke mata pelajaran mana pun, jadi soal belum bisa
                        disimpan. Minta Admin menugaskan Anda di laman{' '}
                        <b>Mata pelajaran</b>, lalu buka kembali laman ini.
                    </Inbox>
                </div>
            )}

            <div className="space-y-3">
                <EditorSoal
                    key={`prompt-${tab}`}
                    nilai={prompt}
                    padaUbah={setPrompt}
                    galat={galat('prompt_json.teks')}
                    placeholder="Tulis soal di sini…"
                />

                {/* Media pada SOAL - stimulus gambar/audio/video yang tampil
                    di atas pertanyaan. Untuk soal jenis "media", inilah
                    berkas utamanya. */}
                <Kotak judul="Gambar / berkas soal">
                    <p className="mb-2 text-[11px] text-ink-3">
                        Tampil di atas teks soal untuk semua jenis. Gambar: JPG, PNG, WEBP, GIF
                        (maks 2&nbsp;MB). Audio sampai 5&nbsp;MB, video sampai 20&nbsp;MB.
                    </p>
                    <MediaSoal
                        media={mediaSoal}
                        padaUbah={setMediaSoal}
                        soalId={soal?.id ?? null}
                        bolehUbah={bolehUbah}
                    />
                </Kotak>

                {tab === 'pilihan_ganda' && (
                    <OpsiBuilder
                        opsi={opsi}
                        kunci={kunciOpsi}
                        padaUbah={setOpsi}
                        padaKunci={setKunciOpsi}
                        tunggal
                        galat={galat('answer_key_json')}
                        galatOpsi={galatOpsi}
                        soalId={soal?.id ?? null}
                        bolehUbah={bolehUbah}
                    />
                )}

                {tab === 'jawaban_ganda' && (
                    <>
                        <OpsiBuilder
                            opsi={opsi}
                            kunci={kunciOpsi}
                            padaUbah={setOpsi}
                            padaKunci={setKunciOpsi}
                            tunggal={false}
                            galat={galat('answer_key_json')}
                            galatOpsi={galatOpsi}
                            soalId={soal?.id ?? null}
                            bolehUbah={bolehUbah}
                        />
                        <label className="flex items-center gap-2 text-xs text-ink-2">
                            <input
                                type="checkbox"
                                checked={skorParsial}
                                onChange={(e) => setSkorParsial(e.target.checked)}
                                disabled={!bolehUbah}
                            />
                            Beri skor sebagian bila siswa memilih sebagian jawaban benar
                        </label>
                    </>
                )}

                {tab === 'benar_salah' && (
                    <OpsiBuilder
                        opsi={opsi.length === 2 ? opsi : BENAR_SALAH}
                        kunci={kunciOpsi}
                        padaUbah={(o) => setOpsi(o)}
                        padaKunci={setKunciOpsi}
                        tunggal
                        galat={galat('answer_key_json')}
                        galatOpsi={galatOpsi}
                        soalId={soal?.id ?? null}
                        bolehUbah={bolehUbah}
                    />
                )}

                {tab === 'isian_singkat' && (
                    <Kotak
                        judul="Jawaban yang diterima"
                        aksi={
                            <Tombol onClick={() => setVarian([...varian, ''])}>Tambah varian</Tombol>
                        }
                    >
                        <p className="mb-2 text-[11px] text-ink-3">
                            Tulis setiap ejaan yang harus dianggap benar. Pencocokan mengabaikan
                            huruf besar/kecil dan spasi di tepi.
                        </p>
                        {galat('answer_key_json') && (
                            <p className="mb-2 text-[11px] text-bad">{galat('answer_key_json')}</p>
                        )}
                        <div className="space-y-2">
                            {varian.map((v, i) => (
                                <div key={i} className="flex gap-2">
                                    <input
                                        value={v}
                                        onChange={(e) =>
                                            setVarian(
                                                varian.map((x, j) => (j === i ? e.target.value : x)),
                                            )
                                        }
                                        placeholder={`Varian ${i + 1}`}
                                        className={kelasInput}
                                    />
                                    <Tombol
                                        nada="bahaya"
                                        onClick={() => setVarian(varian.filter((_, j) => j !== i))}
                                        disabled={varian.length <= 1}
                                    >
                                        Buang
                                    </Tombol>
                                </div>
                            ))}
                        </div>
                    </Kotak>
                )}

                {tab === 'isian_rumpang' && (
                    <RumpangBuilder
                        teks={prompt}
                        rumpang={rumpang}
                        padaUbah={setRumpang}
                        padaTeks={setPrompt}
                        galat={galatAwalan('answer_key_json', 'prompt_json')}
                        bolehUbah={bolehUbah}
                    />
                )}

                {tab === 'menjodohkan' && (
                    <ButirJodohBuilder
                        butir={butir}
                        pasangan={pasangan}
                        padaUbah={setButir}
                        padaPasangan={setPasangan}
                        galat={galat('answer_key_json') ?? galat('butir')}
                    />
                )}

                {tab === 'mengurutkan' && (
                    <UrutanBuilder
                        butir={butir}
                        urutan={urutan}
                        padaUbah={setButir}
                        padaUrutan={setUrutan}
                        galat={galat('answer_key_json') ?? galat('butir')}
                    />
                )}

                {tab === 'angka' && (
                    <Kotak judul="Jawaban angka">
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Field label="Nilai benar" galat={galat('answer_key_json')}>
                                <input
                                    value={nilai}
                                    onChange={(e) => setNilai(e.target.value)}
                                    inputMode="decimal"
                                    placeholder="mis. 3.14"
                                    className={kelasInput}
                                />
                            </Field>
                            <Field
                                label="Toleransi"
                                petunjuk="Selisih yang masih dianggap benar"
                            >
                                <input
                                    value={toleransi}
                                    onChange={(e) => setToleransi(e.target.value)}
                                    inputMode="decimal"
                                    className={kelasInput}
                                />
                            </Field>
                            <Field label="Satuan" petunjuk="Opsional, mis. cm">
                                <input
                                    value={satuan}
                                    onChange={(e) => setSatuan(e.target.value)}
                                    className={kelasInput}
                                />
                            </Field>
                        </div>
                    </Kotak>
                )}

                {tab === 'uraian' && (
                    <>
                        <RubrikBuilder
                            rubrik={rubrik}
                            padaUbah={setRubrik}
                            galat={galat('rubrik') ?? galat('answer_key_json')}
                            // Server menolak rubrik yang jumlahnya berbeda dari
                            // bobot butir (lihat `SkemaJenisSoal`). Angka bobot
                            // yang belum diisi berarti 1 - nilai yang sama yang
                            // dikirim ke server.
                            bobot={parseFloat(bobot) || 1}
                        />
                        <Inbox>
                            Soal uraian dinilai manual, dan kuncinya <b>adalah rubriknya</b> —
                            bukan jawaban benar/salah. Koreksi jawaban uraian ada di konsol ini
                            pada Fase 8.
                        </Inbox>
                    </>
                )}

                {tab === 'media' && (
                    <>
                        <Kotak judul="Mekanisme jawaban soal media">
                            <p className="mb-2 text-[11px] text-ink-3">
                                Soal media adalah soal yang stimulusnya berupa berkas. Cara
                                menjawabnya tetap salah satu dari yang sudah ada.
                            </p>
                            <select
                                value={mekanisme}
                                onChange={(e) => setMekanisme(e.target.value)}
                                disabled={!bolehUbah}
                                className={kelasInput + ' max-w-xs'}
                            >
                                <option value="pilihan">Pilihan — satu jawaban benar</option>
                                <option value="isian">Isian singkat</option>
                                <option value="urut">Mengurutkan</option>
                            </select>
                        </Kotak>

                        {mekanisme === 'isian' && (
                            <Kotak judul="Jawaban yang diterima">
                                <div className="space-y-2">
                                    {varian.map((v, i) => (
                                        <input
                                            key={i}
                                            value={v}
                                            onChange={(e) =>
                                                setVarian(
                                                    varian.map((x, j) =>
                                                        j === i ? e.target.value : x,
                                                    ),
                                                )
                                            }
                                            className={kelasInput}
                                        />
                                    ))}
                                </div>
                                <Tombol onClick={() => setVarian([...varian, ''])}>
                                    Tambah varian
                                </Tombol>
                            </Kotak>
                        )}

                        {mekanisme === 'pilihan' && (
                            <OpsiBuilder
                                opsi={opsi}
                                kunci={kunciOpsi}
                                padaUbah={setOpsi}
                                padaKunci={setKunciOpsi}
                                tunggal
                                galat={galat('answer_key_json')}
                                galatOpsi={galatOpsi}
                                soalId={soal?.id ?? null}
                                bolehUbah={bolehUbah}
                            />
                        )}

                        {mekanisme === 'urut' && (
                            <UrutanBuilder
                                butir={butir}
                                urutan={urutan}
                                padaUbah={setButir}
                                padaUrutan={setUrutan}
                                galat={galat('answer_key_json')}
                            />
                        )}

                        <Inbox>
                            Berkas media (gambar, audio, video) diunggah ke penyimpanan privat
                            sekolah, bukan ditautkan ke layanan luar — sehingga soal tetap utuh
                            walau tautan luar itu mati. Unggah berkasnya di kotak{' '}
                            <b>Gambar / berkas soal</b> di atas.
                        </Inbox>
                    </>
                )}

                <EditorSoal
                    key={`pembahasan-${tab}`}
                    nilai={pembahasan}
                    padaUbah={setPembahasan}
                    label="Pembahasan (opsional)"
                    tinggi="min-h-20"
                    placeholder="Mengapa jawabannya begitu…"
                />

                <Kotak judul="Detail soal">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Field label="Mata pelajaran" galat={galat('subject_id')}>
                            <select
                                value={mapelId}
                                onChange={(e) => setMapelId(e.target.value)}
                                disabled={!bolehUbah}
                                className={kelasInput}
                            >
                                {mapel.map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.nama}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field
                            label="Kode soal"
                            galat={galat('code')}
                            petunjuk="Kosongkan agar sistem memberi kode"
                        >
                            <input
                                value={kode}
                                onChange={(e) => setKode(e.target.value)}
                                disabled={!bolehUbah}
                                placeholder="otomatis"
                                className={kelasInput}
                            />
                        </Field>

                        <Field label="Judul" galat={galat('title')} petunjuk="Ringkasan untuk daftar">
                            <input
                                value={judul}
                                onChange={(e) => setJudul(e.target.value)}
                                disabled={!bolehUbah}
                                className={kelasInput}
                            />
                        </Field>

                        <Field label="Bobot" galat={galat('scoring_config_json.bobot')}>
                            <input
                                value={bobot}
                                onChange={(e) => setBobot(e.target.value)}
                                inputMode="decimal"
                                disabled={!bolehUbah}
                                className={kelasInput}
                            />
                        </Field>

                        <Field label="Tingkat">
                            <select
                                value={tingkat}
                                onChange={(e) => setTingkat(e.target.value)}
                                disabled={!bolehUbah}
                                className={kelasInput}
                            >
                                <option value="mudah">Mudah</option>
                                <option value="sedang">Sedang</option>
                                <option value="sulit">Sulit</option>
                            </select>
                        </Field>

                        <Field label="Status">
                            <select
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                                disabled={!bolehUbah}
                                className={kelasInput}
                            >
                                <option value="draft">Draf — masih disunting</option>
                                <option value="siap">Siap — boleh dipakai Guru lain</option>
                                <option value="arsip">Arsip</option>
                            </select>
                        </Field>
                    </div>

                    <div className="mt-3 flex items-center gap-2 text-[11px] text-ink-3">
                        <Chip nada="info">{jenis.find((j) => j.nilai === tab)?.label}</Chip>
                        <span>
                            {tab === 'uraian'
                                ? 'Dinilai manual — rubrik wajib diisi.'
                                : 'Dinilai otomatis oleh mesin.'}
                        </span>
                    </div>
                </Kotak>

                {paketTujuan && (
                    <Inbox>
                        Soal ini akan ditambahkan ke paket setelah disimpan. Paket lain dapat
                        dipilih dari laman paket.
                    </Inbox>
                )}
            </div>
        </GuruLayout>
    );
}
