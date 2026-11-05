import { Head, Link } from '@inertiajs/react';
import { useMemo } from 'react';
import GuruLayout from '@/Layouts/GuruLayout';
import { Chip, Inbox, Kotak } from '@/Components/ui';
import { ButirPratinjau, KartuButir } from '@/Components/PratinjauButir';

/**
 * Pratinjau seluruh isi satu paket - wireframe-peran.html plate 09.
 *
 * ---------------------------------------------------------------------
 * Mengapa seluruh butir dikirim sekaligus, tanpa paginasi
 * ---------------------------------------------------------------------
 * Yang diperiksa Guru di laman ini bukan satu soal, melainkan paketnya sebagai
 * satu kesatuan: apakah bobotnya berjumlah seratus, apakah jenisnya berimbang,
 * apakah ada soal yang tanpa sengaja masuk dua kali, apakah ada soal yang
 * gambarnya tidak termuat.
 *
 * Keempat pertanyaan itu hanya bisa dijawab dengan melihat seluruhnya. Laman
 * yang memaksa menekan "berikutnya" memaksa Guru mengingat angka yang ia lihat
 * di halaman sebelumnya - dan angka yang diingat adalah angka yang salah
 * hitung.
 *
 * Karena itu pemilih nomor di kiri BUKAN paginasi: ia melompat ke butir yang
 * sudah dirender di bawahnya. Tidak ada permintaan jaringan, dan tidak ada
 * keadaan yang perlu diingat.
 *
 * ---------------------------------------------------------------------
 * Ringkasan di atas dihitung di sisi peramban, dan itu disengaja
 * ---------------------------------------------------------------------
 * Angkanya sudah ada di props butir - menghitungnya ulang di server berarti
 * mengirim dua kali kebenaran yang sama, dan saat keduanya berbeda tidak ada
 * yang tahu mana yang benar. Yang dihitung di server adalah hal yang tidak
 * bisa dihitung dari sini: `bolehUbah`, `poin_override` per butir, dan izin
 * bacanya sendiri.
 */
type Paket = {
    id: number;
    nama: string;
    mapel: string | null;
    tingkat: string | null;
    keterangan: string | null;
    status: string;
    status_label: string;
    penulis: string | null;
};

type Props = {
    paket: Paket;
    butir: ButirPratinjau[];
    bolehUbah: boolean;
};

export default function PratinjauPaket({ paket, butir, bolehUbah }: Props) {
    const ringkas = useMemo(() => {
        const poin = butir.reduce((t, b) => t + (b.poin ?? 0), 0);
        const jenis = new Map<string, number>();

        for (const b of butir) {
            jenis.set(b.jenis_label, (jenis.get(b.jenis_label) ?? 0) + 1);
        }

        return {
            poin,
            // Urut dari yang paling banyak supaya paket yang isinya satu jenis
            // saja terlihat sebagai satu baris panjang, bukan sebagai daftar
            // panjang yang harus dibaca sampai habis.
            jenis: [...jenis.entries()].sort((a, b) => b[1] - a[1]),
            bermedia: butir.filter((b) => b.media.length > 0).length,
            // Bobot tidak harus seratus - Guru boleh memakai skala lain - maka
            // yang ditandai adalah "belum seratus", bukan "salah".
            seratus: Math.abs(poin - 100) < 0.001,
        };
    }, [butir]);

    return (
        <GuruLayout
            judul={`Pratinjau — ${paket.nama}`}
            breadcrumb={`/guru/paket / ${paket.nama} / pratinjau`}
            aksi={
                <>
                    <Link
                        href={`/guru/paket/${paket.id}`}
                        className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
                    >
                        Kembali ke paket
                    </Link>
                    {bolehUbah && (
                        <Link
                            href={`/guru/paket/${paket.id}`}
                            className="rounded-wf border border-accent bg-accent px-3 py-1.5 text-xs font-medium text-on-accent hover:bg-accent/90"
                        >
                            Susun butir
                        </Link>
                    )}
                </>
            }
        >
            <Head title={`Pratinjau — ${paket.nama}`} />

            <div className="mb-3 space-y-2">
                {!bolehUbah && (
                    <Inbox>
                        Paket ini disusun oleh <strong>{paket.penulis ?? 'Guru lain'}</strong>.
                        Anda boleh membacanya dan memakainya sebagai acuan; yang dapat
                        mengubahnya hanya penulisnya.
                    </Inbox>
                )}

                <div className="flex flex-wrap items-center gap-2 text-xs text-ink-3">
                    <Chip nada={paket.status === 'siap' ? 'ok' : 'mute'}>
                        {paket.status_label}
                    </Chip>
                    {paket.mapel && <span>{paket.mapel}</span>}
                    {paket.tingkat && <span>Kelas {paket.tingkat}</span>}
                    {paket.keterangan && <span className="italic">{paket.keterangan}</span>}
                </div>
            </div>

            <div className="mb-3">
                <Kotak judul="Ringkasan paket">
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <Angka label="Butir" nilai={String(butir.length)} />
                        <Angka
                            label="Poin total"
                            nilai={String(ringkas.poin)}
                            nada={ringkas.seratus ? 'ok' : undefined}
                            catatan={ringkas.seratus ? 'tepat 100' : 'bukan skala 100'}
                        />
                        <Angka
                            label="Jenis dipakai"
                            nilai={String(ringkas.jenis.length)}
                            catatan={ringkas.jenis.length === 1 ? 'satu jenis saja' : 'beragam'}
                        />
                        <Angka
                            label="Berisi media"
                            nilai={String(ringkas.bermedia)}
                            catatan={ringkas.bermedia === 0 ? 'tanpa gambar' : 'ada gambar'}
                        />
                    </div>

                    {/* Sebaran jenis ditulis apa adanya, tanpa diagram. Yang
                        dibaca Guru di sini adalah "apakah paket ini seluruhnya
                        pilihan ganda" - dan pertanyaan itu dijawab satu baris
                        teks lebih cepat daripada satu grafik. */}
                    <div className="mt-3 flex flex-wrap gap-2 border-t border-line pt-3">
                        {ringkas.jenis.map(([label, jumlah]) => (
                            <span
                                key={label}
                                className="rounded-wf border border-line bg-surface-2/50 px-2 py-0.5 text-[11px] text-ink-2"
                            >
                                {label} <span className="font-mono text-ink-3">{jumlah}</span>
                            </span>
                        ))}
                    </div>
                </Kotak>
            </div>

            {butir.length === 0 ? (
                <Kotak judul="Isi paket">
                    <p className="text-sm text-ink-2">
                        Paket ini belum berisi butir. Paket kosong tidak bisa ditandai siap
                        maupun dipasang ke mapel event — buka paketnya, lalu tandai soal di
                        panel bank soal.
                    </p>
                </Kotak>
            ) : (
                <div className="space-y-3">
                    {butir.map((b) => (
                        <KartuButir key={b.id} butir={b} />
                    ))}
                </div>
            )}
        </GuruLayout>
    );
}

function Angka({
    label,
    nilai,
    catatan,
    nada,
}: {
    label: string;
    nilai: string;
    catatan?: string;
    nada?: 'ok';
}) {
    return (
        <div>
            <span className="block text-[11px] tracking-wide text-ink-3 uppercase">{label}</span>
            <span
                className={
                    'block font-mono text-lg ' + (nada === 'ok' ? 'text-ok' : 'text-ink')
                }
            >
                {nilai}
            </span>
            {catatan && <span className="block text-[11px] text-ink-3">{catatan}</span>}
        </div>
    );
}
