import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Chip,
    Field,
    Inbox,
    Kotak,
    Peringatan,
    Tombol,
    kelasInput,
} from '@/Components/ui';

/**
 * Laman 17 - Pengawas & penempatan ruang. Wireframe plate 17, PRD §9.6.
 *
 * ---------------------------------------------------------------------
 * Laman ini bukan daftar nama, dan itu yang menentukan bentuknya
 * ---------------------------------------------------------------------
 * Anotasi plate 17 menyatakannya: "penugasan inilah yang memberi Pengawas hak
 * melihat dan memverifikasi presensi di ruang itu. Tanpa penugasan, ia tidak
 * melihat apa pun - dan itu batas isolasi yang harus tetap berlaku meskipun ia
 * guru di sekolah yang sama."
 *
 * Konsekuensinya terlihat di tombol "Buka" pada laman jadwal: ia menolak
 * selama masih ada ruang tanpa pengawas. Karena itu angka "Ruang tanpa
 * pengawas" di sini BUKAN statistik - ia sebab dari penolakan di laman lain,
 * dan menampilkannya sebagai nol palsu akan membuat Admin menebak-nebak.
 *
 * ---------------------------------------------------------------------
 * Kolomnya dua, tetapi datanya daftar
 * ---------------------------------------------------------------------
 * Plate 17 menampilkan `Pengawas 1 | Pengawas 2`. PRD §9.6 mengizinkan lebih
 * dari dua, jadi yang dirender adalah daftar - dua nama pertama di kolomnya,
 * sisanya dihitung. Memaksa dua kolom berarti orang ketiga tersimpan tanpa
 * pernah terlihat di laman yang menugaskannya, dan itu bentuk kehilangan data
 * yang paling sulit disadari.
 *
 * ---------------------------------------------------------------------
 * Satu pengawas per ruang sah, dan laman ini tidak boleh bilang sebaliknya
 * ---------------------------------------------------------------------
 * Plate 17 menggambar `R-02` berisi satu nama lalu menandainya sebagai
 * peringatan. Yang dilanggar R-02 bukan aturan: PRD §9.6 berbunyi "satu ruang
 * dapat memiliki beberapa Pengawas", dan yang benar-benar menghalangi ujian
 * hanyalah ruang yang NOL pengawas - presensi harus diverifikasi orang, dan
 * orang yang tidak ditugaskan tidak bisa membuka ruangnya.
 *
 * Bedanya bukan hiasan kata. Sekolah dengan 24 ruang dan 26 pengawas memang
 * hanya sanggup satu orang per ruang, dan laman yang menandai seluruh
 * jadwalnya kuning setiap hari akan diabaikan tepat pada saat peringatan itu
 * benar-benar perlu dibaca. Karena itu dua hal dipisahkan di layar:
 * ruang tanpa pengawas adalah TEMUAN, ruang berpengawas satu adalah SARAN.
 */
type PengawasBaris = {
    id: number;
    user_id: number;
    nama: string | null;
    username: string | null;
    status: string;
};

type Baris = {
    id: number;
    ruang: string | null;
    ruang_nama: string | null;
    kapasitas: number;
    sesi: string | null;
    sesi_id: number | null;
    ujian: string | null;
    jam: string | null;
    tanggal: string | null;
    pengawas: PengawasBaris[];
    peserta: number;
};

type RuangSesi = {
    id: number;
    ruang: string | null;
    ruang_nama: string | null;
    kapasitas: number;
    peserta: number;
    pengawas: PengawasBaris[];
};

type SesiKelompok = {
    id: number;
    label: string;
    ujian: string | null;
    tanggal: string | null;
    jam: string | null;
    selesai: string | null;
    status: string;
    status_label: string;
    jenis: string;
    susulan: boolean;
    ruang: RuangSesi[];
    jumlah_ruang: number;
    jumlah_pengawas: number;
    /** Kode ruang di sesi ini yang belum punya satu pun pengawas. */
    ruang_tanpa_pengawas: string[];
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    baris: Baris[];
    /** Baris yang sama, dikelompokkan per sesi - bentuk yang ditampilkan. */
    per_sesi: SesiKelompok[];
    angka: {
        ruang: number;
        ditugaskan: number;
        tanpa_pengawas: number;
        saran_per_ruang: number;
        minimal_per_ruang: number;
    };
    saran: Baris[];
    calon: { id: number; nama: string; username: string; peran: string }[];
    sesi_tanpa_ruang: { id: number; label: string; ujian: string | null }[];
};

export default function EventPengawas({
    event,
    baris,
    per_sesi,
    angka,
    saran,
    calon,
    sesi_tanpa_ruang,
}: Props) {
    const [panel, setPanel] = useState(false);
    const [ruangId, setRuangId] = useState<number | null>(null);
    const [orangId, setOrangId] = useState<number | null>(null);

    // Panel tukar - ditambahkan 2026-11-14. `null` = tertutup. Dibuka dari
    // tombol Tukar pada baris dengan isi BARIS ITU lebih dulu dipilih, karena
    // Admin yang menekan Tukar di baris R-02 sedang ingin menukar R-02 -
    // menampilkan dua dropdown kosong berarti menanyakannya lagi.
    const [tukar, setTukar] = useState<{ a: number | null; b: number | null } | null>(null);

    const jalur = `/admin/events/${event.id}`;

    /**
     * Seluruh penugasan event ini, sebagai pilihan panel tukar.
     *
     * Disusun dari `baris` yang sudah dikirim server - bukan dari state lain.
     * Daftar yang disusun ulang di peramban adalah daftar yang suatu hari
     * berbeda dari yang ditampilkan tabelnya.
     */
    const semuaPenugasan = baris.flatMap((b) =>
        b.pengawas.map((p) => ({
            id: p.id,
            label: `${b.ruang ?? 'R-?'} · ${b.sesi ?? '—'} · ${p.nama ?? p.username ?? '—'}`,
        })),
    );

    const bukaTukar = (barisId?: number) => {
        const awal = barisId === undefined ? null : (baris.find((b) => b.id === barisId)?.pengawas[0]?.id ?? null);

        setTukar({ a: awal, b: null });
    };

    const kirimTukar = () => {
        if (tukar?.a === null || tukar?.b === null || tukar === null) {
            return;
        }

        router.post(
            `${jalur}/pengawas/tukar`,
            { a: tukar.a, b: tukar.b },
            { preserveScroll: true, onSuccess: () => setTukar(null) },
        );
    };

    const bukaPanel = (id?: number) => {
        setRuangId(id ?? baris.find((b) => b.pengawas.length === 0)?.id ?? baris[0]?.id ?? null);
        setOrangId(calon[0]?.id ?? null);
        setPanel(true);
    };

    const tugaskan = () => {
        if (ruangId === null || orangId === null) {
            return;
        }

        router.post(
            `${jalur}/pengawas/ruang/${ruangId}`,
            { supervisor_id: orangId },
            { preserveScroll: true, onSuccess: () => setPanel(false) },
        );
    };

    return (
        <AdminLayout
            judul="Pengawas & penempatan ruang"
            breadcrumb={`/admin/events · ${event.kode} · Pengawas`}
            aksi={
                <>
                    <span className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink-2">
                        <span className="text-ink-3">Event:</span>
                        <span className="font-medium text-ink">{event.nama}</span>
                    </span>

                    <Tombol
                        disabled={baris.length === 0}
                        title={
                            baris.length === 0
                                ? 'Belum ada sesi ber-ruang. Susun jadwalnya terlebih dahulu.'
                                : undefined
                        }
                        onClick={() =>
                            router.post(`${jalur}/pengawas/otomatis`, {}, { preserveScroll: true })
                        }
                    >
                        Tugaskan otomatis
                    </Tombol>

                    <Tombol
                        nada="pri"
                        disabled={baris.length === 0 || calon.length === 0}
                        title={
                            calon.length === 0
                                ? 'Belum ada panitia berjabatan pengawas yang punya akun.'
                                : undefined
                        }
                        onClick={() => bukaPanel()}
                    >
                        Tugaskan
                    </Tombol>

                    {/* Tukar hanya masuk akal bila ADA yang bisa ditukar:
                        satu penugasan sendirian tidak punya pasangan. */}
                    <Tombol
                        disabled={semuaPenugasan.length < 2}
                        title={
                            semuaPenugasan.length < 2
                                ? 'Butuh paling sedikit dua penugasan untuk ditukar.'
                                : 'Tukar pengawas antar ruang'
                        }
                        onClick={() => bukaTukar()}
                    >
                        Tukar pengawas
                    </Tombol>
                </>
            }
        >
            <Head title={`Pengawas · ${event.nama}`} />

            {/* Tiga angka, dan yang ketiga yang menentukan: ruang tanpa
                pengawas adalah ruang yang siswanya tidak akan bisa masuk.
                Angka pertama diberi keterangan jumlah pengawas per ruang,
                supaya "1 atau 2" terbaca sebagai keadaan yang disengaja dan
                bukan sebagai angka yang lupa diisi. */}
            <div className="mb-3 grid gap-3 sm:grid-cols-3">
                <KPI label="Ruang" nilai={angka.ruang} />
                <KPI
                    label="Pengawas ditugaskan"
                    nilai={angka.ditugaskan}
                    nada="ok"
                    catatan={`${angka.minimal_per_ruang}–${angka.saran_per_ruang} orang per ruang`}
                />
                <KPI
                    label="Ruang tanpa pengawas"
                    nilai={angka.tanpa_pengawas}
                    nada={angka.tanpa_pengawas > 0 ? 'bad' : 'ok'}
                />
            </div>

            {panel && (
                <div className="mb-4">
                    <Kotak
                        judul="Tugaskan pengawas"
                        aksi={
                            <>
                                <Tombol nada="sek" onClick={() => setPanel(false)}>
                                    Batal
                                </Tombol>
                                <Tombol nada="pri" onClick={tugaskan}>
                                    Tugaskan
                                </Tombol>
                            </>
                        }
                    >
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Ruang & sesi" petunjuk="Barisnya ruang-sesi, bukan ruang">
                                <select
                                    value={ruangId ?? ''}
                                    onChange={(e) => setRuangId(Number(e.target.value))}
                                    className={kelasInput}
                                >
                                    {baris.map((b) => (
                                        <option key={b.id} value={b.id}>
                                            {b.ruang} · {b.sesi} · {b.ujian}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field
                                label="Pengawas"
                                petunjuk="Daftar ini diambil dari panitia berjabatan pengawas"
                            >
                                <select
                                    value={orangId ?? ''}
                                    onChange={(e) => setOrangId(Number(e.target.value))}
                                    className={kelasInput}
                                >
                                    {calon.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.nama} · {c.peran}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </div>

                        <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                            Satu ruang cukup dijaga {angka.minimal_per_ruang} orang, dan boleh
                            sampai {angka.saran_per_ruang}. Penugasan yang bentrok ditolak di sini,
                            bukan hanya dilaporkan di laman jadwal &mdash; satu pengawas tidak bisa
                            berdiri di dua ruang pada jam yang sama, dan menolaknya saat menyimpan
                            berarti Admin tidak perlu menemukannya belakangan.
                        </p>
                    </Kotak>
                </div>
            )}

            {tukar !== null && (
                <div className="mb-4">
                    <Kotak
                        judul="Tukar pengawas"
                        aksi={
                            <>
                                <Tombol nada="sek" onClick={() => setTukar(null)}>
                                    Batal
                                </Tombol>
                                <Tombol
                                    nada="pri"
                                    disabled={tukar.a === null || tukar.b === null}
                                    onClick={kirimTukar}
                                >
                                    Tukar
                                </Tombol>
                            </>
                        }
                    >
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Penugasan pertama" petunjuk="Ruang · sesi · pengawas">
                                <select
                                    value={tukar.a ?? ''}
                                    onChange={(e) =>
                                        setTukar({ ...tukar, a: e.target.value === '' ? null : Number(e.target.value) })
                                    }
                                    className={kelasInput}
                                >
                                    <option value="">— pilih —</option>
                                    {semuaPenugasan.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field
                                label="Penugasan kedua"
                                petunjuk="Ditukar dengan yang pertama"
                                galat={
                                    tukar.a !== null && tukar.b !== null && tukar.a === tukar.b
                                        ? 'Pilih dua penugasan yang berbeda.'
                                        : undefined
                                }
                            >
                                <select
                                    value={tukar.b ?? ''}
                                    onChange={(e) =>
                                        setTukar({ ...tukar, b: e.target.value === '' ? null : Number(e.target.value) })
                                    }
                                    className={kelasInput}
                                >
                                    <option value="">— pilih —</option>
                                    {semuaPenugasan
                                        // Penugasan pertama tidak ditawarkan lagi:
                                        // menukar sesuatu dengan dirinya sendiri
                                        // bukan pertukaran, dan server menolaknya.
                                        .filter((p) => p.id !== tukar.a)
                                        .map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.label}
                                            </option>
                                        ))}
                                </select>
                            </Field>
                        </div>

                        <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                            Yang bertukar adalah <strong>orangnya</strong>: kedua pengawas berdiri di
                            ruang masing-masing yang lain, dan jadwal ruangnya tidak bergerak.
                            Pertukaran ditolak bila hasilnya menaruh salah satunya di dua ruang pada
                            jam yang sama &mdash; dan penolakan itu tidak meninggalkan perubahan
                            setengah jadi.
                        </p>
                    </Kotak>
                </div>
            )}

            {calon.length === 0 && baris.length > 0 && (                <div className="mb-3">
                    <Peringatan>
                        Belum ada panitia berjabatan <strong>pengawas</strong> yang punya akun.
                        Tambahkan orangnya di laman <strong>Kepanitiaan</strong> &mdash; penugasan di
                        sini hanya bisa diberikan kepada akun yang sudah ada.
                    </Peringatan>
                </div>
            )}

            {sesi_tanpa_ruang.length > 0 && (
                <div className="mb-3">
                    <Peringatan>
                        {sesi_tanpa_ruang.length} sesi belum punya ruang:{' '}
                        {sesi_tanpa_ruang.map((s) => `${s.label} (${s.ujian ?? '—'})`).join(', ')}.
                        Penugasan menempel pada ruang-sesi, sehingga sesi tanpa ruang tidak bisa
                        ditugaskan &mdash; tetapkan ruangnya di laman{' '}
                        <strong>Jadwal &amp; sesi</strong>.
                    </Peringatan>
                </div>
            )}

            {/* --------------------------------------------------- per SESI */}
            {/* Pengawas menempel pada RUANG-SESI, bukan pada event: pengawas
                sesi pagi belum tentu pengawas sesi siang. Karena itu daftarnya
                disusun per sesi - pertanyaan yang dijawab Admin saat menyusun
                jadwal pengawasan adalah "siapa yang bertugas di sesi ini",
                bukan "siapa yang bertugas di R-01 sepanjang hari".

                Tabel datar sebelumnya membuat R-01 muncul berkali-kali lintas
                sesi, sehingga Admin harus menyusun kelompoknya sendiri dengan
                mata - dan penugasan yang salah sesi baru ketahuan di hari-H. */}
            {per_sesi.length === 0 ? (
                <Kotak>
                    <p className="text-sm text-ink-3">
                        Belum ada ruang yang dipakai sesi mana pun. Susun jadwalnya terlebih
                        dahulu di laman <strong>Jadwal &amp; sesi</strong>.
                    </p>
                </Kotak>
            ) : (
                <div className="space-y-3">
                    {per_sesi.map((s) => (
                        <KelompokSesi
                            key={s.id}
                            sesi={s}
                            jalur={jalur}
                            calon={calon}
                            bisaTukar={semuaPenugasan.length >= 2}
                            padaTugaskan={bukaPanel}
                            padaTukar={bukaTukar}
                        />
                    ))}
                </div>
            )}

            {/* Pelepasan penugasan kini menempel pada BARISNYA di dalam
                kelompok sesi - lihat `KelompokSesi`. Kotak "Batalkan
                penugasan" yang dulu berdiri sendiri mengharuskan Admin
                mencocokkan nama dengan ruang dan sesi dari daftar terpisah,
                dan pada event dua puluh sesi daftar itu lebih panjang daripada
                tabelnya sendiri. */}

            <div className="mt-3 space-y-3">
                {/* Saran, BUKAN temuan. Ruang yang berisi satu pengawas sudah
                    memenuhi syarat PRD §9.6 dan sesinya tetap bisa dibuka -
                    yang menghalangi hanya ruang tanpa pengawas sama sekali.
                    Karena itu kotaknya biru `Inbox`, bukan kuning `Peringatan`:
                    mewarnai keadaan yang sah sebagai peringatan membuat
                    peringatan yang benar-benar penting ikut diabaikan. */}
                {saran.length > 0 && (
                    <Inbox>
                        {saran
                            .slice(0, 3)
                            .map((b) => `${b.ruang} (${b.sesi}) baru ${b.pengawas.length} pengawas`)
                            .join('. ')}
                        . Satu pengawas per ruang sudah sah &mdash; sesinya tetap bisa dibuka.
                        Menambah pengawas kedua sebaiknya dilakukan pada ujian yang pengawasnya
                        cukup, karena ruang yang benar-benar tidak akan bisa dibuka siswanya
                        hanyalah ruang yang belum punya pengawas sama sekali.
                    </Inbox>
                )}

                <Inbox>
                    Ini <strong>bukan</strong> daftar panitia. Laman{' '}
                    <strong>Kepanitiaan</strong> mencatat siapa saja pengawasnya di tingkat event
                    &mdash; satu daftar nama, sekali. Laman ini mencatat siapa yang berdiri di ruang
                    mana pada sesi mana, dan penugasan itulah yang memberi hak memverifikasi
                    presensi. Seorang pengawas yang ada di kepanitiaan tetapi tidak ditugaskan di
                    sini tidak bisa membuka ruang mana pun.
                </Inbox>
            </div>
        </AdminLayout>
    );
}

/**
 * Satu SESI beserta ruang dan pengawasnya.
 *
 * Pengawas menempel pada ruang-sesi, jadi kelompok inilah jawaban atas
 * "siapa yang bertugas di sesi ini" - pertanyaan yang sebenarnya ditanyakan
 * Admin saat menyusun jadwal pengawasan. Penugasannya tetap per ruang, karena
 * satu orang menjaga satu ruang; yang dikelompokkan hanyalah tampilannya.
 */
function KelompokSesi({
    sesi,
    jalur,
    calon,
    bisaTukar,
    padaTugaskan,
    padaTukar,
}: {
    sesi: SesiKelompok;
    jalur: string;
    calon: Props['calon'];
    bisaTukar: boolean;
    padaTugaskan: (id: number) => void;
    padaTukar: (id: number) => void;
}) {
    // Dibuka bila sesi ini PUNYA masalah - ruang tanpa pengawas, atau
    // belum ada pengawas sama sekali. Sesi yang sudah lengkap dilipat,
    // supaya daftar dua puluh sesi tetap bisa dipindai.
    const [buka, setBuka] = useState(
        sesi.ruang_tanpa_pengawas.length > 0 || sesi.jumlah_pengawas === 0,
    );

    const kurang = sesi.ruang_tanpa_pengawas.length;

    return (
        <section className="overflow-hidden rounded-wf border border-line bg-surface">
            <header className="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-line px-3 py-2.5">
                <button
                    type="button"
                    onClick={() => setBuka(!buka)}
                    aria-expanded={buka}
                    className="flex items-center gap-2 text-left"
                >
                    <span className="font-mono text-xs text-ink-3">{buka ? '▾' : '▸'}</span>
                    <span className="font-mono text-sm font-medium text-ink">{sesi.label}</span>
                </button>

                <span className="text-sm text-ink">{sesi.ujian ?? '—'}</span>

                {sesi.susulan && <Chip nada="info">susulan</Chip>}

                <span className="font-mono text-[11px] text-ink-3">
                    {sesi.tanggal ?? '—'} · {sesi.jam ?? '—'}
                    {sesi.selesai ? `–${sesi.selesai}` : ''}
                </span>

                <span className="ml-auto flex items-center gap-2">
                    <span className="font-mono text-[11px] text-ink-3">
                        {sesi.jumlah_ruang} ruang · {sesi.jumlah_pengawas} pengawas
                    </span>

                    {/* Ruang tanpa pengawas adalah keadaan yang MENGHALANGI
                        sesi dibuka - bukan saran. Angkanya sama dengan yang
                        dipakai `SesiService::mulai()` untuk menolak. */}
                    {kurang > 0 ? (
                        <Chip nada="bad">{kurang} ruang tanpa pengawas</Chip>
                    ) : sesi.jumlah_pengawas === 0 ? (
                        <Chip nada="warn">belum ada pengawas</Chip>
                    ) : (
                        <Chip nada="ok">lengkap</Chip>
                    )}
                </span>
            </header>

            {buka && (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[40rem] text-sm">
                        <thead>
                            <tr className="border-b border-line bg-surface-2/50 text-left text-[11px] text-ink-3">
                                <th className="w-40 px-3 py-2">Ruang</th>
                                <th className="w-24 px-3 py-2">Peserta</th>
                                <th className="px-3 py-2">Pengawas</th>
                                <th className="w-44 px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {sesi.ruang.map((r) => (
                                <tr key={r.id} className="border-b border-line last:border-0">
                                    <td className="px-3 py-2">
                                        <span className="font-mono text-xs font-medium text-ink">
                                            {r.ruang ?? '—'}
                                        </span>
                                        <span className="ml-2 text-[11px] text-ink-3">
                                            {r.ruang_nama}
                                        </span>
                                    </td>

                                    <td className="px-3 py-2">
                                        <span className="font-mono text-xs text-ink-2">
                                            {r.peserta}/{r.kapasitas}
                                        </span>
                                    </td>

                                    {/* Pengawas sebagai DAFTAR, bukan dua
                                        kolom tetap: PRD §9.6 mengizinkan
                                        lebih dari dua, dan kolom tetap membuat
                                        orang ketiga tersimpan tanpa terlihat. */}
                                    <td className="px-3 py-2">
                                        {r.pengawas.length === 0 ? (
                                            <span className="text-xs text-bad">
                                                belum ada pengawas
                                            </span>
                                        ) : (
                                            <span className="flex flex-wrap gap-x-3 gap-y-1">
                                                {r.pengawas.map((p) => (
                                                    <span
                                                        key={p.id}
                                                        className="flex items-center gap-1.5"
                                                    >
                                                        <span className="text-sm text-ink">
                                                            {p.nama ?? p.username ?? '—'}
                                                        </span>
                                                        {p.status !== 'aktif' && (
                                                            <Chip nada="warn">{p.status}</Chip>
                                                        )}
                                                        <button
                                                            className="text-[11px] text-bad hover:underline"
                                                            title={`Lepas ${p.nama ?? 'pengawas'} dari ${r.ruang}`}
                                                            aria-label={`Lepas ${p.nama ?? 'pengawas'} dari ${r.ruang}`}
                                                            onClick={() =>
                                                                router.delete(
                                                                    `${jalur}/pengawas/${p.id}`,
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                        >
                                                            &#10005;
                                                        </button>
                                                    </span>
                                                ))}
                                            </span>
                                        )}
                                    </td>

                                    <td className="px-3 py-2">
                                        <span className="flex justify-end gap-1.5">
                                            <Tombol
                                                nada="sek"
                                                disabled={calon.length === 0}
                                                onClick={() => padaTugaskan(r.id)}
                                            >
                                                Tugaskan
                                            </Tombol>
                                            <Tombol
                                                nada="sek"
                                                disabled={r.pengawas.length === 0 || !bisaTukar}
                                                title={
                                                    r.pengawas.length === 0
                                                        ? 'Ruang ini belum punya pengawas untuk ditukar.'
                                                        : undefined
                                                }
                                                onClick={() => padaTukar(r.id)}
                                            >
                                                Tukar
                                            </Tombol>
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

function KPI({
    label,
    nilai,
    nada,
    catatan,
}: {
    label: string;
    nilai: number;
    nada?: 'ok' | 'warn' | 'bad';
    catatan?: string;
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
            {catatan && <p className="mt-0.5 text-[11px] text-ink-3">{catatan}</p>}
        </div>
    );
}
