import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import GuruLayout from '@/Layouts/GuruLayout';
import { Chip, Inbox, Paginator, Peringatan, Tabel, Tombol } from '@/Components/ui';
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
    /** Sekolah sudah mengatur AI - tombol usulan hanya muncul bila true. */
    ai_siap: boolean;
    /** Seluruh id jawaban yang belum dikoreksi pada ujian ini. */
    sisa_id: number[];
};

/** Satu usulan AI untuk satu jawaban, dalam pratinjau batch. */
type UsulanBaris = {
    jawaban_id: number;
    siswa: string | null;
    nomor: number | null;
    usulan: {
        kriteria: { kriteria: string; maks: number; poin: number }[];
        total: number;
        bobot: number;
        catatan: string | null;
    } | null;
    galat: string | null;
};

export default function KoreksiUjian({ ujian, ringkas, baris, ai_siap, sisa_id }: Props) {
    // Baris pertama yang belum dinilai - tombol "Mulai koreksi" harus membuka
    // SATU jawaban, bukan daftar yang sama dengan yang sedang dilihat.
    const pertama = baris.data.find((b) => !b.sudah_dinilai) ?? baris.data[0];

    // --------------------------------------------------------------- AI batch
    //
    // Pratinjau usulan untuk SEKELOMPOK jawaban - satu halaman, atau seluruh
    // sisa antrean. Tetap pratinjau: yang tersimpan hanya yang dicentang Guru,
    // lewat `aiSimpan`. Server membatasi sepuluh jawaban per permintaan, jadi
    // laman mengirim batch sepuluh-sepuluh dan menampilkan kemajuannya.
    const [minta, setMinta] = useState(false);
    const [kemajuan, setKemajuan] = useState<{ jadi: number; dari: number } | null>(null);
    const [galat, setGalat] = useState<string | null>(null);
    const [usulan, setUsulan] = useState<UsulanBaris[]>([]);
    const [pilih, setPilih] = useState<number[]>([]);

    const belumDinilaiPage = baris.data.filter((b) => !b.sudah_dinilai);

    const mintaUsulan = async (mode: 'halaman' | 'semua') => {
        if (minta) {
            return;
        }

        // "Halaman" = yang belum dinilai di halaman ini; "Semua" = seluruh
        // sisa antrean ujian. Keduanya daftar id, dan dikirim per sepuluh.
        const id = mode === 'halaman' ? belumDinilaiPage.map((b) => b.id) : sisa_id;

        if (id.length === 0) {
            setGalat('Tidak ada jawaban yang belum dinilai untuk diusulkan.');
            return;
        }

        setMinta(true);
        setGalat(null);
        setUsulan([]);
        setPilih([]);
        setKemajuan({ jadi: 0, dari: id.length });

        try {
            const kumpulan: UsulanBaris[] = [];

            for (let i = 0; i < id.length; i += 10) {
                const potong = id.slice(i, i + 10);

                const jawab = await fetch(`/guru/koreksi/${ujian.id}/ai`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN':
                            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                                ?.content ?? '',
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ jawaban: potong }),
                });

                const isi = await jawab.json();

                if (!jawab.ok) {
                    setGalat(isi.pesan ?? 'Usulan AI tidak bisa diambil.');
                    // Yang sudah terkumpul tetap ditampilkan: membuang tiga
                    // batch yang berhasil karena batch keempat kena jatah
                    // adalah membuang pekerjaan yang sudah ditagih.
                    setUsulan(kumpulan);
                    setPilih(kumpulan.filter((u) => u.usulan !== null).map((u) => u.jawaban_id));
                    return;
                }

                kumpulan.push(...isi.hasil);
                setKemajuan({ jadi: kumpulan.length, dari: id.length });
            }

            setUsulan(kumpulan);
            // Yang berhasil diusulkan langsung tercentang: Guru membatalkan
            // yang tidak ia setujui, bukan mencentang yang ia setujui - pada
            // tiga puluh baris, itu bedanya tiga puluh klik.
            setPilih(kumpulan.filter((u) => u.usulan !== null).map((u) => u.jawaban_id));
        } catch {
            setGalat('Tidak bisa menghubungi server. Periksa sambungan, lalu coba lagi.');
        } finally {
            setMinta(false);
            setKemajuan(null);
        }
    };

    const simpan = () => {
        if (minta || pilih.length === 0) {
            return;
        }

        const kiriman = usulan
            .filter((u) => u.usulan !== null && pilih.includes(u.jawaban_id))
            .map((u) => ({
                jawaban_id: u.jawaban_id,
                kriteria: u.usulan!.kriteria.map((k) => ({ kriteria: k.kriteria, poin: k.poin })),
                feedback: u.usulan!.catatan,
            }));

        router.post(`/guru/koreksi/${ujian.id}/ai/simpan`, { koreksi: kiriman }, {
            preserveScroll: true,
            onSuccess: () => {
                setUsulan([]);
                setPilih([]);
            },
        });
    };

    const totalPilih = usulan.filter((u) => pilih.includes(u.jawaban_id)).length;

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

            {/* ------------------------------------------------- Koreksi AI */}
            {/* Pratinjau, bukan tombol ajaib. Guru memilih cakupannya, melihat
                usulan per jawaban, membatalkan yang tidak ia setujui, lalu
                menyimpannya - dan yang tersimpan lewat jalur yang sama dengan
                koreksi manual. */}
            {ai_siap && ringkas.sisa > 0 && (
                <section className="mb-3 rounded-wf border border-line bg-surface">
                    <header className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2.5">
                        <h2 className="text-xs font-medium tracking-wide text-ink-2 uppercase">
                            Koreksi dengan AI
                        </h2>
                        <span className="ml-auto flex flex-wrap items-center gap-2">
                            <Tombol
                                nada="sek"
                                disabled={minta || belumDinilaiPage.length === 0}
                                onClick={() => mintaUsulan('halaman')}
                            >
                                Halaman ini ({belumDinilaiPage.length})
                            </Tombol>
                            <Tombol
                                nada="sek"
                                disabled={minta || sisa_id.length === 0}
                                onClick={() => mintaUsulan('semua')}
                            >
                                Semua ({sisa_id.length})
                            </Tombol>
                        </span>
                    </header>

                    <div className="px-4 py-3">
                        {minta && (
                            <p className="text-xs text-ink-2">
                                Menilai dengan AI…{' '}
                                {kemajuan && (
                                    <span className="font-mono">
                                        {kemajuan.jadi}/{kemajuan.dari}
                                    </span>
                                )}
                            </p>
                        )}

                        {galat && (
                            <div className="mt-2">
                                <Peringatan>{galat}</Peringatan>
                            </div>
                        )}

                        {!minta && usulan.length === 0 && !galat && (
                            <p className="text-xs text-ink-3">
                                AI mengusulkan poin per kriteria untuk tiap jawaban. Usulan
                                ditampilkan sebagai pratinjau - <strong>tidak ada</strong> yang
                                tersimpan sampai Anda menyetujuinya.
                            </p>
                        )}

                        {usulan.length > 0 && (
                            <>
                                <div className="mb-2 flex flex-wrap items-center gap-2 text-xs text-ink-2">
                                    <span>
                                        {totalPilih} dari {usulan.length} usulan dipilih
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setPilih(
                                                usulan
                                                    .filter((u) => u.usulan !== null)
                                                    .map((u) => u.jawaban_id),
                                            )
                                        }
                                        className="text-ink-3 underline-offset-2 hover:text-ink hover:underline"
                                    >
                                        Pilih semua
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setPilih([])}
                                        className="text-ink-3 underline-offset-2 hover:text-ink hover:underline"
                                    >
                                        Kosongkan
                                    </button>
                                    <span className="ml-auto">
                                        <Tombol
                                            nada="pri"
                                            disabled={pilih.length === 0}
                                            onClick={simpan}
                                        >
                                            Simpan {totalPilih} koreksi
                                        </Tombol>
                                    </span>
                                </div>

                                <div className="overflow-hidden rounded-wf border border-line">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-line bg-surface-2/50 text-left text-[11px] text-ink-3">
                                                <th className="w-8 px-3 py-2"></th>
                                                <th className="w-14 px-3 py-2">No</th>
                                                <th className="px-3 py-2">Siswa</th>
                                                <th className="px-3 py-2">Usulan poin</th>
                                                <th className="px-3 py-2">Catatan</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {usulan.map((u) => (
                                                <tr
                                                    key={u.jawaban_id}
                                                    className="border-b border-line last:border-0 align-top"
                                                >
                                                    <td className="px-3 py-2">
                                                        <input
                                                            type="checkbox"
                                                            disabled={u.usulan === null}
                                                            checked={pilih.includes(u.jawaban_id)}
                                                            onChange={(e) =>
                                                                setPilih(
                                                                    e.target.checked
                                                                        ? [...pilih, u.jawaban_id]
                                                                        : pilih.filter(
                                                                              (x) => x !== u.jawaban_id,
                                                                          ),
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2 font-mono text-xs text-ink-2">
                                                        {u.nomor}
                                                    </td>
                                                    <td className="px-3 py-2 text-ink">
                                                        {u.siswa ?? 'Siswa tidak dikenal'}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {u.galat ? (
                                                            <Chip nada="bad">gagal</Chip>
                                                        ) : (
                                                            <span className="font-mono text-xs text-ink">
                                                                {formatPoin(u.usulan!.total)} /{' '}
                                                                {formatPoin(u.usulan!.bobot)}
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2 text-[11px] text-ink-3">
                                                        {u.galat ?? u.usulan!.catatan ?? '—'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </>
                        )}
                    </div>
                </section>
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
