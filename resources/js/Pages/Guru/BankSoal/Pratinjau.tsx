import { Head, Link } from '@inertiajs/react';
import GuruLayout from '@/Layouts/GuruLayout';
import { Chip, Inbox } from '@/Components/ui';
import { ButirPratinjau, KartuButir } from '@/Components/PratinjauButir';

/**
 * Pratinjau satu soal - wireframe-peran.html plate 09, versi satu butir.
 *
 * ---------------------------------------------------------------------
 * Mengapa laman ini ada padahal builder sudah menampilkan soalnya
 * ---------------------------------------------------------------------
 * Builder menampilkan soal sebagai NILAI FIELD: `<sup>2</sup>` di dalam kotak
 * teks terbaca sebagai delapan huruf, bukan sebagai pangkat. Artinya HTML yang
 * tersimpan tidak pernah terlihat sebagai HTML sampai soalnya diujikan - dan
 * saat itu Guru tidak bisa lagi memperbaikinya.
 *
 * Yang paling sering lolos lewat celah itu adalah hal yang justru paling
 * merugikan di layar siswa: tabel selebar layar, gambar yang ukurannya salah,
 * rumus yang tag-nya tidak seimbang. Semuanya terlihat dalam satu detik di
 * laman ini, dan tidak terlihat sama sekali di builder.
 *
 * ---------------------------------------------------------------------
 * Pratinjau ini TIDAK berpura-pura menjadi ujian
 * ---------------------------------------------------------------------
 * Plate 09 menggambar pratinjau di dalam kerangka siswa - penghitung waktu,
 * pemilih nomor, tombol "ragu-ragu". Yang dirender di sini adalah KERANGKA
 * SOALNYA saja, tanpa kerangka ujian, dan itu disengaja: penghitung waktu yang
 * tidak berjalan dan tombol yang tidak menyimpan apa pun adalah layar yang
 * membuat Guru mengira ia sudah menguji hal yang belum ia uji.
 *
 * Bentuk SOALNYA justru sengaja dibuat sama dengan yang nanti diterima siswa:
 * `ExamQuestion::untukSiswa()` membuang kunci, rubrik, dan pembahasan, dan
 * laman ini menampilkan ketiganya di bagian yang jelas terpisah - sehingga
 * Guru melihat persis mana yang akan sampai ke siswa dan mana yang tidak.
 */
type Props = {
    soal: ButirPratinjau;
    bolehUbah: boolean;
};

export default function PratinjauSoal({ soal, bolehUbah }: Props) {
    return (
        <GuruLayout
            judul="Pratinjau soal"
            breadcrumb="/guru/bank-soal / pratinjau"
            aksi={
                <>
                    <Link
                        href="/guru/bank-soal"
                        className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
                    >
                        Kembali ke bank soal
                    </Link>
                    {/* Tautan "Ubah" HANYA muncul bila Guru memang boleh
                        mengubahnya. Soal Guru lain boleh dibaca - dan karena
                        itu laman ini terbuka baginya - tetapi tombol Ubah yang
                        selalu berakhir 403 adalah tombol yang mengajari Guru
                        untuk tidak mempercayai layarnya. */}
                    {bolehUbah && (
                        <Link
                            href={`/guru/bank-soal/${soal.id}/ubah`}
                            className="rounded-wf border border-accent bg-accent px-3 py-1.5 text-xs font-medium text-on-accent hover:bg-accent/90"
                        >
                            Ubah soal
                        </Link>
                    )}
                </>
            }
        >
            <Head title={`Pratinjau — ${soal.judul}`} />

            {!bolehUbah && (
                <div className="mb-3">
                    <Inbox>
                        Soal ini ditulis <strong>{soal.penulis ?? 'Guru lain'}</strong>. Anda
                        boleh membacanya dan memakainya di paket Anda, tetapi yang dapat
                        mengubahnya hanya penulisnya. Bila perlu menyesuaikan, duplikat dulu
                        dari bank soal — salinannya menjadi milik Anda.
                    </Inbox>
                </div>
            )}

            <div className="mb-3 flex flex-wrap items-center gap-2 text-xs text-ink-3">
                <Chip nada="info">{soal.jenis_label}</Chip>
                <span className="font-mono">{soal.kode ?? '—'}</span>
                {soal.mapel && <span>{soal.mapel}</span>}
                {soal.tingkat && <span>Kesulitan {soal.tingkat}</span>}
                <span className="ml-auto">
                    Tampilan di bawah ini sama dengan yang akan dilihat siswa, kecuali
                    bagian kunci, rubrik, dan pembahasan — ketiganya tidak pernah dikirim ke
                    peramban siswa.
                </span>
            </div>

            <div className="mx-auto max-w-3xl">
                {/* Tanpa `nomor`, tanpa `aksi`: satu soal bukan bagian dari
                    urutan apa pun, dan tidak ada yang bisa dilakukan dari
                    dalam kartunya. */}
                <KartuButir butir={soal} />
            </div>
        </GuruLayout>
    );
}
