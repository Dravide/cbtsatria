import { useState } from 'react';
import type { FormEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { tanyaTeks } from '@/Components/dialog';
import { Chip, Field, Inbox, Kotak, Modal, Tombol, kelasInput } from '@/Components/ui';

/**
 * Monitoring satu ruang-sesi per peserta (dibuka di tab terpisah dari rekap lintas ruang).
 * Nomor soal berasal dari denyut peramban siswa (per 30 detik), sedangkan progres dihitung dari jawaban tersimpan di server.
 */

/** Satu butir dari sudut pandang peserta (`nomor` adalah urutan layar anak, `soal_id` menunjuk baris `soal`). */
type Butir = {
    nomor: number;
    soal_id: number;
    dijawab: boolean;
    ditandai: boolean;
};

type Anak = {
    peserta_id: number;
    kursi: number | null;
    nama: string;
    nis: string | null;
    status: string | null;
    status_label: string;
    status_nada: string;
    terhubung: boolean | null;
    nomor: number | null;
    progres: string | null;
    denyut: string | null;
    pelanggaran: number;
    jenis_pelanggaran: string[];
    /** Status layar penuh dari denyut terakhir (bisa basi); null bila belum ada denyut. */
    layar_penuh: boolean | null;
    /** Jumlah peristiwa layar terbagi selama ujian. */
    layar_terbagi: number;
    terkunci: boolean;
    kunci_alasan: string | null;
    /** Nomor soal terjawab urut naik; null berarti belum membuka ujian (berbeda dari []). */
    dikerjakan: number[] | null;
    /** Nomor soal yang belum dijawab, urut naik. */
    belum: number[] | null;
    /** Nomor soal yang ditandai ragu oleh peserta. */
    ditandai: number[] | null;
    /** Seluruh butir peserta ini berurut beserta soal yang ditunjuknya. */
    butir: Butir[] | null;
};

type Soal = {
    id: number;
    /** Urutan paket ujian, bukan urutan layar anak. */
    nomor: number;
    jenis: string | null;
    jenis_label: string | null;
    jenis_pendek: string | null;
    judul: string | null;
    bobot: number;
    /** False untuk uraian: `benar`/`salah` lalu selalu null. */
    otomatis: boolean;
    peserta: number;
    sudah: number;
    belum: number;
    ditandai: number;
    /** Jumlah jawaban yang sudah punya nilai. */
    dinilai: number;
    /** Null selama belum ada jawaban yang dinilai. */
    benar: number | null;
    salah: number | null;
    persen: number;
};

type Ruang = {
    id: number;
    kode: string;
    nama: string | null;
    lokasi: string | null;
    sesi: string | null;
    ujian: string | null;
    status_sesi: string | null;
    status_label: string | null;
    jam: string | null;
    pengawas: string[];
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    ruang: Ruang;
    angka: {
        peserta: number;
        mengerjakan: number;
        selesai: number;
        terputus: number;
        terkunci: number;
        pelanggaran: number;
        insiden: number;
    };
    anak: Anak[];
    soal: Soal[];
    angka_soal: {
        soal: number;
        peserta: number;
        sudah: number;
        belum: number;
        ditandai: number;
        selesai_semua: number;
        belum_mulai: number;
    };
    ruang_tujuan?: {
        id: number;
        kode: string;
        nama: string | null;
        kapasitas: number;
        terisi: number;
        pc: number;
        pc_tersedia: number[];
    }[];
    monitoring_url: string;
};

export default function EventMonitoringRuang({
    event,
    ruang,
    angka,
    anak,
    soal,
    angka_soal,
    ruang_tujuan = [],
    monitoring_url,
}: Props) {
    const [rincian, setRincian] = useState<Anak | null>(null);
    const [pindah, setPindah] = useState<Anak | null>(null);

    const [tindakan, setTindakan] = useState<{ anak: Anak; jenis: 'reset' | 'hentikan' } | null>(
        null,
    );

    /** Buka kunci peserta; alasan opsional karena server mengisi teks bawaan jika kosong. */
    const bukaKunci = async (a: Anak) => {
        const alasan = await tanyaTeks({
            judul: `Buka kunci pengerjaan ${a.nama}?`,
            pesan: 'Penghitung pelanggaran tidak direset. Alasan pembukaan dibaca siswa di riwayat pelanggarannya.',
            label: 'Alasan pembukaan (boleh dikosongkan)',
            petunjuk: 'Kosongkan bila alasannya sudah jelas - server mencatat "Dibuka dari papan monitoring Admin".',
            wajib: false,
            maksimal: 200,
            labelYa: 'Buka kunci',
            labelTidak: 'Jangan',
        });

        if (alasan === null) {
            return;
        }

        router.post(
            `/admin/events/${event.id}/monitoring/peserta/${a.peserta_id}/buka`,
            { alasan },
            { preserveScroll: true },
        );
    };

    const tindakanPeserta = (a: Anak, jenis: 'reset' | 'hentikan') => {
        setTindakan({ anak: a, jenis });
    };

    // Reset seluruh ruang memakai alasan wajib yang disalin ke riwayat tiap peserta.
    const [resetRuang, setResetRuang] = useState(false);
    const [alasanRuang, setAlasanRuang] = useState('');

    const kirimResetRuang = () => {
        router.post(
            `/admin/events/${event.id}/monitoring/ruang/${ruang.id}/reset-massal`,
            { alasan: alasanRuang },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAlasanRuang('');
                    setResetRuang(false);
                },
            },
        );
    };

    const [hentikanRuang, setHentikanRuang] = useState(false);
    const [pesanRuang, setPesanRuang] = useState('');

    const kirimHentikanRuang = () => {
        router.post(
            `/admin/events/${event.id}/monitoring/ruang/${ruang.id}/hentikan-massal`,
            { pesan: pesanRuang },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPesanRuang('');
                    setHentikanRuang(false);
                },
            },
        );
    };

    // Hitung hanya peserta yang sudah punya pengerjaan aktif; peserta belum mulai tidak ikut direset.
    const bisaDireset = anak.filter((a) => a.status !== null && a.status !== 'batal').length;
    const bisaDihentikan = anak.filter((a) => a.status === 'berlangsung').length;

    return (
        <AdminLayout
            judul={`${ruang.kode} · ${ruang.nama ?? 'Ruang'}`}
            breadcrumb={`/admin/events · ${event.kode} · Monitoring · ${ruang.kode}`}
            aksi={
                <>
                    <Link
                        href={monitoring_url}
                        className={
                            'inline-flex items-center justify-center gap-1.5 rounded-wf border px-3 py-1.5 text-xs font-medium ' +
                            'border-line bg-surface text-ink hover:bg-surface-2'
                        }
                    >
                        <span aria-hidden="true" className="text-ink-3">
                            &#8592;
                        </span>
                        Kembali ke monitoring
                    </Link>

                    {bisaDihentikan > 0 && (
                        <Tombol
                            nada="bahaya"
                            onClick={() => setHentikanRuang(true)}
                            title="Hentikan pengerjaan seluruh peserta yang sedang berlangsung di ruang ini"
                        >
                            Hentikan semua ({bisaDihentikan})
                        </Tombol>
                    )}

                    {bisaDireset > 0 && (
                        <Tombol
                            onClick={() => setResetRuang(true)}
                            title="Batalkan pengerjaan seluruh peserta yang masih punya pengerjaan aktif"
                        >
                            Reset seluruh ruang ({bisaDireset})
                        </Tombol>
                    )}
                </>
            }
        >
            <Head title={`${ruang.kode} · Monitoring · ${event.nama}`} />

            <div className="mb-3">
                <Kotak judul="Ruang ini">
                    <div className="grid gap-2 text-xs text-ink-2 sm:grid-cols-2">
                        <p>
                            {[ruang.ujian ?? 'Ujian belum terpasang', ruang.lokasi, ruang.jam]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                        <p className="sm:text-right">
                            Pengawas:{' '}
                            {ruang.pengawas.length > 0 ? (
                                ruang.pengawas.join(', ')
                            ) : (
                                <span className="text-warn">belum ditetapkan</span>
                            )}
                        </p>
                    </div>

                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <Chip nada={ruang.sesi ? 'info' : 'mute'}>
                            {ruang.sesi ?? 'tanpa sesi'}
                        </Chip>
                        {ruang.status_label && (
                            <Chip nada={ruang.status_sesi === 'berjalan' ? 'info' : 'mute'}>
                                {ruang.status_label}
                            </Chip>
                        )}
                    </div>
                </Kotak>
            </div>

            <div className="mb-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <KPI label="Peserta" nilai={angka.peserta} />
                <KPI label="Mengerjakan" nilai={angka.mengerjakan} />
                <KPI label="Selesai" nilai={angka.selesai} nada="ok" />
                <KPI
                    label="Terputus"
                    nilai={angka.terputus}
                    nada={angka.terputus > 0 ? 'bad' : undefined}
                />
                <KPI
                    label="Terkunci"
                    nilai={angka.terkunci}
                    nada={angka.terkunci > 0 ? 'bad' : undefined}
                />
                <KPI
                    label="Pelanggaran"
                    nilai={angka.pelanggaran}
                    nada={angka.pelanggaran > 0 ? 'bad' : undefined}
                />
            </div>

            {angka.insiden > 0 && (
                <div className="mb-3">
                    <Inbox>
                        {angka.insiden} insiden belum ditangani di ruang ini. Penanganannya
                        dilakukan pengawas ruangnya dari papan pengawas, bukan dari sini.
                    </Inbox>
                </div>
            )}

            <RingkasSoal soal={soal} angka={angka_soal} />

            <DaftarAnak
                anak={anak}
                ruang={ruang.kode}
                bisaPindah={ruang_tujuan.length > 0}
                padaRincian={setRincian}
                padaBukaKunci={bukaKunci}
                padaTindakan={tindakanPeserta}
                padaPindah={setPindah}
            />

            <Modal
                judul={pindah ? `Pindah ruang darurat (sesi ini) · ${pindah.nama}` : 'Pindah ruang darurat'}
                terbuka={pindah !== null}
                padaTutup={() => setPindah(null)}
            >
                {pindah !== null && (
                    <FormPindahDaruratAdmin
                        key={pindah.peserta_id}
                        anak={pindah}
                        eventId={event.id}
                        ruangTujuan={ruang_tujuan}
                        padaSelesai={() => setPindah(null)}
                    />
                )}
            </Modal>

            <Modal
                judul={rincian ? `Butir soal · ${rincian.nama}` : 'Butir soal'}
                terbuka={rincian !== null}
                padaTutup={() => setRincian(null)}
            >
                {rincian !== null && (
                    <DetailButir anak={rincian} soal={soal} angka={angka_soal} />
                )}
            </Modal>

            <Modal
                judul={
                    tindakan?.jenis === 'reset'
                        ? `Reset pengerjaan · ${tindakan.anak.nama}`
                        : tindakan
                          ? `Hentikan pengerjaan · ${tindakan.anak.nama}`
                          : 'Pengerjaan'
                }
                terbuka={tindakan !== null}
                padaTutup={() => setTindakan(null)}
            >
                {/* key per peserta agar state form modal tidak terbawa saat pindah peserta. */}
                {tindakan !== null && (
                    <TindakanPeserta
                        key={`${tindakan.jenis}-${tindakan.anak.peserta_id}`}
                        anak={tindakan.anak}
                        jenis={tindakan.jenis}
                        eventId={event.id}
                        padaSelesai={() => setTindakan(null)}
                    />
                )}
            </Modal>

            <Modal
                judul={`Reset seluruh ruang ${ruang.kode}`}
                terbuka={resetRuang}
                padaTutup={() => setResetRuang(false)}
            >
                <div className="space-y-3">
                    <p className="text-xs leading-relaxed text-ink-2">
                        <strong className="text-ink">{bisaDireset} pengerjaan</strong> di
                        ruang ini akan dibatalkan, dan setiap peserta mengulang dari soal
                        pertama saat membuka gerbang lagi. Urutan soal dan sisa waktunya
                        baru; <strong className="text-ink">nilai atas pengerjaan lama hilang</strong>.
                    </p>

                    <p className="rounded-wf border border-warn/40 bg-warn-bg px-2.5 py-2 text-[11px] leading-relaxed text-ink-2">
                        Peserta yang belum mulai mengerjakan tidak tersentuh. Kejadiannya
                        tercatat di riwayat pelanggaran <em>setiap</em> anak yang direset, dengan
                        alasan yang Anda tulis di bawah. Tulis kalimat yang sudah cukup
                        tiga bulan dari sekarang.
                    </p>

                    <Field
                        label="Alasan reset massal"
                        petunjuk="Dibaca siswa di halaman pelanggaran mereka. Sebutkan kejadiannya, bukan hanya tindakannya."
                    >
                        <textarea
                            value={alasanRuang}
                            onChange={(e) => setAlasanRuang(e.target.value)}
                            rows={3}
                            maxLength={200}
                            placeholder="Contoh: listrik ruang R-03 padam di menit kelima; ujian diulang dari awal."
                            className={kelasInput + ' min-h-[84px] resize-y'}
                        />
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Tombol onClick={() => setResetRuang(false)}>Batal</Tombol>
                        <Tombol
                            nada="bahaya"
                            onClick={kirimResetRuang}
                            disabled={alasanRuang.trim().length < 8}
                        >
                            Reset {bisaDireset} pengerjaan
                        </Tombol>
                    </div>
                </div>
            </Modal>

            <Modal
                judul={`Hentikan seluruh ruang ${ruang.kode}`}
                terbuka={hentikanRuang}
                padaTutup={() => setHentikanRuang(false)}
            >
                <div className="space-y-3">
                    <p className="text-xs leading-relaxed text-ink-2">
                        <strong className="text-ink">{bisaDihentikan} pengerjaan</strong> yang
                        sedang berlangsung di ruang ini akan dihentikan sekarang, dan pesan di
                        bawah langsung tampil di layar setiap peserta. Pengerjaan dapat
                        dilanjutkan kembali setelah kunci dibuka.
                    </p>

                    <p className="rounded-wf border border-warn/40 bg-warn-bg px-2.5 py-2 text-[11px] leading-relaxed text-ink-2">
                        Peserta yang belum mulai, sudah selesai, atau sudah terkunci tidak
                        tersentuh.
                    </p>

                    <Field
                        label="Pesan untuk peserta"
                        petunjuk="Ditampilkan di layar seluruh peserta yang dihentikan (minimal 4 karakter)."
                    >
                        <textarea
                            value={pesanRuang}
                            onChange={(e) => setPesanRuang(e.target.value)}
                            rows={3}
                            maxLength={300}
                            placeholder="Contoh: Hentikan pengerjaan sementara, ikuti instruksi pengawas ruang."
                            className={kelasInput + ' min-h-[84px] resize-y'}
                        />
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Tombol onClick={() => setHentikanRuang(false)}>Batal</Tombol>
                        <Tombol
                            nada="bahaya"
                            onClick={kirimHentikanRuang}
                            disabled={pesanRuang.trim().length < 4}
                        >
                            Hentikan {bisaDihentikan} pengerjaan
                        </Tombol>
                    </div>
                </div>
            </Modal>
            <div className="mt-3">
                <Kotak judul="Catatan pembacaan laman ini">
                    <div className="space-y-2 text-xs leading-relaxed text-ink-2">
                        <p>
                            &ldquo;Terputus&rdquo; bukan keadaan yang disimpan, melainkan
                            yang disimpulkan: peserta yang tidak mengirim kabar lebih dari
                            90 detik. Karena itu angkanya bisa naik turun sendiri tanpa ada
                            yang mengubah apa pun. Dan itu memang keadaannya.
                        </p>
                        <p>
                            &ldquo;Sampai nomor&rdquo; berasal dari denyut anak, yang
                            berjalan tiap tiga puluh detik. Anak yang baru berpindah soal
                            masih tampil di nomor lamanya sampai denyut berikutnya, dan
                            jam di sebelahnya menyebutkan denyut mana yang dibaca. Nomor
                            itu dilaporkan peramban siswa, jadi ia isyarat, bukan bukti;
                            jumlah jawaban yang benar-benar tersimpan ada di kolom
                            Progres, dan itu dihitung server.
                        </p>
                        <p>
                            Peserta yang belum pernah berdenyut tampil dengan tanda pisah,
                            bukan dengan nomor 1. &ldquo;Belum ada kabar&rdquo; dan
                            &ldquo;baru membuka soal pertama&rdquo; adalah dua keadaan yang
                            berbeda, dan yang pertama adalah yang perlu ditanyakan ke
                            pengawas ruangnya.
                        </p>
                        <p>
                            Kolom &ldquo;Dikerjakan&rdquo; bukan versi lebih tepat dari
                            &ldquo;Sampai nomor&rdquo;. Keduanya menjawab pertanyaan
                            yang berbeda. Yang pertama dihitung dari jawaban yang
                            benar-benar tersimpan, yang kedua dari nomor yang dilaporkan
                            peramban. Anak yang sudah sampai nomor 12 tetapi baru menyimpan
                            tiga jawaban sedang melewati sebelas soal tanpa menjawabnya;
                            anak yang menjawab sampai nomor 40 tetapi melaporkan nomor 3
                            sedang menengok kembali ke belakang. Keduanya tampak sama di
                            kolom denyut, dan keduanya perlu ditanyai dengan
                            pertanyaan yang berbeda.
                        </p>
                        <p>
                            Butir soal tiap anak dibuka lewat tombol{' '}
                            <span className="text-ink-2">Butir soal</span> di barisnya, dan di
                            sana nomornya nomor yang dilihat anak itu sendiri. Pada ujian yang
                            mengacak soal, nomor yang sama menunjuk soal yang berbeda untuk tiap
                            anak. Dan itulah sebabnya rinciannya per anak, bukan satu
                            tabel untuk seluruh ruangan yang akan mencampur soal-soal berbeda
                            menjadi satu baris.
                        </p>
                        <p>
                            Kolom &ldquo;Benar&rdquo; pada tiap butir baru terisi setelah
                            penilaian berjalan. Penilaian otomatis berjalan sesudah
                            ujiannya berakhir, bukan saat anak menyimpan jawabannya, dan
                            uraian menunggu koreksi guru. Sebelum itu yang tertulis
                            &ldquo;belum dinilai&rdquo;, bukan &ldquo;0 benar&rdquo;:
                            nol terbaca sebagai &ldquo;tidak ada yang benar&rdquo;, dan
                            itu pernyataan yang keliru tentang jawaban yang belum dibaca
                            siapa pun.
                        </p>
                    </div>
                </Kotak>
            </div>
        </AdminLayout>
    );
}

/** Ringkasan progres soal tingkat ruangan; rincian per peserta ada di modal `DetailButir`. */
function RingkasSoal({
    soal,
    angka,
}: {
    soal: Soal[];
    angka: Props['angka_soal'];
}) {
    if (soal.length === 0) {
        return (
            <div className="mb-3">
                <Kotak judul="Soal ruang ini">
                    <p className="py-6 text-center text-sm text-ink-3">
                        {angka.peserta === 0
                            ? 'Belum ada peserta di ruang ini yang membuka ujiannya.'
                            : 'Peserta di ruang ini sudah punya attempt, tetapi butir soalnya belum tertulis. Mesin ujian menulisnya saat anak membuka soal pertama.'}
                    </p>
                </Kotak>
            </div>
        );
    }

    return (
        <div className="mb-3">
            <Kotak
                judul={`Soal ruang ini · ${angka.soal} butir`}
                aksi={
                    <>
                        <Chip nada="info">{angka.sudah} sudah dijawab</Chip>
                        <Chip nada="mute">{angka.belum} belum</Chip>
                        {angka.ditandai > 0 && <Chip nada="warn">{angka.ditandai} ditandai ragu</Chip>}
                    </>
                }
            >
                <div className="grid gap-2 sm:grid-cols-4">
                    <Angka
                        label="Selesai semua butir"
                        nilai={angka.selesai_semua}
                        teks={`${angka.selesai_semua} / ${angka.peserta}`}
                        nada={angka.selesai_semua > 0 ? 'ok' : undefined}
                    />
                    <Angka
                        label="Belum membuka ujian"
                        nilai={angka.belum_mulai}
                        nada={angka.belum_mulai > 0 ? 'warn' : undefined}
                    />
                    <Angka
                        label="Rata-rata terisi"
                        teks={
                            angka.peserta === 0
                                ? '—'
                                : `${Math.round((angka.sudah / Math.max(1, angka.peserta * angka.soal)) * 100)}%`
                        }
                    />
                    <Angka label="Butir soal" nilai={angka.soal} />
                </div>

                <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                    Angka &ldquo;sudah&rdquo; dan &ldquo;belum&rdquo; di atas dihitung dari{' '}
                    <em>jawaban yang tersimpan</em>, bukan dari nomor yang dilaporkan
                    peramban anak. Daftar butir satu anak, beserta soal mana yang belum
                    dijawabnya, dibuka lewat tombol <span className="text-ink-2">Butir soal</span>{' '}
                    di barisnya.
                </p>
            </Kotak>
        </div>
    );
}

/** Rincian butir satu peserta (berurut nomor layar anak), disambungkan ke statistik ruangan lewat `soal_id`. */
function DetailButir({
    anak,
    soal,
    angka,
}: {
    anak: Anak;
    soal: Soal[];
    angka: Props['angka_soal'];
}) {
    // Indeks statistik berdasarkan soal_id agar pencarian O(1) di dalam daftar butir.
    const statistik = new Map(soal.map((s) => [s.id, s]));

    if (anak.butir === null) {
        return (
            <div className="space-y-2 text-xs leading-relaxed text-ink-2">
                <p>
                    Butir soal anak ini <strong>belum tertulis</strong>. Ia belum
                    membuka ujiannya. Mesin ujian menuliskan daftar butirnya saat anak membuka
                    soal pertama, dan sampai itu terjadi tidak ada satu pun nomor yang bisa
                    dibacakan ke pengawas.
                </p>
                <p className="text-ink-3">
                    Yang perlu ditanyakan ke pengawas ruangnya bukan soal mana yang belum
                    dikerjakan, melainkan mengapa anaknya belum masuk.
                </p>
            </div>
        );
    }

    const dijawab = anak.butir.filter((b) => b.dijawab).length;
    const ragu = anak.butir.filter((b) => b.ditandai).length;

    return (
        <div className="space-y-3">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <Angka label="Butir" nilai={anak.butir.length} />
                <Angka label="Dijawab" nilai={dijawab} nada={dijawab === anak.butir.length ? 'ok' : undefined} />
                <Angka label="Belum" nilai={anak.butir.length - dijawab} nada={anak.butir.length - dijawab > 0 ? 'warn' : undefined} />
                <Angka label="Ditandai ragu" nilai={ragu} nada={ragu > 0 ? 'warn' : undefined} />
            </div>

            <div className="space-y-1.5">
                {anak.butir.map((b) => (
                    <BarisButir
                        key={b.nomor}
                        butir={b}
                        soal={statistik.get(b.soal_id)}
                        jumlahPeserta={angka.peserta}
                    />
                ))}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <span className="text-[10px] uppercase tracking-wide text-ink-3">Keterangan</span>
                <Chip nada="ok">sudah dijawab</Chip>
                <Chip nada="warn">ditandai ragu</Chip>
                <Chip nada="mute">belum dijawab</Chip>
                <span className="ml-auto font-mono text-[10px] uppercase tracking-wide text-ink-3">
                    nomor yang dilihat anak
                </span>
            </div>
        </div>
    );
}

/** Satu baris butir peserta; `soal` bisa undefined jika soal sudah dihapus dari paket. */
function BarisButir({
    butir,
    soal,
    jumlahPeserta,
}: {
    butir: Butir;
    soal: Soal | undefined;
    jumlahPeserta: number;
}) {
    const nada = butir.ditandai ? 'warn' : butir.dijawab ? 'ok' : 'mute';
    const teks = butir.ditandai ? 'ragu' : butir.dijawab ? 'sudah' : 'belum';
    const total = Math.max(1, soal?.peserta ?? jumlahPeserta);

    return (
        <div className="rounded-wf border border-line/70 bg-surface-2 px-2.5 py-2">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono text-[11px] text-ink-3">
                    {String(butir.nomor).padStart(3, '0')}
                </span>

                <Chip nada={nada}>{teks}</Chip>

                {soal && <Chip nada="mute">{soal.jenis_pendek ?? soal.jenis ?? '—'}</Chip>}

                <span className="min-w-0 flex-1 truncate text-xs text-ink">
                    {soal?.judul ?? 'Soal tidak lagi ada di paket'}
                </span>

                {soal && (
                    <span className="ml-auto flex items-center gap-3 font-mono text-[11px]">
                        <span className="text-ink">
                            {soal.sudah}
                            <span className="text-ink-3">/{soal.peserta}</span>
                        </span>
                        {soal.benar === null || soal.salah === null ? (
                            <span className="text-ink-3">belum dinilai</span>
                        ) : (
                            <span className="text-ok">{soal.benar} benar</span>
                        )}
                    </span>
                )}
            </div>

            {soal && (
                <div
                    className="mt-1.5 flex h-1.5 overflow-hidden rounded-full bg-line/60"
                    title={`${soal.sudah} dari ${total} anak sudah menjawab soal ini`}
                >
                    <span
                        className="bg-ok"
                        style={{ width: `${Math.min(100, (soal.sudah / total) * 100)}%` }}
                    />
                </div>
            )}
        </div>
    );
}

/** Daftar peserta satu ruang berurut nama (sama dengan papan pengawas). */
function DaftarAnak({
    anak,
    ruang,
    bisaPindah,
    padaRincian,
    padaBukaKunci,
    padaTindakan,
    padaPindah,
}: {
    anak: Anak[];
    ruang: string;
    bisaPindah: boolean;
    padaRincian: (a: Anak) => void;
    padaBukaKunci: (a: Anak) => void;
    padaTindakan: (a: Anak, jenis: 'reset' | 'hentikan') => void;
    padaPindah: (a: Anak) => void;
}) {
    if (anak.length === 0) {
        return (
            <Kotak>
                <p className="py-6 text-center text-sm text-ink-3">
                    Belum ada peserta yang ditempatkan di ruang {ruang}.
                </p>
            </Kotak>
        );
    }

    return (
        <div className="space-y-2">
            <p className="text-[11px] text-ink-3">
                Keadaan ruang {ruang} menurut denyut terakhir tiap peserta, dan butir yang
                jawabannya sudah tersimpan.
            </p>

            {anak.map((a) => (
                <div
                    key={a.peserta_id}
                    className="rounded-wf border border-line/70 bg-surface px-3 py-2"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        {a.kursi !== null && (
                            <span className="rounded-wf border border-line bg-surface-2 px-1.5 py-0.5 font-mono text-[11px] text-ink-2">
                                kursi {a.kursi}
                            </span>
                        )}

                        <span className="text-sm text-ink">{a.nama}</span>

                        {a.nis && (
                            <span className="font-mono text-[11px] text-ink-3">{a.nis}</span>
                        )}

                        <span className="ml-auto flex flex-wrap items-center gap-2">
                            {a.terkunci && <Chip nada="bad">terkunci</Chip>}

                            {/* layar_penuh berasal dari denyut terakhir (bisa basi hingga 30 detik). */}
                            {a.layar_penuh === false && <Chip nada="warn">di luar layar penuh</Chip>}
                            {a.layar_terbagi > 0 && (
                                <Chip nada={a.layar_terbagi >= 3 ? 'bad' : 'warn'}>
                                    layar terbagi {a.layar_terbagi}×
                                </Chip>
                            )}

                            {a.pelanggaran > 0 && (
                                <Chip nada="warn">{a.jenis_pelanggaran.join(', ')}</Chip>
                            )}
                            {a.terhubung === false && <Chip nada="bad">terputus</Chip>}
                            <Chip nada={a.status_nada as 'mute'}>{a.status_label}</Chip>

                            {a.terkunci && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        void padaBukaKunci(a);
                                    }}
                                    className="rounded-wf border border-accent bg-accent px-2 py-0.5 text-[11px] font-medium text-on-accent hover:bg-accent/90"
                                >
                                    Buka kunci
                                </button>
                            )}

                            {/* Ketersediaan tombol mengikuti aturan PengendaliPengerjaan; Hentikan disembunyikan jika sudah terkunci. */}
                            {a.status !== null && a.status !== 'batal' && (
                                <button
                                    type="button"
                                    onClick={() => padaTindakan(a, 'reset')}
                                    className="rounded-wf border border-line bg-surface px-2 py-0.5 text-[11px] text-ink hover:bg-surface-2"
                                >
                                    Reset
                                </button>
                            )}

                            {a.status === 'berjalan' && (
                                <button
                                    type="button"
                                    onClick={() => padaTindakan(a, 'hentikan')}
                                    className="rounded-wf border border-bad bg-surface px-2 py-0.5 text-[11px] text-bad hover:bg-bad/10"
                                >
                                    Hentikan
                                </button>
                            )}

                            {bisaPindah && (
                                <button
                                    type="button"
                                    onClick={() => padaPindah(a)}
                                    className="cursor-pointer rounded-wf border border-line bg-surface px-2 py-0.5 text-[11px] text-ink hover:bg-surface-2"
                                >
                                    Pindah ruang
                                </button>
                            )}

                            <button
                                type="button"
                                onClick={() => padaRincian(a)}
                                className="rounded-wf border border-line bg-surface px-2 py-0.5 text-[11px] text-ink hover:bg-surface-2"
                            >
                                Butir soal
                            </button>
                        </span>
                    </div>

                    <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <Angka
                            label="Sampai nomor"
                            nilai={a.nomor}
                            kosong="—"
                            nada={a.nomor !== null && a.terhubung === false ? 'bad' : undefined}
                        />
                        <Angka label="Progres" teks={a.progres ?? '—'} />
                        <Angka
                            label="Denyut"
                            teks={a.denyut ?? 'belum ada'}
                            nada={a.denyut === null ? 'warn' : undefined}
                        />
                        <Angka
                            label="Pelanggaran"
                            nilai={a.pelanggaran}
                            nada={a.pelanggaran > 0 ? 'warn' : undefined}
                        />
                    </div>

                    <RingkasanButir anak={a} padaRincian={() => padaRincian(a)} />

                    {a.terkunci && a.kunci_alasan && (
                        <p className="mt-2 text-[11px] text-bad">
                            Terkunci: {a.kunci_alasan}
                        </p>
                    )}
                </div>
            ))}
        </div>
    );
}

/**
 * Form modal reset atau hentikan satu peserta (`Hentikan` menampilkan pesannya ke layar siswa).
 * Wajib diberi `key` per peserta karena `Modal` hanya menyembunyikan isi saat ditutup.
 */
function TindakanPeserta({
    anak,
    jenis,
    eventId,
    padaSelesai,
}: {
    anak: Anak;
    jenis: 'reset' | 'hentikan';
    eventId: number;
    padaSelesai: () => void;
}) {
    const reset = jenis === 'reset';

    // Kedua kolom dikirim bersama; masing-masing endpoint hanya memvalidasi kolomnya.
    const { data, setData, post, processing, errors, reset: kosongkan } = useForm({
        alasan: '',
        pesan: '',
    });

    const isi = data[jenis === 'reset' ? 'alasan' : 'pesan'];
    const galat = errors[jenis === 'reset' ? 'alasan' : 'pesan'];
    const cukup = isi.trim().length >= 4;

    const kirim = (e: FormEvent) => {
        e.preventDefault();

        post(
            `/admin/events/${eventId}/monitoring/peserta/${anak.peserta_id}/${reset ? 'reset' : 'hentikan'}`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    // Kosongkan form sebelum menutup agar tidak tersisa bila dibuka ulang.
                    kosongkan();
                    padaSelesai();
                },
            },
        );
    };

    return (
        <form onSubmit={kirim} className="space-y-3">
            {reset ? (
                <p className="text-xs leading-relaxed text-ink-2">
                    Pengerjaan <strong>{anak.nama}</strong> akan dibatalkan, dan ia
                    mengulang dari soal pertama saat membuka gerbang lagi. Urutan soal
                    dan sisa waktunya baru.
                </p>
            ) : (
                <p className="text-xs leading-relaxed text-ink-2">
                    Pengerjaan <strong>{anak.nama}</strong> dihentikan sekarang, dan
                    kalimat di bawah tampil di layar siswanya. Jawabannya tidak hilang,
                    dan pengerjaannya bisa dihidupkan lagi lewat tombol{' '}
                    <span className="text-ink">Buka kunci</span>.
                </p>
            )}

            {reset && (
                <p className="rounded-wf border border-warn/40 bg-warn-bg px-2.5 py-2 text-[11px] leading-relaxed text-ink-2">
                    <strong className="text-warn">Nilai atas pengerjaan ini dihapus.</strong>{' '}
                    Soal yang sudah dikerjakan tetap tercatat sebagai riwayat, tetapi tidak
                    lagi dihitung sebagai nilai peserta. Isi ini hanya untuk keadaan yang
                    memang membatalkan pengerjaannya - misalnya anaknya terputus di menit
                    pertama, atau ia membuka akun yang salah.
                </p>
            )}

            <Field
                label={reset ? 'Alasan reset' : 'Pesan untuk siswa'}
                galat={galat}
                petunjuk={
                    reset
                        ? 'Tercatat di riwayat dan di jejak aktivitas - tiga bulan lagi pertanyaan "mengapa nilai anak ini hilang" dijawab dari sini.'
                        : 'Kalimat ini yang dibaca siswanya. Sebutkan apa yang harus ia lakukan.'
                }
            >
                <textarea
                    value={isi}
                    onChange={(e) => setData(jenis === 'reset' ? 'alasan' : 'pesan', e.target.value)}
                    rows={3}
                    maxLength={reset ? 200 : 300}
                    className={kelasInput + ' min-h-[84px] resize-y'}
                    placeholder={
                        reset
                            ? 'Contoh: perangkat mati di menit pertama, pengerjaan diulang.'
                            : 'Contoh: hentikan dulu, panggil pengawas ke kursi 12.'
                    }
                />
            </Field>

            <div className="flex items-center gap-2">
                <Tombol type="submit" nada={reset ? 'pri' : 'bahaya'} disabled={processing || !cukup}>
                    {reset ? 'Reset pengerjaan' : 'Hentikan sekarang'}
                </Tombol>
                <Tombol onClick={padaSelesai}>Batal</Tombol>
                <span className="ml-auto font-mono text-[10px] text-ink-3">
                    {isi.trim().length}/{reset ? 200 : 300}
                </span>
            </div>
        </form>
    );
}

function FormPindahDaruratAdmin({
    anak,
    eventId,
    ruangTujuan,
    padaSelesai,
}: {
    anak: Anak;
    eventId: number;
    ruangTujuan: NonNullable<Props['ruang_tujuan']>;
    padaSelesai: () => void;
}) {
    const [tujuanId, setTujuanId] = useState<number>(ruangTujuan[0]?.id ?? 0);
    const [mode, setMode] = useState<'hp' | 'pc'>('hp');
    const [nomorPc, setNomorPc] = useState<string>('');
    const [alasan, setAlasan] = useState('');
    const [kirim, setKirim] = useState(false);

    const terpilih = ruangTujuan.find((r) => r.id === tujuanId) ?? null;
    const bisaPc = terpilih !== null && terpilih.pc > 0 && terpilih.pc_tersedia.length > 0;

    const gantiTujuan = (idBaru: number) => {
        setTujuanId(idBaru);
        const r = ruangTujuan.find((x) => x.id === idBaru);
        if (!r || r.pc === 0 || r.pc_tersedia.length === 0) {
            setMode('hp');
            setNomorPc('');
        } else if (mode === 'pc') {
            setNomorPc(String(r.pc_tersedia[0]));
        }
    };

    const gantiMode = (m: 'hp' | 'pc') => {
        setMode(m);
        if (m === 'pc' && terpilih && terpilih.pc_tersedia.length > 0) {
            setNomorPc(String(terpilih.pc_tersedia[0]));
        } else {
            setNomorPc('');
        }
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!terpilih || alasan.trim().length < 3) {
            return;
        }
        if (mode === 'pc' && !nomorPc) {
            return;
        }
        setKirim(true);
        router.post(
            `/admin/events/${eventId}/monitoring/peserta/${anak.peserta_id}/pindah-ruang`,
            {
                ruang_tujuan_id: terpilih.id,
                mode,
                nomor_pc: mode === 'pc' ? Number(nomorPc) : null,
                alasan: alasan.trim(),
            },
            {
                preserveScroll: true,
                onSuccess: () => padaSelesai(),
                onFinish: () => setKirim(false),
            },
        );
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <p className="text-xs leading-relaxed text-ink-2">
                Pindahkan <strong>{anak.nama}</strong> ke ruang lain khusus untuk sesi ini saja.
                Sesi berikutnya tetap mengikuti penempatan awal event.
            </p>

            <Field label="Ruang tujuan">
                <select
                    value={tujuanId}
                    onChange={(e) => gantiTujuan(Number(e.target.value))}
                    className={kelasInput}
                >
                    {ruangTujuan.map((r) => (
                        <option key={r.id} value={r.id}>
                            {r.kode}
                            {r.nama ? ` · ${r.nama}` : ''} ({r.terisi}/{r.kapasitas}
                            {r.pc > 0 ? `, ${r.pc_tersedia.length} PC kosong` : ', BYOD'})
                        </option>
                    ))}
                </select>
            </Field>

            <div>
                <span className="mb-1 block text-xs font-medium text-ink-2">
                    Perangkat yang dipakai
                </span>
                <div className="grid grid-cols-2 gap-2">
                    <button
                        type="button"
                        onClick={() => gantiMode('hp')}
                        className={
                            'cursor-pointer rounded-wf border px-3 py-2 text-xs font-medium ' +
                            (mode === 'hp'
                                ? 'border-accent bg-accent text-on-accent'
                                : 'border-line bg-surface text-ink hover:bg-surface-2')
                        }
                    >
                        Tetap pakai HP
                    </button>
                    <button
                        type="button"
                        disabled={!bisaPc}
                        onClick={() => gantiMode('pc')}
                        className={
                            'cursor-pointer rounded-wf border px-3 py-2 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-45 ' +
                            (mode === 'pc'
                                ? 'border-accent bg-accent text-on-accent'
                                : 'border-line bg-surface text-ink hover:bg-surface-2')
                        }
                    >
                        Pakai PC Lab
                    </button>
                </div>
            </div>

            {mode === 'pc' && terpilih && (
                <Field label="Pilih nomor PC kosong">
                    <select
                        value={nomorPc}
                        onChange={(e) => setNomorPc(e.target.value)}
                        className={kelasInput + ' font-mono'}
                    >
                        {terpilih.pc_tersedia.map((n) => (
                            <option key={n} value={n}>
                                PC {String(n).padStart(2, '0')}
                            </option>
                        ))}
                    </select>
                </Field>
            )}

            <Field label="Alasan pindah">
                <input
                    value={alasan}
                    onChange={(e) => setAlasan(e.target.value)}
                    maxLength={255}
                    placeholder="Contoh: HP siswa bermasalah, pindah ke PC lab"
                    className={kelasInput}
                />
            </Field>

            <div className="flex justify-end gap-2">
                <Tombol onClick={padaSelesai}>Batal</Tombol>
                <Tombol
                    type="submit"
                    nada="pri"
                    disabled={kirim || alasan.trim().length < 3 || (mode === 'pc' && !nomorPc)}
                >
                    {kirim ? 'Memindahkan…' : 'Pindahkan untuk sesi ini'}
                </Tombol>
            </div>
        </form>
    );
}

/** Ringkasan nomor butir (sesuai layar anak) yang sudah, belum (maks 20), dan ditandai ragu. */
function RingkasanButir({ anak, padaRincian }: { anak: Anak; padaRincian: () => void }) {
    const { dikerjakan, belum, ditandai } = anak;

    if (dikerjakan === null || belum === null || ditandai === null) {
        return (
            <p className="mt-2 border-t border-line/60 pt-2 text-[11px] text-ink-3">
                Butir soalnya belum tertulis. Anak ini belum membuka ujiannya.
                Mesin ujian menuliskan daftar butirnya saat soal pertama dibuka.
            </p>
        );
    }

    const belumTampil = belum.length > 20 ? belum.slice(0, 20) : belum;
    const dipotong = belum.length !== belumTampil.length;

    return (
        <div className="mt-2 space-y-1 border-t border-line/60 pt-2 text-[11px] leading-relaxed">
            <p className="text-ink-2">
                <span className="text-ink-3">Dikerjakan: </span>
                {dikerjakan.length === 0 ? (
                    <span className="text-ink-3">belum ada</span>
                ) : (
                    <span className="font-mono text-ok">{dikerjakan.join(', ')}</span>
                )}
                <span className="ml-2 text-ink-3">({dikerjakan.length})</span>
            </p>

            <p className="text-ink-2">
                <span className="text-ink-3">Belum: </span>
                {belum.length === 0 ? (
                    <span className="font-mono text-ok">seluruh butir sudah dijawab</span>
                ) : (
                    <>
                        <span className="font-mono">{belumTampil.join(', ')}</span>
                        {dipotong && (
                            <span className="text-ink-3"> … +{belum.length - belumTampil.length}</span>
                        )}
                        <span className="ml-2 text-ink-3">({belum.length})</span>
                    </>
                )}
            </p>

            {ditandai.length > 0 && (
                <p className="text-ink-2">
                    <span className="text-ink-3">Ditandai ragu: </span>
                    <span className="font-mono text-warn">{ditandai.join(', ')}</span>
                    <span className="ml-2 text-ink-3">
                        Anaknya sendiri menandai ini, dan itu yang paling perlu
                        ditanyakan
                    </span>
                </p>
            )}

            <p className="pt-0.5">
                <button
                    type="button"
                    onClick={padaRincian}
                    className="text-ink-3 underline decoration-dotted underline-offset-2 hover:text-ink"
                >
                    Lihat butir &amp; soalnya
                </button>
            </p>
        </div>
    );
}

/** Kartu angka ringkas; `teks` melewati format angka, `kosong` membedakan null dari nol. */
function Angka({
    label,
    nilai,
    teks,
    kosong = '0',
    nada,
}: {
    label: string;
    nilai?: number | null;
    teks?: string;
    kosong?: string;
    nada?: 'ok' | 'bad' | 'warn';
}) {
    const warna = nada === 'ok' ? 'text-ok' : nada === 'bad' ? 'text-bad' : nada === 'warn' ? 'text-warn' : 'text-ink';

    const isi =
        teks !== undefined
            ? teks
            : nilai === null || nilai === undefined
              ? kosong
              : nilai.toLocaleString('id-ID');

    return (
        <div className="rounded-wf border border-line/70 bg-surface-2 px-2.5 py-1.5">
            <p className="text-[10px] uppercase tracking-wide text-ink-3">{label}</p>
            <p className={'mt-0.5 font-mono text-sm ' + warna}>{isi}</p>
        </div>
    );
}

function KPI({ label, nilai, nada }: { label: string; nilai: number; nada?: 'ok' | 'bad' }) {
    const warna = nada === 'ok' ? 'text-ok' : nada === 'bad' ? 'text-bad' : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className={'mt-1 font-mono text-2xl font-medium ' + warna}>
                {nilai.toLocaleString('id-ID')}
            </p>
        </div>
    );
}
