import { Link } from '@inertiajs/react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { Html } from '@/Components/PratinjauButir';
import { formatPoin } from '@/Components/angka';

/**
 * Riwayat jawaban satu ujian - "soal nomor 7 saya jawab apa".
 *
 * =====================================================================
 * Laman ini TIDAK menghitung apa pun
 * =====================================================================
 * Benar/salah setiap butir sudah tersimpan di `student_answers.is_correct`
 * sejak penilaian berjalan, dan skornya di `score_awarded`. Laman yang
 * menghitung ulang dari kunci akan menjadi mesin penilai KEDUA - dan begitu
 * Admin menekan "Hitung ulang" di laman hasil, dua angka itu berpisah tanpa
 * ada yang tahu mana yang benar.
 *
 * Bentuk jawaban (huruf opsi, teks ketikan, baris jodoh, urutan) juga tidak
 * dirakit di sini: server mengirimnya sudah jadi lewat `RingkasJawaban`,
 * karena sepuluh bentuk `answer_json` adalah sepuluh hal yang tidak boleh
 * disalin ke TypeScript.
 *
 * =====================================================================
 * TIGA keadaan per butir, dan ketiganya berbeda
 * =====================================================================
 *   benar: true      - dinilai, benar.
 *   benar: false     - dinilai, salah.
 *   benar: null      - BELUM dinilai. Uraian yang masih di meja Guru, atau
 *                      butir yang tidak dijawab sama sekali.
 *
 * Menyatukan `null` ke `false` akan membuat anak membaca "salah" di atas
 * jawaban yang belum diperiksa siapa pun - dan ia akan menagih Guru atas
 * tuduhan yang belum dibuat.
 *
 * =====================================================================
 * Kunci dan pembahasan adalah DUA izin, bukan satu
 * =====================================================================
 * `boleh_kunci` menyalakan kolom "kunci"; `boleh_pembahasan` menyalakan blok
 * penjelasan. Admin boleh membuka yang pertama tanpa yang kedua. Karena itu
 * kuncinya TIDAK diambil dari `pembahasan` dan tidak pula sebaliknya.
 */

type Pasangan = { kiri: string; kanan: string };

type Jawaban = {
    dipilih: string[];
    teks: string | null;
    rumpang: string[];
    pasangan: Pasangan[];
    urutan: string[];
    uraian: string | null;
    ditandai: boolean;
};

type Kunci = {
    dipilih: string[];
    teks: string | null;
    rumpang: string[];
    pasangan: Pasangan[];
    urutan: string[];
    angka: { nilai: string; toleransi: string } | null;
    uraian: string | null;
};

type Butir = {
    id: number;
    nomor: number;
    jenis?: string;
    jenis_label: string;
    judul: string | null;
    soal: string | null;
    opsi: Record<string, string>;
    jawaban: Jawaban | null;
    kunci: Kunci | null;
    pembahasan: string | null;
    benar: boolean | null;
    skor: number | null;
    bobot: number;
    keadaan: 'benar' | 'salah' | 'dinilai' | 'belum';
};

type Props = {
    ujian: {
        judul: string | null;
        mapel: string | null;
        event: string | null;
        dikumpulkan_label: string | null;
        kedaluwarsa: boolean;
        status_label: string;
    };
    hasil: { persen: number; final: boolean; sisa_uraian: number };
    boleh_kunci: boolean;
    boleh_pembahasan: boolean;
    butir: Butir[];
    ringkasan: { benar: number; salah: number; dinilai: number; belum: number };
};

export default function Riwayat({
    ujian,
    hasil,
    boleh_kunci,
    boleh_pembahasan,
    butir,
    ringkasan,
}: Props) {
    const crumb = [ujian.mapel, ujian.dikumpulkan_label].filter(Boolean).join(' · ');

    return (
        <KerangkaSiswa
            judul="Riwayat jawaban"
            tabbarAktif="nilai"
            kiri={<span className="truncate text-[12px] text-ink">{crumb || 'Riwayat jawaban'}</span>}
            kanan={
                <span className="angka border border-line-2 px-1.5 py-0.5 text-[11px] text-ink-2">
                    {formatPoin(hasil.persen)}
                </span>
            }
        >
            {/* Kepala: ujian mana, dan angka akhirnya. Angka sementara ditandai
                di sini, bukan di setiap butir - yang berubah hanyalah totalnya
                saat uraian selesai dikoreksi. */}
            <section className="daftar overflow-hidden">
                <header className="daftar-kepala">
                    <h2 className="min-w-0 truncate">{ujian.judul ?? 'Ujian'}</h2>
                </header>

                <div className="grid grid-cols-4">
                    <SelAngka label="Benar" nilai={String(ringkasan.benar)} />
                    <SelAngka label="Salah" nilai={String(ringkasan.salah)} />
                    {/* Uraian yang sudah dikoreksi: nilainya ada, benar/salah
                        memang tidak pernah ditetapkan. Menyatukannya dengan
                        "belum dinilai" akan membuat anak menagih pekerjaan
                        yang sudah selesai. */}
                    <SelAngka label="Uraian" nilai={String(ringkasan.dinilai)} />
                    <SelAngka label="Belum dinilai" nilai={String(ringkasan.belum)} akhir />
                </div>

                {!hasil.final && (
                    <p className="border-t border-line px-3 py-2 catatan">
                        {hasil.sisa_uraian > 0
                            ? `${hasil.sisa_uraian} jawaban uraian masih dikoreksi Guru. Angka di atas bisa berubah.`
                            : 'Nilai ini belum final.'}
                    </p>
                )}
            </section>

            {/*
             * Keterangan pembukaan kunci HANYA muncul bila salah satunya
             * terbuka - anak yang tidak menerima kunci tidak perlu membaca
             * tentang kunci.
             */}
            {(boleh_kunci || boleh_pembahasan) && (
                <p className="keterangan">
                    {boleh_pembahasan
                        ? 'Kunci jawaban dan pembahasan dibuka oleh sekolah untuk ujian ini.'
                        : 'Kunci jawaban dibuka oleh sekolah untuk ujian ini; pembahasannya tidak.'}
                </p>
            )}

            {butir.length === 0 ? (
                <p className="keterangan">Tidak ada butir yang tercatat untuk ujian ini.</p>
            ) : (
                butir.map((b) => (
                    <KartuButir
                        key={b.id}
                        butir={b}
                        bolehKunci={boleh_kunci}
                        bolehPembahasan={boleh_pembahasan}
                    />
                ))
            )}

            <Link href="/siswa/nilai" className="tombol w-full">
                Kembali ke nilai
            </Link>
        </KerangkaSiswa>
    );
}

/** Satu sel angka di papan ringkas. Label di atas, angkanya besar. */
function SelAngka({ label, nilai, akhir }: { label: string; nilai: string; akhir?: boolean }) {
    return (
        <div className={'min-w-0 px-2.5 py-2 text-center ' + (akhir ? '' : 'border-r border-line')}>
            <b className="angka-hitung block text-[17px] leading-none text-ink">{nilai}</b>
            <span className="mt-1 block truncate text-[11px] text-ink-2">{label}</span>
        </div>
    );
}

const WARNA_BENAR: Record<string, string> = {
    benar: 'bg-ok-bg text-ok',
    salah: 'bg-bad-bg text-bad',
    dinilai: 'bg-ok-bg text-ok',
    belum: 'bg-surface-2 text-ink-2',
};

const LABEL_KEADAAN: Record<string, string> = {
    benar: 'benar',
    salah: 'salah',
    // Uraian yang sudah dikoreksi: nilainya ada, tetapi "benar" dan "salah"
    // adalah kata yang tidak berlaku untuk jawaban bernilai sebagian.
    dinilai: 'dinilai',
    belum: 'belum dinilai',
};

/** Satu butir: soal, jawaban anak, lalu kunci dan pembahasan bila dibuka. */
function KartuButir({
    butir,
    bolehKunci,
    bolehPembahasan,
}: {
    butir: Butir;
    bolehKunci: boolean;
    bolehPembahasan: boolean;
}) {
    // Keadaannya datang dari server - lihat `RiwayatSiswaController::keadaan()`.
    // Menurunkannya lagi di sini dari `benar` dan `skor` berarti memindahkan
    // aturan yang sama ke tempat kedua.
    const keadaan = butir.keadaan;

    return (
        <article className="daftar overflow-hidden">
            <header className="daftar-kepala">
                <span className="angka shrink-0 text-[12px] text-ink-2">{butir.nomor}</span>
                <span className="shrink-0 text-[11px] text-ink-2">{butir.jenis_label}</span>
                <span
                    className={
                        'ml-auto shrink-0 border border-line-2 px-1.5 py-0.5 text-[11px] font-medium ' +
                        WARNA_BENAR[keadaan]
                    }
                >
                    {LABEL_KEADAAN[keadaan]}
                </span>
            </header>

            <div className="space-y-2.5 px-3 py-3">
                {butir.soal !== null ? (
                    <Html nilai={butir.soal} />
                ) : (
                    <p className="text-[13px] text-ink-2">Soal tidak tersedia.</p>
                )}

                <JawabanAnak butir={butir} />

                {bolehKunci && butir.kunci !== null && <BlokKunci butir={butir} />}

                {bolehPembahasan && butir.pembahasan !== null && (
                    <div className="border border-line-2 bg-surface-2/40 px-2.5 py-2">
                        <span className="mb-1 block text-[11px] text-ink-2">Pembahasan</span>
                        <Html nilai={butir.pembahasan} />
                    </div>
                )}
            </div>
        </article>
    );
}

/**
 * Jawaban anak, dalam bentuk yang paling cocok dengan jenisnya.
 *
 * Yang TIDAK dijawab tetap digambar - sebagai kalimat kosong yang jelas.
 * Butir yang hilang dari daftar adalah butir yang tampak tidak pernah ada,
 * padahal justru bagian yang tidak dikerjakan itulah yang dicari anak.
 */
function JawabanAnak({ butir }: { butir: Butir }) {
    const j = butir.jawaban;

    if (j === null) {
        return <p className="text-[13px] text-ink-2">Tidak dijawab.</p>;
    }

    const adaIsi =
        j.dipilih.length > 0 ||
        (j.teks !== null && j.teks !== '') ||
        j.rumpang.some((r) => r !== '') ||
        j.pasangan.length > 0 ||
        j.urutan.length > 0 ||
        (j.uraian !== null && j.uraian !== '');

    return (
        <div className="space-y-1.5">
            <div className="flex items-center gap-2">
                <span className="text-[11px] text-ink-2">Jawaban Anda</span>
                {j.ditandai && (
                    <span className="border border-line-2 bg-warn-bg px-1.5 py-0.5 text-[11px] text-warn">
                        ditandai ragu
                    </span>
                )}
                {butir.skor !== null && (
                    <span className="angka ml-auto text-[11px] text-ink-2">
                        {formatPoin(butir.skor)} / {formatPoin(butir.bobot)}
                    </span>
                )}
            </div>

            {!adaIsi && <p className="text-[13px] text-ink-2">Kosong.</p>}

            {j.dipilih.length > 0 && (
                <p className="text-[13px] text-ink">
                    {j.dipilih.map((k) => `${k.toUpperCase()}. ${butir.opsi[k] ?? ''}`).join('  ·  ')}
                </p>
            )}

            {j.teks !== null && j.teks !== '' && <p className="text-[13px] text-ink">{j.teks}</p>}

            {j.rumpang.length > 0 && (
                <ol className="space-y-0.5 text-[13px] text-ink">
                    {j.rumpang.map((r, i) => (
                        <li key={i}>
                            <span className="text-ink-2">Lubang {i + 1}: </span>
                            {r === '' ? '—' : r}
                        </li>
                    ))}
                </ol>
            )}

            {j.pasangan.length > 0 && (
                <ul className="space-y-0.5 text-[13px] text-ink">
                    {j.pasangan.map((p, i) => (
                        <li key={i}>
                            {p.kiri} <span className="text-ink-2">→</span>{' '}
                            {p.kanan === '' ? '—' : p.kanan}
                        </li>
                    ))}
                </ul>
            )}

            {j.urutan.length > 0 && (
                <ol className="ml-4 list-decimal space-y-0.5 text-[13px] text-ink">
                    {j.urutan.map((u, i) => (
                        <li key={i}>{u}</li>
                    ))}
                </ol>
            )}

            {j.uraian !== null && j.uraian !== '' && (
                <p className="whitespace-pre-line text-[13px] leading-relaxed text-ink">
                    {j.uraian}
                </p>
            )}
        </div>
    );
}

/** Kunci jawaban - hanya dirender ketika server mengirimnya. */
function BlokKunci({ butir }: { butir: Butir }) {
    const k = butir.kunci;

    if (k === null) {
        return null;
    }

    const bagian: string[] = [];

    if (k.dipilih.length > 0) {
        bagian.push(
            k.dipilih.map((o) => `${o.toUpperCase()}. ${butir.opsi[o] ?? ''}`).join('  ·  '),
        );
    }

    if (k.teks !== null && k.teks !== '') {
        bagian.push(k.teks);
    }

    if (k.angka !== null) {
        bagian.push(
            k.angka.toleransi !== '0'
                ? `${k.angka.nilai} (± ${k.angka.toleransi})`
                : k.angka.nilai,
        );
    }

    // Uraian tidak punya kunci yang bisa ditampilkan - rubriknya tidak
    // pernah keluar dari server. Blok kunci karena itu dilewati seluruhnya.
    if (k.rumpang.length > 0 || k.pasangan.length > 0 || k.urutan.length > 0) {
        if (k.rumpang.length > 0) {
            bagian.push(k.rumpang.map((r, i) => `Lubang ${i + 1}: ${r}`).join(' · '));
        }
        if (k.pasangan.length > 0) {
            bagian.push(k.pasangan.map((p) => `${p.kiri} → ${p.kanan}`).join(' · '));
        }
        if (k.urutan.length > 0) {
            bagian.push(k.urutan.join(' → '));
        }
    }

    if (bagian.length === 0) {
        return null;
    }

    return (
        <div className="border border-line-2 bg-ok-bg px-2.5 py-2">
            <span className="mb-1 block text-[11px] text-ok">Kunci jawaban</span>
            <div className="space-y-0.5 text-[13px] text-ink">
                {bagian.map((b, i) => (
                    <p key={i}>{b}</p>
                ))}
            </div>
        </div>
    );
}
