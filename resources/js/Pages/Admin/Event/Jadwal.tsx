import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { konfirmasi } from '@/Components/dialog';
import {
    JadwalEvent,
    KPI,
    KelasEvent,
    MapelEvent,
    PemilihEvent,
    TemuanJadwal,
    Ujian,
    ValidasiItem,
    ValidasiStrip,
} from '@/Components/JadwalKomponen';
import {
    DaftarCentangCari,
    Field,
    Inbox,
    Kotak,
    Peringatan,
    PilihCari,
    Tombol,
    kelasInput,
} from '@/Components/ui';

type Props = {
    event: JadwalEvent;
    temuan: TemuanJadwal[];
    validasi: ValidasiItem[];
    ujian: Ujian[];
    kelas: KelasEvent[];
    mapel: MapelEvent[];
    angka: { ujian: number; sesi: number; konflik: number; belum_ditempatkan: number };
};

export default function EventJadwal({
    event,
    temuan,
    validasi,
    ujian,
    kelas,
    mapel,
    angka,
}: Props) {
    const [panelUjian, setPanelUjian] = useState(false);
    const [pesanUjian, setPesanUjian] = useState<string | null>(null);
    const [ujianDiubah, setUjianDiubah] = useState<Ujian | null>(null);

    const jalur = `/admin/events/${event.id}`;

    return (
        <AdminLayout
            judul="Jadwal & ujian"
            breadcrumb={`/admin/events · ${event.kode} · Jadwal`}
            aksi={
                <>
                    <PemilihEvent event={event} />

                    <Tombol
                        onClick={() => router.post(`${jalur}/jadwal/deteksi`, {}, { preserveScroll: true })}
                        title="Periksa ulang jadwal dan laporkan hasilnya"
                    >
                        Deteksi konflik
                    </Tombol>

                    <Tombol nada="pri" onClick={() => setPanelUjian(true)}>
                        Tambah ujian
                    </Tombol>
                </>
            }
        >
            <Head title={`Jadwal · ${event.nama}`} />

            <div className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <KPI label="Ujian" nilai={angka.ujian} />
                <KPI label="Sesi" nilai={angka.sesi} />
                <KPI
                    label="Konflik jadwal"
                    nilai={angka.konflik}
                    nada={angka.konflik > 0 ? 'bad' : 'ok'}
                />
                <KPI
                    label="Belum ditempatkan"
                    nilai={angka.belum_ditempatkan}
                    nada={angka.belum_ditempatkan > 0 ? 'warn' : 'ok'}
                />
            </div>

            <ValidasiStrip validasi={validasi} adaTemuan={temuan.length > 0} />

            {temuan.length > 0 && (
                <div className="mt-2 space-y-2">
                    {temuan.slice(0, 4).map((t, i) => (
                        <div
                            key={`${t.jenis}-${i}`}
                            className="rounded-wf border border-bad/30 bg-bad-bg px-3 py-2.5 text-xs leading-relaxed text-bad"
                        >
                            {t.pesan}
                        </div>
                    ))}
                    {temuan.length > 4 && (
                        <p className="text-xs text-ink-3">
                            +{temuan.length - 4} temuan lain. Buka <em>Kelola sesi</em> pada ujian terkait untuk memperbaikinya.
                        </p>
                    )}
                </div>
            )}

            {panelUjian && (
                <div className="mt-4">
                    <FormUjian
                        jalur={jalur}
                        mapel={mapel}
                        kelas={kelas}
                        jenisEvent={event.jenis}
                        padaTutup={() => setPanelUjian(false)}
                        padaGalat={setPesanUjian}
                    />
                </div>
            )}

            {ujianDiubah && (
                <div className="mt-4">
                    <FormUjian
                        key={ujianDiubah.id}
                        jalur={jalur}
                        ujianAwal={ujianDiubah}
                        mapel={mapel}
                        kelas={kelas}
                        jenisEvent={event.jenis}
                        padaTutup={() => setUjianDiubah(null)}
                        padaGalat={setPesanUjian}
                    />
                </div>
            )}

            {pesanUjian && (
                <div className="mt-4">
                    <Peringatan>{pesanUjian}</Peringatan>
                </div>
            )}

            <div className="mt-3 overflow-hidden rounded-wf border border-line bg-surface">
                <header className="flex items-center justify-between border-b border-line bg-surface-2/40 px-4 py-2.5">
                    <h2 className="font-display text-sm font-semibold text-ink">
                        Daftar ujian ({ujian.length})
                    </h2>
                    <span className="text-xs text-ink-3">
                        Pilih <em>Kelola sesi</em> untuk mengatur jadwal dan ruang per ujian
                    </span>
                </header>

                {ujian.length === 0 ? (
                    <p className="px-4 py-8 text-center text-sm text-ink-3">
                        Belum ada ujian. Tekan <em>Tambah ujian</em> untuk membuatnya.
                    </p>
                ) : (
                    <ul className="divide-y divide-line">
                        {ujian.map((u) => (
                            <li
                                key={u.id}
                                className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"
                            >
                                <span className="font-mono text-xs text-ink-3">{u.kode}</span>
                                <span className="font-medium text-ink">{u.mapel ?? u.judul}</span>

                                {u.tingkat.length > 0 ? (
                                    <span className="font-mono text-[11px] text-ink-2">
                                        {u.tingkat.length === 1
                                            ? `Tingkat ${u.tingkat[0]}`
                                            : `Tingkat ${u.tingkat.join(', ')}`}
                                    </span>
                                ) : (
                                    <span className="text-[11px] text-ink-3">semua kelas</span>
                                )}

                                <span className="ml-auto font-mono text-xs text-ink-3">
                                    {u.durasi} mnt
                                </span>
                                <span className="font-mono text-xs text-ink-3">{u.sesi} sesi</span>

                                <Link href={`${jalur}/jadwal/${u.id}/sesi`}>
                                    <Tombol nada="pri">Kelola sesi</Tombol>
                                </Link>

                                <Tombol
                                    nada="sek"
                                    title="Ubah judul, durasi, kelas sasaran, dan perilaku ujian"
                                    onClick={() => setUjianDiubah(u)}
                                >
                                    Ubah
                                </Tombol>

                                <Tombol
                                    nada="bahaya"
                                    title="Hapus ujian beserta jadwalnya"
                                    onClick={async () => {
                                        const setuju = await konfirmasi({
                                            judul: `Hapus ${u.kode} ${u.judul}?`,
                                            pesan: 'Seluruh sesinya yang belum berjalan ikut terhapus.',
                                            labelYa: 'Hapus',
                                            nada: 'bahaya',
                                        });

                                        if (setuju) {
                                            router.delete(`${jalur}/ujian/${u.id}`, {
                                                preserveScroll: true,
                                            });
                                        }
                                    }}
                                >
                                    Hapus
                                </Tombol>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="mt-3">
                <Inbox>
                    Buat ujian per mata pelajaran di sini terlebih dahulu, lalu tekan{' '}
                    <strong>Kelola sesi</strong> untuk menentukan tanggal, jam, ruang, serta
                    menyiapkan atau membuka sesinya.
                </Inbox>
            </div>
        </AdminLayout>
    );
}

function FormUjian({
    jalur,
    ujianAwal,
    mapel,
    kelas,
    jenisEvent,
    padaTutup,
    padaGalat,
}: {
    jalur: string;
    ujianAwal?: Ujian;
    mapel: MapelEvent[];
    kelas: KelasEvent[];
    jenisEvent: string | null;
    padaTutup: () => void;
    padaGalat: (pesan: string | null) => void;
}) {
    const mengubah = ujianAwal !== undefined;

    const [isi, setIsi] = useState(() => ({
        subject_id: ujianAwal?.mapel_id ?? mapel[0]?.id ?? 0,
        title: ujianAwal?.judul ?? '',
        duration_minutes: ujianAwal?.durasi ?? 60,
        min_duration_minutes: ujianAwal?.minimal ?? null,
        max_attempts: ujianAwal?.percobaan ?? 1,
        instructions: ujianAwal?.instruksi ?? '',
        allow_back_navigation: ujianAwal?.boleh_kembali ?? true,
        shuffle_questions: ujianAwal?.acak_soal ?? false,
        shuffle_options: ujianAwal?.acak_opsi ?? false,
        allow_late_entry: ujianAwal?.boleh_terlambat ?? false,
    }));

    const [pilihKelas, setPilihKelas] = useState<number[]>(ujianAwal?.kelas_id ?? []);

    const mapelUnik = (() => {
        const peta = new Map<string, { nama: string; kode: string | null; varian: MapelEvent[] }>();

        for (const m of mapel) {
            const nama = (m.nama ?? m.kode ?? '').trim();

            if (nama === '') {
                continue;
            }

            const ada = peta.get(nama) ?? { nama, kode: m.kode, varian: [] };
            ada.varian.push(m);
            peta.set(nama, ada);
        }

        return [...peta.values()].sort((a, b) => a.nama.localeCompare(b.nama));
    })();

    const mapelAktif =
        mapelUnik.find((m) => m.varian.some((v) => v.id === isi.subject_id)) ??
        mapelUnik[0] ??
        null;

    const varianTingkat = mapelAktif?.varian ?? [];

    const tingkatSasaran = (() => {
        return [
            ...new Set(
                pilihKelas
                    .map((id) => kelas.find((k) => k.id === id)?.tingkat)
                    .filter((x): x is string => Boolean(x && x.trim())),
            ),
        ].sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    })();

    useEffect(() => {
        if (varianTingkat.length <= 1) {
            return;
        }

        const cocok = varianTingkat.find(
            (v) => v.tingkat !== null && tingkatSasaran.includes(v.tingkat),
        );

        const target = cocok ?? varianTingkat[0];

        if (target !== undefined && target.id !== isi.subject_id) {
            setIsi((lama) => ({ ...lama, subject_id: target.id }));
        }
    }, [tingkatSasaran.join(','), mapelAktif?.nama]);

    const pilihMapel = (nama: string) => {
        const m = mapelUnik.find((x) => x.nama === nama);

        if (m === undefined || m.varian[0] === undefined) {
            return;
        }

        setIsi({ ...isi, subject_id: m.varian[0].id });
    };

    const namaMapelTerpilih = mapelAktif?.nama ?? '';

    const tingkatTerpilih = (() => {
        if (pilihKelas.length === 0) {
            return null;
        }

        const tingkat = [
            ...new Set(
                pilihKelas
                    .map((id) => kelas.find((k) => k.id === id)?.tingkat)
                    .filter((t): t is string => Boolean(t && t.trim())),
            ),
        ];

        return tingkat.length === 1 ? tingkat[0] : null;
    })();

    const simpan = () => {
        padaGalat(null);

        const opsi = {
            preserveScroll: true,
            onSuccess: () => padaTutup(),
            onError: (e: Record<string, string>) =>
                padaGalat(Object.values(e)[0] ?? 'Ujian tidak bisa disimpan.'),
        };

        if (mengubah) {
            router.put(`${jalur}/ujian/${ujianAwal.id}`, { ...isi, class_id: pilihKelas }, opsi);

            return;
        }

        const { title: _judulLama, ...tanpaJudul } = isi;

        router.post(`${jalur}/ujian`, { ...tanpaJudul, class_id: pilihKelas }, opsi);
    };

    if (mapel.length === 0) {
        return (
            <Kotak judul={mengubah ? `Ubah ${ujianAwal.kode}` : 'Tambah ujian'}>
                <Peringatan>
                    Belum ada mata pelajaran yang ditetapkan di event ini. Tetapkan mapelnya di
                    laman <strong>Paket soal</strong> terlebih dahulu.
                </Peringatan>
            </Kotak>
        );
    }

    return (
        <Kotak
            judul={mengubah ? `Ubah ${ujianAwal.kode}` : 'Tambah ujian'}
            aksi={
                <>
                    <Tombol nada="sek" onClick={padaTutup}>
                        Batal
                    </Tombol>
                    <Tombol nada="pri" onClick={simpan}>
                        {mengubah ? 'Simpan perubahan' : 'Simpan ujian'}
                    </Tombol>
                </>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <PilihCari
                    label="Mata pelajaran"
                    petunjuk={
                        mengubah && ujianAwal.ada_paket
                            ? 'Soal sudah dibekukan dari mapel ini, jadi mapelnya tidak bisa diganti.'
                            : undefined
                    }
                    nilai={mapelAktif?.nama ?? ''}
                    pilihan={mapelUnik.map((m) => ({
                        id: m.nama,
                        label: m.nama,
                        ket: m.kode,
                    }))}
                    padaPilih={(nama) => {
                        if (nama !== null) {
                            pilihMapel(nama);
                        }
                    }}
                    disabled={mengubah && ujianAwal.ada_paket}
                    kosong="Tidak ada mata pelajaran yang cocok."
                />

                <Field
                    label="Tingkat"
                    petunjuk={
                        tingkatSasaran.length === 0
                            ? 'Centang kelas sasaran - tingkatnya mengikuti kelas yang dipilih.'
                            : tingkatSasaran.length > 1
                              ? 'Kelas sasaran mencakup beberapa tingkat; mapel menunjuk varian tingkat terendah.'
                              : 'Mengikuti tingkat kelas sasaran.'
                    }
                >
                    <p className="rounded-wf border border-line bg-surface-2/60 px-2.5 py-1.5 text-sm text-ink">
                        {tingkatSasaran.length === 0 ? (
                            <span className="text-ink-3">—</span>
                        ) : (
                            `Tingkat ${tingkatSasaran.join(', ')}`
                        )}
                    </p>
                </Field>

                <Field
                    label="Jenis"
                    petunjuk="Mengikuti jenis kegiatan event. Ubah di laman Ubah event bila perlu."
                >
                    <p className="rounded-wf border border-line bg-surface-2/60 px-2.5 py-1.5 text-sm text-ink">
                        {jenisEvent || '—'}
                    </p>
                </Field>

                <Field
                    label="Judul ujian"
                    petunjuk={
                        mengubah
                            ? 'Judul ujian lama tidak dihitung ulang - ia sudah dipakai di jadwal dan berita acara.'
                            : 'Dibuat otomatis dari jenis event, mata pelajaran, dan tingkat kelas sasaran.'
                    }
                >
                    <p className="rounded-wf border border-line bg-surface-2/60 px-2.5 py-1.5 text-sm text-ink">
                        {mengubah
                            ? (ujianAwal.judul || '—')
                            : ([
                                  (jenisEvent ?? '').trim(),
                                  namaMapelTerpilih.trim(),
                              ]
                                  .filter(Boolean)
                                  .join(' - ') || 'Ujian') +
                              (tingkatTerpilih ? ` #${tingkatTerpilih}` : '')}
                    </p>
                </Field>

                <Field label="Durasi (menit)">
                    <input
                        type="number"
                        min={1}
                        max={600}
                        value={isi.duration_minutes}
                        onChange={(e) =>
                            setIsi({ ...isi, duration_minutes: Number(e.target.value) })
                        }
                        className={kelasInput}
                    />
                </Field>

                <Field
                    label="Waktu minimal (menit)"
                    petunjuk="Boleh dikosongkan. Siswa tidak bisa mengumpulkan sebelum waktu ini habis."
                    galat={
                        isi.min_duration_minutes !== null &&
                        isi.min_duration_minutes > isi.duration_minutes
                            ? 'Tidak boleh melebihi durasi ujian.'
                            : undefined
                    }
                >
                    <input
                        type="number"
                        min={1}
                        max={isi.duration_minutes}
                        value={isi.min_duration_minutes ?? ''}
                        onChange={(e) =>
                            setIsi({
                                ...isi,
                                min_duration_minutes:
                                    e.target.value === '' ? null : Number(e.target.value),
                            })
                        }
                        className={kelasInput}
                        placeholder="Tanpa batas"
                    />
                </Field>

                <Field label="Maksimal percobaan">
                    <input
                        type="number"
                        min={1}
                        max={10}
                        value={isi.max_attempts}
                        onChange={(e) => setIsi({ ...isi, max_attempts: Number(e.target.value) })}
                        className={kelasInput}
                    />
                </Field>

                <Field label="Instruksi" petunjuk="Tampil di layar siswa sebelum mulai">
                    <input
                        value={isi.instructions}
                        onChange={(e) => setIsi({ ...isi, instructions: e.target.value })}
                        className={kelasInput}
                        placeholder="Baca soal dengan teliti"
                    />
                </Field>
            </div>

            <div className="mt-4">
                <DaftarCentangCari
                    label="Kelas sasaran"
                    petunjuk="Kosong berarti seluruh peserta event. Itu pilihan yang disengaja: mencentang setiap kelas satu per satu berarti lupa satu kelas membuat kelas itu tidak mendapat ujian tanpa satu pun peringatan."
                    nilai={pilihKelas}
                    pilihan={kelas.map((k) => ({
                        id: k.id,
                        label: k.nama,
                        tingkat: k.tingkat,
                    }))}
                    padaUbah={setPilihKelas}
                    kosong="Tidak ada kelas yang cocok."
                />

                {kelas.length === 0 && (
                    <p className="mt-2 text-xs text-ink-3">Belum ada kelas di event ini.</p>
                )}
            </div>

            <div className="mt-4 flex flex-wrap gap-4 border-t border-line pt-3">
                <Sakelar
                    label="Acak urutan soal"
                    nilai={isi.shuffle_questions}
                    pada={(v) => setIsi({ ...isi, shuffle_questions: v })}
                />
                <Sakelar
                    label="Acak pilihan jawaban"
                    nilai={isi.shuffle_options}
                    pada={(v) => setIsi({ ...isi, shuffle_options: v })}
                />
                <Sakelar
                    label="Boleh kembali ke soal sebelumnya"
                    nilai={isi.allow_back_navigation}
                    pada={(v) => setIsi({ ...isi, allow_back_navigation: v })}
                />
                <Sakelar
                    label="Izinkan masuk terlambat"
                    nilai={isi.allow_late_entry}
                    pada={(v) => setIsi({ ...isi, allow_late_entry: v })}
                />
            </div>
        </Kotak>
    );
}

function Sakelar({
    label,
    nilai,
    pada,
}: {
    label: string;
    nilai: boolean;
    pada: (v: boolean) => void;
}) {
    return (
        <label className="flex items-center gap-2 text-xs text-ink-2">
            <input type="checkbox" checked={nilai} onChange={(e) => pada(e.target.checked)} />
            {label}
        </label>
    );
}
