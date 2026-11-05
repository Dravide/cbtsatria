import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Chip,
    Field,
    Inbox,
    Kotak,
    Peringatan,
    Tabel,
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
    bentrok: { jenis: string; pesan: string }[];
};

type Ujian = {
    id: number;
    kode: string;
    judul: string;
    mapel: string | null;
    durasi: number;
    sesi: number;
    status: string;
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    baris: Baris[];
    temuan: { jenis: string; pesan: string; sesi_id: number | null }[];
    validasi: { kunci: string; label: string; lulus: boolean; jumlah: number }[];
    ujian: Ujian[];
    ruang: { id: number; kode: string | null; nama: string | null; kapasitas: number; status: string }[];
    kelas: { id: number; nama: string }[];
    mapel: { id: number; kode: string | null; nama: string | null }[];
    angka: { ujian: number; sesi: number; konflik: number; belum_ditempatkan: number };
    jenis_ujian: string[];
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
    jenis_ujian,
}: Props) {
    const [panelUjian, setPanelUjian] = useState(false);
    const [panelSesi, setPanelSesi] = useState(false);
    const [pesanUjian, setPesanUjian] = useState<string | null>(null);

    const jalur = `/admin/events/${event.id}`;

    const barisBentrok = temuan.filter((t) => t.sesi_id !== null).length;

    return (
        <AdminLayout
            judul="Jadwal & sesi"
            breadcrumb={`/admin/events · ${event.kode} · Jadwal & sesi`}
            aksi={
                <>
                    {/* Pemilih event, seperti plate 16. Ia memuat ulang laman
                        event yang sedang dibuka; yang dipilih adalah event,
                        bukan daftar sesi. */}
                    <PemilihEvent event={event} />

                    <Tombol
                        onClick={() => router.post(`${jalur}/jadwal/deteksi`, {}, { preserveScroll: true })}
                        title="Periksa ulang jadwal dan laporkan hasilnya"
                    >
                        Deteksi konflik
                    </Tombol>

                    {/* Ujian perlu tempat lahir, dan plate ini yang punya:
                        tabelnya mengelompokkan per ujian, dan sesi tidak bisa
                        ada sebelum ujiannya ada. Tombolnya sekunder karena
                        plate 16 sendiri menandai "Tambah sesi" sebagai aksi
                        utama - yang ditambahkan di sini adalah pintu masuk
                        ujiannya, bukan pengganti aksi platnya. */}
                    <Tombol onClick={() => setPanelUjian(true)}>Tambah ujian</Tombol>

                    {/* Nada `pri` diambil dari platenya sendiri: pada
                        `wireframe-admin.html:903` tombol ini berbunyi
                        `<span class="btn pri">Tambah sesi</span>`, sementara
                        "Deteksi konflik" di sebelahnya hanya `btn` biasa.
                        Menjadwalkan sesi memang pekerjaan utamanya; memeriksa
                        konflik adalah pemeriksaan yang menemani, bukan
                        tindakan yang menghasilkan sesuatu. */}
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

            <div className="mb-3 grid gap-3 sm:grid-cols-4">
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

            {/* Kotak "Tambah ujian" hanya muncul saat diminta: plate 16 tidak
                punya panel itu, dan menampilkan form penuh §9.2 di laman
                jadwal sepanjang waktu akan mengubur tabelnya. */}
            {panelUjian && (
                <div className="mb-4">
                    <FormUjian
                        jalur={jalur}
                        mapel={mapel}
                        kelas={kelas}
                        jenis={jenis_ujian}
                        padaTutup={() => setPanelUjian(false)}
                        padaGalat={setPesanUjian}
                    />
                </div>
            )}

            {panelSesi && (
                <div className="mb-4">
                    <FormSesi
                        jalur={jalur}
                        ujian={ujian}
                        ruang={ruang}
                        padaTutup={() => setPanelSesi(false)}
                    />
                </div>
            )}

            {pesanUjian && (
                <div className="mb-4">
                    <Peringatan>{pesanUjian}</Peringatan>
                </div>
            )}

            {/* Kalimat bentrok ditulis di atas tabel, seperti plate 16.
                Ia menyebut sesi dan ruangnya, bukan sekadar jumlah - angka
                "3 konflik" tidak memberi tahu Admin apa yang harus digeser. */}
            {temuan.length > 0 && (
                <div className="mb-3 space-y-2">
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

            <Tabel<Baris>
                hal={{ data: baris, total: baris.length }}
                kunci={(b) => b.id}
                kosong="Belum ada sesi. Buat ujian, lalu tambahkan sesinya."
                kolom={[
                    {
                        judul: 'Sesi',
                        kelas: 'w-20',
                        render: (b) => (
                            <span className="font-mono text-xs font-medium text-ink">{b.label}</span>
                        ),
                    },
                    {
                        judul: 'Tanggal',
                        kelas: 'w-24',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">{b.tanggal ?? '—'}</span>
                        ),
                    },
                    {
                        judul: 'Jam',
                        kelas: 'w-32',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">
                                {b.jam ?? '—'}
                                {b.selesai && <span className="text-ink-3">–{b.selesai}</span>}
                            </span>
                        ),
                    },
                    {
                        judul: 'Ujian',
                        render: (b) => <span className="text-sm text-ink">{b.ujian}</span>,
                    },
                    {
                        judul: 'Ruang',
                        kelas: 'w-40',
                        render: (b) =>
                            b.bentrok.length > 0 ? (
                                // Baris bentrok ditandai DI SINI, bukan hanya di
                                // panel bawah - anotasi plate 16 menuntutnya,
                                // dan dialah satu-satunya tanda yang menempel
                                // pada baris yang harus diperbaiki.
                                <span className="flex items-center gap-2">
                                    <Chip nada="bad">bentrok</Chip>
                                    <span className="font-mono text-[11px] text-ink-3">
                                        {b.ruang} ruang
                                    </span>
                                </span>
                            ) : (
                                <span className="font-mono text-xs text-ink-2">
                                    {b.ruang_kode.length > 0 ? b.ruang_kode.join(', ') : '—'}
                                </span>
                            ),
                    },
                    {
                        judul: 'Peserta',
                        kelas: 'w-20',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">{b.peserta}</span>
                        ),
                    },
                    {
                        judul: 'Status',
                        kelas: 'w-28',
                        render: (b) => (
                            <span className="flex items-center gap-1.5">
                                <Chip nada={nadaStatus(b.status)}>{b.status_label}</Chip>
                            </span>
                        ),
                    },
                    {
                        judul: '',
                        kelas: 'w-56',
                        render: (b) => <AksiSesi jalur={jalur} baris={b} />,
                    },
                ]}
            />

            {/* Temuan yang menempel pada baris, ditulis di bawah tabel. Ia
                mengulang pesan yang sudah ada di panel validasi dengan sengaja:
                panel menyebut JENIS temuan, yang ini menyebut barisnya. */}
            {barisBentrok > 0 && (
                <div className="mt-2 space-y-1">
                    {baris
                        .filter((b) => b.bentrok.length > 0)
                        .map((b) => (
                            <p key={b.id} className="text-xs text-bad">
                                <span className="font-mono">{b.label}</span>{' '}
                                {b.bentrok[0].pesan}
                            </p>
                        ))}
                </div>
            )}

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <Kotak judul="Validasi jadwal">
                    <div className="space-y-2">
                        {validasi.map((v) => (
                            <div key={v.kunci} className="flex items-start gap-2 text-xs">
                                <span
                                    className={
                                        'mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-wf border text-[10px] ' +
                                        (v.lulus
                                            ? 'border-ok/40 bg-ok-bg text-ok'
                                            : 'border-line bg-surface-2 text-ink-3')
                                    }
                                >
                                    {v.lulus ? '✓' : ''}
                                </span>
                                <span className={v.lulus ? 'text-ink-2' : 'text-ink'}>
                                    {v.label}
                                    {!v.lulus && v.jumlah > 0 && (
                                        <span className="ml-1 text-bad">({v.jumlah})</span>
                                    )}
                                </span>
                            </div>
                        ))}
                    </div>

                    <p className="mt-3 border-t border-line pt-3 text-[11px] leading-relaxed text-ink-3">
                        Pemeriksaan ini berjalan setiap laman dimuat. <em>Deteksi konflik</em>{' '}
                        menyatakannya dalam kalimat supaya Anda yang baru kembali ke laman ini
                        tahu angkanya masih yang terakhir.
                    </p>
                </Kotak>

                <Kotak judul="Ujian di event ini">
                    {ujian.length === 0 ? (
                        <p className="text-xs text-ink-3">
                            Belum ada ujian. Tekan <em>Tambah ujian</em> untuk membuatnya.
                        </p>
                    ) : (
                        <ul className="divide-y divide-line">
                            {ujian.map((u) => (
                                <li key={u.id} className="flex items-center gap-3 py-2 text-sm">
                                    <span className="font-mono text-xs text-ink-3">{u.kode}</span>
                                    <span className="text-ink">{u.mapel ?? u.judul}</span>
                                    <span className="ml-auto font-mono text-xs text-ink-3">
                                        {u.durasi} mnt
                                    </span>
                                    <span className="font-mono text-xs text-ink-3">
                                        {u.sesi} sesi
                                    </span>
                                    <Chip nada={nadaStatus(u.status)}>{u.status}</Chip>
                                </li>
                            ))}
                        </ul>
                    )}
                </Kotak>
            </div>

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
    mapel,
    kelas,
    jenis,
    padaTutup,
    padaGalat,
}: {
    jalur: string;
    mapel: Props['mapel'];
    kelas: Props['kelas'];
    jenis: string[];
    padaTutup: () => void;
    padaGalat: (pesan: string | null) => void;
}) {
    const [isi, setIsi] = useState({
        subject_id: mapel[0]?.id ?? 0,
        title: '',
        exam_type: jenis[0] ?? '',
        duration_minutes: 60,
        max_attempts: 1,
        instructions: '',
        allow_back_navigation: true,
        shuffle_questions: false,
        shuffle_options: false,
        allow_late_entry: false,
    });
    const [pilihKelas, setPilihKelas] = useState<number[]>([]);

    const simpan = () => {
        padaGalat(null);

        router.post(
            `${jalur}/ujian`,
            { ...isi, class_id: pilihKelas },
            {
                preserveScroll: true,
                onSuccess: () => padaTutup(),
                onError: (e) => padaGalat(Object.values(e)[0] ?? 'Ujian tidak bisa disimpan.'),
            },
        );
    };

    if (mapel.length === 0) {
        return (
            <Kotak judul="Tambah ujian">
                <Peringatan>
                    Belum ada mata pelajaran yang ditetapkan di event ini. Tetapkan mapelnya di
                    laman <strong>Paket soal</strong> terlebih dahulu.
                </Peringatan>
            </Kotak>
        );
    }

    return (
        <Kotak
            judul="Tambah ujian"
            aksi={
                <>
                    <Tombol nada="sek" onClick={padaTutup}>
                        Batal
                    </Tombol>
                    <Tombol nada="pri" onClick={simpan}>
                        Simpan ujian
                    </Tombol>
                </>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Field label="Mata pelajaran">
                    <select
                        value={isi.subject_id}
                        onChange={(e) => setIsi({ ...isi, subject_id: Number(e.target.value) })}
                        className={kelasInput}
                    >
                        {mapel.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.kode} {m.nama}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field label="Judul ujian" petunjuk="Mis. UAS Matematika Kelas X">
                    <input
                        value={isi.title}
                        onChange={(e) => setIsi({ ...isi, title: e.target.value })}
                        className={kelasInput}
                        placeholder="UAS Matematika"
                    />
                </Field>

                <Field label="Jenis">
                    <select
                        value={isi.exam_type}
                        onChange={(e) => setIsi({ ...isi, exam_type: e.target.value })}
                        className={kelasInput}
                    >
                        {jenis.map((j) => (
                            <option key={j} value={j}>
                                {j}
                            </option>
                        ))}
                    </select>
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
    padaTutup,
}: {
    jalur: string;
    ujian: Ujian[];
    ruang: Props['ruang'];
    padaTutup: () => void;
}) {
    const [ujianId, setUjianId] = useState(ujian[0]?.id ?? 0);
    const [buka, setBuka] = useState('');
    const [tutup, setTutup] = useState('');
    const [akhir, setAkhir] = useState('');
    const [pilihRuang, setPilihRuang] = useState<number[]>([]);
    const [galat, setGalat] = useState<string | null>(null);

    const simpan = () => {
        setGalat(null);

        router.post(
            `${jalur}/ujian/${ujianId}/sesi`,
            {
                access_opens_at: buka,
                access_closes_at: tutup,
                hard_end_at: akhir,
                event_room_id: pilihRuang,
            },
            {
                preserveScroll: true,
                onSuccess: () => padaTutup(),
                onError: (e) => setGalat(Object.values(e)[0] ?? 'Sesi tidak bisa disimpan.'),
            },
        );
    };

    return (
        <Kotak
            judul="Tambah sesi"
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
                        Simpan sesi
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
                        className={kelasInput}
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

/* ---------------------------------------------------------------- Aksi baris */

function AksiSesi({ jalur, baris }: { jalur: string; baris: Baris }) {
    const kirim = (aksi: string) =>
        router.post(`${jalur}/ujian/${baris.ujian_id}/sesi/${baris.id}/${aksi}`, {}, { preserveScroll: true });

    return (
        <span className="flex justify-end gap-1.5">
            {baris.status === 'berjalan' ? (
                <Tombol nada="sek" onClick={() => kirim('tutup')}>
                    Tutup
                </Tombol>
            ) : (
                <Tombol
                    nada="sek"
                    disabled={baris.status === 'selesai' || baris.status === 'batal'}
                    title={
                        baris.status === 'selesai'
                            ? 'Sesi ini sudah ditutup.'
                            : 'Buka sesi supaya siswa bisa masuk.'
                    }
                    onClick={() => kirim('mulai')}
                >
                    Buka
                </Tombol>
            )}

            <Tombol
                nada="bahaya"
                onClick={() => {
                    if (confirm(`Hapus ${baris.label}? Pesertanya dilepas dari ruang.`)) {
                        router.delete(`${jalur}/ujian/${baris.ujian_id}/sesi/${baris.id}`, {
                            preserveScroll: true,
                        });
                    }
                }}
            >
                Hapus
            </Tombol>
        </span>
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
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className={'mt-1 font-mono text-2xl font-medium ' + warna}>
                {nilai.toLocaleString('id-ID')}
            </p>
        </div>
    );
}
