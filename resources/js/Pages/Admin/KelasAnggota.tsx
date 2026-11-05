import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Inbox, Kotak, Tombol, kelasInput } from '@/Components/ui';

/**
 * Kelola anggota kelas - bagian dari laman 07.
 *
 * ---------------------------------------------------------------------
 * Mengapa "simpan daftar", bukan tambah/hapus terpisah
 * ---------------------------------------------------------------------
 * Yang dikirim adalah daftar lengkap anggota kelas. Sesudah request ini, isi
 * kelas persis sama dengan yang terlihat di layar.
 *
 * Bentuk tambah/hapus terpisah tampak lebih hemat, tetapi ia menciptakan
 * keadaan di antara dua request yang tidak punya nama: siswa yang sudah
 * ditambahkan tapi belum dihapus, atau sebaliknya. Ketika dua Admin bekerja
 * bersamaan, keadaan antara itulah yang menghasilkan perbedaan yang tidak
 * bisa dijelaskan siapa pun.
 */
type Siswa = { id: number; nama: string; username: string };

type Props = {
    kelas: { id: number; nama: string; tahun: string | null };
    anggota: Siswa[];
    kandidat: Siswa[];
};

export default function KelasAnggota({ kelas, anggota, kandidat }: Props) {
    const [pilih, setPilih] = useState<number[]>(anggota.map((a) => a.id));
    const [cari, setCari] = useState('');

    const semua = [...anggota, ...kandidat].sort((a, b) =>
        a.nama.localeCompare(b.nama, 'id'),
    );

    const tersaring = cari
        ? semua.filter(
              (s) =>
                  s.nama.toLowerCase().includes(cari.toLowerCase()) ||
                  s.username.toLowerCase().includes(cari.toLowerCase()),
          )
        : semua;

    const simpan = () => {
        router.put(`/admin/kelas/${kelas.id}/anggota`, { id: pilih }, { preserveScroll: true });
    };

    return (
        <AdminLayout
            judul={`Anggota ${kelas.nama}`}
            breadcrumb={`/admin/kelas · ${kelas.tahun ?? ''}`}
            aksi={
                <>
                    <Link href="/admin/kelas">
                        <Tombol>Kembali ke daftar kelas</Tombol>
                    </Link>
                    <Tombol nada="pri" onClick={simpan}>
                        Simpan anggota
                    </Tombol>
                </>
            }
        >
            <Head title={`Anggota ${kelas.nama}`} />

            <div className="grid gap-4 lg:grid-cols-[1fr_18rem]">
                <Kotak judul={`${pilih.length} siswa terpilih`}>
                    <input
                        value={cari}
                        onChange={(e) => setCari(e.target.value)}
                        placeholder="Cari nama atau username..."
                        className={kelasInput + ' mb-3'}
                    />

                    <div className="max-h-[28rem] overflow-y-auto rounded-wf border border-line">
                        {tersaring.length === 0 && (
                            <p className="px-3 py-6 text-center text-sm text-ink-3">
                                Tidak ada siswa yang cocok.
                            </p>
                        )}

                        {tersaring.map((s) => {
                            const on = pilih.includes(s.id);

                            return (
                                <label
                                    key={s.id}
                                    className={
                                        'flex cursor-pointer items-center gap-2.5 border-b border-line/60 px-3 py-2 text-sm last:border-0 ' +
                                        (on ? 'bg-accent-soft/60' : 'hover:bg-surface-2/50')
                                    }
                                >
                                    <input
                                        type="checkbox"
                                        checked={on}
                                        onChange={(e) =>
                                            setPilih((p) =>
                                                e.target.checked
                                                    ? [...p, s.id]
                                                    : p.filter((x) => x !== s.id),
                                            )
                                        }
                                        className="size-4 accent-accent"
                                    />
                                    <span className="text-ink">{s.nama}</span>
                                    <span className="ml-auto font-mono text-[11px] text-ink-3">
                                        {s.username}
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                </Kotak>

                <div className="space-y-3">
                    <Inbox>
                        Anggota ditarik sekali di sini, bukan ditentukan per ujian. Kalau
                        ditentukan per ujian, menambah satu siswa di tengah event berarti
                        mengulang pekerjaan itu di setiap ujian pada event tersebut.
                    </Inbox>

                    <Kotak judul="Yang ditawarkan">
                        <ul className="space-y-1.5 text-xs leading-relaxed text-ink-2">
                            <li>&bull; Siswa aktif di sekolah ini</li>
                            <li>&bull; Belum punya kelas lain di tahun ajaran ini</li>
                        </ul>
                        <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                            Siswa yang sudah punya kelas di tahun ajaran ini tidak muncul di
                            daftar. Menawarkannya hanya akan menghasilkan penolakan yang
                            tidak bisa dibaca Admin.
                        </p>
                    </Kotak>

                    <Kotak judul="Catatan">
                        <p className="text-xs leading-relaxed text-ink-2">
                            Mengeluarkan siswa dari kelas tidak menghapus akunnya. Ia hanya
                            berhenti menjadi anggota kelas ini, dan akunnya tetap ada.
                        </p>
                    </Kotak>
                </div>
            </div>
        </AdminLayout>
    );
}
