import { Head, router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Field, Inbox, Kotak, Tombol, kelasInput } from '@/Components/ui';

/**
 * Identitas aplikasi - tab nama, deskripsi, warna, dan ikon.
 *
 * ---------------------------------------------------------------------
 * Yang diatur di sini adalah merek SEKOLAH, bukan merek produk
 * ---------------------------------------------------------------------
 * Nama yang tercetak di judul tab dan di layar beranda ponsel siswa adalah
 * nama sekolahnya sendiri. Menaruhnya di config berarti mengganti nama
 * sekolah menuntut deploy, dan satu basis data yang memuat lebih dari satu
 * sekolah akan menampilkan nama sekolah A di perangkat sekolah B.
 *
 * ---------------------------------------------------------------------
 * `noindex` TIDAK ada di layar ini, dan itu disengaja
 * ---------------------------------------------------------------------
 * Aplikasi ini memuat nilai, presensi, dan jawaban ujian siswa. Halaman yang
 * lolos ke indeks mesin pencari membocorkan data anak ke mesin yang
 * menyimpannya bertahun-tahun, dan itu tidak bisa ditarik kembali. Toggle-nya
 * karena itu tidak pernah dibuat - bukan disembunyikan di bawah.
 *
 * ---------------------------------------------------------------------
 * Ikon diunggah SATU PER SATU, bukan bersama form
 * ---------------------------------------------------------------------
 * Mengikuti pola logo sekolah: berkas dikirim lebih dulu, baru simpan form.
 * Menggabungkannya berarti setiap kali Admin memperbaiki salah ketik nama,
 * tiga berkas ikon ikut terunggah ulang.
 */
type Props = {
    identitas: {
        nama: string;
        nama_pendek: string;
        deskripsi: string;
        warna: string;
        favicon_url: string | null;
        favicon_png_url: string | null;
        ikon_url: string | null;
        ikon_sizes: string | null;
        apple_url: string;
    };
    bawaan: {
        ikon_url: string;
        apple_url: string;
        warna: string;
    };
};

/** Jenis ikon, dan label yang dipakai Admin - bukan nama kolomnya. */
const IKON = [
    {
        jenis: 'favicon',
        label: 'Favicon tab',
        petunjuk: 'PNG, SVG, atau ICO, maksimal 512 KB. Tampil di tab peramban.',
        accept: 'image/png,image/svg+xml,.png,.svg,.ico',
    },
    {
        jenis: 'ikon',
        label: 'Ikon layar beranda',
        petunjuk: 'PNG persegi, minimal 192x192 piksel, maksimal 1 MB.',
        accept: 'image/png,.png',
    },
    {
        jenis: 'apple',
        label: 'Ikon iOS',
        petunjuk: 'PNG 180x180 piksel. iOS tidak membaca ikon manifest.',
        accept: 'image/png,.png',
    },
] as const;

type JenisIkon = (typeof IKON)[number]['jenis'];

export default function Identitas({ identitas, bawaan }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        nama: identitas.nama,
        nama_pendek: identitas.nama_pendek,
        deskripsi: identitas.deskripsi,
        warna: identitas.warna,
    });

    const [sedangUnggah, setSedangUnggah] = useState<JenisIkon | null>(null);

    const simpan = () => {
        put('/admin/sekolah/identitas', { preserveScroll: true });
    };

    const unggah = (jenis: JenisIkon, berkas: File) => {
        const badan = new FormData();
        badan.append('berkas', berkas);

        setSedangUnggah(jenis);

        router.post(`/admin/sekolah/identitas/ikon/${jenis}`, badan, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => setSedangUnggah(null),
        });
    };

    const hapus = (jenis: JenisIkon) => {
        router.delete(`/admin/sekolah/identitas/ikon/${jenis}`, { preserveScroll: true });
    };

    /** Url yang sedang berlaku untuk satu jenis ikon, atau null bila bawaan. */
    const urlIkon = (jenis: JenisIkon): string | null => {
        if (jenis === 'favicon') {
            return identitas.favicon_url;
        }
        if (jenis === 'ikon') {
            return identitas.ikon_url;
        }

        return identitas.apple_url === bawaan.apple_url ? null : identitas.apple_url;
    };

    return (
        <AdminLayout
            judul="Identitas aplikasi"
            breadcrumb="/admin/sekolah · Identitas aplikasi"
            aksi={
                <Tombol type="button" nada="pri" disabled={processing} onClick={simpan}>
                    {processing ? 'Menyimpan...' : 'Simpan'}
                </Tombol>
            }
        >
            <Head title="Identitas aplikasi" />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    simpan();
                }}
                className="grid gap-4 lg:grid-cols-[2fr_1fr]"
            >
                <div className="space-y-4">
                    <Kotak judul="Nama dan deskripsi">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field
                                label="Nama situs"
                                galat={errors.nama}
                                petunjuk="Tampil di judul tab, nama aplikasi di layar beranda, dan pratinjau tautan."
                            >
                                <input
                                    value={data.nama}
                                    onChange={(e) => setData('nama', e.target.value)}
                                    className={kelasInput}
                                />
                            </Field>

                            <Field
                                label="Nama pendek"
                                galat={errors.nama_pendek}
                                petunjuk="Maksimal 12 karakter. Android memotongnya di tengah kata bila lebih."
                            >
                                <input
                                    value={data.nama_pendek}
                                    onChange={(e) => setData('nama_pendek', e.target.value)}
                                    className={kelasInput}
                                />
                            </Field>

                            <div className="sm:col-span-2">
                                <Field
                                    label="Deskripsi"
                                    galat={errors.deskripsi}
                                    petunjuk="Meta description dan pratinjau saat tautan dibagikan lewat WhatsApp."
                                >
                                    <textarea
                                        rows={2}
                                        maxLength={255}
                                        value={data.deskripsi}
                                        onChange={(e) => setData('deskripsi', e.target.value)}
                                        className={kelasInput}
                                    />
                                </Field>
                            </div>
                        </div>
                    </Kotak>

                    <Kotak judul="Ikon">
                        <div className="space-y-4">
                            {IKON.map((satu) => (
                                <BarisIkon
                                    key={satu.jenis}
                                    jenis={satu.jenis}
                                    label={satu.label}
                                    petunjuk={satu.petunjuk}
                                    accept={satu.accept}
                                    url={urlIkon(satu.jenis)}
                                    sedangUnggah={sedangUnggah === satu.jenis}
                                    padaUnggah={(berkas) => unggah(satu.jenis, berkas)}
                                    padaHapus={() => hapus(satu.jenis)}
                                />
                            ))}
                        </div>
                    </Kotak>
                </div>

                <div className="space-y-4">
                    <Kotak judul="Warna tema">
                        <Field
                            label="Warna bilah peramban"
                            galat={errors.warna}
                            petunjuk="Dipakai sebagai theme-color di ponsel dan warna tema manifest."
                        >
                            <div className="flex items-center gap-2">
                                <input
                                    type="color"
                                    value={data.warna}
                                    onChange={(e) => setData('warna', e.target.value)}
                                    className="h-9 w-12 cursor-pointer rounded-wf border border-line bg-surface"
                                    aria-label="Pilih warna tema"
                                />
                                <input
                                    value={data.warna}
                                    onChange={(e) => setData('warna', e.target.value)}
                                    className={kelasInput + ' font-mono'}
                                />
                            </div>
                        </Field>

                        <p className="mt-2 text-[11px] leading-relaxed text-ink-3">
                            Warna ini hanya mewarnai bilah peramban dan manifest. Warna
                            tombol dan lencana di dalam aplikasi tetap mengikuti sistem
                            tema, supaya kontrasnya tetap lolos pemeriksaan.
                        </p>
                    </Kotak>

                    <Inbox>
                        Mesin pencari <b>tidak pernah</b> boleh mengindeks aplikasi ini:
                        halamannya memuat nilai, presensi, dan jawaban ujian, dan indeks
                        yang sudah terlanjur dibuat tidak bisa ditarik kembali. Karena itu
                        larangannya permanen, bukan pilihan yang bisa dimatikan di sini.
                    </Inbox>
                </div>
            </form>
        </AdminLayout>
    );
}

/**
 * Satu baris ikon: pratinjau, tombol pilih, dan tombol hapus.
 *
 * Pratinjau memakai latar kotak-kotak, bukan putih: favicon PNG transparan
 * yang berwarna putih akan tampak "tidak terunggah" di atas permukaan putih.
 */
function BarisIkon({
    label,
    petunjuk,
    accept,
    url,
    sedangUnggah,
    padaUnggah,
    padaHapus,
}: {
    jenis: JenisIkon;
    label: string;
    petunjuk: string;
    accept: string;
    url: string | null;
    sedangUnggah: boolean;
    padaUnggah: (berkas: File) => void;
    padaHapus: () => void;
}) {
    const masukan = useRef<HTMLInputElement>(null);

    return (
        <div className="flex flex-wrap items-center gap-3 border-b border-line/60 pb-4 last:border-0 last:pb-0">
            <div className="grid size-14 shrink-0 place-items-center overflow-hidden rounded-wf border border-line bg-surface-2">
                {url ? (
                    <img src={url} alt="" className="size-full object-contain p-1" />
                ) : (
                    <span className="text-[10px] text-ink-3">bawaan</span>
                )}
            </div>

            <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 text-xs font-medium text-ink">
                    {label}
                    {url === null && <Chip nada="mute">bawaan</Chip>}
                </p>
                <p className="mt-0.5 text-[11px] leading-relaxed text-ink-3">{petunjuk}</p>
            </div>

            <input
                ref={masukan}
                type="file"
                accept={accept}
                className="hidden"
                onChange={(e) => {
                    const berkas = e.target.files?.[0];

                    if (berkas) {
                        padaUnggah(berkas);
                    }

                    // Dikosongkan supaya memilih berkas yang SAMA dua kali
                    // tetap memicu perubahan - tanpa ini, Admin yang mengunggah
                    // versi salah lalu memperbaikinya dengan nama berkas yang
                    // sama tidak melihat apa pun terjadi.
                    e.target.value = '';
                }}
            />

            <div className="flex gap-2">
                <Tombol disabled={sedangUnggah} onClick={() => masukan.current?.click()}>
                    {sedangUnggah ? 'Mengunggah...' : url ? 'Ganti' : 'Pilih'}
                </Tombol>

                {url !== null && (
                    <Tombol nada="bahaya" disabled={sedangUnggah} onClick={padaHapus}>
                        Hapus
                    </Tombol>
                )}
            </div>
        </div>
    );
}
