import { Link, useForm } from '@inertiajs/react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { Field, Peringatan, kelasInput } from '@/Components/ui';

/**
 * Ganti sandi - `/siswa/akun/sandi`. Dipisah dari laman Akun 2026-11-14.
 *
 * =====================================================================
 * Mengapa laman sendiri, bukan formulir di bawah identitas
 * =====================================================================
 * Permintaan pemilik produk: "agar laman /siswa/akun fokus ke informasi akun
 * saja". Alasan yang membuat permintaan itu bukan sekadar kerapian: laman
 * Akun dibuka untuk menjawab "nomor induk saya berapa", dan formulir tiga
 * kolom yang selalu terlihat di bawahnya adalah formulir yang sesekali
 * tersentuh tanpa niat. Yang tersentuh tanpa niat di sini adalah sandi.
 *
 * Laman sendiri juga membuat satu hal mungkin: setelah sandinya berganti,
 * anak kembali ke Akun - bukan ke formulir kosong. Lihat `gantiSandi()` di
 * server, yang mengarahkan ke `siswa.akun`.
 *
 * =====================================================================
 * Nama field tetap bahasa INGGRIS, dan itu bukan kelalaian
 * =====================================================================
 * `Handler::$dontFlash` di framework hanya membuang tiga nama:
 * `current_password`, `password`, `password_confirmation`. Mengubahnya
 * menjadi `sandi_lama` akan menulis KETIGA sandi sebagai teks terbuka ke
 * `_old_input` setiap kali validasi gagal - dan tidak ada yang gagal secara
 * terlihat. Alasannya lengkap di `GantiSandiRequest`.
 *
 * =====================================================================
 * Kalimat "perangkat lain" tinggal di sini, bukan di Akun
 * =====================================================================
 * Ia menjelaskan AKIBAT dari tindakan yang dilakukan di laman ini, dan
 * kalimat yang menjelaskan akibat harus berada di sebelah tombolnya. Di
 * laman Akun ia hanya akan terbaca sebagai keterangan yang tidak menempel
 * pada apa pun.
 *
 * =====================================================================
 * `sandi_bawaan` masih datang, dan dipakai untuk dua kalimat berbeda
 * =====================================================================
 * Belum terganti: peringatan yang menjelaskan MENGAPA sebaiknya diganti.
 * Sudah terganti: satu kalimat biasa, karena peringatan yang tetap muncul
 * sesudah sandinya diganti adalah peringatan yang berhenti dipercaya.
 */

type Props = {
    nama: string;
    sandi_bawaan: boolean;
};

export default function Sandi({ nama, sandi_bawaan }: Props) {
    const form = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    function simpan(e: React.FormEvent) {
        e.preventDefault();

        form.put('/siswa/akun/sandi', {
            // Gulir tidak dikembalikan ke atas: pesan galat muncul persis di
            // bawah field yang salah, dan melompat ke atas menyembunyikannya.
            preserveScroll: true,
            // `onSuccess` TIDAK mengosongkan formulir: server mengarahkan ke
            // laman Akun pada keberhasilan, sehingga laman ini dilepas -
            // dan `reset()` pada komponen yang akan dilepas adalah pekerjaan
            // yang tidak pernah terlihat hasilnya.
        });
    }

    return (
        <KerangkaSiswa
            judul="Ganti sandi"
            tabbarAktif="akun"
            kiri={<span className="truncate text-[12px] font-medium text-ink">Sandi · {nama}</span>}
            kanan={
                <Link href="/siswa/akun" className="text-[11px] text-ink-2 hover:text-ink">
                    Kembali
                </Link>
            }
        >
            {sandi_bawaan ? (
                <Peringatan>
                    Sandi Anda masih sandi bawaan dari sekolah. Anda tetap bisa mengikuti ujian
                    dengan sandi ini, tetapi sebaiknya diganti sekarang: sandi bawaan sama untuk
                    semua siswa, sehingga orang lain bisa masuk ke akun Anda.
                </Peringatan>
            ) : (
                <p className="keterangan">
                    Sandi Anda sudah bukan sandi bawaan sekolah. Menggantinya di sini akan
                    mengeluarkan akun dari perangkat lain.
                </p>
            )}

            <section className="daftar overflow-hidden">
                <header className="daftar-kepala">
                    <h2>Ganti sandi</h2>
                </header>

                <form onSubmit={simpan} className="space-y-3 p-3">
                    <Field label="Sandi lama" galat={form.errors.current_password}>
                        <input
                            type="password"
                            autoComplete="current-password"
                            value={form.data.current_password}
                            onChange={(e) => form.setData('current_password', e.target.value)}
                            className={kelasInput}
                        />
                    </Field>

                    <Field
                        label="Sandi baru"
                        galat={form.errors.password}
                        petunjuk="Minimal 6 karakter."
                    >
                        <input
                            type="password"
                            autoComplete="new-password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                            className={kelasInput}
                        />
                    </Field>

                    <Field label="Ulangi sandi baru" galat={form.errors.password_confirmation}>
                        <input
                            type="password"
                            autoComplete="new-password"
                            value={form.data.password_confirmation}
                            onChange={(e) => form.setData('password_confirmation', e.target.value)}
                            className={kelasInput}
                        />
                    </Field>

                    {/*
                     * Kalimat ini yang membedakan laman ini dari laman Admin:
                     * siswa perlu tahu bahwa mengganti sandi di ponselnya
                     * memutus sesi di perangkat lain - termasuk kalau
                     * sandinya memang dipakai orang lain.
                     */}
                    <p className="text-[12px] leading-relaxed text-ink-2">
                        Mengganti sandi akan mengeluarkan akun Anda dari perangkat lain. Ponsel ini
                        tetap masuk.
                    </p>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="tombol tombol--pri w-full"
                    >
                        {form.processing ? 'Menyimpan…' : 'Simpan sandi'}
                    </button>
                </form>
            </section>
        </KerangkaSiswa>
    );
}
