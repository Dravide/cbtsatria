import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DaftarMapel, { MapelEvent } from '@/Components/DaftarMapelEvent';
import { Chip, Inbox, Kotak } from '@/Components/ui';

/**
 * Papan keadaan sesi lintas ruang, dari konsol Admin.
 *
 * =====================================================================
 * Barisnya RUANG; daftar anaknya punya laman sendiri
 * =====================================================================
 * Laman ini menjawab "ruang mana yang tertinggal", dan itu pertanyaan yang
 * dijawab rekap - bukan daftar nama.
 *
 * Daftar nama itu sendiri tetap ada, karena ada pertanyaan yang tidak bisa
 * dijawab rekap: "anak ini sudah sampai nomor berapa". Rekap menjawab berapa
 * BANYAK yang sudah dikerjakan; ia tidak menjawab soal MANA yang sedang
 * dibuka. Untuk anak yang melompat ke nomor 40 lalu kembali ke nomor 3,
 * keduanya berbeda jauh - dan laporan "ujian saya macet di soal 12" tidak
 * bisa ditelusuri dari kolom Mengerjakan.
 *
 * Yang berubah 2026-11-09: daftarnya tidak lagi dibuka DI DALAM kartu ruang
 * di laman ini, melainkan di laman sendiri yang dibuka di tab baru. Tiga hal
 * yang sekaligus salah dengan bentuk yang lama:
 *
 *   1. Papan ini disegarkan terus selama ujian berjalan. Membawa daftar
 *      seribu anak di payload-nya berarti setiap penyegaran mengirim ulang
 *      daftar yang belum tentu dibuka.
 *   2. Dua puluh ruang yang daftarnya bisa dibuka di satu halaman
 *      menghasilkan halaman yang tidak lagi bisa dipindai - dan kemampuan
 *      memindainya itulah gunanya papan ini.
 *   3. Admin yang menelusuri satu anak sedang mengerjakan satu pekerjaan.
 *      Tab tersendiri membuatnya tidak hilang saat ia kembali ke rekap, dan
 *      tautannya bisa dikirim ke panitia lain apa adanya.
 *
 * Ia tetap TAB BARU, bukan navigasi Inertia biasa: kembali ke rekap harus
 * mengembalikan posisi gulir dan keadaan saringannya, dan itu hanya gratis
 * bila laman rekapnya tidak pernah ditinggalkan.
 *
 * ---------------------------------------------------------------------
 * Nomor soal datang dari denyut anak, dan itu berarti ia bisa tertinggal
 * ---------------------------------------------------------------------
 * Nomor yang tampil di laman rincian adalah nomor yang dilaporkan peramban
 * anak pada denyut terakhirnya - tiap tiga puluh detik. Anak yang baru
 * berpindah soal masih tampil di nomor lamanya sampai denyut berikutnya, dan
 * itu memang keadaannya: angka yang lebih baru daripada denyutnya tidak ada.
 *
 * Karena itu kolom jam denyutnya ikut ditampilkan di sana. Angka "sampai
 * nomor 12" yang disertai "07:41:20" bisa dinilai sendiri oleh Admin; angka
 * telanjang tidak bisa.
 *
 * ---------------------------------------------------------------------
 * Kolom TERPUTUS dihitung dari `last_seen_at`, bukan dari kolom tersendiri
 * ---------------------------------------------------------------------
 * "Terputus" bukan keadaan yang disimpan; ia keadaan yang DISIMPULKAN dari
 * selisih waktu - 90 detik tanpa kabar. Menyimpannya sebagai kolom berarti
 * kolom yang harus diperbarui oleh sesuatu yang mungkin tidak berjalan
 * justru saat jaringan putus.
 *
 * ---------------------------------------------------------------------
 * Yang TIDAK ada di laman ini: koreksi
 * ---------------------------------------------------------------------
 * Admin mengoreksi PRESENSI, dan itu ada di laman Presensi. Papan ini tidak
 * mengubah apa pun - ia hanya menjawab "bagaimana keadaan sesi sekarang".
 * Menaruh tombol yang mengubah data di papan yang sedang dibaca sambil
 * memantau berarti mengambil keputusan dari ringkasan, bukan dari orangnya.
 */
type Baris = {
    ruang_id: number;
    ruang: string;
    ruang_nama: string | null;
    sesi: string | null;
    ujian: string | null;
    status_sesi: string | null;
    status_label: string | null;
    peserta: number;
    mengerjakan: number;
    selesai: number;
    terputus: number;
    pelanggaran: number;
    terkunci: number;
    insiden: number;
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    ruang_url: string;
    baris: Baris[];

    /**
     * Seluruh mapel event, termasuk yang belum dijadwalkan - lihat catatan
     * `DaftarMapelEvent`. `baris` hanya memuat mapel yang sudah punya sesi.
     */
    mapel: MapelEvent[];
    angka: {
        ruang: number;
        peserta: number;
        mengerjakan: number;
        selesai: number;
        terputus: number;
        pelanggaran: number;
        terkunci: number;
    };
};

export default function EventMonitoring({ event, ruang_url, baris, mapel, angka }: Props) {
    const terkunci = baris.filter((b) => b.terkunci > 0);
    const terisi = baris.filter((b) => b.peserta > 0);
    const adaAttempt = angka.mengerjakan > 0 || angka.selesai > 0;

    return (
        <AdminLayout
            judul="Monitoring"
            breadcrumb={`/admin/events · ${event.kode} · Monitoring`}
            aksi={
                <span className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink-2">
                    <span className="text-ink-3">Event:</span>
                    <span className="font-medium text-ink">{event.nama}</span>
                </span>
            }
        >
            <Head title={`Monitoring · ${event.nama}`} />

            {/* Mapel lebih dulu, dan alasannya sama dengan laman Presensi:
                papan di bawah disusun dari ruang-sesi, sehingga mapel yang
                jadwalnya belum dibuat tidak muncul satu kali pun. Daftar ini
                yang membuat "belum ada yang mengerjakan" bisa dibedakan dari
                "belum ada yang dijadwalkan". */}
            <div className="mb-3">
                <DaftarMapel mapel={mapel} denganPresensi={false} />
            </div>

            <div className="mb-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-7">
                <KPI label="Ruang-sesi" nilai={angka.ruang} />
                <KPI label="Peserta" nilai={angka.peserta} />
                <KPI label="Mengerjakan" nilai={angka.mengerjakan} />
                <KPI label="Selesai" nilai={angka.selesai} nada="ok" />
                <KPI
                    label="Terputus"
                    nilai={angka.terputus}
                    nada={angka.terputus > 0 ? 'bad' : undefined}
                />
                <KPI
                    label="Terkunci"
                    nilai={angka.terkunci}
                    nada={angka.terkunci > 0 ? 'bad' : undefined}
                />
                <KPI
                    label="Pelanggaran"
                    nilai={angka.pelanggaran}
                    nada={angka.pelanggaran > 0 ? 'bad' : undefined}
                />
            </div>

            {!adaAttempt && (
                <div className="mb-3">
                    <Inbox>
                        Belum ada satu pun pengerjaan tercatat di event ini. Angka
                        pengerjaan baru terisi setelah mesin ujian menulisnya; yang bisa
                        dilihat sekarang adalah kesiapan dan insidennya.
                    </Inbox>
                </div>
            )}

            {terkunci.length > 0 && (
                <div className="mb-3">
                    <Inbox>
                        Ada peserta terkunci di{' '}
                        <span className="font-mono">
                            {terkunci.map((b) => b.ruang).join(', ')}
                        </span>
                        . Pembukaan kunci dilakukan pengawas ruangnya setelah berbicara
                        dengan yang bersangkutan.
                    </Inbox>
                </div>
            )}

            {terisi.length > 0 && (
                <div className="mb-3">
                    <Inbox>
                        Nama satu per satu tidak ada di papan ini &mdash; ia ada di{' '}
                        <span className="font-medium">Lihat peserta</span> tiap ruang, yang
                        terbuka di tab baru. Papan ini untuk memindai ruang mana yang
                        tertinggal; daftar anak untuk menelusuri satu orang.
                    </Inbox>
                </div>
            )}

            <div className="space-y-3">
                {baris.length === 0 && (
                    <Kotak>
                        <p className="py-6 text-center text-sm text-ink-3">
                            Event ini belum punya sesi yang menempatkan peserta di ruang
                            mana pun.
                        </p>
                    </Kotak>
                )}

                {baris.map((b) => (
                    <Kotak
                        key={b.ruang_id}
                        judul={`${b.ruang} · ${b.ruang_nama ?? 'Ruang'}`}
                        aksi={
                            <>
                                <Chip nada={b.sesi ? 'info' : 'mute'}>
                                    {b.sesi ?? 'tanpa sesi'}
                                </Chip>
                                {b.status_label && (
                                    <Chip nada={b.status_sesi === 'berjalan' ? 'info' : 'mute'}>
                                        {b.status_label}
                                    </Chip>
                                )}
                                {b.terputus > 0 && <Chip nada="bad">{b.terputus} terputus</Chip>}
                                {b.terkunci > 0 && <Chip nada="bad">{b.terkunci} terkunci</Chip>}
                                {b.pelanggaran > 0 && (
                                    <Chip nada="warn">{b.pelanggaran} pelanggaran</Chip>
                                )}
                                <Link
                                    href={`${ruang_url}/${b.ruang_id}`}
                                    target="_blank"
                                    rel="noopener"
                                    className={
                                        'inline-flex items-center justify-center gap-1.5 rounded-wf border px-3 py-1.5 text-xs font-medium ' +
                                        'transition-colors duration-150 active:translate-y-px ' +
                                        'border-line bg-surface text-ink hover:bg-surface-2'
                                    }
                                >
                                    Lihat peserta
                                    <span aria-hidden="true" className="text-ink-3">
                                        &#8599;
                                    </span>
                                </Link>
                            </>
                        }
                    >
                        <div className="grid gap-2 text-xs text-ink-2 sm:grid-cols-2">
                            <p>{b.ujian ?? 'Ujian belum terpasang di sesi ini'}</p>
                            <p className="sm:text-right">
                                {b.insiden > 0 ? (
                                    <span className="text-warn">
                                        {b.insiden} insiden belum ditangani di ruang ini.
                                    </span>
                                ) : (
                                    <span className="text-ink-3">Tidak ada insiden terbuka.</span>
                                )}
                            </p>
                        </div>

                        <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-6">
                            <Angka label="Peserta" nilai={b.peserta} />
                            <Angka label="Mengerjakan" nilai={b.mengerjakan} />
                            <Angka label="Selesai" nilai={b.selesai} nada="ok" />
                            <Angka
                                label="Terputus"
                                nilai={b.terputus}
                                nada={b.terputus > 0 ? 'bad' : undefined}
                            />
                            <Angka
                                label="Terkunci"
                                nilai={b.terkunci}
                                nada={b.terkunci > 0 ? 'bad' : undefined}
                            />
                            <Angka
                                label="Pelanggaran"
                                nilai={b.pelanggaran}
                                nada={b.pelanggaran > 0 ? 'warn' : undefined}
                            />
                        </div>
                    </Kotak>
                ))}
            </div>

            <div className="mt-3">
                <Kotak judul="Catatan pembacaan papan ini">
                    <div className="space-y-2 text-xs leading-relaxed text-ink-2">
                        <p>
                            &ldquo;Terputus&rdquo; bukan keadaan yang disimpan, melainkan
                            yang disimpulkan: peserta yang tidak mengirim kabar lebih dari
                            90 detik. Karena itu angkanya bisa naik turun sendiri tanpa ada
                            yang mengubah apa pun &mdash; dan itu memang keadaannya.
                        </p>
                        <p>
                            &ldquo;Sampai nomor&rdquo; ada di laman rincian tiap ruang,
                            bukan di sini. Angka itu berasal dari denyut anak, yang
                            berjalan tiap tiga puluh detik; anak yang baru berpindah soal
                            masih tampil di nomor lamanya sampai denyut berikutnya, dan jam
                            di sebelahnya menyebutkan denyut mana yang dibaca. Nomor itu
                            dilaporkan peramban siswa, jadi ia isyarat, bukan bukti; jumlah
                            jawaban yang benar-benar tersimpan ada di kolom Progres, dan
                            itu dihitung server.
                        </p>
                    </div>
                </Kotak>
            </div>
        </AdminLayout>
    );
}

function KPI({ label, nilai, nada }: { label: string; nilai: number; nada?: 'ok' | 'bad' }) {
    const warna = nada === 'ok' ? 'text-ok' : nada === 'bad' ? 'text-bad' : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className={'mt-1 font-mono text-2xl font-medium ' + warna}>
                {nilai.toLocaleString('id-ID')}
            </p>
        </div>
    );
}

/**
 * Satu angka kecil berlabel.
 *
 * Dua bentuk nilai, karena keduanya memang berbeda jenis: `nilai` untuk
 * bilangan yang boleh nol, dan `teks` untuk yang sudah berbentuk kalimat -
 * "12 / 40 terjawab" tidak boleh lewat `toLocaleString`.
 *
 * `kosong` dipakai untuk membedakan "belum ada kabar" dari nol. Yang pertama
 * ditampilkan sebagai pisah, dan pemisahan itu disengaja.
 */
function Angka({
    label,
    nilai,
    teks,
    kosong = '0',
    nada,
}: {
    label: string;
    nilai?: number | null;
    teks?: string;
    kosong?: string;
    nada?: 'ok' | 'bad' | 'warn';
}) {
    const warna = nada === 'ok' ? 'text-ok' : nada === 'bad' ? 'text-bad' : nada === 'warn' ? 'text-warn' : 'text-ink';

    const isi =
        teks !== undefined
            ? teks
            : nilai === null || nilai === undefined
              ? kosong
              : nilai.toLocaleString('id-ID');

    return (
        <div className="rounded-wf border border-line/70 bg-surface-2 px-2.5 py-1.5">
            <p className="text-[10px] uppercase tracking-wide text-ink-3">{label}</p>
            <p className={'mt-0.5 font-mono text-sm ' + warna}>{isi}</p>
        </div>
    );
}
