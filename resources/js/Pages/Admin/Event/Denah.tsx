import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Inbox, Kotak, TombolTautan } from '@/Components/ui';

/**
 * Laman 16c - Denah tempat duduk satu ruang. Wireframe plate 16c.
 *
 * ---------------------------------------------------------------------
 * Yang digambar adalah kocokan BENIH event, dihitung ulang
 * ---------------------------------------------------------------------
 * Sampai 2026-11-10 laman ini menggambar
 * `exam_participants.exam_session_room_id` apa adanya. Itu salah, dan
 * kesalahannya tampak di layar: pada event yang hanya SEBAGIAN ujiannya punya
 * sesi - mis. ujian kelas 8 belum dijadwalkan - penyebaran tidak pernah
 * menulis kursi untuk siswa kelas 8, sehingga ruang berkapasitas 39 terbaca
 * "terisi 17" dan denahnya hanya memuat kelas 7. Padahal laman Penempatan
 * menghitung ulang kocokan dari benihnya dan menyebut angka yang benar.
 *
 * Sekarang keduanya membaca peta YANG SAMA: benih di `exam_event_shuffles`,
 * diterjemahkan `PetaKursiEvent` menjadi penghuni tiap ruang event. Denah
 * karena itu sama dengan pratinjau penempatan, dan tidak menunggu "Sebar"
 * untuk ruang ujian yang belum punya sesi.
 *
 * Yang harus dibaca pencetaknya: selama penyebaran belum selesai, kursi di
 * kertas bisa berbeda dari baris yang tersimpan di `exam_participants`. Denah
 * ini menggambar apa yang AKAN berlaku - dan itulah yang ditempel di meja.
 *
 * Nomor kursinya datang dari server, dihitung `NomorKursi` - aturan yang sama
 * dengan yang dipakai pengawas di papan presensi dan yang tercetak di tablo.
 * Kalau React menghitungnya sendiri, layar dan kertas bisa berbeda, dan yang
 * salah baru ketahuan saat tablo sudah dibagikan ke enam pengawas.
 *
 * ---------------------------------------------------------------------
 * Bentuk Z: empat kolom meja, disusur satu baris penuh lalu turun
 * ---------------------------------------------------------------------
 * Kursi 1..8 berurutan sepanjang satu jalur masuk, sehingga pengawas yang
 * menyusur dari pintu tidak perlu menyeberang ruangan untuk menemukan kursi
 * berikutnya. Yang menggambar kolomnya adalah CSS di sini; yang MENENTUKAN
 * nomor dan mejanya adalah `NomorKursi` di server.
 *
 * ---------------------------------------------------------------------
 * Jumlah meja datang dari kapasitas ruang, bukan dipatok dua puluh
 * ---------------------------------------------------------------------
 * Sanggupnya satu ruang ditentukan nilai Kapasitas di laman Ruang: ruang 32
 * kursi mendapat 16 meja, bukan 20. Karena itu seluruh selnya - termasuk
 * yang kosong - dikirim server, dan laman ini hanya menggambarnya.
 */
/**
 * Satu kursi yang terisi.
 *
 * `id` adalah id `exam_participants` dan boleh null: peserta yang ujiannya
 * belum ditarik - atau yang ikut lebih dari satu ujian sehingga barisnya
 * tidak tunggal - memang belum punya satu baris ujian untuk ditunjuk. Denah
 * tetap menggambarnya, karena yang dijawab denah adalah "siapa duduk di
 * sini", bukan "baris mana yang tersimpan". `student_id` selalu ada, dan
 * itulah identitas yang mengikat.
 */
type Peserta = {
    id: number | null;
    student_id: number;
    nama: string;
    nis: string | null;
    kelas: string | null;
};

type Sel = {
    kursi: number;
    meja: number;
    baris: number;
    kolom: number;
    peserta: Peserta | null;
};

type Ruang = {
    id: number;
    kode: string;
    nama: string | null;
    kapasitas: number;
    terisi: number;
    kursi_per_meja: number;
    meja_per_baris: number;
    sel: Sel[];
    baris: {
        kursi: number;
        meja: number;
        peserta_id: number | null;
        student_id: number;
        nama: string;
        nis: string | null;
        kelas: string | null;
    }[];
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    tablo_url: string;
    denah_url: string;
    /** PDF denah - lembar yang ditempel di meja. `?ruang=` memilih satu. */
    pdf_url: string;
    daftar_ruang: { id: number; kode: string; nama: string | null; terisi: number; kapasitas: number }[];
    ruang: Ruang | null;
};

export default function EventDenah({ event, tablo_url, denah_url, pdf_url, daftar_ruang, ruang }: Props) {
    const jalur = denah_url;

    const kosong = ruang ? ruang.sel.filter((s) => s.peserta === null).length : 0;
    const jumlahMeja = ruang ? Math.ceil(Math.max(1, ruang.kapasitas) / ruang.kursi_per_meja) : 0;

    return (
        <AdminLayout
            judul="Denah tempat duduk"
            breadcrumb={`/admin/events · ${event.kode} · Penempatan · Denah`}
            aksi={
                <span className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink-2">
                    <span className="text-ink-3">Event:</span>
                    <span className="font-medium text-ink">{event.nama}</span>
                </span>
            }
        >
            <Head title={`Denah kursi · ${event.nama}`} />

            <div className="mb-3 flex flex-wrap items-center gap-2">
                {daftar_ruang.length > 0 && (
                    <label className="flex items-center gap-2 text-xs text-ink-2">
                        <span className="text-ink-3">Ruang</span>
                        <select
                            className="rounded-wf border border-line bg-surface px-2 py-1 text-xs text-ink"
                            value={ruang?.id ?? ''}
                            onChange={(e) => pilihRuang(jalur, Number(e.target.value))}
                        >
                            {ruang === null && <option value="">— pilih ruang —</option>}
                            {daftar_ruang.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.kode} · {r.terisi}/{r.kapasitas}
                                </option>
                            ))}
                        </select>
                    </label>
                )}

                <span className="ml-auto flex flex-wrap items-center gap-2">
                    {/* Dua berkas cetak, dan yang membedakannya tugasnya:
                        tablo adalah daftar hadir yang DITANDATANGANI
                        pengawas, denah adalah gambar meja yang DITEMPPEL di
                        dalam ruang. Keduanya membawa nomor kursi yang sama -
                        yang berbeda hanya apa yang dicari pembacanya. */}
                    <TombolTautan
                        href={ruang ? `${pdf_url}?ruang=${ruang.id}` : pdf_url}
                        title="Denah tempat duduk PDF untuk ditempel di meja"
                    >
                        Unduh denah PDF
                    </TombolTautan>
                    <TombolTautan
                        href={ruang ? `${tablo_url}?ruang=${ruang.id}` : tablo_url}
                        title="Daftar hadir PDF untuk ruang ini"
                    >
                        Cetak tablo
                    </TombolTautan>
                </span>
            </div>

            {ruang === null && (
                <Kotak>
                    <p className="py-6 text-center text-sm text-ink-3">
                        {daftar_ruang.length === 0
                            ? 'Event ini belum punya ruang. Susun jadwalnya di laman Jadwal & sesi terlebih dahulu.'
                            : 'Pilih satu ruang untuk melihat denahnya.'}
                    </p>
                </Kotak>
            )}

            {ruang !== null && (
                <>
                    <div className="mb-3 grid gap-3 sm:grid-cols-4">
                        <KPI label="Meja" nilai={jumlahMeja} />
                        <KPI label="Kursi" nilai={ruang.kapasitas} />
                        <KPI label="Terisi" nilai={ruang.terisi} />
                        <KPI label="Kosong" nilai={kosong} nada={kosong > 0 ? 'warn' : undefined} />
                    </div>

                    <Kotak
                        judul={`${ruang.kode}${ruang.nama ? ` · ${ruang.nama}` : ''}`}
                        aksi={
                            <>
                                <Chip nada="info">
                                    {ruang.meja_per_baris} kolom × {Math.ceil(jumlahMeja / ruang.meja_per_baris)}{' '}
                                    baris
                                </Chip>
                                <Chip nada="mute">{ruang.kursi_per_meja} kursi per meja</Chip>
                            </>
                        }
                    >
                        {ruang.terisi === 0 && (
                            <div className="mb-3">
                                <Inbox>
                                    Belum ada peserta yang ditempatkan di ruang ini. Kursinya
                                    dihitung dari kocokan event &mdash; kocok dulu di laman{' '}
                                    <span className="font-medium">Penempatan</span>.
                                </Inbox>
                            </div>
                        )}

                        <PapanTulis />

                        <div className="space-y-1.5">
                            {kelompokMeja(ruang.sel, ruang.meja_per_baris).map((baris, i) => (
                                <div
                                    key={i}
                                    className="grid gap-1.5"
                                    style={{
                                        gridTemplateColumns: `repeat(${ruang.meja_per_baris}, minmax(0, 1fr))`,
                                    }}
                                >
                                    {baris.map((meja) => (
                                        <Meja key={meja[0]?.meja ?? i} sel={meja} />
                                    ))}
                                </div>
                            ))}
                        </div>

                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            <span className="text-[10px] uppercase tracking-wide text-ink-3">
                                Keterangan
                            </span>
                            <Chip nada="ok">kursi terisi</Chip>
                            <Chip nada="mute">kursi kosong</Chip>
                            <Chip nada="info">
                                {ruang.meja_per_baris} kolom · {Math.ceil(jumlahMeja / ruang.meja_per_baris)}{' '}
                                baris
                            </Chip>
                            <span className="ml-auto font-mono text-[10px] uppercase tracking-wide text-ink-3">
                                Pintu / arah masuk
                            </span>
                        </div>
                    </Kotak>

                    <div className="mt-3">
                        <Inbox>
                            Nomor kursi di denah ini yang dicetak dan ditempel di tiap meja, dan
                            nomor yang sama yang dibaca pengawas di daftar presensi. Tidak ada nomor
                            kedua: ruang tidak pernah memakai penomoran berbeda antara layar dan
                            kertas.
                        </Inbox>
                    </div>

                    <div className="mt-3">
                        <Kotak judul="Catatan pembacaan denah ini">
                            <div className="space-y-2 text-xs leading-relaxed text-ink-2">
                                <p>
                                    Denah ini menggambar kursi menurut <em>kocokan benih</em>{' '}
                                    eventnya &mdash; benih yang sama yang dipakai laman
                                    Penempatan, sehingga angka di sini tidak berbeda dari
                                    pratinjau di sana. Ia tidak mengocok ulang dan tidak bisa
                                    mengubah penempatan. Urutannya mengikuti abjad nama
                                    peserta di ruang itu, sehingga nomor yang sama dibaca
                                    pengawas di papan presensi.
                                </p>
                                <p className="text-warn">
                                    Selama penyebaran belum selesai &mdash; mis. masih ada
                                    ujian yang belum punya sesi &mdash; kursi di lembar ini
                                    bisa berbeda dari baris yang sudah tersimpan di
                                    <em> exam_participants</em>. Yang digambar adalah susunan
                                    yang <strong>akan berlaku</strong>, dan itulah yang perlu
                                    ditempel di meja.
                                </p>
                                <p>
                                    Jumlah mejanya dihitung dari <em>kapasitas</em> ruang, bukan
                                    dipatok dua puluh: ruang 32 kursi mendapat 16 meja. Kursi yang
                                    belum terisi tetap digambar, karena ruangan yang tampak penuh
                                    membuat pengawas menyusuri meja yang sebenarnya kosong.
                                </p>
                                <p className="text-warn">
                                    Nama dan NIS ada di dalam kotak karena lembar ini untuk
                                    pengawas di dalam ruangan, bukan untuk ditempel di koridor.
                                    Denah bernama tidak boleh dipasang di daun pintu bersama kartu
                                    QR &mdash; halaman pintu sengaja tidak memuat satu pun nama
                                    anak.
                                </p>
                            </div>
                        </Kotak>
                    </div>
                </>
            )}
        </AdminLayout>
    );
}

/** Bar papan tulis - penanda arah depan ruangan, sama dengan wireframe. */
function PapanTulis() {
    return (
        <div className="mb-2 rounded-wf border border-line bg-surface-2 py-1.5 text-center font-mono text-[10px] uppercase tracking-[0.14em] text-ink-3">
            Papan tulis
        </div>
    );
}

/**
 * Satu meja, berisi `kursi_per_meja` kursi.
 *
 * Meja yang kursinya seluruhnya kosong tetap digambar dengan bingkai yang
 * sama; yang membedakannya hanya isi kotaknya. Bingkai yang hilang untuk meja
 * kosong akan membuat denah tampak seperti ruangan yang lebih kecil.
 */
function Meja({ sel }: { sel: Sel[] }) {
    return (
        <div className="grid grid-cols-2 gap-1 rounded-wf border border-line bg-surface-2 p-1">
            {sel.map((s) => (
                <div
                    key={s.kursi}
                    className={
                        'min-w-0 rounded-[4px] border px-1.5 py-1 ' +
                        (s.peserta === null
                            ? 'border-dashed border-line/70 bg-surface'
                            : 'border-line/70 bg-surface')
                    }
                    title={s.peserta ? `${s.peserta.nama} · kursi ${s.kursi}` : `kursi ${s.kursi} kosong`}
                >
                    <p className="font-mono text-[10px] text-ink-3">
                        {String(s.kursi).padStart(2, '0')}
                    </p>
                    {s.peserta === null ? (
                        <p className="truncate text-[11px] text-ink-3">&mdash;</p>
                    ) : (
                        <>
                            <p className="truncate text-[11px] text-ink">{s.peserta.nama}</p>
                            <p className="truncate font-mono text-[10px] text-ink-3">
                                {s.peserta.kelas ?? '—'}
                                {s.peserta.nis ? ` · ${s.peserta.nis}` : ''}
                            </p>
                        </>
                    )}
                </div>
            ))}
        </div>
    );
}

/**
 * Kelompokkan sel menjadi baris meja.
 *
 * Pengelompokannya mengikuti `baris` yang dihitung server, bukan pembagian
 * array di sini - sehingga meja yang nomornya melompat (kapasitas yang
 * berubah sesudah penomoran) tetap diletakkan di baris yang benar.
 */
function kelompokMeja(sel: Sel[], perBaris: number): Sel[][][] {
    const meja = new Map<number, Sel[]>();

    for (const s of sel) {
        const isi = meja.get(s.meja) ?? [];
        isi.push(s);
        meja.set(s.meja, isi);
    }

    const baris: Sel[][][] = [];

    for (const [nomor, isi] of [...meja.entries()].sort((a, b) => a[0] - b[0])) {
        const indeks = Math.floor((nomor - 1) / perBaris);
        baris[indeks] = baris[indeks] ?? [];
        baris[indeks].push(isi);
    }

    return baris.map((b) => b ?? []);
}

function KPI({ label, nilai, nada }: { label: string; nilai: number; nada?: 'warn' | 'bad' }) {
    const warna = nada === 'warn' ? 'text-warn' : nada === 'bad' ? 'text-bad' : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className={'mt-1 font-mono text-2xl font-medium ' + warna}>
                {nilai.toLocaleString('id-ID')}
            </p>
        </div>
    );
}

/* --------------------------------------------------------------- Aksi */

function pilihRuang(jalur: string, ruangId: number) {
    router.get(jalur, { ruang: ruangId }, { preserveScroll: true, preserveState: true });
}
