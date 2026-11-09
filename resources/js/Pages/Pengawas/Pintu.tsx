import { Head, useForm } from '@inertiajs/react';
import { Chip, Inbox, Peringatan, UmpanBalik } from '@/Components/ui';

/**
 * Plate 10 - pintu ruang. `/p/{slug}`.
 *
 * =====================================================================
 * Laman ini SENGAJA tidak memakai KerangkaPonsel
 * =====================================================================
 * KerangkaPonsel menggambar bilah yang menyebut ruang dari prop bersama
 * `pengawasRuang` - dan prop itu null justru DI SINI, sebelum pintunya
 * dibuka. Memakainya berarti kepala yang menyebut "Pengawas" pada satu-
 * satunya laman yang seluruh gunanya adalah menyebut ruang mana yang
 * sedang dibuka.
 *
 * Karena itu laman ini menggambar kepalanya sendiri dari prop halamannya.
 *
 * ---------------------------------------------------------------------
 * Yang boleh terlihat: tiga hal
 * ---------------------------------------------------------------------
 * Nama ruang, nama sekolah, nama sesi. Tidak daftar peserta, tidak token,
 * tidak nama pengawas. Tautan ini tertempel di daun pintu yang bisa difoto
 * siapa pun yang lewat di koridor, dan daftar nama anak-anak di daun pintu
 * adalah hal yang tidak boleh terjadi.
 *
 * ---------------------------------------------------------------------
 * Kode enam angka, dan mengapa ia boleh sesingkat itu
 * ---------------------------------------------------------------------
 * Kode harus bisa diketik cepat sambil berdiri dengan peserta sudah
 * menunggu di belakang. Ia tidak boleh menjadi satu-satunya penjaga, dan
 * memang bukan: tautan menyempitkannya ke satu ruang, kode menyempitkannya
 * ke satu orang, dan keduanya mati bersama sesinya.
 */
type Props = {
    slug: string;
    ruang: {
        nama: string | null;
        kode: string | null;
        lokasi: string | null;
        sesi: string | null;
        ujian: string | null;
        jam: string | null;
    } | null;
    berlaku: boolean;
    alasan: string | null;
    sekolah: string | null;
};

export default function Pintu({ slug, ruang, berlaku, alasan, sekolah }: Props) {
    const { data, setData, post, processing, errors } = useForm({ kode: '' });

    const kirim = (e: React.FormEvent) => {
        e.preventDefault();
        post(`/p/${slug}`);
    };

    return (
        <>
            <Head title="Pintu ruang" />

            <div className="min-h-screen bg-paper">
                <div className="mx-auto flex min-h-screen w-full max-w-[26rem] flex-col sm:border-x sm:border-line">
                    <header className="flex items-center gap-2 border-b border-line bg-surface px-3 py-2.5">
                        <span className="text-[11px] text-ink-3">Pengawas ruang</span>
                        <span className="ml-auto">
                            <Chip nada="info">tautan ruang</Chip>
                        </span>
                    </header>

                    <main className="flex-1 space-y-3 px-3 py-4">
                        <UmpanBalik />

                        {!berlaku && (
                            <Peringatan>
                                {alasan ?? 'Tautan ini tidak berlaku lagi.'}
                            </Peringatan>
                        )}

                        {berlaku && (
                            <>
                                <div className="rounded-wf border border-line bg-surface px-4 py-5 text-center">
                                    <p className="font-display text-base font-semibold text-ink">
                                        {ruang?.nama ?? 'Ruang belum ditetapkan'}
                                    </p>

                                    {/* Nama sekolah, nama sesi, dan jamnya -
                                        tiga hal yang membuat pengawas yakin
                                        ia berdiri di ruang yang benar. */}
                                    <p className="mt-1 text-[11px] text-ink-3">
                                        {[sekolah, ruang?.sesi, ruang?.ujian]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>

                                    {ruang?.jam && (
                                        <p className="mt-0.5 font-mono text-[11px] text-ink-3">
                                            {ruang.jam}
                                        </p>
                                    )}

                                    <form onSubmit={kirim} className="mt-4 space-y-3">
                                        <label className="block text-left">
                                            <span className="mb-1 block text-[11px] text-ink-3">
                                                Masukkan kode pengawas Anda.
                                            </span>
                                            {/* Enam kotak terpisah, bukan satu
                                                input: kode ini dibaca dari
                                                kartu, dan satu kolom lebar
                                                membuat orang menghitung
                                                angkanya dua kali. */}
                                            <input
                                                value={data.kode}
                                                onChange={(e) =>
                                                    setData(
                                                        'kode',
                                                        e.target.value
                                                            .replace(/\D/g, '')
                                                            .slice(0, 6),
                                                    )
                                                }
                                                inputMode="numeric"
                                                autoComplete="one-time-code"
                                                autoFocus
                                                className="w-full rounded-wf border border-line bg-surface-2 px-2 py-3 text-center font-mono text-2xl tracking-[0.4em] text-ink outline-none focus:border-accent"
                                                placeholder="——————"
                                            />
                                            {errors.kode && (
                                                <span className="mt-1 block text-[11px] text-bad">
                                                    {errors.kode}
                                                </span>
                                            )}
                                        </label>

                                        <button
                                            type="submit"
                                            disabled={processing || data.kode.length !== 6}
                                            className="w-full rounded-wf border border-accent bg-accent py-2.5 text-sm font-medium text-on-accent disabled:cursor-not-allowed disabled:border-ink-3/40 disabled:bg-ink-3/40"
                                        >
                                            Masuk
                                        </button>
                                    </form>
                                </div>

                                <Inbox>
                                    Tautan hanya menunjuk ruang ini dan tidak membuka apa pun
                                    sendirian. Kode pengawas Andalah yang membuka sesinya, dan
                                    hanya bila Anda ditugaskan di ruang ini.
                                </Inbox>
                            </>
                        )}
                    </main>
                </div>
            </div>
        </>
    );
}
