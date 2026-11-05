import { Link } from '@inertiajs/react';
import KerangkaPonsel from '@/Layouts/KerangkaPonsel';
import { Chip, Inbox } from '@/Components/ui';
import { KotakPonsel } from '@/Components/ponsel';

/**
 * Plate 11 - ruang tugas hari ini. `/pengawas`.
 *
 * =====================================================================
 * Tidak ada tabel, tidak ada filter, tidak ada sidebar
 * =====================================================================
 * Satu laman, tugas hari ini, tiga tombol besar. Pengawas tidak pernah
 * memilih ruang dari daftar panjang - ruangnya sudah ditetapkan Admin, dan
 * hanya yang ditetapkan itu yang muncul.
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

export default function RuangTugas({ ruang, pelaku }: { ruang: Baris[]; pelaku: string }) {
    // Lubang paling mahal di laman ini: ruang yang sesinya sudah berjalan
    // tapi belum punya satu pengawas pun. Ia bukan keadaan yang bisa
    // ditangani pengawas dari laman ini, jadi yang benar adalah
    // MENYEBUTNYA - daftar yang tampak lengkap padahal satu ruangnya tidak
    // akan pernah bisa dibuka adalah daftar yang menipu.
    const tanpaPengawas = ruang.filter((r) => r.boleh_buka && r.peserta > 0);

    return (
        <KerangkaPonsel
            judul="Ruang tugas hari ini"
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

            {ruang.map((r) => (
                <KotakPonsel key={r.ruang_id} solid padat>
                    <div className="flex items-center gap-2">
                        <b className="font-display text-sm text-ink">
                            {r.ruang} &middot; {r.ruang_nama ?? 'Ruang'}
                        </b>
                        <span className="ml-auto">
                            <Chip nada={r.boleh_buka ? 'info' : 'mute'}>
                                {r.sesi ? `sesi ${r.sesi}` : 'tanpa sesi'}
                            </Chip>
                        </span>
                    </div>

                    <p className="text-[11px] text-ink-2">
                        {[r.ujian, `${r.peserta} peserta`, r.jam]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>

                    {r.lokasi && <p className="text-[11px] text-ink-3">{r.lokasi}</p>}

                    {r.insiden_terbuka > 0 && (
                        <p className="text-[11px] text-warn">
                            {r.insiden_terbuka} insiden belum ditangani di ruang ini.
                        </p>
                    )}

                    <div className="flex flex-wrap gap-1.5 pt-1">
                        {r.boleh_buka ? (
                            <>
                                <Link
                                    href={`/pengawas/ruang/${r.ruang_id}/presensi`}
                                    className="rounded-wf border border-accent bg-accent px-3 py-2 text-xs font-medium text-on-accent"
                                >
                                    Presensi
                                </Link>
                                <Link
                                    href={`/pengawas/ruang/${r.ruang_id}/token`}
                                    className="rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink hover:bg-surface-2"
                                >
                                    Lihat token
                                </Link>
                                <Link
                                    href={`/pengawas/ruang/${r.ruang_id}/monitoring`}
                                    className="rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink hover:bg-surface-2"
                                >
                                    Monitoring
                                </Link>
                            </>
                        ) : (
                            <>
                                {['Presensi', 'Lihat token', 'Monitoring'].map((t) => (
                                    <span
                                        key={t}
                                        className="rounded-wf border border-line bg-surface-2 px-3 py-2 text-xs text-ink-3/60"
                                    >
                                        {t}
                                    </span>
                                ))}
                            </>
                        )}
                    </div>

                    {!r.boleh_buka && r.alasan_tolak && (
                        <p className="text-[11px] text-ink-3">{r.alasan_tolak}</p>
                    )}
                </KotakPonsel>
            ))}

            {pelaku === 'tautan' && (
                <Inbox>
                    Sesi dari tautan ruang: hanya ruang ini yang tampil. Penempatan lain
                    hari ini tetap tersembunyi sampai Anda masuk dengan akun sendiri.
                </Inbox>
            )}

            {pelaku !== 'tautan' && tanpaPengawas.length > 0 && (
                <Inbox>
                    {tanpaPengawas.length} ruang sudah bisa dibuka. Token dan berita acara
                    ada di dalam masing-masing ruang.
                </Inbox>
            )}
        </KerangkaPonsel>
    );
}
