import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import GuruLayout from '@/Layouts/GuruLayout';
import {
    Chip,
    Field,
    Inbox,
    Tombol,
    kelasInput,
    nadaStatus,
} from '@/Components/ui';

/**
 * Impor soal - wireframe-peran.html plate 04.
 *
 * =====================================================================
 * Layar ini berhenti DI PRATINJAU, dan itu yang membuatnya berguna
 * =====================================================================
 * Alurnya: unggah -> pratinjau per baris -> konfirmasi. Yang menyimpan soal
 * hanya langkah ketiga, dan Guru melihat SETIAP baris beserta nomor barisnya
 * sebelum satu pun soal masuk. Itulah kalimat blueprint `:1225` - "Guru yang
 * mengimpor 50 soal harus tahu baris mana yang perlu diperbaiki, bukan
 * menerima pesan 'impor gagal' tanpa alamat".
 *
 * Karena itu kolom "Baris" di tabel pratinjau menampilkan NOMOR YANG DILIHAT
 * GURU DI EXCEL, bukan nomor urut hasil impor. Baris 17 di sini adalah baris
 * 17 di sana - kalau tidak, satu-satunya gunanya laporan ini hilang.
 *
 * =====================================================================
 * Dua tombol di akhir, dan bedanya bukan hiasan
 * =====================================================================
 * "Impor ke bank saja" dan "Impor & tambah ke paket" melakukan hal yang
 * berbeda, dan Guru yang menyiapkan persediaan umum tidak ingin paketnya
 * tersentuh. Tombol paket hanya muncul bila ada paket tujuan yang dipilih -
 * menawarkan tombol yang tidak mungkin dijalankan lebih buruk daripada tidak
 * menawarkannya.
 *
 * =====================================================================
 * Mapel ditanyakan di sini, bukan disimpulkan
 * =====================================================================
 * Seluruh isi berkas masuk ke SATU mapel, dan mapelnya tidak bisa ditebak
 * dari paketnya: impor tanpa paket adalah pemakaian yang sah. Guru yang
 * mengampu Matematika dan Fisika akan mendapat soal Fisika di bank soal
 * Matematika bila pilihannya disembunyikan - tersimpan rapi, tanpa galat,
 * dan baru ketahuan saat paketnya kosong.
 */

type Baris = {
    nomor: number | null;
    ringkas: string;
    jenis: string | null;
    jenis_pendek: string;
    sah: boolean;
    kolom: string | null;
    pesan: string | null;
};

type Pratinjau = {
    id: number;
    nama_asli: string;
    mapel: string | null;
    paket: string | null;
    status: string;
    status_label: string;
    boleh_konfirmasi: boolean;
    sah: number;
    gagal: number;
    pesan_berkas: string | null;
    kolom_terbaca: string[];
    baris: Baris[];
};

type Riwayat = {
    id: number;
    nama_asli: string;
    mapel: string | null;
    paket: string | null;
    status: string;
    status_label: string;
    sah: number;
    gagal: number;
    total: number;
    ada_laporan: boolean;
    diunggah_pada: string | null;
};

type Props = {
    riwayat: Riwayat[];
    aktif: Pratinjau | null;
    tujuan: {
        mapel: { id: number; nama: string; kode: string | null }[];
        paket: { id: number; nama: string; mapel_id: number; mapel: string | null }[];
        paket_terpilih: number | null;
        mapel_terpilih: number | null;
    };
    kolom: string[];
};

export default function Impor({ riwayat, aktif, tujuan, kolom }: Props) {
    const [kirim, setKirim] = useState(false);
    const [galat, setGalat] = useState<Record<string, string>>({});

    const [mapelId, setMapelId] = useState(String(tujuan.mapel_terpilih ?? ''));
    const [paketId, setPaketId] = useState(String(tujuan.paket_terpilih ?? ''));
    const [namaBerkas, setNamaBerkas] = useState<string | null>(null);

    const masukan = useRef<HTMLInputElement>(null);

    // Paket yang ditawarkan mengikuti mapel yang dipilih. Menawarkan paket
    // Fisika pada impor Matematika berarti PaketSoalService yang menolaknya
    // nanti - dan penolakan setelah Guru menekan tombol adalah penolakan
    // yang bisa dihindari sedari awal.
    const paketSejenis = tujuan.paket.filter((p) => String(p.mapel_id) === mapelId);

    const unggah = (berkas: File) => {
        if (kirim) {
            return;
        }

        setGalat({});
        setKirim(true);

        const form = new FormData();
        form.append('berkas', berkas);
        form.append('subject_id', mapelId);

        if (paketId !== '') {
            form.append('question_package_id', paketId);
        }

        router.post('/guru/bank-soal/impor/unggah', form, {
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

    const konfirmasi = (kePaket: boolean) => {
        if (kirim || aktif === null) {
            return;
        }

        setKirim(true);

        router.post(
            `/guru/bank-soal/impor/${aktif.id}/konfirmasi`,
            { ke_paket: kePaket },
            { preserveScroll: true, onFinish: () => setKirim(false) },
        );
    };

    const bolehUnggah = mapelId !== '' && !kirim;

    return (
        <GuruLayout
            judul="Impor soal"
            breadcrumb="/guru/bank-soal/impor"
            aksi={
                <a
                    href="/guru/bank-soal/impor/template"
                    className="rounded-wf border border-line px-3 py-1.5 text-xs font-medium text-ink-2 hover:bg-surface-2"
                >
                    Unduh template
                </a>
            }
        >
            <Head title="Impor soal" />

            <div className="mb-3">
                <Inbox>
                    Impor selalu berhenti di <strong>pratinjau</strong> sebelum menyimpan apa pun.
                    Baris yang gagal ditampilkan bersama nomor barisnya — nomor yang sama dengan yang
                    Anda lihat di Excel.
                </Inbox>
            </div>

            {/* ------------------------------------------------ 1. Unggah */}

            <section className="mb-4 rounded-wf border border-line bg-surface p-4">
                <h2 className="mb-3 text-xs font-medium tracking-wide text-ink-2 uppercase">
                    1. Pilih berkas
                </h2>

                <div className="mb-3 grid gap-3 sm:grid-cols-2">
                    <Field
                        label="Mata pelajaran tujuan"
                        galat={galat.subject_id}
                        petunjuk="Seluruh soal di berkas ini masuk ke mapel ini."
                    >
                        <select
                            value={mapelId}
                            onChange={(e) => {
                                setMapelId(e.target.value);
                                // Paket tujuan ikut dikosongkan: paket terikat
                                // satu mapel, dan membiarkannya terpilih setelah
                                // mapelnya berganti berarti paket yang akan
                                // ditolak service.
                                setPaketId('');
                            }}
                            className={kelasInput}
                        >
                            <option value="">Pilih mata pelajaran…</option>
                            {tujuan.mapel.map((m) => (
                                <option key={m.id} value={m.id}>
                                    {m.nama}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field
                        label="Paket tujuan"
                        galat={galat.question_package_id}
                        petunjuk="Opsional. Kosongkan untuk menyimpan ke bank soal saja."
                    >
                        <select
                            value={paketId}
                            onChange={(e) => setPaketId(e.target.value)}
                            disabled={mapelId === ''}
                            className={kelasInput}
                        >
                            <option value="">Ke bank soal saja</option>
                            {paketSejenis.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.nama}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>

                <label
                    className={
                        'flex cursor-pointer flex-col items-center gap-2 rounded-wf border border-dashed px-4 py-6 text-center transition-colors ' +
                        (bolehUnggah
                            ? 'border-line bg-surface-2/50 hover:border-accent'
                            : 'cursor-not-allowed border-line bg-surface-2 opacity-60')
                    }
                >
                    <input
                        ref={masukan}
                        type="file"
                        accept=".xlsx,.xlsm,.csv,.txt"
                        className="hidden"
                        disabled={!bolehUnggah}
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
                        {mapelId === ''
                            ? 'Pilih mata pelajaran tujuannya lebih dulu.'
                            : 'Batas 2 MB dan 2.000 baris per berkas. Format .xls lama tidak didukung.'}
                    </span>
                </label>

                {galat.berkas && (
                    <p className="mt-2 text-[11px] text-bad">{galat.berkas}</p>
                )}

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
                    <p className="mt-1.5 text-[11px] text-ink-3">
                        Urutan kolom bebas — yang dicari adalah <strong>namanya</strong>. Kolom wajib
                        hanya judul, jenis, dan soal. Kunci diisi berbeda per jenis: huruf untuk
                        pilihan ganda, <code>a=Jakarta; b=Bandung</code> untuk menjodohkan,
                        <code> c, a, b</code> untuk mengurutkan, dan
                        <code> Kriteria:4 | Kriteria lain:3</code> di kolom rubrik untuk uraian.
                    </p>
                </details>
            </section>

            {/* -------------------------------------------- 2. Pratinjau */}

            {aktif && (
                <section className="mb-4 rounded-wf border border-line bg-surface">
                    <header className="flex flex-wrap items-center gap-3 border-b border-line px-4 py-2.5">
                        <h2 className="text-xs font-medium tracking-wide text-ink-2 uppercase">
                            2. Pratinjau · {aktif.nama_asli}
                        </h2>
                        <div className="ml-auto flex flex-wrap items-center gap-2">
                            {aktif.mapel && <Chip nada="info">{aktif.mapel}</Chip>}
                            {aktif.paket && <Chip nada="mute">{aktif.paket}</Chip>}
                            {aktif.sah > 0 && <Chip nada="ok">{aktif.sah} valid</Chip>}
                            {aktif.gagal > 0 && <Chip nada="bad">{aktif.gagal} gagal</Chip>}
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
                                    <th className="px-2 py-2 font-medium">Ringkas isi</th>
                                    <th className="w-20 px-2 py-2 font-medium">Jenis</th>
                                    <th className="w-16 px-2 py-2 font-medium">Hasil</th>
                                    <th className="px-4 py-2 font-medium">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                {aktif.baris.map((b, i) => (
                                    <tr
                                        key={`${b.nomor}-${i}`}
                                        className="border-t border-line align-top"
                                    >
                                        <td className="px-4 py-2 font-mono text-ink-3">
                                            {b.nomor}
                                        </td>
                                        <td className="px-2 py-2 text-ink-2">{b.ringkas}</td>
                                        <td className="px-2 py-2 text-ink-3">{b.jenis_pendek}</td>
                                        <td className="px-2 py-2">
                                            <Chip nada={b.sah ? 'ok' : 'bad'}>
                                                {b.sah ? 'ok' : 'gagal'}
                                            </Chip>
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
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <footer className="flex flex-wrap items-center gap-3 border-t border-line px-4 py-3">
                        <span className="text-xs text-ink-2">
                            {aktif.sah} dari {aktif.sah + aktif.gagal} baris siap diimpor
                        </span>

                        {aktif.boleh_konfirmasi ? (
                            <div className="ml-auto flex flex-wrap gap-2">
                                <Tombol
                                    onClick={() => konfirmasi(false)}
                                    disabled={kirim || aktif.sah === 0}
                                >
                                    Impor ke bank saja
                                </Tombol>
                                {aktif.paket && (
                                    <Tombol
                                        nada="pri"
                                        onClick={() => konfirmasi(true)}
                                        disabled={kirim || aktif.sah === 0}
                                    >
                                        Impor &amp; tambah ke paket
                                    </Tombol>
                                )}
                            </div>
                        ) : (
                            <span className="ml-auto text-[11px] text-ink-3">
                                Berkas ini tidak bisa dikonfirmasi lagi.
                            </span>
                        )}
                    </footer>

                    <div className="border-t border-line px-4 py-2.5">
                        <p className="text-[11px] leading-relaxed text-ink-3">
                            Baris yang gagal <strong>tidak</strong> menggagalkan berkasnya —{' '}
                            {aktif.sah} soal tetap tersimpan, dan laporan galat memuat alamat baris
                            sisanya. Perbaiki berkasnya, lalu unggah ulang: berkas yang{' '}
                            <em>isinya sama</em> ditolak supaya soal tidak tergandakan.
                        </p>
                    </div>
                </section>
            )}

            {/* --------------------------------------------- 3. Riwayat */}

            {riwayat.length > 0 && (
                <section className="rounded-wf border border-line bg-surface">
                    <header className="border-b border-line px-4 py-2.5">
                        <h2 className="text-xs font-medium tracking-wide text-ink-2 uppercase">
                            Riwayat impor
                        </h2>
                    </header>

                    <table className="w-full text-xs">
                        <thead className="bg-surface-2 text-left text-ink-3">
                            <tr>
                                <th className="px-4 py-2 font-medium">Berkas</th>
                                <th className="px-2 py-2 font-medium">Mapel</th>
                                <th className="px-2 py-2 font-medium">Hasil</th>
                                <th className="px-2 py-2 font-medium">Status</th>
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
                                            {r.paket && ` · ${r.paket}`}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2 text-ink-3">{r.mapel ?? '—'}</td>
                                    <td className="px-2 py-2 text-ink-2">
                                        {r.total === 0 ? (
                                            <span className="text-ink-3">—</span>
                                        ) : (
                                            <>
                                                <span className="text-ok">{r.sah}</span>
                                                {r.gagal > 0 && (
                                                    <span className="text-bad"> · {r.gagal} gagal</span>
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
                                                href={`/guru/bank-soal/impor/${r.id}/laporan`}
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
                            atas berkas yang sama akan menggandakan seluruh soalnya.
                        </p>
                    </div>
                </section>
            )}
        </GuruLayout>
    );
}
