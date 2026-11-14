import { router } from '@inertiajs/react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';

/**
 * Riwayat pelanggaran saya - wireframe-peran.html plate 29.
 *
 * =====================================================================
 * Siswa membaca catatannya sendiri, dan itu keputusan yang disengaja
 * =====================================================================
 * Plate 29 menulis konsekuensinya apa adanya: catatan yang dapat dibaca oleh
 * yang bersangkutan akan selalu ditanyakan - dan itu justru tujuannya.
 * Catatan yang tidak dapat dibantah dengan data adalah catatan yang tidak
 * layak dipakai untuk menjatuhkan sanksi.
 *
 * Karena itu yang dikirim ke laman ini adalah catatan yang SAMA dengan yang
 * dibaca pengawas di plate 16: jam, jenis, tingkat, dan tindakannya. Bukan
 * versi yang diperhalus.
 *
 * ---------------------------------------------------------------------
 * HANYA BACA, dan tidak ada satu pun tombol tulis di laman ini
 * ---------------------------------------------------------------------
 * `exam_violations` append-only sejak migrasinya, dan tidak ada rute yang
 * mengubahnya. Yang ditawarkan plate 29 adalah CARA MENGAJUKAN KEBERATAN -
 * menghubungi Pengawas ruang atau Admin sekolah - dan itu kalimat, bukan
 * tombol yang tidak melakukan apa-apa.
 *
 * ---------------------------------------------------------------------
 * Tindakan pengawas tidak ikut dihitung sebagai kejadian
 * ---------------------------------------------------------------------
 * Angka "Total kejadian" dihitung server dari pelanggaran siswa saja. Baris
 * "Kunci dibuka pengawas" tetap TAMPIL di riwayat - ia bagian dari apa yang
 * terjadi pada ujian itu - tetapi ia diberi label tingkatnya sendiri dan
 * tidak menambah hitungan. Menambahkannya berarti menuduh anak atas
 * perbuatan orang dewasa.
 *
 * ---------------------------------------------------------------------
 * Redesign: tiga angka tidak lagi tiga kotak terpisah
 * ---------------------------------------------------------------------
 * Sampai redesign ini ketiga angkanya berdiri sebagai tiga kotak bersudut
 * membulat di ATAS penyaring event, padahal penyaring itu yang menentukan
 * angka mana yang sedang dibaca. Sekarang angkanya satu papan dengan tiga
 * sel yang berbagi garis, dan urutannya: saring dulu, angka menyusul.
 *
 * "Terberat" dan "Pernah dikunci" juga kehilangan lambangnya. Nilainya
 * sekarang angka besar dengan satuannya ditulis di bawahnya - dan
 * "sebelumnya tidak pernah" adalah kalimat yang lebih jujur daripada `0`
 * telanjang pada label yang tidak menerangkan apa itu nol.
 */

type OpsiEvent = { id: number | null; nama: string | null; jumlah: number };

type Riwayat = {
    id: number;
    jam: string | null;
    tanggal: string | null;
    jenis: string;
    tindakan: string;
    tingkat: string;
    nada: string;
    mapel: string | null;
    /**
     * Nama event per baris - ditambahkan 2026-11-14.
     *
     * Penyaring event di atas riwayat hanya muncul saat siswa punya LEBIH
     * DARI SATU event berisi catatan. Dengan satu event ia tidak dirender
     * sama sekali, sehingga tanpa baris ini riwayatnya berdiri tanpa satu
     * pun keterangan tentang ujian mana yang dimaksud.
     */
    event: string | null;
    durasi: string | null;
    tindakan_pengawas: boolean;
};

type Props = {
    nama: string;
    event: OpsiEvent[];
    terpilih: number | null;
    angka: { total: number; terberat: number; pernah_dikunci: number };
    riwayat: Riwayat[];
};

/** Nada sel angka - dipetakan di satu tempat, sama seperti SelStatus di beranda. */
const NADA_ANGKA: Record<string, string> = {
    ok: 'text-ok',
    warn: 'text-warn',
    bad: 'text-bad',
};

export default function Pelanggaran({ nama, event, terpilih, angka, riwayat }: Props) {
    const ganti = (id: number | null) => {
        // Penyaringan dikerjakan server, bukan di peramban: daftar
        // pelanggaran satu sekolah bisa panjang, dan menyaringnya di sini
        // berarti mengirimkan seluruh riwayat lebih dulu supaya bisa
        // disaring keluar.
        router.get('/siswa/pelanggaran', id === null ? {} : { event: id }, { preserveScroll: true });
    };

    return (
        <KerangkaSiswa
            judul="Riwayat pelanggaran"
            tabbarAktif="pelanggaran"
            kiri={<span className="truncate text-[12px] text-ink">Riwayat · {nama}</span>}
            kanan={
                <span className="border border-line-2 px-1.5 py-0.5 text-[11px] text-ink-2">
                    hanya baca
                </span>
            }
        >
            {/*
             * Penyaring event sebagai SEL yang berbagi garis - bukan tombol
             * yang mengambang. Sel yang sedang hidup mengisi tinta, sama
             * seperti tabbar: itu satu-satunya tanda "sedang dibaca" yang
             * dipakai konsol ini.
             */}
            {event.length > 1 && (
                <nav className="sel overflow-hidden">
                    {event.map((e) => {
                        const on = e.id === terpilih;

                        return (
                            <button
                                key={e.id ?? 'tanpa-event'}
                                type="button"
                                onClick={() => ganti(e.id)}
                                aria-current={on ? 'true' : undefined}
                                className={
                                    'truncate px-2 py-2 text-[11px] ' +
                                    (on ? 'sel-hidup' : 'text-ink-2')
                                }
                            >
                                {e.nama ?? 'Tanpa nama'}
                                {e.jumlah > 0 && (
                                    <span className={'angka ml-1 ' + (on ? '' : 'text-ink-2')}>
                                        {e.jumlah}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </nav>
            )}

            {/*
             * Tiga angka, satu papan. Angkanya yang besar; labelnya satu
             * tingkat di bawahnya. "Terberat" bersatuan tingkat, dan itu
             * ditulis - angka 0 tanpa satuan tidak menerangkan apa pun.
             */}
            <section className="daftar grid grid-cols-3 overflow-hidden">
                <SelAngka
                    label="Total kejadian"
                    nilaiTeks={String(angka.total)}
                    satuan=""
                    nada={angka.total > 0 ? 'warn' : undefined}
                    akhir={false}
                />
                {/* "Terberat" adalah tingkat keberatan satu PERISTIWA (1..5),
                    bukan tingkat eskalasi (0..3). Dua hal berbeda yang pernah
                    tertukar di blueprint - lihat ExamViolation::labelTingkat(). */}
                <SelAngka
                    label="Terberat"
                    nilaiTeks={angka.terberat > 0 ? String(angka.terberat) : 'tidak ada'}
                    satuan={angka.terberat > 0 ? 'tingkat' : ''}
                    nada={angka.terberat >= 5 ? 'bad' : angka.terberat > 0 ? 'warn' : undefined}
                    akhir={false}
                />
                <SelAngka
                    label="Pernah dikunci"
                    nilaiTeks={angka.pernah_dikunci > 0 ? String(angka.pernah_dikunci) : 'belum'}
                    satuan={angka.pernah_dikunci > 0 ? 'kali' : ''}
                    nada={angka.pernah_dikunci > 0 ? 'bad' : undefined}
                    akhir
                />
            </section>

            <section className="daftar overflow-hidden">
                <header className="daftar-kepala">
                    <h2>Kejadian</h2>
                    {riwayat.length > 0 && (
                        <span className="angka ml-auto text-[12px] text-ink-2">
                            {riwayat.length}
                        </span>
                    )}
                </header>

                {riwayat.length === 0 ? (
                    <div className="px-3 py-6">
                        <p className="text-[13px] leading-relaxed text-ink-2">
                            Tidak ada catatan pelanggaran untuk event ini.
                        </p>
                    </div>
                ) : (
                    riwayat.map((r) => (
                        <div key={r.id} className="daftar-baris px-3 py-2.5">
                            <div className="flex items-center gap-2">
                                <span className="min-w-0 flex-1 truncate text-[13px] font-medium text-ink">
                                    {r.jenis}
                                </span>
                                <span className="angka shrink-0 text-[11px] text-ink-2">
                                    {r.jam ?? 'tanpa jam'}
                                </span>
                                <LencanaTingkat nada={r.nada}>{r.tingkat}</LencanaTingkat>
                            </div>
                            {/*
                             * Nama event berdiri SENDIRI di antara jenis dan
                             * keterangan waktu - ditambahkan 2026-11-14.
                             *
                             * Ia menjawab "yang mana", sementara baris di
                             * bawahnya menjawab "kapan". Menyatukannya ke
                             * baris bawah berarti tiga keterangan berderet,
                             * dan satu di antaranya (mapel) dipotong lebih
                             * dulu di ponsel 26rem.
                             *
                             * Penyaring event di ATAS laman belum cukup:
                             * ia hanya dirender saat siswa punya lebih dari
                             * satu event berisi catatan.
                             */}
                            <p className="catatan mt-0.5 truncate">
                                {r.event ?? 'Tanpa event'}
                            </p>

                            {/*
                             * Mapel, tanggal dan durasi sebagai SEL, bukan
                             * satu kalimat bertitik pemisah.
                             *
                             * Lantai 63 menamai `A · B · C` sebagai tanda baca
                             * templat: titik tengah yang berdiri di antara
                             * segala hal tidak menerangkan hubungan apa pun.
                             *
                             * Nilai yang kosong tetap DILEWATI - sel kosong
                             * lebih buruk daripada kalimat yang kehilangan
                             * satu bagiannya.
                             */}
                            <div className="mt-0.5 flex items-center gap-2 text-[11px] text-ink-2">
                                <span className="min-w-0 flex-1 truncate">
                                    {r.mapel ?? 'Tanpa mapel'}
                                </span>
                                {r.tanggal && <span className="angka shrink-0">{r.tanggal}</span>}
                                {r.durasi && <span className="angka shrink-0">{r.durasi}</span>}
                                <span className="shrink-0">{r.tindakan}</span>
                            </div>
                        </div>
                    ))
                )}
            </section>

            <p className="keterangan">
                Anda dapat mengajukan keberatan atas catatan ini kepada Pengawas ruang atau Admin
                sekolah. Catatan pelanggaran tidak dapat dihapus.
            </p>
        </KerangkaSiswa>
    );
}

/** Satu sel angka. Label di atas, angka besar, satuannya di bawah. */
function SelAngka({
    label,
    nilaiTeks,
    satuan,
    nada,
    akhir,
}: {
    label: string;
    nilaiTeks: string;
    satuan: string;
    nada?: 'ok' | 'warn' | 'bad';
    akhir: boolean;
}) {
    // Kata ("tidak ada", "belum") tidak pernah dicetak sebesar angka. Angka
    // di sel ini adalah satuan hitung, dan memberi kata ukuran angka membuat
    // tiga sel terlihat seperti tiga nilai yang sama pentingnya.
    const kata = /\D/.test(nilaiTeks);

    // Nol BUKAN sinyal - lihat pemanggilnya. Rust menandai perbuatan atau
    // pilihan, dan "0 kejadian" adalah kebalikan dari keduanya.
    const nol = nilaiTeks === '0';

    return (
        <div className={'min-w-0 px-2.5 py-2 ' + (akhir ? '' : 'border-r border-line')}>
            <span className="block truncate text-[11px] text-ink-2">{label}</span>
            <span
                className={
                    'mt-1 block truncate leading-none ' +
                    (kata ? 'text-[13px] font-medium ' : 'angka-hitung text-[19px] ') +
                    (nadakah(nada, nol))
                }
            >
                {nilaiTeks}
            </span>
            {satuan && <span className="mt-1 block truncate text-[11px] text-ink-2">{satuan}</span>}
        </div>
    );
}

/**
 * Warna satu sel angka.
 *
 * Nol selalu netral. Rust, amber dan hijau menandai sesuatu yang TERJADI;
 * memberi nol warna sinyal berarti halaman paling berteriak justru pada
 * keadaan yang paling tenang - dan pada laman ini, saat tidak ada kejadian,
 * warna itu satu-satunya warna di seluruh layar.
 */
function nadakah(nada: 'ok' | 'warn' | 'bad' | undefined, nol: boolean): string {
    if (nol) {
        return 'text-ink-2';
    }

    return nada ? NADA_ANGKA[nada] : 'text-ink';
}

/** Tingkat keberatan sebagai sel bergaris, bukan lencana bulat. */
function LencanaTingkat({ nada, children }: { nada: string; children: string }) {
    const warna: Record<string, string> = {
        ok: 'bg-ok-bg text-ok',
        warn: 'bg-warn-bg text-warn',
        bad: 'bg-bad-bg text-bad',
        info: 'bg-info-bg text-info',
        mute: 'bg-surface-2 text-ink-2',
    };

    return (
        <span
            className={
                'shrink-0 border border-line-2 px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap ' +
                (warna[nada] ?? warna.mute)
            }
        >
            {children}
        </span>
    );
}
