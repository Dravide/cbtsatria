import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { Field, kelasInput } from '@/Components/ui';

/**
 * Gerbang ujian - wireframe-peran.html plate 20 dan 21, `/siswa/ujian/{peserta}/gerbang`.
 *
 * =====================================================================
 * Satu komponen, dua gambar, dan yang memilih adalah `layak.boleh`
 * =====================================================================
 * Server mengirim `layak`, dan laman ini tidak menghitung ulang apa pun.
 * Itu bukan kerapian: `KelayakanUjian::periksa()` dipanggil gerbang DAN
 * `MesinUjian::mulai()` - PRD §10.8 menutup dengan "Validasi dilakukan
 * kembali pada endpoint start exam." Laman yang menyimpulkan sendiri
 * "pastinya boleh" akan menampilkan tombol aktif tepat sebelum server
 * menolaknya, dan siswa yang menekannya berpikir ujiannya rusak.
 *
 * ---------------------------------------------------------------------
 * Menyegar sendiri 15 detik
 * ---------------------------------------------------------------------
 * Plate 20 menuliskannya sendiri: "Halaman ini menyegar sendiri setiap
 * 15 detik". Yang menyegarkan adalah `router.reload`, BUKAN
 * `window.location.reload` - memuat ulang dokumen penuh akan meminta
 * bundel JS lagi setiap 15 detik pada ponsel yang sinyalnya sedang buruk.
 *
 * Penyegaran itu HANYA hidup saat siswa benar-benar menunggu presensi.
 * Alasannya kesopanan pada server: gerbang yang sudah boleh dimulai tidak
 * punya apa pun yang bisa berubah, dan terus menanyakan hal yang sama
 * setiap 15 detik sepanjang waktu yang dihabiskan anak membaca aturan.
 *
 * ---------------------------------------------------------------------
 * Tombol mati, bukan tombol hilang
 * ---------------------------------------------------------------------
 * Plate 20: "Tombol 'mulai' tetap terlihat tetapi mati - bukan
 * disembunyikan - supaya siswa tahu bahwa yang kurang adalah verifikasi,
 * bukan haknya untuk ikut ujian."
 */

type Peserta = {
    peserta_id: number;
    ujian: string | null;
    mapel: string | null;
    durasi: number | null;
    sesi: string | null;
    mulai: string | null;
    berakhir: string | null;
    ruang: string | null;
    ruang_nama: string | null;
    kursi: number | null;
};

type Props = {
    peserta: Peserta;
    layak: { boleh: boolean; alasan: string | null; petunjuk: string | null };
    ber_token: boolean;
    melanjutkan: boolean;
    sisa_detik: number | null;
    aturan: string[];
};

/** Selang penyegaran sendiri - angka yang sama dengan kalimat di plate 20. */
const SELANG_MS = 15_000;

export default function Gerbang({ peserta, layak, ber_token, melanjutkan, sisa_detik, aturan }: Props) {
    const form = useForm({ token: '' });

    // Satu penanda, bukan state: penyegaran tidak boleh memicu render ulang
    // laman ini. Kalau ia memicu, isi kotak token yang sedang diketik anak
    // akan kembali kosong setiap 15 detik - dan token ujian dibacakan dari
    // papan tulis, jadi mengisinya ulang bukan hal sepele.
    const menyegar = useRef(false);

    // Menunggu presensi = belum boleh, DAN bukan karena sesinya belum dibuka
    // atau karena kuota attempt habis. Dua yang terakhir itu tidak berubah
    // dengan sendirinya dalam hitungan detik, dan menyegarkan untuk keduanya
    // berarti menanyakan berkali-kali jawaban yang sudah pasti.
    const menungguPresensi = !layak.boleh && layak.alasan === PESAN_BELUM_DIPRESENSI;

    useEffect(() => {
        if (!menungguPresensi) {
            return;
        }

        const jam = window.setInterval(() => {
            if (menyegar.current) {
                return;
            }

            menyegar.current = true;

            router.reload({
                only: ['layak', 'melanjutkan', 'sisa_detik'],
                onFinish: () => {
                    menyegar.current = false;
                },
            });
        }, SELANG_MS);

        return () => window.clearInterval(jam);
    }, [menungguPresensi]);

    const kirim = (e: React.FormEvent) => {
        e.preventDefault();

        // Endpoint `mulai` sendiri yang memeriksa ulang presensi, jendela
        // sesi, dan token - dan ia mengembalikan galatnya sebagai galat
        // validasi di laman ini, bukan sebagai halaman 500. Karena itu tidak
        // ada penjagaan klien di sini: penjagaan kedua yang berbeda dari
        // server justru menciptakan tombol yang tidak bereaksi.
        form.post(`/siswa/ujian/${peserta.peserta_id}/gerbang`);
    };

    return (
        <KerangkaSiswa
            judul="Gerbang ujian"
            tanpaKeluar
            kiri={
                <span className="truncate text-[12px] font-medium text-ink">
                    {[peserta.mapel, peserta.sesi].filter(Boolean).join(' · ') || peserta.ujian}
                </span>
            }
            kanan={
                layak.boleh ? (
                    <span className="border border-line-2 bg-ok-bg px-1.5 py-0.5 text-[11px] font-medium text-ok">
                        presensi ok
                    </span>
                ) : (
                    <span className="border border-line-2 bg-warn-bg px-1.5 py-0.5 text-[11px] font-medium text-warn">
                        menunggu
                    </span>
                )
            }
        >
            {layak.boleh ? (
                <SiapMulai
                    form={form}
                    kirim={kirim}
                    berToken={ber_token}
                    melanjutkan={melanjutkan}
                    sisaDetik={sisa_detik}
                    aturan={aturan}
                    ruang={peserta.ruang}
                />
            ) : (
                <BelumBoleh
                    alasan={layak.alasan}
                    petunjuk={layak.petunjuk}
                    ruang={peserta.ruang}
                    menungguPresensi={menungguPresensi}
                />
            )}
        </KerangkaSiswa>
    );
}

/**
 * Kalimat penolakan presensi, disalin dari `Kelayakan::PESAN_BELUM_DIPRESENSI`.
 *
 * Disalin, dan itu memang satu-satunya cara laman ini bisa tahu bahwa
 * penolakan yang diterimanya adalah "belum dipresensi" dan bukan salah satu
 * dari sepuluh penolakan lain - perbandingan string adalah pilihan yang
 * jelek, tetapi alternatifnya (menambahkan kode penolakan ke `Kelayakan`)
 * mengubah kelas bersama yang sudah dipakai sejak Fase 6 hanya demi satu
 * perilaku tampilan.
 *
 * Yang menjaga salinan ini tetap benar: kalau kalimatnya berubah di server,
 * yang hilang hanya penyegaran otomatis - bukan kebenaran penolakannya.
 * Siswa tetap melihat kalimat yang benar, dan tinggal menyegar sendiri.
 */
const PESAN_BELUM_DIPRESENSI =
    'Anda belum mendapatkan verifikasi kehadiran dari Pengawas ruang ujian. ' +
    'Silakan menghubungi Pengawas yang bertugas untuk melakukan verifikasi sebelum memulai ujian.';

/**
 * Plate 20 - penolakan.
 *
 * =====================================================================
 * Redesign: kalimatnya sama, nadanya turun satu tingkat
 * =====================================================================
 * Sampai redesign ini seluruh kotak ini - kepala, alasan, tombol mati,
 * petunjuk, dan nama ruang - dicetak dalam warna peringatan, dan alasan
 * penolakannya bahkan 10px. Anak yang baru pertama kali membuka gerbang
 * menerima satu kotak yang seluruhnya berbunyi "ada yang salah" tanpa satu
 * pun bagian yang tenang untuk dibaca.
 *
 * Yang berubah hanya nadanya: kepala dan alasannya tetap warna peringatan,
 * selebihnya memakai tinta biasa pada ukuran badan. Isi kalimatnya tidak
 * disentuh satu huruf.
 */
function BelumBoleh({
    alasan,
    petunjuk,
    ruang,
    menungguPresensi,
}: {
    alasan: string | null;
    petunjuk: string | null;
    ruang: string | null;
    menungguPresensi: boolean;
}) {
    return (
        <div className="grid place-content-center justify-items-center gap-3 py-8">
            <section className="papan w-[min(88vw,20rem)] overflow-hidden">
                <header className="papan-kepala">
                    <span className="text-warn">Akses Ujian Belum Tersedia</span>
                </header>

                <div className="space-y-2.5 p-3 text-center">
                    <p className="text-[12px] leading-relaxed text-warn">{alasan}</p>

                    {/* Tombol mati, bukan tombol hilang - plate 20: siswa
                        harus tahu bahwa yang kurang adalah verifikasi, bukan
                        haknya untuk ikut ujian. */}
                    <span className="tombol w-full" aria-disabled="true">
                        Mulai ujian
                    </span>

                    {petunjuk && <p className="text-[12px] leading-relaxed text-ink-2">{petunjuk}</p>}

                    {menungguPresensi && (
                        <p className="catatan">Halaman ini menyegar sendiri setiap 15 detik</p>
                    )}

                    {ruang && (
                        <p className="catatan">
                            Ruang Anda: <b className="angka text-ink">{ruang}</b>
                        </p>
                    )}
                </div>
            </section>
        </div>
    );
}

/** Plate 21 - pintu masuk. */
function SiapMulai({
    form,
    kirim,
    berToken,
    melanjutkan,
    sisaDetik,
    aturan,
    ruang,
}: {
    form: ReturnType<typeof useForm<{ token: string }>>;
    kirim: (e: React.FormEvent) => void;
    berToken: boolean;
    melanjutkan: boolean;
    sisaDetik: number | null;
    aturan: string[];
    ruang: string | null;
}) {
    return (
        <form onSubmit={kirim} className="grid justify-items-center gap-3 py-4">
            <p className="max-w-[22rem] text-[12px] leading-relaxed text-ink-2">
                {berToken ? (
                    <>
                        Presensi Anda sudah diverifikasi Pengawas. Token ujian ditulis di papan
                        tulis ruang {ruang ?? 'ujian'}.
                    </>
                ) : (
                    <>Presensi Anda sudah diverifikasi Pengawas. Ujian ini tidak memakai token.</>
                )}
            </p>

            {berToken && (
                <div className="w-[9.5rem]">
                    {/* Token dibaca dari papan tulis lalu diketik di ponsel, jadi
                        ia diminta dalam huruf besar dengan keyboard yang tidak
                        mengoreksi ejaan. `autoCorrect`, `autoCapitalize`, dan
                        `spellCheck` dimatikan karena koreksi otomatis ponsel
                        mengubah kode acak menjadi kata yang mirip - dan siswa
                        melihat tokennya "salah" padahal yang salah adalah
                        ponselnya.

                        Huruf besarnya datang dari NILAINYA (`toUpperCase` di
                        bawah), bukan dari `text-transform` di sini. Dulu kelas
                        `uppercase` berdiri di baris ini bersama jarak huruf
                        0,35em, dan keduanya dicatat lantai 67: yang menuliskan
                        huruf besar dua kali adalah dua tempat yang bisa
                        berselisih, dan jarak huruf sememat itu tidak menambah
                        keterbacaan satu huruf pun. */}
                    <Field label="" galat={form.errors.token}>
                        <input
                            value={form.data.token}
                            onChange={(e) => form.setData('token', e.target.value.toUpperCase())}
                            autoComplete="off"
                            autoCorrect="off"
                            autoCapitalize="characters"
                            spellCheck={false}
                            inputMode="text"
                            maxLength={12}
                            placeholder="— — — — — —"
                            className={kelasInput + ' text-center font-mono text-base'}
                        />
                    </Field>
                </div>
            )}

            <button type="submit" disabled={form.processing} className="tombol tombol--pri w-full max-w-[20rem]">
                {form.processing
                    ? 'Menyiapkan…'
                    : melanjutkan
                      ? 'Lanjutkan ujian'
                      : 'Mulai ujian'}
            </button>

            {/* Siswa yang kembali setelah sinyalnya putus harus tahu waktunya
                sudah berjalan sejak tadi - kalimat ini yang mencegahnya
                mengira jam barunya dimulai ulang. */}
            {melanjutkan && sisaDetik !== null && (
                <p className="text-[12px] font-medium text-warn">
                    Ujian Anda sedang berjalan. Sisa waktu{' '}
                    <b className="angka">{formatSisa(sisaDetik)}</b>.
                </p>
            )}

            {/* Aturan main ditulis LENGKAP sebelum tombol mulai - plate 21.
                Isinya dari server, bukan teks tetap di sini: durasi dan
                kebolehan mundur datang dari ujian yang sesungguhnya. */}
            <section className="papan overflow-hidden">
                <header className="papan-kepala">
                    <span>Aturan ujian</span>
                </header>

                <ul className="space-y-1.5 p-3 text-left">
                    {aturan.map((a) => (
                        <li key={a} className="flex gap-2 text-[12px] leading-relaxed text-ink-2">
                            {/* Angka urut, bukan tanda centang di dalam
                                lingkaran: aturan ini BELUM dikerjakan, dan
                                centang di sebelahnya adalah tanda selesai.
                                Yang urut juga menjawab "berapa aturannya". */}
                            <span className="angka w-4 shrink-0 text-ink-3">
                                {aturan.indexOf(a) + 1}
                            </span>
                            <span>{a}</span>
                        </li>
                    ))}
                </ul>
            </section>
        </form>
    );
}

/** `mm:ss` atau `h:mm:ss` - jam dinding, bukan jumlah detik. */
function formatSisa(detik: number): string {
    const j = Math.floor(detik / 3600);
    const m = Math.floor((detik % 3600) / 60);
    const d = detik % 60;

    const dua = (n: number) => String(n).padStart(2, '0');

    return j > 0 ? `${j}:${dua(m)}:${dua(d)}` : `${dua(m)}:${dua(d)}`;
}
