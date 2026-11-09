import { Head } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    BilahFilter,
    Chip,
    Kotak,
    Paginator,
    Tabel,
    Tombol,
    kelasInput,
    pakaiFilter,
} from '@/Components/ui';

/**
 * Laman 24 - Log aktivitas. Wireframe plate 24.
 *
 * ---------------------------------------------------------------------
 * Laman ini tidak punya tombol hapus, dan itu bukan kelalaian
 * ---------------------------------------------------------------------
 * Membuka token, mengoreksi presensi, dan membuka kunci ujian semuanya dapat
 * dipersoalkan setelah ujian selesai. Catatannya karena itu harus tetap ada
 * meskipun Admin-nya sudah berganti - dan itu hanya berarti bila tidak ada
 * satu pun jalan menghapusnya, termasuk bagi Admin yang sedang masuk.
 *
 * Baris "Objek" memakai chip berwarna sesuai jenisnya, bukan teks biasa:
 * saat membaca log untuk mencari satu kejadian, yang dicari mata adalah
 * pola - kumpulan chip merah di antara chip biru menandakan ada serangkaian
 * penguncian di jam yang sama.
 */
type Baris = {
    id: number;
    jam: string | null;
    tanggal: string | null;
    aktor: string;
    aksi: string;
    objek: string | null;
    objek_id: number | null;
    ip: string | null;
};

type Props = {
    baris: Paginator<Baris>;
    filter: { cari: string; aksi: string; aktor: string };
    jumlah: number;
};

export default function Log({ baris, filter, jumlah }: Props) {
    const f = pakaiFilter('/admin/log', {
        cari: filter.cari,
        aksi: filter.aksi,
        aktor: filter.aktor,
    });

    return (
        <AdminLayout
            judul="Log aktivitas"
            breadcrumb="/admin/log"
            aksi={
                <>
                    <Tombol onClick={f.terapkan}>Saring</Tombol>
                    {/* Ekspor log belum dibangun, dan tombolnya tidak
                        dipalsukan: yang mengunduh berkas kosong lebih buruk
                        daripada yang tidak ada.

                        Kalimatnya dulu menunjuk "Fase 8 bersama laporan", dan
                        itu menyesatkan sekarang. Fase 8 memang sudah menulis
                        penulis XLSX/CSV - tetapi ekspor pengguna di Fase 10
                        justru diberi tabel dan job SENDIRI, bukan menumpang
                        `report_exports`, karena ekspor laporan terikat pada
                        satu event. Ekspor log akan mengikuti bentuk yang sama:
                        antrean, riwayat, dan rute unduh berwenang. */}
                    <Tombol disabled title="Ekspor log aktivitas belum dibangun">
                        Ekspor
                    </Tombol>
                </>
            }
        >
            <Head title="Log aktivitas" />

            <div className="mb-3 grid gap-3 sm:grid-cols-3">
                <Kotak>
                    <p className="text-xs text-ink-3">Total catatan</p>
                    <p className="mt-1 font-mono text-2xl font-medium text-ink">
                        {jumlah.toLocaleString('id-ID')}
                    </p>
                </Kotak>
                <Kotak>
                    <p className="text-xs text-ink-3">Cakupan</p>
                    <p className="mt-1 text-sm text-ink-2">Seluruh aktivitas sekolah ini</p>
                </Kotak>
                <Kotak>
                    <p className="text-xs text-ink-3">Retensi</p>
                    <p className="mt-1 text-sm text-ink-2">Tidak dihapus otomatis</p>
                </Kotak>
            </div>

            {/* Bilah filter di antara angka ringkasan dan tabel - Admin harus
                melihat angka SEBELUM menyaring, supaya ia tahu seberapa besar
                yang sedang ia persempit. */}
            <div className="mb-3">
                <BilahFilter padaKirim={f.terapkan}>
                    <input
                        value={f.nilai.cari ?? ''}
                        onChange={(e) => f.ubah('cari', e.target.value)}
                        placeholder="Cari aksi atau jenis objek..."
                        className={kelasInput + ' max-w-xs'}
                    />
                    <input
                        value={f.nilai.aksi ?? ''}
                        onChange={(e) => f.ubah('aksi', e.target.value)}
                        placeholder="Jenis aksi"
                        className={kelasInput + ' max-w-[14rem]'}
                    />
                    <Tombol type="submit" nada="pri">
                        Terapkan
                    </Tombol>
                    <Tombol onClick={f.reset}>Reset</Tombol>
                </BilahFilter>
            </div>

            <Tabel<Baris>
                hal={baris}
                kunci={(b) => b.id}
                kosong="Belum ada aktivitas tercatat."
                kolom={[
                    {
                        judul: 'Jam',
                        kelas: 'w-24',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">{b.jam ?? '-'}</span>
                        ),
                    },
                    { judul: 'Aktor', render: (b) => <span className="text-ink">{b.aktor}</span> },
                    { judul: 'Aksi', render: (b) => <span className="text-ink-2">{b.aksi}</span> },
                    {
                        judul: 'Objek',
                        kelas: 'w-40',
                        render: (b) => (
                            <span className="flex items-center gap-2">
                                {b.objek && <Chip nada={nadaObjek(b.objek)}>{b.objek}</Chip>}
                                {b.objek_id !== null && (
                                    <span className="font-mono text-[11px] text-ink-3">
                                        #{b.objek_id}
                                    </span>
                                )}
                            </span>
                        ),
                    },
                    {
                        judul: 'IP',
                        kelas: 'w-32',
                        render: (b) => (
                            <span className="font-mono text-[11px] text-ink-3">{b.ip ?? '-'}</span>
                        ),
                    },
                ]}
            />

            <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                Laman ini tidak punya tombol hapus, dan itu disengaja: membuka token,
                mengoreksi presensi, dan membuka kunci ujian semuanya dapat dipersoalkan
                setelah ujian selesai, jadi catatannya harus tetap ada meskipun Admin-nya
                sudah berganti.
            </p>
        </AdminLayout>
    );
}

function nadaObjek(objek: string) {
    if (objek.includes('Room') || objek.includes('User')) {
        return 'info' as const;
    }
    if (objek.includes('AcademicYear') || objek.includes('School')) {
        return 'ok' as const;
    }
    if (objek.includes('Subject') || objek.includes('Class')) {
        return 'mute' as const;
    }

    return 'warn' as const;
}
