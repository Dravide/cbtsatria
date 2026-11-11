import { Chip } from '@/Components/ui';
import { Html } from '@/Components/PratinjauButir';

/**
 * Pratinjau satu soal hasil AI - kartu di dalam modal Generate.
 *
 * =====================================================================
 * Mengapa kunci IKUT ditampilkan di sini, padahal layar siswa tidak
 * =====================================================================
 * Yang membaca kartu ini Guru, dan satu-satunya pertanyaan yang ia ajukan
 * sebelum mencentang adalah "apakah jawaban benarnya memang ini". Tanpa
 * kuncinya terlihat, memeriksa sepuluh soal berarti membuka sepuluh form -
 * dan itulah yang membuat soal AI dibayar tetapi tidak pernah dipakai.
 *
 * Kunci ini tidak pernah sampai ke layar siswa: yang dikirim ke mesin ujian
 * adalah snapshot `ExamQuestion::untukSiswa()`, yang membuang `kunci_json`.
 */

/** Satu soal hasil AI, dalam bentuk yang dikirim `POST /guru/bank-soal/ai`. */
export type SoalAi = {
    jenis: string;
    judul: string;
    pertanyaan: string;
    pembahasan: string | null;
    opsi: { kunci: string; teks: string }[];
    kunci: string[];
    varian: string[];
    rumpang: string[][];
    butir: { kunci: string; grup: string; teks: string; pasangan: string | null }[];
    pasangan: Record<string, string>;
    urutan: string[];
    nilai: number | null;
    toleransi: number;
    satuan: string | null;
    rubrik: { kriteria: string; keterangan: string; skor_maks: number }[];
};

/** Label satu jenis, dibaca dari daftar yang dikirim server. */
export const labelJenis = (
    jenis: string,
    daftar: { nilai: string; label: string }[],
): string => daftar.find((j) => j.nilai === jenis)?.label ?? jenis;

export function PratinjauSoalAi({
    soal,
    nomor,
    dicentang,
    padaCentang,
    jenis,
    padaUbah,
}: {
    soal: SoalAi;
    nomor: number;
    dicentang: boolean;
    padaCentang: (nilai: boolean) => void;
    jenis: { nilai: string; label: string; pendek: string }[];
    /**
     * Bila ada, kartu menampilkan "Ubah di form".
     *
     * Opsional karena laman bank soal - tempat fitur ini sekarang hidup -
     * tidak punya form untuk dituju: soalnya belum tersimpan. Di sana
     * tombolnya tidak dirender alih-alih menunjuk laman yang tidak ada.
     */
    padaUbah?: () => void;
}) {
    return (
        <div
            className={
                'rounded-wf border ' +
                (dicentang ? 'border-accent bg-accent-soft/30' : 'border-line bg-surface')
            }
        >
            <header className="flex items-start gap-2 border-b border-line px-3 py-2">
                <input
                    type="checkbox"
                    checked={dicentang}
                    onChange={(e) => padaCentang(e.target.checked)}
                    className="mt-0.5"
                    aria-label={`Centang soal nomor ${nomor}`}
                />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-xs font-medium text-ink">
                        {nomor}. {soal.judul}
                    </p>
                    <p className="mt-0.5 text-[11px] text-ink-3">{labelJenis(soal.jenis, jenis)}</p>
                </div>
                {padaUbah && (
                    <button
                        type="button"
                        onClick={padaUbah}
                        className="shrink-0 text-[11px] text-accent underline underline-offset-2 hover:no-underline"
                    >
                        Ubah di form
                    </button>
                )}
            </header>

            <div className="space-y-2 px-3 py-2">
                <Html nilai={soal.pertanyaan} kosong="(soal tanpa teks)" />
                <KunciAi soal={soal} />
            </div>
        </div>
    );
}

/**
 * Kunci jawaban, dalam bentuk yang bisa diperiksa sekilas.
 *
 * Satu cabang per jenis, dan itu memang disengaja: sepuluh bentuk kunci
 * adalah sepuluh KALIMAT yang berbeda bagi pembacanya - "kunci a" untuk
 * pilihan ganda, "Jakarta / DKI Jakarta" untuk isian, "k1 → p2" untuk
 * jodoh. Menyatukannya menjadi satu tampilan JSON berarti Guru membaca JSON
 * alih-alih soal.
 */
function KunciAi({ soal }: { soal: SoalAi }) {
    switch (soal.jenis) {
        case 'pilihan_ganda':
        case 'jawaban_ganda':
            return (
                <ul className="space-y-1">
                    {soal.opsi.map((o) => {
                        const benar = soal.kunci.includes(o.kunci);

                        return (
                            <li key={o.kunci} className="flex items-start gap-2 text-xs">
                                <span
                                    className={
                                        'grid size-5 shrink-0 place-items-center rounded-wf border font-mono text-[10px] ' +
                                        (benar
                                            ? 'border-ok bg-ok-bg text-ok'
                                            : 'border-line bg-surface-2 text-ink-3')
                                    }
                                >
                                    {o.kunci}
                                </span>
                                <span className={benar ? 'text-ink' : 'text-ink-2'}>
                                    <Html nilai={o.teks} kosong="(kosong)" />
                                </span>
                                {benar && <Chip nada="ok">kunci</Chip>}
                            </li>
                        );
                    })}
                </ul>
            );

        case 'benar_salah':
            return (
                <p className="text-xs text-ink-2">
                    Jawaban:{' '}
                    <b className="text-ink">{soal.kunci[0] === 'b' ? 'Benar' : 'Salah'}</b>
                </p>
            );

        case 'isian_singkat':
            return (
                <div className="flex flex-wrap items-center gap-1">
                    <span className="text-[11px] text-ink-3">Diterima:</span>
                    {soal.varian.map((v) => (
                        <Chip key={v} nada="ok">
                            {v}
                        </Chip>
                    ))}
                </div>
            );

        case 'isian_rumpang':
            return (
                <ol className="space-y-1 text-xs">
                    {soal.rumpang.map((varian, i) => (
                        <li key={i} className="flex flex-wrap items-center gap-1">
                            <span className="font-mono text-[11px] text-ink-3">{`{{${i + 1}}}`}</span>
                            {varian.length === 0 ? (
                                <Chip nada="bad">belum ada kunci</Chip>
                            ) : (
                                varian.map((v) => (
                                    <Chip key={v} nada="ok">
                                        {v}
                                    </Chip>
                                ))
                            )}
                        </li>
                    ))}
                </ol>
            );

        case 'menjodohkan': {
            const kanan = soal.butir.filter((b) => b.grup === 'kanan');
            const teksKanan = (kunci: string) =>
                kanan.find((b) => b.kunci === kunci)?.teks ?? '(tidak ada)';

            return (
                <ul className="space-y-1 text-xs">
                    {soal.butir
                        .filter((b) => b.grup === 'kiri')
                        .map((b) => (
                            <li key={b.kunci} className="flex items-baseline gap-1.5">
                                <Html nilai={b.teks} kosong="(kosong)" />
                                <span className="text-ink-3">→</span>
                                <Html
                                    nilai={b.pasangan ? teksKanan(b.pasangan) : null}
                                    kosong="belum dipasangkan"
                                />
                            </li>
                        ))}
                </ul>
            );
        }

        case 'mengurutkan': {
            const peta = new Map(soal.butir.map((b) => [b.kunci, b.teks]));

            return (
                <ol className="ml-4 list-decimal space-y-0.5 text-xs text-ink-2">
                    {soal.urutan.map((k) => (
                        <li key={k}>
                            <Html nilai={peta.get(k) ?? null} kosong="(item hilang)" />
                        </li>
                    ))}
                </ol>
            );
        }

        case 'angka':
            return (
                <p className="text-xs text-ink-2">
                    Jawaban: <b className="angka text-ink">{soal.nilai ?? '—'}</b>
                    {soal.toleransi > 0 && <> ± {soal.toleransi}</>}
                    {soal.satuan && <> {soal.satuan}</>}
                </p>
            );

        case 'uraian':
            return (
                <ul className="space-y-1 text-xs">
                    {soal.rubrik.map((r, i) => (
                        <li key={i} className="flex items-baseline gap-2">
                            <span className="angka shrink-0 text-ink-2">{r.skor_maks}</span>
                            <span className="text-ink">{r.kriteria}</span>
                            {r.keterangan && (
                                <span className="text-[11px] text-ink-3">{r.keterangan}</span>
                            )}
                        </li>
                    ))}
                </ul>
            );

        default:
            return null;
    }
}
