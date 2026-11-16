import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Inbox, Kotak, Peringatan, Tombol } from '@/Components/ui';

/**
 * Pengawas & penempatan ruang - wireframe plate 17, PRD §9.6.
 *
 * ---------------------------------------------------------------------
 * Panitia berjabatan pengawas BUKAN penugasan
 * ---------------------------------------------------------------------
 * Plate 13 mencatat siapa saja pengawasnya di tingkat event - satu daftar
 * nama. Laman ini mencatat siapa yang berdiri di R-02 pada sesi 1. Plate 17
 * menyatakan mengapa pemisahan itu menentukan:
 *
 *   "penugasan inilah yang memberi Pengawas hak melihat dan memverifikasi
 *    presensi di ruang itu. Tanpa penugasan, ia tidak melihat apa pun."
 *
 * ---------------------------------------------------------------------
 * Laman ini SUDAH TIDAK menugaskan; ia meringkas dan menautkan
 * ---------------------------------------------------------------------
 * Pengawas menempel pada RUANG-SESI, dan tiap sesi punya pengawasnya sendiri.
 * Pada event dengan tiga puluh tiga ruang dan dua belas sesi, merender seluruh
 * baris di sini menghasilkan ratusan baris - dan yang tenggelam adalah daftar
 * SESINYA sendiri, padahal itulah yang dipindai Admin lebih dulu: "sesi mana
 * yang belum berpengawas".
 *
 * Karena itu laman ini menampilkan satu kartu per sesi, dan pekerjaan
 * menugaskan terjadi di laman sesinya (`/pengawas/sesi/{id}`). Tombol yang
 * seluruh aksinya ada di laman lain tidak ditampilkan di sini: tombol yang
 * mengalihkan halaman tanpa menyebutnya mengajari Admin untuk tidak
 * memercayai layarnya.
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
    ruang: {
        id: number;
        ruang: string | null;
        ruang_nama: string | null;
        kapasitas: number;
        peserta: number;
        pengawas: PengawasBaris[];
    }[];
    jumlah_ruang: number;
    jumlah_pengawas: number;
    ruang_tanpa_pengawas: string[];
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    baris: Baris[];
    per_sesi: SesiKelompok[];
    angka: {
        ruang: number;
        ditugaskan: number;
        tanpa_pengawas: number;
        saran_per_ruang: number;
        minimal_per_ruang: number;
    };
    calon: { id: number; nama: string; username: string; peran: string }[];
    sesi_tanpa_ruang: { id: number; label: string; ujian: string | null }[];
    /** Alamat laman sesi - dikirim server supaya bentuk rutenya satu tempat. */
    jalur_sesi: string;
};

export default function EventPengawas({
    event,
    baris,
    per_sesi,
    angka,
    calon,
    sesi_tanpa_ruang,
    jalur_sesi,
}: Props) {
    const jalur = `/admin/events/${event.id}`;

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

                    <TombolTautanKeLamanSesi per_sesi={per_sesi} jalur_sesi={jalur_sesi} />

                    <Tombol
                        disabled={baris.length === 0}
                        title={
                            baris.length === 0
                                ? 'Belum ada sesi ber-ruang. Susun jadwalnya terlebih dahulu.'
                                : 'Bagikan seluruh pengawas ke ruang yang belum terjaga'
                        }
                        onClick={() =>
                            router.post(`${jalur}/pengawas/otomatis`, {}, { preserveScroll: true })
                        }
                    >
                        Tugaskan otomatis
                    </Tombol>
                </>
            }
        >
            <Head title={`Pengawas · ${event.nama}`} />

            {/* Tiga angka, dan yang ketiga yang menentukan: ruang tanpa
                pengawas adalah ruang yang siswanya tidak akan bisa masuk. */}
            <div className="mb-3 grid gap-3 sm:grid-cols-3">
                <KPI label="Ruang" nilai={angka.ruang} catatan="seluruh sesi" />
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

            {calon.length === 0 && baris.length > 0 && (
                <div className="mb-3">
                    <Peringatan>
                        Belum ada panitia berjabatan <strong>pengawas</strong> yang punya akun.
                        Tambahkan orangnya di laman <strong>Kepanitiaan</strong> &mdash; penugasan
                        hanya bisa diberikan kepada akun yang sudah ada.
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
            {/* Satu kartu per sesi. Isinya RINGKASAN - daftar ruang dan
                tombol Tugaskan ada di laman sesinya, karena tiga puluh tiga
                ruang per sesi yang dirender di sini menenggelamkan daftar
                sesinya sendiri. */}
            {per_sesi.length === 0 ? (
                <Kotak>
                    <p className="text-sm text-ink-3">
                        Belum ada ruang yang dipakai sesi mana pun. Susun jadwalnya terlebih
                        dahulu di laman <strong>Jadwal &amp; sesi</strong>.
                    </p>
                </Kotak>
            ) : (
                <div className="space-y-2">
                    {per_sesi.map((s) => (
                        <KartuSesi key={s.id} sesi={s} jalur_sesi={jalur_sesi} />
                    ))}
                </div>
            )}

            {/* Saran, BUKAN temuan. Ruang yang berisi satu pengawas sudah
                memenuhi syarat PRD §9.6 dan sesinya tetap bisa dibuka - yang
                menghalangi hanya ruang tanpa pengawas sama sekali. */}
            {per_sesi.some((s) => s.jumlah_pengawas > 0) && (
                <div className="mt-3">
                    <Inbox>
                        Satu pengawas per ruang sudah sah &mdash; sesinya tetap bisa dibuka.
                        Menambah pengawas kedua sebaiknya dilakukan pada sesi yang pengawasnya
                        cukup, karena ruang yang benar-benar tidak akan bisa dibuka siswanya
                        hanyalah ruang yang belum punya pengawas sama sekali.
                    </Inbox>
                </div>
            )}
        </AdminLayout>
    );
}

/* ---------------------------------------------------------- Kartu sesi */

function KartuSesi({ sesi, jalur_sesi }: { sesi: SesiKelompok; jalur_sesi: string }) {
    const kurang = sesi.ruang_tanpa_pengawas.length;
    const href = `${jalur_sesi}/${sesi.id}`;

    return (
        <Link
            href={href}
            className="block rounded-wf border border-line bg-surface px-3 py-2.5 transition-colors hover:border-ink-3/40 hover:bg-surface-2/40"
        >
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <span className="font-mono text-sm font-medium text-ink">{sesi.label}</span>
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

                    {/* Ruang tanpa pengawas MENGHALANGI sesi dibuka - angka yang
                        sama dipakai `SesiService::mulai()` untuk menolak. */}
                    {kurang > 0 ? (
                        <Chip nada="bad">{kurang} ruang tanpa pengawas</Chip>
                    ) : sesi.jumlah_pengawas === 0 ? (
                        <Chip nada="warn">belum ada pengawas</Chip>
                    ) : (
                        <Chip nada="ok">lengkap</Chip>
                    )}

                    <span className="text-[11px] font-medium text-accent">Atur →</span>
                </span>
            </div>

            {/* Ruang yang belum terjaga disebut NAMANYA, bukan hanya
                dihitung: Admin yang membuka laman sesi perlu tahu ruang mana
                yang harus dicari, dan "3 ruang" tidak menjawab itu. */}
            {kurang > 0 && (
                <p className="mt-1 text-[11px] text-bad">
                    Belum berpengawas: {sesi.ruang_tanpa_pengawas.join(', ')}
                </p>
            )}
        </Link>
    );
}

/**
 * Tautan "Atur pengawas" di toolbar - hanya bila ada tepat satu sesi.
 *
 * Dengan dua sesi atau lebih, tautan tunggal ke salah satunya adalah tebakan
 * yang mengarahkan Admin ke sesi yang bukan maksudnya. Keadaan itu tidak
 * ditebak: Admin memilih sesinya dari daftar di bawah.
 */
function TombolTautanKeLamanSesi({
    per_sesi,
    jalur_sesi,
}: {
    per_sesi: SesiKelompok[];
    jalur_sesi: string;
}) {
    if (per_sesi.length !== 1) {
        return null;
    }

    return (
        <Link
            href={`${jalur_sesi}/${per_sesi[0].id}`}
            className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
        >
            Atur pengawas {per_sesi[0].label}
        </Link>
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
