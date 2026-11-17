import { Head, Link, router } from '@inertiajs/react';
import { useMemo } from 'react';
import GuruLayout from '@/Layouts/GuruLayout';
import {
    BilahFilter,
    Chip,
    Inbox,
    Paginator,
    PilihCari,
    Tabel,
    Tombol,
    TombolTautan,
    pakaiFilter,
} from '@/Components/ui';
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
    /** Id penempatan peserta - kunci rute rincian per siswa. */
    peserta_id: number | null;
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
    filter: {
        kelas: string;
        /** Kunci urutan yang sedang berlaku - kolom BERTABEL dari server. */
        urut: string;
        arah: 'asc' | 'desc';
    };
    kelas: { id: string; label: string }[];
};

export default function NilaiUjian({ ujian, kkm, ringkas, baris, filter, kelas }: Props) {
    const f = pakaiFilter(`/guru/nilai/${ujian.id}`, { kelas: filter.kelas });

    /**
     * Klik kepala kolom: urutkan menurutnya, atau balik arahnya bila kolom
     * itu sudah aktif.
     *
     * Urutannya dikerjakan SERVER - `TabelQuery` sudah membatasi kolomnya
     * dengan daftar putih, jadi nilai yang dikirim dari sini tidak bisa
     * membuka kolom yang tidak seharusnya. Yang dikerjakan di sini hanya
     * memutuskan arah, dan itu pun hanya tampilan: server menormalkannya
     * lagi.
     *
     * Halaman pertama ikut direset, sama seperti penyaring kelas: Guru yang
     * sedang di halaman 3 lalu mengurutkan ulang akan menerima halaman 3
     * dari susunan yang baru - dan menyimpulkan urutannya salah.
     */
    const urutkan = (kolom: string) => {
        const sudahAktif = filter.urut === kolom;
        const arah = sudahAktif && filter.arah === 'asc' ? 'desc' : 'asc';

        router.get(
            `/guru/nilai/${ujian.id}`,
            {
                ...(filter.kelas ? { kelas: filter.kelas } : {}),
                urut: kolom,
                arah,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    /** Kepala kolom yang bisa diklik, dengan penanda arah. */
    const KepalaUrut = ({ kolom, children }: { kolom: string; children: React.ReactNode }) => {
        const aktif = filter.urut === kolom;

        return (
            <button
                type="button"
                onClick={() => urutkan(kolom)}
                aria-sort={aktif ? (filter.arah === 'asc' ? 'ascending' : 'descending') : 'none'}
                className={
                    'inline-flex items-center gap-1 text-[11px] font-medium transition-colors ' +
                    (aktif ? 'text-accent' : 'text-ink-3 hover:text-ink')
                }
            >
                {children}
                <span aria-hidden="true" className={aktif ? '' : 'text-ink-3/50'}>
                    {aktif ? (filter.arah === 'asc' ? '▲' : '▼') : '↕'}
                </span>
            </button>
        );
    };

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

    /**
     * Unduhan tersedia hanya bila SELURUH baris sudah final, dan alasannya
     * bukan tampilan.
     *
     * Di layar, uraian yang belum dikoreksi tampil "belum" dan angkanya
     * terbaca sebagai sementara. Di berkas XLSX, kolom itu menjadi SEL yang
     * dijumlahkan Excel - Guru yang menghitung rata-rata dari berkasnya akan
     * mendapat angka yang berbeda dari laman ini, tanpa tanda bahwa
     * penyebabnya koreksi yang belum selesai.
     *
     * Karena itu tombolnya ADA dan MENOLAK dengan alasan, bukan hilang:
     * tombol yang hilang membuat Guru mengira fiturnya belum ada, dan ia
     * akan menyusun sendiri tabelnya di Excel.
     */
    const siapUnduh = ringkas.belum_final === 0 && ringkas.hasil > 0;
    const urlUnduh = `/guru/nilai/${ujian.id}/unduh${filter.kelas ? `?kelas=${filter.kelas}` : ''}`;

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
                <>
                    {/* Unduhan: satu kelas bila penyaringnya aktif, seluruh
                        ujian bila tidak. Labelnya menyebut cakupannya, supaya
                        Guru tidak mengira ia mengunduh seluruh kelas padahal
                        sedang menyaring satu kelas. */}
                    {siapUnduh ? (
                        <TombolTautan
                            href={urlUnduh}
                            title={
                                filter.kelas
                                    ? 'Unduh nilai kelas ini sebagai XLSX'
                                    : 'Unduh nilai seluruh ujian sebagai XLSX'
                            }
                        >
                            Unduh {filter.kelas ? 'kelas ini' : 'semua'}
                        </TombolTautan>
                    ) : (
                        <span
                            title={
                                ringkas.hasil === 0
                                    ? 'Belum ada nilai yang bisa diunduh.'
                                    : `${ringkas.belum_final} siswa masih menunggu koreksi uraian.`
                            }
                            className="inline-flex items-center justify-center gap-1.5 rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink-3"
                        >
                            Unduh {filter.kelas ? 'kelas ini' : 'semua'}
                        </span>
                    )}
                </>
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
                    {/* Kelas bisa puluhan, dan daftar itu yang paling sering
                        dicari Guru saat menelusuri satu kelas di antara
                        seluruh kelas yang ia ampu. `PilihCari` menyaring
                        sambil mengetik, dan baris "Semua kelas" mengembalikan
                        penyaring ke kosong. */}
                    <div className="w-56">
                        <PilihCari<string>
                            label="Kelas"
                            nilai={f.nilai.kelas === '' ? null : (f.nilai.kelas ?? null)}
                            pilihan={kelas.map((k) => ({ id: k.id, label: k.label }))}
                            padaPilih={(id) => f.ubah('kelas', id ?? '')}
                            disabled={!kelasAda}
                            izinkanKosong
                            labelKosong="Semua kelas"
                            placeholder="Semua kelas"
                            kosong="Tidak ada kelas yang cocok."
                        />
                    </div>

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
                        kepala: <KepalaUrut kolom="s.name">Nama</KepalaUrut>,
                        render: (b) => (
                            <div className="min-w-0">
                                {/* Nama adalah tautan ke rinciannya: soal,
                                    jawaban, dan linimasa. Sebelumnya satu-
                                    satunya cara melihat "kenapa nilainya
                                    begini" adalah membuka koreksi dan
                                    menebak butir mana yang dimaksud. */}
                                {b.peserta_id === null ? (
                                    <span className="block truncate text-sm text-ink">
                                        {b.nama ?? 'Siswa tidak dikenal'}
                                    </span>
                                ) : (
                                    <Link
                                        href={`/guru/nilai/${ujian.id}/siswa/${b.peserta_id}`}
                                        className="block truncate text-sm text-ink underline decoration-line underline-offset-2 hover:decoration-ink"
                                    >
                                        {b.nama ?? 'Siswa tidak dikenal'}
                                    </Link>
                                )}
                                {b.kelas && (
                                    <span className="text-[11px] text-ink-3">{b.kelas}</span>
                                )}
                            </div>
                        ),
                    },
                    {
                        judul: 'Obj.',
                        kepala: <KepalaUrut kolom="exam_results.automatic_score">Obj.</KepalaUrut>,
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
                        kepala: <KepalaUrut kolom="exam_results.final_percentage">Nilai</KepalaUrut>,
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
