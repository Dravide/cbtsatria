import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Kotak, Peringatan, PilihCari, Tombol } from '@/Components/ui';

/**
 * Pengawas satu SESI - daftar ruang + penugasan.
 *
 * ---------------------------------------------------------------------
 * Mengapa laman tersendiri, bukan bagian dari laman pengawas
 * ---------------------------------------------------------------------
 * Pengawas menempel pada RUANG-SESI, dan tiap sesi punya pengawasnya sendiri.
 * Pada event dengan tiga puluh tiga ruang dan dua belas sesi, merender
 * semuanya di satu laman menghasilkan empat ratus baris - dan yang tenggelam
 * adalah daftar SESINYA sendiri, padahal itulah yang dipindai Admin lebih
 * dulu.
 *
 * Laman induk karena itu hanya menampilkan ringkasan per sesi; pekerjaan
 * menugaskan terjadi di sini, satu sesi pada satu waktu. Penugasan tetap
 * menempel pada ruang-sesi - yang berubah hanya di mana tombolnya berada.
 */

type PengawasBaris = {
    id: number;
    user_id: number;
    nama: string | null;
    username: string | null;
    status: string;
};

type RuangBaris = {
    id: number;
    ruang: string | null;
    ruang_nama: string | null;
    khusus_perangkat?: boolean;
    kapasitas: number;
    peserta: number;
    pengawas: PengawasBaris[];
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    sesi: {
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
    };
    ruang: RuangBaris[];
    angka: {
        ruang: number;
        tanpa_pengawas: number;
        saran_per_ruang: number;
        minimal_per_ruang: number;
    };
    calon: {
        id: number;
        nama: string;
        username: string;
        peran: string;
        terpakai?: number;
        maks?: number;
        dikecualikan?: boolean;
        alasan_pengecualian?: string | null;
    }[];
    calon_panitia?: {
        id: number;
        nama: string;
        username: string;
        peran: string;
        terpakai?: number;
        maks?: number;
        dikecualikan?: boolean;
        alasan_pengecualian?: string | null;
    }[];
    jalur_induk: string;
};

export default function PengawasSesi({
    event,
    sesi,
    ruang,
    angka,
    calon,
    calon_panitia,
    jalur_induk,
}: Props) {
    const daftarPanitia = calon_panitia ?? [];
    const jalur = `/admin/events/${event.id}`;

    // Ruang yang panel "Tugaskan"-nya terbuka. Satu pada satu waktu: panel
    // yang bisa terbuka di tiga puluh tiga baris sekaligus mengembalikan
    // masalah gulir yang justru ingin dihilangkan laman ini.
    const [ruangId, setRuangId] = useState<number | null>(null);
    const [orangId, setOrangId] = useState<number | null>(null);
    const [galat, setGalat] = useState<string | null>(null);

    const buka = (id: number) => {
        setRuangId(ruangId === id ? null : id);
        setOrangId(null);
        setGalat(null);
    };

    const tugaskan = (idRuang: number) => {
        if (orangId === null) {
            return;
        }

        router.post(
            `${jalur}/pengawas/ruang/${idRuang}`,
            { supervisor_id: orangId },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRuangId(null);
                    setOrangId(null);
                    setGalat(null);
                },
                onError: (e: Record<string, string>) =>
                    setGalat(Object.values(e)[0] ?? 'Pengawas tidak bisa ditugaskan.'),
            },
        );
    };

    const lepas = (tugasId: number) => {
        router.delete(`${jalur}/pengawas/${tugasId}`, { preserveScroll: true });
    };

    const otomatisSesi = () => {
        router.post(`${jalur}/pengawas/sesi/${sesi.id}/otomatis`, {}, { preserveScroll: true });
    };

    const kosongkanSesi = () => {
        router.post(`${jalur}/pengawas/sesi/${sesi.id}/kosongkan`, {}, { preserveScroll: true });
    };

    return (
        <AdminLayout
            judul={`Pengawas ${sesi.label}`}
            breadcrumb={`/admin/events · ${event.kode} · Pengawas · ${sesi.label}`}
            aksi={
                <div className="flex flex-wrap items-center gap-2">
                    <Tombol nada="pri" disabled={calon.length === 0 || ruang.length === 0} onClick={otomatisSesi}>
                        Tugaskan otomatis sesi ini
                    </Tombol>
                    <Tombol nada="sek" disabled={ruang.length === 0} onClick={kosongkanSesi}>
                        Kosongkan sesi ini
                    </Tombol>
                    <Link
                        href={jalur_induk}
                        className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
                    >
                        Kembali ke daftar sesi
                    </Link>
                </div>
            }
        >
            <Head title={`Pengawas ${sesi.label} · ${event.nama}`} />

            {/* Kepala sesi: pertanyaan yang dijawab laman ini adalah "siapa
                yang bertugas di sesi ini", jadi identitas sesinya ditulis
                lengkap di atas. */}
            <Kotak>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <span className="font-mono text-sm font-medium text-ink">{sesi.label}</span>
                    <span className="text-sm text-ink">{sesi.ujian ?? '—'}</span>
                    {sesi.susulan && <Chip nada="info">susulan</Chip>}
                    <span className="font-mono text-[11px] text-ink-3">
                        {sesi.tanggal ?? '—'} · {sesi.jam ?? '—'}
                        {sesi.selesai ? `–${sesi.selesai}` : ''}
                    </span>
                    <Chip nada="mute">{sesi.status_label}</Chip>

                    <span className="ml-auto flex items-center gap-2">
                        <span className="font-mono text-[11px] text-ink-3">
                            {angka.ruang} ruang
                        </span>
                        {angka.tanpa_pengawas > 0 ? (
                            <Chip nada="bad">{angka.tanpa_pengawas} ruang tanpa pengawas</Chip>
                        ) : (
                            <Chip nada="ok">lengkap</Chip>
                        )}
                    </span>
                </div>
            </Kotak>

            {calon.length === 0 && ruang.length > 0 && (
                <div className="mt-3">
                    <Peringatan>
                        Belum ada panitia berjabatan <strong>pengawas</strong> yang punya akun.
                        Tambahkan orangnya di laman <strong>Kepanitiaan</strong>. Penugasan
                        di sini hanya bisa diberikan kepada akun yang sudah ada.
                    </Peringatan>
                </div>
            )}

            {ruang.some((r) => r.khusus_perangkat) && daftarPanitia.length === 0 && (
                <div className="mt-3">
                    <Peringatan>
                        Belum ada anggota <strong>Panitia Event</strong> (jabatan selain pengawas,
                        seperti Ketua/Teknisi/Proktor/Anggota) untuk mengawasi ruang{' '}
                        <strong>Khusus Perangkat</strong>. Tambahkan di laman{' '}
                        <Link
                            href={`${jalur}/panitia`}
                            className="font-medium underline underline-offset-2"
                        >
                            Kepanitiaan
                        </Link>
                        .
                    </Peringatan>
                </div>
            )}

            {ruang.length === 0 ? (
                <div className="mt-3">
                    <Kotak>
                        <p className="text-sm text-ink-3">
                            Sesi ini belum punya ruang. Tetapkan ruangnya di laman{' '}
                            <strong>Jadwal &amp; sesi</strong>, penugasan menempel pada
                            ruang-sesi, sehingga sesi tanpa ruang tidak bisa ditugaskan.
                        </p>
                    </Kotak>
                </div>
            ) : (
                <div className="mt-3 space-y-2">
                    {ruang.map((r) => {
                        const terbuka = ruangId === r.id;

                        return (
                            <section
                                key={r.id}
                                className="rounded-wf border border-line bg-surface"
                            >
                                <header className="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-3 py-2.5">
                                    <span className="font-mono text-xs font-medium text-ink">
                                        {r.ruang ?? '—'}
                                    </span>
                                    {r.khusus_perangkat && (
                                        <Chip nada="info">Khusus Perangkat · Panitia</Chip>
                                    )}
                                    <span className="text-[11px] text-ink-3">{r.ruang_nama}</span>

                                    <span className="font-mono text-[11px] text-ink-3">
                                        {r.peserta}/{r.kapasitas} peserta
                                    </span>

                                    {/* Pengawas sebagai DAFTAR, bukan dua kolom
                                        tetap: PRD §9.6 mengizinkan lebih dari
                                        dua, dan kolom tetap membuat orang
                                        ketiga tersimpan tanpa terlihat. */}
                                    <span className="flex min-w-0 flex-1 flex-wrap gap-x-3 gap-y-1">
                                        {r.pengawas.length === 0 ? (
                                            <span className="text-xs text-bad">
                                                belum ada pengawas
                                            </span>
                                        ) : (
                                            r.pengawas.map((p) => (
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
                                                        type="button"
                                                        className="text-[11px] text-bad hover:underline"
                                                        title={`Lepas ${p.nama ?? 'pengawas'} dari ${r.ruang}`}
                                                        aria-label={`Lepas ${p.nama ?? 'pengawas'} dari ${r.ruang}`}
                                                        onClick={() => lepas(p.id)}
                                                    >
                                                        &#10005;
                                                    </button>
                                                </span>
                                            ))
                                        )}
                                    </span>

                                    <Tombol
                                        nada={terbuka ? 'sek' : 'pri'}
                                        disabled={(r.khusus_perangkat ? daftarPanitia.length : calon.length) === 0}
                                        onClick={() => buka(r.id)}
                                    >
                                        {terbuka ? 'Batal' : 'Tugaskan'}
                                    </Tombol>
                                </header>

                                {terbuka && (
                                    <div className="rounded-b-wf border-t border-line bg-surface-2/30 px-3 py-3">
                                        {r.khusus_perangkat && (
                                            <p className="mb-2 text-[11px] text-info">
                                                Ruang Khusus Perangkat: dilewati dari rotasi pengawas
                                                reguler dan langsung ditangani oleh Panitia Event.
                                            </p>
                                        )}
                                        <div className="flex flex-wrap items-end gap-2">
                                            <div className="w-72">
                                                <PilihCari
                                                    label={r.khusus_perangkat ? 'Panitia Event' : 'Pengawas'}
                                                    nilai={orangId}
                                                    pilihan={(r.khusus_perangkat ? daftarPanitia : calon).map((c) => {
                                                        const kuota =
                                                            c.maks && c.maks > 0
                                                                ? `${c.terpakai ?? 0}/${c.maks}`
                                                                : `${c.terpakai ?? 0}×`;
                                                        const status = c.dikecualikan
                                                            ? `dikosongkan di sesi ini${c.alasan_pengecualian ? ` (${c.alasan_pengecualian})` : ''}`
                                                            : c.maks && c.maks > 0 && (c.terpakai ?? 0) >= c.maks
                                                              ? 'kuota penuh'
                                                              : 'tersedia';
                                                        return {
                                                            id: c.id,
                                                            label: c.nama ?? c.username ?? '—',
                                                            ket: `${c.peran} · ${kuota} · ${status}`,
                                                        };
                                                    })}
                                                    padaPilih={(id) => setOrangId(id)}
                                                    kosong={
                                                        r.khusus_perangkat
                                                            ? 'Belum ada panitia event (non-pengawas) yang memiliki akun.'
                                                            : 'Tidak ada pengawas yang cocok.'
                                                    }
                                                />
                                            </div>

                                            <Tombol
                                                nada="pri"
                                                disabled={orangId === null}
                                                onClick={() => tugaskan(r.id)}
                                            >
                                                Tugaskan ke {r.ruang}
                                            </Tombol>
                                        </div>

                                        {galat && (
                                            <p className="mt-2 text-[11px] text-bad">{galat}</p>
                                        )}

                                        <p className="mt-2 text-[11px] leading-relaxed text-ink-3">
                                            Satu ruang dijaga {angka.minimal_per_ruang} pengawas.
                                            Menambah orang kedua tetap bisa, tetapi tidak
                                            dilakukan otomatis. Penugasan yang bentrok ditolak, karena
                                            satu orang tidak bisa berdiri di dua ruang pada
                                            jam yang sama.
                                        </p>
                                    </div>
                                )}
                            </section>
                        );
                    })}
                </div>
            )}
        </AdminLayout>
    );
}
