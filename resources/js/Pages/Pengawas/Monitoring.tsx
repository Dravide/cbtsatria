import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import KerangkaPonsel from '@/Layouts/KerangkaPonsel';
import { Chip, Inbox, Peringatan } from '@/Components/ui';
import { KpiPonsel, KotakPonsel } from '@/Components/ponsel';

/**
 * Plate 15 - monitoring ruang. `/pengawas/ruang/{id}/monitoring`.
 *
 * =====================================================================
 * Fase 6 MEMBACA; yang menulis exam_attempts adalah Fase 7
 * =====================================================================
 * Laman ini dibangun terhadap tabel yang belum punya penulis, dan itu
 * keputusan yang dikunci di awal fase: bentuk yang benar untuk "peserta
 * yang sedang mengerjakan" sudah bisa ditetapkan sekarang, dan
 * menetapkannya sekarang berarti Fase 7 tinggal mengisi kolomnya - bukan
 * mengubah tata letak papan yang sudah dipakai orang di hari-H.
 *
 * Satu hal yang karena itu harus jujur: papan ini menampilkan "belum mulai"
 * untuk semua orang sampai mesin ujian ada. Angka yang dikarang akan jauh
 * lebih buruk daripada papan yang mengaku kosong.
 *
 * ---------------------------------------------------------------------
 * Pengawas hanya melihat siswa di ruangnya
 * ---------------------------------------------------------------------
 * "Nama siswa di ruang lain tidak muncul sama sekali, bukan disamarkan."
 * Daftarnya dibangun dari penempatan di ruang INI - bukan dari daftar
 * attempt seluruh sekolah yang lalu disaring, karena daftar yang disaring
 * adalah daftar yang suatu hari lupa disaring.
 *
 * ---------------------------------------------------------------------
 * Progres dikirim sebagai `null`, bukan 0
 * ---------------------------------------------------------------------
 * "Belum mulai" dan "0 dari 40" adalah dua keadaan yang berbeda, dan yang
 * membedakannya hanya satu hal: apakah ada attempt. Menampilkan 0/40 untuk
 * anak yang belum membuka ujiannya akan membuat pengawas mencarinya.
 */
type Kursi = {
    peserta_id: number;
    kursi: number;
    nama: string;
    nis: string | null;
    kelas: string | null;
    status: string | null;
    status_label: string;
    status_nada: string;
    terhubung: boolean | null;
    progres: string | null;
    pelanggaran: number;
    terberat: number;
    jenis_pelanggaran: string[];
    terkunci: boolean;
    kunci_alasan: string | null;
};

type Insiden = {
    id: number;
    jenis: string;
    keterangan: string;
    peserta: string | null;
    pelaku: string;
    jam: string | null;
    tanggal: string | null;
    status: string;
    terbuka: boolean;
};

type Props = {
    ruang: {
        id: number;
        kode: string;
        nama: string | null;
        sesi: string | null;
        ujian: string | null;
        berakhir_pada: string | null;
    };
    pelaku: string;
    angka: {
        peserta: number;
        mengerjakan: number;
        selesai: number;
        terputus: number;
        pelanggaran: number;
        insiden: number;
    };
    kursi: Kursi[];
    insiden: Insiden[];
    jenis_insiden: Record<string, string>;
};

export default function Monitoring({
    ruang,
    angka,
    kursi,
    insiden,
    jenis_insiden,
}: Props) {
    const [bukaInsiden, setBukaInsiden] = useState(false);
    const [jenis, setJenis] = useState('lain');
    const [keterangan, setKeterangan] = useState('');
    const [pesertaId, setPesertaId] = useState<string>('');
    const [kirim, setKirim] = useState(false);

    const terkunci = kursi.filter((k) => k.terkunci);

    const simpanInsiden = (e: React.FormEvent) => {
        e.preventDefault();

        if (keterangan.trim().length < 4) {
            return;
        }

        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/insiden`,
            {
                jenis,
                keterangan,
                peserta_id: pesertaId === '' ? null : Number(pesertaId),
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setKirim(false);
                    setKeterangan('');
                    setPesertaId('');
                    setBukaInsiden(false);
                },
            },
        );
    };

    return (
        <KerangkaPonsel
            judul="Monitoring ruang"
            gelap
            tabbar={{ ruangId: ruang.id, aktif: 'monitoring' }}
            kiri={
                <>
                    <Chip nada="info">{ruang.kode}</Chip>
                    <span className="min-w-0 truncate text-[11px] text-ink-3">
                        {[ruang.ujian, ruang.sesi].filter(Boolean).join(' · ')}
                    </span>
                </>
            }
        >
            <div className="grid grid-cols-2 gap-1">
                <KpiPonsel label="Mengerjakan" nilai={angka.mengerjakan} nada="info" />
                <KpiPonsel label="Selesai" nilai={angka.selesai} nada="ok" />
                <KpiPonsel
                    label="Terputus"
                    nilai={angka.terputus}
                    nada={angka.terputus > 0 ? 'bad' : undefined}
                />
                <KpiPonsel
                    label="Pelanggaran"
                    nilai={angka.pelanggaran}
                    nada={angka.pelanggaran > 0 ? 'warn' : undefined}
                />
            </div>

            <KotakPonsel padat>
                {/* Kepala daftar menyebut kolom yang BENAR-BENAR ada di
                    barisnya. Sebelumnya ia punya kolom "Flag" selebar
                    `shrink-0` yang tidak berpasangan dengan apa pun -
                    penanda (terputus, pelanggaran, dikunci) dirender sebagai
                    chip di bawah nama, bukan sebagai kolom. Ruang `w-6` di
                    kiri memakai lebar kolom nomor kursi supaya kepalanya
                    sejajar dengan barisnya, bukan menggeser satu kolom. */}
                <div className="flex items-center gap-2.5 border-b border-line/70 px-3 py-1.5 text-[11px] text-ink-3">
                    <span className="w-6 shrink-0">No</span>
                    <span className="min-w-0 flex-1">Nama</span>
                    <span className="w-12 shrink-0 text-right">Progres</span>
                </div>

                {kursi.length === 0 && (
                    <p className="px-1 py-6 text-center text-xs text-ink-3">
                        Belum ada peserta yang ditempatkan di ruang ini.
                    </p>
                )}

                {kursi.map((k) => (
                    <div
                        key={k.peserta_id}
                        className={
                            // `relative` + tautan yang melapisi SELURUH baris:
                            // nama siswa adalah satu-satunya jalan ke lamannya,
                            // dan nama selebar ~20px di ponsel adalah sasaran
                            // yang harus dibidik. Barisnya sendiri sudah 52px,
                            // jadi yang diperluas adalah area tekannya, bukan
                            // tata letaknya - kolom progres tetap di atasnya
                            // lewat `relative z-10`.
                            'relative flex items-start gap-2.5 border-b border-line/60 px-3 py-2 last:border-0 ' +
                            (k.terkunci ? 'bg-bad-bg/40' : '')
                        }
                    >
                        <span className="w-6 shrink-0 pt-0.5 font-mono text-[11px] text-ink-3">
                            {String(k.kursi).padStart(2, '0')}
                        </span>

                        <div className="min-w-0 flex-1">
                            <Link
                                href={`/pengawas/ruang/${ruang.id}/siswa/${k.peserta_id}`}
                                className="block truncate text-sm font-medium text-ink after:absolute after:inset-0 after:content-[''] hover:text-accent active:text-accent"
                            >
                                {k.nama}
                            </Link>
                            <div className="mt-1 flex flex-wrap items-center gap-1">
                                <Chip nada={k.status_nada as 'mute'}>{k.status_label}</Chip>
                                {k.terhubung === false && <Chip nada="bad">terputus</Chip>}
                                {k.pelanggaran > 0 && (
                                    <Chip nada={k.terberat >= 3 ? 'bad' : 'warn'}>
                                        {k.jenis_pelanggaran[0] ?? `${k.pelanggaran} pelanggaran`}
                                    </Chip>
                                )}
                                {k.terkunci && <Chip nada="bad">dikunci</Chip>}
                            </div>
                        </div>

                        <span className="w-12 shrink-0 pt-0.5 text-right font-mono text-[11px] text-ink-2">
                            {k.progres ?? '—'}
                        </span>
                    </div>
                ))}
            </KotakPonsel>

            {terkunci.length > 0 && (
                <Peringatan>
                    {terkunci.map((k) => k.nama).join(', ')} terkunci setelah{' '}
                    {terkunci[0].pelanggaran} pelanggaran. Buka kunci hanya setelah
                    berbicara dengan yang bersangkutan.
                </Peringatan>
            )}

            <KotakPonsel
                padat
                judul="Insiden ruang"
                kanan={
                    <Chip nada={angka.insiden > 0 ? 'warn' : 'mute'}>
                        {angka.insiden} terbuka
                    </Chip>
                }
            >
                {insiden.length === 0 && (
                    <p className="px-1 py-4 text-center text-xs text-ink-3">
                        Belum ada insiden dicatat di ruang ini.
                    </p>
                )}

                {insiden.slice(0, 20).map((i) => (
                    <div
                        key={i.id}
                        className="border-b border-line/60 py-2 last:border-0"
                    >
                        <div className="flex items-center gap-2">
                            <span className="font-mono text-[11px] text-ink-3">{i.jam}</span>
                            <span className="text-xs font-medium text-ink">{i.jenis}</span>
                            <span className="ml-auto">
                                <Chip nada={i.terbuka ? 'warn' : 'ok'}>
                                    {i.terbuka ? 'terbuka' : 'ditangani'}
                                </Chip>
                            </span>
                        </div>
                        <p className="mt-0.5 text-[11px] text-ink-2">{i.keterangan}</p>
                        <p className="text-[11px] text-ink-3">
                            {[i.peserta, i.pelaku, i.tanggal].filter(Boolean).join(' · ')}
                        </p>
                    </div>
                ))}

                {bukaInsiden ? (
                    <form onSubmit={simpanInsiden} className="space-y-1.5 pt-1">
                        <select
                            value={jenis}
                            onChange={(e) => setJenis(e.target.value)}
                            className="min-h-11 w-full rounded-wf border border-line bg-surface px-2 text-xs text-ink"
                        >
                            {Object.entries(jenis_insiden).map(([nilai, label]) => (
                                <option key={nilai} value={nilai}>
                                    {label}
                                </option>
                            ))}
                        </select>

                        <select
                            value={pesertaId}
                            onChange={(e) => setPesertaId(e.target.value)}
                            className="min-h-11 w-full rounded-wf border border-line bg-surface px-2 text-xs text-ink"
                        >
                            <option value="">Seluruh ruang (bukan satu peserta)</option>
                            {kursi.map((k) => (
                                <option key={k.peserta_id} value={k.peserta_id}>
                                    {k.nama}
                                </option>
                            ))}
                        </select>

                        <textarea
                            value={keterangan}
                            onChange={(e) => setKeterangan(e.target.value)}
                            rows={3}
                            placeholder="Apa yang terjadi?"
                            className="w-full rounded-wf border border-line bg-surface px-2 py-1.5 text-xs text-ink outline-none focus:border-accent"
                        />

                        <div className="flex flex-wrap gap-1.5">
                            <button
                                type="submit"
                                disabled={kirim || keterangan.trim().length < 4}
                                className="inline-grid min-h-11 place-items-center rounded-wf border border-accent bg-accent px-3 text-xs font-medium text-on-accent disabled:opacity-50"
                            >
                                Catat insiden
                            </button>
                            <button
                                type="button"
                                onClick={() => setBukaInsiden(false)}
                                className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface px-3 text-xs text-ink-2"
                            >
                                Batal
                            </button>
                        </div>
                    </form>
                ) : (
                    <button
                        type="button"
                        onClick={() => setBukaInsiden(true)}
                        className="inline-grid min-h-11 w-full place-items-center rounded-wf border border-line bg-surface px-3 text-xs text-ink-2"
                    >
                        Catat insiden
                    </button>
                )}
            </KotakPonsel>

            <Inbox>
                Pengawas hanya melihat siswa di ruangnya. Nama siswa di ruang lain tidak
                muncul sama sekali, bukan disamarkan.
            </Inbox>
        </KerangkaPonsel>
    );
}
