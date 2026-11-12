import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Field, Inbox, Kotak, Tombol, kelasInput } from '@/Components/ui';

/**
 * Laman 11 - Buat / ubah event. Wireframe plate 11.
 *
 * ---------------------------------------------------------------------
 * Ambang pelanggaran ada di sini, bukan di Pengaturan sekolah
 * ---------------------------------------------------------------------
 * Plate 11 mencatat alasannya: "toleransi berbeda menurut jenis ujian: ujian
 * harian dan ujian akhir tidak seharusnya memakai ambang yang sama. Nilai
 * bawaannya diambil dari Pengaturan sekolah, lalu disimpan sendiri oleh event
 * ini."
 *
 * Tiga field, bukan satu. Plate 23 menampilkan satu angka sekolah karena
 * itulah toleransi UMUM; yang menentukannya per tingkat adalah laman ini.
 *
 * ---------------------------------------------------------------------
 * Urutan ambang diperiksa di layar SEBELUM dikirim
 * ---------------------------------------------------------------------
 * Peringatkan 3 / tandai 2 / kunci 1 adalah konfigurasi yang bisa diketik
 * siapa saja. Server menolaknya - tetapi menunggu perjalanan bolak-balik
 * untuk memberi tahu sesuatu yang bisa dilihat di layar adalah pengalaman
 * yang buruk, dan Admin sudah tahu ia salah ketik sejak angka kedua.
 *
 * Karena itu peringatannya muncul saat mengetik. Server tetap memeriksanya:
 * peringatan di layar adalah kenyamanan, bukan penegakan.
 *
 * ---------------------------------------------------------------------
 * "Zona waktu" ditampilkan, tidak bisa diubah di sini
 * ---------------------------------------------------------------------
 * Plate 11 menampilkan field Zona waktu berisi "Asia/Jakarta". Ia milik
 * sekolah, bukan milik event: dua event di sekolah yang sama tidak boleh
 * berjalan di zona waktu berbeda, karena jadwal ujian dibandingkan lintas
 * event saat memeriksa benturan ruang. Yang dilakukan laman ini adalah
 * MENAMPILKANNYA supaya Admin melihat zona mana yang akan dipakai -
 * mengubahnya adalah pekerjaan Profil sekolah.
 */
type OpsiTahun = { id: number; nama: string; aktif: boolean };
type OpsiSemester = { id: number; tahun_id: number; nama: string };
type EventRingkas = {
    id: number;
    kode: string;
    nama: string;
    jenis: string | null;
    status: string;
    status_label: string;
    final: boolean;
    description: string | null;
    semester_id: number | null;
    tahun_id: number;
    ambang_peringatkan: number;
    ambang_tandai_pengawas: number;
    ambang_kunci_ujian: number;
    enforce_fullscreen: boolean;
    detect_split_screen: boolean;
    detect_float_window: boolean;
    show_watermark: boolean;
    mulai: string | null;
    selesai: string | null;
};

type Props = {
    event: EventRingkas | null;
    bawaan: {
        ambang_peringatkan: number;
        ambang_tandai_pengawas: number;
        ambang_kunci_ujian: number;
        enforce_fullscreen: boolean;
        detect_split_screen: boolean;
        detect_float_window: boolean;
        show_watermark: boolean;
        timezone: string;
    } | null;
    tahun: OpsiTahun[];
    semester: OpsiSemester[];
    jenis: string[];
};

export default function EventForm({ event, bawaan, tahun, semester, jenis }: Props) {
    const ubah = event !== null;

    const { data, setData, post, put, processing, errors } = useForm({
        name: event?.nama ?? '',
        event_type: event?.jenis ?? '',
        description: event?.description ?? '',
        academic_year_id: event?.tahun_id ?? (tahun.find((t) => t.aktif)?.id ?? tahun[0]?.id ?? 0),
        semester_id: event?.semester_id ?? null,
        code: event?.kode ?? '',

        // Tiga ambang. Saat membuat, ketiganya berisi angka pengaturan
        // sekolah - satu angka yang sama untuk ketiganya, karena itulah yang
        // dimaksud "bawaan" di plate 23.
        ambang_peringatkan: event?.ambang_peringatkan ?? bawaan?.ambang_peringatkan ?? 5,
        ambang_tandai_pengawas: event?.ambang_tandai_pengawas ?? bawaan?.ambang_tandai_pengawas ?? 5,
        ambang_kunci_ujian: event?.ambang_kunci_ujian ?? bawaan?.ambang_kunci_ujian ?? 5,

        enforce_fullscreen: event?.enforce_fullscreen ?? bawaan?.enforce_fullscreen ?? true,

        // Tiga penjaga layar: bawaan dari Pengaturan sekolah, disimpan
        // sendiri oleh event ini.
        detect_split_screen: event?.detect_split_screen ?? bawaan?.detect_split_screen ?? true,
        detect_float_window: event?.detect_float_window ?? bawaan?.detect_float_window ?? true,
        show_watermark: event?.show_watermark ?? bawaan?.show_watermark ?? true,
        start_at: event?.mulai ?? '',
        end_at: event?.selesai ?? '',
    });

    // Semester disaring per tahun ajaran yang dipilih. Mengirim seluruh
    // semester sekolah memaksa peramban menebak hubungannya, dan tebakannya
    // salah begitu sekolah punya lebih dari dua tahun ajaran.
    const semesterTersedia = useMemo(
        () => semester.filter((s) => s.tahun_id === Number(data.academic_year_id)),
        [semester, data.academic_year_id],
    );

    const urutanSalah =
        !(data.ambang_peringatkan <= data.ambang_tandai_pengawas) ||
        !(data.ambang_tandai_pengawas <= data.ambang_kunci_ujian);

    const kirim = (e: React.FormEvent) => {
        e.preventDefault();

        if (ubah && event) {
            put(`/admin/events/${event.id}`);
        } else {
            post('/admin/events');
        }
    };

    return (
        <AdminLayout
            judul={ubah ? `Ubah ${event?.nama}` : 'Buat event'}
            breadcrumb={
                ubah
                    ? `/admin/events · ${event?.kode} · Ubah`
                    : '/admin/events · Buat'
            }
            aksi={
                <>
                    <Link href={ubah && event ? `/admin/events/${event.id}` : '/admin/events'}>
                        <Tombol>Batal</Tombol>
                    </Link>
                    <Tombol
                        nada="pri"
                        type="submit"
                        disabled={processing || urutanSalah}
                        onClick={() => {
                            const form = document.getElementById('form-event') as HTMLFormElement;
                            form?.requestSubmit();
                        }}
                    >
                        {ubah ? 'Simpan' : 'Simpan draft'}
                    </Tombol>
                </>
            }
        >
            <Head title={ubah ? 'Ubah event' : 'Buat event'} />

            {ubah && event?.final && (
                <div className="mb-3">
                    <Chip nada="warn">
                        Event berstatus {event.status_label} &mdash; tidak bisa dipindahkan lagi
                    </Chip>
                </div>
            )}

            <form id="form-event" onSubmit={kirim} className="space-y-4">
                <Kotak judul="Identitas">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Nama event" galat={errors.name}>
                            <input
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="UAS Ganjil 2026/2027"
                                className={kelasInput}
                            />
                        </Field>

                        <Field
                            label="Jenis"
                            galat={errors.event_type}
                            petunjuk="Boleh diketik bebas bila sekolah punya istilah sendiri."
                        >
                            <input
                                value={data.event_type}
                                onChange={(e) => setData('event_type', e.target.value)}
                                list="daftar-jenis"
                                placeholder="Ujian akhir semester"
                                className={kelasInput}
                            />
                            <datalist id="daftar-jenis">
                                {jenis.map((j) => (
                                    <option key={j} value={j} />
                                ))}
                            </datalist>
                        </Field>
                    </div>

                    <div className="mt-3 grid gap-3 sm:grid-cols-3">
                        <Field label="Tahun ajaran" galat={errors.academic_year_id}>
                            <select
                                value={data.academic_year_id}
                                onChange={(e) => {
                                    setData('academic_year_id', Number(e.target.value));
                                    // Semester dari tahun ajaran lama tidak
                                    // berlaku lagi. Membiarkannya terpilih
                                    // berarti menyimpan pasangan tahun-semester
                                    // yang tidak pernah ada.
                                    setData('semester_id', null);
                                }}
                                disabled={ubah}
                                className={kelasInput}
                            >
                                {tahun.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.nama}
                                        {t.aktif ? ' (aktif)' : ''}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field
                            label="Semester"
                            galat={errors.semester_id}
                            petunjuk={ubah ? undefined : 'Boleh dikosongkan.'}
                        >
                            <select
                                value={data.semester_id ?? ''}
                                onChange={(e) =>
                                    setData('semester_id', e.target.value ? Number(e.target.value) : null)
                                }
                                className={kelasInput}
                            >
                                <option value="">— tanpa semester —</option>
                                {semesterTersedia.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.nama}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Zona waktu" petunjuk="Mengikuti Profil sekolah.">
                            <input
                                value={bawaan?.timezone ?? 'Asia/Jakarta'}
                                readOnly
                                className={kelasInput + ' font-mono'}
                            />
                        </Field>
                    </div>

                    {!ubah && (
                        <div className="mt-3">
                            <Field
                                label="Kode event"
                                galat={errors.code}
                                petunjuk="Kosongkan untuk dibangkitkan otomatis: EV-2026-001."
                            >
                                <input
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                    placeholder="otomatis"
                                    className={kelasInput + ' max-w-[12rem] font-mono'}
                                />
                            </Field>
                        </div>
                    )}

                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                        <Field label="Mulai" galat={errors.start_at}>
                            <input
                                type="datetime-local"
                                value={data.start_at}
                                onChange={(e) => setData('start_at', e.target.value)}
                                className={kelasInput + ' font-mono'}
                            />
                        </Field>

                        <Field label="Selesai" galat={errors.end_at}>
                            <input
                                type="datetime-local"
                                value={data.end_at}
                                onChange={(e) => setData('end_at', e.target.value)}
                                className={kelasInput + ' font-mono'}
                            />
                        </Field>
                    </div>

                    <div className="mt-3">
                        <Field label="Deskripsi" galat={errors.description}>
                            <textarea
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                rows={2}
                                className={kelasInput}
                            />
                        </Field>
                    </div>
                </Kotak>

                <Kotak judul="Ambang pelanggaran siswa">
                    <p className="mb-3 text-xs leading-relaxed text-ink-2">
                        Tiga tingkat, dan masing-masing berlaku pada satu tindakan. Nilai awalnya
                        diambil dari Pengaturan sekolah &mdash; sesudah disimpan, event ini
                        memegang nilainya sendiri dan tidak lagi ikut berubah bila pengaturan
                        sekolah diubah. Urutannya harus menaik: siswa tidak boleh dikunci
                        sebelum ia diperingatkan.
                    </p>

                    <div className="grid gap-3 sm:grid-cols-3">
                        <Field label="Peringatkan" galat={errors.ambang_peringatkan}>
                            <input
                                type="number"
                                min={1}
                                max={50}
                                value={data.ambang_peringatkan}
                                onChange={(e) =>
                                    setData('ambang_peringatkan', Number(e.target.value))
                                }
                                className={kelasInput + ' font-mono'}
                            />
                        </Field>

                        <Field label="Tandai pengawas" galat={errors.ambang_tandai_pengawas}>
                            <input
                                type="number"
                                min={1}
                                max={50}
                                value={data.ambang_tandai_pengawas}
                                onChange={(e) =>
                                    setData('ambang_tandai_pengawas', Number(e.target.value))
                                }
                                className={kelasInput + ' font-mono'}
                            />
                        </Field>

                        <Field label="Kunci ujian" galat={errors.ambang_kunci_ujian}>
                            <input
                                type="number"
                                min={1}
                                max={50}
                                value={data.ambang_kunci_ujian}
                                onChange={(e) =>
                                    setData('ambang_kunci_ujian', Number(e.target.value))
                                }
                                className={kelasInput + ' font-mono'}
                            />
                        </Field>
                    </div>

                    {urutanSalah ? (
                        <p className="mt-2 text-xs text-bad">
                            Urutan harus menaik: peringatkan ({data.ambang_peringatkan}) &le; tandai
                            pengawas ({data.ambang_tandai_pengawas}) &le; kunci ujian (
                            {data.ambang_kunci_ujian}).
                        </p>
                    ) : (
                        <p className="mt-2 font-mono text-[11px] text-ink-3">
                            Pelanggaran ke-{data.ambang_peringatkan} &rarr; peringatan &middot;
                            ke-{data.ambang_tandai_pengawas} &rarr; pengawas ditandai &middot;
                            ke-{data.ambang_kunci_ujian} &rarr; ujian terkunci
                        </p>
                    )}

                    <label className="mt-4 flex items-start gap-2">
                        <input
                            type="checkbox"
                            checked={data.enforce_fullscreen}
                            onChange={(e) => setData('enforce_fullscreen', e.target.checked)}
                            className="mt-0.5"
                        />
                        <span className="text-xs text-ink-2">
                            Wajib layar penuh selama ujian
                            <span className="mt-0.5 block text-[11px] text-ink-3">
                                Berbeda dari ketiga ambang di atas. Ambang menjawab &ldquo;berapa
                                kali boleh&rdquo;; ini menjawab &ldquo;boleh keluar atau
                                tidak&rdquo; &mdash; dan jawabannya tidak bergantung pada
                                hitungan.
                            </span>
                        </span>
                    </label>

                    {/* Tiga penjaga layar. Sengaja di dalam kotak yang SAMA
                        dengan layar penuh: ketiganya hanya berlaku bila layar
                        penuh dituntut, dan kotak terpisah akan membuatnya
                        terbaca sebagai tiga aturan yang berdiri sendiri. */}
                    <div className="mt-3 border-t border-line pt-3">
                        <p className="mb-2 text-[11px] font-medium tracking-wide text-ink-2 uppercase">
                            Penjaga layar
                        </p>

                        <div className="space-y-2">
                            <label className="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    checked={data.detect_split_screen}
                                    onChange={(e) =>
                                        setData('detect_split_screen', e.target.checked)
                                    }
                                    className="mt-0.5"
                                />
                                <span className="text-xs text-ink-2">
                                    Deteksi layar terbagi
                                    <span className="mt-0.5 block text-[11px] text-ink-3">
                                        Lebar yang menyusut sampai di bawah 72% layar dilaporkan
                                        sebagai <code>layar_terbagi</code>, tingkat 3 &mdash;
                                        dicatat, tidak mengunci.
                                    </span>
                                </span>
                            </label>

                            <label className="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    checked={data.detect_float_window}
                                    onChange={(e) =>
                                        setData('detect_float_window', e.target.checked)
                                    }
                                    className="mt-0.5"
                                />
                                <span className="text-xs text-ink-2">
                                    Deteksi jendela mengambang
                                    <span className="mt-0.5 block text-[11px] text-ink-3">
                                        Tinggi yang menyusut sampai di bawah 55% layar. Ambangnya
                                        lebih longgar dari lebar dengan sengaja: bilah alamat
                                        peramban ponsel memakan tinggi tanpa siapa pun membelah
                                        layar.
                                    </span>
                                </span>
                            </label>

                            <label className="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    checked={data.show_watermark}
                                    onChange={(e) => setData('show_watermark', e.target.checked)}
                                    className="mt-0.5"
                                />
                                <span className="text-xs text-ink-2">
                                    Tanda air nama dan nomor induk
                                    <span className="mt-0.5 block text-[11px] text-ink-3">
                                        Tangkapan layar tidak bisa diblokir peramban; tanda air
                                        membuat hasilnya menyebut pemiliknya. Mematikannya pada
                                        layar kecil masuk akal &mdash; ia menutupi sebagian soal.
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>
                </Kotak>

                {!ubah && (
                    <Inbox>
                        Event baru selalu dibuat sebagai <strong>draft</strong>. Komponennya
                        &mdash; kelas, peserta, mapel, ruang, panitia &mdash; disusun sesudahnya,
                        dan status <strong>siap</strong> hanya bisa dicapai bila seluruh syarat
                        kesiapan terpenuhi.
                    </Inbox>
                )}

                <div className="flex gap-2">
                    <Tombol
                        type="submit"
                        nada="pri"
                        disabled={processing || urutanSalah}
                    >
                        {ubah ? 'Simpan perubahan' : 'Simpan draft'}
                    </Tombol>
                    <Link href={ubah && event ? `/admin/events/${event.id}` : '/admin/events'}>
                        <Tombol>Batal</Tombol>
                    </Link>
                </div>
            </form>
        </AdminLayout>
    );
}
