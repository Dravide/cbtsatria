import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Inbox, Tombol, nadaStatus } from '@/Components/ui';

/**
 * Impor pengguna massal - PRD §5.7, wireframe-admin.html plate 05.
 *
 * =====================================================================
 * Layar ini berhenti DI PRATINJAU, dan itu yang membuatnya berguna
 * =====================================================================
 * Alurnya: unggah -> pratinjau per baris -> konfirmasi. Yang menulis akun
 * hanya langkah ketiga, dan Admin melihat SETIAP baris beserta nomor barisnya
 * sebelum satu pun akun tersentuh.
 *
 * Kolom "Baris" menampilkan NOMOR YANG DILIHAT ADMIN DI EXCEL, bukan nomor
 * urut hasil impor. Baris 17 di sini adalah baris 17 di sana - kalau tidak,
 * satu-satunya gunanya laporan ini hilang.
 *
 * =====================================================================
 * Kalimat yang paling penting di laman ini: username dan sandi tidak berubah
 * =====================================================================
 * Mode "perbarui akun lama" berarti setiap unggahan menyentuh akun yang sudah
 * dipakai orang. Yang membuat Admin berani menekan Konfirmasi bukan tombolnya,
 * melainkan janji bahwa akun lama hanya kehilangan nama, email, dan kelasnya -
 * bukan nama akunnya. Janji itu ditulis di kotak biru, bukan disembunyikan di
 * dokumen.
 *
 * =====================================================================
 * Tidak ada kolom peran di sini
 * =====================================================================
 * Templatenya SATU untuk tiga peran: perannya ditentukan per baris di kolom
 * pertamanya. Selector peran di laman ini akan meminta Admin menjawab
 * pertanyaan yang berkasnya sudah jawab - dan menjawabnya dengan salah berarti
 * membatalkan seluruh berkasnya.
 */

type Baris = {
    nomor: number | null;
    peran: string | null;
    nama: string;
    nomor_identitas: string | null;
    aksi: string;
    kelas: string | null;
    kelas_aksi: string | null;
    kelas_dari: string | null;
    sah: boolean;
    kolom: string | null;
    pesan: string | null;
};

type Pratinjau = {
    id: number;
    nama_asli: string;
    status: string;
    status_label: string;
    boleh_konfirmasi: boolean;
    sah: number;
    gagal: number;
    baru: number;
    diperbarui: number;
    pesan_berkas: string | null;
    kolom_terbaca: string[];
    baris: Baris[];
};

type Riwayat = {
    id: number;
    nama_asli: string;
    status: string;
    status_label: string;
    sah: number;
    gagal: number;
    baru: number;
    diperbarui: number;
    total: number;
    ada_laporan: boolean;
    boleh_batal: boolean;
    diunggah_pada: string | null;
};

type Props = {
    riwayat: Riwayat[];
    aktif: Pratinjau | null;
    kolom: string[];
};

/**
 * Label chip hasil satu baris.
 *
 * "Diperbarui" sengaja berwarna peringatan, bukan hijau. Baris yang menimpa
 * akun orang lain bukan kabar baik yang perlu dirayakan - ia keadaan yang
 * harus Admin sadari sedang terjadi, dan angka "12 diperbarui" itulah satu-
 * satunya tempat ia bisa menyadarinya sebelum menekan Konfirmasi.
 */
function labelBaris(b: Baris): { teks: string; nada: 'ok' | 'warn' | 'bad' | 'info' } {
    if (!b.sah) {
        return { teks: 'gagal', nada: 'bad' };
    }

    if (b.aksi === 'diperbarui') {
        return { teks: 'diperbarui', nada: 'warn' };
    }

    return { teks: 'baru', nada: 'info' };
}

/** Keterangan kelas satu baris, bila ada. */
function keteranganKelas(b: Baris): string | null {
    if (b.kelas === null) {
        return null;
    }

    if (b.kelas_aksi === 'pindah' && b.kelas_dari !== null) {
        return `pindah dari ${b.kelas_dari} ke ${b.kelas}`;
    }

    return `kelas ${b.kelas}`;
}

export default function PenggunaImpor({ riwayat, aktif, kolom }: Props) {
    const [kirim, setKirim] = useState(false);
    const [galat, setGalat] = useState<Record<string, string>>({});
    const [namaBerkas, setNamaBerkas] = useState<string | null>(null);

    const masukan = useRef<HTMLInputElement>(null);

    const unggah = (berkas: File) => {
        if (kirim) {
            return;
        }

        setGalat({});
        setKirim(true);

        const form = new FormData();
        form.append('berkas', berkas);

        router.post('/admin/pengguna/impor/unggah', form, {
            forceFormData: true,
            preserveScroll: true,
            onError: (e) => setGalat(e as Record<string, string>),
            onFinish: () => {
                setKirim(false);
                setNamaBerkas(null);

                if (masukan.current) {
                    masukan.current.value = '';
                }
            },
        });
    };

    const konfirmasi = () => {
        if (kirim || aktif === null) {
            return;
        }

        setKirim(true);

        router.post(
            `/admin/pengguna/impor/${aktif.id}/konfirmasi`,
            {},
            { preserveScroll: true, onFinish: () => setKirim(false) },
        );
    };

    /**
     * Batalkan satu berkas impor.
     *
     * Barisnya DIHAPUS - bukan ditandai - karena `berkas_hash` unik per Admin:
     * baris yang dibatalkan harus melepaskan kuncinya, kalau tidak berkas yang
     * sama tidak akan pernah bisa diunggah lagi setelah diperbaiki. Karena itu
     * konfirmasinya menyebut apa yang hilang, bukan sekadar "Batalkan?".
     */
    const batalkan = (id: number, nama: string) => {
        if (kirim) {
            return;
        }

        const setuju = window.confirm(
            `Batalkan impor "${nama}"?\n\nSeluruh baris pratinjaunya dibuang dan berkasnya dihapus. ` +
                'Tidak ada akun yang tersimpan dari berkas ini, dan berkas yang sama boleh diunggah lagi setelah diperbaiki.',
        );

        if (!setuju) {
            return;
        }

        setKirim(true);

        router.delete(`/admin/pengguna/impor/${id}`, {
            preserveScroll: true,
            onFinish: () => setKirim(false),
        });
    };

    return (
        <AdminLayout
            judul="Impor pengguna"
            breadcrumb="/admin/pengguna/impor"
            aksi={
                <a
                    href="/admin/pengguna/impor/template"
                    className="rounded-wf border border-line px-3 py-1.5 text-xs font-medium text-ink-2 hover:bg-surface-2"
                >
                    Unduh template
                </a>
            }
        >
            <Head title="Impor pengguna" />

            <div className="mb-3">
                <Inbox>
                    NIS/NIP yang sudah terdaftar akan <strong>diperbarui</strong> — nama, email,
                    kelas, dan profilnya. <strong>Username dan sandi tidak diubah.</strong> Sel
                    yang dibiarkan kosong juga tidak mengosongkan data yang sudah ada.
                </Inbox>
            </div>

            {/* ------------------------------------------------ 1. Unggah */}

            <section className="mb-4 rounded-wf border border-line bg-surface p-4">
                <h2 className="mb-3 font-display text-sm font-semibold text-ink">
                    1. Pilih berkas
                </h2>

                <label
                    className={
                        'flex cursor-pointer flex-col items-center gap-2 rounded-wf border border-dashed px-4 py-6 text-center transition-colors ' +
                        (kirim
                            ? 'cursor-not-allowed border-line bg-surface-2 opacity-60'
                            : 'border-line bg-surface-2/50 hover:border-accent')
                    }
                >
                    <input
                        ref={masukan}
                        type="file"
                        accept=".xlsx,.xlsm,.csv,.txt"
                        className="hidden"
                        disabled={kirim}
                        onChange={(e) => {
                            const berkas = e.target.files?.[0];

                            if (berkas) {
                                setNamaBerkas(berkas.name);
                                unggah(berkas);
                            }
                        }}
                    />
                    <span className="text-sm font-medium text-ink">
                        {namaBerkas ?? 'Pilih berkas XLSX atau CSV'}
                    </span>
                    <span className="text-[11px] text-ink-3">
                        Batas 2 MB dan 2.000 baris per berkas. Format .xls lama tidak didukung.
                    </span>
                </label>

                {galat.berkas && <p className="mt-2 text-[11px] text-bad">{galat.berkas}</p>}

                {kirim && namaBerkas && (
                    <p className="mt-2 text-[11px] text-ink-3">
                        Mengunggah dan memeriksa seluruh barisnya…
                    </p>
                )}

                <details className="mt-3">
                    <summary className="cursor-pointer text-[11px] text-ink-3 hover:text-ink-2">
                        Kolom yang dibaca ({kolom.length})
                    </summary>
                    <p className="mt-1.5 font-mono text-[11px] leading-relaxed text-ink-3">
                        {kolom.join(' · ')}
                    </p>
                    <p className="mt-1.5 text-[11px] leading-relaxed text-ink-3">
                        Urutan kolom bebas — yang dicari adalah <strong>namanya</strong>. Satu
                        template untuk tiga peran: kolom <code>peran</code> diisi{' '}
                        <code>siswa</code>, <code>guru</code>, atau <code>pengawas</code>, dan
                        kolom identitas yang tidak berlaku dibiarkan kosong. Kolom kelas hanya
                        dibaca pada baris siswa, dan diisi nama kelas di tahun ajaran yang sedang
                        aktif.
                    </p>
                </details>
            </section>

            {/* -------------------------------------------- 2. Pratinjau */}

            {aktif && (
                <section className="mb-4 rounded-wf border border-line bg-surface">
                    <header className="flex flex-wrap items-center gap-3 border-b border-line px-4 py-2.5">
                        <h2 className="font-display text-sm font-semibold text-ink">
                            2. Pratinjau · {aktif.nama_asli}
                        </h2>
                        <div className="ml-auto flex flex-wrap items-center gap-2">
                            {aktif.baru > 0 && <Chip nada="info">{aktif.baru} akun baru</Chip>}
                            {aktif.diperbarui > 0 && (
                                <Chip nada="warn">{aktif.diperbarui} diperbarui</Chip>
                            )}
                            {aktif.gagal > 0 && <Chip nada="bad">{aktif.gagal} baris gagal</Chip>}
                        </div>
                    </header>

                    {aktif.pesan_berkas && (
                        <div className="border-b border-line px-4 py-3">
                            <p className="text-xs leading-relaxed text-bad">{aktif.pesan_berkas}</p>
                            {aktif.kolom_terbaca.length > 0 && (
                                <p className="mt-1.5 font-mono text-[11px] text-ink-3">
                                    Terbaca: {aktif.kolom_terbaca.join(' · ')}
                                </p>
                            )}
                        </div>
                    )}

                    <div className="max-h-[26rem] overflow-y-auto">
                        <table className="w-full text-xs">
                            <thead className="sticky top-0 bg-surface-2 text-left text-ink-3">
                                <tr>
                                    <th className="w-14 px-4 py-2 font-medium">Baris</th>
                                    <th className="w-20 px-2 py-2 font-medium">Peran</th>
                                    <th className="px-2 py-2 font-medium">Nama</th>
                                    <th className="w-32 px-2 py-2 font-medium">Nomor</th>
                                    <th className="w-24 px-2 py-2 font-medium">Hasil</th>
                                    <th className="px-4 py-2 font-medium">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                {aktif.baris.map((b, i) => {
                                    const hasil = labelBaris(b);
                                    const kelas = keteranganKelas(b);

                                    return (
                                        <tr
                                            key={`${b.nomor}-${i}`}
                                            className="border-t border-line align-top"
                                        >
                                            <td className="px-4 py-2 font-mono text-ink-3">
                                                {b.nomor}
                                            </td>
                                            <td className="px-2 py-2 text-ink-3">
                                                {b.peran ?? '—'}
                                            </td>
                                            <td className="px-2 py-2 text-ink-2">{b.nama}</td>
                                            <td className="px-2 py-2 font-mono text-ink-3">
                                                {b.nomor_identitas ?? '—'}
                                            </td>
                                            <td className="px-2 py-2">
                                                <Chip nada={hasil.nada}>{hasil.teks}</Chip>
                                            </td>
                                            <td className="px-4 py-2">
                                                {b.sah ? (
                                                    <span className="text-ink-3">
                                                        {kelas && (
                                                            <span className="text-warn">
                                                                {kelas} ·{' '}
                                                            </span>
                                                        )}
                                                        {b.pesan}
                                                    </span>
                                                ) : (
                                                    <span className="text-bad">
                                                        {b.kolom && (
                                                            <span className="font-mono text-[11px] text-ink-3">
                                                                {b.kolom} ·{' '}
                                                            </span>
                                                        )}
                                                        {b.pesan}
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <footer className="flex flex-wrap items-center gap-3 border-t border-line px-4 py-3">
                        <span className="text-xs text-ink-2">
                            {aktif.baru} akun baru, {aktif.diperbarui} diperbarui
                            {aktif.gagal > 0 && `, ${aktif.gagal} baris perlu diperbaiki`}
                        </span>

                        {aktif.boleh_konfirmasi ? (
                            <Tombol
                                nada="pri"
                                onClick={konfirmasi}
                                disabled={kirim || aktif.sah === 0}
                            >
                                {kirim ? 'Menyimpan…' : 'Impor'}
                            </Tombol>
                        ) : (
                            <span className="ml-auto text-[11px] text-ink-3">
                                Berkas ini tidak bisa dikonfirmasi lagi.
                            </span>
                        )}

                        <Tombol
                            nada="bahaya"
                            onClick={() => batalkan(aktif.id, aktif.nama_asli)}
                            disabled={kirim}
                            title="Buang berkas ini beserta pratinjaunya, tanpa menyimpan satu akun pun"
                        >
                            Batalkan impor
                        </Tombol>
                    </footer>

                    <div className="border-t border-line px-4 py-2.5">
                        <p className="text-[11px] leading-relaxed text-ink-3">
                            Baris yang gagal <strong>tidak</strong> menggagalkan berkasnya —{' '}
                            {aktif.sah} baris tetap tersimpan, dan laporan galat memuat alamat
                            baris sisanya. Perbaiki berkasnya, lalu unggah ulang: berkas yang{' '}
                            <em>isinya sama</em> ditolak supaya akun tidak tersentuh dua kali.
                        </p>
                    </div>
                </section>
            )}

            {/* --------------------------------------------- 3. Riwayat */}

            {riwayat.length > 0 && (
                <section className="rounded-wf border border-line bg-surface">
                    <header className="border-b border-line px-4 py-2.5">
                        <h2 className="font-display text-sm font-semibold text-ink">
                            Riwayat impor
                        </h2>
                    </header>

                    <table className="w-full text-xs">
                        <thead className="bg-surface-2 text-left text-ink-3">
                            <tr>
                                <th className="px-4 py-2 font-medium">Berkas</th>
                                <th className="px-2 py-2 font-medium">Hasil</th>
                                <th className="w-28 px-2 py-2 font-medium">Status</th>
                                <th className="w-24 px-4 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {riwayat.map((r) => (
                                <tr key={r.id} className="border-t border-line">
                                    <td className="px-4 py-2">
                                        <span className="block truncate font-medium text-ink">
                                            {r.nama_asli}
                                        </span>
                                        <span className="text-[11px] text-ink-3">
                                            {r.diunggah_pada}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2 text-ink-2">
                                        {r.total === 0 ? (
                                            <span className="text-ink-3">—</span>
                                        ) : (
                                            <>
                                                {r.baru > 0 && (
                                                    <span className="text-info">
                                                        {r.baru} baru
                                                    </span>
                                                )}
                                                {r.diperbarui > 0 && (
                                                    <span className="text-warn">
                                                        {r.baru > 0 && ' · '}
                                                        {r.diperbarui} diperbarui
                                                    </span>
                                                )}
                                                {r.gagal > 0 && (
                                                    <span className="text-bad">
                                                        {r.sah > 0 && ' · '}
                                                        {r.gagal} gagal
                                                    </span>
                                                )}
                                                {r.sah === 0 && r.gagal === 0 && (
                                                    <span className="text-ink-3">
                                                        belum disimpan
                                                    </span>
                                                )}
                                            </>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Chip nada={nadaStatus(r.status)}>{r.status_label}</Chip>
                                    </td>
                                    <td className="px-4 py-2 text-right">
                                        <div className="flex items-center justify-end gap-2">
                                            {r.ada_laporan && (
                                                <a
                                                    href={`/admin/pengguna/impor/${r.id}/laporan`}
                                                    className="text-[11px] font-medium text-accent hover:underline"
                                                >
                                                    Laporan galat
                                                </a>
                                            )}
                                            {r.boleh_batal && (
                                                <button
                                                    type="button"
                                                    onClick={() => batalkan(r.id, r.nama_asli)}
                                                    disabled={kirim}
                                                    className="text-[11px] font-medium text-bad hover:underline disabled:opacity-50"
                                                    title="Buang berkas ini beserta pratinjaunya"
                                                >
                                                    Batalkan
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <div className="border-t border-line px-4 py-2.5">
                        <p className="text-[11px] text-ink-3">
                            Berkas yang sudah diimpor tidak bisa dikonfirmasi ulang — impor kedua
                            atas berkas yang sama akan memperbarui akun yang sama untuk kedua
                            kalinya.
                        </p>
                    </div>
                </section>
            )}
        </AdminLayout>
    );
}
