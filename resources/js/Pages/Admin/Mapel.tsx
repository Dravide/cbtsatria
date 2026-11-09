import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    BilahFilter,
    Chip,
    Field,
    Inbox,
    Modal,
    Tombol,
    kelasInput,
    nadaStatus,
    pakaiFilter,
} from '@/Components/ui';

/**
 * Laman 08 - Mata pelajaran + penugasan mengajar. Wireframe plate 08.
 *
 * ---------------------------------------------------------------------
 * Penugasan mengajar ada di laman ini, bukan di laman Guru
 * ---------------------------------------------------------------------
 * Satu baris penugasan adalah satu IZIN: guru A boleh menyusun soal untuk
 * mapel B di kelas C. Tabel itu milik mapel - tanpa mapel dan kelas ia tidak
 * punya arti, sementara guru hanya salah satu kolomnya.
 *
 * Kalau dipindahkan ke laman Guru, Admin harus membuka dua layar untuk
 * menjawab satu pertanyaan yang sebenarnya satu: "siapa yang mengajar MTK di
 * X-1?" - dan jawabannya dibaca dari dua tempat yang bisa tidak sinkron.
 * Karena itu penugasannya ditampilkan langsung di baris mapelnya.
 *
 * Akibatnya di Fase 4: bank soal Guru dibatasi tabel ini, bukan daftar yang
 * disimpan di laman Guru. Sumber kebenarannya satu.
 */
type Penugasan = { id: number; guru: string | null; kelas: string | null };

type Baris = {
    id: number;
    kode: string;
    nama: string;
    kelompok: string | null;
    tingkat: string | null;
    status: string;
    penugasan: Penugasan[];
};

/** Satu kelompok tingkat beserta isinya - dihitung server, bukan di sini. */
type Kelompok = {
    kunci: string;
    tingkat: string | null;
    label: string;
    jumlah: number;
    /** Jumlah IZIN mengajar di tingkat ini, bukan jumlah mapel yang punya. */
    penugasan: number;
    baris: Baris[];
};

type Props = {
    /**
     * Daftar rata seluruh mapel. Laman ini tidak dipaginasi: barisnya
     * dikelompokkan tingkatnya, dan kepala kelompok yang berbunyi "12 mapel"
     * di atas tiga baris adalah angka yang salah.
     */
    baris: Baris[];
    kelompok: Kelompok[];
    filter: { cari: string; status: string };
    jumlah: number;
    guru: { id: number; nama: string }[];
    kelas: { id: number; nama: string }[];
    /**
     * Katalog tingkat KELAS, untuk penugasan massal - bukan `Baris.tingkat`,
     * yang merupakan tingkat MAPEL-nya sendiri. Dua hal berbeda yang
     * kebetulan bernama sama di bahasa Indonesia.
     */
    tingkat: { tingkat: string; jumlah: number; kelas: string[] }[];
    tahunAjaranAktif: string | null;
};

export default function Mapel({
    kelompok,
    filter,
    jumlah,
    guru,
    kelas,
    tingkat,
    tahunAjaranAktif,
}: Props) {
    const [tambah, setTambah] = useState(false);
    const [ubah, setUbah] = useState<Baris | null>(null);
    const [tugaskan, setTugaskan] = useState<Baris | null>(null);

    // Kelompok yang sedang terbuka, disimpan per kuncinya.
    //
    // Bawaannya KOSONG - seluruh kelompok tertutup - sehingga layar pertama
    // menampilkan tiga kepala kelompok alih-alih tiga ribu piksel mapel.
    //
    // KECUALI saat ada filter aktif: hasil pencarian yang tersembunyi di
    // balik lipatan terbaca sebagai "tidak ketemu", dan Admin yang baru
    // mengetik "MTK" lalu melihat tiga kepala kelompok akan menyimpulkan
    // mapelnya tidak ada.
    //
    // Disimpan di state, bukan di `localStorage`: pilihannya berlaku selama
    // Admin berada di laman ini, dan pilihan yang bertahan lintas sesi
    // berarti Admin yang membuka laman ini berbulan-bulan kemudian mendapati
    // satu tingkat terbuka tanpa ia tahu mengapa.
    const [terbuka, setTerbuka] = useState<Record<string, boolean>>({});

    const adaFilter = filter.cari !== '' || filter.status !== '';

    const terbukakah = (kunci: string) => terbuka[kunci] ?? adaFilter;

    const semuaTerbuka = kelompok.length > 0 && kelompok.every((k) => terbukakah(k.kunci));

    const alihSemua = () => {
        if (semuaTerbuka) {
            setTerbuka(Object.fromEntries(kelompok.map((k) => [k.kunci, false])));

            return;
        }

        setTerbuka(Object.fromEntries(kelompok.map((k) => [k.kunci, true])));
    };

    const f = pakaiFilter('/admin/mapel', { cari: filter.cari, status: filter.status });

    return (
        <AdminLayout
            judul="Mata pelajaran"
            breadcrumb="/admin/mapel"
            aksi={
                <>
                    {/* Dulu tombol mati bertuliskan "Impor Excel tersedia
                        setelah berkas template selesai". Yang membuatnya
                        mati bukan templatenya, melainkan tidak adanya
                        importer - dan tombol mati tanpa penjelasan yang
                        benar membuat Admin melaporkan fiturnya rusak. */}
                    <Tombol onClick={() => router.visit('/admin/impor/mapel')}>Impor</Tombol>
                    <Tombol nada="pri" onClick={() => setTambah(true)}>
                        Tambah mapel
                    </Tombol>
                </>
            }
        >
            <Head title="Mata pelajaran" />

            {!tahunAjaranAktif && (
                <div className="mb-3">
                    <Inbox>
                        Belum ada tahun ajaran aktif, jadi penugasan mengajar belum bisa
                        dibuat &mdash; daftar kelas masih kosong. Aktifkan tahun ajaran di
                        laman Tahun ajaran.
                    </Inbox>
                </div>
            )}

            <div className="mb-3">
                <BilahFilter padaKirim={f.terapkan}>
                    <input
                        value={f.nilai.cari ?? ''}
                        onChange={(e) => f.ubah('cari', e.target.value)}
                        placeholder="Cari kode atau nama mapel..."
                        className={kelasInput + ' max-w-xs'}
                    />
                    <select
                        value={f.nilai.status ?? ''}
                        onChange={(e) => f.ubah('status', e.target.value)}
                        className={kelasInput + ' max-w-[10rem]'}
                    >
                        <option value="">Semua status</option>
                        <option value="aktif">Aktif</option>
                        <option value="nonaktif">Nonaktif</option>
                    </select>
                    <Tombol type="submit" nada="pri">
                        Terapkan
                    </Tombol>
                    <Tombol onClick={f.reset}>Reset</Tombol>

                    {/* "Buka semua" ada karena pencarian sering diikuti
                        pertanyaan "yang cocok ini di tingkat mana saja" -
                        dan membuka tiga kelompok satu per satu untuk
                        menjawabnya adalah pekerjaan yang bisa dihindari. */}
                    {kelompok.length > 0 && (
                        <Tombol onClick={alihSemua}>
                            {semuaTerbuka ? 'Tutup semua' : 'Buka semua'}
                        </Tombol>
                    )}
                </BilahFilter>
            </div>

            {jumlah === 0 && (
                <div className="rounded-wf border border-line bg-surface px-3 py-8 text-center text-sm text-ink-3">
                    {filter.cari || filter.status
                        ? 'Tidak ada mata pelajaran yang cocok dengan filter itu.'
                        : 'Belum ada mata pelajaran.'}
                </div>
            )}

            <div className="space-y-3">
                {kelompok.map((k) => (
                    <KelompokMapel
                        key={k.kunci}
                        kelompok={k}
                        terbuka={terbukakah(k.kunci)}
                        padaAlih={() => setTerbuka((t) => ({ ...t, [k.kunci]: !terbukakah(k.kunci) }))}
                        padaUbah={setUbah}
                        padaTugaskan={setTugaskan}
                    />
                ))}
            </div>

            <p className="mt-3 text-[11px] text-ink-3">
                {jumlah.toLocaleString('id-ID')} mata pelajaran
                {jumlah > 0 && `, ${kelompok.length} kelompok tingkat.`}
            </p>

            <FormMapel terbuka={tambah} padaTutup={() => setTambah(false)} />
            {/* `key` bukan hiasan: `Modal` mengembalikan `null` dari dalam
                dirinya sendiri, jadi form di luarnya tidak pernah dilepas dan
                `useForm` menyimpan nilai awalnya sejak panel masih tertutup -
                saat `mapel` masih kosong. Tanpa `key`, "Ubah" membuka kolom
                kosong. */}
            <FormMapel
                key={'ubah-' + (ubah?.id ?? 'kosong')}
                terbuka={ubah !== null}
                mapel={ubah}
                padaTutup={() => setUbah(null)}
            />

            {/* Alasan yang sama untuk pilihan yang sedang diketik: membuka
                "Tugaskan" pada mapel lain tanpa menutup panelnya akan membawa
                guru dan kelas yang tadi dipilih ke mapel yang salah. */}
            <FormTugaskan
                key={'tugas-' + (tugaskan?.id ?? 'kosong')}
                mapel={tugaskan}
                guru={guru}
                kelas={kelas}
                tingkat={tingkat}
                padaTutup={() => setTugaskan(null)}
            />
        </AdminLayout>
    );
}

/* -------------------------------------------------------- Kelompok tingkat */

/**
 * Satu tingkat, bisa dilipat.
 *
 * ---------------------------------------------------------------------
 * Mengapa kelompok kosong TETAP ditampilkan
 * ---------------------------------------------------------------------
 * Menyembunyikannya membuat "Tingkat 7, 8, 9" berubah menjadi "Tingkat 8, 9"
 * begitu satu tingkat kebetulan habis mapelnya - dan yang membacanya
 * menyimpulkan tingkat 7 tidak ada di sekolah ini, bukan bahwa mapelnya
 * belum diisi.
 *
 * ---------------------------------------------------------------------
 * Mengapa kepalanya MEMBAWA angkanya
 * ---------------------------------------------------------------------
 * "Tingkat 7 · 12 mapel · 3 penugasan" adalah ringkasan yang membuat tingkat
 * yang datanya belum lengkap terlihat TANPA membukanya - dan itulah satu-
 * satunya cara Admin menemukan tingkat yang terlewat tanpa membuka ketiganya
 * bergantian.
 */
function KelompokMapel({
    kelompok,
    terbuka,
    padaAlih,
    padaUbah,
    padaTugaskan,
}: {
    kelompok: Kelompok;
    terbuka: boolean;
    padaAlih: () => void;
    padaUbah: (m: Baris) => void;
    padaTugaskan: (m: Baris) => void;
}) {
    return (
        <section className="overflow-hidden rounded-wf border border-line bg-surface">
            <button
                type="button"
                onClick={padaAlih}
                aria-expanded={terbuka}
                className="flex w-full flex-wrap items-center gap-3 px-4 py-2.5 text-left transition-colors duration-150 hover:bg-surface-2/60"
            >
                <span
                    className={
                        'font-mono text-xs text-ink-3 transition-transform duration-150 ' +
                        (terbuka ? 'rotate-90' : '')
                    }
                    aria-hidden
                >
                    &#9656;
                </span>

                <span className="font-display text-sm font-semibold text-ink">
                    {kelompok.label}
                </span>

                <span className="font-mono text-xs text-ink-3">
                    {kelompok.jumlah} mapel
                </span>

                <span className="font-mono text-xs text-ink-3">
                    {kelompok.penugasan} penugasan
                </span>

                {/* Kelompok tanpa satu pun mapel dikatakan apa adanya. Chip
                    kosong lebih mudah terlewat daripada kalimat. */}
                {kelompok.jumlah === 0 && (
                    <span className="text-xs text-ink-3">belum ada mapel</span>
                )}
            </button>

            {terbuka && kelompok.baris.length > 0 && (
                <div className="border-t border-line">
                    {kelompok.baris.map((m) => (
                        <BarisMapel
                            key={m.id}
                            baris={m}
                            padaUbah={padaUbah}
                            padaTugaskan={padaTugaskan}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}

/**
 * Satu mapel: kepala ringkas + daftar penugasan di bawahnya.
 *
 * Dipisah dari kelompoknya karena di dalam satu kelompok bentuknya daftar,
 * bukan kotak bertumpuk - kotak di dalam kotak memakan lebar yang justru
 * dibutuhkan penugasan yang bisa panjang ("Bu Ani · X-1" dua belas kali).
 */
function BarisMapel({
    baris: m,
    padaUbah,
    padaTugaskan,
}: {
    baris: Baris;
    padaUbah: (m: Baris) => void;
    padaTugaskan: (m: Baris) => void;
}) {
    return (
        <div className="border-b border-line/50 px-4 py-2.5 last:border-b-0 hover:bg-accent-soft/30">
            <div className="flex flex-wrap items-center gap-3">
                <span className="font-mono text-xs text-ink-3">{m.kode}</span>
                <span className="font-medium text-ink">{m.nama}</span>
                {m.kelompok && <Chip nada="info">{m.kelompok}</Chip>}
                <Chip nada={nadaStatus(m.status)}>{m.status}</Chip>

                <span className="ml-auto flex gap-3">
                    <button
                        onClick={() => padaTugaskan(m)}
                        className="text-xs text-accent hover:underline"
                    >
                        Tugaskan guru
                    </button>
                    <button
                        onClick={() => padaUbah(m)}
                        className="text-xs text-accent hover:underline"
                    >
                        Ubah
                    </button>
                </span>
            </div>

            {m.penugasan.length === 0 ? (
                <p className="mt-1.5 text-[11px] text-ink-3">
                    Belum ada guru ditugaskan. Tanpa penugasan, tidak ada guru yang dapat
                    menyusun soal untuk mapel ini.
                </p>
            ) : (
                <ul className="mt-1.5 flex flex-wrap gap-1.5">
                    {m.penugasan.map((p) => (
                        <li key={p.id}>
                            <span className="inline-flex items-center gap-2 rounded-wf border border-line bg-surface-2/60 px-2 py-1 text-xs text-ink-2">
                                <span>
                                    {p.guru} &middot; {p.kelas}
                                </span>
                                <button
                                    onClick={() =>
                                        router.delete(`/admin/penugasan/${p.id}`, {
                                            preserveScroll: true,
                                        })
                                    }
                                    className="text-ink-3 hover:text-bad"
                                    aria-label="Batalkan penugasan"
                                >
                                    &#10005;
                                </button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function FormMapel({
    terbuka,
    mapel,
    padaTutup,
}: {
    terbuka: boolean;
    mapel?: Baris | null;
    padaTutup: () => void;
}) {
    const ubah = Boolean(mapel);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        code: mapel?.kode ?? '',
        name: mapel?.nama ?? '',
        category: mapel?.kelompok ?? '',
        grade_level: mapel?.tingkat ?? '',
        status: mapel?.status ?? 'aktif',
    });

    return (
        <Modal
            judul={ubah ? `Ubah ${mapel?.nama}` : 'Tambah mata pelajaran'}
            terbuka={terbuka}
            padaTutup={padaTutup}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    const opsi = {
                        onSuccess: () => {
                            padaTutup();
                            reset();
                        },
                    };

                    if (ubah && mapel) {
                        put(`/admin/mapel/${mapel.id}`, opsi);
                    } else {
                        post('/admin/mapel', opsi);
                    }
                }}
                className="space-y-3"
            >
                <div className="grid gap-3 sm:grid-cols-[6rem_1fr]">
                    <Field label="Kode" galat={errors.code}>
                        <input
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                            placeholder="MTK"
                            className={kelasInput + ' font-mono'}
                        />
                    </Field>
                    <Field label="Nama" galat={errors.name}>
                        <input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className={kelasInput}
                        />
                    </Field>
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Kelompok" galat={errors.category}>
                        <input
                            value={data.category}
                            onChange={(e) => setData('category', e.target.value)}
                            placeholder="Wajib / Peminatan / Kejuruan"
                            className={kelasInput}
                        />
                    </Field>
                    <Field label="Tingkat" galat={errors.grade_level}>
                        <input
                            value={data.grade_level}
                            onChange={(e) => setData('grade_level', e.target.value)}
                            placeholder="X / XI / XII"
                            className={kelasInput}
                        />
                    </Field>
                </div>

                <Field label="Status" galat={errors.status}>
                    <select
                        value={data.status}
                        onChange={(e) => setData('status', e.target.value)}
                        className={kelasInput}
                    >
                        <option value="aktif">aktif</option>
                        <option value="nonaktif">nonaktif</option>
                    </select>
                </Field>

                <div className="flex gap-2 pt-1">
                    <Tombol type="submit" nada="pri" disabled={processing}>
                        Simpan
                    </Tombol>
                    <Tombol onClick={padaTutup}>Batal</Tombol>
                </div>
            </form>
        </Modal>
    );
}

/**
 * Tugaskan guru - satu kelas, atau seluruh satu tingkat sekaligus.
 *
 * ---------------------------------------------------------------------
 * Mengapa ada dua lingkup, bukan satu daftar kelas berisi optgroup tingkat
 * ---------------------------------------------------------------------
 * Yang tampak seperti pilihan tata letak ini sebenarnya soal APA yang
 * disetujui Admin. `<optgroup>` terlihat lebih ringkas, tetapi ia menyembunyikan
 * akibatnya: "tingkat 9" di dalam daftar kelas tampak seperti satu pilihan di
 * antara sebelas, padahal ia adalah sebelas izin sekaligus.
 *
 * Karena itu lingkupnya dipilih lebih dulu, dan begitu tingkat dipilih,
 * layar MENYEBUT kelas mana saja yang tercakup. Admin menugaskan sebelas
 * kelas; ia berhak melihat kesebelasnya sebelum menekan tombol.
 *
 * ---------------------------------------------------------------------
 * Mengapa dua kolom, bukan nilai yang dipakai bergantian
 * ---------------------------------------------------------------------
 * `class_id` dan `tingkat` dikirim bersama-sama, apa pun lingkupnya, dan yang
 * menentukan adalah `lingkup`. Memakai satu kolom bergantian - `class_id`
 * berisi id kelas ATAU nama tingkat - berarti server harus menebak dari
 * bentuk nilainya, dan satu tingkat bernama "12" tidak bisa dibedakan dari
 * id kelas 12. Di sini server tidak menebak apa pun.
 */
function FormTugaskan({
    mapel,
    guru,
    kelas,
    tingkat,
    padaTutup,
}: {
    mapel: Baris | null;
    guru: { id: number; nama: string }[];
    kelas: { id: number; nama: string }[];
    tingkat: { tingkat: string; jumlah: number; kelas: string[] }[];
    padaTutup: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        lingkup: 'kelas' as 'kelas' | 'tingkat',
        teacher_id: '',
        class_id: '',
        tingkat: '',
    });

    const dipilih = tingkat.find((t) => t.tingkat === data.tingkat);

    return (
        <Modal
            judul={mapel ? `Tugaskan guru pada ${mapel.nama}` : 'Tugaskan guru'}
            terbuka={mapel !== null}
            padaTutup={padaTutup}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/admin/mapel/${mapel?.id}/tugaskan`, {
                        onSuccess: () => {
                            padaTutup();
                            reset();
                        },
                    });
                }}
                className="space-y-3"
            >
                <Field label="Guru" galat={errors.teacher_id}>
                    <select
                        value={data.teacher_id}
                        onChange={(e) => setData('teacher_id', e.target.value)}
                        className={kelasInput}
                    >
                        <option value="">Pilih guru...</option>
                        {guru.map((g) => (
                            <option key={g.id} value={g.id}>
                                {g.nama}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field label="Lingkup penugasan">
                    <div className="flex gap-2">
                        {(
                            [
                                ['kelas', 'Satu kelas'],
                                ['tingkat', 'Seluruh tingkat'],
                            ] as const
                        ).map(([nilai, label]) => (
                            <label
                                key={nilai}
                                className={
                                    'flex-1 cursor-pointer rounded-wf border px-3 py-2 text-center text-xs ' +
                                    (data.lingkup === nilai
                                        ? 'border-accent bg-accent/5 text-ink'
                                        : 'border-line text-ink-3 hover:text-ink-2')
                                }
                            >
                                <input
                                    type="radio"
                                    name="lingkup"
                                    value={nilai}
                                    checked={data.lingkup === nilai}
                                    onChange={() => setData('lingkup', nilai)}
                                    className="sr-only"
                                />
                                {label}
                            </label>
                        ))}
                    </div>
                </Field>

                {data.lingkup === 'kelas' ? (
                    <Field
                        label="Kelas"
                        galat={errors.class_id}
                        petunjuk="Kelas dari tahun ajaran aktif saja."
                    >
                        <select
                            value={data.class_id}
                            onChange={(e) => setData('class_id', e.target.value)}
                            className={kelasInput}
                        >
                            <option value="">Pilih kelas...</option>
                            {kelas.map((k) => (
                                <option key={k.id} value={k.id}>
                                    {k.nama}
                                </option>
                            ))}
                        </select>
                    </Field>
                ) : (
                    <Field
                        label="Tingkat"
                        galat={errors.tingkat}
                        petunjuk="Kelas dari tahun ajaran aktif saja."
                    >
                        <select
                            value={data.tingkat}
                            onChange={(e) => setData('tingkat', e.target.value)}
                            className={kelasInput}
                        >
                            <option value="">Pilih tingkat...</option>
                            {tingkat.map((t) => (
                                <option key={t.tingkat} value={t.tingkat}>
                                    Tingkat {t.tingkat} &mdash; {t.jumlah} kelas
                                </option>
                            ))}
                        </select>
                    </Field>
                )}

                {data.lingkup === 'tingkat' && dipilih && (
                    <div className="rounded-wf border border-line bg-surface-2/60 px-3 py-2">
                        <p className="text-[11px] text-ink-3">
                            Akan ditugaskan di {dipilih.jumlah} kelas
                        </p>
                        <p className="mt-1 text-xs leading-relaxed text-ink-2">
                            {dipilih.kelas.join(', ')}
                        </p>
                    </div>
                )}

                {/* Tingkat tanpa kelas sama sekali tidak bisa muncul di sini -
                    katalognya dibangun dari kelas yang ada. Yang mungkin
                    adalah katalognya kosong sejak awal, dan itu keadaan yang
                    berbeda: tidak ada kelas mana pun yang punya tingkat. */}
                {data.lingkup === 'tingkat' && tingkat.length === 0 && (
                    <Inbox>
                        Belum ada kelas yang tingkatnya terisi di tahun ajaran aktif.
                        Isi tingkatnya di laman Kelas, atau tugaskan per kelas.
                    </Inbox>
                )}

                <Inbox>
                    {data.lingkup === 'kelas'
                        ? 'Satu baris penugasan adalah satu izin: guru tersebut boleh menyusun soal untuk mapel ini di kelas itu, dan tidak untuk yang lain.'
                        : 'Satu tindakan ini membuat satu penugasan untuk setiap kelas di tingkat itu. Kelas yang sudah ditugaskan sebelumnya dilewati, bukan ditimpa.'}
                </Inbox>

                <div className="flex gap-2 pt-1">
                    <Tombol type="submit" nada="pri" disabled={processing}>
                        Tugaskan
                    </Tombol>
                    <Tombol onClick={padaTutup}>Batal</Tombol>
                </div>
            </form>
        </Modal>
    );
}
