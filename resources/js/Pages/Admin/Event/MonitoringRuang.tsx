import { useState } from 'react';
import type { FormEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { tanyaTeks } from '@/Components/dialog';
import { Chip, Field, Inbox, Kotak, Modal, Tombol, kelasInput } from '@/Components/ui';

/**
 * Laman 20b - keadaan per peserta SATU ruang-sesi.
 *
 * =====================================================================
 * Mengapa laman sendiri, dibuka di tab baru
 * =====================================================================
 * Papan monitoring lintas ruang menjawab "ruang mana yang tertinggal", dan
 * itu pertanyaan yang dijawab rekap. Daftar nama ada karena pertanyaan lain
 * yang tidak bisa dijawab rekap: "anak ini sudah sampai nomor berapa". Rekap
 * menjawab berapa BANYAK yang sudah dikerjakan; ia tidak menjawab soal MANA
 * yang sedang dibuka, dan untuk anak yang melompat ke nomor 40 lalu kembali
 * ke nomor 3 keduanya berbeda jauh.
 *
 * Sampai 2026-11-09 daftar itu dibuka DI DALAM kartu ruang di papan lintas
 * ruang. Tiga hal sekaligus salah dengan bentuk itu:
 *
 *   1. Papan lintas ruang disegarkan terus selama ujian berjalan, dan
 *      membawa daftar seribu anak di payload-nya berarti setiap penyegaran
 *      mengirim ulang daftar yang belum tentu dibuka.
 *   2. Dua puluh ruang yang daftarnya bisa dibuka di satu halaman
 *      menghasilkan halaman yang tidak lagi bisa dipindai - dan kemampuan
 *      memindainya itulah gunanya papan itu.
 *   3. Admin yang menelusuri satu anak sedang mengerjakan satu pekerjaan.
 *      Tab tersendiri membuatnya tidak hilang saat ia kembali ke rekap.
 *
 * Laman ini karena itu adalah kebalikan dari papan lintas ruang: SATU ruang,
 * seluruh anaknya, dan tidak ada angka lintas ruang yang perlu dipindai.
 *
 * =====================================================================
 * Nomor kursi ikut, dan itu yang menyambung laman ini ke ruang fisiknya
 * =====================================================================
 * Admin membaca laman ini untuk menelusuri satu anak, dan langkah berikutnya
 * hampir selalu menghubungi pengawas ruangnya. Yang bisa disampaikan saat
 * itu adalah NOMOR KURSI - bukan nomor peserta, yang tidak tertulis di mana
 * pun yang bisa dilihat pengawas. Nomornya datang dari server, dihitung
 * `NomorKursi`: aturan yang sama dengan papan pengawas, denah, dan tablo.
 *
 * =====================================================================
 * Nomor soal datang dari denyut anak, dan itu berarti ia bisa tertinggal
 * =====================================================================
 * Nomor yang tampil adalah nomor yang dilaporkan peramban anak pada denyut
 * terakhirnya - tiap tiga puluh detik. Anak yang baru berpindah soal masih
 * tampil di nomor lamanya sampai denyut berikutnya, dan itu memang
 * keadaannya: angka yang lebih baru daripada denyutnya tidak ada.
 *
 * Karena itu kolom jam denyutnya ikut ditampilkan. Angka "sampai nomor 12"
 * yang disertai "07:41:20" bisa dinilai sendiri oleh Admin; angka telanjang
 * tidak bisa.
 *
 * Yang membacanya juga perlu tahu satu hal lagi: nomornya DILAPORKAN
 * PERAMBAN SISWA, sehingga ia isyarat, bukan bukti. Yang tidak bisa
 * dilaporkan adalah jumlah jawaban yang tersimpan - dan itulah yang
 * dihitung server di kolom Progres.
 *
 * =====================================================================
 * Dua jawaban atas "sampai mana", dan keduanya memang perlu
 * =====================================================================
 * Kolom "Sampai nomor" menjawab apa yang SEDANG DILIHAT anak. Kolom
 * "Dikerjakan" menjawab soal MANA yang jawabannya sudah tersimpan - dan itu
 * pertanyaan yang berbeda, bukan pertanyaan yang sama dengan angka yang lebih
 * tepat.
 *
 * Anak yang sudah sampai nomor 12 tetapi baru menyimpan tiga jawaban sedang
 * melewati sebelas soal tanpa menjawabnya; anak yang menjawab sampai nomor
 * 40 tetapi melaporkan nomor 3 sedang menengok kembali ke belakang. Keduanya
 * tampak sama di kolom denyut, dan keduanya perlu ditanyai pengawasnya -
 * dengan pertanyaan yang berbeda.
 *
 * Karena itu nomor soalnya ditempel di sini sebagai daftar, bukan sebagai
 * satu angka. "Belum: 4, 7, 8" bisa dibacakan lewat telepon; "37 dari 40"
 * tidak bisa.
 *
 * =====================================================================
 * Nomor yang dibaca di sini adalah nomor YANG DILIHAT ANAK
 * =====================================================================
 * Pada ujian yang mengacak soal, nomor 5 menunjuk soal yang BERBEDA untuk
 * tiap anak - jadi daftar bernomor itu berlaku per anak, dan tidak bisa
 * dijumlahkan lintas anak.
 *
 * =====================================================================
 * Butir soalnya dibuka per anak, di dalam modal
 * =====================================================================
 * Sampai 2026-11-10 statistik per butir digambar di sini sebagai satu tabel
 * milik seluruh ruangan. Tabel itu menjawab pertanyaan yang salah: Admin
 * membuka laman ini untuk menelusuri SATU anak, dan pada ujian yang
 * mengacak soal baris "nomor 5" di tabel campuran itu bukan soal yang
 * sedang dilihat anak mana pun secara khusus.
 *
 * Yang tinggal di luar adalah angka ruangannya - berapa butir, berapa sudah
 * dijawab seluruh ruangan - karena itu yang menjawab "ruang ini tertinggal
 * atau tidak". Daftar butirnya pindah ke modal di balik nama anaknya, dan
 * di sana nomornya nomor anak ITU, disambungkan ke soal ujiannya lewat
 * `butir[].soal_id` - bukan lewat nomor, yang justru berbeda antar anak.
 *
 * =====================================================================
 * Yang TIDAK ada di laman ini: koreksi presensi
 * =====================================================================
 * Admin mengoreksi PRESENSI, dan itu ada di laman Presensi. Laman ini tidak
 * mengubah kehadiran siapa pun.
 *
 * =====================================================================
 * Yang ADA sejak 2026-11-12: reset dan penghentian pengerjaan
 * =====================================================================
 * Dua tombol di baris anak - `Reset` dan `Hentikan` - dan keduanya
 * mengubah pengerjaan anaknya, bukan presensinya. Alasannya ada di sini
 * dan bukan di laman lain: Admin yang menemukan anak yang terputus di menit
 * pertama sedang berdiri di papan ini, dan mengirimnya ke laman lain berarti
 * ia harus mencari anak yang sama untuk kedua kalinya.
 *
 * Keduanya menuntut kalimat tertulis, dan `Hentikan` justru MENGIRIM
 * kalimat itu ke layar siswanya. Lihat catatan `TindakanPeserta`.
 */
/**
 * Satu butir seperti yang dilihat SATU anak.
 *
 * `nomor` adalah nomor di layar anak (`attempt_questions.display_order`);
 * `soal_id` menunjuk baris di `soal` - dan itu perantaranya. Pada ujian yang
 * mengacak soal, nomor 5 menunjuk soal yang berbeda untuk tiap anak, jadi
 * nomor saja tidak cukup untuk mencari statistiknya.
 */
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
    /**
     * Keadaan layar penuh menurut denyut terakhir - `null` bila anak belum
     * pernah mengirim denyut sama sekali.
     *
     * Ia BISA BASI, dan itu sebabnya ia bukan penghitung pelanggaran:
     * kejadiannya dicatat terpisah sebagai `keluar_layar_penuh`, dan yang
     * dibaca di sini hanya keadaannya sekarang.
     */
    layar_penuh: boolean | null;
    /** Berapa kali layar anak terbagi selama ujian - jumlah PERISTIWA. */
    layar_terbagi: number;
    terkunci: boolean;
    kunci_alasan: string | null;
    /**
     * Nomor soal yang jawabannya sudah tersimpan, urut naik.
     *
     * Ketiganya `null` untuk anak yang belum membuka ujiannya - dan itu
     * berbeda dari `[]`, yang berarti "tidak ada yang belum dikerjakan".
     */
    dikerjakan: number[] | null;
    /** Nomor soal yang belum dijawab, urut naik. */
    belum: number[] | null;
    /** Nomor soal yang ditandai ragu oleh anaknya sendiri. */
    ditandai: number[] | null;
    /** Seluruh butir anak ini berurut, beserta soal yang ditunjuknya. */
    butir: Butir[] | null;
};

type Soal = {
    id: number;
    /** Urutan UJIAN, bukan urutan satu anak - lihat catatan berkas ini. */
    nomor: number;
    jenis: string | null;
    jenis_label: string | null;
    jenis_pendek: string | null;
    judul: string | null;
    bobot: number;
    /** False untuk uraian: `benar`/`salah` di bawahnya lalu selalu null. */
    otomatis: boolean;
    peserta: number;
    sudah: number;
    belum: number;
    ditandai: number;
    /** Berapa jawaban yang sudah punya nilai; sisanya masih null. */
    dinilai: number;
    /** Null selama belum ada satu pun yang dinilai - lihat catatan berkas. */
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
    monitoring_url: string;
};

export default function EventMonitoringRuang({
    event,
    ruang,
    angka,
    anak,
    soal,
    angka_soal,
    monitoring_url,
}: Props) {
    // Anak yang rincian soalnya sedang dibuka. `null` berarti modalnya tutup.
    const [rincian, setRincian] = useState<Anak | null>(null);

    // Reset atau hentikan, beserta anaknya. `null` berarti modalnya tutup.
    const [tindakan, setTindakan] = useState<{ anak: Anak; jenis: 'reset' | 'hentikan' } | null>(
        null,
    );

    /**
     * Buka kunci satu peserta, dengan alasan yang diminta lebih dulu.
     *
     * Alasannya ditanyakan di sini, bukan dikirim kosong: `catatPembukaan()`
     * menulis alasannya ke riwayat yang dibaca SISWA di
     * `/siswa/pelanggaran`, dan baris "Dibuka dari papan monitoring Admin"
     * yang generik membuat anak membaca alasan yang tidak menerangkan apa
     * pun. Batal pada dialog berarti tidak ada permintaan sama sekali.
     *
     * `wajib: false` mengikuti aturan servernya (`nullable`): membuka kunci
     * yang jelas alasannya tidak boleh tertahan hanya karena Admin memilih
     * tidak menulis. Kosong tetap terkirim sebagai kosong, dan server yang
     * mengisi kalimat bawaannya.
     */
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

    /**
     * Dua tindakan yang menuntut kalimat dari Admin - lihat catatan
     * `TindakanPeserta` di bawah.
     *
     * Keduanya lewat modal, BUKAN dialog satu kolom seperti `bukaKunci`:
     * kalimat yang ditulis di sini dibaca siswa di layarnya sendiri, dan
     * keduanya butuh ruang untuk menjelaskan akibatnya sebelum Admin
     * menekan tombol.
     */
    const tindakanPeserta = (a: Anak, jenis: 'reset' | 'hentikan') => {
        setTindakan({ anak: a, jenis });
    };

    return (
        <AdminLayout
            judul={`${ruang.kode} · ${ruang.nama ?? 'Ruang'}`}
            breadcrumb={`/admin/events · ${event.kode} · Monitoring · ${ruang.kode}`}
            aksi={
                <>
                    {/* Kembali ke papan rekap sengaja navigasi Inertia BIASA,
                        bukan tab baru: yang sedang dibaca di sini adalah satu
                        ruang, dan jalan pulangnya harus satu klik. Tab baru
                        dipakai arah sebaliknya - dari rekap ke sini - supaya
                        posisi gulir dan saringan di rekap tidak hilang. */}
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
                padaRincian={setRincian}
                padaBukaKunci={bukaKunci}
                padaTindakan={tindakanPeserta}
            />

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
                {/* `key` berisi id barisnya: lihat catatan `TindakanPeserta`.
                    Tanpa itu, membuka modal untuk anak kedua menampilkan
                    kalimat yang diketik untuk anak pertama. */}
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

            <div className="mt-3">
                <Kotak judul="Catatan pembacaan laman ini">
                    <div className="space-y-2 text-xs leading-relaxed text-ink-2">
                        <p>
                            &ldquo;Terputus&rdquo; bukan keadaan yang disimpan, melainkan
                            yang disimpulkan: peserta yang tidak mengirim kabar lebih dari
                            90 detik. Karena itu angkanya bisa naik turun sendiri tanpa ada
                            yang mengubah apa pun &mdash; dan itu memang keadaannya.
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
                            &ldquo;Sampai nomor&rdquo; &mdash; keduanya menjawab pertanyaan
                            yang berbeda. Yang pertama dihitung dari jawaban yang
                            benar-benar tersimpan, yang kedua dari nomor yang dilaporkan
                            peramban. Anak yang sudah sampai nomor 12 tetapi baru menyimpan
                            tiga jawaban sedang melewati sebelas soal tanpa menjawabnya;
                            anak yang menjawab sampai nomor 40 tetapi melaporkan nomor 3
                            sedang menengok kembali ke belakang. Keduanya tampak sama di
                            kolom denyut, dan keduanya perlu ditanyai &mdash; dengan
                            pertanyaan yang berbeda.
                        </p>
                        <p>
                            Butir soal tiap anak dibuka lewat tombol{' '}
                            <span className="text-ink-2">Butir soal</span> di barisnya, dan di
                            sana nomornya nomor yang dilihat anak itu sendiri. Pada ujian yang
                            mengacak soal, nomor yang sama menunjuk soal yang berbeda untuk tiap
                            anak &mdash; dan itulah sebabnya rinciannya per anak, bukan satu
                            tabel untuk seluruh ruangan yang akan mencampur soal-soal berbeda
                            menjadi satu baris.
                        </p>
                        <p>
                            Kolom &ldquo;Benar&rdquo; pada tiap butir baru terisi setelah
                            penilaian berjalan &mdash; penilaian otomatis berjalan sesudah
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

/**
 * Statistik satu baris per SOAL - dan satu barisnya adalah satu soal ujian.
 *
 * =====================================================================
 * Mengapa nomornya urutan ujian, bukan nomor yang dilihat anak
 * =====================================================================
 * Pada ujian yang mengacak soal, satu nomor menunjuk soal berbeda di tiap
 * attempt: menjumlahkan "berapa yang menjawab nomor 5" akan mencampur empat
 * soal yang berbeda menjadi satu baris, dan barisnya jadi tidak bermakna.
 * Yang dijumlahkan di sini adalah soalnya, dan nomor di kolom pertama adalah
 * posisinya di paket - penunjuk, bukan klaim tentang layar anak.
 *
 * =====================================================================
 * "Belum" di sini berarti belum dijawab OLEH SIAPA PUN
 * =====================================================================
 * Bukan "belum dikerjakan anak ini". Angka yang besar di kolom itu adalah
 * keadaan yang wajar pada sepuluh menit pertama ujian, dan laman ini tidak
 * boleh membuatnya tampak seperti kegagalan. Karena itu bilahnya bukan
 * peringatan: yang diberi nada adalah barisnya yang benar-benar tertinggal
 * setelah sebagian besar ruangan melewatinya.
 *
 * =====================================================================
 * Baris yang tidak punya "Benar" bukan baris yang rusak
 * =====================================================================
 * Tidak ada satu pun soal yang bernilai selama ujiannya masih berjalan:
 * penilaian otomatis bekerja sesudah ujian ditutup, dan uraian menunggu
 * koreksi guru. `is_correct` yang null berarti BELUM DINILAI - bukan salah -
 * dan kolomnya karena itu ditampilkan sebagai kalimat, bukan nol.
 */
/**
 * Ringkasan ruangan: berapa butir, berapa yang sudah dijawab seluruh ruangan.
 *
 * =====================================================================
 * Rinciannya pindah ke modal per anak, dan itu memang tempatnya
 * =====================================================================
 * Sampai 2026-11-10 daftar butirnya ada di sini, sebagai satu tabel milik
 * seluruh ruangan. Tabel itu menjawab pertanyaan yang salah: Admin membuka
 * laman ini untuk menelusuri SATU anak - dan pada ujian yang mengacak soal
 * nomor 5 anak yang satu menunjuk soal yang berbeda dari nomor 5 anak
 * sebelahnya, sehingga tabel campuran itu justru tidak menjawab apa pun
 * tentang anak yang sedang ia tanyakan.
 *
 * Yang tinggal di sini adalah angka ruangannya, karena itulah yang menjawab
 * "ruang ini tertinggal atau tidak" - pertanyaan yang memang milik rekap.
 * Daftar butir per anak ada di modal, di balik nama anaknya.
 */
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
                    <em>jawaban yang tersimpan</em> &mdash; bukan dari nomor yang dilaporkan
                    peramban anak. Daftar butir satu anak, beserta soal mana yang belum
                    dijawabnya, dibuka lewat tombol <span className="text-ink-2">Butir soal</span>{' '}
                    di barisnya.
                </p>
            </Kotak>
        </div>
    );
}

/**
 * Rincian butir SATU anak - isi modal, dibuka dari baris namanya.
 *
 * =====================================================================
 * Dua daftar, dan keduanya perlu ada
 * =====================================================================
 * Yang pertama adalah butir anak ini apa adanya, berurut nomor yang ia lihat
 * di layarnya: nomor, jenis soal, judulnya, dan keadaannya - sudah dijawab,
 * belum, atau ditandai ragu. Itu yang dibacakan Admin ke pengawas lewat
 * telepon, dan karena itu nomornya nomor anak ini, bukan nomor paket.
 *
 * Yang kedua adalah statistik soal itu untuk SELURUH ruangan - berapa anak
 * yang sudah menjawabnya - dan itu diambil lewat `soal_id`, bukan lewat
 * nomor. Pada ujian yang mengacak soal, menyambungkannya lewat nomor akan
 * menempelkan "31 anak sudah menjawab" dari soal yang sama sekali lain.
 *
 * =====================================================================
 * Kolom "Benar" kosong selama ujiannya berjalan
 * =====================================================================
 * Penilaian otomatis berjalan sesudah ujian ditutup, jadi seluruh jawaban
 * objektif masih belum bernilai saat laman ini paling sering dibuka. Yang
 * tertulis karena itu &ldquo;belum dinilai&rdquo;, bukan &ldquo;0 benar&rdquo;
 * - nol terbaca sebagai "tidak ada yang benar".
 */
function DetailButir({
    anak,
    soal,
    angka,
}: {
    anak: Anak;
    soal: Soal[];
    angka: Props['angka_soal'];
}) {
    // Peta soal_id -> baris statistiknya. Dibangun sekali untuk seluruh modal;
    // mencari dengan `find()` di dalam loop akan mengubah modal berisi empat
    // puluh butir menjadi empat puluh kali penyusuran.
    const statistik = new Map(soal.map((s) => [s.id, s]));

    if (anak.butir === null) {
        return (
            <div className="space-y-2 text-xs leading-relaxed text-ink-2">
                <p>
                    Butir soal anak ini <strong>belum tertulis</strong> &mdash; ia belum
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

/**
 * Satu butir di modal, dari sudut pandang SATU anak.
 *
 * Nomor di kiri adalah nomor di layar anak itu. Statistik ruangannya -
 * "berapa anak lain sudah menjawab soal ini" - ditempelkan di kanan lewat
 * `soal_id`, dan `null` bila barisnya tidak ketemu: itu terjadi kalau butirnya
 * ditulis mesin ujian untuk soal yang sejak itu dihapus dari paket, dan
 * menampilkan nol untuknya akan mengarang angka.
 */
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

/**
 * Daftar anak satu ruang, berurut nama.
 *
 * Urutannya nama, bukan nomor kursi: Admin memakai daftar ini untuk mencari
 * satu anak yang dilaporkan bermasalah, dan ia tahu namanya - bukan nomor
 * kursinya. Urutan yang sama dipakai papan pengawas, sehingga baris yang
 * sama menempati urutan yang sama di kedua papan. Nomor kursinya tetap
 * ditampilkan, karena ia yang disebutkan saat menghubungi pengawas.
 */
function DaftarAnak({
    anak,
    ruang,
    padaRincian,
    padaBukaKunci,
    padaTindakan,
}: {
    anak: Anak[];
    ruang: string;
    padaRincian: (a: Anak) => void;
    padaBukaKunci: (a: Anak) => void;
    padaTindakan: (a: Anak, jenis: 'reset' | 'hentikan') => void;
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

                            {/* Layar, dan mengapa dua chip dan bukan satu.
                                `layar_penuh === false` adalah KEADAAN yang
                                dilaporkan denyut - bisa basi, dan anak yang
                                baru saja kembali ke layar penuh masih tampil
                                di sini sampai tiga puluh detik berikutnya.
                                Ia berwarna `warn`, bukan `bad`: yang
                                terpenting adalah jumlah kejadian di
                                sebelahnya. */}
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

                            {/* Buka kunci, DI SINI dan bukan di laman
                                pengawas saja - lihat catatan
                                `bukaKunci()` di PemantauanEventController.
                                Tautan ruang pengawas 404 untuk siswa yang
                                sudah berpindah sesi, dan justru anak itulah
                                yang Admin lihat terkunci di papan ini.

                                Tombolnya hanya muncul pada baris yang memang
                                terkunci: tombol yang selalu ada dan selalu
                                ditolak mengajari Admin untuk tidak
                                memercayai papannya. */}
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

                            {/* Dua tindakan yang mengubah nasib pengerjaan
                                anak - lihat `TindakanPeserta`.

                                Ketersediaannya mengikuti keadaan barisnya,
                                dan itu bukan kenyamanan: `PengendaliPengerjaan`
                                MENOLAK reset atas attempt yang sudah batal
                                dan penghentian atas apa pun yang tidak
                                sedang berjalan. Tombol yang selalu ada dan
                                selalu ditolak mengajari Admin untuk tidak
                                memercayai papannya - alasan yang sama
                                dengan `Buka kunci` di atas.

                                `Hentikan` sengaja tidak muncul pada anak yang
                                sudah terkunci: pesannya akan menimpa
                                penghentian sebelumnya, dan Admin yang
                                menulis pesan kedua biasanya bermaksud
                                membukanya. */}
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

                            {/* Rincian butirnya dibuka di modal, bukan
                                dituliskan di baris ini: satu anak bisa
                                membawa empat puluh nomor, dan sepuluh baris
                                seperti itu membuat daftar ruangannya tidak
                                lagi bisa dipindai - padahal memindai nama
                                itulah gunanya daftar ini. */}
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
 * Reset atau hentikan SATU peserta - isi modal, dibuka dari barisnya.
 *
 * =====================================================================
 * Dua tindakan, dan keduanya menuntut kalimat
 * =====================================================================
 * `Reset` membatalkan attempt terakhir lalu mengembalikan anaknya ke gerbang:
 * kerjanya mengulang dari soal pertama, dengan urutan dan deadline baru, dan
 * NILAI atas pengerjaan lamanya hilang. `Hentikan` mengunci pengerjaannya di
 * tempat, dan kalimat yang ditulis di sini adalah yang dibaca anak di
 * layarnya sendiri (`lock_reason` -> `/siswa/ujian/{id}/terkunci`).
 *
 * Karena itu keduanya lewat modal, bukan `window.prompt`: yang kedua butuh
 * keterangan bahwa pesannya TAMPIL DI LAYAR SISWA - sebuah kotak prompt
 * peramban tidak bisa menampilkan itu, dan Admin yang tidak tahu akan
 * menulis "cek" pada kolom yang dibaca seorang anak.
 *
 * =====================================================================
 * `key` wajib berisi id barisnya
 * =====================================================================
 * `Modal` mengembalikan `null` dari dalam dan tidak melepas anaknya, jadi
 * komponen ini HIDUP TERUS saat modalnya ditutup. Tanpa `key`, membuka modal
 * untuk anak kedua menampilkan kalimat yang tadi diketik untuk anak pertama -
 * dan Admin yang tidak menyadarinya akan mengirim pesan itu ke anak yang
 * salah. `key` di laman atas memaksa komponennya lahir ulang tiap baris.
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

    // Kedua kolom dikirim sekaligus; pengendalinya memvalidasi kolom yang
    // ia pakai dan mengabaikan yang lain - jadi satu form bisa melayani dua
    // endpoint tanpa dua komponen yang isinya hampir sama.
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
                    // Formulirnya dikosongkan SEBELUM ditutup, bukan sesudah:
                    // modal ini tidak dilepas saat ditutup (lihat catatan
                    // `key` di atas), sehingga kalimat lama akan muncul lagi
                    // bila Admin membuka anak berikutnya tanpa `key`.
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

/**
 * Satu baris ringkas butir satu anak, dan pintu ke rinciannya.
 *
 * =====================================================================
 * Daftar nomornya, bukan satu angka
 * =====================================================================
 * "37 dari 40" menjawab berapa banyak; ia tidak menjawab soal MANA, dan
 * justru itu yang dibacakan Admin ke pengawas lewat telepon. Daftarnya
 * karena itu tetap ditulis di sini, dipisah koma, supaya jawabannya bisa
 * dibaca tanpa membuka apa pun.
 *
 * Nomornya di sini adalah nomor YANG DILIHAT ANAK - pada ujian yang
 * mengacak soal, nomor itu tidak sama dengan nomor anak di kursi
 * sebelahnya.
 *
 * Batas tampilannya dijaga: ruang dengan anak yang belum menyentuh ujiannya
 * akan membanjiri barisnya dengan empat puluh nomor sekaligus, dan daftar
 * yang tidak bisa dipindai tidak menjawab pertanyaan apa pun. Sisa yang
 * terpotong bisa dibaca utuh di modalnya, per butir, beserta soalnya.
 */
function RingkasanButir({ anak, padaRincian }: { anak: Anak; padaRincian: () => void }) {
    const { dikerjakan, belum, ditandai } = anak;

    if (dikerjakan === null || belum === null || ditandai === null) {
        return (
            <p className="mt-2 border-t border-line/60 pt-2 text-[11px] text-ink-3">
                Butir soalnya belum tertulis &mdash; anak ini belum membuka ujiannya.
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
                        &mdash; anaknya sendiri menandai ini, dan itu yang paling perlu
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

/**
 * Satu angka kecil berlabel.
 *
 * Dua bentuk nilai, karena keduanya memang berbeda jenis: `nilai` untuk
 * bilangan yang boleh nol, dan `teks` untuk yang sudah berbentuk kalimat -
 * "12 / 40 terjawab" tidak boleh lewat `toLocaleString`.
 *
 * `kosong` dipakai untuk membedakan "belum ada kabar" dari nol. Yang pertama
 * ditampilkan sebagai pisah, dan pemisahan itu disengaja.
 */
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
