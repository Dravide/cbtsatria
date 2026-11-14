import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Inbox, Kotak, Peringatan, kelasInput } from '@/Components/ui';

/**
 * Laman soal per mata pelajaran - wireframe `wireframe-soal-event.html`, N1.
 *
 * =====================================================================
 * Mengapa laman ini ada, padahal paket soal sudah ditampilkan
 * =====================================================================
 * Tab "Mata pelajaran & paket" menjawab dua angka: paket mana terpasang, dan
 * berapa butirnya. Ia tidak pernah menjawab pertanyaan yang sebenarnya dibawa
 * Admin ke sana - soal APA yang akan dikerjakan 240 anak - sehingga Admin
 * menandai event Siap, membuka sesi, dan mencetak tablo tanpa sekali pun
 * melihat isi paket yang ia setujui.
 *
 * =====================================================================
 * Urutan barisnya urutan PAKET, bukan abjad
 * =====================================================================
 * Nomor di kolom pertama adalah posisi butir di paket - angka yang sama yang
 * tercetak di naskah dan dibacakan pengawas. Daftar yang diurutkan menurut
 * kode akan menampilkan 1, 2, 11, 16, 19, 17 dan terbaca sebagai daftar yang
 * salah urut, padahal yang salah adalah urutannya.
 *
 * =====================================================================
 * Kelompok per mapel, bukan satu tabel berkolom Mapel
 * =====================================================================
 * Pertanyaan Admin adalah "mapel ini beres belum". Tabel berkolom mapel
 * memaksanya memindai ulang seluruh baris untuk menjawab satu mapel; kelompok
 * membuat satu mapel menjadi satu blok yang bisa dinilai selesai atau belum
 * dalam sekali lihat.
 *
 * =====================================================================
 * Baca saja
 * =====================================================================
 * Soal disunting Guru pemiliknya lewat bank soal. Laman ini TIDAK punya satu
 * pun tombol yang mengubah soal - yang tersedia hanya jalan ke sana. Tombol
 * yang server tolak (403) mengajari Admin untuk tidak memercayai layarnya.
 */

type Baris = {
    nomor: number;
    kode: string | null;
    judul: string | null;
    jenis: string;
    jenis_label: string;
    jenis_pendek: string;
    tingkat: string | null;
    poin: number;
    perlu_koreksi: boolean;
    status: string | null;
    punya_kunci: boolean;
    jumlah_rumpang: number | null;
    punya_media: boolean;
    punya_rubrik: boolean;
    punya_pembahasan: boolean;
    diarsipkan: boolean;
    basi: boolean;
};

type Kelompok = {
    mapel: { id: number; kode: string | null; nama: string | null };
    paket: { nama: string; status: string } | null;
    keadaan: 'terpasang' | 'belum_dibekukan' | 'kosong' | 'belum';
    butir: number;
    poin: number;
    uraian: number;
    diarsipkan: number;
    basi: number;
    jenis: { jenis: string; pendek: string; jumlah: number }[];
    baris: Baris[];
};

type Saringan = { cari: string; jenis: string; tingkat: string };

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    kelompok: Kelompok[];
    angka: {
        mapel: number;
        butir: number;
        poin: number;
        uraian: number;
        diarsipkan: number;
        basi: number;
    };
    filter: Saringan;
    pilihan: { jenis: Record<string, string>; tingkat: string[] };
};

/**
 * Warna batang sebaran jenis.
 *
 * Dipetakan di satu tempat karena batangnya muncul di setiap kepala kelompok,
 * dan dua peta yang berbeda akan membuat "Uraian" berwarna rust di satu mapel
 * dan kelabu di mapel sebelahnya - padahal yang dibandingkan justru bentuk
 * paket antar mapel.
 */
const WARNA_JENIS: Record<string, string> = {
    pilihan_ganda: 'bg-accent',
    jawaban_ganda: 'bg-accent/60',
    benar_salah: 'bg-accent/40',
    isian_singkat: 'bg-info/55',
    isian_rumpang: 'bg-info/40',
    menjodohkan: 'bg-info/30',
    mengurutkan: 'bg-info/30',
    angka: 'bg-ink-3',
    uraian: 'bg-warn',
    media: 'bg-line-2',
};

export default function EventSoal({ event, kelompok, angka, filter, pilihan }: Props) {
    const [terbuka, setTerbuka] = useState<number | null>(kelompok[0]?.mapel.id ?? null);

    // `Partial<Saringan>`, bukan `Partial<Props['filter']>`: di berkas .tsx
    // tanda `<` sesudah sebuah nama dibaca parser sebagai awal elemen JSX,
    // sehingga tipe di posisi argumen harus berupa nama tunggal.
    const saring = (ubah: Partial<Saringan>) => {
        const baru = { ...filter, ...ubah };

        router.get(
            `/admin/events/${event.id}/mapel/soal`,
            // Nilai kosong TIDAK dikirim. `?cari=&jenis=` yang tertinggal di
            // alamat membuat tautan yang ditempel ke pesan terbaca seolah
            // sedang menyaring sesuatu.
            Object.fromEntries(Object.entries(baru).filter(([, v]) => v !== '')),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const menyaring = filter.cari !== '' || filter.jenis !== '' || filter.tingkat !== '';

    return (
        <AdminLayout
            judul="Soal per mata pelajaran"
            breadcrumb={`/admin/events · ${event.kode} · Soal`}
            aksi={
                <Link href="/guru/bank-soal">
                    <span className="inline-block rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink-2 hover:bg-surface-2">
                        Buka bank soal
                    </span>
                </Link>
            }
        >
            <Head title={`Soal · ${event.nama}`} />

            {/* Tabbar - laman ini tab KEDUA, bukan menu sidebar baru. Yang
                dibutuhkan Admin adalah berpindah antara "paket mana
                terpasang" dan "soal apa isinya" tanpa kehilangan tempat. */}
            <nav className="mb-3 flex gap-1 border-b border-line text-xs">
                <Link
                    href={`/admin/events/${event.id}/mapel`}
                    className="px-3 py-1.5 text-ink-2 hover:text-ink"
                >
                    Mata pelajaran &amp; paket
                </Link>
                <span className="-mb-px border-b-2 border-accent px-3 py-1.5 font-medium text-accent">
                    Soal
                </span>
            </nav>

            {/* Angka di atas TIDAK ikut menyusut saat penyaring dipakai - ia
                menjawab "seberapa besar event ini", dan angka yang berubah
                saat Admin mencari satu kata berhenti menjawab pertanyaan itu. */}
            <div className="mb-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <KPI label="Mapel terpasang" nilai={String(angka.mapel)} />
                <KPI label="Butir seluruh mapel" nilai={String(angka.butir)} />
                <KPI label="Poin total" nilai={formatPoin(angka.poin)} />
                <KPI label="Uraian" nilai={String(angka.uraian)} />
                <KPI
                    label="Perlu diperiksa"
                    nilai={String(angka.diarsipkan + angka.basi)}
                    nada={angka.diarsipkan + angka.basi > 0 ? 'warn' : undefined}
                />
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input
                    value={filter.cari}
                    onChange={(e) => saring({ cari: e.target.value })}
                    placeholder="Cari judul atau kode soal..."
                    className={kelasInput + ' max-w-xs'}
                />

                <select
                    value={filter.jenis}
                    onChange={(e) => saring({ jenis: e.target.value })}
                    className={kelasInput + ' w-auto'}
                >
                    <option value="">Semua jenis</option>
                    {Object.entries(pilihan.jenis).map(([nilai, pendek]) => (
                        <option key={nilai} value={nilai}>
                            {pendek}
                        </option>
                    ))}
                </select>

                <select
                    value={filter.tingkat}
                    onChange={(e) => saring({ tingkat: e.target.value })}
                    className={kelasInput + ' w-auto'}
                >
                    <option value="">Semua tingkat</option>
                    {pilihan.tingkat.map((t) => (
                        <option key={t} value={t}>
                            {t}
                        </option>
                    ))}
                </select>

                {menyaring && (
                    <button
                        type="button"
                        onClick={() => saring({ cari: '', jenis: '', tingkat: '' })}
                        className="text-xs text-accent hover:underline"
                    >
                        Bersihkan
                    </button>
                )}
            </div>

            {kelompok.length === 0 ? (
                <Kotak>
                    <p className="text-sm leading-relaxed text-ink-2">
                        {menyaring
                            ? 'Tidak ada butir yang cocok dengan penyaring ini.'
                            : 'Belum ada mata pelajaran yang ditetapkan. Tetapkan mapelnya lebih dulu, lalu pasang paket soalnya.'}
                    </p>
                </Kotak>
            ) : (
                <div className="space-y-3">
                    {kelompok.map((k) => (
                        <KelompokMapel
                            key={k.mapel.id}
                            kelompok={k}
                            idEvent={event.id}
                            terbuka={terbuka === k.mapel.id}
                            padaBuka={() =>
                                setTerbuka(terbuka === k.mapel.id ? null : k.mapel.id)
                            }
                        />
                    ))}
                </div>
            )}

            <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                Soal disunting Guru pemiliknya lewat bank soal; laman ini hanya membaca. Kolom
                &ldquo;Ket.&rdquo; menandai hal yang membuat paket tidak layak diujikan apa adanya:
                soal yang sudah diarsipkan tetapi masih dipakai, dan butir yang bank soalnya
                berubah sesudah dibekukan.
            </p>
        </AdminLayout>
    );
}

/**
 * Satu kelompok mapel.
 *
 * Kepala kelompok menampilkan SEBARAN JENIS sebagai batang, bukan angka
 * berderet. Yang dibaca Admin di kepala adalah bentuk paketnya - kebanyakan
 * pilihan ganda? ada uraian? - dan enam angka berderet tidak membentuk apa pun
 * di mata.
 */
function KelompokMapel({
    kelompok,
    idEvent,
    terbuka,
    padaBuka,
}: {
    kelompok: Kelompok;
    idEvent: number;
    terbuka: boolean;
    padaBuka: () => void;
}) {
    const k = kelompok;

    return (
        <section className="overflow-hidden rounded-wf border border-line bg-surface">
            <button
                type="button"
                onClick={padaBuka}
                className="flex w-full flex-wrap items-center gap-2 border-b border-line px-3 py-2 text-left hover:bg-surface-2/50"
                aria-expanded={terbuka}
            >
                <span className="font-mono text-[11px] text-ink-3">{k.mapel.kode}</span>
                <b className="text-[13px] font-medium text-ink">{k.mapel.nama}</b>

                <StatusPaket keadaan={k.keadaan} paket={k.paket} />

                <span className="text-[11px] text-ink-3">
                    {k.butir} butir · {formatPoin(k.poin)} poin
                </span>

                {k.diarsipkan + k.basi > 0 && (
                    <Chip nada="warn">{k.diarsipkan + k.basi} perlu diperiksa</Chip>
                )}

                <span className="ml-auto flex items-center gap-2">
                    {/* Batang sebaran - bentuk paket, bukan angka. */}
                    <span
                        className="flex h-1 w-16 overflow-hidden rounded-full bg-surface-2"
                        title={k.jenis.map((j) => `${j.pendek} ${j.jumlah}`).join(' · ')}
                        aria-hidden="true"
                    >
                        {k.jenis.map((j) => (
                            <i
                                key={j.jenis}
                                className={WARNA_JENIS[j.jenis] ?? 'bg-line-2'}
                                style={{ flexGrow: j.jumlah, flexBasis: 0 }}
                            />
                        ))}
                    </span>
                    <span className="font-mono text-[11px] text-ink-3">{terbuka ? '−' : '+'}</span>
                </span>
            </button>

            {terbuka && (
                <>
                    {k.keadaan === 'belum' && (
                        <div className="px-3 py-4">
                            <p className="text-sm leading-relaxed text-ink-2">
                                Belum ada paket soal terpasang untuk mapel ini. Ujian tidak bisa
                                dijalankan, dan event tidak bisa ditandai Siap.
                            </p>
                            <Link
                                href={`/admin/events/${idEvent}/mapel`}
                                className="mt-2 inline-block text-xs font-medium text-accent hover:underline"
                            >
                                Pasang paket
                            </Link>
                        </div>
                    )}

                    {k.keadaan === 'kosong' && (
                        <div className="px-3 py-4">
                            <Peringatan>
                                Paket <b>{k.paket?.nama}</b> terpasang tetapi belum berisi satu
                                butir pun. Ujian tidak bisa dijalankan.
                            </Peringatan>
                            <p className="mt-2 text-xs text-ink-2">
                                Paketnya sudah dipilih &mdash; yang perlu dihubungi adalah Guru
                                penulisnya, bukan memasang ulang.
                            </p>
                        </div>
                    )}

                    {k.keadaan === 'belum_dibekukan' && (
                        <div className="border-b border-line px-3 py-2">
                            <Inbox>
                                Ujiannya belum dibuat, sehingga isi paket ini yang AKAN
                                dikerjakan &mdash; bukan salinan yang tersimpan. Angka bobot di
                                bawah sudah mengikuti timpaan paket.
                            </Inbox>
                        </div>
                    )}

                    {k.baris.length > 0 && (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="bg-surface-2/60 text-[10px] uppercase tracking-wide text-ink-3">
                                    <tr>
                                        <th className="w-10 px-2 py-1.5 font-medium">No</th>
                                        <th className="w-20 px-2 py-1.5 font-medium">Kode</th>
                                        <th className="px-2 py-1.5 font-medium">Judul soal</th>
                                        <th className="w-20 px-2 py-1.5 font-medium">Jenis</th>
                                        <th className="w-16 px-2 py-1.5 font-medium">Tingkat</th>
                                        <th className="w-12 px-2 py-1.5 text-right font-medium">
                                            Poin
                                        </th>
                                        <th className="w-24 px-2 py-1.5 font-medium">Ket.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {k.baris.map((b) => (
                                        <tr
                                            key={`${b.nomor}-${b.kode}`}
                                            className={
                                                'border-t border-line/60 ' +
                                                (b.perlu_koreksi ? 'bg-accent-soft/30' : '')
                                            }
                                        >
                                            <td className="px-2 py-1.5 font-mono text-ink-3">
                                                {b.nomor}
                                            </td>
                                            <td className="px-2 py-1.5 font-mono text-ink-2">
                                                {b.kode ?? '—'}
                                            </td>
                                            <td className="max-w-0 truncate px-2 py-1.5 text-ink">
                                                {b.judul ?? 'Tanpa judul'}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                <Chip nada={b.perlu_koreksi ? 'warn' : 'mute'}>
                                                    {b.jenis_pendek}
                                                </Chip>
                                            </td>
                                            <td className="px-2 py-1.5 text-ink-2">
                                                {b.tingkat ?? '—'}
                                            </td>
                                            <td className="px-2 py-1.5 text-right font-mono text-ink">
                                                {formatPoin(b.poin)}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                <Keterangan baris={b} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </>
            )}
        </section>
    );
}

/**
 * Status paket satu mapel - EMPAT keadaan, bukan satu chip "belum siap".
 *
 * `belum` menuntut Admin memilih paket; `kosong` berarti ia sudah memilih dan
 * yang harus dihubungi Gurunya. Menyatukan keduanya membuat Admin menekan
 * "Pasang" untuk paket yang sudah terpasang.
 */
function StatusPaket({
    keadaan,
    paket,
}: {
    keadaan: Kelompok['keadaan'];
    paket: Kelompok['paket'];
}) {
    if (keadaan === 'belum') {
        return <Chip nada="mute">belum</Chip>;
    }

    if (keadaan === 'kosong') {
        return <Chip nada="bad">kosong</Chip>;
    }

    if (keadaan === 'belum_dibekukan') {
        return <Chip nada="info">{paket?.nama ?? 'paket'} · belum dibekukan</Chip>;
    }

    return <Chip nada="ok">{paket?.nama ?? 'terpasang'}</Chip>;
}

/**
 * Keterangan pendek per baris - huruf bergaris, bukan chip.
 *
 * Satu baris bisa memuat tiga tanda sekaligus, dan tiga chip berwarna di satu
 * baris berhenti terbaca sebagai tiga keadaan. Huruf tidak menerangkan apa
 * pun sendirian; `title`-nya yang menerangkan, dan itu memang pola tabel
 * padat.
 */
function Keterangan({ baris: b }: { baris: Baris }) {
    const tanda: { isi: string; judul: string; nada: string }[] = [];

    if (b.punya_kunci) {
        tanda.push({ isi: 'K', judul: 'Punya kunci jawaban', nada: 'border-accent text-accent' });
    }
    if (b.jumlah_rumpang !== null) {
        tanda.push({
            isi: String(b.jumlah_rumpang),
            judul: `${b.jumlah_rumpang} rumpang`,
            nada: 'border-accent text-accent',
        });
    }
    if (b.punya_media) {
        tanda.push({ isi: 'M', judul: 'Memuat media', nada: 'border-line-2 text-ink-3' });
    }
    if (b.punya_rubrik) {
        tanda.push({ isi: 'R', judul: 'Punya rubrik', nada: 'border-accent text-accent' });
    }
    if (b.punya_pembahasan) {
        tanda.push({ isi: 'P', judul: 'Punya pembahasan', nada: 'border-line-2 text-ink-3' });
    }
    if (b.diarsipkan) {
        tanda.push({ isi: 'A', judul: 'Diarsipkan tetapi masih dipakai paket ini', nada: 'border-bad text-bad' });
    }
    if (b.basi) {
        tanda.push({
            isi: '!',
            judul: 'Bank soal berubah sesudah dibekukan; yang dikerjakan siswa tetap salinan lama',
            nada: 'border-warn text-warn',
        });
    }

    if (tanda.length === 0) {
        return <span className="text-ink-3/60">—</span>;
    }

    return (
        <span className="flex flex-wrap gap-0.5">
            {tanda.map((t) => (
                <span
                    key={t.isi + t.judul}
                    title={t.judul}
                    className={
                        'inline-grid size-3.5 place-items-center rounded-[2px] border bg-surface-2/60 font-mono text-[8px] leading-none ' +
                        t.nada
                    }
                >
                    {t.isi}
                </span>
            ))}
        </span>
    );
}

function KPI({ label, nilai, nada }: { label: string; nilai: string; nada?: 'warn' }) {
    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p
                className={
                    'mt-1 font-mono text-2xl font-medium ' +
                    (nada === 'warn' ? 'text-warn' : 'text-ink')
                }
            >
                {nilai}
            </p>
        </div>
    );
}

/** Angka berdesimal hanya bila ada desimalnya - "28" bukan "28,00". */
function formatPoin(n: number): string {
    return Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ',');
}
