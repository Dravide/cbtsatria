import { Link } from '@inertiajs/react';
import KerangkaPonsel from '@/Layouts/KerangkaPonsel';
import { Chip, Inbox } from '@/Components/ui';

/**
 * Plate 11 - ruang tugas hari ini. `/pengawas`.
 *
 * =====================================================================
 * Redesign 2026-11-13 - pelat gelap, ruang berjalan memimpin
 * =====================================================================
 * Tiga temuan dari pembacaan before yang direparasi di sini:
 *
 *   1. Ruang tunggal mengapung di halaman kosong tanpa penekanan apa pun.
 *      Kini kartu ruang yang sesinya BERJALAN memimpin daftar dan membawa
 *      angka pesertanya DISET BESAR - sebelumnya "38 peserta" tersembunyi
 *      di dalam kalimat kecil.
 *   2. Dua Inbox di bawah daftar berbunyi hampir sama. Kini pengumuman
 *      digabung jadi satu blok dengan satu suara.
 *   3. Tidak ada sinyal "sesi sedang berjalan" pada kartu yang aktif. Kini
 *      kartu itu diberi bilah hidup (baris aksen di tepi kartu) - dan hanya
 *      satu di halaman, karena dua sesi ruang berbeda bisa berjalan serentak
 *      tapi milik pengawas ini yang ditandai, bukan milik sistem.
 *
 * Daftar itu datang dari `KonteksRuang::ruangTersedia()`, dan BENTUK
 * datanya yang membedakan dua pintu masuk: pemegang tautan melihat tepat
 * satu kartu karena tautannya menunjuk satu ruang, sementara pengawas
 * berakun melihat setiap ruang yang ditugaskan kepadanya.
 *
 * ---------------------------------------------------------------------
 * Tombol yang belum bisa ditekan diberi ALASAN, bukan disembunyikan
 * ---------------------------------------------------------------------
 * Plate 11 menggambarnya begitu: kartu R-02 punya tiga tombol nonaktif dan
 * satu keterangan "Sesi R-02 belum dibuka." Pengawas yang menemukan tombol
 * mati tanpa penjelasan akan menekannya berulang kali lalu menelepon Admin -
 * dan Admin sedang mengurus hal lain di hari-H.
 *
 * Karena itu `alasan_tolak` dirender sebagai kalimat, bukan sebagai tooltip:
 * tooltip tidak ada di ponsel.
 */
type Baris = {
    ruang_id: number;
    ruang: string;
    ruang_nama: string | null;
    lokasi: string | null;
    sesi_id: number | null;
    sesi: string | null;
    ujian: string | null;
    jam: string | null;
    status_sesi: string | null;
    status_label: string | null;
    peserta: number;
    kapasitas: number;
    insiden_terbuka: number;
    boleh_buka: boolean;
    alasan_tolak: string | null;
};

export default function RuangTugas({
    ruang,
    pelaku,
    pengawas,
}: {
    ruang: Baris[];
    pelaku: string;
    pengawas: string | null;
}) {
    // Ruang yang sesinya sudah BERJALAN memimpin; sisanya mengikuti urutan
    // Admin. Daftar yang terurut status, bukan abjad, menjawab pertanyaan
    // pertama pengawas yang baru membuka ponselnya: "yang mana yang harus
    // kupunggu sekarang."
    const berjalan = ruang.filter((r) => r.status_sesi === 'berjalan');
    const lainnya = ruang.filter((r) => r.status_sesi !== 'berjalan');

    // Lubang paling mahal di laman ini: ruang yang sesinya sudah berjalan
    // tapi belum punya satu pengawas pun. Ia bukan keadaan yang bisa
    // ditangani pengawas dari laman ini, jadi yang benar adalah
    // MENYEBUTNYA - daftar yang tampak lengkap padahal satu ruangnya tidak
    // akan pernah bisa dibuka adalah daftar yang menipu.
    const siapDibuka = lainnya.filter((r) => r.boleh_buka && r.peserta > 0);

    const kartu = (r: Baris) => {
        const hidup = r.status_sesi === 'berjalan';

        return (
            <section
                key={r.ruang_id}
                className={
                    'relative overflow-hidden rounded-wf border ' +
                    (hidup ? 'border-accent-soft bg-surface-2' : 'border-line bg-surface')
                }
            >
                {/* Bilah hidup: hanya pada kartu sesi berjalan. Ia bukan hiasan -
                    satu-satunya sinyal status yang terbaca dari jarak satu lengan
                    tanpa membaca satu kata pun. */}
                {hidup && <span className="absolute inset-y-0 left-0 w-1 bg-accent" />}

                <div className={hidup ? 'space-y-2 p-3 pl-4' : 'space-y-2 p-3'}>
                    <div className="flex items-baseline gap-2">
                        <b className="font-display text-base text-ink">{r.ruang}</b>
                        {r.ruang_nama && (
                            <span className="min-w-0 truncate text-xs text-ink-2">
                                {r.ruang_nama}
                            </span>
                        )}
                        <span className="ml-auto shrink-0">
                            {r.sesi ? (
                                <Chip nada={hidup ? 'ok' : 'info'}>{r.sesi}</Chip>
                            ) : (
                                <Chip nada="mute">tanpa sesi</Chip>
                            )}
                        </span>
                    </div>

                    {r.ujian && <p className="text-sm text-ink">{r.ujian}</p>}
                    {r.jam && <p className="text-xs text-ink-2">{r.jam}</p>}
                    {r.lokasi && <p className="text-xs text-ink-3">{r.lokasi}</p>}

                    {/* Angka peserta diset besar pada kartu hidup: inilah satu
                        angka yang pengawas disebut paling sering - saat berita
                        acara, saat menghitung kehadiran. Tabular nums menjaga
                        angkanya tidak bergeser saat berubah. */}
                    {hidup && (
                        <p
                            className="font-display text-3xl leading-none text-ink"
                            style={{ fontVariantNumeric: 'tabular-nums' }}
                        >
                            {r.peserta}
                            <span className="text-base text-ink-3"> / {r.kapasitas} kursi</span>
                        </p>
                    )}

                    {!hidup && r.peserta > 0 && (
                        <p className="text-xs text-ink-2">
                            {r.peserta} peserta, {r.kapasitas} kursi.
                        </p>
                    )}

                    {r.insiden_terbuka > 0 && (
                        <p className="text-xs text-warn">
                            {r.insiden_terbuka} insiden belum ditangani di ruang ini.
                        </p>
                    )}

                    <div className="flex flex-wrap gap-1.5 pt-1">
                        {r.boleh_buka ? (
                            <>
                                <Link
                                    href={`/pengawas/ruang/${r.ruang_id}/presensi`}
                                    className="inline-grid min-h-11 place-items-center rounded-wf border border-accent bg-accent px-3 text-xs font-medium text-on-accent"
                                >
                                    Presensi
                                </Link>
                                <Link
                                    href={`/pengawas/ruang/${r.ruang_id}/token`}
                                    className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface px-3 text-xs text-ink hover:bg-surface-2"
                                >
                                    Lihat token
                                </Link>
                                <Link
                                    href={`/pengawas/ruang/${r.ruang_id}/monitoring`}
                                    className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface px-3 text-xs text-ink hover:bg-surface-2"
                                >
                                    Monitoring
                                </Link>
                            </>
                        ) : (
                            <>
                                {['Presensi', 'Lihat token', 'Monitoring'].map((t) => (
                                    <span
                                        key={t}
                                        // aria-disabled, bukan sekadar warna: tanpa
                                        // itu pembaca layar mengumumkan tiga label
                                        // yang tampak bisa ditekan tanpa keadaan
                                        // apa pun. Warnanya ink-3 penuh - ink-3/60
                                        // di atas surface-2 jatuh di bawah 3:1.
                                        aria-disabled="true"
                                        className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface-2 px-3 text-xs text-ink-3"
                                    >
                                        {t}
                                    </span>
                                ))}
                            </>
                        )}
                    </div>

                    {!r.boleh_buka && r.alasan_tolak && (
                        <p className="text-xs text-ink-3">{r.alasan_tolak}</p>
                    )}
                </div>
            </section>
        );
    };

    return (
        <KerangkaPonsel
            judul="Ruang tugas hari ini"
            gelap
            kanan={
                <Chip nada={pelaku === 'tautan' ? 'info' : 'mute'}>
                    {pelaku === 'tautan' ? 'tautan ruang' : 'akun'}
                </Chip>
            }
        >
            {ruang.length === 0 && (
                <Inbox>
                    Tidak ada ruang yang ditugaskan kepada Anda hari ini. Penugasan
                    pengawas ditetapkan Admin di laman ruang masing-masing.
                </Inbox>
            )}

            {/* Nama pengawas yang membuka pintunya, sejak 2026-11-07 - kodenya
                menempel pada orang, sehingga "data pengawasnya langsung ada"
                bukan lagi sesuatu yang harus dikarang dari nama ruang. Di atas
                daftar, bukan di dalam kartu: yang dijawabnya adalah "siapa saya
                di sini", dan pertanyaan itu tidak bergantung pada ruang mana
                yang sedang dilihat. */}
            {pengawas && (
                <Inbox>
                    Anda masuk sebagai <strong>{pengawas}</strong>.
                </Inbox>
            )}

            {berjalan.map(kartu)}

            {/* Pemisah antar kelompok hanya dibutuhkan bila keduanya ada:
                satu jenis kartu tidak perlu pengumuman tentang dirinya. */}
            {berjalan.length > 0 && lainnya.length > 0 && (
                <p className="pt-2 text-xs text-ink-3">Ruang lain hari ini</p>
            )}

            {lainnya.map(kartu)}

            {pelaku === 'tautan' && (
                <Inbox>
                    Sesi dari tautan ruang: hanya ruang ini yang tampil. Penempatan lain
                    hari ini tetap tersembunyi sampai Anda masuk dengan akun sendiri.
                </Inbox>
            )}

            {pelaku !== 'tautan' && siapDibuka.length > 0 && (
                <Inbox>
                    {siapDibuka.length} ruang sudah bisa dibuka. Token dan berita acara
                    ada di dalam masing-masing ruang.
                </Inbox>
            )}
        </KerangkaPonsel>
    );
}
