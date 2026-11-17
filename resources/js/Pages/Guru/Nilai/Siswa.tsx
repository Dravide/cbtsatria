import { Head } from '@inertiajs/react';
import GuruLayout from '@/Layouts/GuruLayout';
import { Chip, Inbox, Kotak, TombolTautan } from '@/Components/ui';
import { formatPoin } from '@/Components/angka';

/**
 * Rincian satu siswa: soal, jawabannya, dan linimasa pengerjaannya.
 *
 * =====================================================================
 * Apa yang benar-benar diketahui, dan tidak dikarang
 * =====================================================================
 * Mesin ujian TIDAK mencatat setiap klik. Yang bertanggal hanya tiga:
 * kapan anak menekan Mulai, kapan tiap jawaban TERAKHIR tersimpan, dan
 * kapan ia mengumpulkan. Jadi linimasa di sini berbunyi "jawaban nomor 7
 * tersimpan 07:34:11", bukan "nomor 7 diklik 07:34:09" - perbedaan tiga
 * detik itu tidak tersimpan di mana pun, dan angka yang dikarang di layar
 * akan dibandingkan dengan jam yang anak ingat.
 *
 * Yang bisa dijawab dengan data ini, dan itu pertanyaan Guru yang
 * sungguhan: butir mana yang dikerjakan paling akhir, berapa lama ia
 * berhenti sebelum butir berikutnya, dan apakah ia mengumpulkan jauh
 * sebelum waktunya.
 *
 * ---------------------------------------------------------------------
 * Jarak antar-simpanan adalah waktu yang ia habiskan di butir BERIKUTNYA
 * ---------------------------------------------------------------------
 * `saved_at` butir 7 adalah saat butir 7 disimpan, bukan saat ia mulai
 * membacanya. Karena itu kolom "menit ke-" di linimasa dibaca sebagai
 * "pada menit ini jawaban itu masuk", dan jarak ke baris berikutnya adalah
 * perkiraan waktu yang ia pakai untuk butir sesudahnya. Laman ini
 * menuliskannya apa adanya, bukan sebagai "durasi per soal" - itu angka
 * yang tidak pernah diukur.
 */

type Butir = {
    id: number;
    nomor: number;
    jenis?: string;
    jenis_label: string;
    judul: string | null;
    soal: string | null;
    opsi: Record<string, string>;
    jawaban: {
        dipilih?: string[];
        teks?: string | null;
        rumpang?: string[];
        pasangan?: { kiri: string; kanan: string }[];
        urutan?: string[];
        uraian?: string | null;
    } | null;
    kunci: {
        dipilih?: string[];
        teks?: string | null;
        rumpang?: string[];
        pasangan?: { kiri: string; kanan: string }[];
        urutan?: string[];
    } | null;
    benar: boolean | null;
    skor: number | null;
    bobot: number;
    keadaan: 'benar' | 'salah' | 'dinilai' | 'belum';
    tersimpan: string | null;
    tersimpan_label: string | null;
    versi: number | null;
    ditandai: boolean;
};

type Kejadian = {
    jenis: 'mulai' | 'jawab' | 'kumpul';
    label: string;
    pada: string;
    jam: string;
    detik: number | null;
    nomor: number | null;
    keadaan?: string;
};

type Props = {
    ujian: { id: number; judul: string; kode: string | null; mapel: string | null };
    siswa: { peserta_id: number; nama: string | null; nis: string | null; kelas: string | null };
    pengerjaan: {
        id: number;
        nomor_attempt: number;
        status: string;
        status_label: string;
        mulai: string | null;
        mulai_label: string | null;
        batas: string | null;
        dikumpulkan: string | null;
        dikumpulkan_label: string | null;
        terakhir_terlihat: string | null;
        pelanggaran: number;
        terberat: number;
        terkunci: boolean;
        kunci_alasan: string | null;
        durasi_detik: number | null;
    } | null;
    hasil: {
        objektif: number;
        uraian: number | null;
        nilai: number;
        final: boolean;
        sisa_uraian: number;
        status: string;
        status_label: string;
        lulus: boolean | null;
    } | null;
    kkm: number;
    butir: Butir[];
    linimasa: Kejadian[];
    jalur_ujian: string;
};

export default function NilaiSiswa({
    ujian,
    siswa,
    pengerjaan,
    hasil,
    kkm,
    butir,
    linimasa,
    jalur_ujian,
}: Props) {
    const terjawab = butir.filter((b) => b.jawaban !== null).length;

    return (
        <GuruLayout
            judul={`Rincian ${siswa.nama ?? 'siswa'}`}
            breadcrumb={`Nilai · ${ujian.mapel ?? ujian.judul} · ${siswa.nama ?? 'Siswa'}`}
            aksi={
                <>
                    <TombolTautan href={jalur_ujian}>Kembali ke nilai kelas</TombolTautan>
                    <span className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink-2">
                        <span className="text-ink-3">Ujian:</span>
                        <span className="font-medium text-ink">{ujian.mapel ?? ujian.judul}</span>
                    </span>
                </>
            }
        >
            <Head title={`Nilai · ${siswa.nama ?? 'Siswa'}`} />

            {/* ---------------------------------------------- identitas */}
            <div className="mb-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span className="font-display text-base font-semibold text-ink">
                    {siswa.nama ?? 'Siswa tidak dikenal'}
                </span>
                {siswa.nis && (
                    <span className="font-mono text-xs text-ink-2">{siswa.nis}</span>
                )}
                {siswa.kelas && <span className="text-xs text-ink-3">{siswa.kelas}</span>}
                {pengerjaan && pengerjaan.nomor_attempt > 1 && (
                    // Percobaan kedua berarti yang dibaca di bawah adalah
                    // percobaan KEDUA - Guru perlu tahu itu sebelum
                    // membandingkannya dengan riwayat di kepala.
                    <Chip nada="warn">percobaan ke-{pengerjaan.nomor_attempt}</Chip>
                )}
                {pengerjaan?.terkunci && <Chip nada="bad">terkunci</Chip>}
            </div>

            {pengerjaan === null ? (
                <Kotak>
                    <Inbox>
                        Peserta ini belum pernah menekan Mulai pada ujian ini, sehingga tidak
                        ada jawaban maupun linimasa yang bisa ditampilkan.
                    </Inbox>
                </Kotak>
            ) : (
                <div className="space-y-3">
                    {/* ---------------------------------------------- KPI */}
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                        <KPI label="Nilai" nilai={hasil ? formatPoin(hasil.nilai) : '—'} />
                        <KPI
                            label="Objektif"
                            nilai={hasil ? formatPoin(hasil.objektif) : '—'}
                        />
                        <KPI
                            label="Uraian"
                            nilai={
                                hasil === null
                                    ? '—'
                                    : hasil.uraian === null
                                      ? 'belum'
                                      : formatPoin(hasil.uraian)
                            }
                        />
                        <KPI
                            label="Terjawab"
                            nilai={`${terjawab}/${butir.length}`}
                        />
                        <KPI
                            label="Pelanggaran"
                            nilai={String(pengerjaan.pelanggaran)}
                            nada={pengerjaan.pelanggaran > 0 ? 'warn' : undefined}
                        />
                        <KPI
                            label="Dikerjakan"
                            nilai={formatDurasi(pengerjaan.durasi_detik)}
                        />
                        <KPI
                            label="Status"
                            nilai={
                                hasil === null
                                    ? '—'
                                    : hasil.lulus === null
                                      ? 'belum'
                                      : hasil.lulus
                                        ? 'tuntas'
                                        : 'remedial'
                            }
                            nada={hasil?.lulus === false ? 'warn' : undefined}
                        />
                    </div>

                    {hasil && (
                        <p className="px-1 text-[11px] text-ink-3">
                            KKM sekolah {kkm}. Status &ldquo;belum&rdquo; berarti masih ada
                            uraian yang dikoreksi, bukan remedial.
                        </p>
                    )}

                    {hasil && !hasil.final && (
                        <Inbox>
                            <strong>{hasil.sisa_uraian}</strong> uraiannya belum dikoreksi.
                            Kolom Uraian dan status tuntas di laman ini belum bisa dibaca;
                            angka Nilai memakai persentase sementara.
                        </Inbox>
                    )}

                    {pengerjaan.kunci_alasan && (
                        <Inbox>Dikunci: {pengerjaan.kunci_alasan}</Inbox>
                    )}

                    {/* ------------------------------------ waktu pengerjaan */}
                    <Kotak judul="Waktu pengerjaan">
                        <div className="grid gap-2 text-xs text-ink-2 sm:grid-cols-2 lg:grid-cols-4">
                            <Baris label="Mulai" nilai={pengerjaan.mulai_label ?? '—'} />
                            <Baris label="Batas" nilai={pengerjaan.batas ?? '—'} />
                            <Baris
                                label="Dikumpulkan"
                                nilai={pengerjaan.dikumpulkan_label ?? 'belum dikumpulkan'}
                            />
                            <Baris
                                label="Terakhir terlihat"
                                nilai={pengerjaan.terakhir_terlihat ?? '—'}
                            />
                        </div>
                        <p className="mt-2 text-[11px] leading-relaxed text-ink-3">
                            Status: <strong>{pengerjaan.status_label}</strong>. Durasi di atas
                            adalah jam dinding sejak Mulai sampai dikumpulkan &mdash; mesin ujian
                            tidak mencatat akumulasi waktu aktif, jadi waktu yang ia tinggalkan
                            (mis. jaringan putus) tetap terhitung.
                        </p>
                    </Kotak>

                    {/* ---------------------------------------- linimasa */}
                    <Kotak judul={`Linimasa (${linimasa.length} kejadian)`}>
                        <p className="mb-2 text-[11px] leading-relaxed text-ink-3">
                            Yang tersimpan hanya <strong>penyimpanan terakhir</strong> tiap butir
                            &mdash; bukan setiap klik. Satu butir yang disunting dua kali muncul
                            sekali, dengan jam simpanan terbarunya.
                        </p>

                        {linimasa.length === 0 ? (
                            <p className="py-4 text-center text-xs text-ink-3">
                                Tidak ada kejadian bertanggal untuk pengerjaan ini.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[26rem] text-sm">
                                    <thead>
                                        <tr className="border-b border-line text-left">
                                            <Th className="w-20">Jam</Th>
                                            <Th className="w-24">Menit ke-</Th>
                                            <Th>Kejadian</Th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {linimasa.map((k, i) => (
                                            <tr
                                                key={`${k.jenis}-${k.jam}-${k.nomor ?? i}`}
                                                className="border-b border-line/50 last:border-0"
                                            >
                                                <td className="px-3 py-1.5 font-mono text-xs text-ink-2">
                                                    {k.jam}
                                                </td>
                                                <td className="px-3 py-1.5 font-mono text-xs text-ink-3">
                                                    {k.detik === null
                                                        ? '—'
                                                        : formatMenit(k.detik)}
                                                </td>
                                                <td className="px-3 py-1.5 text-xs text-ink">
                                                    <span className="flex items-center gap-2">
                                                        <span
                                                            className={
                                                                'inline-block size-1.5 shrink-0 rounded-full ' +
                                                                (k.jenis === 'mulai'
                                                                    ? 'bg-info'
                                                                    : k.jenis === 'kumpul'
                                                                      ? 'bg-ok'
                                                                      : keadaanTitik(k.keadaan))
                                                            }
                                                            aria-hidden="true"
                                                        />
                                                        {k.label}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Kotak>

                    {/* ------------------------------------------ butir */}
                    <Kotak judul={`Soal dan jawaban (${butir.length} butir)`}>
                        {butir.length === 0 ? (
                            <p className="py-4 text-center text-xs text-ink-3">
                                Ujian ini tidak punya butir pada pengerjaan tersebut.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {butir.map((b) => (
                                    <ButirSoal key={b.id} butir={b} />
                                ))}
                            </div>
                        )}
                    </Kotak>

                    <p className="px-1 text-[11px] leading-relaxed text-ink-3">
                        Laman ini adalah konsol Guru, sehingga kunci jawaban ikut tampil.
                        Halaman siswa tidak memuatnya kecuali Admin membuka kunci dan
                        pembahasan di laman publikasi hasil.
                    </p>
                </div>
            )}
        </GuruLayout>
    );
}

/* ---------------------------------------------------------------- Butir */

function ButirSoal({ butir: b }: { butir: Butir }) {
    const nada =
        b.keadaan === 'benar'
            ? 'ok'
            : b.keadaan === 'salah'
              ? 'bad'
              : b.keadaan === 'dinilai'
                ? 'info'
                : 'mute';

    return (
        <div className="rounded-wf border border-line/70 bg-surface px-3 py-2">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono text-xs font-medium text-ink-2">
                    {String(b.nomor).padStart(2, '0')}
                </span>
                <Chip nada="mute">{b.jenis_label}</Chip>
                {b.ditandai && <Chip nada="warn">ditandai ragu</Chip>}

                <span className="ml-auto flex items-center gap-2">
                    {b.tersimpan_label && (
                        <span className="font-mono text-[11px] text-ink-3">
                            tersimpan {b.tersimpan_label}
                        </span>
                    )}
                    <span className="font-mono text-[11px] text-ink-3">
                        {b.skor === null ? '—' : formatPoin(b.skor)}/{formatPoin(b.bobot)}
                    </span>
                    <Chip nada={nada}>{labelKeadaan(b.keadaan)}</Chip>
                </span>
            </div>

            {b.soal && (
                <p className="mt-1.5 text-sm leading-relaxed text-ink">{b.soal}</p>
            )}

            {Object.keys(b.opsi).length > 0 && (
                <ul className="mt-1.5 space-y-0.5">
                    {Object.entries(b.opsi).map(([kunci, teks]) => {
                        const dipilih = b.jawaban?.dipilih?.includes(kunci) ?? false;
                        const benar = b.kunci?.dipilih?.includes(kunci) ?? false;

                        return (
                            <li
                                key={kunci}
                                className={
                                    'flex items-start gap-2 rounded px-2 py-1 text-xs ' +
                                    (benar
                                        ? 'bg-ok-bg/60 text-ink'
                                        : dipilih
                                          ? 'bg-bad-bg/50 text-ink'
                                          : 'text-ink-2')
                                }
                            >
                                <span className="font-mono text-[11px] text-ink-3">
                                    {kunci}
                                </span>
                                <span className="min-w-0 flex-1">{teks}</span>
                                {dipilih && (
                                    <span className="shrink-0 text-[11px] text-ink-3">
                                        dijawab
                                    </span>
                                )}
                                {benar && (
                                    <span className="shrink-0 text-[11px] text-ok">kunci</span>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}

            {b.jawaban?.teks && (
                <p className="mt-1.5 text-xs text-ink">
                    <span className="text-ink-3">Dijawab: </span>
                    {b.jawaban.teks}
                </p>
            )}

            {b.jawaban?.uraian && (
                <p className="mt-1.5 whitespace-pre-line rounded-wf border border-line/60 bg-surface-2/40 px-2.5 py-2 text-xs leading-relaxed text-ink">
                    {b.jawaban.uraian}
                </p>
            )}

            {b.jawaban?.rumpang && b.jawaban.rumpang.length > 0 && (
                <p className="mt-1.5 text-xs text-ink">
                    <span className="text-ink-3">Rumpang: </span>
                    {b.jawaban.rumpang.map((r, i) => `${i + 1}. ${r || '—'}`).join(' · ')}
                </p>
            )}

            {b.jawaban?.pasangan && b.jawaban.pasangan.length > 0 && (
                <ul className="mt-1.5 space-y-0.5">
                    {b.jawaban.pasangan.map((p, i) => (
                        <li key={i} className="text-xs text-ink-2">
                            {p.kiri} → {p.kanan}
                        </li>
                    ))}
                </ul>
            )}

            {b.jawaban?.urutan && b.jawaban.urutan.length > 0 && (
                <p className="mt-1.5 text-xs text-ink">
                    <span className="text-ink-3">Urutan: </span>
                    {b.jawaban.urutan.join(' → ')}
                </p>
            )}

            {b.jawaban === null && (
                <p className="mt-1.5 text-[11px] text-ink-3 italic">Tidak dijawab.</p>
            )}

            {/* Kunci untuk jenis yang tidak berbentuk pilihan. */}
            {b.kunci &&
                b.kunci.dipilih === undefined &&
                (b.kunci.teks || (b.kunci.rumpang && b.kunci.rumpang.length > 0)) && (
                    <p className="mt-1.5 text-[11px] text-ok">
                        Kunci:{' '}
                        {b.kunci.teks ??
                            b.kunci.rumpang?.map((r, i) => `${i + 1}. ${r}`).join(' · ')}
                    </p>
                )}
        </div>
    );
}

/* ----------------------------------------------------------- potongan */

function KPI({
    label,
    nilai,
    nada,
}: {
    label: string;
    nilai: string;
    nada?: 'warn' | 'bad';
}) {
    const warna = nada === 'warn' ? 'text-warn' : nada === 'bad' ? 'text-bad' : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-3 py-2">
            <span className="block text-[11px] text-ink-3">{label}</span>
            <span className={'mt-0.5 block font-mono text-lg font-semibold ' + warna}>
                {nilai}
            </span>
        </div>
    );
}

function Baris({ label, nilai }: { label: string; nilai: string }) {
    return (
        <div>
            <span className="block text-[11px] text-ink-3">{label}</span>
            <span className="font-mono text-xs text-ink">{nilai}</span>
        </div>
    );
}

function Th({ children, className }: { children: React.ReactNode; className?: string }) {
    return (
        <th className={'px-3 py-2 text-[11px] font-medium text-ink-3 ' + (className ?? '')}>
            {children}
        </th>
    );
}

function labelKeadaan(k: Butir['keadaan']): string {
    return k === 'benar'
        ? 'benar'
        : k === 'salah'
          ? 'salah'
          : k === 'dinilai'
            ? 'dinilai'
            : 'belum dinilai';
}

function keadaanTitik(k?: string): string {
    return k === 'benar' ? 'bg-ok' : k === 'salah' ? 'bg-bad' : k === 'dinilai' ? 'bg-info' : 'bg-ink-3/40';
}

/** Durasi dari detik: "1 jam 12 menit" · "12 menit" · "48 detik". */
function formatDurasi(detik: number | null): string {
    if (detik === null) {
        return '—';
    }

    const jam = Math.floor(detik / 3600);
    const menit = Math.floor((detik % 3600) / 60);

    if (jam > 0) {
        return `${jam}j ${menit}m`;
    }

    if (menit > 0) {
        return `${menit} menit`;
    }

    return `${detik} dtk`;
}

/** Detik sejak Mulai sebagai "menit ke-12" - dibaca sebagai cerita. */
function formatMenit(detik: number): string {
    const menit = Math.floor(detik / 60);
    const sisa = detik % 60;

    return `${menit}m ${String(sisa).padStart(2, '0')}d`;
}
