import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Inbox, Kotak, Tabel, Tombol, nadaStatus } from '@/Components/ui';

/**
 * Rincian ruang - tautan pintu.
 *
 * Bagian wireframe plate 09 yang tidak punya jalur sendiri di sidebar.
 *
 * ---------------------------------------------------------------------
 * Tautan menentukan RUANG; kode menentukan ORANG - dan kodenya TIDAK di sini
 * ---------------------------------------------------------------------
 * Dua hal ini mudah tertukar, dan menukarnya adalah kesalahan yang mahal:
 *
 *   Tautan boleh panjang, boleh ditempel di pintu, dan sendirian ia tidak
 *   membuka apa pun - ia hanya menampilkan satu kolom kode.
 *
 *   Kode pendek supaya bisa diketik di depan pintu, dan justru karena
 *   pendek ia tidak boleh menjadi satu-satunya penjaga.
 *
 * Sampai 2026-11-07 kodenya terbit DI SINI, menempel pada ruang - sehingga
 * kartu pintu yang difoto orang lewat membawa tautan sekaligus kodenya, dan
 * keduanya cukup untuk masuk. Kini kodenya menempel pada ORANG, dan
 * diterbitkan di laman Kepanitiaan (`/admin/events/{id}/panitia`). Laman ini
 * hanya mengurus tautannya.
 *
 * ---------------------------------------------------------------------
 * Yang tampil di sini tidak pernah berupa kode
 * ---------------------------------------------------------------------
 * Tidak ada kode dalam bentuk terbaca di laman ini, dan tidak ada di mana
 * pun setelah respons penerbitannya. Tautan yang perlu diganti diterbitkan
 * ulang - dan itu mencabut tautan lama, sehingga kartu pintu yang beredar
 * tidak menumpuk.
 */
type Tautan = {
    id: number;
    slug: string;
    url: string;
    status: string;
    berlaku: boolean;
    dipakai: number;
    terbit: string | null;
    kedaluwarsa: string | null;
    dicabut: string | null;
};

type Props = {
    ruang: {
        id: number;
        kode: string;
        nama: string | null;
        lokasi: string | null;
        kapasitas: number;
        pc: number;
        byod: boolean;
        status: string;
    };
    tautan: Tautan[];
    aktif: { id: number; url: string; slug: string; kedaluwarsa: string | null; dipakai: number } | null;
};

export default function RuangDetail({ ruang, tautan, aktif }: Props) {
    return (
        <AdminLayout
            judul={`Ruang ${ruang.kode}`}
            breadcrumb={`/admin/ruang · ${ruang.nama ?? ruang.kode}`}
            aksi={
                <>
                    <Link href="/admin/ruang">
                        <Tombol>Kembali</Tombol>
                    </Link>
                    <Tombol
                        nada="pri"
                        onClick={() =>
                            router.post(`/admin/ruang/${ruang.id}/terbitkan`, {}, {
                                preserveScroll: true,
                            })
                        }
                    >
                        {aktif ? 'Terbitkan ulang' : 'Terbitkan tautan'}
                    </Tombol>
                </>
            }
        >
            <Head title={`Ruang ${ruang.kode}`} />

            <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
                <div className="space-y-4">
                    <Kotak judul="Tautan masuk ruang">
                        {aktif ? (
                            <>
                                <div className="flex flex-wrap items-center gap-2">
                                    <code className="rounded-wf border border-line bg-surface-2 px-3 py-2 font-mono text-sm text-ink">
                                        {aktif.url}
                                    </code>
                                    <Tombol onClick={() => salin(aktif.url)}>Salin tautan</Tombol>
                                </div>

                                <p className="mt-3 flex flex-wrap items-center gap-2 text-xs text-ink-3">
                                    <span>
                                        Terbit {tautan[0]?.terbit} &middot; kedaluwarsa{' '}
                                        {aktif.kedaluwarsa} &middot;
                                    </span>
                                    <Chip nada={aktif.dipakai > 1 ? 'warn' : 'mute'}>
                                        {aktif.dipakai} pemakaian
                                    </Chip>
                                </p>

                                <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                                    Tautan ini tidak membuka apa pun sendirian. Ia hanya
                                    menampilkan satu kolom kode di pintu ruang.
                                </p>
                            </>
                        ) : (
                            <p className="py-4 text-sm text-ink-3">
                                Belum ada tautan yang berlaku untuk ruang ini. Terbitkan untuk
                                membuat tautan pintu.
                            </p>
                        )}
                    </Kotak>

                    <Kotak
                        judul="Riwayat tautan"
                        aksi={
                            aktif && (
                                <Tombol
                                    nada="bahaya"
                                    onClick={() =>
                                        router.post(`/admin/ruang/${ruang.id}/cabut`, {}, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    Cabut semua
                                </Tombol>
                            )
                        }
                    >
                        <Tabel<Tautan>
                            hal={{ data: tautan, total: tautan.length }}
                            kunci={(t) => t.id}
                            kosong="Belum ada tautan pernah diterbitkan."
                            kolom={[
                                {
                                    judul: 'Slug',
                                    render: (t) => (
                                        <span className="font-mono text-xs text-ink-2">
                                            /p/{t.slug}
                                        </span>
                                    ),
                                },
                                {
                                    judul: 'Status',
                                    kelas: 'w-32',
                                    render: (t) => (
                                        <Chip nada={nadaStatus(t.status)}>{t.status}</Chip>
                                    ),
                                },
                                {
                                    judul: 'Dipakai',
                                    kelas: 'w-20',
                                    render: (t) => (
                                        <span className="font-mono text-xs text-ink-2">
                                            {t.dipakai}&times;
                                        </span>
                                    ),
                                },
                                {
                                    judul: 'Terbit',
                                    kelas: 'w-28',
                                    render: (t) => (
                                        <span className="font-mono text-[11px] text-ink-3">
                                            {t.terbit ?? '-'}
                                        </span>
                                    ),
                                },
                                {
                                    judul: 'Berakhir',
                                    kelas: 'w-28',
                                    render: (t) => (
                                        <span className="font-mono text-[11px] text-ink-3">
                                            {t.dicabut ? `dicabut ${t.dicabut}` : (t.kedaluwarsa ?? '-')}
                                        </span>
                                    ),
                                },
                            ]}
                        />
                    </Kotak>
                </div>

                <div className="space-y-4">
                    <Kotak judul="Ruang">
                        <dl className="space-y-2 text-sm">
                            <Baris label="Kode" nilai={ruang.kode} mono />
                            <Baris label="Nama" nilai={ruang.nama ?? '-'} />
                            <Baris label="Lokasi" nilai={ruang.lokasi ?? '-'} />
                            <Baris label="Kapasitas" nilai={String(ruang.kapasitas)} mono />
                            <Baris
                                label="Perangkat"
                                nilai={ruang.byod ? 'BYOD (perangkat siswa)' : `${ruang.pc} PC`}
                                mono
                            />
                            <div className="flex items-center gap-2 pt-1">
                                <dt className="text-xs text-ink-3">Status</dt>
                                <dd className="ml-auto">
                                    <Chip nada={nadaStatus(ruang.status)}>{ruang.status}</Chip>
                                </dd>
                            </div>
                        </dl>
                    </Kotak>

                    <Kotak judul="Bagaimana keduanya bekerja">
                        <div className="space-y-3 text-xs leading-relaxed text-ink-2">
                            <div>
                                <p className="font-medium text-ink">Tautan menentukan ruang</p>
                                <p>
                                    Boleh panjang, boleh ditempel di pintu. Sendirian ia tidak
                                    membuka apa pun.
                                </p>
                            </div>
                            <div>
                                <p className="font-medium text-ink">Kode menentukan orang</p>
                                <p>
                                    Pendek supaya bisa diketik di depan pintu, dan karena itu
                                    tidak boleh menjadi satu-satunya penjaga. Ia milik
                                    pengawasnya sendiri, dan diterbitkan di laman{' '}
                                    <Link href="/admin/events" className="text-accent hover:underline">
                                        Kepanitiaan
                                    </Link>{' '}
                                    event yang bersangkutan.
                                </p>
                            </div>
                            <div>
                                <p className="font-medium text-ink">Keduanya tidak lagi sepasang</p>
                                <p>
                                    Tautan {ruang.kode} menentukan RUANG; kode menentukan SIAPA
                                    yang masuk. Kode pengawas ruang lain memang ditolak di
                                    pintu ini &mdash; bukan karena kodenya salah, tetapi karena
                                    pengawas itu tidak ditugaskan di sini.
                                </p>
                            </div>
                        </div>
                    </Kotak>

                    <Inbox>
                        Kode pengawas tidak diterbitkan di laman ini, melainkan di laman
                        Kepanitiaan event &mdash; satu kode per pengawas. Ia ditampilkan sekali
                        tepat saat diterbitkan, dan yang tersimpan hanya sidik jarinya.
                    </Inbox>
                </div>
            </div>
        </AdminLayout>
    );
}

function Baris({ label, nilai, mono }: { label: string; nilai: string; mono?: boolean }) {
    return (
        <div className="flex items-center gap-2">
            <dt className="text-xs text-ink-3">{label}</dt>
            <dd className={'ml-auto text-ink ' + (mono ? 'font-mono text-xs' : 'text-sm')}>
                {nilai}
            </dd>
        </div>
    );
}

/**
 * Salin ke papan klip, dengan jalur mundur untuk konteks yang tidak aman.
 *
 * navigator.clipboard tidak tersedia di luar HTTPS dan localhost, dan
 * penggunaannya tanpa pemeriksaan menghasilkan promise yang gagal tanpa
 * pesan - Admin akan mengklik "Salin tautan" dan tidak tahu apakah berhasil.
 */
function salin(teks: string) {
    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(teks);
        return;
    }

    const ta = document.createElement('textarea');
    ta.value = teks;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
}
