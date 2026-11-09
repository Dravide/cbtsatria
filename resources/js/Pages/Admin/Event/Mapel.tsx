import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Inbox, Kotak, Tabel, Tombol, kelasInput } from '@/Components/ui';

/**
 * Laman 15 - Mata pelajaran & paket soal. Wireframe plate 15.
 *
 * ---------------------------------------------------------------------
 * Kolom "Paket soal" kini bisa diisi, dan pintunya satu
 * ---------------------------------------------------------------------
 * Fase 3 membangun kolom ini berisi "belum" dengan catatan yang menyebut
 * Fase 4 sebagai penggantinya. Fase 4 tiba, dan yang berubah adalah SUMBER
 * jawabannya - bukan tempatnya.
 *
 * Yang menjawabnya sekarang: `event_subjects.question_package_id`. Setiap
 * perubahan lewat `PUTC /admin/events/{id}/mapel/paket`, dan satu tulisan di
 * sana menghasilkan DUA baris: kolom paket aktif, dan baris riwayat yang
 * menyebut siapa memasangnya dan kapan. Itulah yang diminta blueprint
 * `:312` dengan kalimatnya sendiri.
 *
 * Tidak ada jalur lain untuk mengubah paket terpasang. Tidak ada kueri
 * langsung, tidak ada penyuntingan massal - karena pemasangan yang tidak
 * tercatat membuat riwayatnya berbohong, dan riwayat yang berbohong lebih
 * buruk daripada tidak ada riwayat.
 *
 * ---------------------------------------------------------------------
 * Tiga keadaan, bukan dua
 * ---------------------------------------------------------------------
 *   belum     - belum ada paket. Gerbang kesiapan menolak.
 *   kosong    - paket terpasang tetapi belum berisi butir. Gerbang menolak,
 *               dan pesannya menyebut paket mana yang harus diisi.
 *   terpasang - paket terpasang dan berisi.
 *
 * "kosong" ada di antara keduanya karena ia menuntut tindakan yang berbeda:
 * Admin yang melihat "belum" tahu ia harus memilih; Admin yang melihat
 * "kosong" sudah memilih, dan yang harus ia hubungi adalah Gurunya.
 *
 * ---------------------------------------------------------------------
 * Paket Draft tidak bisa dipasang, dan itu dijaga server
 * ---------------------------------------------------------------------
 * Daftar paket di panel menampilkan statusnya, dan yang Draft tetap terlihat
 * - Admin perlu tahu paketnya SUDAH ada tetapi belum selesai, supaya ia tahu
 * siapa yang harus dihubungi. Server menolaknya dengan pesan yang menyebut
 * nama paketnya.
 */
type Baris = {
    id: number;
    kode: string | null;
    nama: string;
    kategori: string | null;
    tingkat: string | null;
    baris_id: number | null;
    paket_id: number | null;
    paket: string | null;
    paket_status: 'belum' | 'kosong' | 'terpasang';
    butir: number | null;
};

type PaketTersedia = {
    id: number;
    nama: string;
    status: string;
    butir: number;
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    baris: Baris[];
    tersedia: { id: number; kode: string | null; nama: string }[];
};

export default function EventMapel({ event, baris, tersedia }: Props) {
    const [pilih, setPilih] = useState<number[]>(baris.map((b) => b.id));
    const [cari, setCari] = useState('');
    // Mapel yang panel paketnya sedang terbuka. Satu saja pada satu waktu:
    // membuka empat panel sekaligus berarti empat daftar paket yang harus
    // diambil, dan yang dibutuhkan Admin hanyalah satu baris.
    const [bukaPaket, setBukaPaket] = useState<number | null>(null);

    const kirim = () =>
        router.put(
            `/admin/events/${event.id}/mapel`,
            { id: pilih },
            { preserveScroll: true },
        );

    const tercari = tersedia.filter(
        (s) =>
            (s.nama + ' ' + (s.kode ?? '')).toLowerCase().includes(cari.toLowerCase()),
    );

    return (
        <AdminLayout
            judul="Mata pelajaran & paket soal"
            breadcrumb={`/admin/events · ${event.kode} · Mata pelajaran`}
            aksi={
                <Tombol nada="pri" onClick={kirim}>
                    Simpan mapel
                </Tombol>
            }
        >
            <Head title={`Mata pelajaran · ${event.nama}`} />

            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                <KPI label="Mapel ditetapkan" nilai={baris.length} />
                <KPI label="Paket terpasang" nilai={baris.filter((b) => b.paket_status === 'terpasang').length} />
                <KPI label="Total butir soal" nilai={baris.reduce((n, b) => n + (b.butir ?? 0), 0)} />
            </div>

            <Tabel<Baris>
                hal={{ data: baris, total: baris.length }}
                kunci={(b) => b.id}
                kosong="Belum ada mata pelajaran. Pilih dari daftar di bawah, lalu simpan."
                kolom={[
                    {
                        judul: 'Kode',
                        kelas: 'w-24',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">{b.kode ?? '—'}</span>
                        ),
                    },
                    {
                        judul: 'Mata pelajaran',
                        render: (b) => (
                            <span>
                                <span className="text-ink">{b.nama}</span>
                                {(b.kategori || b.tingkat) && (
                                    <span className="mt-0.5 block text-[11px] text-ink-3">
                                        {[b.kategori, b.tingkat].filter(Boolean).join(' · ')}
                                    </span>
                                )}
                            </span>
                        ),
                    },
                    {
                        judul: 'Paket soal',
                        kelas: 'w-64',
                        render: (b) => (
                            <div className="flex items-center gap-2">
                                {b.paket_status === 'belum' && <Chip nada="mute">belum</Chip>}
                                {b.paket_status === 'kosong' && (
                                    <Chip nada="warn" >{b.paket} · kosong</Chip>
                                )}
                                {b.paket_status === 'terpasang' && (
                                    <Chip nada="ok">{b.paket}</Chip>
                                )}
                                {b.baris_id !== null && (
                                    <button
                                        onClick={() =>
                                            setBukaPaket(bukaPaket === b.id ? null : b.id)
                                        }
                                        className="text-[11px] text-accent hover:underline"
                                    >
                                        {b.paket_id === null ? 'Pasang' : 'Ganti'}
                                    </button>
                                )}
                            </div>
                        ),
                    },
                    {
                        judul: 'Butir',
                        kelas: 'w-20',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-3">
                                {b.butir === null ? '—' : b.butir}
                            </span>
                        ),
                    },
                ]}
            />

            {bukaPaket !== null && (
                <PanelPaket
                    event={event}
                    baris={baris.find((b) => b.id === bukaPaket)!}
                    padaTutup={() => setBukaPaket(null)}
                />
            )}

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <Kotak
                    judul="Tambah / lepas mapel"
                    aksi={
                        <Tombol nada="sek" onClick={kirim}>
                            Simpan
                        </Tombol>
                    }
                >
                    <input
                        value={cari}
                        onChange={(e) => setCari(e.target.value)}
                        placeholder="Cari mata pelajaran..."
                        className={kelasInput + ' mb-3'}
                    />

                    <div className="max-h-64 space-y-1 overflow-y-auto">
                        {[...baris.map((b) => ({ id: b.id, kode: b.kode, nama: b.nama })), ...tercari]
                            .sort((a, b) => a.nama.localeCompare(b.nama))
                            .map((s) => (
                                <label
                                    key={s.id}
                                    className="flex items-center gap-2 rounded-wf px-1.5 py-1 text-sm hover:bg-surface-2/60"
                                >
                                    <input
                                        type="checkbox"
                                        checked={pilih.includes(s.id)}
                                        onChange={(e) =>
                                            setPilih(
                                                e.target.checked
                                                    ? [...pilih, s.id]
                                                    : pilih.filter((x) => x !== s.id),
                                            )
                                        }
                                    />
                                    <span className="font-mono text-xs text-ink-2">{s.kode}</span>
                                    <span className="text-ink">{s.nama}</span>
                                </label>
                            ))}

                        {baris.length === 0 && tercari.length === 0 && (
                            <p className="text-xs text-ink-3">
                                Tidak ada mata pelajaran aktif selain yang sudah ditetapkan.
                            </p>
                        )}
                    </div>
                </Kotak>

                <div className="space-y-3">
                    <Inbox>
                        Mata pelajaran yang <strong>dinonaktifkan</strong> tidak bisa ditambahkan
                        ke event baru, tetapi tetap muncul di event lama yang sudah memakainya.
                        Menghapusnya dari event lama akan mengubah susunan ujian yang sudah
                        berjalan, dan itu bukan yang dimaksud menonaktifkan mapel.
                    </Inbox>

                    <Kotak judul="Paket soal">
                        <p className="text-xs leading-relaxed text-ink-2">
                            Pemasangan paket soal dilakukan <strong>di sini</strong>, bukan di
                            laman Guru. Mengubah paket setelah jadwal tersimpan harus lewat satu
                            pintu, dan pintu itu mencatat siapa yang mengubahnya serta kapan.
                        </p>
                        <p className="mt-2 text-xs leading-relaxed text-ink-3">
                            Paket yang masih berstatus <strong>Draft</strong> belum bisa dipasang.
                            Status Siap diberikan Gurunya setelah paket itu selesai disusun —
                            memasang paket setengah jadi berarti yang menemukan soalnya kurang
                            adalah pengawas di ruang ujian.
                        </p>
                    </Kotak>

                    <Inbox>
                        Melepas mapel dari event ikut menghapus riwayat pemasangan paketnya.
                        Itu memang yang diinginkan: riwayat pemasangan yang tidak lagi punya
                        mapel tidak menjawab pertanyaan apa pun.
                    </Inbox>
                </div>
            </div>
        </AdminLayout>
    );
}

/* ------------------------------------------------------- Panel pasang paket */

/**
 * Panel pemilihan paket untuk satu mapel.
 *
 * Daftar paketnya diambil saat panelnya dibuka, bukan dikirim bersama laman:
 * empat puluh mapel berarti empat puluh daftar paket, dan satu di antaranya
 * yang dibutuhkan Admin.
 *
 * Disaring per MAPEL di server. Paket Matematika tidak muncul di baris Fisika,
 * karena paket yang salah mapelnya akan ditolak service - dan pemilih yang
 * menawarkan sesuatu yang akan ditolak adalah pemilih yang mengajari Admin
 * untuk tidak mempercayai layarnya.
 */
function PanelPaket({
    event,
    baris,
    padaTutup,
}: {
    event: { id: number; nama: string };
    baris: Baris;
    padaTutup: () => void;
}) {
    const [paket, setPaket] = useState<PaketTersedia[] | null>(null);
    const [riwayat, setRiwayat] = useState<
        { id: number; paket: string | null; pemasang: string | null; pada: string | null }[]
    >([]);
    const [galat, setGalat] = useState<string | null>(null);
    const [kirim, setKirim] = useState(false);

    // Daftar paket diambil SEKALI saat panelnya terbuka. `baris.baris_id`
    // ada di daftar ketergantungan karena panel yang sama dipakai ulang
    // untuk mapel lain - tanpa itu, membuka panel mapel kedua akan
    // menampilkan paket milik mapel pertama.
    useEffect(() => {
        let batal = false;

        fetch(`/admin/events/${event.id}/mapel/${baris.baris_id}/paket`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((d) => {
                if (batal) {
                    return;
                }
                setPaket(d.tersedia as PaketTersedia[]);
                setRiwayat(d.riwayat);
            })
            .catch(() => {
                if (!batal) {
                    setGalat('Daftar paket gagal dimuat. Muat ulang laman ini.');
                }
            });

        return () => {
            batal = true;
        };
    }, [event.id, baris.baris_id]);

    const pasang = (id: number | null) => {
        setGalat(null);
        setKirim(true);

        router.put(
            `/admin/events/${event.id}/mapel/paket`,
            { baris_id: baris.baris_id, question_package_id: id },
            {
                preserveScroll: true,
                onError: (e) =>
                    setGalat((e as Record<string, string>).question_package_id ?? 'Gagal disimpan.'),
                onFinish: () => setKirim(false),
                onSuccess: () => padaTutup(),
            },
        );
    };

    return (
        <div className="mt-3 rounded-wf border border-line bg-surface">
            <header className="flex items-center gap-2 border-b border-line px-4 py-2.5">
                <h2 className="font-display text-sm font-semibold text-ink">
                    Paket soal · {baris.nama}
                </h2>
                <button
                    onClick={padaTutup}
                    className="ml-auto text-ink-3 hover:text-ink"
                    aria-label="Tutup"
                >
                    &#10005;
                </button>
            </header>

            <div className="space-y-3 p-4">
                {galat && (
                    <p className="rounded-wf border border-bad/30 bg-bad-bg px-3 py-2 text-xs text-bad">
                        {galat}
                    </p>
                )}

                {paket === null && (
                    <p className="text-xs text-ink-3">Memuat daftar paket…</p>
                )}

                {paket !== null && paket.length === 0 && (
                    <p className="text-xs text-ink-3">
                        Belum ada paket soal untuk mata pelajaran ini. Paket disusun Guru di
                        konsolnya, di laman Paket soal.
                    </p>
                )}

                {paket !== null && paket.length > 0 && (
                    <div className="max-h-56 space-y-1 overflow-y-auto">
                        {paket.map((p) => (
                            <label
                                key={p.id}
                                className="flex cursor-pointer items-center gap-2 rounded-wf px-1.5 py-1.5 text-sm hover:bg-surface-2"
                            >
                                <input
                                    type="radio"
                                    name={`paket-${baris.baris_id}`}
                                    checked={baris.paket_id === p.id}
                                    onChange={() => pasang(p.id)}
                                    disabled={kirim}
                                />
                                <span className="min-w-0 flex-1 truncate text-ink">{p.nama}</span>
                                <Chip nada={p.status === 'siap' ? 'ok' : 'mute'}>
                                    {p.status}
                                </Chip>
                                <span className="font-mono text-[11px] text-ink-3">
                                    {p.butir} butir
                                </span>
                            </label>
                        ))}
                    </div>
                )}

                {baris.paket_id !== null && (
                    <Tombol
                        nada="bahaya"
                        onClick={() => pasang(null)}
                        disabled={kirim}
                    >
                        Lepas paket
                    </Tombol>
                )}

                {riwayat.length > 0 && (
                    <details className="border-t border-line pt-3">
                        <summary className="cursor-pointer text-[11px] text-ink-3 hover:text-ink">
                            Riwayat pemasangan ({riwayat.length})
                        </summary>
                        <ul className="mt-2 space-y-1">
                            {riwayat.map((r) => (
                                <li key={r.id} className="text-[11px] text-ink-3">
                                    <span className="text-ink-2">{r.paket}</span>
                                    {' · dipasang '}
                                    {r.pemasang ?? 'Admin'}
                                    {r.pada && ` · ${new Date(r.pada).toLocaleString('id-ID')}`}
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
            </div>
        </div>
    );
}

function KPI({ label, nilai }: { label: string; nilai: number }) {
    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className="mt-1 font-mono text-2xl font-medium text-ink">{nilai}</p>
        </div>
    );
}
