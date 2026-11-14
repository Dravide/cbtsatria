import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { konfirmasi } from '@/Components/dialog';
import { Chip, Field, Inbox, Kotak, Tombol, kelasInput } from '@/Components/ui';

/**
 * Laman 04 - Profil sekolah. Wireframe plate 04.
 *
 * Empat tab: Identitas, Kontak, Zona waktu, Logo. Semuanya satu form - data
 * ini diisi sekali di awal dan jarang disentuh lagi, jadi memecahnya menjadi
 * empat rute berarti empat laman yang tidak pernah punya isi baru.
 */
type Props = {
    sekolah: {
        id: number;
        kode: string;
        nama: string;
        npsn: string;
        alamat: string | null;
        email: string | null;
        telepon: string | null;
        zona_waktu: string;
        logo_url: string | null;
        status: string;
        /** Alamat papan publik pengawas; null = papan belum dinyalakan. */
        papan_slug: string | null;
    };
    zonaWaktu: Record<string, string>;
};

export default function Sekolah({ sekolah, zonaWaktu }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        nama: sekolah.nama,
        alamat: sekolah.alamat ?? '',
        email: sekolah.email ?? '',
        telepon: sekolah.telepon ?? '',
        zona_waktu: sekolah.zona_waktu,
    });

    const kirim = (e: React.FormEvent) => {
        e.preventDefault();
        put('/admin/sekolah');
    };

    return (
        <AdminLayout judul="Profil sekolah" breadcrumb="/admin/sekolah">
            <Head title="Profil sekolah" />

            <form onSubmit={kirim} className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                <div className="space-y-4">
                    <Kotak judul="Identitas">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="NPSN">
                                {/* NPSN dikunci, dan pengunciannya bukan
                                    hiasan: ia identitas tenant yang dipakai
                                    sistem di belakang layar. Mengubahnya
                                    berarti memindahkan sekolah ke identitas
                                    lain - operasi di luar aplikasi. Yang
                                    dikunci di UI juga ditolak di server,
                                    sehingga request langsung pun tidak
                                    berpengaruh. */}
                                <div className="flex items-center gap-2">
                                    <input
                                        value={sekolah.npsn}
                                        disabled
                                        className={kelasInput + ' font-mono'}
                                    />
                                    <Chip nada="mute">terkunci</Chip>
                                </div>
                            </Field>

                            <Field label="Kode internal sekolah">
                                <div className="flex items-center gap-2">
                                    <input
                                        value={sekolah.kode}
                                        disabled
                                        className={kelasInput + ' font-mono'}
                                    />
                                    <Chip nada="mute">terkunci</Chip>
                                </div>
                            </Field>

                            <div className="sm:col-span-2">
                                <Field label="Nama sekolah" galat={errors.nama}>
                                    <input
                                        value={data.nama}
                                        onChange={(e) => setData('nama', e.target.value)}
                                        className={kelasInput}
                                    />
                                </Field>
                            </div>

                            <div className="sm:col-span-2">
                                <Field
                                    label="Alamat"
                                    galat={errors.alamat}
                                    petunjuk="Alamat ini muncul di kop berita acara dan laporan hasil."
                                >
                                    <textarea
                                        rows={3}
                                        value={data.alamat}
                                        onChange={(e) => setData('alamat', e.target.value)}
                                        className={kelasInput}
                                    />
                                </Field>
                            </div>
                        </div>
                    </Kotak>

                    <Kotak judul="Kontak & waktu">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Email sekolah" galat={errors.email}>
                                <input
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    className={kelasInput}
                                />
                            </Field>

                            <Field label="Telepon" galat={errors.telepon}>
                                <input
                                    value={data.telepon}
                                    onChange={(e) => setData('telepon', e.target.value)}
                                    className={kelasInput}
                                />
                            </Field>

                            <Field
                                label="Zona waktu"
                                galat={errors.zona_waktu}
                                petunjuk="Menentukan jam mulai dan berakhir sesi ujian."
                            >
                                <select
                                    value={data.zona_waktu}
                                    onChange={(e) => setData('zona_waktu', e.target.value)}
                                    className={kelasInput}
                                >
                                    {Object.entries(zonaWaktu).map(([nilai, label]) => (
                                        <option key={nilai} value={nilai}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </div>
                    </Kotak>
                </div>

                <div className="space-y-4">
                    <Kotak judul="Logo">
                        <LogoFormulir sekolah={sekolah} />
                    </Kotak>

                    <PapanFormulir sekolah={sekolah} />

                    <Kotak judul="Status">
                        <div className="flex items-center gap-2">
                            <Chip nada={sekolah.status === 'aktif' ? 'ok' : 'bad'}>
                                {sekolah.status}
                            </Chip>
                            <span className="text-xs text-ink-3">
                                Sekolah nonaktif tidak dapat dipakai mengerjakan ujian.
                            </span>
                        </div>
                    </Kotak>

                    <Inbox>
                        NPSN dikunci karena ia identitas tenant yang dipakai sistem di
                        belakang layar. Mengubahnya berarti memindahkan sekolah ini ke
                        identitas lain, dan itu dilakukan di luar aplikasi.
                    </Inbox>
                </div>

                <div className="lg:col-span-2">
                    <Tombol type="submit" nada="pri" disabled={processing}>
                        {processing ? 'Menyimpan...' : 'Simpan profil'}
                    </Tombol>
                </div>
            </form>
        </AdminLayout>
    );
}

/**
 * Saklar papan publik pengawas - ditambahkan 2026-11-14.
 *
 * =====================================================================
 * Mengapa form SENDIRI, bukan tombol di form profil
 * =====================================================================
 * Form profil mengirim seluruh kolomnya sekaligus. Saklar yang ikut di
 * dalamnya berarti setiap penyimpanan alamat sekolah memutuskan ulang
 * apakah nama pengawas tayang ke internet - dan keputusan yang
 * digabungkan begitu suatu hari diambil tanpa dibaca.
 *
 * =====================================================================
 * Kalimatnya menyebut APA yang tayang, bukan "papan publik"
 * =====================================================================
 * Admin yang membaca "menyalakan papan publik" akan membayangkan denah,
 * nilai, atau daftar peserta. Yang sebenarnya tayang hanya tiga hal, dan
 * itu ditulis di sini - sebelum saklarnya ditekan, bukan sesudah.
 *
 * =====================================================================
 * Alamatnya tidak bisa diketik
 * =====================================================================
 * Slug dibangkitkan dari nama sekolah + empat huruf acak dan tidak pernah
 * menerima input. Kolom yang bisa diisi peramban adalah alamat internet
 * yang suatu hari diisi sesuatu yang bukan nama sekolah.
 */
function PapanFormulir({ sekolah }: { sekolah: Props['sekolah'] }) {
    const aktif = sekolah.papan_slug !== null;

    const kirim = (nyalakan: boolean) => {
        router.patch(
            '/admin/papan',
            { aktif: nyalakan },
            { preserveScroll: true },
        );
    };

    return (
        <Kotak judul="Papan publik pengawas">
            <div className="space-y-2.5">
                <p className="text-xs leading-relaxed text-ink-2">
                    Alamat tanpa login yang menjawab &ldquo;pengawas mana ada di ruang
                    mana&rdquo;. Yang tayang hanya <strong className="text-ink">nama ruang</strong>,{' '}
                    <strong className="text-ink">nama pengawas</strong>, dan{' '}
                    <strong className="text-ink">jam sesi</strong> &mdash; tidak nama siswa,
                    tidak nomor kursi, tidak token.
                </p>

                {aktif ? (
                    <>
                        <div className="rounded-wf border border-line bg-surface-2 px-2.5 py-2">
                            <p className="text-[11px] text-ink-3">Alamat papan</p>
                            <a
                                href={`/papan/${sekolah.papan_slug}`}
                                target="_blank"
                                rel="noopener"
                                className="mt-0.5 block break-all font-mono text-[11px] text-accent hover:underline"
                            >
                                /papan/{sekolah.papan_slug}
                            </a>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <Chip nada="ok">menyala</Chip>
                            <Tombol
                                nada="bahaya"
                                onClick={async () => {
                                    const ya = await konfirmasi({
                                        judul: 'Matikan papan publik?',
                                        pesan: 'Alamatnya berhenti berlaku seketika, dan tautan yang sudah ditempel di lorong menjadi 404. Menyalakannya lagi akan membuat alamat BARU yang harus ditempel ulang.',
                                        labelYa: 'Matikan',
                                        labelTidak: 'Jangan',
                                        nada: 'bahaya',
                                    });

                                    if (ya) {
                                        kirim(false);
                                    }
                                }}
                            >
                                Matikan
                            </Tombol>
                        </div>
                    </>
                ) : (
                    <div className="flex flex-wrap items-center gap-2">
                        <Chip nada="mute">mati</Chip>
                        <Tombol nada="pri" onClick={() => kirim(true)}>
                            Nyalakan papan
                        </Tombol>
                    </div>
                )}
            </div>
        </Kotak>
    );
}

/**
 * Unggah/hapus logo - kotak "Logo" plate 04.
 *
 * ---------------------------------------------------------------------
 * Form kedua, dan itu bukan pemisahan yang dibuat-buat
 * ---------------------------------------------------------------------
 * Form profil dikirim sebagai JSON (Inertia bawaan). Begitu satu berkas
 * masuk ke dalamnya, SELURUH form harus jadi multipart - termasuk saat Admin
 * hanya mengubah nomor telepon. Formnya sendiri juga jadi satu tombol
 * simpan untuk dua pekerjaan yang berbeda, dan "ganti logo tetapi jangan
 * simpan alamat" jadi tidak bisa dinyatakan.
 *
 * `useForm` sendiri, jadi `forceFormData` di sini tidak menular ke form
 * profil di atasnya.
 */
function LogoFormulir({ sekolah }: { sekolah: Props['sekolah'] }) {
    const { data, setData, post, delete: hapus, processing, errors, progress } = useForm<{
        logo: File | null;
    }>({ logo: null });

    const masukanBerkas = useRef<HTMLInputElement>(null);

    // Pratinjau dari berkas yang BARU dipilih, bukan dari `sekolah.logo_url` -
    // Admin harus melihat apa yang akan ia simpan sebelum menekan tombolnya.
    const [pratinjau, setPratinjau] = useState<string | null>(null);

    // Object URL menahan berkasnya di memori sampai ia dilepas. Tanpa
    // pembersihan ini, setiap logo yang dicoba bertahan sampai halaman
    // ditutup.
    useEffect(() => {
        if (!data.logo) {
            setPratinjau(null);
            return;
        }

        const url = URL.createObjectURL(data.logo);
        setPratinjau(url);

        return () => URL.revokeObjectURL(url);
    }, [data.logo]);

    const tampil = pratinjau ?? sekolah.logo_url;

    return (
        <>
            {tampil ? (
                <img
                    src={tampil}
                    alt="Logo sekolah"
                    className="mx-auto h-24 w-24 object-contain"
                />
            ) : (
                <div className="grid h-24 place-items-center rounded-wf border border-dashed border-wf-line text-xs text-ink-3">
                    Belum ada logo
                </div>
            )}

            <input
                ref={masukanBerkas}
                type="file"
                accept="image/png,image/svg+xml,.png,.svg"
                className="hidden"
                onChange={(e) => {
                    const berkas = e.target.files?.[0];

                    if (berkas) {
                        setData('logo', berkas);
                    }
                }}
            />

            {errors.logo && (
                <p className="mt-2 text-[11px] text-bad">{errors.logo}</p>
            )}

            {progress && (
                <div className="mt-2 h-1 overflow-hidden rounded-full bg-surface-2">
                    <div
                        className="h-full bg-accent transition-[width]"
                        style={{ width: `${progress.percentage ?? 0}%` }}
                    />
                </div>
            )}

            <div className="mt-3 flex gap-2">
                <Tombol
                    nada="sek"
                    disabled={processing}
                    onClick={() => masukanBerkas.current?.click()}
                >
                    {sekolah.logo_url ? 'Ganti logo' : 'Pilih logo'}
                </Tombol>

                {data.logo && (
                    <Tombol
                        nada="pri"
                        disabled={processing}
                        onClick={() =>
                            post('/admin/sekolah/logo', {
                                forceFormData: true,
                                onSuccess: () => {
                                    setData('logo', null);
                                    if (masukanBerkas.current) {
                                        masukanBerkas.current.value = '';
                                    }
                                },
                            })
                        }
                    >
                        {processing ? 'Menyimpan...' : 'Simpan logo'}
                    </Tombol>
                )}

                {sekolah.logo_url && !data.logo && (
                    <Tombol
                        nada="bahaya"
                        disabled={processing}
                        onClick={() => hapus('/admin/sekolah/logo')}
                    >
                        Hapus logo
                    </Tombol>
                )}
            </div>

            <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                PNG atau SVG, maksimal 1 MB. Sekolah lain tidak bisa melihat
                maupun menggantinya.
            </p>
        </>
    );
}
