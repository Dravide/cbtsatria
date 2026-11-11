import { Link } from '@inertiajs/react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { formatPoin } from '@/Components/angka';

/**
 * Nilai saya - wireframe-peran.html plate 28. `/siswa/nilai`.
 *
 * =====================================================================
 * Angkanya sekarang ADA, dan bentuk lamannya tidak berubah
 * =====================================================================
 * Sampai Fase 7 laman ini menggambar KEADAAN - karena belum ada nilai yang
 * tersimpan. Sekarang `SumberNilaiService` mengirim angka sungguhan, dan
 * barisnya mendapat kolom Nilai. Struktur lamannya sengaja TIDAK berubah
 * bentuk: laman yang mengubah bentuknya saat nilai mulai ada adalah laman
 * yang tidak pernah diuji dalam bentuk yang benar-benar dipakai.
 *
 * =====================================================================
 * Redesign: satu papan, dan angka yang bisa dibandingkan ke bawah
 * =====================================================================
 * Laman ini memakai material yang sama dengan beranda - lembar putih bergaris -
 * karena keduanya menjawab pertanyaan yang sama tentang daftar ujian yang
 * sama; yang berbeda hanya kolom terakhirnya. Sebelumnya nilai tinggal di
 * kotak membulat dengan bayangan, dan anak yang berpindah dari beranda ke
 * sini melihat dua benda yang tidak mengaku satu keluarga.
 *
 * Susunan barisnya juga diubah satu hal, dan hanya satu: NILAI pindah ke
 * KIRI baris kedua, di bawah nama mapel, sebagai angka besar. Sebelumnya ia
 * mengambang di kanan dan `objektif`, `uraian`, serta `tuntas` berbaris di
 * bawahnya dengan ukuran 10px - sehingga angka yang justru dicari anak
 * adalah yang paling kecil di barisnya. Di sini angkanya terbaca lebih dulu,
 * lalu rinciannya menjelaskan.
 *
 * =====================================================================
 * Tiga keadaan, tiga kalimat, dan tidak boleh tertukar
 * =====================================================================
 * 1. `dipublikasikan: false` - hasil event belum dibuka. Anak tidak boleh
 *    tahu angkanya, apa pun yang tersimpan di server.
 * 2. `dipublikasikan: true` tetapi `final: false` - hasil sudah dibuka, tetapi
 *    uraiannya masih di meja Guru. Angkanya SEMENTARA. Menampilkannya sebagai
 *    nilai akhir membuat anak menagih Guru atas angka yang masih berubah.
 * 3. `final: true` - nilai akhir. Ini satu-satunya yang boleh disebut "nilai".
 *
 * Ketiganya ditulis apa adanya di laman ini, karena "sudah keluar belum" adalah
 * pertanyaan yang benar-benar dibawa anak ke laman ini - dan jawaban "belum,
 * karena uraianmu masih dikoreksi" lebih berguna daripada tabel kosong.
 *
 * =====================================================================
 * `objektif` baru muncul bila Admin membukanya
 * =====================================================================
 * Plate 21 punya centang "Tampilkan nilai objektif ke siswa". Tanpa centang
 * itu, `objektif` null dan barisnya tidak merinci dari mana angkanya datang -
 * tetapi angka AKHIRnya tetap tampil, karena publikasi sudah membukanya.
 */

type Baris = {
    peserta_id: number;
    mapel: string | null;
    ujian: string | null;
    event: string | null;
    tanggal: string | null;
    tanggal_label: string | null;
    status: string;
    status_label: string;
    dipublikasikan: boolean;
    nilai: number | null;
    objektif: number | null;
    uraian: number | null;
    final: boolean;
    sisa_uraian: number;
    lulus: boolean | null;
};

type Props = {
    nama: string;
    kelas: string | null;
    kkm: number;
    daftar: Baris[];
    ada_nilai: boolean;
    menunggu_terbit: boolean;
    remedial: { mapel: string | null; nilai: number }[];
};

export default function Nilai({
    nama,
    kelas,
    kkm,
    daftar,
    ada_nilai,
    menunggu_terbit,
    remedial,
}: Props) {
    return (
        <KerangkaSiswa
            judul="Nilai saya"
            tabbarAktif="nilai"
            kiri={<span className="truncate text-[12px] text-ink">Nilai · {nama}</span>}
            kanan={
                kelas ? (
                    <span className="border border-line-2 px-1.5 py-0.5 text-[11px] text-ink-2">
                        {kelas}
                    </span>
                ) : undefined
            }
        >
            {!ada_nilai && (
                <p className="keterangan">
                    {menunggu_terbit
                        ? 'Nilai belum tersedia. Hasil ujian baru dipublikasikan setelah seluruh uraian selesai dikoreksi Guru.'
                        : 'Belum ada nilai. Hasil ujian muncul di halaman ini setelah Admin mempublikasikannya untuk seluruh angkatan.'}
                </p>
            )}

            {/*
             * Papan selalu digambar, seperti di beranda - wadah yang tergambar
             * itulah yang membedakan "nilainya belum keluar" dari "lamannya
             * kosong".
             */}
            <section className="daftar overflow-hidden">
                <header className="daftar-kepala">
                    <h2>Ujian yang sudah dikerjakan</h2>
                    {daftar.length > 0 && (
                        <span className="angka ml-auto text-[12px] text-ink-2">
                            {daftar.length}
                        </span>
                    )}
                </header>

                {daftar.length === 0 ? (
                    <div className="px-3 py-6">
                        <p className="text-[13px] leading-relaxed text-ink-2">
                            Belum ada ujian yang Anda kerjakan.
                        </p>
                    </div>
                ) : (
                    daftar.map((b) => <BarisNilai key={b.peserta_id} baris={b} />)
                )}
            </section>

            {/* Kalimat KKM hanya muncul saat ada nilai yang SUDAH FINAL.
                Menyebut ambang kelulusan di atas nilai sementara berarti
                menyatakan seorang anak remedial berdasarkan angka yang masih
                berubah. */}
            {ada_nilai && (
                <p className="keterangan">
                    Nilai di bawah KKM <b>{kkm}</b> dinyatakan remedial, dan jadwalnya
                    ditetapkan Guru mata pelajaran.
                </p>
            )}

            {remedial.length > 0 && (
                <section className="daftar overflow-hidden">
                    <header className="daftar-kepala">
                        <h2>Perlu remedial</h2>
                    </header>

                    {remedial.map((r, i) => (
                        <div key={i} className="daftar-baris flex items-center gap-2 px-3 py-2">
                            <span className="min-w-0 flex-1 truncate text-[13px] text-ink">
                                {r.mapel ?? 'Tanpa mapel'}
                            </span>
                            <span className="angka shrink-0 text-[12px] text-warn">
                                {formatPoin(r.nilai)}
                            </span>
                        </div>
                    ))}
                </section>
            )}

            {/*
             * Tautan kembali sebagai TOMBOL, bukan huruf kecil bergaris.
             *
             * Sebelumnya ia 12px `ink-2` bergaris bawah, dan di laman yang
             * isinya bisa kosong ia menjadi satu-satunya jalan pulang yang
             * terlihat seperti keterangan kaki halaman. Sasaran ketuknya
             * sekarang 40px dengan bidang 44px, sama seperti tombol lain.
             */}
            <Link href="/siswa" className="tombol w-full">
                Kembali ke jadwal ujian
            </Link>
        </KerangkaSiswa>
    );
}

/**
 * Satu baris nilai.
 *
 * Kolom kanannya SATU hal saja: angka bila ada, kalimat keadaan bila belum -
 * tidak pernah keduanya, karena angka dan "belum dipublikasikan" yang
 * berdampingan terbaca sebagai nilai nol.
 */
function BarisNilai({ baris }: { baris: Baris }) {
    return (
        <div className="daftar-baris px-3 py-2.5">
            <div className="flex items-baseline gap-2">
                <span className="min-w-0 flex-1 truncate text-[13px] font-medium text-ink">
                    {baris.mapel ?? 'Tanpa mapel'}
                </span>

                {baris.nilai !== null ? (
                    <b className="angka-besar shrink-0 text-[17px] leading-none text-ink">
                        {formatPoin(baris.nilai)}
                    </b>
                ) : (
                    <span className="shrink-0 border border-line-2 bg-surface-2 px-1.5 py-0.5 text-[11px] whitespace-nowrap text-ink-2">
                        {baris.dipublikasikan ? 'belum dikoreksi' : 'belum dibuka'}
                    </span>
                )}
            </div>

            {/* SATU titik, dan itu memang yang diizinkan lantai 63 - "one dot
                in a byline". Pita di beranda memakai kolom karena ia empat
                hal yang dibandingkan sekilas; baris ini dua keterangan satu
                ujian, dan memisahkannya jadi sel tidak menambah apa pun. */}
            <p className="catatan mt-0.5 truncate">
                {[baris.tanggal_label, baris.status_label].filter(Boolean).join(' · ')}
            </p>

            {/* Rincian per bagian. Keduanya hanya muncul saat angka akhirnya
                ada - rincian tanpa angka akhir adalah potongan nilai yang
                membingungkan. */}
            {baris.nilai !== null && (
                <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-ink-2">
                    {baris.objektif !== null && (
                        <span>
                            objektif{' '}
                            <span className="angka text-ink">{formatPoin(baris.objektif)}</span>
                        </span>
                    )}
                    {baris.uraian !== null && (
                        <span>
                            uraian{' '}
                            <span className="angka text-ink">{formatPoin(baris.uraian)}</span>
                        </span>
                    )}
                    {!baris.final && <span className="text-warn">sementara</span>}
                    {baris.lulus !== null && (
                        <span
                            className={
                                'border border-line-2 px-1.5 py-0.5 ' +
                                (baris.lulus ? 'bg-ok-bg text-ok' : 'bg-warn-bg text-warn')
                            }
                        >
                            {baris.lulus ? 'tuntas' : 'remedial'}
                        </span>
                    )}
                </div>
            )}

            {/* Uraian yang belum dikoreksi dijelaskan sebagai pekerjaan yang
                sedang berjalan, bukan sebagai kabar buruk. */}
            {baris.nilai !== null && !baris.final && baris.sisa_uraian > 0 && (
                <p className="catatan mt-1">
                    {baris.sisa_uraian} jawaban uraian masih dikoreksi Guru. Angka di atas
                    bisa berubah.
                </p>
            )}

            {/*
             * Jalan ke riwayat jawaban, dan HANYA bila hasilnya sudah dibuka.
             *
             * Laman riwayat sendiri menjawab 404 sebelum publikasi - tautan ini
             * tidak "menjaganya", ia hanya tidak menawarkan pintu yang pasti
             * tertutup. Tanpa tautan ini, `show_explanations` dan
             * `show_objectives` tidak punya satu pun pembaca di peramban.
             */}
            {baris.dipublikasikan && (
                <Link
                    href={`/siswa/ujian/${baris.peserta_id}/riwayat`}
                    className="mt-1.5 inline-block text-[12px] font-medium text-accent"
                >
                    Lihat jawaban saya
                </Link>
            )}
        </div>
    );
}
