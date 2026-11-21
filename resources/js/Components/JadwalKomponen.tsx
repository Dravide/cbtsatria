export type Baris = {
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
    jenis: string;
    susulan: boolean;
    dibatalkan_pada: string | null;
    alasan_batal: string | null;
    buka_iso: string | null;
    tutup_iso: string | null;
    akhir_iso: string | null;
    ruang_id: number[];
    bentrok: { jenis: string; pesan: string }[];
};

export type Ujian = {
    id: number;
    kode: string;
    judul: string;
    mapel: string | null;
    mapel_id: number;
    jenis: string | null;
    durasi: number;
    minimal: number | null;
    instruksi: string | null;
    percobaan: number;
    acak_soal: boolean;
    acak_opsi: boolean;
    boleh_kembali: boolean;
    boleh_terlambat: boolean;
    kelas_id: number[];
    tingkat: string[];
    ada_paket: boolean;
    sesi: number;
};

export type JadwalEvent = {
    id: number;
    kode: string;
    nama: string;
    jenis: string | null;
    status: string;
    status_label: string;
};

export type RuangEvent = {
    id: number;
    kode: string | null;
    nama: string | null;
    kapasitas: number;
    status: string;
};

export type KelasEvent = {
    id: number;
    nama: string;
    tingkat: string | null;
};

export type MapelEvent = {
    id: number;
    kode: string | null;
    nama: string | null;
    tingkat: string | null;
};

export type TemuanJadwal = {
    jenis: string;
    pesan: string;
    sesi_id: number | null;
};

export type ValidasiItem = {
    kunci: string;
    label: string;
    lulus: boolean;
    jumlah: number;
};

export type KandidatSusulan = {
    peserta_id: number;
    nis: string | null;
    nama: string;
    kelas: string | null;
    status: string;
    status_label: string;
};

export function ValidasiStrip({
    validasi,
    adaTemuan,
}: {
    validasi: ValidasiItem[];
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

export function PemilihEvent({ event }: { event: JadwalEvent }) {
    return (
        <span className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink-2">
            <span className="text-ink-3">Event:</span>
            <span className="font-medium text-ink">{event.nama}</span>
        </span>
    );
}

export function KPI({
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
