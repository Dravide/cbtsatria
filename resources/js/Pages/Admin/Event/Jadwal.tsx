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

    // Ujian yang sedang diubah. Sama polanya dengan `sesiDiubah`: SALINAN
    // barisnya, bukan idnya, supaya panelnya tetap utuh walau tabelnya dimuat
    // ulang di belakang.
    const [ujianDiubah, setUjianDiubah] = useState<Ujian | null>(null);

    // Baris yang sedang diubah. Disimpan sebagai SALINAN baris (bukan id),
    // karena panel ubah memuat ulang seluruh nilai jendela dan ruang dari
    // situ - dan salinan itu tetap utuh walau tabel dimuat ulang di belakang.
    const [sesiDiubah, setSesiDiubah] = useState<Baris | null>(null);

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
                        jenisEvent={event.jenis}
                        padaTutup={() => setPanelUjian(false)}
                        padaGalat={setPesanUjian}
                    />
                </div>
            )}

            {/* Panel ubah ujian. Satu kotak di ATAS tabel, sama seperti panel
                tambah dan panel sesi - bukan modal per baris. */}
            {ujianDiubah && (
                <div className="mb-4">
                    <FormUjian
                        // `key` bukan hiasan: berpindah dari satu ujian ke ujian
                        // lain tanpa menutup panelnya memakai ULANG state form
                        // yang sama, sehingga yang tampil adalah nilai ujian
                        // yang pertama - dan menyimpannya menimpa ujian kedua
                        // dengan isi ujian pertama.
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
                <div className="mb-4">
                    <FormSesi
                        jalur={jalur}
                        ujian={ujian}
                        ruang={ruang}
                        padaTutup={() => setPanelSesi(false)}
                    />
                </div>
            )}

            {/* Panel ubah sesi. Satu kotak di ATAS tabel, sama seperti panel
                tambah - bukan modal per baris: jendela tiga waktu dan daftar
                ruang butuh lebar, dan kotak di dalam tabel akan terjepit.
                Galatnya tampil di dalam panel itu sendiri. */}
            {sesiDiubah && (
                <div className="mb-4">
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
                                {/* Alasannya dibawa di `title`, bukan sebagai
                                    baris sendiri: kolom ini 7rem, dan satu
                                    kalimat pembatalan akan mendorong Status
                                    sesi lain keluar dari kolomnya. Yang
                                    selalu terbaca adalah chip-nya; yang
                                    ingin tahu mengapa mengarahkan penunjuk. */}
                                <span
                                    title={
                                        b.alasan_batal ??
                                        (b.status === 'batal' ? 'Dibatalkan tanpa keterangan.' : undefined)
                                    }
                                >
                                    <Chip nada={nadaStatus(b.status)}>{b.status_label}</Chip>
                                </span>
                            </span>
                        ),
                    },
                    {
                        judul: '',
                        kelas: 'w-72',
                        render: (b) => <AksiSesi jalur={jalur} baris={b} padaUbah={setSesiDiubah} />,
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
                                    {/* Tidak ada chip status ujian di sini.
                                        Kolomnya dijatuhkan 2026-11-07: satu
                                        penulis, nol pembaca logika, sehingga
                                        chipnya mustahil berbunyi selain
                                        "draft". Status yang berarti ada di
                                        tabel sesi di bawah, per sesi. */}

                                    {/* Ujian lahir di laman ini, jadi ia juga
                                        harus bisa diperbaiki di laman ini.
                                        Tanpa tombol ini, salah ketik durasi
                                        hanya bisa ditambal dengan menghapus
                                        ujiannya - dan menghapusnya berarti
                                        kehilangan sesinya. */}
                                    <Tombol
                                        nada="sek"
                                        title="Ubah judul, durasi, kelas sasaran, dan perilaku ujian"
                                        onClick={() => setUjianDiubah(u)}
                                    >
                                        Ubah
                                    </Tombol>
                                    {/* Ujian lahir di laman ini, jadi salah
                                        pilih mapel juga harus bisa dibatalkan
                                        di laman ini. Sesi yang sudah berjalan
                                        menolak di server dan pesannya muncul
                                        sebagai galat - lihat
                                        `hapusUjian()` di controller. */}
                                    <Tombol
                                        nada="bahaya"
                                        title="Hapus ujian beserta jadwalnya"
                                        onClick={() => {
                                            if (
                                                confirm(
                                                    `Hapus ${u.kode} ${u.judul}? Seluruh sesinya yang belum berjalan ikut terhapus.`,
                                                )
                                            ) {
                                                router.delete(`${jalur}/ujian/${u.id}`, {
                                                    preserveScroll: true,
                                                });
                                            }
                                        }}
                                    >
                                        Hapus
                                    </Tombol>
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

/* ---------------------------------------------------------------- Aksi baris */

function AksiSesi({
    jalur,
    baris,
    padaUbah,
}: {
    jalur: string;
    baris: Baris;
    padaUbah: (baris: Baris) => void;
}) {
    const kirim = (aksi: string) =>
        router.post(`${jalur}/ujian/${baris.ujian_id}/sesi/${baris.id}/${aksi}`, {}, { preserveScroll: true });

    // Sesi yang sudah Selesai atau Batal bersifat final (StatusSesi): jendela
    // dan ruangnya tidak bisa diubah lagi, karena peserta mungkin sudah
    // mengerjakan. Menawarkan "Ubah" yang pasti ditolak lebih buruk daripada
    // tombol yang mati beserta alasannya.
    const final = baris.status === 'selesai' || baris.status === 'batal';

    return (
        <span className="flex justify-end gap-1.5">
            <Tombol
                nada="sek"
                disabled={final}
                title={
                    final
                        ? 'Sesi yang sudah selesai atau batal tidak bisa diubah lagi.'
                        : 'Ubah jendela waktu dan ruang sesi'
                }
                onClick={() => padaUbah(baris)}
            >
                Ubah
            </Tombol>

            {/* Siapkan HANYA muncul pada sesi Draft, dan itu bukan sekadar
                penghematan tempat: graf StatusSesi menolak Siap dari status
                lain, jadi tombol pada sesi Siap/Berjalan/Selesai pasti gagal.
                Langkah ini wajib - tanpanya "Buka" selalu ditolak dengan
                "berstatus Draft dan tidak bisa dibuka". */}
            {baris.status === 'draft' && (
                <Tombol
                    nada="sek"
                    title="Tandai jendela sesi sudah final, supaya sesi bisa dibuka."
                    onClick={() => kirim('siapkan')}
                >
                    Siapkan
                </Tombol>
            )}

            {baris.status === 'berjalan' ? (
                <Tombol nada="sek" onClick={() => kirim('tutup')}>
                    Tutup
                </Tombol>
            ) : (
                <Tombol
                    nada="sek"
                    disabled={final || baris.status === 'draft'}
                    title={
                        baris.status === 'selesai'
                            ? 'Sesi ini sudah ditutup.'
                            : baris.status === 'draft'
                              ? 'Siapkan dulu sesinya. Graf status menolak Draft langsung ke Berjalan.'
                              : 'Buka sesi supaya siswa bisa masuk.'
                    }
                    onClick={() => kirim('mulai')}
                >
                    Buka
                </Tombol>
            )}

            {/* Batalkan - dan ia HANYA muncul selama sesinya belum final.
                Alasannya ditanyakan lebih dulu dan dikirim bersama
                permintaannya: baris "batal" tanpa keterangan membuat Admin
                berikutnya menyusun ulang jadwal yang sudah sengaja
                dibatalkan. Batal pada dialog berarti tidak ada permintaan
                sama sekali - pola yang sama dengan buka kunci di papan
                monitoring. */}
            {!final && (
                <Tombol
                    nada="bahaya"
                    title="Batalkan sesi: tokennya dicabut, dan sesi ini tidak dihitung lagi di jadwal."
                    onClick={() => {
                        const alasan = window.prompt(
                            `Batalkan ${baris.label}?\n\nToken sesi ini akan dicabut. Tulis alasannya (boleh dikosongkan):`,
                            '',
                        );

                        if (alasan === null) {
                            return;
                        }

                        router.post(
                            `${jalur}/ujian/${baris.ujian_id}/sesi/${baris.id}/batal`,
                            { alasan },
                            { preserveScroll: true },
                        );
                    }}
                >
                    Batalkan
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
