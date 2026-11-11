import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import GuruLayout from '@/Layouts/GuruLayout';
import {
    BilahFilter,
    Chip,
    Opsi,
    Paginator,
    Tabel,
    Tombol,
    kelasInput,
    nadaStatus,
    pakaiFilter,
} from '@/Components/ui';
import { GenerateAi, JenisAi, Mapel } from './GenerateAi';

/**
 * Laman Guru 02 - bank soal. Wireframe-peran.html plate 02.
 *
 * ---------------------------------------------------------------------
 * Tidak ada pemilih event di bar atas laman ini, dan itu disengaja
 * ---------------------------------------------------------------------
 * Soal adalah milik Guru dan sekolah, bukan milik satu ujian (wireframe
 * plate 02). Yang menyaring di sini adalah mapel, jenis, tingkat, dan teks -
 * tidak pernah event. Menambahkan pemilih event berarti Guru menyaring
 * soalnya dengan cara yang tidak berarti: soal yang sama muncul di ujian
 * ganjil dan ujian genap, dan itu justru gunanya bank soal.
 *
 * ---------------------------------------------------------------------
 * Kolom "Penulis" ditampilkan meskipun Guru tidak bisa menyaringnya
 * ---------------------------------------------------------------------
 * Bank soal ini BERSAMA - Guru melihat soal Guru lain untuk mapel yang sama.
 * Tanpa kolom penulis, Guru yang menemukan soal bagus tidak tahu harus
 * berterima kasih kepada siapa, dan yang lebih penting: Guru yang menemukan
 * soal KELIRU tidak tahu siapa yang bisa memperbaikinya. Ia sendiri tidak
 * bisa - dan itu yang harus terbaca dari layar, bukan dari pesan galat
 * setelah ia menekan Simpan.
 *
 * Karena itu baris milik Guru lain TIDAK menampilkan tombol Ubah. Bukan
 * karena server akan menolaknya - server memang menolak (403) - melainkan
 * karena tombol yang selalu gagal adalah tombol yang mengajari Guru untuk
 * tidak mempercayai layarnya.
 */
type Baris = {
    id: number;
    kode: string;
    judul: string;
    cuplikan: string;
    jenis: string;
    jenis_label: string;
    jenis_pendek: string;
    tingkat: string | null;
    mapel: string | null;
    penulis: string | null;
    dipakai: number;
    status: string;
    milik_saya: boolean;
};

type Props = {
    baris: Paginator<Baris>;
    filter: {
        cari: string;
        mapel: string;
        jenis: string;
        tingkat: string;
        status: string;
    };
    jumlah: number;
    mapel: Mapel[];
    jenis: { nilai: string; label: string; pendek: string }[];
    status: Opsi[];
    bolehMenulis: boolean;
    // Jenis soal yang boleh dihasilkan AI, dibaca server dari
    // PenulisSoalAi::JENIS_DIDUKUNG - bukan daftar yang ditulis ulang di
    // sini, karena daftar salinan berhenti diperbarui diam-diam.
    jenisAi?: JenisAi[];
    // Hanya SAKLARNYA. Base URL dan nama model tidak dikirim ke peramban.
    ai?: boolean;
};

export default function BankSoalIndex({
    baris,
    filter,
    jumlah,
    mapel,
    jenis,
    status,
    bolehMenulis,
    jenisAi = [],
    ai = false,
}: Props) {
    const [pilih, setPilih] = useState<number[]>([]);
    const [modalAi, setModalAi] = useState(false);

    const f = pakaiFilter('/guru/bank-soal', {
        cari: filter.cari,
        mapel: filter.mapel,
        jenis: filter.jenis,
        tingkat: filter.tingkat,
        status: filter.status,
    });

    const bolehAksi = pilih.length === 1;
    const satu = pilih.length === 1 ? baris.data.find((b) => b.id === pilih[0]) : undefined;

    /** Duplikat mengikuti baris yang ditandai, bukan baris pertama daftar. */
    const duplikat = () => {
        if (!satu) {
            return;
        }
        router.post(`/guru/bank-soal/${satu.id}/duplikat`);
    };

    const arsipkan = () => {
        if (!satu) {
            return;
        }
        router.post(`/guru/bank-soal/${satu.id}/arsip`, undefined, {
            onSuccess: () => setPilih([]),
        });
    };

    return (
        <GuruLayout
            judul="Bank soal"
            breadcrumb="/guru/bank-soal"
            aksi={
                <>
                    {bolehMenulis && ai && jenisAi.length > 0 && (
                        <Tombol onClick={() => setModalAi(true)}>Generate AI</Tombol>
                    )}
                    <Link href="/guru/bank-soal/impor" className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2">
                        Impor
                    </Link>
                    {bolehMenulis && (
                        <Link
                            href="/guru/bank-soal/baru"
                            className="rounded-wf border border-accent bg-accent px-3 py-1.5 text-xs font-medium text-on-accent hover:bg-accent/90"
                        >
                            Buat soal
                        </Link>
                    )}
                </>
            }
        >
            <Head title="Bank soal" />

            {/* Pratinjau hasil AI ditaruh DI ATAS bilah saringan: ia bukan
                bagian dari penyaringan, dan Guru yang baru meminta sepuluh
                soal harus melihatnya tanpa menggulir melewati lima menu
                turun. Modal dan tombolnya hidup di komponen ini. */}
            <GenerateAi
                mapel={mapel}
                jenisAi={jenisAi}
                terbuka={modalAi}
                padaTutup={() => setModalAi(false)}
            />

            <div className="mb-3">
                <BilahFilter padaKirim={f.terapkan}>
                    <select
                        value={f.nilai.mapel ?? ''}
                        onChange={(e) => f.ubah('mapel', e.target.value)}
                        className={kelasInput + ' max-w-[13rem]'}
                    >
                        <option value="">Semua mata pelajaran</option>
                        {mapel.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.nama}
                            </option>
                        ))}
                    </select>

                    <select
                        value={f.nilai.jenis ?? ''}
                        onChange={(e) => f.ubah('jenis', e.target.value)}
                        className={kelasInput + ' max-w-[11rem]'}
                    >
                        <option value="">Semua jenis</option>
                        {jenis.map((j) => (
                            <option key={j.nilai} value={j.nilai}>
                                {j.label}
                            </option>
                        ))}
                    </select>

                    <select
                        value={f.nilai.tingkat ?? ''}
                        onChange={(e) => f.ubah('tingkat', e.target.value)}
                        className={kelasInput + ' max-w-[9rem]'}
                    >
                        <option value="">Semua tingkat</option>
                        <option value="mudah">Mudah</option>
                        <option value="sedang">Sedang</option>
                        <option value="sulit">Sulit</option>
                    </select>

                    <select
                        value={f.nilai.status ?? ''}
                        onChange={(e) => f.ubah('status', e.target.value)}
                        className={kelasInput + ' max-w-[9rem]'}
                    >
                        <option value="">Semua status</option>
                        {status.map((s) => (
                            <option key={s.nilai} value={s.nilai}>
                                {s.label}
                            </option>
                        ))}
                    </select>

                    <input
                        value={f.nilai.cari ?? ''}
                        onChange={(e) => f.ubah('cari', e.target.value)}
                        placeholder="Cari teks soal atau kode..."
                        className={kelasInput + ' max-w-xs'}
                    />

                    <Tombol type="submit" nada="pri">
                        Terapkan
                    </Tombol>
                    <Tombol onClick={f.reset}>Reset</Tombol>
                </BilahFilter>
            </div>

            {/* Tabel, bukan daftar kartu: soal bisa berjumlah ratusan, dan
                kartu yang punya tinggi tetap berarti lima baris per layar. */}
            <Tabel<Baris>
                hal={baris}
                kunci={(b) => b.id}
                kosong={
                    jumlah === 0 && !f.nilai.cari && !f.nilai.mapel
                        ? 'Bank soal masih kosong. Mulai dengan "Buat soal" atau impor dari berkas.'
                        : 'Tidak ada soal yang cocok dengan saringan ini.'
                }
                kolom={[
                    {
                        judul: '',
                        kelas: 'w-8',
                        render: (b) => (
                            <input
                                type="checkbox"
                                checked={pilih.includes(b.id)}
                                onChange={(e) =>
                                    setPilih(e.target.checked ? [b.id] : [])
                                }
                                aria-label={`Pilih soal ${b.kode}`}
                                className="align-middle"
                            />
                        ),
                    },
                    {
                        judul: 'Soal',
                        render: (b) => (
                            <div className="min-w-0">
                                <Link
                                    href={`/guru/bank-soal/${b.id}/ubah`}
                                    className="block truncate font-medium text-ink hover:text-accent"
                                >
                                    {b.cuplikan || b.judul}
                                </Link>
                                <span className="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-ink-3">
                                    <span className="font-mono">{b.kode}</span>
                                    {b.mapel && <span>{b.mapel}</span>}
                                    {/* Penulis hanya ditulis bila bukan milik
                                        sendiri - "saya" pada setiap baris
                                        adalah kolom yang tidak menjelaskan
                                        apa pun. */}
                                    {!b.milik_saya && b.penulis && (
                                        <span title="Soal Guru lain - boleh dipakai, tidak boleh diubah">
                                            penulis: {b.penulis}
                                        </span>
                                    )}
                                </span>
                            </div>
                        ),
                    },
                    {
                        judul: 'Jenis',
                        kelas: 'w-20',
                        render: (b) => <Chip nada="mute">{b.jenis_pendek}</Chip>,
                    },
                    {
                        judul: 'Tingkat',
                        kelas: 'w-20',
                        render: (b) => (
                            <span className="text-xs text-ink-3">{b.tingkat ?? '—'}</span>
                        ),
                    },
                    {
                        judul: 'Dipakai',
                        kelas: 'w-20',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">
                                {b.dipakai > 0 ? `${b.dipakai}×` : '—'}
                            </span>
                        ),
                    },
                    {
                        judul: 'Status',
                        kelas: 'w-20',
                        render: (b) => <Chip nada={nadaStatus(b.status)}>{b.status}</Chip>,
                    },
                ]}
            />

            <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-ink-3">
                <span>
                    {pilih.length === 0
                        ? 'Tandai satu baris untuk menduplikasi atau mengarsipkannya.'
                        : satu && !satu.milik_saya
                          ? 'Soal ini ditulis Guru lain. Anda boleh memakainya di paket, tetapi tidak mengubahnya — duplikat dulu bila perlu disesuaikan.'
                          : 'Salinan hasil duplikat menjadi milik Anda sendiri.'}
                </span>
                <span className="ml-auto flex gap-2">
                    {/* Pratinjau AKTIF untuk baris siapa pun, termasuk soal
                        Guru lain - membacanya memang izin yang diberikan.
                        Karena itu tombolnya tidak memakai `bolehMenulis`, yang
                        menjaga Duplikat dan Arsipkan.

                        Tanpa tombol ini, satu-satunya cara melihat soal sebagai
                        HTML adalah membuka builder - yang menampilkannya
                        sebagai NILAI FIELD, sehingga tag-nya tidak pernah
                        terlihat sebagai bentuk. */}
                    <Link
                        href={satu ? `/guru/bank-soal/${satu.id}/pratinjau` : '#'}
                        aria-disabled={!bolehAksi}
                        className={
                            'rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium ' +
                            (bolehAksi
                                ? 'text-ink hover:bg-surface-2'
                                : 'pointer-events-none text-ink-3/50')
                        }
                    >
                        Pratinjau
                    </Link>
                    <Tombol
                        onClick={duplikat}
                        disabled={!bolehAksi || !bolehMenulis}
                        title={!bolehMenulis ? 'Hanya Guru yang dapat menduplikasi soal' : undefined}
                    >
                        Duplikat
                    </Tombol>
                    <Tombol
                        nada="bahaya"
                        onClick={arsipkan}
                        disabled={!bolehAksi || !satu?.milik_saya}
                        title={
                            satu && !satu.milik_saya
                                ? 'Hanya penulis soal yang dapat mengarsipkannya'
                                : undefined
                        }
                    >
                        Arsipkan
                    </Tombol>
                </span>
            </div>
        </GuruLayout>
    );
}
