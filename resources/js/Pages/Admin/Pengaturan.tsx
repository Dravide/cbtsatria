import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Field, Inbox, Kotak, Tombol, kelasInput } from '@/Components/ui';

/**
 * Laman 23 - Pengaturan sekolah. Wireframe plate 23.
 *
 * Empat tab: Ujian, Keamanan, Nilai, Notifikasi.
 *
 * ---------------------------------------------------------------------
 * Yang paling penting di laman ini bukan field-nya, melainkan kalimatnya
 * ---------------------------------------------------------------------
 * "Nilai di sini adalah bawaan untuk event baru. Event yang sudah dibuat
 * menyimpan nilainya sendiri dan tidak ikut berubah."
 *
 * Itulah yang membuat laman ini aman diubah kapan saja, bahkan di tengah
 * musim ujian. Tanpa jaminan itu, Admin yang mengubah ambang pelanggaran akan
 * mengubah aturan main peserta yang sedang mengerjakan - dan tidak ada satu
 * pun tanda di layar yang memberi tahu dia.
 */
type Props = {
    pengaturan: {
        durasi_token_menit: number;
        ambang_pelanggaran: number;
        simpan_otomatis_detik: number;
        kkm: number;
        kunci_klik_kanan: boolean;
        peringatkan_keluar_tab: boolean;
        kunci_setelah_ambang: boolean;
        izinkan_tempel_uraian: boolean;
    };
};

const TAB = ['Ujian', 'Keamanan', 'Nilai', 'Notifikasi'] as const;

export default function Pengaturan({ pengaturan }: Props) {
    const [tab, setTab] = useState<(typeof TAB)[number]>('Ujian');

    const { data, setData, put, processing, errors } = useForm(pengaturan);

    const kirim = (e: React.FormEvent) => {
        e.preventDefault();
        put('/admin/pengaturan');
    };

    return (
        <AdminLayout
            judul="Pengaturan sekolah"
            breadcrumb="/admin/pengaturan"
            aksi={
                <Tombol type="submit" nada="pri" disabled={processing} onClick={() => put('/admin/pengaturan')}>
                    {processing ? 'Menyimpan...' : 'Simpan'}
                </Tombol>
            }
        >
            <Head title="Pengaturan" />

            <form onSubmit={kirim} className="space-y-4">
                <div className="flex flex-wrap gap-1 border-b border-line">
                    {TAB.map((t) => (
                        <button
                            key={t}
                            type="button"
                            onClick={() => setTab(t)}
                            className={
                                '-mb-px border-b-2 px-3 py-2 text-sm ' +
                                (tab === t
                                    ? 'border-accent font-medium text-accent'
                                    : 'border-transparent text-ink-2 hover:text-ink')
                            }
                        >
                            {t}
                        </button>
                    ))}
                </div>

                {tab === 'Ujian' && (
                    <Kotak judul="Perilaku ujian">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field
                                label="Durasi token berlaku (menit)"
                                galat={errors.durasi_token_menit}
                                petunjuk="Berapa lama token sesi tetap sah setelah dibuka pengawas."
                            >
                                <input
                                    type="number"
                                    min={1}
                                    max={240}
                                    value={data.durasi_token_menit}
                                    onChange={(e) =>
                                        setData('durasi_token_menit', Number(e.target.value))
                                    }
                                    className={kelasInput + ' font-mono'}
                                />
                            </Field>

                            <Field
                                label="Simpan otomatis tiap (detik)"
                                galat={errors.simpan_otomatis_detik}
                                petunjuk="Jeda antar penyimpanan jawaban saat siswa mengerjakan."
                            >
                                <input
                                    type="number"
                                    min={5}
                                    max={300}
                                    value={data.simpan_otomatis_detik}
                                    onChange={(e) =>
                                        setData('simpan_otomatis_detik', Number(e.target.value))
                                    }
                                    className={kelasInput + ' font-mono'}
                                />
                            </Field>

                            <Field
                                label="Ambang pelanggaran bawaan"
                                galat={errors.ambang_pelanggaran}
                                petunjuk="Jumlah pelanggaran sebelum ujian siswa terkunci."
                            >
                                <input
                                    type="number"
                                    min={1}
                                    max={50}
                                    value={data.ambang_pelanggaran}
                                    onChange={(e) =>
                                        setData('ambang_pelanggaran', Number(e.target.value))
                                    }
                                    className={kelasInput + ' font-mono'}
                                />
                            </Field>
                        </div>
                    </Kotak>
                )}

                {tab === 'Keamanan' && (
                    <Kotak judul="Kebijakan proteksi ujian">
                        <div className="space-y-2.5">
                            <Centang
                                label="Kunci klik kanan dan salin"
                                nilai={data.kunci_klik_kanan}
                                padaUbah={(v) => setData('kunci_klik_kanan', v)}
                            />
                            <Centang
                                label="Peringatkan saat keluar tab"
                                nilai={data.peringatkan_keluar_tab}
                                padaUbah={(v) => setData('peringatkan_keluar_tab', v)}
                            />
                            <Centang
                                label="Kunci ujian setelah ambang terlampaui"
                                nilai={data.kunci_setelah_ambang}
                                padaUbah={(v) => setData('kunci_setelah_ambang', v)}
                            />
                            <Centang
                                label="Izinkan tempel teks pada soal uraian"
                                nilai={data.izinkan_tempel_uraian}
                                padaUbah={(v) => setData('izinkan_tempel_uraian', v)}
                            />
                        </div>

                        <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                            Menyalakan proteksi berlebih pada soal uraian menyulitkan siswa
                            menjawab, dan yang paling sering terjadi adalah siswa mengetik
                            ulang kalimat panjang dari buku - bukan menyalin jawaban.
                        </p>
                    </Kotak>
                )}

                {tab === 'Nilai' && (
                    <Kotak judul="Ambang nilai">
                        <Field
                            label="Nilai minimum tuntas (KKM)"
                            galat={errors.kkm}
                            petunjuk="Dipakai laporan untuk menandai siswa tuntas atau belum."
                        >
                            <input
                                type="number"
                                min={0}
                                max={100}
                                value={data.kkm}
                                onChange={(e) => setData('kkm', Number(e.target.value))}
                                className={kelasInput + ' font-mono'}
                            />
                        </Field>
                    </Kotak>
                )}

                {tab === 'Notifikasi' && (
                    <Kotak judul="Notifikasi">
                        <p className="text-sm text-ink-3">
                            Notifikasi ke pengawas dan kepala sekolah dibangun bersama laman
                            monitoring di Fase 6. Belum ada yang bisa diatur di sini, dan
                            menampilkan tombol yang belum tersambung hanya akan membuat
                            Admin menebak-nebak apakah pengaturannya tersimpan.
                        </p>
                    </Kotak>
                )}

                <Inbox>
                    Nilai di sini adalah bawaan untuk event baru. Event yang sudah dibuat
                    menyimpan nilainya sendiri dan tidak ikut berubah &mdash; jadi mengubah
                    ambang di sini tidak menyentuh ujian yang sedang berjalan.
                </Inbox>
            </form>
        </AdminLayout>
    );
}

function Centang({
    label,
    nilai,
    padaUbah,
}: {
    label: string;
    nilai: boolean;
    padaUbah: (v: boolean) => void;
}) {
    return (
        <label className="flex cursor-pointer items-center gap-2.5 text-sm text-ink">
            <input
                type="checkbox"
                checked={nilai}
                onChange={(e) => padaUbah(e.target.checked)}
                className="size-4 accent-accent"
            />
            {label}
        </label>
    );
}
