import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Inbox, Peringatan, Tombol, kelasInput, nadaStatus } from '@/Components/ui';

/**
 * Impor master data - mata pelajaran, ruang ujian, dan kelas.
 *
 * =====================================================================
 * Satu laman untuk tiga jenis, dan jenisnya datang dari URL
 * =====================================================================
 * Alurnya identik: unggah -> pratinjau per baris -> konfirmasi. Yang berbeda
 * hanya kolom berkasnya, dan itu sudah dikirim server sebagai prop `kolom` -
 * sehingga laman ini tidak perlu tahu apa itu mapel, apa itu ruang, dan apa
 * itu kelas.
 *
 * Tiga laman kembar berarti tiga tempat yang harus tetap sepakat soal kalimat
 * "yang sudah ada akan diperbarui" - dan janji itulah satu-satunya alasan
 * Admin berani menekan tombolnya.
 *
 * =====================================================================
 * Layar ini berhenti DI PRATINJAU, dan itu yang membuatnya berguna
 * =====================================================================
 * Yang menulis baris hanya langkah ketiga, dan Admin melihat SETIAP baris
 * beserta nomor barisnya sebelum satu pun data tersentuh.
 *
 * Kolom "Baris" menampilkan NOMOR YANG DILIHAT ADMIN DI EXCEL, bukan nomor
 * urut hasil impor. Baris 17 di sini adalah baris 17 di sana - kalau tidak,
 * satu-satunya gunanya laporan ini hilang.
 *
 * =====================================================================
 * Tahun ajaran dipilih DI LAMAN, bukan di dalam berkas
 * =====================================================================
 * Hanya kelas yang memakainya (`tahun` bukan null), dan alasannya sama dengan
 * laman Kelas: nama kelas unik per tahun ajaran, dan "berganti TA berarti
 * berganti daftar kelas". Pemilihnya mengubah URL (`?ta=`) dan memuat ulang
 * dari server, bukan menyaring di sisi klien.
 *
 * Berkas yang membawa kolom tahunnya sendiri berarti dua tempat memilih tahun
 * yang sama, dan berkas yang lupa mengisinya tidak punya jawaban yang benar -
 * menebak tahun aktif berarti menulis seluruh berkasnya ke tahun yang salah.
 *
 * =====================================================================
 * Kalimat yang paling penting: yang sudah ada akan DIPERBARUI
 * =====================================================================
 * Baris dicocokkan lewat kolom kuncinya - `kode` untuk mapel dan ruang, `nama`
 * untuk kelas - dan yang cocok menimpa baris yang sudah ada. Nama kolomnya
 * datang dari server sebagai `label_kunci`; menuliskannya sebagai "kode" di
 * sini akan membuat laman impor kelas menjelaskan hal yang tidak ada di
 * berkasnya.
 */

type Baris = {
    nomor: number | null;
    kode: string;
    nama: string;
    aksi: string;
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
    diunggah_pada: string | null;
};

type Tahun = { id: number; nama: string; aktif: boolean };

type Props = {
    jenis: string;
    jenis_label: string;
    kembali: string;
    riwayat: Riwayat[];
    aktif: Pratinjau | null;
    kolom: string[];
    kolom_kunci: string;
    label_kunci: string;
    status_sah: string[];
    butuh_tahun: boolean;
    tahun: Tahun | null;
    daftar_tahun: Tahun[];
};

/**
 * Label chip hasil satu baris.
 *
 * "Diperbarui" sengaja berwarna peringatan, bukan hijau. Baris yang menimpa
 * data yang sudah ada bukan kabar baik yang perlu dirayakan - ia keadaan yang
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

export default function MasterImpor({
    jenis,
    jenis_label,
    kembali,
    riwayat,
    aktif,
    kolom,
    kolom_kunci,
    label_kunci,
    status_sah,
    butuh_tahun,
    tahun,
    daftar_tahun,
}: Props) {
    const [kirim, setKirim] = useState(false);
    const [galat, setGalat] = useState<Record<string, string>>({});
    const [namaBerkas, setNamaBerkas] = useState<string | null>(null);

    const masukan = useRef<HTMLInputElement>(null);

    // Tahun ajarannya ikut di setiap alamat, termasuk yang kembali ke laman
    // ini. Tanpa itu, sesudah unggah Admin mendarat di laman impor tanpa tahun
    // terpilih - dan laman itu lalu menolak setiap unggahan berikutnya.
    const ta = tahun ? `?ta=${tahun.id}` : '';

    const unggah = (berkas: File) => {
        if (kirim) {
            return;
        }

        setGalat({});
        setKirim(true);

        const form = new FormData();
        form.append('berkas', berkas);

        // Tahun ajarannya ikut dikirim, bukan hanya ada di URL. Yang
        // membacanya adalah `unggah()` di server, dan ia menyimpannya di baris
        // batch supaya Konfirmasi - yang datang lebih lambat - menulis ke
        // tahun yang sama dengan yang dipratinjau.
        if (tahun) {
            form.append('tahun_ajaran_id', String(tahun.id));
        }

        router.post(`/admin/impor/${jenis}/unggah${ta}`, form, {
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
            `/admin/impor/${jenis}/${aktif.id}/konfirmasi`,
            {},
            { preserveScroll: true, onFinish: () => setKirim(false) },
        );
    };

    const gantiTahun = (id: string) => {
        router.get(`/admin/impor/${jenis}`, { ta: id }, { preserveState: false });
    };

    return (
        <AdminLayout
            judul={`Impor ${jenis_label.toLowerCase()}`}
            breadcrumb={`/admin/impor/${jenis}${tahun ? ` · TA ${tahun.nama}` : ''}`}
            aksi={
                <>
                    {/* Pemilih TA hanya muncul untuk jenis yang memang
                        terikat tahun ajaran. Merendernya untuk mapel berarti
                        menawarkan pilihan yang tidak mengubah apa pun. */}
                    {tahun && daftar_tahun.length > 0 && (
                        <select
                            value={String(tahun.id)}
                            onChange={(e) => gantiTahun(e.target.value)}
                            className={kelasInput + ' w-auto font-mono'}
                        >
                            {daftar_tahun.map((t) => (
                                <option key={t.id} value={t.id}>
                                    TA {t.nama}
                                    {t.aktif ? ' (aktif)' : ''}
                                </option>
                            ))}
                        </select>
                    )}

                    <a
                        href={kembali}
                        className="rounded-wf border border-line px-3 py-1.5 text-xs font-medium text-ink-2 hover:bg-surface-2"
                    >
                        Kembali
                    </a>
                    <a
                        href={`/admin/impor/${jenis}/template`}
                        className="rounded-wf border border-line px-3 py-1.5 text-xs font-medium text-ink-2 hover:bg-surface-2"
                    >
                        Unduh template
                    </a>
                </>
            }
        >
            <Head title={`Impor ${jenis_label.toLowerCase()}`} />

            <div className="mb-3 space-y-3">
                <Inbox>
                    Baris dicocokkan lewat <strong>{label_kunci.toLowerCase()}</strong>.{' '}
                    {label_kunci} yang sudah terdaftar akan <strong>diperbarui</strong> — bukan
                    dibuat ulang. Sel yang dibiarkan kosong tidak mengosongkan data yang sudah ada.
                </Inbox>

                {tahun && (
                    <Inbox>
                        Baris berkas ini masuk ke tahun ajaran{' '}
                        <strong>{tahun.nama}</strong>. Nama kelas boleh sama di tahun ajaran
                        berbeda — X-1 muncul lagi setiap tahun, dan itu bukan duplikat.
                    </Inbox>
                )}

                {/* Jenis yang butuh tahun ajaran tetapi sekolahnya belum punya
                    satu pun: yang menghalangi bukan berkasnya, melainkan belum
                    adanya tahun ajaran. Menyuruh Admin "pilih tahun ajarannya"
                    tanpa satu pun pilihan adalah jalan buntu. */}
                {!tahun && butuh_tahun && (
                    <Peringatan>
                        Kelas selalu milik satu tahun ajaran, jadi tambahkan tahun ajaran lebih
                        dulu di laman Tahun ajaran sebelum mengimpor kelas.
                    </Peringatan>
                )}
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
                        Urutan kolom bebas — yang dicari adalah <strong>namanya</strong>. Kolom{' '}
                        <code>{kolom_kunci}</code> wajib ada; kolom sisanya boleh dikosongkan,
                        dan yang dikosongkan tidak mengubah data yang sudah terisi. Kolom{' '}
                        <code>status</code> menerima {status_sah.join(', ')}.
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
                            {aktif.baru > 0 && <Chip nada="info">{aktif.baru} baris baru</Chip>}
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
                                    <th className="w-28 px-2 py-2 font-medium">{label_kunci}</th>
                                    {/* Untuk kelas, kolom kuncinya ADALAH nama
                                        kelasnya - dua kolom dengan isi yang
                                        persis sama hanya membuat tabelnya
                                        lebih lebar tanpa memberi tahu apa pun. */}
                                    {kolom_kunci !== 'nama' && (
                                        <th className="px-2 py-2 font-medium">Nama</th>
                                    )}
                                    <th className="w-28 px-2 py-2 font-medium">Hasil</th>
                                    <th className="px-4 py-2 font-medium">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                {aktif.baris.map((b, i) => {
                                    const hasil = labelBaris(b);

                                    return (
                                        <tr
                                            key={`${b.nomor}-${i}`}
                                            className="border-t border-line align-top"
                                        >
                                            <td className="px-4 py-2 font-mono text-ink-3">
                                                {b.nomor}
                                            </td>
                                            <td className="px-2 py-2 font-mono text-ink-2">
                                                {b.kode || '—'}
                                            </td>
                                            {kolom_kunci !== 'nama' && (
                                                <td className="px-2 py-2 text-ink-2">
                                                    {b.nama || '—'}
                                                </td>
                                            )}
                                            <td className="px-2 py-2">
                                                <Chip nada={hasil.nada}>{hasil.teks}</Chip>
                                            </td>
                                            <td className="px-4 py-2">
                                                {b.sah ? (
                                                    <span className="text-ink-3">{b.pesan}</span>
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
                            {aktif.baru} baris baru, {aktif.diperbarui} diperbarui
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
                    </footer>

                    <div className="border-t border-line px-4 py-2.5">
                        <p className="text-[11px] leading-relaxed text-ink-3">
                            Baris yang gagal <strong>tidak</strong> menggagalkan berkasnya —{' '}
                            {aktif.sah} baris tetap tersimpan, dan laporan galat memuat alamat
                            baris sisanya. Perbaiki berkasnya, lalu unggah ulang: berkas yang{' '}
                            <em>isinya sama</em> ditolak supaya datanya tidak tersentuh dua kali.
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
                                        {r.ada_laporan && (
                                            <a
                                                href={`/admin/impor/${jenis}/${r.id}/laporan`}
                                                className="text-[11px] font-medium text-accent hover:underline"
                                            >
                                                Laporan galat
                                            </a>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <div className="border-t border-line px-4 py-2.5">
                        <p className="text-[11px] text-ink-3">
                            Berkas yang sudah diimpor tidak bisa dikonfirmasi ulang — impor kedua
                            atas berkas yang sama akan menimpa baris yang sama untuk kedua kalinya.
                        </p>
                    </div>
                </section>
            )}
        </AdminLayout>
    );
}
