import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

type KelasOpsi = {
    id: number;
    nama: string;
    tingkat: string | null;
};

type JadwalSiswa = {
    id: number;
    ujian: string | null;
    mapel: string | null;
    hari_tanggal: string | null;
    jam: string;
    sesi_label: string | null;
    ruang_kode: string | null;
    status: string | null;
    status_label: string | null;
    susulan: boolean;
};

type EventSiswa = {
    id: number;
    nama: string;
    status: string;
    status_label: string;
    ruang: {
        kode: string | null;
        nama: string | null;
        lokasi: string | null;
    } | null;
    kursi: number | null;
    meja: number | null;
    jadwal: JadwalSiswa[];
};

type HasilSiswa = {
    nama: string;
    nis: string | null;
    kelas: string;
    events: EventSiswa[];
};

type Props = {
    sekolah: string;
    slug: string;
    kelas: KelasOpsi[];
    hasil: HasilSiswa | null;
    diperbarui: string;
};

export default function Ruang({ sekolah, slug, kelas, hasil, diperbarui }: Props) {
    const page = usePage().props as unknown as {
        identitasAplikasi?: { nama: string; logo: string | null };
        flash?: { galat?: string | null; sukses?: string | null };
    };

    const form = useForm({
        kelas_id: kelas[0]?.id ? String(kelas[0].id) : '',
        nis: '',
    });

    const kirim = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/ruang/${slug}`, {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={`Cek ruang & kursi · ${sekolah}`} />

            <div className="laman-publik min-h-screen bg-paper">
                <div className="mx-auto flex min-h-screen w-full max-w-[30rem] flex-col sm:border-x sm:border-line">
                    <header className="flex flex-col gap-2 border-b border-line bg-surface px-4 py-3">
                        {page.identitasAplikasi?.logo && (
                            <div className="flex justify-center border-b border-line/60 pb-2">
                                <img
                                    src={page.identitasAplikasi.logo}
                                    alt={page.identitasAplikasi.nama || sekolah}
                                    className="h-5 w-auto max-w-[144px] object-contain"
                                />
                            </div>
                        )}
                        <div className="flex items-baseline gap-2">
                            <div className="min-w-0 flex-1">
                                <h1 className="truncate text-sm font-semibold text-ink">{sekolah}</h1>
                                <p className="mt-0.5 text-[11px] text-ink-3">
                                    Cek ruang ujian &amp; nomor kursi
                                </p>
                            </div>
                            <span className="shrink-0 font-mono text-[11px] text-ink-3">
                                {diperbarui}
                            </span>
                        </div>
                    </header>

                    <main className="flex-1 space-y-3 px-3 py-4">
                        {page.flash?.galat && (
                            <div className="rounded-wf border border-danger/30 bg-danger-bg px-3.5 py-2.5 text-xs text-danger">
                                {page.flash.galat}
                            </div>
                        )}

                        <form
                            onSubmit={kirim}
                            className="space-y-3 rounded-wf border border-line bg-surface p-3.5"
                        >
                            <div>
                                <label
                                    htmlFor="kelas_id"
                                    className="mb-1 block text-xs font-medium text-ink"
                                >
                                    Kelas
                                </label>
                                <select
                                    id="kelas_id"
                                    value={form.data.kelas_id}
                                    onChange={(e) => form.setData('kelas_id', e.target.value)}
                                    required
                                    className="w-full rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink outline-none focus:border-accent focus:ring-2 focus:ring-accent/15"
                                >
                                    <option value="" disabled>
                                        Pilih kelas...
                                    </option>
                                    {kelas.map((k) => (
                                        <option key={k.id} value={k.id}>
                                            {k.nama}
                                        </option>
                                    ))}
                                </select>
                                {form.errors.kelas_id && (
                                    <p className="mt-1 text-[11px] text-danger">
                                        {form.errors.kelas_id}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label
                                    htmlFor="nis"
                                    className="mb-1 block text-xs font-medium text-ink"
                                >
                                    NIS / NISN
                                </label>
                                <input
                                    id="nis"
                                    type="text"
                                    inputMode="numeric"
                                    value={form.data.nis}
                                    onChange={(e) => form.setData('nis', e.target.value)}
                                    placeholder="Masukkan NIS atau NISN Anda"
                                    required
                                    className="w-full rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink outline-none placeholder:text-ink-3 focus:border-accent focus:ring-2 focus:ring-accent/15"
                                />
                                {form.errors.nis && (
                                    <p className="mt-1 text-[11px] text-danger">
                                        {form.errors.nis}
                                    </p>
                                )}
                            </div>

                            <button
                                type="submit"
                                disabled={form.processing || kelas.length === 0}
                                className="w-full rounded-wf bg-accent px-3.5 py-2 text-xs font-medium text-white transition-opacity hover:opacity-90 disabled:opacity-50"
                            >
                                {form.processing ? 'Mencari...' : 'Cek ruang & kursi'}
                            </button>
                        </form>

                        {hasil !== null && (
                            <section className="space-y-3" aria-label="Hasil pencarian ruang">
                                <div className="rounded-wf border border-line bg-surface px-3.5 py-3">
                                    <p className="text-sm font-semibold text-ink">{hasil.nama}</p>
                                    <p className="mt-0.5 font-mono text-[11px] text-ink-3">
                                        {hasil.kelas}
                                        {hasil.nis ? ` · NIS ${hasil.nis}` : ''}
                                    </p>
                                </div>

                                {hasil.events.length === 0 ? (
                                    <div className="rounded-wf border border-line bg-surface px-4 py-6 text-center text-xs text-ink-3">
                                        Belum terdaftar pada event ujian yang aktif saat ini.
                                    </div>
                                ) : (
                                    hasil.events.map((ev) => (
                                        <article
                                            key={ev.id}
                                            className="overflow-hidden rounded-wf border border-line bg-surface"
                                        >
                                            <div className="flex items-center justify-between gap-2 border-b border-line bg-surface-2 px-3.5 py-2">
                                                <h2 className="truncate text-xs font-semibold text-ink">
                                                    {ev.nama}
                                                </h2>
                                                <span className="shrink-0 rounded-full border border-line bg-surface px-2 py-0.5 font-mono text-[10px] text-ink-2">
                                                    {ev.status_label}
                                                </span>
                                            </div>

                                            <div className="grid grid-cols-2 gap-2 border-b border-line p-3.5">
                                                <div className="rounded-wf border border-line bg-surface-2 px-3 py-2.5">
                                                    <p className="text-[10px] uppercase tracking-wide text-ink-3">
                                                        Ruang ujian
                                                    </p>
                                                    <p className="mt-1 font-mono text-lg font-semibold text-ink">
                                                        {ev.ruang?.kode ?? '—'}
                                                    </p>
                                                    {(ev.ruang?.nama || ev.ruang?.lokasi) && (
                                                        <p className="mt-0.5 text-[11px] text-ink-2">
                                                            {[ev.ruang?.nama, ev.ruang?.lokasi]
                                                                .filter(Boolean)
                                                                .join(' · ')}
                                                        </p>
                                                    )}
                                                </div>

                                                <div className="rounded-wf border border-line bg-surface-2 px-3 py-2.5">
                                                    <p className="text-[10px] uppercase tracking-wide text-ink-3">
                                                        Nomor kursi
                                                    </p>
                                                    <p className="mt-1 font-mono text-lg font-semibold text-accent">
                                                        {ev.kursi !== null ? `#${ev.kursi}` : '—'}
                                                    </p>
                                                    <p className="mt-0.5 text-[11px] text-ink-2">
                                                        {ev.meja !== null
                                                            ? `Meja ${ev.meja}`
                                                            : 'Belum ditempatkan'}
                                                    </p>
                                                </div>
                                            </div>

                                            {ev.jadwal.length > 0 && (
                                                <ul>
                                                    {ev.jadwal.map((j) => (
                                                        <li
                                                            key={j.id}
                                                            className="flex items-start justify-between gap-2 border-b border-line px-3.5 py-2.5 last:border-b-0"
                                                        >
                                                            <div className="min-w-0 flex-1">
                                                                <p className="text-xs font-medium text-ink">
                                                                    {j.mapel || j.ujian || 'Ujian'}
                                                                </p>
                                                                <p className="mt-0.5 text-[11px] text-ink-3">
                                                                    {j.hari_tanggal ?? 'Jadwal menyusul'}
                                                                </p>
                                                                <p className="mt-0.5 font-mono text-[11px] text-ink-2">
                                                                    {j.jam}
                                                                    {j.sesi_label ? ` · ${j.sesi_label}` : ''}
                                                                    {j.ruang_kode ? ` · ${j.ruang_kode}` : ''}
                                                                </p>
                                                            </div>
                                                            {j.susulan && (
                                                                <span className="shrink-0 rounded-full border border-warn/30 bg-warn-bg px-2 py-0.5 font-mono text-[10px] text-warn">
                                                                    susulan
                                                                </span>
                                                            )}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </article>
                                    ))
                                )}
                            </section>
                        )}
                    </main>

                    <footer className="border-t border-line bg-surface px-4 py-3">
                        <nav className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-[11px] text-ink-3">
                            <Link href={`/jadwal/${slug}`} className="text-accent hover:underline">
                                Jadwal &amp; pengumuman
                            </Link>
                            <span>·</span>
                            <Link href={`/papan/${slug}`} className="hover:text-ink">
                                Papan pengawas
                            </Link>
                            <span>·</span>
                            <Link href={`/pantau/${slug}`} className="hover:text-ink">
                                Pantau live
                            </Link>
                            <span>·</span>
                            <Link href={`/wali/${slug}`} className="hover:text-ink">
                                Pantau wali
                            </Link>
                        </nav>
                    </footer>
                </div>
            </div>
        </>
    );
}
