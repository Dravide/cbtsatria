import { Link } from '@inertiajs/react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { Chip, Inbox } from '@/Components/ui';
import { KotakPonsel } from '@/Components/ponsel';
import { formatPoin } from '@/Components/angka';

/**
 * Nilai saya - wireframe-peran.html plate 28.
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
            kanan={kelas ? <Chip nada="mute">{kelas}</Chip> : undefined}
        >
            {!ada_nilai && (
                <Inbox>
                    {menunggu_terbit
                        ? 'Nilai belum tersedia. Hasil ujian baru dipublikasikan setelah seluruh uraian selesai dikoreksi Guru.'
                        : 'Belum ada nilai. Hasil ujian muncul di halaman ini setelah Admin mempublikasikannya untuk seluruh angkatan.'}
                </Inbox>
            )}

            {daftar.length === 0 ? (
                <KotakPonsel padat>
                    <p className="py-6 text-center text-[12px] text-ink-3">
                        Belum ada ujian yang Anda kerjakan.
                    </p>
                </KotakPonsel>
            ) : (
                <KotakPonsel padat judul="Ujian yang sudah dikerjakan">
                    <div className="space-y-0">
                        {daftar.map((b) => (
                            <div
                                key={b.peserta_id}
                                className="border-b border-line/60 py-2.5 last:border-0"
                            >
                                <div className="flex items-start gap-2">
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[12px] text-ink">
                                            {b.mapel ?? 'Tanpa mapel'}
                                        </span>
                                        <span className="block truncate text-[10px] text-ink-3">
                                            {[b.tanggal_label, b.status_label]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </span>

                                    {/* Kolom kanan adalah NILAI bila ada, dan
                                        kalimat-keadaan bila belum. Tidak
                                        pernah keduanya - angka dan "belum
                                        dipublikasikan" berdampingan terbaca
                                        sebagai nilai nol. */}
                                    {b.nilai !== null ? (
                                        <span className="shrink-0 text-right">
                                            <span className="block font-mono text-lg leading-none font-semibold text-ink">
                                                {formatPoin(b.nilai)}
                                            </span>
                                            {!b.final && (
                                                <span className="mt-0.5 block text-[10px] text-warn">
                                                    sementara
                                                </span>
                                            )}
                                        </span>
                                    ) : (
                                        <Chip nada="mute">
                                            {b.dipublikasikan ? 'belum dikoreksi' : 'belum dibuka'}
                                        </Chip>
                                    )}
                                </div>

                                {/* Rincian per bagian. Keduanya hanya muncul
                                    saat angka akhirnya ada - rincian tanpa
                                    angka akhir adalah potongan nilai yang
                                    membingungkan. */}
                                {b.nilai !== null && (
                                    <div className="mt-1.5 flex flex-wrap items-center gap-3 text-[10px] text-ink-3">
                                        {b.objektif !== null && (
                                            <span>
                                                objektif{' '}
                                                <span className="font-mono text-ink-2">
                                                    {formatPoin(b.objektif)}
                                                </span>
                                            </span>
                                        )}
                                        {b.uraian !== null && (
                                            <span>
                                                uraian{' '}
                                                <span className="font-mono text-ink-2">
                                                    {formatPoin(b.uraian)}
                                                </span>
                                            </span>
                                        )}
                                        {b.lulus !== null && (
                                            <Chip nada={b.lulus ? 'ok' : 'warn'}>
                                                {b.lulus ? 'tuntas' : 'remedial'}
                                            </Chip>
                                        )}
                                    </div>
                                )}

                                {/* Uraian yang belum dikoreksi dijelaskan
                                    sebagai pekerjaan yang sedang berjalan,
                                    bukan sebagai kabar buruk. */}
                                {b.nilai !== null && !b.final && b.sisa_uraian > 0 && (
                                    <p className="mt-1 text-[10px] text-ink-3">
                                        {b.sisa_uraian} jawaban uraian masih dikoreksi Guru — angka
                                        di atas bisa berubah.
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                </KotakPonsel>
            )}

            {/* Kalimat KKM hanya muncul saat ada nilai yang SUDAH FINAL.
                Menyebut ambang kelulusan di atas nilai sementara berarti
                menyatakan seorang anak remedial berdasarkan angka yang masih
                berubah. */}
            {ada_nilai && (
                <Inbox>
                    Nilai di bawah KKM <b>{kkm}</b> dinyatakan remedial, dan jadwalnya
                    ditetapkan Guru mata pelajaran.
                </Inbox>
            )}

            {remedial.length > 0 && (
                <KotakPonsel padat judul="Perlu remedial">
                    <div className="space-y-1.5 py-1">
                        {remedial.map((r, i) => (
                            <div key={i} className="flex items-center gap-2">
                                <span className="min-w-0 flex-1 truncate text-[12px] text-ink">
                                    {r.mapel ?? 'Tanpa mapel'}
                                </span>
                                <span className="font-mono text-[12px] text-warn">
                                    {formatPoin(r.nilai)}
                                </span>
                            </div>
                        ))}
                    </div>
                </KotakPonsel>
            )}

            <Link href="/siswa" className="block px-1 text-[11px] text-accent underline">
                Kembali ke jadwal ujian
            </Link>
        </KerangkaSiswa>
    );
}
