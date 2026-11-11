import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Inbox, Kotak, Tombol, TombolTautan, kelasInput } from '@/Components/ui';

/**
 * Presensi lintas ruang, dari konsol Admin - PRD §3.5.
 *
 * =====================================================================
 * Mengapa daftar kursi pengawas TIDAK diulang di sini
 * =====================================================================
 * Pertanyaan Admin dan pertanyaan pengawas bukan pertanyaan yang sama, dan
 * menyatukan keduanya menghasilkan laman yang menjawab keduanya dengan
 * buruk:
 *
 *   pengawas  "siapa di ruang SAYA yang belum saya tandai"   -> satu ruang
 *   Admin     "ruang mana yang pengawasnya belum selesai"    -> semua ruang
 *
 * Karena itu kolom utamanya adalah rekap: Admin tidak memanggil nama satu per
 * satu, ia mencari ruangan yang tertinggal.
 *
 * ---------------------------------------------------------------------
 * Barisnya RUANG EVENT - dan itu perbaikan 2026-11-10
 * ---------------------------------------------------------------------
 * Sebelumnya barisnya RUANG-SESI, dan akibatnya laman ini hanya memuat kelas 7:
 * setiap kartu dibangun dari ruang-sesinya, sehingga ujian yang belum
 * dijadwalkan tidak punya kartu sama sekali. Sekarang kartunya per ruang event
 * (33 kartu), penghuninya dari kocokan benih - sama dengan denah dan tablo.
 * Server tetap boleh mengirim bentuk lama untuk event yang belum pernah
 * dikocok, dan `punya_sesi` itulah yang membedakannya di layar.
 *
 * ---------------------------------------------------------------------
 * Admin boleh MENGOREKSI, dan koreksinya menuntut alasan SELALU
 * ---------------------------------------------------------------------
 * Plate 17 membebaskan pengawas dari alasan SELAMA sesi berjalan, dan
 * membebaskannya justru karena ia melihat orangnya berdiri di depan kursi.
 * Admin tidak melihat apa pun - yang sampai kepadanya adalah laporan bahwa
 * "anak saya sebenarnya hadir". Mewarisi keringanan itu akan menghasilkan
 * koreksi tanpa kalimat, tepat pada kasus yang paling perlu kalimat.
 *
 * Karena itu kolom alasan WAJIB di formnya, dan tombol simpannya mati
 * selama kolomnya kosong - bukan sekadar ditolak server.
 *
 * ---------------------------------------------------------------------
 * Yang TIDAK dilakukan Admin di sini: mengubah baris lama
 * ---------------------------------------------------------------------
 * Setiap koreksi menulis baris `attendance_logs` baru. Karena itu laman ini
 * tidak punya tombol hapus, dan tidak akan punya.
 */
/**
 * Satu baris di daftar anak - dan bentuknya berlaku untuk KEDUA jalur server.
 *
 * `presensiPerRuangSesi()` tidak mengisi `student_id`, `kursi`, maupun
 * `kelas`; `presensiPerRuangEvent()` mengisinya. Semuanya opsional di sini
 * supaya satu komponen bisa membaca dua bentuk yang memang sengaja berbeda -
 * dan yang menjaganya adalah test kedua jalur, bukan tipe TypeScript.
 *
 * `peserta_id` boleh null: siswa yang ujiannya belum ditarik belum punya
 * baris `exam_participants`, dan karena itu tidak punya penempatan untuk
 * ditunjuk. `record_id` null berarti belum ada baris presensi - lihat status
 * labelnya.
 */
type Peserta = {
    peserta_id: number | null;
    student_id?: number;
    kursi?: number;
    kelas?: string | null;
    record_id: number | null;
    nama: string;
    nis: string | null;
    status: string | null;
    status_label: string;
    status_nada: string;
    diverifikasi_oleh: string | null;
    diverifikasi_jam: string | null;
};

/**
 * Berita acara satu ruang-SESI, diringkas untuk kartu ruang event.
 *
 * `dibuka: false` berarti pengawasnya BELUM PERNAH membuka lamannya - dan
 * laman Admin ini sengaja tidak membuatkan draf untuk ruang itu, karena
 * `LaporanSesiService::untuk()` memakai `firstOrCreate` dan Admin yang
 * menyegarkan papan tiap lima menit akan membuat 33 draf kosong. Draf yang
 * dibuat hanya dengan dibuka berarti berhenti menjadi kabar "pengawas sudah
 * datang" - satu-satunya kabar yang diberikannya kepada Admin.
 *
 * `ruang_sesi_id` adalah kunci yang BENAR untuk berita acara; `baris` di
 * laman ini berkunci RUANG EVENT, dan satu ruang event bisa memuat beberapa
 * ruang-sesi. Karena itu bentuknya daftar.
 */
type BeritaAcara = {
    ruang_sesi_id: number;
    pengawas: string[];
    url: string;
    dibuka: boolean;
    final: boolean;
    dikirim_pada: string | null;
    foto: number;
    foto_sesudah: boolean;
    ttd_pengawas: boolean;
    ttd_oleh: string | null;
    kurang: string[];
    selesai: boolean;
};

type Baris = {
    ruang_id: number;
    ruang: string;
    ruang_nama: string | null;
    lokasi: string | null;
    sesi_id: number | null;
    sesi: string | null;
    ujian: string | null;
    jam: string | null;
    /** Jalur per-event hanya: apakah ruang ini sudah punya sesi ujian. */
    punya_sesi?: boolean;
    pengawas: string[];
    rekap: {
        total: number;
        hadir: number;
        terlambat: number;
        tidak_hadir: number;
        izin: number;
        pending: number;
    };
    insiden: number;
    berita_acara: BeritaAcara[];
    peserta: Peserta[];
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    baris: Baris[];
    angka: {
        ruang: number;
        belum_tuntas: number;
        hadir: number;
        peserta: number;
        insiden: number;
        berita_acara_belum: number;
    };
    opsi: Record<string, string>;
    tanpa_pengawas: string[];
};

export default function EventPresensi({ event, baris, angka, opsi, tanpa_pengawas }: Props) {
    // Ruang yang sedang dibuka daftar anaknya. Satu saja pada satu waktu:
    // laman ini dibuka untuk mencari ruangan yang tertinggal, dan membuka
    // dua daftar sekaligus membuat daftar yang dicari tergeser ke bawah.
    const [buka, setBuka] = useState<number | null>(null);

    return (
        <AdminLayout
            judul="Presensi"
            breadcrumb={`/admin/events · ${event.kode} · Presensi`}
            aksi={
                <span className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink-2">
                    <span className="text-ink-3">Event:</span>
                    <span className="font-medium text-ink">{event.nama}</span>
                </span>
            }
        >
            <Head title={`Presensi · ${event.nama}`} />

            <div className="mb-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                {/* "Ruang", bukan "Ruang-sesi": jalur per-ruang-event menghitung
                    ruangan event, dan label yang menyebut sesi akan membuat Admin
                    mencari kartu yang jumlahnya memang berbeda. */}
                <KPI label="Ruang" nilai={angka.ruang} />
                <KPI
                    label="Belum tuntas"
                    nilai={angka.belum_tuntas}
                    nada={angka.belum_tuntas > 0 ? 'bad' : 'ok'}
                />
                <KPI label="Hadir" nilai={angka.hadir} nada="ok" />
                <KPI label="Peserta" nilai={angka.peserta} />
                <KPI label="Insiden" nilai={angka.insiden} nada={angka.insiden > 0 ? 'bad' : undefined} />
                {/* Berita acara yang belum final. Angka ini yang membuat Admin
                    tahu harus mengejar pengawas ruang mana SEBELUM arsipnya
                    diminta - dan "belum dibuka" sengaja ikut terhitung, karena
                    ruang yang belum disentuh sama belum selesainya dengan ruang
                    yang fotonya kurang. */}
                <KPI
                    label="Berita acara belum"
                    nilai={angka.berita_acara_belum}
                    nada={angka.berita_acara_belum > 0 ? 'bad' : 'ok'}
                />
            </div>

            {tanpa_pengawas.length > 0 && (
                <div className="mb-3">
                    <Inbox>
                        {tanpa_pengawas.length} ruang belum punya pengawas:{' '}
                        <span className="font-mono">{tanpa_pengawas.join(', ')}</span>. Presensi
                        ruang itu tidak akan terisi selama tidak ada yang membukanya.
                    </Inbox>
                </div>
            )}

            <div className="space-y-3">
                {baris.length === 0 && (
                    <Kotak>
                        <p className="py-6 text-center text-sm text-ink-3">
                            Event ini belum punya sesi yang menempatkan peserta di ruang mana pun.
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
                                    {b.sesi ?? 'belum dijadwalkan'}
                                </Chip>
                                {b.rekap.pending > 0 ? (
                                    <Chip nada="warn">{b.rekap.pending} belum</Chip>
                                ) : (
                                    <Chip nada="ok">tuntas</Chip>
                                )}
                                {/* Chip berita acara di KEPALA kartu, bukan hanya
                                    di badannya: Admin memindai kepala-kepala
                                    kartu untuk mencari ruang yang tertinggal,
                                    dan dokumen yang belum selesai adalah
                                    bagian dari "tertinggal" itu. Dihitung dari
                                    baris yang sama dengan blok di bawahnya -
                                    dua hitungan atas data yang sama adalah dua
                                    angka yang bisa berbeda diam-diam. */}
                                <Chip
                                    nada={
                                        b.berita_acara.length === 0
                                            ? 'mute'
                                            : b.berita_acara.every((l) => l.selesai)
                                              ? 'ok'
                                              : 'warn'
                                    }
                                >
                                    {b.berita_acara.length === 0
                                        ? 'tanpa berita acara'
                                        : b.berita_acara.every((l) => l.selesai)
                                          ? 'berita acara final'
                                          : `berita acara ${b.berita_acara.filter((l) => l.selesai).length}/${b.berita_acara.length}`}
                                </Chip>
                                <Tombol onClick={() => setBuka(buka === b.ruang_id ? null : b.ruang_id)}>
                                    {buka === b.ruang_id ? 'Tutup daftar' : 'Koreksi presensi'}
                                </Tombol>
                            </>
                        }
                    >
                        <div className="grid gap-2 text-xs text-ink-2 sm:grid-cols-2">
                            <p>
                                {[b.ujian, b.lokasi, b.jam].filter(Boolean).join(' · ') ||
                                    (b.punya_sesi === false
                                        ? 'Ujian ruang ini belum punya sesi — jadwalkan di laman Jadwal & sesi.'
                                        : '—')}
                            </p>
                            <p className="sm:text-right">
                                Pengawas:{' '}
                                {b.pengawas.length > 0 ? (
                                    b.pengawas.join(', ')
                                ) : (
                                    <span className="text-warn">belum ditetapkan</span>
                                )}
                            </p>
                        </div>

                        <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-6">
                            <Angka label="Peserta" nilai={b.rekap.total} />
                            <Angka label="Hadir" nilai={b.rekap.hadir} nada="ok" />
                            <Angka label="Terlambat" nilai={b.rekap.terlambat} />
                            <Angka label="Tidak hadir" nilai={b.rekap.tidak_hadir} />
                            <Angka label="Izin" nilai={b.rekap.izin} />
                            <Angka label="Belum" nilai={b.rekap.pending} nada={b.rekap.pending > 0 ? 'bad' : undefined} />
                        </div>

                        {b.insiden > 0 && (
                            <p className="mt-2 text-xs text-warn">
                                {b.insiden} insiden belum ditangani di ruang ini.
                            </p>
                        )}

                        <BlokBeritaAcara ruang={b.ruang} laporan={b.berita_acara} />

                        {buka === b.ruang_id && (
                            <div className="mt-4 border-t border-line pt-3">
                                <DaftarPeserta
                                    eventId={event.id}
                                    ruang={b.ruang}
                                    peserta={b.peserta}
                                    opsi={opsi}
                                />
                            </div>
                        )}
                    </Kotak>
                ))}
            </div>
        </AdminLayout>
    );
}

/**
 * Berita acara satu kartu ruang - ringkasannya, dan pintu ke dokumennya.
 *
 * =====================================================================
 * Admin MEMBACA, dan karena itu tidak ada satu pun tombol yang menulis
 * =====================================================================
 * Yang ditawarkan di sini hanya "Buka berita acara": tautan ke laman dokumen
 * di tab baru, yang memang boleh dibuka Admin. Menandatangani dan mengirim
 * tetap milik pengawas ruangnya - `AttendancePolicy::tandatangani()`,
 * `BeritaAcaraController::tandaTangan()`, dan prop `boleh_ttd` menegakkannya
 * di tiga tempat, dan laman ini tidak boleh menjadi tempat keempat yang
 * menawarkan tombol yang pasti ditolak.
 *
 * =====================================================================
 * Yang BELUM dibuka tetap digambar sebagai baris, bukan disembunyikan
 * =====================================================================
 * Ruang yang pengawasnya belum menyentuh berita acaranya adalah ruang yang
 * paling perlu dilihat Admin. Menghilangkan barisnya membuat kartu yang
 * bersih terbaca "tidak ada yang perlu dikerjakan" untuk ruang yang justru
 * paling tertinggal.
 *
 * =====================================================================
 * Satu ruang event bisa punya BEBERAPA berita acara
 * =====================================================================
 * Berita acara melekat pada ruang-SESI, dan satu ruang event dipakai
 * beberapa sesi (pagi dan siang, atau dua mapel). Karena itu daftarnya
 * ditampilkan apa adanya, dengan jam sesinya - dua tanda tangan atas dua
 * sesi adalah dua dokumen yang berbeda, bukan satu dokumen dua kali.
 */
function BlokBeritaAcara({ ruang, laporan }: { ruang: string; laporan: BeritaAcara[] }) {
    if (laporan.length === 0) {
        return (
            <p className="mt-3 border-t border-line/60 pt-2.5 text-[11px] text-ink-3">
                Ruang {ruang} belum punya sesi, sehingga belum ada berita acara yang bisa
                dibuat untuknya.
            </p>
        );
    }

    return (
        <div className="mt-3 border-t border-line/60 pt-2.5">
            <p className="mb-1.5 text-[11px] text-ink-3">
                Berita acara ruang-sesi. Admin membaca, mencetak, dan mengunduhnya; tanda
                tangan tetap milik pengawas ruangnya.
            </p>

            <div className="space-y-1.5">
                {laporan.map((l) => (
                    <div
                        key={l.ruang_sesi_id}
                        className="rounded-wf border border-line/70 bg-surface px-2.5 py-2"
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-xs text-ink">
                                {l.dibuka ? 'Berita acara' : 'Belum dibuka'}
                            </span>

                            {l.dibuka && (
                                <>
                                    {l.selesai ? (
                                        <Chip nada="ok">final</Chip>
                                    ) : (
                                        <Chip nada="warn">draf</Chip>
                                    )}
                                    <Chip nada={l.foto_sesudah ? 'ok' : 'mute'}>
                                        {l.foto} foto
                                    </Chip>
                                    <Chip nada={l.ttd_pengawas ? 'ok' : 'mute'}>
                                        {l.ttd_pengawas ? 'tanda tangan pengawas' : 'belum ditandatangani'}
                                    </Chip>
                                </>
                            )}

                            {l.dikirim_pada && (
                                <span className="text-[11px] text-ink-3">
                                    dikirim {l.dikirim_pada}
                                </span>
                            )}

                            <span className="ml-auto">
                                <TombolTautan
                                    href={l.url}
                                    title={`Berita acara ruang ${ruang} · ${l.pengawas.join(', ') || 'tanpa pengawas'}`}
                                >
                                    Buka berita acara
                                </TombolTautan>
                            </span>
                        </div>

                        {l.dibuka && !l.selesai && l.kurang.length > 0 && (
                            <p className="mt-1 text-[11px] leading-relaxed text-warn">
                                Kurang: {l.kurang.join(' ')}
                            </p>
                        )}

                        {/* Ruang yang belum dibuka TIDAK diberi keterangan
                            "kurang": tidak ada yang kurang pada dokumen yang
                            belum ada, dan menyebutkannya terbaca sebagai
                            pengawas yang lalai alih-alih sesi yang belum
                            berjalan.

                            Yang disebutkan justru konsekuensi menekan
                            tautannya: lamannya MEMBUAT draf begitu dibuka -
                            lihat `LaporanSesiService::untuk()`. Admin yang
                            membukanya untuk memeriksa menghapus kabar
                            "pengawas belum datang" untuk ruang itu, dan ia
                            perlu tahu itu SEBELUM menekannya. */}
                        {!l.dibuka && (
                            <p className="mt-1 text-[11px] leading-relaxed text-ink-3">
                                Pengawasnya belum membuka laman ini. Membukanya membuat draf
                                kosong, dan kabar &ldquo;belum dibuka&rdquo; hilang untuk
                                ruangan ini.
                            </p>
                        )}

                        {l.ttd_oleh && (
                            <p className="mt-1 text-[11px] text-ink-3">
                                Ditandatangani {l.ttd_oleh}
                            </p>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}

/**
 * Daftar anak satu ruang, dengan kolom koreksinya.
 *
 * Baris yang belum punya `record_id` ditampilkan TANPA formnya, bukan
 * dengan form yang pasti gagal: koreksi bekerja atas baris presensi, dan
 * baris presensi dibuat saat penempatannya dibuat. Penempatan yang tidak
 * punya baris presensi berarti penempatannya dibuat sebelum tabel presensi
 * ada - dan itu keadaan yang harus disebutkan, bukan ditutupi tombol.
 */
function DaftarPeserta({
    eventId,
    ruang,
    peserta,
    opsi,
}: {
    eventId: number;
    ruang: string;
    peserta: Peserta[];
    opsi: Record<string, string>;
}) {
    if (peserta.length === 0) {
        return (
            <p className="py-4 text-center text-xs text-ink-3">
                Belum ada peserta yang ditempatkan di ruang ini.
            </p>
        );
    }

    return (
        <div className="space-y-2">
            <p className="text-[11px] text-ink-3">
                Koreksi atas ruang {ruang} selalu menuntut alasan tertulis. Baris lama tidak
                dihapus; koreksi ditulis sebagai perubahan baru.
            </p>

            {peserta.map((p) => (
                <BarisKoreksi
                    // `student_id` diutamakan: satu siswa bisa punya dua baris
                    // penempatan (satu per ujian) di ruang yang sama, dan
                    // `peserta_id` kosong pada siswa yang ujiannya belum
                    // ditarik. Kuncinya harus unik per ANAK.
                    key={p.student_id ?? p.peserta_id ?? p.nama}
                    eventId={eventId}
                    peserta={p}
                    opsi={opsi}
                />
            ))}
        </div>
    );
}

function BarisKoreksi({
    eventId,
    peserta,
    opsi,
}: {
    eventId: number;
    peserta: Peserta;
    opsi: Record<string, string>;
}) {
    const [terbuka, setTerbuka] = useState(false);
    const [status, setStatus] = useState(peserta.status ?? 'hadir');
    const [alasan, setAlasan] = useState('');
    const [kirim, setKirim] = useState(false);

    const simpan = () => {
        if (peserta.record_id === null || alasan.trim().length < 4) {
            return;
        }

        setKirim(true);
        router.post(
            `/admin/events/${eventId}/presensi/koreksi`,
            { record_id: peserta.record_id, status, alasan },
            {
                preserveScroll: true,
                onFinish: () => {
                    setKirim(false);
                    setTerbuka(false);
                    setAlasan('');
                },
            },
        );
    };

    return (
        <div className="rounded-wf border border-line/70 bg-surface px-3 py-2">
            <div className="flex flex-wrap items-center gap-2">
                {typeof peserta.kursi === 'number' && (
                    <span className="font-mono text-[11px] text-ink-3">
                        {String(peserta.kursi).padStart(2, '0')}
                    </span>
                )}
                <span className="text-sm text-ink">{peserta.nama}</span>
                {peserta.nis && (
                    <span className="font-mono text-[11px] text-ink-3">{peserta.nis}</span>
                )}
                {peserta.kelas && (
                    <span className="text-[11px] text-ink-3">{peserta.kelas}</span>
                )}

                <span className="ml-auto flex items-center gap-2">
                    <Chip nada={peserta.status_nada as 'mute'}>
                        {peserta.status_label === 'Belum ada sesi'
                            ? 'belum dijadwalkan'
                            : peserta.status_label}
                    </Chip>

                    {peserta.record_id === null ? (
                        <span className="text-[11px] text-warn">
                            {peserta.status_label === 'Belum ada sesi'
                                ? 'Ujiannya belum dijadwalkan'
                                : 'Baris presensinya belum ada'}
                        </span>
                    ) : (
                        <Tombol onClick={() => setTerbuka((t) => !t)}>
                            {terbuka ? 'Batal' : 'Ubah'}
                        </Tombol>
                    )}
                </span>
            </div>

            {peserta.diverifikasi_jam && (
                <p className="mt-0.5 text-[11px] text-ink-3">
                    Diverifikasi {peserta.diverifikasi_jam}
                    {peserta.diverifikasi_oleh ? ` oleh ${peserta.diverifikasi_oleh}` : ''}
                </p>
            )}

            {terbuka && peserta.record_id !== null && (
                <div className="mt-2 flex flex-wrap items-end gap-2 border-t border-line/60 pt-2">
                    <label className="block">
                        <span className="mb-1 block text-[11px] text-ink-3">Ubah menjadi</span>
                        <select
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                            className={kelasInput}
                        >
                            {Object.entries(opsi).map(([nilai, label]) => (
                                <option key={nilai} value={nilai}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="block min-w-[16rem] flex-1">
                        <span className="mb-1 block text-[11px] text-ink-3">
                            Alasan koreksi (wajib)
                        </span>
                        <textarea
                            value={alasan}
                            onChange={(e) => setAlasan(e.target.value)}
                            rows={2}
                            placeholder="Mis. surat keterangan dokter diterima 04 Nov."
                            className={kelasInput}
                        />
                    </label>

                    <Tombol
                        nada="pri"
                        onClick={simpan}
                        disabled={kirim || alasan.trim().length < 4}
                    >
                        Simpan koreksi
                    </Tombol>
                </div>
            )}
        </div>
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

function Angka({ label, nilai, nada }: { label: string; nilai: number; nada?: 'ok' | 'bad' }) {
    const warna = nada === 'ok' ? 'text-ok' : nada === 'bad' ? 'text-bad' : 'text-ink';

    return (
        <div className="rounded-wf border border-line/70 bg-surface-2/50 px-2.5 py-1.5">
            <p className="text-[10px] text-ink-3">{label}</p>
            <p className={'font-mono text-sm ' + warna}>{nilai}</p>
        </div>
    );
}
