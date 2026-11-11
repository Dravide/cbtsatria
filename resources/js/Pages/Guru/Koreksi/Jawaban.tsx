import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import GuruLayout from '@/Layouts/GuruLayout';
import { Chip, Field, Inbox, Peringatan, Tombol, kelasInput } from '@/Components/ui';
import { Html } from '@/Components/PratinjauButir';
import { formatPoin } from '@/Components/angka';

/**
 * Satu jawaban uraian - wireframe-peran.html plate 06.
 *
 * ---------------------------------------------------------------------
 * Yang diketik Guru adalah CENTANG, bukan poin bebas
 * ---------------------------------------------------------------------
 * Kalimat di kaki plate 06: "Kalau Guru mengetik poin bebas, dua pengoreksi
 * berbeda akan memberi nilai berbeda untuk jawaban yang sama." Karena itu
 * bentuk formnya adalah daftar kriteria dari rubrik yang dibekukan, masing-
 * masing dengan satu kotak centang dan poinnya sendiri. Totalnya dijumlahkan
 * di sini hanya untuk DIBACA - yang disimpan adalah satu poin per kriteria.
 *
 * Kotak angka di tiap kriteria tetap ada, tetapi batasnya poin kriteria itu
 * sendiri. Guru yang ingin memberi 2 dari 4 poin karena langkahnya setengah
 * benar memang perlu bisa melakukannya; yang tidak boleh adalah memberi 4,5
 * pada kriteria bernilai 4.
 *
 * ---------------------------------------------------------------------
 * Terkunci berarti terkunci
 * ---------------------------------------------------------------------
 * `terkunci` berasal dari `SchoolEvent::koreksiTerkunci()` - event sudah
 * mempublikasikan hasilnya. Seluruh kontrol dinonaktifkan, dan yang
 * menjelaskannya adalah kotak peringatan di atas, bukan tombol mati tanpa
 * kalimat. Yang membukanya adalah Admin, dengan alasan tercatat.
 */

type Kriteria = { kriteria: string; maks?: number; poin?: number };

type Props = {
    ujian: {
        id: number;
        judul: string;
        kode: string | null;
        mapel: string | null;
        event: string | null;
    };
    siswa: { nama: string | null; nomor_soal: number | null };
    soal: {
        judul: string | null;
        teks: string | null;
        bobot: number;
        rubrik: Kriteria[];
    };
    jawaban: { id: number; teks: string; terjawab: boolean };
    koreksi: {
        score: number;
        kriteria: Kriteria[];
        feedback: string | null;
        dinilai_pada: string | null;
    } | null;
    terkunci: boolean;
    berikutnya: number | null;
};

export default function KoreksiJawaban({
    ujian,
    siswa,
    soal,
    jawaban,
    koreksi,
    terkunci,
    berikutnya,
}: Props) {
    /**
     * Kriteria yang dipakai laman ini.
     *
     * Urutannya: rubrik SNAPSHOT lebih dulu, lalu kriteria yang tersimpan di
     * koreksi. Rubrik snapshot adalah kebenarannya - koreksi lama yang
     * kriteria-nya berbeda (rubrik sudah diubah sesudahnya) tetap ditampilkan,
     * karena itulah yang benar-benar dinilai saat itu.
     */
    const kriteria: Kriteria[] = useMemo(() => {
        if (soal.rubrik.length > 0) {
            return soal.rubrik;
        }

        return koreksi?.kriteria ?? [];
    }, [soal.rubrik, koreksi]);

    /**
     * Poin per kriteria, diindeks.
     *
     * Nilai awalnya diambil dari koreksi lama bila ada, supaya laman
     * "Tinjau" menampilkan apa yang benar-benar tersimpan - bukan daftar
     * kosong yang menuntut Guru mengingat-ingat apa yang tadi ia beri.
     */
    const [poin, setPoin] = useState<number[]>(() => {
        const lama = koreksi?.kriteria ?? [];

        return kriteria.map((k) => {
            const cocok = lama.find((l) => l.kriteria === k.kriteria);

            return Number(cocok?.poin ?? k.poin ?? 0);
        });
    });

    const [feedback, setFeedback] = useState(koreksi?.feedback ?? '');
    const [kirim, setKirim] = useState(false);
    const [galat, setGalat] = useState<Record<string, string>>({});

    const maksimal = kriteria.reduce((n, k) => n + (Number(k.maks) || 0), 0);
    const total = poin.reduce((n, p) => n + p, 0);

    const simpan = () => {
        if (kirim || terkunci) {
            return;
        }

        setKirim(true);
        setGalat({});

        router.post(
            `/guru/koreksi/jawaban/${jawaban.id}`,
            {
                kriteria: kriteria.map((k, i) => ({
                    kriteria: k.kriteria,
                    poin: poin[i],
                })),
                feedback: feedback || null,
            },
            {
                onError: (e) => setGalat(e as Record<string, string>),
                onFinish: () => setKirim(false),
            },
        );
    };

    const batalkan = () => {
        if (kirim || terkunci) {
            return;
        }

        setKirim(true);

        router.delete(`/guru/koreksi/jawaban/${jawaban.id}`, {
            onFinish: () => setKirim(false),
        });
    };

    return (
        <GuruLayout
            judul="Koreksi uraian"
            breadcrumb={`Koreksi · ${ujian.mapel ?? ujian.judul} · Soal ${siswa.nomor_soal ?? '—'}`}
            aksi={
                <>
                    {/* "Lewati" hanya muncul bila ada berikutnya. Menampilkannya
                        sebagai tombol mati di antrean yang sisa satu jawaban
                        membuat Guru menekan tombol yang tidak melakukan apa-apa
                        dan menyimpulkan lamannya rusak. */}
                    {berikutnya !== null && (
                        <Link
                            href={`/guru/koreksi/jawaban/${berikutnya}`}
                            className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
                        >
                            Lewati
                        </Link>
                    )}
                    <Tombol nada="pri" onClick={simpan} disabled={kirim || terkunci}>
                        {kirim ? 'Menyimpan…' : 'Simpan & lanjut'}
                    </Tombol>
                </>
            }
        >
            <Head title={`Koreksi · ${siswa.nama ?? 'Jawaban'}`} />

            {terkunci && (
                <div className="mb-3">
                    <Peringatan>
                        Koreksi ujian ini <strong>terkunci</strong> karena hasilnya sudah
                        dipublikasikan. Nilai yang sudah tersimpan tidak dapat diubah. Admin
                        dapat membukanya kembali lewat laman Hasil event, dan alasannya
                        tercatat di log aktivitas.
                    </Peringatan>
                </div>
            )}

            {kriteria.length === 0 && (
                <div className="mb-3">
                    <Inbox>
                        Soal ini tidak punya rubrik. Tanpa rubrik, poin tidak bisa diisi
                        per kriteria - nilai uraiannya akan tetap dihitung sebagai belum
                        dikoreksi sampai rubriknya ditambahkan.
                    </Inbox>
                </div>
            )}

            <div className="grid gap-3 lg:grid-cols-2">
                {/* ------------------------------------------ Jawaban siswa */}
                <section className="rounded-wf border border-line bg-surface">
                    <header className="flex items-center gap-2 border-b border-line px-4 py-2.5">
                        <h2 className="text-xs font-medium tracking-wide text-ink-2 uppercase">
                            Jawaban siswa
                        </h2>
                        <span className="ml-auto text-xs text-ink-2">{siswa.nama}</span>
                    </header>

                    <div className="p-4">
                        <p className="mb-3 text-xs text-ink-3">{soal.judul}</p>

                        {/* Teks soal adalah HTML berformat dari snapshot, jadi
                            dirender lewat `Html` - pola yang sama dengan
                            `Siswa/Riwayat`. Jawaban siswa di bawahnya tetap
                            teks biasa: yang ia ketik tidak pernah HTML. */}
                        {soal.teks && (
                            <div className="mb-3 rounded-wf border border-line bg-surface-2/50 px-3 py-2">
                                <Html nilai={soal.teks} />
                            </div>
                        )}

                        {jawaban.terjawab ? (
                            <div className="text-sm leading-relaxed whitespace-pre-line text-ink">
                                {jawaban.teks}
                            </div>
                        ) : (
                            // Jawaban kosong BUKAN nol yang tersembunyi. Guru
                            // harus tahu bahwa tidak ada yang diketik, karena
                            // memberi poin penuh pada jawaban kosong adalah
                            // kekeliruan yang mungkin terjadi saat mengoreksi
                            // tiga puluh jawaban berturut-turut.
                            <p className="text-sm text-ink-3 italic">
                                Siswa tidak menulis jawaban apa pun.
                            </p>
                        )}
                    </div>
                </section>

                {/* ------------------------------------------------- Rubrik */}
                <section className="rounded-wf border border-line bg-surface">
                    <header className="flex items-center gap-2 border-b border-line px-4 py-2.5">
                        <h2 className="text-xs font-medium tracking-wide text-ink-2 uppercase">
                            Rubrik
                        </h2>
                        <span className="ml-auto text-[11px] text-ink-3">
                            bobot butir {formatPoin(soal.bobot)}
                        </span>
                    </header>

                    <div className="space-y-2 p-4">
                        {kriteria.map((k, i) => {
                            const batas = Number(k.maks) || 0;

                            return (
                                <div
                                    key={`${k.kriteria}-${i}`}
                                    className="flex items-center gap-2 rounded-wf border border-line px-3 py-2"
                                >
                                    <input
                                        type="checkbox"
                                        checked={poin[i] >= batas && batas > 0}
                                        disabled={terkunci}
                                        onChange={(e) => {
                                            const baru = [...poin];
                                            baru[i] = e.target.checked ? batas : 0;
                                            setPoin(baru);
                                        }}
                                        className="h-3.5 w-3.5 accent-[var(--color-accent)]"
                                        aria-label={k.kriteria}
                                    />

                                    <span className="min-w-0 flex-1 truncate text-xs text-ink">
                                        {k.kriteria}
                                    </span>

                                    {/* Poin sebagian. Guru yang perlu memberi 2
                                        dari 4 mengetiknya di sini; batasnya
                                        poin kriteria itu, bukan totalnya. */}
                                    <input
                                        type="number"
                                        min={0}
                                        max={batas}
                                        step={0.5}
                                        value={poin[i]}
                                        disabled={terkunci}
                                        onChange={(e) => {
                                            const isi = Number(e.target.value);
                                            const baru = [...poin];
                                            baru[i] = Math.min(batas, Math.max(0, isi || 0));
                                            setPoin(baru);
                                        }}
                                        className="w-16 rounded-wf border border-line bg-surface px-2 py-1 text-right font-mono text-xs text-ink outline-none focus:border-accent disabled:bg-surface-2"
                                    />

                                    <span className="w-8 text-right font-mono text-[11px] text-ink-3">
                                        / {formatPoin(batas)}
                                    </span>
                                </div>
                            );
                        })}

                        <div className="flex items-center justify-between border-t border-line pt-3">
                            <span className="text-xs text-ink-2">Poin diberikan</span>
                            <span className="font-mono text-sm font-semibold text-ink">
                                {formatPoin(total)}{' '}
                                <span className="font-normal text-ink-3">/ {formatPoin(maksimal)}</span>
                            </span>
                        </div>

                        {koreksi && (
                            <div className="flex items-center gap-2 pt-1 text-[11px] text-ink-3">
                                <Chip nada="ok">sudah dikoreksi</Chip>
                                <span>{formatPoin(koreksi.score)} poin tersimpan</span>
                                {!terkunci && (
                                    <button
                                        onClick={batalkan}
                                        disabled={kirim}
                                        className="ml-auto text-[11px] font-medium text-bad hover:underline disabled:opacity-50"
                                    >
                                        Batalkan koreksi
                                    </button>
                                )}
                            </div>
                        )}
                    </div>
                </section>
            </div>

            {/* ------------------------------------------------- Catatan */}
            <div className="mt-3">
                <Field
                    label="Catatan untuk siswa"
                    galat={galat.feedback}
                    petunjuk="Tampil ke siswa hanya bila pembahasan dibuka Admin. Catatan ini bukan tempat mencatat kekeliruan siswa — itu ada di kolom pelanggaran."
                >
                    <textarea
                        value={feedback}
                        disabled={terkunci}
                        onChange={(e) => setFeedback(e.target.value)}
                        rows={2}
                        className={kelasInput}
                        placeholder="Contoh: langkahnya benar, satuan belum ditulis."
                    />
                </Field>
            </div>

            {galat.kriteria && (
                <p className="mt-2 text-[11px] text-bad">{galat.kriteria}</p>
            )}

            <div className="mt-3 flex flex-wrap items-center justify-end gap-2">
                <Link
                    href={`/guru/koreksi/${ujian.id}`}
                    className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
                >
                    Kembali ke antrean
                </Link>

                <Tombol nada="pri" onClick={simpan} disabled={kirim || terkunci || kriteria.length === 0}>
                    {kirim ? 'Menyimpan…' : 'Simpan & lanjut'}
                </Tombol>
            </div>
        </GuruLayout>
    );
}
