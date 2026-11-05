import { Head, router } from '@inertiajs/react';
import { useMemo } from 'react';
import GuruLayout from '@/Layouts/GuruLayout';
import { BilahFilter, Chip, Inbox, Paginator, Tabel, Tombol, kelasInput, pakaiFilter } from '@/Components/ui';
import { formatPoin } from '@/Components/angka';

/**
 * Nilai satu ujian - wireframe-peran.html plate 07.
 *
 * ---------------------------------------------------------------------
 * Objektif dan uraian adalah DUA kolom, dan itu bagian dari spesifikasi
 * ---------------------------------------------------------------------
 * Kalimat di kaki plate 07: "kolom tunggal menyembunyikan bahwa separuh kelas
 * belum dikoreksi". Kolom Nilai tetap ada karena angka itulah yang dibaca
 * siswa, tetapi Guru harus bisa melihat dari mana angkanya datang.
 *
 * ---------------------------------------------------------------------
 * "Belum" bukan nol
 * ---------------------------------------------------------------------
 * `uraian` bernilai `null` selama `pending_essays > 0`, dan kolom itu
 * merendernya sebagai "belum" berwarna abu. Yang TIDAK boleh terjadi adalah
 * kolom Nilai ikut kosong: siswa yang sudah selesai objektifnya punya angka
 * yang sah, dan menyembunyikannya membuat Guru mengira tidak ada yang mengerjakan.
 */

type Baris = {
    hasil_id: number;
    nis: string | null;
    nama: string | null;
    kelas: string | null;
    objektif: number;
    uraian: number | null;
    nilai: number;
    final: boolean;
    sisa_uraian: number;
    status: string;
    status_label: string;
    status_nada: string;
    lulus: boolean | null;
};

type Props = {
    ujian: {
        id: number;
        judul: string;
        kode: string | null;
        mapel: string | null;
        event: string | null;
    };
    kkm: number;
    ringkas: {
        hasil: number;
        rata_rata: number | null;
        tertinggi: number | null;
        remedial: number;
        belum_final: number;
    };
    baris: Paginator<Baris>;
    filter: { kelas: string };
    kelas: { id: string; label: string }[];
};

export default function NilaiUjian({ ujian, kkm, ringkas, baris, filter, kelas }: Props) {
    const f = pakaiFilter(`/guru/nilai/${ujian.id}`, { kelas: filter.kelas });

    /**
     * Penyaring kelas mengirim lewat URL, dan halaman pertama ikut direset.
     *
     * Tanpa reset, Admin yang sedang di halaman 3 menyaring ke kelas yang
     * hanya punya satu halaman akan menerima tabel kosong - dan menyimpulkan
     * kelas itu belum punya nilai.
     */
    const terapkan = () => {
        const bersih = Object.fromEntries(
            Object.entries(f.nilai).filter(([, v]) => v !== '' && v !== undefined),
        );

        router.get(`/guru/nilai/${ujian.id}`, bersih, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const kelasAda = kelas.length > 0;

    const kosong = useMemo(
        () =>
            filter.kelas
                ? 'Tidak ada nilai untuk kelas ini pada ujian ini.'
                : 'Belum ada nilai. Daftar ini terisi setelah siswa mengumpulkan ujiannya.',
        [filter.kelas],
    );

    return (
        <GuruLayout
            judul="Nilai kelas"
            breadcrumb={`Nilai · ${ujian.mapel ?? ujian.judul}`}
            aksi={
                // XLSX dan PDF di plate 07 belum punya rutenya di Fase 8:
                // ekspor sisi Guru memakai sumber data yang sama dengan plate
                // 22, dan menambahkannya di sini berarti dua jalur ekspor
                // yang harus tetap sepakat. Tombolnya tidak dirender sampai
                // jalur itu ada - tombol mati mengajari Guru untuk tidak
                // mempercayai laman ini.
                null
            }
        >
            <Head title={`Nilai · ${ujian.judul}`} />

            <div className="mb-3">
                <p className="text-xs text-ink-3">
                    {[ujian.mapel, ujian.event, ujian.kode].filter(Boolean).join(' · ')}
                </p>
            </div>

            <div className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <Kpi label="Siswa" nilai={String(ringkas.hasil)} />
                <Kpi label="Rata-rata" nilai={formatPoin(ringkas.rata_rata)} />
                <Kpi label="Tertinggi" nilai={formatPoin(ringkas.tertinggi)} />
                <Kpi
                    label={`Remedial (< ${kkm})`}
                    nilai={String(ringkas.remedial)}
                    nada={ringkas.remedial > 0 ? 'warn' : undefined}
                />
            </div>

            {ringkas.belum_final > 0 && (
                <div className="mb-3">
                    <Inbox>
                        <strong>{ringkas.belum_final}</strong> siswa masih menunggu koreksi
                        uraian. Angka rata-rata dan remedial di atas dihitung dari baris yang
                        sudah final saja — memasukkan yang belum dinilai sebagai nol akan
                        membuat kelas ini terlihat lebih buruk daripada kenyataannya.
                    </Inbox>
                </div>
            )}

            <div className="mb-3">
                <BilahFilter padaKirim={terapkan}>
                    <select
                        value={f.nilai.kelas ?? ''}
                        onChange={(e) => f.ubah('kelas', e.target.value)}
                        className={kelasInput + ' max-w-[14rem]'}
                        disabled={!kelasAda}
                    >
                        <option value="">Semua kelas</option>
                        {kelas.map((k) => (
                            <option key={k.id} value={k.id}>
                                {k.label}
                            </option>
                        ))}
                    </select>

                    <Tombol type="submit" nada="pri">
                        Terapkan
                    </Tombol>
                    {filter.kelas && <Tombol onClick={f.reset}>Reset</Tombol>}
                </BilahFilter>
            </div>

            <Tabel<Baris>
                hal={baris}
                kunci={(b) => b.hasil_id}
                kosong={kosong}
                kolom={[
                    {
                        judul: 'NIS',
                        kelas: 'w-24',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">{b.nis ?? '—'}</span>
                        ),
                    },
                    {
                        judul: 'Nama',
                        render: (b) => (
                            <div className="min-w-0">
                                <span className="block truncate text-sm text-ink">
                                    {b.nama ?? 'Siswa tidak dikenal'}
                                </span>
                                {b.kelas && (
                                    <span className="text-[11px] text-ink-3">{b.kelas}</span>
                                )}
                            </div>
                        ),
                    },
                    {
                        judul: 'Obj.',
                        kelas: 'w-16 text-right',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">
                                {formatPoin(b.objektif)}
                            </span>
                        ),
                    },
                    {
                        judul: 'Uraian',
                        kelas: 'w-20 text-right',
                        render: (b) =>
                            b.uraian === null ? (
                                // "belum" berwarna abu - kalimat plate 07.
                                // Nol akan terbaca sebagai "dinilai, tidak
                                // dapat poin", dan itu tuduhan yang salah
                                // alamat ketika penyebabnya adalah antrean
                                // koreksi yang belum disentuh.
                                <span
                                    className="text-[11px] text-ink-3 italic"
                                    title={`${b.sisa_uraian} uraian belum dikoreksi`}
                                >
                                    belum
                                </span>
                            ) : (
                                <span className="font-mono text-xs text-ink-2">
                                    {formatPoin(b.uraian)}
                                </span>
                            ),
                    },
                    {
                        judul: 'Nilai',
                        kelas: 'w-20 text-right',
                        render: (b) => (
                            <span className="font-mono text-sm font-semibold text-ink">
                                {formatPoin(b.nilai)}
                            </span>
                        ),
                    },
                    {
                        judul: 'Status',
                        kelas: 'w-28',
                        render: (b) =>
                            b.final ? (
                                <Chip nada={b.lulus ? 'ok' : 'warn'}>
                                    {b.lulus ? 'tuntas' : 'remedial'}
                                </Chip>
                            ) : (
                                <Chip nada="mute">{b.status_label}</Chip>
                            ),
                    },
                ]}
            />
        </GuruLayout>
    );
}

/* ------------------------------------------------------------------ KPI */

function Kpi({ label, nilai, nada }: { label: string; nilai: string; nada?: 'warn' }) {
    const warna = nada === 'warn' ? 'text-warn' : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-3 py-2">
            <span className="block text-[11px] text-ink-3">{label}</span>
            <span className={'mt-0.5 block font-mono text-lg font-semibold ' + warna}>{nilai}</span>
        </div>
    );
}
