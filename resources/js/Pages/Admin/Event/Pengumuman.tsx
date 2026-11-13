import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { konfirmasi } from '@/Components/dialog';
import { Chip, Field, Inbox, Kotak, Modal, Tombol, kelasInput } from '@/Components/ui';

/**
 * Papan pengumuman event - ditambahkan 2026-11-14.
 *
 * =====================================================================
 * Satu-satunya tulisan di konsol Admin yang dibaca SISWA
 * =====================================================================
 * Seluruh laman lain di kelompok Event berbicara TENTANG siswa - siapa
 * pesertanya, siapa pengawasnya, berapa nilainya. Laman ini menulis pesan
 * UNTUK mereka, dan itu mengubah dua hal:
 *
 *   1. Kalimatnya harus bisa dibaca anak SMP tanpa istilah admin. Petunjuk
 *      di bawah kolom isi menyebutkan itu, karena kolom tanpa petunjuk akan
 *      diisi "sesi 2 maju 15 menit sesuai berita acara panitia".
 *   2. Ia tayang SEGERA. Tidak ada tombol "terbitkan" yang terpisah dari
 *      simpan: pengumuman yang ditulis pada pagi hari ujian tidak punya
 *      waktu untuk melewati antrean.
 *
 * =====================================================================
 * Yang kedaluwarsa TETAP tampil, dengan alasannya
 * =====================================================================
 * Pengumuman yang sudah lewat tidak disembunyikan dari laman ini - justru
 * pada hari-H Admin perlu melihatnya untuk memperpanjang. Yang dibedakan
 * adalah chip-nya ("tayang" / "berakhir"), bukan keberadaannya.
 *
 * =====================================================================
 * `key` berisi id barisnya
 * =====================================================================
 * `Modal` mengembalikan `null` dari dalam dan TIDAK melepas anaknya, jadi
 * formulir di dalamnya hidup terus saat modalnya ditutup. Tanpa `key`,
 * membuka pengumuman kedua menampilkan kalimat yang diketik untuk yang
 * pertama - dan Admin yang tidak menyadarinya akan menerbitkan ulang pesan
 * lama di bawah judul baru.
 */
type Pengumuman = {
    id: number;
    judul: string;
    isi: string;
    nada: string;
    berlaku_sampai: string | null;
    berlaku_sampai_iso: string | null;
    tayang: boolean;
    penulis: string | null;
    dibuat: string | null;
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    pengumuman: Pengumuman[];
    angka: { tayang: number; berakhir: number };
};

export default function EventPengumuman({ event, pengumuman, angka }: Props) {
    // `null` = modal tertutup; `'baru'` = tambah; angka = ubah baris itu.
    const [sunting, setSunting] = useState<number | 'baru' | null>(null);

    const baris = sunting === 'baru' ? null : pengumuman.find((p) => p.id === sunting) ?? null;

    const hapus = async (p: Pengumuman) => {
        const ya = await konfirmasi({
            judul: `Hapus pengumuman "${p.judul}"?`,
            pesan: 'Pengumuman hilang dari beranda siswa. Kalimat yang sudah dibaca tidak bisa ditarik kembali.',
            labelYa: 'Hapus',
            labelTidak: 'Jangan',
            nada: 'bahaya',
        });

        if (!ya) {
            return;
        }

        router.delete(`/admin/events/${event.id}/pengumuman/${p.id}`, { preserveScroll: true });
    };

    return (
        <AdminLayout
            judul="Pengumuman"
            breadcrumb={`/admin/events · ${event.kode} · Pengumuman`}
            aksi={
                <>
                    <span className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink-2">
                        <span className="text-ink-3">Event:</span>
                        <span className="font-medium text-ink">{event.nama}</span>
                    </span>
                    <Tombol nada="pri" onClick={() => setSunting('baru')}>
                        Tulis pengumuman
                    </Tombol>
                </>
            }
        >
            <Head title={`Pengumuman · ${event.nama}`} />

            <div className="mb-3">
                <Inbox>
                    Pengumuman tampil di beranda siswa yang terdaftar pada event ini — di atas
                    jadwal ujian mereka, karena ia bisa membatalkan jadwal itu. Tidak ada
                    persetujuan atau terbitan terpisah: begitu disimpan, ia langsung tayang.
                </Inbox>
            </div>

            <div className="mb-3 grid gap-3 sm:grid-cols-2">
                <KPI label="Sedang tayang" nilai={angka.tayang} nada="ok" />
                <KPI label="Sudah berakhir" nilai={angka.berakhir} />
            </div>

            {pengumuman.length === 0 ? (
                <Kotak>
                    <p className="py-8 text-center text-sm text-ink-3">
                        Belum ada pengumuman untuk event ini. Siswa hanya melihat jadwal
                        ujiannya.
                    </p>
                </Kotak>
            ) : (
                <div className="space-y-2">
                    {pengumuman.map((p) => (
                        <article
                            key={p.id}
                            className={
                                'rounded-wf border bg-surface px-4 py-3 ' +
                                (p.tayang
                                    ? p.nada === 'penting'
                                        ? 'border-accent/40'
                                        : 'border-line'
                                    : 'border-line/60 opacity-70')
                            }
                        >
                            <div className="flex flex-wrap items-baseline gap-2">
                                <h2 className="font-display text-sm font-semibold text-ink">
                                    {p.judul}
                                </h2>
                                <Chip nada={p.tayang ? (p.nada === 'penting' ? 'warn' : 'ok') : 'mute'}>
                                    {p.tayang ? (p.nada === 'penting' ? 'penting' : 'tayang') : 'berakhir'}
                                </Chip>

                                <span className="ml-auto flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        onClick={() => setSunting(p.id)}
                                        className="rounded-wf border border-line bg-surface px-2 py-0.5 text-[11px] text-ink hover:bg-surface-2"
                                    >
                                        Ubah
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            void hapus(p);
                                        }}
                                        className="rounded-wf border border-line bg-surface px-2 py-0.5 text-[11px] text-bad hover:bg-bad-bg"
                                    >
                                        Hapus
                                    </button>
                                </span>
                            </div>

                            <p className="mt-1.5 whitespace-pre-wrap text-xs leading-relaxed text-ink-2">
                                {p.isi}
                            </p>

                            <p className="mt-2 text-[11px] text-ink-3">
                                {[
                                    p.penulis ? `oleh ${p.penulis}` : null,
                                    p.dibuat,
                                    p.berlaku_sampai ? `berlaku sampai ${p.berlaku_sampai}` : 'tanpa batas waktu',
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </article>
                    ))}
                </div>
            )}

            <Modal
                judul={baris ? `Ubah pengumuman · ${baris.judul}` : 'Pengumuman baru'}
                terbuka={sunting !== null}
                padaTutup={() => setSunting(null)}
            >
                {sunting !== null && (
                    <FormPengumuman
                        /* `key` wajib - lihat catatan kepala berkas. */
                        key={baris?.id ?? 'baru'}
                        eventId={event.id}
                        baris={baris}
                        padaSelesai={() => setSunting(null)}
                    />
                )}
            </Modal>
        </AdminLayout>
    );
}

function FormPengumuman({
    eventId,
    baris,
    padaSelesai,
}: {
    eventId: number;
    baris: Pengumuman | null;
    padaSelesai: () => void;
}) {
    const { data, setData, post, put, processing, errors, reset } = useForm({
        judul: baris?.judul ?? '',
        isi: baris?.isi ?? '',
        nada: baris?.nada ?? 'info',
        berlaku_sampai: baris?.berlaku_sampai_iso ?? '',
    });

    const kirim = (e: FormEvent) => {
        e.preventDefault();

        const opsi = {
            preserveScroll: true,
            onSuccess: () => {
                // Dikosongkan SEBELUM modal ditutup - alasan yang sama dengan
                // `TindakanPeserta` di MonitoringRuang.
                reset();
                padaSelesai();
            },
        };

        if (baris) {
            put(`/admin/events/${eventId}/pengumuman/${baris.id}`, opsi);
        } else {
            post(`/admin/events/${eventId}/pengumuman`, opsi);
        }
    };

    return (
        <form onSubmit={kirim} className="space-y-3">
            <Field label="Judul" galat={errors.judul}>
                <input
                    value={data.judul}
                    onChange={(e) => setData('judul', e.target.value)}
                    maxLength={120}
                    placeholder="Contoh: Sesi 2 mundur 15 menit"
                    className={kelasInput}
                />
            </Field>

            <Field
                label="Isi"
                galat={errors.isi}
                petunjuk="Tulis seperti berbicara kepada siswa: apa yang berubah, dan apa yang harus mereka lakukan."
            >
                <textarea
                    value={data.isi}
                    onChange={(e) => setData('isi', e.target.value)}
                    rows={4}
                    maxLength={1000}
                    placeholder="Contoh: Ujian Matematika sesi 2 dimulai pukul 10.15, bukan 10.00. Tetap menunggu di ruang masing-masing."
                    className={kelasInput + ' min-h-[96px] resize-y'}
                />
            </Field>

            <Field
                label="Nada"
                petunjuk="Penting memakai warna aksen dan dibaca lebih dulu. Pakai hanya bila siswa harus berbuat sesuatu."
            >
                <div className="flex gap-2">
                    {[
                        { nilai: 'info', label: 'Info' },
                        { nilai: 'penting', label: 'Penting' },
                    ].map((n) => (
                        <label
                            key={n.nilai}
                            className={
                                'flex min-h-11 flex-1 cursor-pointer items-center justify-center gap-2 rounded-wf border text-xs ' +
                                (data.nada === n.nilai
                                    ? 'border-accent bg-accent-soft font-medium text-accent'
                                    : 'border-line bg-surface text-ink-2 hover:bg-surface-2')
                            }
                        >
                            <input
                                type="radio"
                                name="nada"
                                value={n.nilai}
                                checked={data.nada === n.nilai}
                                onChange={() => setData('nada', n.nilai)}
                                className="sr-only"
                            />
                            {n.label}
                        </label>
                    ))}
                </div>
            </Field>

            <Field
                label="Berlaku sampai (boleh dikosongkan)"
                galat={errors.berlaku_sampai}
                petunjuk="Kosongkan bila pengumuman berlaku sepanjang event. Isi bila ia berhenti berlaku sendiri — mis. pengumuman listrik padam."
            >
                <input
                    type="datetime-local"
                    value={data.berlaku_sampai}
                    onChange={(e) => setData('berlaku_sampai', e.target.value)}
                    className={kelasInput}
                />
            </Field>

            <div className="flex justify-end gap-2 pt-1">
                <Tombol onClick={padaSelesai} disabled={processing}>
                    Batal
                </Tombol>
                <Tombol type="submit" nada="pri" disabled={processing}>
                    {processing ? 'Menyimpan…' : baris ? 'Simpan perubahan' : 'Terbitkan'}
                </Tombol>
            </div>
        </form>
    );
}

function KPI({ label, nilai, nada }: { label: string; nilai: number; nada?: 'ok' }) {
    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p
                className={
                    'mt-1 font-mono text-2xl font-medium ' + (nada === 'ok' ? 'text-ok' : 'text-ink')
                }
            >
                {nilai}
            </p>
        </div>
    );
}
