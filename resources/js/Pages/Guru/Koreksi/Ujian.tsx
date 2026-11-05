import { Head, Link } from '@inertiajs/react';
import GuruLayout from '@/Layouts/GuruLayout';
import { Chip, Inbox, Paginator, Tabel } from '@/Components/ui';
import { formatPoin } from '@/Components/angka';

/**
 * Antrean satu ujian - wireframe-peran.html plate 06, tanpa panel rubrik.
 *
 * ---------------------------------------------------------------------
 * Kolomnya "belum dinilai lebih dulu", dan itu bukan selera
 * ---------------------------------------------------------------------
 * Urutan antrean tetap `student_answer.id` supaya Guru dan tombol "berikutnya"
 * berjalan di jalur yang sama, tetapi baris yang SUDAH dinilai tidak
 * disembunyikan: Guru yang ingin memeriksa koreksinya sendiri membukanya dari
 * sini, dan daftar yang hanya berisi sisa pekerjaan membuat koreksi yang sudah
 * tersimpan tidak bisa ditinjau lagi.
 *
 * Yang membedakannya adalah chip di kolom kanan - dan Guru yang mengoreksi
 * dari atas ke bawah akan melewati yang berchip "sudah" tanpa membukanya.
 */

type Baris = {
    id: number;
    nomor: number;
    siswa: string | null;
    judul: string | null;
    cuplikan: string;
    sudah_dinilai: boolean;
    skor: number | null;
    bobot: number;
};

type Props = {
    ujian: {
        id: number;
        judul: string;
        kode: string | null;
        mapel: string | null;
        event: string | null;
    };
    ringkas: {
        total: number;
        sudah: number;
        sisa: number;
        poin_diberikan: number;
        rata_rata: number | null;
        maksimal: number;
    };
    baris: Paginator<Baris>;
};

export default function KoreksiUjian({ ujian, ringkas, baris }: Props) {
    // Baris pertama yang belum dinilai - tombol "Mulai koreksi" harus membuka
    // SATU jawaban, bukan daftar yang sama dengan yang sedang dilihat.
    const pertama = baris.data.find((b) => !b.sudah_dinilai) ?? baris.data[0];

    return (
        <GuruLayout
            judul="Koreksi uraian"
            breadcrumb={`Koreksi · ${ujian.mapel ?? ujian.judul}`}
            aksi={
                pertama && (
                    <Link
                        href={`/guru/koreksi/jawaban/${pertama.id}`}
                        className="rounded-wf border border-accent bg-accent px-3 py-1.5 text-xs font-medium text-on-accent hover:bg-accent/90"
                    >
                        {ringkas.sisa > 0 ? 'Mulai koreksi' : 'Tinjau koreksi'}
                    </Link>
                )
            }
        >
            <Head title={`Koreksi · ${ujian.judul}`} />

            <div className="mb-3">
                <p className="text-xs text-ink-3">
                    {[ujian.mapel, ujian.event, ujian.kode].filter(Boolean).join(' · ')}
                </p>
            </div>

            {/* Lima KPI plate 06: Sudah, Sisa, Poin, Rata-rata, Antrean.
                "Antrean" di wireframe adalah sisa pada halaman ini, jadi
                lima kotaknya tetap lima - dengan arti yang sama. */}
            <div className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <Kpi label="Sudah" nilai={String(ringkas.sudah)} />
                <Kpi
                    label="Sisa"
                    nilai={String(ringkas.sisa)}
                    nada={ringkas.sisa > 0 ? 'warn' : 'ok'}
                />
                <Kpi label="Poin diberikan" nilai={formatPoin(ringkas.poin_diberikan)} />
                <Kpi
                    label="Rata-rata"
                    nilai={
                        ringkas.rata_rata === null
                            ? '—'
                            : `${formatPoin(ringkas.rata_rata)} / ${formatPoin(ringkas.maksimal)}`
                    }
                />
            </div>

            {ringkas.total === 0 && (
                <div className="mb-3">
                    <Inbox>
                        Ujian ini tidak punya butir uraian. Yang dinilai otomatis tidak
                        pernah masuk antrean ini.
                    </Inbox>
                </div>
            )}

            <Tabel<Baris>
                hal={baris}
                kunci={(b) => b.id}
                kosong="Tidak ada jawaban uraian pada ujian ini."
                kolom={[
                    {
                        judul: 'No',
                        kelas: 'w-14',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">{b.nomor}</span>
                        ),
                    },
                    {
                        judul: 'Siswa & jawaban',
                        render: (b) => (
                            <div className="min-w-0">
                                <Link
                                    href={`/guru/koreksi/jawaban/${b.id}`}
                                    className="block truncate text-sm font-medium text-ink hover:text-accent"
                                >
                                    {b.siswa ?? 'Siswa tidak dikenal'}
                                </Link>
                                <p className="mt-0.5 line-clamp-1 text-[11px] text-ink-3">
                                    {b.judul}
                                </p>
                                {/* Cuplikan jawaban siswa, supaya Guru bisa
                                    memutuskan mana yang perlu dibuka lebih
                                    dulu - jawaban kosong tidak perlu satu
                                    klik penuh. */}
                                {b.cuplikan && (
                                    <p className="mt-1 line-clamp-1 text-[11px] text-ink-2 italic">
                                        &ldquo;{b.cuplikan}&rdquo;
                                    </p>
                                )}
                            </div>
                        ),
                    },
                    {
                        judul: 'Poin',
                        kelas: 'w-20 text-right',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">
                                {b.sudah_dinilai
                                    ? `${formatPoin(b.skor)} / ${formatPoin(b.bobot)}`
                                    : `— / ${formatPoin(b.bobot)}`}
                            </span>
                        ),
                    },
                    {
                        judul: 'Status',
                        kelas: 'w-24',
                        render: (b) => (
                            <Chip nada={b.sudah_dinilai ? 'ok' : 'warn'}>
                                {b.sudah_dinilai ? 'sudah' : 'belum'}
                            </Chip>
                        ),
                    },
                    {
                        judul: '',
                        kelas: 'w-24 text-right',
                        render: (b) => (
                            <Link
                                href={`/guru/koreksi/jawaban/${b.id}`}
                                className="text-xs font-medium text-accent hover:underline"
                            >
                                {b.sudah_dinilai ? 'Tinjau' : 'Koreksi'}
                            </Link>
                        ),
                    },
                ]}
            />
        </GuruLayout>
    );
}

/* ------------------------------------------------------------------ KPI */

function Kpi({ label, nilai, nada }: { label: string; nilai: string; nada?: 'ok' | 'warn' }) {
    const warna = nada === 'ok' ? 'text-ok' : nada === 'warn' ? 'text-warn' : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-3 py-2">
            <span className="block text-[11px] text-ink-3">{label}</span>
            <span className={'mt-0.5 block font-mono text-lg font-semibold ' + warna}>{nilai}</span>
        </div>
    );
}
