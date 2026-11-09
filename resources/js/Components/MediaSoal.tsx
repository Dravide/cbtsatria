import { useRef, useState } from 'react';
import { Tombol } from '@/Components/ui';

/**
 * Unggah dan hapus media soal - gambar/audio/video, pada SOAL maupun pada
 * satu PILIHAN JAWABAN.
 *
 * =====================================================================
 * Unggahan langsung, tanpa menunggu soal disimpan
 * =====================================================================
 * Guru menempel gambar ke pilihan B selagi menulis pilihan A dan C. Kalau
 * unggahan menuntut soal tersimpan lebih dulu, seluruh builder harus dibuat
 * menyimpan kosong lebih dulu - dan soal kosong tanpa teks ditolak aturannya
 * sendiri. Karena itu berkasnya diunggah SEKARANG, sebagai media draf
 * (`question_id` NULL), lalu ditautkan saat soal disimpan.
 *
 * Endpoint-nya menjawab JSON dan dipanggil lewat `fetch`, bukan Inertia:
 * redirect akan memasang ulang seluruh builder dan membuang draf yang belum
 * tersimpan. Pola CSRF-nya sama dengan `Siswa/Kerjakan.tsx`.
 *
 * =====================================================================
 * Daftar `media` milik PEMANGGIL, bukan komponen ini
 * =====================================================================
 * Komponen ini tidak menyimpan daftar berkasnya sendiri - ia menerima
 * `media` dan memanggil `padaUbah` setiap kali daftar berubah. Itulah yang
 * membuat id media draf bisa ikut terkirim saat soal disimpan: pemanggil
 * (Builder) memegang daftarnya dan menyertakannya di payload.
 */

export type MediaButir = {
    id: number;
    tipe: string;
    mime: string | null;
    nama: string | null;
    ukuran: number;
    url: string;
    gambar: boolean;
};

/** Token CSRF dari cookie - bentuk yang dibaca `PreventRequestForgery`. */
function tokenCsrf(): string {
    const cocok = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return cocok ? decodeURIComponent(cocok[1]) : '';
}

/**
 * Unggah satu berkas. `soalId` null = media draf untuk soal baru.
 *
 * Melempar `Error` berisi pesan Indonesia yang siap ditampilkan bila server
 * menolaknya (jenis berkas tak dikenal, ukuran lebih batas, izin).
 */
export async function unggahMedia(
    berkas: File,
    opsi: { soalId: number | null; optionKey?: string | null },
): Promise<MediaButir> {
    const badan = new FormData();
    badan.append('berkas', berkas);

    if (opsi.soalId !== null) {
        badan.append('soal_id', String(opsi.soalId));
    }

    if (opsi.optionKey) {
        badan.append('option_key', opsi.optionKey);
    }

    const tanggapan = await fetch('/guru/bank-soal/media', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': tokenCsrf(),
        },
        body: badan,
    });

    // 419 = sesi kedaluwarsa. Muat ulang memulihkan tokennya; mengulang
    // kiriman tidak.
    if (tanggapan.status === 419) {
        window.location.reload();

        throw new Error('Sesi kedaluwarsa. Halaman akan dimuat ulang.');
    }

    const isi = (await tanggapan.json().catch(() => ({}))) as {
        media?: MediaButir;
        message?: string;
        errors?: Record<string, string[]>;
    };

    if (!tanggapan.ok || !isi.media) {
        const pesan = isi.errors?.berkas?.[0] ?? isi.message;

        throw new Error(pesan ?? 'Gagal mengunggah berkas. Coba lagi.');
    }

    return isi.media;
}

/** Hapus satu berkas. Melempar `Error` berpesan Indonesia bila ditolak. */
export async function hapusMedia(id: number): Promise<void> {
    const tanggapan = await fetch(`/guru/bank-soal/media/${id}`, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': tokenCsrf(),
        },
    });

    if (tanggapan.status === 419) {
        window.location.reload();

        throw new Error('Sesi kedaluwarsa. Halaman akan dimuat ulang.');
    }

    if (!tanggapan.ok) {
        const isi = (await tanggapan.json().catch(() => ({}))) as { message?: string };

        throw new Error(isi.message ?? 'Gagal menghapus berkas. Coba lagi.');
    }
}

/**
 * Pemilih media: pratinjau berkas terpasang, tombol unggah, dan tombol buang.
 *
 * `ringkas` dipakai di dalam baris pilihan jawaban - pratinjau lebih kecil dan
 * tombolnya sebaris, supaya empat pilihan tidak berubah menjadi empat kotak
 * besar.
 */
export function MediaSoal({
    media,
    padaUbah,
    soalId,
    optionKey = null,
    bolehUbah = true,
    ringkas = false,
}: {
    media: MediaButir[];
    padaUbah: (media: MediaButir[]) => void;
    soalId: number | null;
    optionKey?: string | null;
    bolehUbah?: boolean;
    ringkas?: boolean;
}) {
    const [sibuk, setSibuk] = useState(false);
    const [galat, setGalat] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const pilih = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const daftar = e.target.files;

        if (!daftar || daftar.length === 0) {
            return;
        }

        setSibuk(true);
        setGalat(null);

        try {
            const baru = [...media];

            // Satu per satu, bukan Promise.all: berkas besar dikirim berurutan
            // supaya pesan galat menyebut berkas yang benar-benar gagal.
            for (const berkas of Array.from(daftar)) {
                baru.push(await unggahMedia(berkas, { soalId, optionKey }));
            }

            padaUbah(baru);
        } catch (err) {
            setGalat(err instanceof Error ? err.message : 'Gagal mengunggah berkas.');
        } finally {
            setSibuk(false);

            if (inputRef.current) {
                inputRef.current.value = '';
            }
        }
    };

    const buang = async (id: number) => {
        setSibuk(true);
        setGalat(null);

        try {
            await hapusMedia(id);
            padaUbah(media.filter((m) => m.id !== id));
        } catch (err) {
            setGalat(err instanceof Error ? err.message : 'Gagal menghapus berkas.');
        } finally {
            setSibuk(false);
        }
    };

    const ukuranGambar = ringkas ? 'max-h-16' : 'max-h-40';

    return (
        <div className={ringkas ? 'mt-1 space-y-1' : 'space-y-2'}>
            {media.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {media.map((m) => (
                        <div
                            key={m.id}
                            className="relative rounded-wf border border-line bg-surface-2/40 p-1"
                        >
                            {m.gambar ? (
                                <img
                                    src={m.url}
                                    alt={m.nama ?? `Media ${m.id}`}
                                    className={`${ukuranGambar} max-w-full rounded-wf`}
                                />
                            ) : (
                                <a
                                    href={m.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="block px-2 py-1 text-[11px] text-accent underline"
                                >
                                    {m.nama ?? `Berkas ${m.id}`}
                                </a>
                            )}
                            {bolehUbah && (
                                <button
                                    type="button"
                                    onClick={() => buang(m.id)}
                                    disabled={sibuk}
                                    title="Buang berkas ini"
                                    className="absolute -top-1.5 -right-1.5 grid size-4 place-items-center rounded-full border border-line bg-surface text-[10px] text-bad hover:bg-bad-bg disabled:opacity-50"
                                >
                                    ×
                                </button>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {bolehUbah && (
                <>
                    <input
                        ref={inputRef}
                        type="file"
                        accept="image/*,audio/*,video/*"
                        multiple
                        onChange={pilih}
                        disabled={sibuk}
                        className="hidden"
                    />
                    <Tombol
                        onClick={() => inputRef.current?.click()}
                        disabled={sibuk}
                        title="Gambar: JPG/PNG/WEBP/GIF. Audio: MP3/WAV/OGG/M4A/AAC. Video: MP4/WEBM."
                    >
                        {sibuk ? 'Mengunggah…' : ringkas ? '+ Gambar/berkas' : '+ Tambah gambar/berkas'}
                    </Tombol>
                </>
            )}

            {galat && <p className="text-[11px] text-bad">{galat}</p>}
        </div>
    );
}
