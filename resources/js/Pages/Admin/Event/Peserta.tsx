import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    BilahFilter,
    Chip,
    Inbox,
    Kotak,
    Paginator,
    Tabel,
    Tombol,
    kelasInput,
    nadaStatus,
    pakaiFilter,
} from '@/Components/ui';

/**
 * Laman 14 - Kelas & peserta. Wireframe plate 14, PRD §6.7.
 *
 * ---------------------------------------------------------------------
 * Kelas dan peserta di satu laman
 * ---------------------------------------------------------------------
 * Keduanya satu keputusan - "kelas mana yang ikut" dan "siapa saja orangnya"
 * - dan plate 14 menampilkan keduanya bersamaan. Memisahkannya berarti Admin
 * menetapkan kelas di satu tempat, menekan Tarik peserta di tempat lain, lalu
 * kembali ke tempat pertama untuk memeriksa hasilnya.
 *
 * ---------------------------------------------------------------------
 * Tiga angka, dan yang ketiga paling mudah salah
 * ---------------------------------------------------------------------
 * "Kelas / Peserta / Dikecualikan". Angka ketiga dihitung dari peserta
 * berstatus dikecualikan, dan itu sebabnya peserta yang keluar DITANDAI, bukan
 * dihapus. Sekolah yang mengecualikan tujuh siswa karena ikut lomba perlu
 * melihat angka tujuh, bukan nol.
 *
 * ---------------------------------------------------------------------
 * "Tarik peserta" adalah aksi yang bisa diulang
 * ---------------------------------------------------------------------
 * Tombolnya tetap ada sesudah dipakai, dan itu disengaja: kelas bisa
 * ditambahkan kemudian, siswa bisa berpindah kelas, dan penarikan ulang
 * adalah cara memperbaikinya. Yang tidak dilakukannya adalah menyentuh
 * peserta bersumber manual - lihat EventParticipantService.
 */
type Baris = {
    id: number;
    nis: string | null;
    nama: string | null;
    aktif: boolean;
    kelas: string | null;
    sumber: string;
    status: string;
};

type KelasOpsi = { id: number; nama: string; tingkat: string | null; anggota: number };

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    baris: Paginator<Baris>;
    angka: { kelas: number; peserta: number; dikecualikan: number };
    kelas: KelasOpsi[];
    kelas_tersedia: { id: number; nama: string }[];
    ruang: { id: number; kode: string | null; nama: string | null; kapasitas: number; status: string }[];
    ruang_tersedia: { id: number; kode: string; nama: string; kapasitas: number; byod: boolean }[];
    filter: { cari: string };
};

export default function EventPeserta({
    event,
    baris,
    angka,
    kelas,
    kelas_tersedia,
    ruang,
    ruang_tersedia,
    filter,
}: Props) {
    const f = pakaiFilter(`/admin/events/${event.id}/peserta`, { cari: filter.cari });

    const [pilihKelas, setPilihKelas] = useState<number[]>(kelas.map((k) => k.id));
    const [pilihRuang, setPilihRuang] = useState<number[]>(ruang.map((r) => r.id));
    const [centang, setCentang] = useState<number[]>([]);

    // Isi-nya `RequestPayload`, bukan `Record<string, unknown>`: Inertia
    // menerima FormData juga, dan tipenya sengaja tidak menerima objek
    // sembarang supaya nilai yang tidak bisa diserialkan gagal di sini -
    // bukan di peramban, sebagai permintaan yang tidak pernah terkirim.
    const kirim = (
        metode: 'post' | 'put',
        jalur: string,
        isi: Record<string, string | number | (string | number)[]>,
    ) => {
        const opsi = { preserveScroll: true, onSuccess: () => setCentang([]) };

        if (metode === 'put') {
            router.put(jalur, isi, opsi);

            return;
        }

        router.post(jalur, isi, opsi);
    };

    const semuaTercentang =
        baris.data.length > 0 && centang.length === baris.data.length;

    return (
        <AdminLayout
            judul="Kelas & peserta"
            breadcrumb={`/admin/events · ${event.kode} · Kelas & peserta`}
            aksi={
                <>
                    <Tombol
                        nada="pri"
                        disabled={angka.kelas === 0}
                        title={
                            angka.kelas === 0
                                ? 'Tetapkan minimal satu kelas terlebih dahulu.'
                                : undefined
                        }
                        onClick={() => kirim('post', `/admin/events/${event.id}/peserta/tarik`, {})}
                    >
                        Tarik peserta
                    </Tombol>
                </>
            }
        >
            <Head title={`Kelas & peserta · ${event.nama}`} />

            <div className="mb-3 grid gap-3 sm:grid-cols-3">
                <KPI label="Kelas ditetapkan" nilai={angka.kelas} />
                <KPI label="Peserta terdaftar" nilai={angka.peserta} nada="ok" />
                <KPI label="Dikecualikan" nilai={angka.dikecualikan} nada={angka.dikecualikan > 0 ? 'warn' : undefined} />
            </div>

            <div className="mb-4 grid gap-4 lg:grid-cols-2">
                <Kotak
                    judul="Kelas peserta"
                    aksi={
                        <Tombol
                            nada="sek"
                            onClick={() =>
                                kirim('put', `/admin/events/${event.id}/peserta/kelas`, { id: pilihKelas })
                            }
                        >
                            Simpan kelas
                        </Tombol>
                    }
                >
                    <p className="mb-3 text-xs leading-relaxed text-ink-2">
                        Kelas yang ikut event ini. Menyimpan hanya menetapkan kelasnya &mdash;
                        pesertanya ditarik terpisah lewat tombol <em>Tarik peserta</em>, karena
                        kelas ditetapkan saat event disusun sementara peserta ditarik saat
                        pendaftaran ditutup.
                    </p>

                    <div className="max-h-56 space-y-1 overflow-y-auto">
                        {[...kelas, ...kelas_tersedia.map((k) => ({ ...k, tingkat: null, anggota: 0 }))]
                            .sort((a, b) => a.nama.localeCompare(b.nama))
                            .map((k) => {
                                const sudah = kelas.some((x) => x.id === k.id);

                                return (
                                    <label
                                        key={k.id}
                                        className="flex items-center gap-2 rounded-wf px-1.5 py-1 text-sm hover:bg-surface-2/60"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={pilihKelas.includes(k.id)}
                                            onChange={(e) =>
                                                setPilihKelas(
                                                    e.target.checked
                                                        ? [...pilihKelas, k.id]
                                                        : pilihKelas.filter((x) => x !== k.id),
                                                )
                                            }
                                        />
                                        <span className="text-ink">{k.nama}</span>
                                        {sudah && k.anggota > 0 && (
                                            <span className="ml-auto font-mono text-[11px] text-ink-3">
                                                {k.anggota} siswa
                                            </span>
                                        )}
                                    </label>
                                );
                            })}

                        {kelas.length === 0 && kelas_tersedia.length === 0 && (
                            <p className="text-xs text-ink-3">
                                Belum ada kelas pada tahun ajaran event ini.
                            </p>
                        )}
                    </div>
                </Kotak>

                <Kotak
                    judul="Ruang teralokasi"
                    aksi={
                        <Tombol
                            nada="sek"
                            onClick={() =>
                                kirim('put', `/admin/events/${event.id}/peserta/ruang`, { id: pilihRuang })
                            }
                        >
                            Simpan ruang
                        </Tombol>
                    }
                >
                    <p className="mb-3 text-xs leading-relaxed text-ink-2">
                        Kapasitas dijumlahkan dari ruang yang dialokasikan. Kekurangan kapasitas
                        tidak menghalangi penyimpanan &mdash; ia muncul sebagai butir kesiapan yang
                        harus dibereskan sebelum event ditandai siap.
                    </p>

                    <div className="max-h-56 space-y-1 overflow-y-auto">
                        {[...ruang.map((r) => ({ id: r.id, kode: r.kode ?? '', nama: r.nama ?? '', kapasitas: r.kapasitas, byod: false })), ...ruang_tersedia]
                            .sort((a, b) => a.kode.localeCompare(b.kode))
                            .map((r) => (
                                <label
                                    key={r.id}
                                    className="flex items-center gap-2 rounded-wf px-1.5 py-1 text-sm hover:bg-surface-2/60"
                                >
                                    <input
                                        type="checkbox"
                                        checked={pilihRuang.includes(r.id)}
                                        onChange={(e) =>
                                            setPilihRuang(
                                                e.target.checked
                                                    ? [...pilihRuang, r.id]
                                                    : pilihRuang.filter((x) => x !== r.id),
                                            )
                                        }
                                    />
                                    <span className="font-mono text-xs text-ink-2">{r.kode}</span>
                                    <span className="text-ink">{r.nama}</span>
                                    {r.byod && <Chip nada="mute">BYOD</Chip>}
                                    <span className="ml-auto font-mono text-[11px] text-ink-3">
                                        {r.kapasitas} kursi
                                    </span>
                                </label>
                            ))}

                        {ruang.length === 0 && ruang_tersedia.length === 0 && (
                            <p className="text-xs text-ink-3">
                                Belum ada ruang aktif di sekolah ini.
                            </p>
                        )}
                    </div>
                </Kotak>
            </div>

            <div className="mb-3 flex flex-wrap items-end gap-2">
                <BilahFilter padaKirim={f.terapkan}>
                    <input
                        value={f.nilai.cari ?? ''}
                        onChange={(e) => f.ubah('cari', e.target.value)}
                        placeholder="Cari nama atau NIS..."
                        className={kelasInput + ' max-w-xs'}
                    />
                    <Tombol type="submit" nada="pri">
                        Cari
                    </Tombol>
                </BilahFilter>

                {/* Aksi massal, bukan per baris: sekolah mengecualikan siswa per
                    kelompok, dan satu per satu berarti mengulang tiga puluh
                    kali. Tombolnya mati saat tidak ada yang dicentang - lebih
                    baik daripada tombol hidup yang mengirim daftar kosong. */}
                <div className="ml-auto flex gap-2">
                    <Tombol
                        disabled={centang.length === 0}
                        onClick={() =>
                            kirim('post', `/admin/events/${event.id}/peserta/massal`, {
                                id: centang,
                                status: 'dikecualikan',
                            })
                        }
                    >
                        Kecualikan terpilih
                    </Tombol>
                    <Tombol
                        disabled={centang.length === 0}
                        onClick={() =>
                            kirim('post', `/admin/events/${event.id}/peserta/massal`, {
                                id: centang,
                                status: 'terdaftar',
                            })
                        }
                    >
                        Kembalikan terpilih
                    </Tombol>
                </div>
            </div>

            <Tabel<Baris>
                hal={baris}
                kunci={(b) => b.id}
                kosong="Belum ada peserta. Tetapkan kelas, lalu tekan Tarik peserta."
                kolom={[
                    {
                        judul: '',
                        kelas: 'w-10',
                        render: (b) => (
                            <input
                                type="checkbox"
                                checked={centang.includes(b.id)}
                                onChange={(e) =>
                                    setCentang(
                                        e.target.checked
                                            ? [...centang, b.id]
                                            : centang.filter((x) => x !== b.id),
                                    )
                                }
                            />
                        ),
                    },
                    {
                        judul: 'NIS',
                        kelas: 'w-28',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">{b.nis ?? '—'}</span>
                        ),
                    },
                    {
                        judul: 'Nama',
                        render: (b) => (
                            <span>
                                <span className="text-ink">{b.nama ?? '—'}</span>
                                {/* Siswa nonaktif tetap di daftar bila ia sudah
                                    tertarik sebelumnya, dan ditandai di sini.
                                    Menghapusnya diam-diam berarti jumlah
                                    peserta berubah tanpa sebab yang terlihat. */}
                                {!b.aktif && (
                                    <span className="ml-2 text-[11px] text-warn">
                                        akun nonaktif
                                    </span>
                                )}
                            </span>
                        ),
                    },
                    {
                        judul: 'Kelas',
                        kelas: 'w-32',
                        render: (b) => (
                            <span className="text-xs text-ink-2">{b.kelas ?? '—'}</span>
                        ),
                    },
                    {
                        judul: 'Sumber',
                        kelas: 'w-24',
                        render: (b) => <Chip nada={nadaStatus(b.sumber)}>{b.sumber}</Chip>,
                    },
                    {
                        judul: 'Status',
                        kelas: 'w-32',
                        render: (b) => <Chip nada={nadaStatus(b.status)}>{b.status}</Chip>,
                    },
                ]}
            />

            <div className="mt-2 flex items-center gap-2 text-xs text-ink-3">
                <button
                    onClick={() =>
                        setCentang(
                            semuaTercentang ? [] : baris.data.map((b) => b.id),
                        )
                    }
                    className="hover:text-accent hover:underline"
                >
                    {semuaTercentang ? 'Batalkan semua' : 'Pilih semua di halaman ini'}
                </button>
                {centang.length > 0 && <span>· {centang.length} terpilih</span>}
            </div>

            <div className="mt-3">
                <Inbox>
                    Peserta ditarik <strong>sekali</strong> di sini, bukan ditentukan per ujian.
                    Bila ditentukan per ujian, menambah satu siswa di tengah event berarti
                    mengulang pekerjaan di belasan tempat. Peserta yang dikecualikan tetap
                    tersimpan sebagai catatan &mdash; ia tidak dihapus, sehingga alasan
                    ketidakhadirannya masih bisa ditelusuri sesudah event selesai.
                </Inbox>
            </div>
        </AdminLayout>
    );
}

function KPI({ label, nilai, nada }: { label: string; nilai: number; nada?: 'ok' | 'warn' }) {
    const warna = nada === 'ok' ? 'text-ok' : nada === 'warn' ? 'text-warn' : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className={'mt-1 font-mono text-2xl font-medium ' + warna}>
                {nilai.toLocaleString('id-ID')}
            </p>
        </div>
    );
}
