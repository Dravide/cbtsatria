import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { konfirmasi } from '@/Components/dialog';
import {
    BilahFilter,
    Chip,
    Field,
    Inbox,
    Modal,
    Paginator,
    Tabel,
    Tombol,
    kelasInput,
    nadaStatus,
    pakaiFilter,
} from '@/Components/ui';

/**
 * Laman 07 - Kelas. Wireframe plate 07.
 *
 * ---------------------------------------------------------------------
 * Pemilih TA di bar atas, bukan di dalam form
 * ---------------------------------------------------------------------
 * Wireframe menandaskannya, dan perbedaan itu bukan tata letak:
 *
 *   "Berganti TA berarti berganti daftar kelas, bukan menyaring daftar
 *    yang sama."
 *
 * Karena itu pemilihnya mengubah URL (?ta=12) dan memuat ulang daftar dari
 * server. Kalau ia diletakkan di dalam form bersama pencarian, ia akan
 * tampak seperti penyaring - dan Admin akan menyimpulkan "X-1 tidak ada"
 * padahal X-1 hanya milik tahun ajaran lain.
 *
 * Laman ini juga tidak menawarkan "semua tahun ajaran". Daftar kosong pada
 * tahun ajaran kosong adalah jawaban yang benar.
 */
type Baris = {
    id: number;
    nama: string;
    tingkat: string | null;
    wali: string | null;
    siswa: number;
    status: string;
    wali_slug: string | null;
    wali_tayang: boolean;
    wali_foto: boolean;
    wali_url: string | null;
    wali_kode: string | null;
};

type Props = {
    tahunDipilih: { id: number; nama: string; aktif: boolean } | null;
    daftarTahun: { id: number; nama: string; aktif: boolean }[];
    baris: Paginator<Baris> | { data: Baris[]; total: number };
    wali: { id: number; nama: string }[];
    filter: { cari: string };
};

export default function Kelas({ tahunDipilih, daftarTahun, baris, wali, filter }: Props) {
    /**
     * Baris yang SEDANG TAMPIL - `baris` bisa berupa paginator atau objek
     * `{data,total}`, tergantung ada-tidaknya penyaring.
     *
     * Yang dihitung untuk aksi massal adalah halaman ini, BUKAN seluruh
     * tahun ajaran: tombolnya ada di laman ini, dan angka yang ia sebut
     * harus sama dengan angka yang bisa dihitung Admin di layar. Server
     * tetap mengerjakan seluruh tahun - lihat `pantauMassal()` - tetapi
     * kalimat konfirmasi yang menyebut angka yang tidak terlihat di layar
     * adalah kalimat yang tidak bisa diperiksa sebelum ditekan.
     */
    const dataBaris = Array.isArray(baris) ? baris : (baris.data ?? []);

    /**
     * Nyalakan pantau wali untuk SELURUH kelas di tahun ajaran yang terbuka.
     *
     * =====================================================================
     * Konfirmasinya menyebut JUMLAH, dan jumlah itu dihitung dari yang
     * BELUM tayang
     * =====================================================================
     * "Nyalakan semua" pada laman yang tiga dari tiga puluh kelasnya sudah
     * tayang akan berbunyi seolah membuka tiga puluh. Yang dihitung di sini
     * adalah sisanya, dan kalimatnya menyebut bahwa kode kelas yang sudah
     * ada TIDAK diganti - itu pertanyaan pertama yang muncul sebelum
     * menekannya, karena kode lama sudah beredar di grup wali kelas.
     */
    const nyalakanMassal = async () => {
        const belum = dataBaris.filter((b) => !b.wali_tayang).length;

        if (belum === 0) {
            setTersalin('Semua kelas di tahun ajaran ini sudah tayang.');

            return;
        }

        const ya = await konfirmasi({
            judul: `Nyalakan pantau wali untuk ${belum} kelas?`,
            pesan:
                `Nama siswa di ${belum} kelas itu bisa dibaca siapa pun yang memegang tautannya. ` +
                'Setiap kelas mendapat tautan dan kode enam angka sendiri — bagikan lewat grup wali ' +
                'kelas masing-masing. Kelas yang sudah tayang dilewati, dan kodenya TIDAK diganti. ' +
                'Foto berita acara tidak ikut dinyalakan.',
            labelYa: `Nyalakan ${belum} kelas`,
            labelTidak: 'Jangan',
        });

        if (!ya) {
            return;
        }

        router.post(
            '/admin/kelas/pantau-massal',
            { academic_year_id: tahunDipilih?.id ?? null },
            { preserveScroll: true },
        );
    };

    const [tambah, setTambah] = useState(false);
    const [ubah, setUbah] = useState<Baris | null>(null);
    const [tersalin, setTersalin] = useState<string | null>(null);

    /**
     * Salin satu nilai ke papan klip.
     *
     * =====================================================================
     * Tiga jalur kegagalan, dan ketiganya HARUS berbunyi
     * =====================================================================
     * Versi pertama fungsi ini gagal tanpa suara pada dua dari tiga jalur,
     * dan keduanya sudah terjadi di pemakaian nyata:
     *
     *   1. nilainya `null` - sebelumnya `return` diam-diam, sehingga tombol
     *      "Salin tautan" pada baris yang tautannya belum bisa disusun tidak
     *      berbuat apa pun. Yang menekannya menyimpulkan tombolnya rusak.
     *   2. `navigator.clipboard` menolak - terjadi di luar konteks aman
     *      (`http://` selain localhost), dan itu keadaan lazim di sekolah
     *      yang membuka konsol lewat IP LAN.
     *   3. `document.execCommand('copy')` mengembalikan `false`. Ia TIDAK
     *      melempar, sehingga `try/catch` tidak menangkapnya - dan versi
     *      pertama melaporkan "disalin" untuk penyalinan yang gagal.
     *
     * Nilai yang gagal disalin selalu DITULIS di pesannya. Admin yang
     * menyalin enam kelas berturut-turut harus bisa melanjutkan dengan
     * mengetik ulang dari layar, bukan menebak apa yang tadi gagal.
     */
    const salin = async (teks: string | null, label: string) => {
        if (teks === null || teks === '') {
            setTersalin(`${label} belum tersedia untuk kelas ini.`);

            return;
        }

        try {
            await navigator.clipboard.writeText(teks);
            setTersalin(`${label} disalin.`);

            return;
        } catch {
            // Jatuh ke cadangan di bawah - alasan, bukan keputusasaan.
        }

        const kotak = document.createElement('textarea');
        kotak.value = teks;
        kotak.setAttribute('readonly', '');
        kotak.style.position = 'fixed';
        kotak.style.top = '0';
        kotak.style.opacity = '0';
        document.body.appendChild(kotak);

        // `select()` pada elemen yang belum terlihat di sebagian peramban
        // memilih rentang kosong; `setSelectionRange` menutupnya.
        kotak.select();
        kotak.setSelectionRange(0, teks.length);

        let berhasil = false;

        try {
            // Nilai baliknya DIPERIKSA - lihat catatan di atas: ia `false`
            // pada kegagalan, bukan lemparan.
            berhasil = document.execCommand('copy');
        } catch {
            berhasil = false;
        } finally {
            document.body.removeChild(kotak);
        }

        setTersalin(
            berhasil
                ? `${label} disalin.`
                : `Tidak bisa menyalin otomatis. ${label}: ${teks} - salin manual dari teks ini.`,
        );
    };

    /**
     * Menyalakan pantau wali untuk satu kelas.
     *
     * Kalimat konfirmasinya menyebut APA yang menjadi terbaca, bukan
     * sekadar "Aktifkan?". Yang dibuka di sini adalah nama tiga puluh anak
     * ke alamat internet, dan Admin yang menekannya perlu tahu itu sebelum
     * menekan, bukan sesudah.
     */
    const nyalakanWali = async (b: Baris) => {
        const ya = await konfirmasi({
            judul: `Buka laman pantau wali untuk ${b.nama}?`,
            pesan:
                'Nama ' +
                b.siswa +
                ' siswa kelas ini bisa dibaca siapa pun yang memegang tautannya. ' +
                'Jam masuk, jam mulai, dan lama mengerjakan tetap menuntut kode kelas — ' +
                'bagikan tautan dan kodenya lewat grup wali kelas, bukan pengumuman sekolah.',
            labelYa: 'Nyalakan',
            labelTidak: 'Jangan',
        });

        if (!ya) {
            return;
        }

        router.patch(`/admin/kelas/${b.id}/pantau`, { tayang: true }, { preserveScroll: true });
    };

    /**
     * Terbitkan ulang kode kelas.
     *
     * Tautannya TIDAK berubah, dan itu ditulis di konfirmasinya: Admin yang
     * mengira tautannya ikut berganti akan membagikan ulang tautan lama dan
     * mengira kodenya sudah disampaikan.
     */
    const terbitkanKode = async (b: Baris) => {
        const ya = await konfirmasi({
            judul: `Terbitkan kode baru untuk ${b.nama}?`,
            pesan:
                'Kode lama langsung tidak berlaku. Tautannya tetap sama, jadi Anda hanya ' +
                'perlu mengirim kode barunya ke grup wali kelas.',
            labelYa: 'Terbitkan kode',
            labelTidak: 'Jangan',
        });

        if (!ya) {
            return;
        }

        router.post(`/admin/kelas/${b.id}/kode-wali`, {}, { preserveScroll: true });
    };

    const f = pakaiFilter('/admin/kelas', { cari: filter.cari, ta: String(tahunDipilih?.id ?? '') });

    const gantiTahun = (id: string) => {
        router.get('/admin/kelas', { ta: id }, { preserveState: false, replace: true });
    };

    if (daftarTahun.length === 0) {
        return (
            <AdminLayout judul="Kelas" breadcrumb="/admin/kelas">
                <Head title="Kelas" />
                <Inbox>
                    Belum ada tahun ajaran. Kelas selalu milik satu tahun ajaran, jadi
                    tambahkan tahun ajaran lebih dulu di laman Tahun ajaran.
                </Inbox>
            </AdminLayout>
        );
    }

    return (
        <AdminLayout
            judul="Kelas"
            breadcrumb={`/admin/kelas${tahunDipilih ? ` · TA ${tahunDipilih.nama}` : ''}`}
            aksi={
                <>
                    {/* Pemilih TA di bar atas - lihat catatan di atas. */}
                    <select
                        value={String(tahunDipilih?.id ?? '')}
                        onChange={(e) => gantiTahun(e.target.value)}
                        className={kelasInput + ' w-auto font-mono'}
                    >
                        {daftarTahun.map((t) => (
                            <option key={t.id} value={t.id}>
                                TA {t.nama}
                                {t.aktif ? ' (aktif)' : ''}
                            </option>
                        ))}
                    </select>

                    {/* Impor membuka laman impor DENGAN tahun ajarannya, dan
                        itu bukan kenyamanan: nama kelas unik per tahun
                        ajaran, sehingga berkas kelas tanpa tahun yang jelas
                        tidak punya jawaban yang benar soal ke mana barisnya
                        masuk. Yang sedang dibuka di sini adalah tahun yang
                        akan menerimanya. */}
                    <Tombol
                        onClick={() =>
                            router.visit(`/admin/impor/kelas?ta=${tahunDipilih?.id ?? ''}`)
                        }
                    >
                        Impor
                    </Tombol>

                    {/* Nyalakan massal - hanya dirender bila memang ADA
                        yang belum tayang. Tombol yang ditekan lalu berkata
                        "semua sudah tayang" adalah tombol yang mengajarkan
                        Admin untuk berhenti mempercayainya. */}
                    {dataBaris.some((b) => !b.wali_tayang) && (
                        <Tombol onClick={nyalakanMassal}>Nyalakan pantau wali</Tombol>
                    )}

                    <Tombol nada="pri" onClick={() => setTambah(true)}>
                        Tambah kelas
                    </Tombol>
                </>
            }
        >
            <Head title="Kelas" />

            {tahunDipilih && !tahunDipilih.aktif && (
                <div className="mb-3">
                    <Inbox>
                        Anda sedang melihat tahun ajaran <strong>{tahunDipilih.nama}</strong> yang
                        tidak aktif. Daftar di bawah adalah kelas tahun itu, bukan kelas tahun
                        berjalan.
                    </Inbox>
                </div>
            )}

            <div className="mb-3">
                <BilahFilter padaKirim={f.terapkan}>
                    <input
                        value={f.nilai.cari ?? ''}
                        onChange={(e) => f.ubah('cari', e.target.value)}
                        placeholder="Cari nama kelas..."
                        className={kelasInput + ' max-w-xs'}
                    />
                    <Tombol type="submit" nada="pri">
                        Terapkan
                    </Tombol>
                    <Tombol onClick={f.reset}>Reset</Tombol>
                </BilahFilter>
            </div>

            {/* Umpan balik salin. Toast global akan hilang dalam tiga detik
                dan Admin yang menyalin enam kode berturut-turut tidak bisa
                membedakan mana yang sudah - jadi baris ini MENETAP sampai
                nilai lain disalin. */}
            {tersalin !== null && (
                <p className="mb-2 rounded-wf border border-info/25 bg-info-bg px-3 py-1.5 font-mono text-[11px] text-info">
                    {tersalin}
                </p>
            )}

            <Tabel<Baris>
                hal={baris}
                kunci={(b) => b.id}
                kosong="Belum ada kelas di tahun ajaran ini."
                kolom={[
                    {
                        judul: 'Kelas',
                        render: (b) => (
                            <span className="font-medium text-ink">{b.nama}</span>
                        ),
                    },
                    {
                        judul: 'Tingkat',
                        kelas: 'w-24',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">{b.tingkat ?? '-'}</span>
                        ),
                    },
                    {
                        judul: 'Wali kelas',
                        render: (b) => (
                            <span className="text-ink-2">{b.wali ?? 'belum ditentukan'}</span>
                        ),
                    },
                    {
                        judul: 'Siswa',
                        kelas: 'w-24',
                        render: (b) => (
                            <span className="font-mono text-sm text-ink">
                                {b.siswa}
                            </span>
                        ),
                    },
                    {
                        judul: 'Status',
                        kelas: 'w-24',
                        render: (b) => <Chip nada={nadaStatus(b.status)}>{b.status}</Chip>,
                    },
                    {
                        judul: 'Pantau wali',
                        kelas: 'w-44',
                        render: (b) =>
                            b.wali_tayang ? (
                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <Chip nada="ok">tayang</Chip>
                                    <span
                                        className="font-mono text-[11px] text-ink-2"
                                        title="Kode kelas - dibagikan wali kelas ke grup"
                                    >
                                        {b.wali_kode ?? '••••••'}
                                    </span>
                                    {/* Dua tombol terpisah, bukan satu menu:
                                        keduanya dipakai BERULANG pada minggu
                                        ujian - "kirim tautannya lagi ke grup"
                                        dan "kodenya berubah, kirim yang
                                        baru" - dan menu tersembunyi untuk
                                        dua aksi yang paling sering dipakai
                                        adalah klik yang diulang tiga puluh
                                        kali. */}
                                    {/* Tombol "Salin tautan" hanya aktif bila
                                        tautannya MEMANG ADA. `wali_url` null
                                        terjadi saat sekolah belum punya
                                        `papan_slug` - keadaan yang lazim
                                        pada sekolah yang belum pernah
                                        menyalakan papan pengawas - dan
                                        tombol yang ditekan lalu berkata
                                        "belum tersedia" lebih buruk daripada
                                        tombol yang mati dengan sebabnya. */}
                                    <button
                                        type="button"
                                        onClick={() => salin(b.wali_url, 'Tautan')}
                                        disabled={b.wali_url === null}
                                        className="text-[11px] text-accent hover:underline disabled:cursor-not-allowed disabled:text-ink-3 disabled:no-underline"
                                        title={
                                            b.wali_url === null
                                                ? 'Sekolah ini belum menyalakan papan publik. Nyalakan lebih dulu di Pengaturan → Papan publik.'
                                                : 'Salin tautan laman wali'
                                        }
                                    >
                                        Salin tautan
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => salin(b.wali_kode, 'Kode')}
                                        disabled={b.wali_kode === null}
                                        className="text-[11px] text-accent hover:underline disabled:cursor-not-allowed disabled:text-ink-3 disabled:no-underline"
                                        title={
                                            b.wali_kode === null
                                                ? 'Kode belum diterbitkan untuk kelas ini.'
                                                : 'Salin kode kelas'
                                        }
                                    >
                                        Salin kode
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => terbitkanKode(b)}
                                        className="text-[11px] text-ink-3 hover:text-ink"
                                        title="Terbitkan ulang kode - tautannya tidak berubah"
                                    >
                                        Kode baru
                                    </button>
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => nyalakanWali(b)}
                                    className="text-[11px] text-accent hover:underline"
                                    title="Buka laman pantau untuk wali kelas ini"
                                >
                                    Nyalakan
                                </button>
                            ),
                    },
                    {
                        judul: '',
                        kelas: 'w-40 text-right',
                        render: (b) => (
                            <span className="flex justify-end gap-3">
                                <Link
                                    href={`/admin/kelas/${b.id}/anggota`}
                                    className="text-xs text-accent hover:underline"
                                >
                                    Kelola anggota
                                </Link>
                                <button
                                    onClick={() => setUbah(b)}
                                    className="text-xs text-accent hover:underline"
                                >
                                    Ubah
                                </button>
                            </span>
                        ),
                    },
                ]}
            />

            <FormKelas
                // Tahun ajaran yang sedang dipilih ikut jadi kunci: berpindah
                // tab tahun lalu membuka "Tambah kelas" akan menyimpan kelas
                // baru ke tahun yang PERTAMA dibuka, bukan yang sedang tampil.
                key={'baru-' + (tahunDipilih?.id ?? 'kosong')}
                terbuka={tambah}
                tahunId={tahunDipilih?.id ?? null}
                wali={wali}
                padaTutup={() => setTambah(false)}
            />

            {/* `key` bukan hiasan: `Modal` mengembalikan `null` dari dalam
                dirinya sendiri, jadi form di luarnya tidak pernah dilepas dan
                `useForm` menyimpan nilai awalnya sejak panel masih tertutup -
                saat `kelas` masih kosong. Tanpa `key`, "Ubah" membuka kolom
                kosong. */}
            <FormKelas
                key={'ubah-' + (ubah?.id ?? 'kosong')}
                terbuka={ubah !== null}
                tahunId={tahunDipilih?.id ?? null}
                wali={wali}
                kelas={ubah}
                padaTutup={() => setUbah(null)}
            />
        </AdminLayout>
    );
}

function FormKelas({
    terbuka,
    tahunId,
    wali,
    kelas,
    padaTutup,
}: {
    terbuka: boolean;
    tahunId: number | null;
    wali: { id: number; nama: string }[];
    kelas?: Baris | null;
    padaTutup: () => void;
}) {
    const ubah = Boolean(kelas);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        academic_year_id: tahunId ?? 0,
        name: kelas?.nama ?? '',
        grade_level: kelas?.tingkat ?? '',
        homeroom_teacher_id: '',
        status: kelas?.status ?? 'aktif',
    });

    return (
        <Modal
            judul={ubah ? `Ubah ${kelas?.nama}` : 'Tambah kelas'}
            terbuka={terbuka}
            padaTutup={padaTutup}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    const opsi = {
                        onSuccess: () => {
                            padaTutup();
                            reset();
                        },
                    };

                    if (ubah && kelas) {
                        put(`/admin/kelas/${kelas.id}`, opsi);
                    } else {
                        post('/admin/kelas', opsi);
                    }
                }}
                className="space-y-3"
            >
                <Field
                    label="Nama kelas"
                    galat={errors.name}
                    petunjuk="Nama boleh sama di tahun ajaran berbeda - X-1 muncul lagi setiap tahun."
                >
                    <input
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="X-1"
                        className={kelasInput}
                    />
                </Field>

                <Field label="Tingkat" galat={errors.grade_level}>
                    <input
                        value={data.grade_level}
                        onChange={(e) => setData('grade_level', e.target.value)}
                        placeholder="X / XI / XII"
                        className={kelasInput}
                    />
                </Field>

                <Field
                    label="Wali kelas"
                    galat={errors.homeroom_teacher_id}
                    petunjuk="Hanya akun berguru yang ditawarkan di sini."
                >
                    <select
                        value={data.homeroom_teacher_id}
                        onChange={(e) => setData('homeroom_teacher_id', e.target.value)}
                        className={kelasInput}
                    >
                        <option value="">Belum ditentukan</option>
                        {wali.map((w) => (
                            <option key={w.id} value={w.id}>
                                {w.nama}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field label="Status" galat={errors.status}>
                    <select
                        value={data.status}
                        onChange={(e) => setData('status', e.target.value)}
                        className={kelasInput}
                    >
                        <option value="aktif">aktif</option>
                        <option value="nonaktif">nonaktif</option>
                    </select>
                </Field>

                <div className="flex gap-2 pt-1">
                    <Tombol type="submit" nada="pri" disabled={processing}>
                        Simpan
                    </Tombol>
                    <Tombol onClick={padaTutup}>Batal</Tombol>
                </div>
            </form>
        </Modal>
    );
}
