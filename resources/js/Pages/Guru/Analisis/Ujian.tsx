import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import GuruLayout from '@/Layouts/GuruLayout';
import { Chip, Inbox, Peringatan, Tabel, Tombol } from '@/Components/ui';
import { formatDayaBeda, formatPersen } from '@/Components/angka';

/**
 * Analisis butir satu ujian - wireframe-peran.html plate 08.
 *
 * ---------------------------------------------------------------------
 * Butir yang belum dianalisis TETAP ditampilkan
 * ---------------------------------------------------------------------
 * Menyembunyikannya berarti Guru melihat empat dari empat puluh baris lalu
 * menyimpulkan ujiannya cuma empat soal. Barisnya tampil dengan tanda hubung
 * di setiap angka dan chip "belum dihitung" - kalimat yang berbeda dari nol.
 *
 * ---------------------------------------------------------------------
 * Inbox merah hanya untuk daya beda NEGATIF
 * ---------------------------------------------------------------------
 * Plate 08 menulis: "Butir 29 punya daya beda negatif: siswa yang nilainya
 * tinggi justru lebih banyak menjawab salah. Biasanya tanda kunci jawaban
 * tertukar." Itu satu-satunya putusan yang menuntut tindakan hari ini, dan
 * karena itu satu-satunya yang diberi kotak merah. "Terlalu mudah" dan
 * "sulit" adalah catatan untuk tahun depan, bukan kesalahan yang harus
 * diperbaiki sekarang.
 */

type Baris = {
    butir_id: number;
    nomor: number;
    judul: string | null;
    jenis: string;
    jenis_label: string;
    bobot: number;
    dijawab: number | null;
    benar: number | null;
    salah: number | null;
    persen_benar: number | null;
    daya_beda: number | null;
    putusan: string | null;
    putusan_nada: string | null;
    rata_durasi_detik?: number | null;
    kuadran?: string | null;
    opsi_mati?: string[];
};

type KemiripanKursi = {
    ruang: string;
    kursi_a: number;
    nama_a: string;
    kursi_b: number;
    nama_b: string;
    salah_sama: number;
};

type Props = {
    ujian: {
        id: number;
        judul: string;
        kode: string | null;
        mapel: string | null;
        event: string | null;
    };
    baris: Baris[];
    ada_analisis: boolean;
    kemiripan_kursi?: KemiripanKursi[];
    ambang: {
        mudah: number;
        sulit: number;
        daya_beda: number;
        minimal_peserta: number;
    };
};

export default function AnalisisUjian({ ujian, baris, ada_analisis, kemiripan_kursi = [], ambang }: Props) {
    const [kirim, setKirim] = useState(false);

    /**
     * Butir dengan daya beda negatif - satu-satunya yang diberi kotak merah.
     *
     * Yang DIBACA adalah `putusan` dari `QuestionAnalysis::putuskan()`, bukan
     * perbandingan ulang di sini: urutan keputusannya (negatif lebih dulu,
     * baru kesukaran) adalah aturan yang sudah ditegakkan di satu tempat, dan
     * menghitungnya lagi di peramban berarti dua salinan yang bisa berbeda.
     */
    const bermasalah = baris.filter(
        (b) => b.putusan === 'periksa kunci' || (b.daya_beda !== null && b.daya_beda < 0),
    );

    const hitungUlang = () => {
        if (kirim) {
            return;
        }

        setKirim(true);

        router.post(
            `/guru/analisis/${ujian.id}/hitung-ulang`,
            {},
            { onFinish: () => setKirim(false), preserveScroll: true },
        );
    };

    return (
        <GuruLayout
            judul="Analisis butir"
            breadcrumb={`Analisis · ${ujian.mapel ?? ujian.judul}`}
            aksi={
                <>
                    <span className="self-center text-[11px] text-ink-3">setelah ujian</span>
                    <a
                        href={`/guru/analisis/${ujian.id}/unduh`}
                        target="_blank"
                        rel="noreferrer"
                        className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
                    >
                        Unduh PDF Analisis
                    </a>
                    <Tombol onClick={hitungUlang} disabled={kirim}>
                        {kirim ? 'Menghitung…' : 'Hitung ulang'}
                    </Tombol>
                </>
            }
        >
            <Head title={`Analisis · ${ujian.judul}`} />

            <div className="mb-3">
                <p className="text-xs text-ink-3">
                    {[ujian.mapel, ujian.event, ujian.kode].filter(Boolean).join(' · ')}
                </p>
            </div>

            {!ada_analisis && (
                <div className="mb-3">
                    <Inbox>
                        Ujian ini belum pernah dianalisis. Tombol <strong>Hitung ulang</strong>{' '}
                        menghitungnya sekarang. Hitung hanya setelah seluruh kelas mengumpulkan,
                        karena daya beda yang dihitung dari separuh kelas adalah angka yang salah.
                    </Inbox>
                </div>
            )}

            {bermasalah.length > 0 && (
                <div className="mb-3">
                    <Peringatan>
                        {bermasalah.length === 1 ? (
                            <>
                                Butir <strong>{bermasalah[0].nomor}</strong> punya daya beda
                                negatif: siswa yang nilainya tinggi justru lebih banyak menjawab
                                salah. Biasanya tanda kunci jawaban tertukar.
                            </>
                        ) : (
                            <>
                                <strong>{bermasalah.length} butir</strong> punya daya beda negatif
                                atau kunci yang patut diperiksa. Siswa bernilai tinggi justru lebih
                                banyak menjawab salah, dan biasanya tanda kunci jawabannya tertukar.
                                Butirnya: {bermasalah.map((b) => b.nomor).join(', ')}.
                            </>
                        )}
                    </Peringatan>
                </div>
            )}

            <Tabel<Baris>
                hal={{ data: baris, total: baris.length }}
                kunci={(b) => b.butir_id}
                kosong="Ujian ini belum punya butir."
                kolom={[
                    {
                        judul: 'No',
                        kelas: 'w-12',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">{b.nomor}</span>
                        ),
                    },
                    {
                        judul: 'Ringkas soal',
                        render: (b) => (
                            <div className="min-w-0">
                                <span className="block truncate text-sm text-ink">
                                    {b.judul ?? 'Tanpa judul'}
                                </span>
                                <span className="text-[11px] text-ink-3">
                                    {b.jenis_label} · bobot {b.bobot}
                                </span>
                            </div>
                        ),
                    },
                    {
                        judul: 'Dijawab',
                        kelas: 'w-20 text-right',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">
                                {b.dijawab ?? '—'}
                            </span>
                        ),
                    },
                    {
                        judul: 'Benar',
                        kelas: 'w-20 text-right',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">
                                {formatPersen(b.persen_benar)}
                            </span>
                        ),
                    },
                    {
                        judul: 'Daya beda',
                        kelas: 'w-24 text-right',
                        render: (b) => (
                            <span
                                className={
                                    'font-mono text-xs ' +
                                    (b.daya_beda !== null && b.daya_beda < 0
                                        ? 'text-bad'
                                        : 'text-ink-2')
                                }
                                // Null saat kelompok atas/bawah tidak cukup
                                // besar. Nol akan terbaca sebagai "tidak
                                // membedakan sama sekali", dan itu putusan
                                // yang berbeda - lihat PenghitungAnalisis.
                                title={
                                    b.daya_beda === null
                                        ? `Belum bisa dihitung. Butuh minimal ${ambang.minimal_peserta} peserta`
                                        : undefined
                                }
                            >
                                {formatDayaBeda(b.daya_beda)}
                            </span>
                        ),
                    },
                    {
                        judul: 'Putusan',
                        kelas: 'w-32',
                        render: (b) =>
                            b.putusan === null ? (
                                <Chip nada="mute">belum dihitung</Chip>
                            ) : (
                                <Chip nada={nadaPutusan(b.putusan_nada)}>{b.putusan}</Chip>
                            ),
                    },
                    {
                        judul: 'Telemetri',
                        kelas: 'w-44',
                        render: (b) => (
                            <div className="space-y-0.5 text-[11px] text-ink-2">
                                <div>
                                    {b.rata_durasi_detik != null ? `${b.rata_durasi_detik} dtk` : '—'}
                                    {b.kuadran ? ` · ${b.kuadran}` : ''}
                                </div>
                                {b.opsi_mati && b.opsi_mati.length > 0 && (
                                    <div className="text-[10px] text-warn">
                                        Opsi mati: {b.opsi_mati.join(', ')}
                                    </div>
                                )}
                            </div>
                        ),
                    },
                ]}
            />

            {kemiripan_kursi.length > 0 && (
                <div className="mt-4 rounded-wf border border-warn bg-surface p-3">
                    <p className="text-xs font-semibold text-ink">
                        Korelasi Jawaban Salah Berdekatan (Denah Kursi)
                    </p>
                    <p className="mt-0.5 text-[11px] text-ink-3">
                        Pasangan kursi bersebelahan dengan minimal 3 jawaban salah yang identik.
                    </p>
                    <div className="mt-2 space-y-1.5">
                        {kemiripan_kursi.map((k, i) => (
                            <div
                                key={`${k.ruang}-${k.kursi_a}-${k.kursi_b}-${i}`}
                                className="flex items-center justify-between rounded-wf border border-line bg-surface-2 px-2.5 py-1.5 text-xs"
                            >
                                <span>
                                    <strong className="font-mono">{k.ruang}</strong> · Kursi {k.kursi_a} ({k.nama_a}) & Kursi {k.kursi_b} ({k.nama_b})
                                </span>
                                <Chip nada="warn">{k.salah_sama} salah identik</Chip>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                Ambang: terlalu mudah di atas {ambang.mudah}%, sulit di bawah {ambang.sulit}%,
                daya beda minimal {formatDayaBeda(ambang.daya_beda)}. Daya beda hanya dihitung
                bila peserta cukup (minimal {ambang.minimal_peserta}).
            </p>
        </GuruLayout>
    );
}

/**
 * Nada dari `QuestionAnalysis::nada()`.
 *
 * Nilainya adalah string bebas dari server, sehingga peta ini wajib punya
 * cabang default - dan defaultnya `mute`, bukan `bad`. Putusan yang tidak
 * dikenal bukan alasan menuduh soal itu bermasalah.
 */
function nadaPutusan(nada: string | null): 'ok' | 'warn' | 'bad' | 'info' | 'mute' {
    switch (nada) {
        case 'ok':
        case 'warn':
        case 'bad':
        case 'info':
            return nada;
        default:
            return 'mute';
    }
}
