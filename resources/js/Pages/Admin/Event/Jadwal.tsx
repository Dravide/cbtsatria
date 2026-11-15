import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { konfirmasi, tanyaTeks } from '@/Components/dialog';
import {
    Chip,
    Field,
    Inbox,
    Kotak,
    Peringatan,
    Tombol,
    kelasInput,
    nadaStatus,
} from '@/Components/ui';

/**
 * Laman 16 - Jadwal & sesi. Wireframe plate 16, PRD §9.2-§9.5.
 *
 * ---------------------------------------------------------------------
 * Baris bentrok ditandai DI TABELNYA, dan itu permintaan eksplisit
 * ---------------------------------------------------------------------
 * Anotasi plate 16 berbunyi: "Baris bentrok ditandai di tabelnya sendiri,
 * bukan hanya diringkas di panel validasi, supaya Admin bisa memperbaiki
 * langsung dari baris yang bermasalah."
 *
 * Karena itu kolom `Ruang` menampilkan chip `bentrok` pada barisnya, dan
 * setiap temuan ditulis di bawah baris itu - bukan hanya dihitung di panel
 * bawah. Panelnya tetap ada; ia menjawab "apakah jadwal ini aman", sementara
 * tanda di baris menjawab "yang mana yang harus saya geser".
 *
 * ---------------------------------------------------------------------
 * Ujian dan sesi punya dua tombol terpisah, dan urutannya terbalik
 * ---------------------------------------------------------------------
 * "Tambah ujian" lebih dulu, "Tambah sesi" kedua, dan itu bukan urutan
 * tampilan: sesi menempel pada ujian, sehingga tombol sesi mati selama belum
 * ada satu ujian pun. Tombol yang hidup lalu menolak input lebih buruk
 * daripada tombol yang mati beserta alasannya.
 */
type Baris = {
    id: number;
    label: string;
    tanggal: string | null;
    jam: string | null;
    selesai: string | null;
    ujian_id: number;
    ujian: string;
    ruang: number;
    ruang_kode: string[];
    peserta: number;
    status: string;
    status_label: string;
    /** Kapan sesi dibatalkan, bila memang dibatalkan. */
    dibatalkan_pada: string | null;
    alasan_batal: string | null;
    /** Nilai mentah `Y-m-d\TH:i` untuk memuat ulang panel "Ubah sesi". */
    buka_iso: string | null;
    tutup_iso: string | null;
    akhir_iso: string | null;
    /** Id ruang-EVENT yang sedang dipakai sesi ini. */
    ruang_id: number[];
    bentrok: { jenis: string; pesan: string }[];
};

type Ujian = {
    id: number;
    kode: string;
    judul: string;
    mapel: string | null;
    mapel_id: number;
    jenis: string | null;
    durasi: number;
    instruksi: string | null;
    percobaan: number;
    acak_soal: boolean;
    acak_opsi: boolean;
    boleh_kembali: boolean;
    boleh_terlambat: boolean;
    /** Kelas sasaran yang sedang berlaku - untuk mencentang ulang panel ubah. */
    kelas_id: number[];
    /** Sudah punya paket soal yang dibekukan; mapelnya tidak boleh diganti lagi. */
    ada_paket: boolean;
    sesi: number;
};

type Props = {
    event: {
        id: number;
        kode: string;
        nama: string;
        /** Jenis kegiatan event - sumber "jenis ujian" sejak 2026-11-12. */
        jenis: string | null;
        status: string;
        status_label: string;
    };
    baris: Baris[];
    temuan: { jenis: string; pesan: string; sesi_id: number | null }[];
    validasi: { kunci: string; label: string; lulus: boolean; jumlah: number }[];
    ujian: Ujian[];
    ruang: { id: number; kode: string | null; nama: string | null; kapasitas: number; status: string }[];
    kelas: { id: number; nama: string; tingkat: string | null }[];
    mapel: { id: number; kode: string | null; nama: string | null }[];
    angka: { ujian: number; sesi: number; konflik: number; belum_ditempatkan: number };
};

export default function EventJadwal({
    event,
    baris,
    temuan,
    validasi,
    ujian,
    ruang,
    kelas,
    mapel,
    angka,
}: Props) {
    const [panelUjian, setPanelUjian] = useState(false);
    const [panelSesi, setPanelSesi] = useState(false);
    const [pesanUjian, setPesanUjian] = useState<string | null>(null);

    const [ujianDiubah, setUjianDiubah] = useState<Ujian | null>(null);
    const [sesiDiubah, setSesiDiubah] = useState<Baris | null>(null);

    // Penyaring tabel. Sebelumnya tidak ada satu pun, sehingga event dengan
    // enam ujian menampilkan seluruh sesinya sekaligus dan Admin menggulir
    // untuk menemukan satu sesi. Ketiganya bekerja bersama.
    const [saringUjian, setSaringUjian] = useState(0);
    const [saringStatus, setSaringStatus] = useState('semua');
    const [cari, setCari] = useState('');

    // Sesi yang dibuka barisnya untuk melihat kenapa ia bentrok / dibatalkan.
    // Satu id pada satu waktu: membuka semuanya sekaligus mengembalikan
    // masalah yang justru ingin dihilangkan penyaring.
    const [terbuka, setTerbuka] = useState<number | null>(null);

    const jalur = `/admin/events/${event.id}`;

    const barisBentrok = temuan.filter((t) => t.sesi_id !== null).length;

    const tersaring = baris.filter((b) => {
        if (saringUjian !== 0 && b.ujian_id !== saringUjian) {
            return false;
        }
        if (saringStatus !== 'semua' && b.status !== saringStatus) {
            return false;
        }
        if (cari.trim() !== '') {
            const q = cari.trim().toLowerCase();
            const teks = `${b.label} ${b.ujian} ${b.ruang_kode.join(' ')}`.toLowerCase();
            if (!teks.includes(q)) {
                return false;
            }
        }
        return true;
    });

    const grup = kelompokkan(tersaring);
    const sesiTampil = tersaring.length;

    // Status sesi yang tersedia untuk mengisi pilihan penyaring - diambil dari
    // data, bukan didaftar tetap: pilihan yang tidak pernah muncul di tabel
    // adalah pilihan yang menuntun ke layar kosong.
    const statusAda = [...new Set(baris.map((b) => b.status))].sort();

    return (
        <AdminLayout
            judul="Jadwal & sesi"
            breadcrumb={`/admin/events · ${event.kode} · Jadwal & sesi`}
            aksi={
                <>
                    <PemilihEvent event={event} />

                    <Tombol
                        onClick={() => router.post(`${jalur}/jadwal/deteksi`, {}, { preserveScroll: true })}
                        title="Periksa ulang jadwal dan laporkan hasilnya"
                    >
                        Deteksi konflik
                    </Tombol>

                    <Tombol onClick={() => setPanelUjian(true)}>Tambah ujian</Tombol>

                    <Tombol
                        nada="pri"
                        disabled={ujian.length === 0}
                        title={
                            ujian.length === 0
                                ? 'Buat ujian terlebih dahulu - sesi menempel pada ujian.'
                                : undefined
                        }
                        onClick={() => setPanelSesi(true)}
                    >
                        Tambah sesi
                    </Tombol>
                </>
            }
        >
            <Head title={`Jadwal & sesi · ${event.nama}`} />

            {/* ------------------------------------------------ ringkasan */}
            {/* Empat angka tetap, tetapi lebih rapat: satu baris tipis, bukan
                empat kartu setinggi 100px. Angka yang selalu nol (konflik,
                belum ditempatkan) tetap tampil - nol yang berwarna hijau
                adalah jawaban "jadwal ini aman", dan itu justru yang dicari. */}
            <div className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <KPI label="Ujian" nilai={angka.ujian} />
                <KPI label="Sesi" nilai={angka.sesi} />
                <KPI
                    label="Konflik jadwal"
                    nilai={angka.konflik}
                    nada={angka.konflik > 0 ? 'bad' : 'ok'}
                />
                <KPI
                    label="Belum ditempatkan"
                    nilai={angka.belum_ditempatkan}
                    nada={angka.belum_ditempatkan > 0 ? 'warn' : 'ok'}
                />
            </div>

            {/* Hasil validasi dipindah ke ATAS tabel sebagai satu strip tipis.
                Dulu ia kotak tersendiri di bawah, bersebelahan dengan daftar
                ujian - dan pada event satu-ujian keduanya menjadi dua kotak
                besar yang isinya tiga baris. Strip di sini menjawab
                "apakah jadwal ini aman" sebelum Admin membaca tabelnya. */}
            <ValidasiStrip validasi={validasi} adaTemuan={temuan.length > 0} />

            {temuan.length > 0 && (
                <div className="mt-2 space-y-2">
                    {temuan.slice(0, 4).map((t, i) => (
                        <div
                            key={`${t.jenis}-${i}`}
                            className="rounded-wf border border-bad/30 bg-bad-bg px-3 py-2.5 text-xs leading-relaxed text-bad"
                        >
                            {t.pesan}
                        </div>
                    ))}
                    {temuan.length > 4 && (
                        <p className="text-xs text-ink-3">
                            +{temuan.length - 4} temuan lain &mdash; perbaiki yang di atas lebih dulu.
                        </p>
                    )}
                </div>
            )}

            {/* -------------------------------------------------- panel form */}
            {panelUjian && (
                <div className="mt-4">
                    <FormUjian
                        jalur={jalur}
                        mapel={mapel}
                        kelas={kelas}
                        jenisEvent={event.jenis}
                        padaTutup={() => setPanelUjian(false)}
                        padaGalat={setPesanUjian}
                    />
                </div>
            )}

            {ujianDiubah && (
                <div className="mt-4">
                    <FormUjian
                        key={ujianDiubah.id}
                        jalur={jalur}
                        ujianAwal={ujianDiubah}
                        mapel={mapel}
                        kelas={kelas}
                        jenisEvent={event.jenis}
                        padaTutup={() => setUjianDiubah(null)}
                        padaGalat={setPesanUjian}
                    />
                </div>
            )}

            {panelSesi && (
                <div className="mt-4">
                    <FormSesi
                        jalur={jalur}
                        ujian={ujian}
                        ruang={ruang}
                        padaTutup={() => setPanelSesi(false)}
                    />
                </div>
            )}

            {sesiDiubah && (
                <div className="mt-4">
                    <FormSesi
                        jalur={jalur}
                        ujian={ujian}
                        ruang={ruang}
                        sesiAwal={sesiDiubah}
                        padaTutup={() => setSesiDiubah(null)}
                    />
                </div>
            )}

            {pesanUjian && (
                <div className="mt-4">
                    <Peringatan>{pesanUjian}</Peringatan>
                </div>
            )}

            {/* ------------------------------------------------- penyaring */}
            {/* Satu baris, bukan tiga kontrol bertumpuk. Muncul hanya bila ada
                sesuatu untuk disaring - pada event satu-sesi, penyaring adalah
                tiga kontrol yang tidak menyaring apa pun. */}
            {baris.length > 1 && (
                <div className="mt-4 flex flex-wrap items-center gap-2 rounded-wf border border-line bg-surface px-3 py-2">
                    <input
                        value={cari}
                        onChange={(e) => setCari(e.target.value)}
                        placeholder="Cari sesi, ujian, atau ruang"
                        className="min-w-0 flex-1 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink outline-none placeholder:text-ink-3 focus:border-accent focus:ring-2 focus:ring-accent/15 sm:max-w-xs"
                    />

                    <select
                        value={saringUjian}
                        onChange={(e) => setSaringUjian(Number(e.target.value))}
                        className="rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink outline-none focus:border-accent"
                        aria-label="Saring menurut ujian"
                    >
                        <option value={0}>Semua ujian</option>
                        {ujian.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.kode} · {u.mapel ?? u.judul}
                            </option>
                        ))}
                    </select>

                    <select
                        value={saringStatus}
                        onChange={(e) => setSaringStatus(e.target.value)}
                        className="rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink outline-none focus:border-accent"
                        aria-label="Saring menurut status"
                    >
                        <option value="semua">Semua status</option>
                        {statusAda.map((s) => (
                            <option key={s} value={s}>
                                {labelStatus(s)}
                            </option>
                        ))}
                    </select>

                    {(cari !== '' || saringUjian !== 0 || saringStatus !== 'semua') && (
                        <button
                            type="button"
                            onClick={() => {
                                setCari('');
                                setSaringUjian(0);
                                setSaringStatus('semua');
                            }}
                            className="text-xs text-ink-3 underline-offset-2 hover:text-ink hover:underline"
                        >
                            Bersihkan
                        </button>
                    )}

                    <span className="ml-auto text-[11px] text-ink-3">
                        {sesiTampil === baris.length
                            ? `${baris.length} sesi`
                            : `${sesiTampil} dari ${baris.length} sesi`}
                    </span>
                </div>
            )}

            {/* ---------------------------------------------------- tabel */}
            <div className="mt-3 overflow-hidden rounded-wf border border-line bg-surface">
                {baris.length === 0 ? (
                    <p className="px-4 py-8 text-center text-sm text-ink-3">
                        Belum ada sesi. Buat ujian, lalu tambahkan sesinya.
                    </p>
                ) : grup.length === 0 ? (
                    <p className="px-4 py-8 text-center text-sm text-ink-3">
                        Tidak ada sesi yang cocok dengan penyaring ini.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[44rem] text-sm">
                            <thead>
                                <tr className="border-b border-line bg-surface-2/50 text-left">
                                    {['Ujian', 'Ruang', 'Peserta', 'Status', ''].map((h, i) => (
                                        <th
                                            key={h || i}
                                            className={
                                                'px-3 py-2 text-[11px] font-medium text-ink-3 ' +
                                                ['', 'w-32', 'w-20', 'w-28', 'w-16'][i]
                                            }
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            {grup.map((g) => (
                                <tbody key={g.kunci}>
                                    {/* Kepala kelompok = SATU slot waktu. Inilah
                                        yang menghilangkan pengulangan: tanggal
                                        dan jam yang sama dulu ditulis ulang di
                                        setiap baris, padahal ia milik waktunya,
                                        bukan milik sesinya. */}
                                    <tr className="border-b border-line bg-surface-2/30">
                                        <td colSpan={5} className="px-3 py-1.5">
                                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                                {g.label && (
                                                    <span className="text-xs font-medium text-ink">
                                                        {g.label}
                                                    </span>
                                                )}
                                                <span className="font-mono text-xs font-medium text-ink">
                                                    {g.tanggal ?? '—'}
                                                </span>
                                                <span className="font-mono text-xs text-ink-2">
                                                    {g.jam ?? '—'}
                                                    {g.selesai && (
                                                        <span className="text-ink-3"> – {g.selesai}</span>
                                                    )}
                                                </span>
                                                <span className="text-[11px] text-ink-3">
                                                    {g.sesi.length} sesi · {g.peserta} peserta
                                                </span>
                                                {g.konflik > 0 && (
                                                    <Chip nada="bad">{g.konflik} bentrok</Chip>
                                                )}
                                                {!g.final && <Chip nada="mute">belum lengkap</Chip>}
                                            </div>
                                        </td>
                                    </tr>

                                    {g.sesi.map((b) => (
                                        <BarisSesi
                                            key={b.id}
                                            jalur={jalur}
                                            baris={b}
                                            terbuka={terbuka === b.id}
                                            padaBuka={() =>
                                                setTerbuka(terbuka === b.id ? null : b.id)
                                            }
                                            padaUbah={setSesiDiubah}
                                        />
                                    ))}
                                </tbody>
                            ))}
                        </table>
                    </div>
                )}
            </div>

            {/* Temuan yang menempel pada baris dibaca dari barisnya sendiri
                (tombol di kolom paling kanan membuka keterangannya). Yang di
                sini hanya ringkasannya, supaya jumlahnya tidak hilang. */}
            {barisBentrok > 0 && (
                <p className="mt-2 text-xs text-ink-3">
                    {barisBentrok} sesi bertanda <span className="text-bad">bentrok</span> &mdash;
                    tekan <em>Rincian</em> pada barisnya untuk melihat penyebabnya.
                </p>
            )}

            {/* -------------------------------------------------- kelola ujian */}
            {/* Dulu panel "Ujian di event ini" berdiri di kolom kanan
                bersebelahan dengan validasi. Isinya kini ikut kelompok waktu
                di tabel, jadi satu-satunya yang tersisa di sini adalah
                PENGELOLAAN ujiannya - dan itu jarang dipakai, sehingga
                dilipat. */}
            <details className="group mt-4 rounded-wf border border-line bg-surface">
                <summary className="flex cursor-pointer items-center gap-2 px-3 py-2.5 text-xs text-ink-2 hover:text-ink">
                    <span className="font-medium">Kelola ujian</span>
                    <span className="text-ink-3">({ujian.length})</span>
                    <span className="ml-auto text-[11px] text-ink-3 group-open:hidden">
                        judul, durasi, kelas sasaran
                    </span>
                </summary>

                <div className="border-t border-line">
                    {ujian.length === 0 ? (
                        <p className="px-3 py-3 text-xs text-ink-3">
                            Belum ada ujian. Tekan <em>Tambah ujian</em> untuk membuatnya.
                        </p>
                    ) : (
                        <ul className="divide-y divide-line">
                            {ujian.map((u) => (
                                <li key={u.id} className="flex flex-wrap items-center gap-3 px-3 py-2 text-sm">
                                    <span className="font-mono text-xs text-ink-3">{u.kode}</span>
                                    <span className="text-ink">{u.mapel ?? u.judul}</span>
                                    <span className="ml-auto font-mono text-xs text-ink-3">
                                        {u.durasi} mnt
                                    </span>
                                    <span className="font-mono text-xs text-ink-3">{u.sesi} sesi</span>
                                    <Tombol
                                        nada="sek"
                                        title="Ubah judul, durasi, kelas sasaran, dan perilaku ujian"
                                        onClick={() => setUjianDiubah(u)}
                                    >
                                        Ubah
                                    </Tombol>
                                    <Tombol
                                        nada="bahaya"
                                        title="Hapus ujian beserta jadwalnya"
                                        onClick={async () => {
                                            const setuju = await konfirmasi({
                                                judul: `Hapus ${u.kode} ${u.judul}?`,
                                                pesan: 'Seluruh sesinya yang belum berjalan ikut terhapus.',
                                                labelYa: 'Hapus ujian',
                                                nada: 'bahaya',
                                            });

                                            if (!setuju) {
                                                return;
                                            }

                                            router.delete(`${jalur}/ujian/${u.id}`, {
                                                preserveScroll: true,
                                            });
                                        }}
                                    >
                                        Hapus
                                    </Tombol>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </details>

            <div className="mt-3">
                <Inbox>
                    Tanggal dan jam di tabel ini adalah jendela sesinya, bukan durasi ujiannya.
                    Sesi yang selesai tepat ketika sesi berikutnya dibuka{' '}
                    <strong>tidak dihitung bentrok</strong> &mdash; menyusun jadwal rapat seperti
                    itu justru cara memakai ruang dengan efisien. Yang dihitung bentrok adalah
                    jendela yang benar-benar bertumpang.
                </Inbox>
            </div>
        </AdminLayout>
    );
}

/* ------------------------------------------------------- Kelompokkan per waktu */

type Grup = {
    kunci: string;
    /** Label sesi yang berlaku di slot ini, mis. "Sesi 1". */
    label: string | null;
    tanggal: string | null;
    jam: string | null;
    selesai: string | null;
    sesi: Baris[];
    konflik: number;
    peserta: number;
    final: boolean;
};

/**
 * Mengelompokkan sesi menurut slot waktunya.
 *
 * Urutan kelompok mengikuti urutan `baris` yang datang dari server - sudah
 * diurutkan `access_opens_at` di sana. Mengurutkan ulang di sini berarti dua
 * tempat yang harus sepakat soal urutan, dan yang di layar selalu yang
 * terakhir ditulis.
 *
 * Label sesi ikut jadi kunci kelompok, bukan kolom tersendiri. Dalam satu slot
 * waktu labelnya memang seragam; kalau sekolah menjadwalkan sesi berbeda di
 * jam yang sama, keduanya berdiri sebagai kelompok terpisah dan labelnya
 * terbaca di kepala masing-masing - lebih jelas daripada angka telanjang yang
 * berulang di setiap baris.
 */
function kelompokkan(baris: Baris[]): Grup[] {
    const peta = new Map<string, Grup>();

    for (const b of baris) {
        const kunci = `${b.tanggal ?? '—'}|${b.jam ?? '—'}|${b.label}`;
        let g = peta.get(kunci);

        if (!g) {
            g = {
                kunci,
                label: b.label,
                tanggal: b.tanggal,
                jam: b.jam,
                // Jam selesai terakhir pada slot ini, supaya rentangnya utuh
                // ketika dua sesi di slot yang sama berakhir berbeda.
                selesai: b.selesai,
                sesi: [],
                konflik: 0,
                peserta: 0,
                final: true,
            };
            peta.set(kunci, g);
        }

        g.sesi.push(b);
        g.konflik += b.bentrok.length;
        g.peserta += b.peserta;

        if (b.selesai && (!g.selesai || b.selesai > g.selesai)) {
            g.selesai = b.selesai;
        }

        // "Final" hanya bila SELURUH sesi di slot itu sudah selesai/batal -
        // satu sesi yang masih Draft berarti jadwal slot ini belum lengkap.
        if (b.status !== 'selesai' && b.status !== 'batal') {
            g.final = false;
        }
    }

    return [...peta.values()];
}

/* ------------------------------------------------------------- Satu baris sesi */

function BarisSesi({
    jalur,
    baris,
    terbuka,
    padaBuka,
    padaUbah,
}: {
    jalur: string;
    baris: Baris;
    terbuka: boolean;
    padaBuka: () => void;
    padaUbah: (baris: Baris) => void;
}) {
    // Sesi yang sudah Selesai atau Batal bersifat final: jendela dan ruangnya
    // tidak bisa diubah lagi, karena peserta mungkin sudah mengerjakan.
    const final = baris.status === 'selesai' || baris.status === 'batal';

    return (
        <>
            <tr className="border-b border-line last:border-0 align-top">
                <td className="px-3 py-2">
                    <span className="text-sm text-ink">
                        {baris.ujian.replace(/\s*·\s*semua kelas$/, '')}
                    </span>
                    {/·\s*semua kelas$/.test(baris.ujian) && (
                        <span className="ml-1.5 text-[11px] text-ink-3">semua kelas</span>
                    )}
                </td>

                <td className="px-3 py-2">
                    {baris.bentrok.length > 0 ? (
                        <span className="flex items-center gap-2">
                            <Chip nada="bad">bentrok</Chip>
                            <span className="font-mono text-[11px] text-ink-3">
                                {baris.ruang} ruang
                            </span>
                        </span>
                    ) : (
                        <span className="font-mono text-xs text-ink-2">
                            {baris.ruang_kode.length > 0 ? baris.ruang_kode.join(', ') : '—'}
                        </span>
                    )}
                </td>

                <td className="px-3 py-2">
                    <span className="font-mono text-xs text-ink-2">{baris.peserta}</span>
                </td>

                <td className="px-3 py-2">
                    <span
                        title={
                            baris.alasan_batal ??
                            (baris.status === 'batal' ? 'Dibatalkan tanpa keterangan.' : undefined)
                        }
                    >
                        <Chip nada={nadaStatus(baris.status)}>{baris.status_label}</Chip>
                    </span>
                </td>

                {/* Satu tombol utama (langkah berikutnya) + satu menu. Dulu
                    empat tombol sejajar selalu tampil; di event dua belas sesi
                    itu empat puluh delapan tombol, dan yang paling sering
                    dibutuhkan - "langkah berikutnya" - tenggelam di antaranya. */}
                <td className="px-3 py-2">
                    <div className="flex justify-end gap-1.5">
                        <LangkahBerikut jalur={jalur} baris={baris} />
                        <AksiLainnya
                            jalur={jalur}
                            baris={baris}
                            final={final}
                            terbuka={terbuka}
                            padaBuka={padaBuka}
                            padaUbah={padaUbah}
                        />
                    </div>
                </td>
            </tr>

            {terbuka && (
                <tr className="border-b border-line bg-surface-2/20 last:border-0">
                    <td colSpan={5} className="px-3 py-2">
                        <div className="space-y-1 text-xs leading-relaxed">
                            {baris.bentrok.length > 0 ? (
                                baris.bentrok.map((t, i) => (
                                    <p key={i} className="text-bad">
                                        <span className="font-medium">{t.jenis}</span> {t.pesan}
                                    </p>
                                ))
                            ) : (
                                <p className="text-ok">Tidak ada konflik pada sesi ini.</p>
                            )}

                            {baris.alasan_batal && (
                                <p className="text-ink-2">
                                    <span className="font-medium">Dibatalkan:</span>{' '}
                                    {baris.alasan_batal}
                                    {baris.dibatalkan_pada && (
                                        <span className="text-ink-3">
                                            {' '}
                                            · {baris.dibatalkan_pada.slice(0, 16).replace('T', ' ')}
                                        </span>
                                    )}
                                </p>
                            )}

                            <p className="text-ink-3">
                                Ruang:{' '}
                                {baris.ruang_kode.length > 0 ? baris.ruang_kode.join(', ') : 'belum ada'}
                                {' · '}Peserta: {baris.peserta}
                            </p>
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}

/**
 * Tombol "langkah berikutnya" sesi - satu saja, dan yang benar untuk statusnya.
 *
 * Graf StatusSesi memaksa urutannya (Draft -> Siap -> Berjalan -> Selesai), dan
 * menampilkan tiga tombol sekaligus berarti dua di antaranya selalu mati.
 */
function LangkahBerikut({ jalur, baris }: { jalur: string; baris: Baris }) {
    const kirim = (aksi: string) =>
        router.post(`${jalur}/ujian/${baris.ujian_id}/sesi/${baris.id}/${aksi}`, {}, { preserveScroll: true });

    if (baris.status === 'draft') {
        // Nada `sek`, bukan `pri`. Ini langkah yang WAJIB, tetapi pada jadwal
        // dua belas sesi ia muncul dua belas kali - dan dua belas tombol biru
        // pekat dalam satu layar berhenti menandai apa pun. Yang menandai
        // prioritas di laman ini adalah kolom Status: mata mencari chip Draft.
        return (
            <Tombol
                nada="sek"
                title="Tandai jendela sesi sudah final, supaya sesi bisa dibuka."
                onClick={() => kirim('siapkan')}
            >
                Siapkan
            </Tombol>
        );
    }

    if (baris.status === 'siap') {
        return (
            <Tombol nada="pri" title="Buka sesi supaya siswa bisa masuk." onClick={() => kirim('mulai')}>
                Buka
            </Tombol>
        );
    }

    if (baris.status === 'berjalan') {
        return (
            <Tombol nada="sek" title="Tutup sesi: pengerjaan dihentikan." onClick={() => kirim('tutup')}>
                Tutup
            </Tombol>
        );
    }

    // Selesai / batal: tidak ada langkah berikutnya. Yang tersisa hanya
    // keterangan, dan itu ada di menu.
    return null;
}

/**
 * Menu tindakan yang jarang dipakai: Ubah, Batalkan, Hapus, Rincian.
 *
 * Memakai <details> supaya tertutup sendiri dan tidak menuntut JavaScript
 * penutup - klik di luar tidak menutupnya, tetapi Escape dan memilih satu
 * tindakan menutupnya, dan itu cukup untuk menu sel baris.
 */
function AksiLainnya({
    jalur,
    baris,
    final,
    terbuka,
    padaBuka,
    padaUbah,
}: {
    jalur: string;
    baris: Baris;
    final: boolean;
    terbuka: boolean;
    padaBuka: () => void;
    padaUbah: (baris: Baris) => void;
}) {
    return (
        <details className="relative">
            <summary
                className="grid size-8 cursor-pointer place-items-center rounded-wf border border-line text-xs text-ink-2 hover:bg-surface-2 hover:text-ink"
                title="Tindakan lain"
                aria-label="Tindakan lain"
            >
                ⋯
            </summary>

            <div className="absolute right-0 z-20 mt-1 w-44 overflow-hidden rounded-wf border border-line bg-surface shadow-wf">
                <button
                    type="button"
                    onClick={() => {
                        padaBuka();
                    }}
                    className="block w-full px-3 py-2 text-left text-xs text-ink-2 hover:bg-surface-2 hover:text-ink"
                >
                    {terbuka ? 'Tutup rincian' : 'Rincian'}
                </button>

                <button
                    type="button"
                    disabled={final}
                    title={final ? 'Sesi yang sudah selesai atau batal tidak bisa diubah lagi.' : undefined}
                    onClick={() => padaUbah(baris)}
                    className="block w-full px-3 py-2 text-left text-xs text-ink-2 hover:bg-surface-2 hover:text-ink disabled:cursor-not-allowed disabled:text-ink-3/50"
                >
                    Ubah jendela &amp; ruang
                </button>

                {!final && (
                    <button
                        type="button"
                        onClick={async () => {
                            const alasan = await tanyaTeks({
                                judul: `Batalkan ${baris.label}?`,
                                pesan: 'Token sesi ini dicabut, dan sesi tidak dihitung lagi di jadwal.',
                                label: 'Alasan pembatalan (boleh dikosongkan)',
                                petunjuk: 'Alasan ini dibaca Admin berikutnya yang menyusun ulang jadwal.',
                                wajib: false,
                                maksimal: 200,
                                labelYa: 'Batalkan sesi',
                                labelTidak: 'Jangan',
                            });

                            if (alasan === null) {
                                return;
                            }

                            router.post(
                                `${jalur}/ujian/${baris.ujian_id}/sesi/${baris.id}/batal`,
                                { alasan },
                                { preserveScroll: true },
                            );
                        }}
                        className="block w-full px-3 py-2 text-left text-xs text-ink-2 hover:bg-surface-2 hover:text-ink"
                    >
                        Batalkan sesi
                    </button>
                )}

                <button
                    type="button"
                    onClick={async () => {
                        const setuju = await konfirmasi({
                            judul: `Hapus ${baris.label}?`,
                            pesan: 'Pesertanya dilepas dari ruang ini dan harus ditempatkan ulang. Berita acara yang sudah dikirim atau insiden yang tercatat akan menolak penghapusan.',
                            labelYa: 'Hapus sesi',
                            nada: 'bahaya',
                        });

                        if (!setuju) {
                            return;
                        }

                        router.delete(`${jalur}/ujian/${baris.ujian_id}/sesi/${baris.id}`, {
                            preserveScroll: true,
                        });
                    }}
                    className="block w-full border-t border-line px-3 py-2 text-left text-xs text-bad hover:bg-bad-bg"
                >
                    Hapus sesi
                </button>
            </div>
        </details>
    );
}

/* ------------------------------------------------------------- Strip validasi */

function ValidasiStrip({
    validasi,
    adaTemuan,
}: {
    validasi: Props['validasi'];
    adaTemuan: boolean;
}) {
    const gagal = validasi.filter((v) => !v.lulus).length;

    return (
        <div
            className={
                'rounded-wf border px-3 py-2 ' +
                (gagal > 0 || adaTemuan
                    ? 'border-warn/40 bg-warn-bg'
                    : 'border-ok/30 bg-ok-bg')
            }
        >
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px]">
                <span
                    className={
                        'font-medium ' + (gagal > 0 || adaTemuan ? 'text-warn' : 'text-ok')
                    }
                >
                    {gagal > 0 || adaTemuan ? 'Perlu diperiksa' : 'Jadwal aman'}
                </span>

                {validasi.map((v) => (
                    <span key={v.kunci} className="flex items-center gap-1.5">
                        <span
                            className={
                                'inline-flex h-3.5 w-3.5 items-center justify-center rounded-full text-[9px] ' +
                                (v.lulus ? 'bg-ok text-on-accent' : 'bg-warn/20 text-warn')
                            }
                        >
                            {v.lulus ? '✓' : '!'}
                        </span>
                        <span className={v.lulus ? 'text-ink-2' : 'text-ink'}>
                            {v.label}
                            {!v.lulus && v.jumlah > 0 && (
                                <span className="ml-1 font-medium text-bad">({v.jumlah})</span>
                            )}
                        </span>
                    </span>
                ))}
            </div>
        </div>
    );
}

function labelStatus(status: string): string {
    return (
        {
            draft: 'Draft',
            siap: 'Siap',
            berjalan: 'Berjalan',
            selesai: 'Selesai',
            batal: 'Batal',
        }[status] ?? status
    );
}

/* ------------------------------------------------------------ Pemilih event */

function PemilihEvent({ event }: { event: Props['event'] }) {
    return (
        <span className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink-2">
            <span className="text-ink-3">Event:</span>
            <span className="font-medium text-ink">{event.nama}</span>
        </span>
    );
}

/* --------------------------------------------------------------- Panel ujian */

function FormUjian({
    jalur,
    ujianAwal,
    mapel,
    kelas,
    jenisEvent,
    padaTutup,
    padaGalat,
}: {
    jalur: string;
    /**
     * Ujian yang diubah. Kosong berarti mode TAMBAH - sama polanya dengan
     * `FormSesi`, dan alasannya sama: form ubah yang terbuka KOSONG pada
     * ujian yang sudah ada terbaca seperti "ujiannya hilang".
     */
    ujianAwal?: Ujian;
    mapel: Props['mapel'];
    kelas: Props['kelas'];
    /** Jenis kegiatan event - dipakai untuk judul, bukan dipilih per ujian. */
    jenisEvent: string | null;
    padaTutup: () => void;
    padaGalat: (pesan: string | null) => void;
}) {
    const mengubah = ujianAwal !== undefined;

    const [isi, setIsi] = useState(() => ({
        subject_id: ujianAwal?.mapel_id ?? mapel[0]?.id ?? 0,
        // `title` TIDAK dikirim saat menambah: judulnya dibangkitkan server
        // dari jenis event + mapel (`ExamRegistrar::judulOtomatis()`). Saat
        // mengubah, nilai yang ada tetap dikirim balik apa adanya - judul
        // ujian lama boleh berbentuk apa pun, dan menghitungnya ulang di sini
        // berarti menimpa nama yang sudah dipakai di jadwal tercetak.
        title: ujianAwal?.judul ?? '',
        // `exam_type` TIDAK ada di sini sejak 2026-11-12. Jenisnya milik
        // event (`school_events.event_type`, disunting di laman Ubah event),
        // jadi memilihnya lagi per ujian hanya menciptakan dua tempat yang
        // bisa berselisih. Ujian lama yang sudah punya nilai tidak diubah:
        // `perbarui()` hanya menulis kunci yang dikirim.
        duration_minutes: ujianAwal?.durasi ?? 60,
        max_attempts: ujianAwal?.percobaan ?? 1,
        instructions: ujianAwal?.instruksi ?? '',
        allow_back_navigation: ujianAwal?.boleh_kembali ?? true,
        shuffle_questions: ujianAwal?.acak_soal ?? false,
        shuffle_options: ujianAwal?.acak_opsi ?? false,
        allow_late_entry: ujianAwal?.boleh_terlambat ?? false,
    }));

    const [pilihKelas, setPilihKelas] = useState<number[]>(ujianAwal?.kelas_id ?? []);

    // Nama mapel yang sedang dipilih - untuk pratinjau judul. `mapel[0]`
    // adalah nilai awalnya, sama dengan `subject_id` di atas.
    const namaMapelTerpilih =
        mapel.find((m) => m.id === isi.subject_id)?.nama ?? mapel[0]?.nama ?? '';

    // Tingkat untuk pratinjau judul. Aturannya SAMA dengan
    // `ExamRegistrar::tingkat()`: dari kelas sasaran yang dicentang, dan
    // hanya bila seluruhnya satu tingkat. Kelas yang mencampur dua tingkat -
    // atau belum dicentang sama sekali - berhenti tanpa `#`.
    const tingkatTerpilih = (() => {
        if (pilihKelas.length === 0) {
            return null;
        }

        const tingkat = [
            ...new Set(
                pilihKelas
                    .map((id) => kelas.find((k) => k.id === id)?.tingkat)
                    .filter((t): t is string => Boolean(t && t.trim())),
            ),
        ];

        return tingkat.length === 1 ? tingkat[0] : null;
    })();

    const simpan = () => {
        padaGalat(null);

        const opsi = {
            preserveScroll: true,
            onSuccess: () => padaTutup(),
            onError: (e: Record<string, string>) =>
                padaGalat(Object.values(e)[0] ?? 'Ujian tidak bisa disimpan.'),
        };

        if (mengubah) {
            router.put(`${jalur}/ujian/${ujianAwal.id}`, { ...isi, class_id: pilihKelas }, opsi);

            return;
        }

        // Tanpa `title`: server yang membangkitkannya. Mengirim string kosong
        // akan melewati `filled()` di `buat()` dan tetap jatuh ke jalur yang
        // sama - tetapi mengirimnya berarti laman ini mengaku tahu judulnya,
        // dan bentuk judul itu hidup di satu tempat saja.
        const { title: _judulLama, ...tanpaJudul } = isi;

        router.post(`${jalur}/ujian`, { ...tanpaJudul, class_id: pilihKelas }, opsi);
    };

    if (mapel.length === 0) {
        return (
            <Kotak judul={mengubah ? `Ubah ${ujianAwal.kode}` : 'Tambah ujian'}>
                <Peringatan>
                    Belum ada mata pelajaran yang ditetapkan di event ini. Tetapkan mapelnya di
                    laman <strong>Paket soal</strong> terlebih dahulu.
                </Peringatan>
            </Kotak>
        );
    }

    return (
        <Kotak
            judul={mengubah ? `Ubah ${ujianAwal.kode}` : 'Tambah ujian'}
            aksi={
                <>
                    <Tombol nada="sek" onClick={padaTutup}>
                        Batal
                    </Tombol>
                    <Tombol nada="pri" onClick={simpan}>
                        {mengubah ? 'Simpan perubahan' : 'Simpan ujian'}
                    </Tombol>
                </>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Field
                    label="Mata pelajaran"
                    petunjuk={
                        mengubah && ujianAwal.ada_paket
                            ? 'Soal sudah dibekukan dari mapel ini, jadi mapelnya tidak bisa diganti.'
                            : undefined
                    }
                >
                    <select
                        value={isi.subject_id}
                        onChange={(e) => setIsi({ ...isi, subject_id: Number(e.target.value) })}
                        // Mapel yang sudah punya paket terkunci: snapshot soalnya
                        // berasal dari mapel lama, dan ujian berlabel Fisika
                        // berisi soal Matematika tidak terlihat di layar mana
                        // pun sampai siswa membuka soal pertama.
                        disabled={mengubah && ujianAwal.ada_paket}
                        className={kelasInput}
                    >
                        {mapel.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.kode} {m.nama}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field
                    label="Jenis"
                    petunjuk="Mengikuti jenis kegiatan event. Ubah di laman Ubah event bila perlu."
                >
                    <p className="rounded-wf border border-line bg-surface-2/60 px-2.5 py-1.5 text-sm text-ink">
                        {jenisEvent || '—'}
                    </p>
                </Field>

                {/* Judul dibangkitkan server, tetapi Admin tetap melihat
                    bentuknya SEBELUM menyimpan - dan bentuk yang dilihatnya
                    dihitung dengan aturan yang sama: jenis, lalu mapel.
                    Tanpa ini, "Tambah ujian" menyimpan baris yang namanya
                    tidak ditampilkan di mana pun di panel ini. */}
                <Field
                    label="Judul ujian"
                    petunjuk={
                        mengubah
                            ? 'Judul ujian lama tidak dihitung ulang - ia sudah dipakai di jadwal dan berita acara.'
                            : 'Dibuat otomatis dari jenis event, mata pelajaran, dan tingkat kelas sasaran.'
                    }
                >
                    <p className="rounded-wf border border-line bg-surface-2/60 px-2.5 py-1.5 text-sm text-ink">
                        {mengubah
                            ? (ujianAwal.judul || '—')
                            : ([
                                  (jenisEvent ?? '').trim(),
                                  namaMapelTerpilih.trim(),
                              ]
                                  .filter(Boolean)
                                  .join(' - ') || 'Ujian') +
                              (tingkatTerpilih ? ` #${tingkatTerpilih}` : '')}
                    </p>
                </Field>

                <Field label="Durasi (menit)">
                    <input
                        type="number"
                        min={1}
                        max={600}
                        value={isi.duration_minutes}
                        onChange={(e) =>
                            setIsi({ ...isi, duration_minutes: Number(e.target.value) })
                        }
                        className={kelasInput}
                    />
                </Field>

                <Field label="Maksimal percobaan">
                    <input
                        type="number"
                        min={1}
                        max={10}
                        value={isi.max_attempts}
                        onChange={(e) => setIsi({ ...isi, max_attempts: Number(e.target.value) })}
                        className={kelasInput}
                    />
                </Field>

                <Field label="Instruksi" petunjuk="Tampil di layar siswa sebelum mulai">
                    <input
                        value={isi.instructions}
                        onChange={(e) => setIsi({ ...isi, instructions: e.target.value })}
                        className={kelasInput}
                        placeholder="Baca soal dengan teliti"
                    />
                </Field>
            </div>

            <div className="mt-4">
                <p className="mb-1.5 text-xs font-medium text-ink-2">Kelas sasaran</p>
                <p className="mb-2 text-[11px] text-ink-3">
                    Kosong berarti <strong>seluruh peserta event</strong>. Itu pilihan yang
                    disengaja: mencentang setiap kelas satu per satu berarti lupa satu kelas membuat
                    kelas itu tidak mendapat ujian tanpa satu pun peringatan.
                </p>

                <div className="flex flex-wrap gap-3">
                    {kelas.map((k) => (
                        <label key={k.id} className="flex items-center gap-1.5 text-sm">
                            <input
                                type="checkbox"
                                checked={pilihKelas.includes(k.id)}
                                onChange={(e) =>
                                    setPilihKelas(
                                        e.target.checked
                                            ? [...pilihKelas, k.id]
                                            : pilihKelas.filter((x) => x !== k.id),
                                    )
                                }
                            />
                            <span className="text-ink">{k.nama}</span>
                        </label>
                    ))}

                    {kelas.length === 0 && (
                        <p className="text-xs text-ink-3">Belum ada kelas di event ini.</p>
                    )}
                </div>
            </div>

            <div className="mt-4 flex flex-wrap gap-4 border-t border-line pt-3">
                <Sakelar
                    label="Acak urutan soal"
                    nilai={isi.shuffle_questions}
                    pada={(v) => setIsi({ ...isi, shuffle_questions: v })}
                />
                <Sakelar
                    label="Acak pilihan jawaban"
                    nilai={isi.shuffle_options}
                    pada={(v) => setIsi({ ...isi, shuffle_options: v })}
                />
                <Sakelar
                    label="Boleh kembali ke soal sebelumnya"
                    nilai={isi.allow_back_navigation}
                    pada={(v) => setIsi({ ...isi, allow_back_navigation: v })}
                />
                <Sakelar
                    label="Izinkan masuk terlambat"
                    nilai={isi.allow_late_entry}
                    pada={(v) => setIsi({ ...isi, allow_late_entry: v })}
                />
                {/* Sakelar "Terbitkan hasil setelah ujian" DIBUANG di Fase 8,
                    bukan dipindah. Publikasi hasil kini satu aksi untuk SELURUH
                    event (plate 21, /admin/events/{event}/hasil), sehingga
                    sakelar per ujian di sini akan menjadi tempat kedua yang
                    mengatur hal yang sama - dan dua tempat berarti suatu hari
                    keduanya berbeda pendapat. */}
            </div>
        </Kotak>
    );
}

function Sakelar({
    label,
    nilai,
    pada,
}: {
    label: string;
    nilai: boolean;
    pada: (v: boolean) => void;
}) {
    return (
        <label className="flex items-center gap-2 text-xs text-ink-2">
            <input type="checkbox" checked={nilai} onChange={(e) => pada(e.target.checked)} />
            {label}
        </label>
    );
}

/* ---------------------------------------------------------------- Panel sesi */

function FormSesi({
    jalur,
    ujian,
    ruang,
    sesiAwal,
    padaTutup,
    padaGalat,
}: {
    jalur: string;
    ujian: Ujian[];
    ruang: Props['ruang'];
    /**
     * Baris yang diubah. Kosong berarti mode TAMBAH. Nilai awalnya dimuat dari
     * sini supaya panel ubah terbuka dengan jendela dan ruang yang sekarang,
     * bukan kosong - form kosong pada sesi yang sudah berjalan terbaca seperti
     * "jadwalnya hilang".
     */
    sesiAwal?: Baris;
    padaTutup: () => void;
    padaGalat?: (pesan: string | null) => void;
}) {
    const mengubah = sesiAwal !== undefined;

    const [ujianId, setUjianId] = useState(sesiAwal?.ujian_id ?? ujian[0]?.id ?? 0);
    const [buka, setBuka] = useState(sesiAwal?.buka_iso ?? '');
    const [tutup, setTutup] = useState(sesiAwal?.tutup_iso ?? '');
    const [akhir, setAkhir] = useState(sesiAwal?.akhir_iso ?? '');
    const [pilihRuang, setPilihRuang] = useState<number[]>(sesiAwal?.ruang_id ?? []);
    const [galat, setGalat] = useState<string | null>(null);

    const umumkanGalat = (pesan: string) => {
        setGalat(pesan);
        padaGalat?.(pesan);
    };

    const simpan = () => {
        setGalat(null);
        padaGalat?.(null);

        // Hanya tiga waktu dan ruang - sama dengan field di form Tambah sesi.
        // `duration_minutes` sengaja TIDAK dikirim: `SesiService::perbarui`
        // memakai `isset()`, sehingga nilai `null` akan DIPERTAHANKAN diam-diam
        // alih-alih dikosongkan. Mengirim hal yang tidak bisa dikosongkan lewat
        // form ini hanya menciptakan tombol yang tampak bekerja tetapi tidak.
        const isi = {
            access_opens_at: buka,
            access_closes_at: tutup,
            hard_end_at: akhir,
            event_room_id: pilihRuang,
        };

        const opsi = {
            preserveScroll: true,
            onSuccess: () => padaTutup(),
            onError: (e: Record<string, string>) =>
                umumkanGalat(Object.values(e)[0] ?? 'Sesi tidak bisa disimpan.'),
        };

        if (mengubah) {
            // Hanya jendela & ruang yang diubah lewat sini. Ujian sesi tetap:
            // memindahkan sesi ke ujian lain adalah membuat sesi baru, bukan
            // menyunting yang ini, dan `pastikanSesiMilikUjian` menolaknya.
            router.put(`${jalur}/ujian/${ujianId}/sesi/${sesiAwal.id}`, isi, opsi);

            return;
        }

        router.post(`${jalur}/ujian/${ujianId}/sesi`, isi, opsi);
    };

    return (
        <Kotak
            judul={mengubah ? `Ubah ${sesiAwal.label}` : 'Tambah sesi'}
            aksi={
                <>
                    <Tombol nada="sek" onClick={padaTutup}>
                        Batal
                    </Tombol>
                    <Tombol
                        nada="pri"
                        disabled={!buka || !tutup || !akhir}
                        onClick={simpan}
                    >
                        {mengubah ? 'Simpan perubahan' : 'Simpan sesi'}
                    </Tombol>
                </>
            }
        >
            {galat && (
                <div className="mb-3">
                    <Peringatan>{galat}</Peringatan>
                </div>
            )}

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Field label="Ujian">
                    <select
                        value={ujianId}
                        onChange={(e) => setUjianId(Number(e.target.value))}
                        disabled={mengubah}
                        className={kelasInput}
                        title={
                            mengubah
                                ? 'Ujian sesi tidak bisa dipindah; buat sesi baru bila perlu.'
                                : undefined
                        }
                    >
                        {ujian.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.kode} · {u.mapel ?? u.judul}
                            </option>
                        ))}
                    </select>
                </Field>

                {/* Tiga waktu, dan masing-masing menjawab pertanyaan berbeda.
                    Menyatukannya menghilangkan tepat perbedaan yang membuat
                    ujian bisa dijalankan - lihat ExamSession. */}
                <Field label="Akses dibuka" petunjuk="Kapan siswa boleh masuk">
                    <input
                        type="datetime-local"
                        value={buka}
                        onChange={(e) => setBuka(e.target.value)}
                        className={kelasInput}
                    />
                </Field>

                <Field label="Batas mulai" petunjuk="Orang terakhir boleh MULAI">
                    <input
                        type="datetime-local"
                        value={tutup}
                        onChange={(e) => setTutup(e.target.value)}
                        className={kelasInput}
                    />
                </Field>

                <Field label="Batas akhir mutlak" petunjuk="Ujian berhenti, tanpa kecuali">
                    <input
                        type="datetime-local"
                        value={akhir}
                        onChange={(e) => setAkhir(e.target.value)}
                        className={kelasInput}
                    />
                </Field>
            </div>

            <div className="mt-4">
                <p className="mb-2 text-xs font-medium text-ink-2">Ruang</p>
                <div className="flex flex-wrap gap-3">
                    {ruang.map((r) => (
                        <label key={r.id} className="flex items-center gap-1.5 text-sm">
                            <input
                                type="checkbox"
                                checked={pilihRuang.includes(r.id)}
                                onChange={(e) =>
                                    setPilihRuang(
                                        e.target.checked
                                            ? [...pilihRuang, r.id]
                                            : pilihRuang.filter((x) => x !== r.id),
                                    )
                                }
                            />
                            <span className="font-mono text-xs text-ink-2">{r.kode}</span>
                            <span className="text-ink">{r.nama}</span>
                            <span className="font-mono text-[11px] text-ink-3">
                                {r.kapasitas}
                            </span>
                            {r.status !== 'aktif' && <Chip nada="warn">{r.status}</Chip>}
                        </label>
                    ))}

                    {ruang.length === 0 && (
                        <p className="text-xs text-ink-3">
                            Belum ada ruang dialokasikan ke event ini.
                        </p>
                    )}
                </div>
            </div>
        </Kotak>
    );
}

function KPI({
    label,
    nilai,
    nada,
}: {
    label: string;
    nilai: number;
    nada?: 'ok' | 'warn' | 'bad';
}) {
    const warna =
        nada === 'ok'
            ? 'text-ok'
            : nada === 'warn'
              ? 'text-warn'
              : nada === 'bad'
                ? 'text-bad'
                : 'text-ink';

    return (
        <div className="flex items-baseline justify-between gap-3 rounded-wf border border-line bg-surface px-3 py-2">
            <p className="text-xs text-ink-3">{label}</p>
            <p className={'font-mono text-lg leading-none font-medium ' + warna}>
                {nilai.toLocaleString('id-ID')}
            </p>
        </div>
    );
}
