import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Field, Inbox, Peringatan, Tabel, Tombol, kelasInput } from '@/Components/ui';

/**
 * Publikasi hasil - wireframe-admin.html plate 21.
 *
 * ---------------------------------------------------------------------
 * SATU tombol, dan alasannya ditulis di laman ini juga
 * ---------------------------------------------------------------------
 * Kalimat plate 21: "Publikasi adalah satu aksi untuk seluruh event, bukan
 * per ujian... Membuka nilai sebagian membuat siswa membandingkan nilai yang
 * belum final." Tabelnya tetap per ujian, tetapi hanya untuk MEMPERLIHATKAN
 * ujian mana yang menahan - bukan supaya bisa diterbitkan satu per satu.
 *
 * ---------------------------------------------------------------------
 * Dua kunci yang berbeda, dan laman ini menampilkan keduanya terpisah
 * ---------------------------------------------------------------------
 * `terbit` adalah "hasil terlihat siswa". `terkunci` adalah "Guru masih boleh
 * mengoreksi". Keduanya biasanya sejalan, tetapi blueprint keputusan #10
 * mengizinkan Admin membuka koreksi TANPA menarik publikasi - koreksi satu
 * salah ketik tanpa membuat seluruh angkatan melihat nilai yang lenyap lalu
 * muncul lagi.
 *
 * Karena itu aksinya terpisah, dan alasannya wajib untuk keduanya: yang
 * dicabut atau dibuka adalah hasil yang sudah dibaca seluruh angkatan.
 */

type BarisUjian = {
    id: number;
    judul: string;
    kode: string | null;
    mapel: string | null;
    peserta: number;
    objektif: number;
    uraian_dinilai: number;
    uraian_total: number;
    sisa_uraian: number;
};

type Publikasi = {
    terbit: boolean;
    terbit_pada: string | null;
    terbit_label: string | null;
    diumumkan: boolean;
    diumumkan_pada: string | null;
    terkunci: boolean;
    sisa_koreksi: number;
    boleh_terbit: boolean;
    ujian_tertahan: number;
    total_ujian: number;
    tampilkan_objektif: boolean;
    tampilkan_pembahasan: boolean;
};

type Props = {
    event: { id: number; nama: string; kode: string; status: string; status_label: string };
    ujian: BarisUjian[];
    publikasi: Publikasi;
};

export default function Hasil({ event, ujian, publikasi }: Props) {
    const [alasan, setAlasan] = useState<Record<string, string>>({});
    const [kirim, setKirim] = useState<string | null>(null);
    const [galat, setGalat] = useState<Record<string, string>>({});
    const [tampil, setTampil] = useState({
        objektif: publikasi.tampilkan_objektif,
        pembahasan: publikasi.tampilkan_pembahasan,
    });

    /**
     * Satu pengirim untuk seluruh aksi, dengan nama aksi sebagai penanda.
     *
     * Ditulis sebagai satu fungsi, bukan enam, karena keenamnya melakukan hal
     * yang sama: POST ke rute event + kembali. Yang berbeda hanya rutenya dan
     * muatannya - dan enam salinan `router.post` adalah enam tempat yang harus
     * diubah bersamaan saat rutenya berganti.
     */
    const aksi = (
        nama: string,
        jalur: string,
        muatan: Record<string, string | boolean> = {},
        metode: 'post' | 'get' = 'post',
    ) => {
        if (kirim !== null) {
            return;
        }

        setKirim(nama);
        setGalat({});

        const opsi = {
            preserveScroll: true,
            onError: (e: Record<string, string>) => setGalat(e),
            onFinish: () => setKirim(null),
        };

        if (metode === 'get') {
            router.get(jalur, muatan, opsi);
        } else {
            router.post(jalur, muatan, opsi);
        }
    };

    return (
        <AdminLayout
            judul="Publikasi hasil"
            breadcrumb={`Event · ${event.nama}`}
            aksi={
                // Tombol yang dinonaktifkan tetap DIKLIK oleh Admin yang
                // penasaran, dan itu sebabnya `title`-nya menjelaskan sebabnya;
                // tombol mati tanpa kalimat hanya mengajari Admin untuk
                // menebak. Kotak inbox di bawah juga mengatakannya.
                <Tombol
                    nada="pri"
                    disabled={!publikasi.boleh_terbit || kirim !== null}
                    title={
                        publikasi.boleh_terbit
                            ? 'Terbitkan hasil seluruh event'
                            : 'Nonaktif sampai seluruh uraian dikoreksi'
                    }
                    onClick={() =>
                        publikasi.boleh_terbit &&
                        aksi('terbitkan', `/admin/events/${event.id}/hasil/terbitkan`)
                    }
                >
                    {kirim === 'terbitkan' ? 'Menerbitkan…' : 'Publikasikan'}
                </Tombol>
            }
        >
            <Head title={`Hasil · ${event.nama}`} />

            {galat.alasan && <p className="mb-2 text-[11px] text-bad">Alasan wajib diisi.</p>}

            {/* -------------------------------------------------- Status */}
            <div className="mb-3">
                {publikasi.terbit ? (
                    <div className="rounded-wf border border-ok/25 bg-ok-bg px-3 py-2.5 text-xs leading-relaxed text-ok">
                        Hasil dipublikasikan {publikasi.terbit_label ?? ''}
                        {publikasi.diumumkan ? ' dan pengumuman sudah dikirim' : ''}. Siswa
                        melihat nilainya sendiri di laman Nilai saya.
                        {publikasi.terkunci && ' Koreksi terkunci.'}
                    </div>
                ) : !publikasi.boleh_terbit ? (
                    <Peringatan>
                        Publikasi nonaktif sampai seluruh uraian terkoreksi.{' '}
                        <strong>{publikasi.ujian_tertahan}</strong> dari{' '}
                        {publikasi.total_ujian} ujian masih memiliki uraian belum dinilai.
                    </Peringatan>
                ) : (
                    <Inbox>
                        Seluruh uraian sudah dikoreksi. Publikasi berlaku untuk SELURUH event
                        sekaligus — menerbitkan sebagian membuat siswa membandingkan nilai yang
                        belum final.
                    </Inbox>
                )}
            </div>

            {/* ---------------------------------------------------- Tabel */}
            <Tabel<BarisUjian>
                hal={{ data: ujian, total: ujian.length }}
                kunci={(u) => u.id}
                kosong="Event ini belum punya ujian."
                kolom={[
                    {
                        judul: 'Ujian',
                        render: (u) => (
                            <div className="min-w-0">
                                <span className="block truncate text-sm text-ink">{u.judul}</span>
                                <span className="text-[11px] text-ink-3">
                                    {[u.mapel, u.kode].filter(Boolean).join(' · ')}
                                </span>
                            </div>
                        ),
                    },
                    {
                        judul: 'Peserta',
                        kelas: 'w-20 text-right',
                        render: (u) => (
                            <span className="font-mono text-xs text-ink-2">{u.peserta}</span>
                        ),
                    },
                    {
                        judul: 'Objektif',
                        kelas: 'w-24',
                        render: (u) => (
                            // Objektif dinilai seketika saat attempt ditutup, jadi
                            // angkanya selalu penuh - chip-nya hijau karena tidak
                            // ada pekerjaan yang menunggu di kolom ini. Yang
                            // ditampilkan adalah "sudah/total", bukan angka
                            // tunggal: angka 278 tanpa penyebut tidak menjelaskan
                            // apakah seluruh peserta sudah tercatat.
                            <Chip nada={u.objektif >= u.peserta ? 'ok' : 'warn'}>
                                {u.objektif}/{u.peserta}
                            </Chip>
                        ),
                    },
                    {
                        judul: 'Uraian',
                        kelas: 'w-28',
                        render: (u) => (
                            <Chip
                                nada={
                                    u.sisa_uraian === 0
                                        ? 'ok'
                                        : u.uraian_dinilai === 0
                                          ? 'bad'
                                          : 'warn'
                                }
                            >
                                {u.uraian_dinilai}/{u.uraian_total}
                            </Chip>
                        ),
                    },
                ]}
            />

            {/* ------------------------------------------- Setelah terbit */}
            <div className="mt-3 grid gap-3 lg:grid-cols-2">
                <section className="rounded-wf border border-line bg-surface">
                    <header className="flex items-center gap-2 border-b border-line px-4 py-2.5">
                        <h2 className="font-display text-sm font-semibold text-ink">
                            Setelah dipublikasikan
                        </h2>
                        <div className="ml-auto">
                            <Tombol
                                disabled={!publikasi.terbit || publikasi.diumumkan || kirim !== null}
                                title={
                                    publikasi.terbit
                                        ? 'Kirim pengumuman ke siswa'
                                        : 'Terbitkan hasil lebih dulu'
                                }
                                onClick={() =>
                                    aksi('umumkan', `/admin/events/${event.id}/hasil/umumkan`)
                                }
                            >
                                {publikasi.diumumkan ? 'Sudah diumumkan' : 'Kirim pengumuman'}
                            </Tombol>
                        </div>
                    </header>

                    <div className="space-y-2 p-4">
                        <label className="flex items-center gap-2 text-xs text-ink">
                            <input
                                type="checkbox"
                                checked={tampil.objektif}
                                onChange={(e) =>
                                    setTampil((t) => ({ ...t, objektif: e.target.checked }))
                                }
                                className="h-3.5 w-3.5 accent-[var(--color-accent)]"
                            />
                            Tampilkan nilai objektif ke siswa
                        </label>

                        <label className="flex items-center gap-2 text-xs text-ink">
                            <input
                                type="checkbox"
                                checked={tampil.pembahasan}
                                onChange={(e) =>
                                    setTampil((t) => ({ ...t, pembahasan: e.target.checked }))
                                }
                                className="h-3.5 w-3.5 accent-[var(--color-accent)]"
                            />
                            Tampilkan pembahasan soal
                        </label>

                        <p className="text-[11px] leading-relaxed text-ink-3">
                            Nilai akhir selalu terlihat siswa setelah publikasi. Dua kotak ini
                            menentukan apakah <em>rincian</em> per bagian dan pembahasan ikut
                            tampil. Tanpa nilai objektif, siswa dengan uraian belum lengkap tidak
                            bisa menebak separuh angkanya.
                        </p>

                        <div className="flex justify-end pt-1">
                            <Tombol
                                disabled={kirim !== null}
                                onClick={() =>
                                    aksi(
                                        'tampilan',
                                        `/admin/events/${event.id}/hasil/tampilan`,
                                        {
                                            tampilkan_objektif: tampil.objektif,
                                            tampilkan_pembahasan: tampil.pembahasan,
                                        },
                                    )
                                }
                            >
                                {kirim === 'tampilan' ? 'Menyimpan…' : 'Simpan tampilan'}
                            </Tombol>
                        </div>
                    </div>
                </section>

                <section className="rounded-wf border border-line bg-surface">
                    <header className="border-b border-line px-4 py-2.5">
                        <h2 className="font-display text-sm font-semibold text-ink">
                            Koreksi &amp; publikasi
                        </h2>
                    </header>

                    <div className="space-y-3 p-4">
                        <AlasanAksi
                            label="Alasan"
                            nilai={alasan.utama ?? ''}
                            padaUbah={(v) => setAlasan((a) => ({ ...a, utama: v }))}
                            petunjuk="Wajib untuk menarik publikasi dan membuka kunci koreksi. Tercatat di log aktivitas."
                        />

                        <div className="flex flex-wrap gap-2">
                            {/* Tarik publikasi - hasil kembali tidak terlihat
                                siswa, dan koreksi terbuka kembali. */}
                            <Tombol
                                nada="bahaya"
                                disabled={!publikasi.terbit || !alasan.utama || kirim !== null}
                                onClick={() =>
                                    aksi('tarik', `/admin/events/${event.id}/hasil/tarik`, {
                                        alasan: alasan.utama ?? '',
                                    })
                                }
                            >
                                {kirim === 'tarik' ? 'Menarik…' : 'Tarik publikasi'}
                            </Tombol>

                            {/* Kunci koreksi - arahnya berlawanan dengan dua
                                tombol di atas, dan itu memang maksudnya. */}
                            {publikasi.terkunci ? (
                                <Tombol
                                    disabled={!publikasi.terbit || !alasan.utama || kirim !== null}
                                    onClick={() =>
                                        aksi(
                                            'buka',
                                            `/admin/events/${event.id}/hasil/buka-koreksi`,
                                            { alasan: alasan.utama ?? '' },
                                        )
                                    }
                                >
                                    {kirim === 'buka' ? 'Membuka…' : 'Buka kunci koreksi'}
                                </Tombol>
                            ) : (
                                <Tombol
                                    disabled={!publikasi.terbit || kirim !== null}
                                    onClick={() =>
                                        aksi(
                                            'kunci',
                                            `/admin/events/${event.id}/hasil/kunci-koreksi`,
                                        )
                                    }
                                >
                                    {kirim === 'kunci' ? 'Mengunci…' : 'Kunci koreksi'}
                                </Tombol>
                            )}

                            <Tombol
                                disabled={kirim !== null}
                                title="Hitung ulang seluruh nilai dan analisis butir setelah kunci soal diperbaiki"
                                onClick={() =>
                                    aksi(
                                        'hitung',
                                        `/admin/events/${event.id}/hasil/hitung-ulang`,
                                    )
                                }
                            >
                                {kirim === 'hitung' ? 'Menghitung…' : 'Hitung ulang'}
                            </Tombol>
                        </div>

                        <p className="text-[11px] leading-relaxed text-ink-3">
                            Membuka kunci koreksi <strong>tidak</strong> menarik publikasi: siswa
                            tetap melihat nilainya, dan Guru boleh memperbaiki satu koreksi tanpa
                            seluruh angkatan melihat angkanya lenyap lalu muncul lagi. Menarik
                            publikasi melakukan sebaliknya, dan karena itu keduanya tombol
                            terpisah.
                        </p>
                    </div>
                </section>
            </div>
        </AdminLayout>
    );
}

/* ------------------------------------------------------------- Alasan */

/**
 * Kotak alasan yang dipakai bersama tiga aksi.
 *
 * Satu kotak, bukan tiga: hanya satu aksi yang dijalankan pada satu waktu, dan
 * tiga kotak alasan di satu panel berarti Admin mengetik kalimat yang sama tiga
 * kali lalu menekan yang salah.
 */
function AlasanAksi({
    label,
    nilai,
    padaUbah,
    petunjuk,
}: {
    label: string;
    nilai: string;
    padaUbah: (v: string) => void;
    petunjuk?: string;
}) {
    return (
        <Field label={label} petunjuk={petunjuk}>
            <textarea
                value={nilai}
                onChange={(e) => padaUbah(e.target.value)}
                rows={2}
                className={kelasInput}
                placeholder="Contoh: nilai Agama kelas XI-2 terkoreksi setelah kunci soal dibetulkan."
            />
        </Field>
    );
}
