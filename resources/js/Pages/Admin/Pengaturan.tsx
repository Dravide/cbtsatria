import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Field, Inbox, Kotak, Peringatan, Tombol, kelasInput } from '@/Components/ui';

/**
 * Laman 23 - Pengaturan sekolah. Wireframe plate 23.
 *
 * Lima tab: Ujian, Keamanan, Nilai, AI, Notifikasi.
 *
 * ---------------------------------------------------------------------
 * Yang paling penting di laman ini bukan field-nya, melainkan kalimatnya
 * ---------------------------------------------------------------------
 * "Nilai di sini adalah bawaan untuk event baru. Event yang sudah dibuat
 * menyimpan nilainya sendiri dan tidak ikut berubah."
 *
 * Itulah yang membuat laman ini aman diubah kapan saja, bahkan di tengah
 * musim ujian. Tanpa jaminan itu, Admin yang mengubah ambang pelanggaran akan
 * mengubah aturan main peserta yang sedang mengerjakan - dan tidak ada satu
 * pun tanda di layar yang memberi tahu dia.
 *
 * ---------------------------------------------------------------------
 * Tab AI punya form dan endpoint SENDIRI
 * ---------------------------------------------------------------------
 * Dan itu bukan kerapian. Form Pengaturan mengirim sebelas kunci `required`
 * dan menulis kolom `settings` secara UTUH. Menyatukan AI ke sana berarti:
 * setiap penyimpanan tab Ujian ikut mengirim `api_key` - padahal kolom itu
 * selalu kosong di peramban (kuncinya memang tidak pernah dikirim ke sini) -
 * dan "Uji koneksi" menjadi aksi yang menyimpan. Memisahkannya membuat setiap
 * tab punya satu niat.
 */
type Props = {
    pengaturan: {
        durasi_token_menit: number;
        ambang_pelanggaran: number;
        simpan_otomatis_detik: number;
        kkm: number;
        kunci_klik_kanan: boolean;
        peringatkan_keluar_tab: boolean;
        kunci_setelah_ambang: boolean;
        izinkan_tempel_uraian: boolean;
        deteksi_layar_terbagi: boolean;
        deteksi_jendela_mengambang: boolean;
        tanda_air_ujian: boolean;
    };
    ai: {
        base_url: string;
        model: string;
        aktif: boolean;
        batas_token: number;
        ada_kunci: boolean;
        petunjuk_kunci: string | null;
    };
};

const TAB = ['Ujian', 'Keamanan', 'Nilai', 'AI', 'Notifikasi'] as const;

type Tab = (typeof TAB)[number];

export default function Pengaturan({ pengaturan, ai }: Props) {
    const [tab, setTab] = useState<Tab>('Ujian');

    const { data, setData, put, processing, errors } = useForm(pengaturan);

    // Form kedua, bukan satu form dengan lima tab: tab AI tidak boleh ikut
    // mengirim delapan field tab lain, dan tab lain tidak boleh ikut
    // mengirim kunci API.
    const formAi = useForm({
        base_url: ai.base_url,
        model: ai.model,
        // Selalu kosong saat laman dibuka - kuncinya tidak pernah dikirim ke
        // peramban. Kosong berarti "jangan ubah", bukan "kosongkan".
        api_key: '',
        aktif: ai.aktif,
        batas_token: ai.batas_token,
    });

    const [menguji, setMenguji] = useState(false);
    const [hasilUji, setHasilUji] = useState<{ ok: boolean; pesan: string } | null>(null);

    const kirim = (e: React.FormEvent) => {
        e.preventDefault();
        put('/admin/pengaturan');
    };

    const simpanAi = () => {
        formAi.put('/admin/pengaturan/ai', { preserveScroll: true });
    };

    /**
     * Uji koneksi ke penyedia dengan isian di layar SEKARANG.
     *
     * Tidak menyimpan apa pun: Admin yang menguji lalu membatalkan tidak
     * meninggalkan konfigurasi setengah jadi.
     */
    const ujiKoneksi = async () => {
        setMenguji(true);
        setHasilUji(null);

        try {
            const cocok = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

            const tanggapan = await fetch('/admin/pengaturan/ai/uji', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': cocok ? decodeURIComponent(cocok[1]) : '',
                },
                body: JSON.stringify({
                    base_url: formAi.data.base_url,
                    model: formAi.data.model,
                    api_key: formAi.data.api_key,
                }),
            });

            if (tanggapan.status === 419) {
                window.location.reload();

                return;
            }

            const isi = await tanggapan.json().catch(() => ({}));

            setHasilUji({
                ok: tanggapan.ok,
                pesan: String(
                    (isi as { pesan?: string }).pesan ??
                        'Penyedia menjawab, tetapi pesannya tidak bisa dibaca.',
                ),
            });
        } catch {
            setHasilUji({
                ok: false,
                pesan: 'Tidak bisa menghubungi server. Periksa sambungan, lalu coba lagi.',
            });
        } finally {
            setMenguji(false);
        }
    };

    const simpan = tab === 'AI' ? simpanAi : () => put('/admin/pengaturan');
    const sedangSimpan = tab === 'AI' ? formAi.processing : processing;

    return (
        <AdminLayout
            judul="Pengaturan sekolah"
            breadcrumb="/admin/pengaturan"
            aksi={
                // Tab Notifikasi tidak punya satu pun kolom yang bisa
                // disimpan. Tombol Simpan di sana tidak akan mengubah apa
                // pun, dan tombol yang ditekan tanpa hasil membuat Admin
                // mengira pengaturannya tersimpan.
                tab === 'Notifikasi' ? null : (
                    <Tombol type="button" nada="pri" disabled={sedangSimpan} onClick={simpan}>
                        {sedangSimpan ? 'Menyimpan...' : 'Simpan'}
                    </Tombol>
                )
            }
        >
            <Head title="Pengaturan" />

            <div className="space-y-4">
                <div className="flex flex-wrap gap-1 border-b border-line">
                    {TAB.map((t) => (
                        <button
                            key={t}
                            type="button"
                            onClick={() => setTab(t)}
                            className={
                                '-mb-px border-b-2 px-3 py-2 text-sm ' +
                                (tab === t
                                    ? 'border-accent font-medium text-accent'
                                    : 'border-transparent text-ink-2 hover:text-ink')
                            }
                        >
                            {t}
                        </button>
                    ))}
                </div>

                {tab !== 'AI' && (
                    <form onSubmit={kirim} className="space-y-4">
                        {tab === 'Ujian' && (
                            <Kotak judul="Perilaku ujian">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field
                                        label="Durasi token berlaku (menit)"
                                        galat={errors.durasi_token_menit}
                                        petunjuk="Berapa lama token sesi tetap sah setelah dibuka pengawas."
                                    >
                                        <input
                                            type="number"
                                            min={1}
                                            max={240}
                                            value={data.durasi_token_menit}
                                            onChange={(e) =>
                                                setData('durasi_token_menit', Number(e.target.value))
                                            }
                                            className={kelasInput + ' font-mono'}
                                        />
                                    </Field>

                                    <Field
                                        label="Simpan otomatis tiap (detik)"
                                        galat={errors.simpan_otomatis_detik}
                                        petunjuk="Jeda antar penyimpanan jawaban saat siswa mengerjakan."
                                    >
                                        <input
                                            type="number"
                                            min={5}
                                            max={300}
                                            value={data.simpan_otomatis_detik}
                                            onChange={(e) =>
                                                setData('simpan_otomatis_detik', Number(e.target.value))
                                            }
                                            className={kelasInput + ' font-mono'}
                                        />
                                    </Field>

                                    <Field
                                        label="Ambang pelanggaran bawaan"
                                        galat={errors.ambang_pelanggaran}
                                        petunjuk="Jumlah pelanggaran sebelum ujian siswa terkunci."
                                    >
                                        <input
                                            type="number"
                                            min={1}
                                            max={50}
                                            value={data.ambang_pelanggaran}
                                            onChange={(e) =>
                                                setData('ambang_pelanggaran', Number(e.target.value))
                                            }
                                            className={kelasInput + ' font-mono'}
                                        />
                                    </Field>
                                </div>
                            </Kotak>
                        )}

                        {tab === 'Keamanan' && (
                            <>
                                <Kotak judul="Kebijakan proteksi ujian">
                                    <div className="space-y-2.5">
                                        <Centang
                                            label="Kunci klik kanan dan salin"
                                            nilai={data.kunci_klik_kanan}
                                            padaUbah={(v) => setData('kunci_klik_kanan', v)}
                                        />
                                        <Centang
                                            label="Peringatkan saat keluar tab"
                                            nilai={data.peringatkan_keluar_tab}
                                            padaUbah={(v) => setData('peringatkan_keluar_tab', v)}
                                        />
                                        <Centang
                                            label="Kunci ujian setelah ambang terlampaui"
                                            nilai={data.kunci_setelah_ambang}
                                            padaUbah={(v) => setData('kunci_setelah_ambang', v)}
                                        />
                                        <Centang
                                            label="Izinkan tempel teks pada soal uraian"
                                            nilai={data.izinkan_tempel_uraian}
                                            padaUbah={(v) => setData('izinkan_tempel_uraian', v)}
                                        />
                                    </div>

                                    <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                                        Menyalakan proteksi berlebih pada soal uraian menyulitkan
                                        siswa menjawab, dan yang paling sering terjadi adalah siswa
                                        mengetik ulang kalimat panjang dari buku - bukan menyalin
                                        jawaban.
                                    </p>
                                </Kotak>

                                <Kotak judul="Layar dan tangkapan layar">
                                    <div className="space-y-2.5">
                                        <Centang
                                            label="Deteksi layar terbagi (split screen)"
                                            nilai={data.deteksi_layar_terbagi}
                                            padaUbah={(v) => setData('deteksi_layar_terbagi', v)}
                                        />
                                        <Centang
                                            label="Deteksi jendela mengambang (floating window)"
                                            nilai={data.deteksi_jendela_mengambang}
                                            padaUbah={(v) => setData('deteksi_jendela_mengambang', v)}
                                        />
                                        <Centang
                                            label="Tanda air nama dan nomor induk di atas soal"
                                            nilai={data.tanda_air_ujian}
                                            padaUbah={(v) => setData('tanda_air_ujian', v)}
                                        />
                                    </div>

                                    <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                                        Dua yang pertama hanya berlaku pada ujian yang menuntut layar
                                        penuh - tanpa layar penuh, larangan membelah layar tidak punya
                                        dasar. Layar terbagi diukur dari <b>lebar</b> yang menyusut,
                                        jendela mengambang dari <b>tinggi</b>: dua sisi berbeda, jadi
                                        keduanya dipisah. Ponsel lipat dan papan tik bisa menyempitkan
                                        tinggi tanpa siapa pun membelah layar, dan memaafkannya tidak
                                        boleh ikut memaafkan layar yang benar-benar terbagi.
                                    </p>

                                    <p className="mt-2 text-[11px] leading-relaxed text-ink-3">
                                        Tangkapan layar <b>tidak bisa diblokir peramban</b> - tidak ada
                                        API yang memberitahu tombolnya ditekan. Yang bisa dikerjakan
                                        hanyalah membuat hasilnya menyebut pemiliknya, dan itulah tanda
                                        air. Mematikannya berarti tangkapan layar yang beredar tidak
                                        menunjuk siapa pun. Tanda air <b>tidak</b> menunggu layar
                                        penuh: tangkapan layar bisa diambil dari ujian mana pun.
                                    </p>
                                </Kotak>
                            </>
                        )}

                        {tab === 'Nilai' && (
                            <Kotak judul="Ambang nilai">
                                <Field
                                    label="Nilai minimum tuntas (KKM)"
                                    galat={errors.kkm}
                                    petunjuk="Dipakai laporan untuk menandai siswa tuntas atau belum."
                                >
                                    <input
                                        type="number"
                                        min={0}
                                        max={100}
                                        value={data.kkm}
                                        onChange={(e) => setData('kkm', Number(e.target.value))}
                                        className={kelasInput + ' font-mono'}
                                    />
                                </Field>
                            </Kotak>
                        )}

                        {tab === 'Notifikasi' && (
                            <Kotak judul="Notifikasi">
                                <p className="text-sm text-ink-3">
                                    Belum ada notifikasi yang bisa diatur. Pemberitahuan ke
                                    pengawas dibangun bersama laman monitoring, dan sampai itu
                                    ada, tab ini hanya memberi tahu bahwa pengaturannya memang
                                    belum tersedia &mdash; bukan belum tersimpan.
                                </p>
                            </Kotak>
                        )}

                        <Inbox>
                            Nilai di sini adalah bawaan untuk event baru. Event yang sudah dibuat
                            menyimpan nilainya sendiri dan tidak ikut berubah &mdash; jadi mengubah
                            ambang di sini tidak menyentuh ujian yang sedang berjalan.
                        </Inbox>
                    </form>
                )}

                {tab === 'AI' && (
                    <div className="space-y-4">
                        <Kotak judul="Penyedia AI untuk Generate soal">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field
                                    label="Base URL"
                                    galat={formAi.errors.base_url}
                                    petunjuk="Alamat API, tanpa /chat/completions. Mis. https://api.openai.com/v1"
                                >
                                    <input
                                        value={formAi.data.base_url}
                                        onChange={(e) => formAi.setData('base_url', e.target.value)}
                                        placeholder="https://api.openai.com/v1"
                                        autoComplete="off"
                                        className={kelasInput + ' font-mono'}
                                    />
                                </Field>

                                <Field
                                    label="Model"
                                    galat={formAi.errors.model}
                                    petunjuk="Nama model persis seperti di dokumentasi penyedia."
                                >
                                    <input
                                        value={formAi.data.model}
                                        onChange={(e) => formAi.setData('model', e.target.value)}
                                        placeholder="mis. gpt-4o-mini"
                                        autoComplete="off"
                                        className={kelasInput + ' font-mono'}
                                    />
                                </Field>

                                <Field
                                    label="API key"
                                    galat={formAi.errors.api_key}
                                    petunjuk={
                                        formAi.data.api_key === ''
                                            ? 'Kosongkan bila tidak ingin mengubahnya.'
                                            : undefined
                                    }
                                >
                                    <input
                                        type="password"
                                        value={formAi.data.api_key}
                                        onChange={(e) => formAi.setData('api_key', e.target.value)}
                                        placeholder={ai.petunjuk_kunci ?? 'belum diisi'}
                                        autoComplete="new-password"
                                        className={kelasInput + ' font-mono'}
                                    />
                                </Field>

                                <Field
                                    label="Batas panjang jawaban (token)"
                                    galat={formAi.errors.batas_token}
                                    petunjuk="1500 cukup untuk satu soal uraian beserta rubriknya."
                                >
                                    <input
                                        type="number"
                                        min={256}
                                        max={8000}
                                        value={formAi.data.batas_token}
                                        onChange={(e) =>
                                            formAi.setData('batas_token', Number(e.target.value))
                                        }
                                        className={kelasInput + ' font-mono'}
                                    />
                                </Field>
                            </div>

                            <div className="mt-3 space-y-2.5">
                                <Centang
                                    label="Nyalakan Generate AI di bank soal"
                                    nilai={formAi.data.aktif}
                                    padaUbah={(v) => formAi.setData('aktif', v)}
                                />

                                <p className="text-[11px] text-ink-3">
                                    {ai.ada_kunci
                                        ? `Kunci API tersimpan: ${ai.petunjuk_kunci}`
                                        : 'Belum ada API key tersimpan.'}
                                </p>
                            </div>

                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                <Tombol onClick={ujiKoneksi} disabled={menguji}>
                                    {menguji ? 'Menghubungi...' : 'Uji koneksi'}
                                </Tombol>
                                <span className="text-[11px] text-ink-3">
                                    Menguji isian di layar ini tanpa menyimpannya.
                                </span>
                            </div>

                            {hasilUji && (
                                <div className="mt-3">
                                    {hasilUji.ok ? (
                                        <Inbox>{hasilUji.pesan}</Inbox>
                                    ) : (
                                        <Peringatan>{hasilUji.pesan}</Peringatan>
                                    )}
                                </div>
                            )}
                        </Kotak>

                        <Peringatan>
                            Selama AI aktif, setiap klik <b>Generate</b> di laman bank soal
                            mengirim permintaan ke alamat di atas dan <b>ditagih ke akun
                            penyedia itu</b>. Soal yang dihasilkan hanya mengisi form &mdash;
                            tidak ada yang tersimpan sampai Guru menekan Simpan.
                        </Peringatan>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}

function Centang({
    label,
    nilai,
    padaUbah,
}: {
    label: string;
    nilai: boolean;
    padaUbah: (v: boolean) => void;
}) {
    return (
        <label className="flex cursor-pointer items-center gap-2.5 text-sm text-ink">
            <input
                type="checkbox"
                checked={nilai}
                onChange={(e) => padaUbah(e.target.checked)}
                className="size-4 accent-accent"
            />
            {label}
        </label>
    );
}
