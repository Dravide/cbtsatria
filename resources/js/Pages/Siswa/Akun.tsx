import { Link, router } from '@inertiajs/react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { konfirmasi } from '@/Components/dialog';
import { Peringatan } from '@/Components/ui';

/**
 * Akun saya - tab keempat konsol siswa. `/siswa/akun`.
 *
 * =====================================================================
 * Laman ini tidak digambar wireframe mana pun
 * =====================================================================
 * `wireframe-peran.html` menggambar TAB "Akun" di tabbar siswa tiga kali
 * (`:999`, `:1215`, `:1243`) tetapi tidak pernah menggambar lamannya. Isinya
 * karena itu dikunci pemilik produk 2026-11-07: identitas + ganti sandi +
 * keluar.
 *
 * Sejak 2026-11-14 formulir ganti sandi PINDAH ke `/siswa/akun/sandi`
 * (permintaan pemilik produk: "agar laman /siswa/akun fokus ke informasi
 * akun saja"). Yang tinggal di sini identitas, satu tautan ke laman sandi,
 * dan tombol keluar. Alasan pemisahannya ada di blok "Keamanan" di bawah,
 * dekat dengan tempat akibatnya terbaca.
 *
 * =====================================================================
 * Redesign: identitas sebagai PAPAN, bukan daftar label-nilai abu
 * =====================================================================
 * Sampai redesign ini identitas berdiri sebagai enam baris `dt`/`dd` di mana
 * labelnya 12px abu dan nilainya rata kanan - bentuk yang membuat "Nama" dan
 * "Sekolah" sama pentingnya dengan "NISN", dan membuat salah satu dari
 * keenamnya (nama anak itu sendiri) tidak bisa ditemukan lebih cepat daripada
 * yang lain.
 *
 * Di sini keempat yang MENGENALI anak - nama, kelas, NIS, NISN, username,
 * sekolah - tetap lengkap, tetapi barisnya bergaris seperti papan, dan yang
 * pertama jauh lebih besar daripada sisanya. Nama adalah satu-satunya nilai
 * di laman ini yang perlu ditemukan dalam sekejap.
 *
 * =====================================================================
 * "Sandi" di sini, "Password" di layar login
 * =====================================================================
 * Ketidaksamaan itu disengaja dan dicatat supaya terbaca sebagai pilihan.
 * Layar login memakai kata yang dikenali anak dari ponselnya; laman ini
 * memakai kata yang dipakai sekolah. Mengubah layar login ada di luar lingkup
 * dan akan menyentuh satu form yang dipakai empat peran.
 *
 * =====================================================================
 * Peringatan sandi bawaan TIDAK memblokir apa pun
 * =====================================================================
 * Ia satu kartu kuning dan satu kalimat, tidak lebih. Keputusan yang dikunci:
 * anak tidak boleh tertahan di luar ruang ujian karena urusan sandi. Karena
 * itu tidak ada gerbang di sini, tidak ada tombol yang wajib ditekan, dan
 * tidak ada penghitung waktu.
 *
 * =====================================================================
 * UmpanBalik TIDAK dirender di sini
 * =====================================================================
 * `KerangkaSiswa` sudah merendernya di dalam `<main>` - merender dua kali
 * berarti dua kotak pesan yang isinya sama.
 *
 * =====================================================================
 * Tautan keluar ADA DI LAMAN INI, bukan di kaki kerangka
 * =====================================================================
 * Kaki kerangka hanya merender "Keluar dari akun" bila tabbarnya
 * disembunyikan (`KerangkaSiswa`, blok terakhir), dan laman ini mengisi
 * `tabbarAktif`. Tanpa blok keluar di bawah, siswa tidak punya jalan keluar
 * sama sekali dari keempat laman bertabbar.
 */

type Props = {
    nama: string;
    username: string;
    nis: string | null;
    nisn: string | null;
    sekolah: string | null;
    kelas: string | null;
    sandi_bawaan: boolean;
};

export default function Akun({ nama, username, nis, nisn, sekolah, kelas, sandi_bawaan }: Props) {
    return (
        <KerangkaSiswa
            judul="Akun saya"
            tabbarAktif="akun"
            kiri={<span className="truncate text-[12px] font-medium text-ink">Akun · {nama}</span>}
            kanan={
                kelas ? (
                    <span className="border border-line-2 px-1.5 py-0.5 text-[11px] text-ink-2">
                        {kelas}
                    </span>
                ) : undefined
            }
        >
            {/* Peringatan, bukan penegakan - lihat catatan berkas ini. */}
            {sandi_bawaan && (
                <Peringatan>
                    Sandi Anda masih sandi bawaan dari sekolah. Anda tetap bisa mengikuti ujian
                    dengan sandi ini, tetapi sebaiknya diganti sekarang: sandi bawaan sama untuk
                    semua siswa, sehingga orang lain bisa masuk ke akun Anda.
                </Peringatan>
            )}

            <section className="daftar overflow-hidden">
                <header className="daftar-kepala">
                    <h2>Identitas</h2>
                </header>

                <div className="px-3 py-3">
                    {/* Nama anak itu sendiri, dan ia satu-satunya nilai di sini
                        yang berukuran lebih besar dari badan teks. */}
                    <b className="block truncate font-display text-[17px] leading-tight text-ink">
                        {nama}
                    </b>
                    <span className="catatan mt-1 block truncate">
                        {sekolah ?? 'Sekolah belum terdata'}
                    </span>
                </div>

                {/*
                 * Baris-barisnya bergaris, jadi yang memisahkan dua nilai
                 * adalah garis, bukan jarak. Nilainya rata KIRI dan berdampingan
                 * dengan labelnya - rata kanan pada kolom sempit membuat dua
                 * nilai dengan panjang berbeda tampak berasal dari dua tabel.
                 */}
                <dl>
                    <BarisNilai label="Username" nilai={username} />
                    <BarisNilai label="Kelas" nilai={kelas} />
                    <BarisNilai label="NIS" nilai={nis} />
                    <BarisNilai label="NISN" nilai={nisn} />
                </dl>
            </section>

            {/*
             * Ganti sandi PINDAH ke laman sendiri sejak 2026-11-14.
             *
             * Sampai saat itu formulir tiga kolom berdiri di setengah bawah
             * laman ini, dan akibatnya laman yang dibuka untuk "nomor induk
             * saya berapa" memuat tiga kolom sandi yang selalu terlihat -
             * dan formulir yang selalu terlihat adalah formulir yang
             * sesekali tersentuh tanpa niat. Yang tersentuh tanpa niat di
             * sini adalah sandi.
             *
             * Yang tersisa di sini adalah TAUTAN, dan kalimatnya menyebut
             * apa yang menunggu di sana - bukan hanya "Ganti sandi", yang
             * pada tabbar tiga item lain terbaca sebagai label menu keempat.
             */}
            <section className="daftar overflow-hidden">
                <header className="daftar-kepala">
                    <h2>Keamanan</h2>
                </header>

                <div className="px-3 py-3">
                    {/* Kalimatnya TIDAK mengulang peringatan di atas laman.
                        Peringatan itu sudah menjelaskan mengapa sandi bawaan
                        berbahaya; yang dibutuhkan di sini hanya apa yang
                        menunggu di laman sebelah. */}
                    <p className="text-[12px] leading-relaxed text-ink-2">
                        Sandi akun diganti di laman tersendiri, bukan di sini. Menggantinya
                        akan mengeluarkan akun Anda dari perangkat lain.
                    </p>

                    <Link href="/siswa/akun/sandi" className="tombol mt-2.5 w-full">
                        Ganti sandi
                    </Link>
                </div>
            </section>

            <section className="daftar">
                {/* Tombol, bukan `<Link as="button">`: keluar ditanyakan lebih
                    dulu, dan dialognya asinkron - sementara `onClick` pada
                    `Link` harus memutuskan `preventDefault()` saat itu juga.
                    Dengan `router.post` di sini, kunjungannya baru terjadi
                    SETELAH siswa menjawab, jadi tidak ada tombol yang sudah
                    berangkat sebelum pertanyaannya selesai. */}
                <button
                    type="button"
                    onClick={async () => {
                        const setuju = await konfirmasi({
                            judul: 'Keluar dari akun ini?',
                            pesan: 'Pengerjaan yang sedang berjalan tetap tersimpan di server. Masuk lagi dengan NIS dan sandi yang sama.',
                            labelYa: 'Keluar',
                            nada: 'bahaya',
                        });

                        if (setuju) {
                            router.post('/logout');
                        }
                    }}
                    className="tombol tombol--bahaya w-full"
                >
                    Keluar dari akun
                </button>
            </section>
        </KerangkaSiswa>
    );
}

/**
 * Satu baris label-nilai.
 *
 * Helper berkas-lokal, meniru `Admin/RuangDetail.tsx` - `ui.tsx` sengaja
 * tidak punya komponen key-value bersama, dan house style-nya memang
 * helper di berkas yang memakainya.
 *
 * Nilai kosong ditulis "belum terdata", bukan tanda pisah. Cara itu sama
 * dengan yang dipakai papan di beranda, dan ia menjelaskan bahwa yang
 * hilang adalah datanya - bukan bahwa nilainya nol atau tidak berlaku.
 */
function BarisNilai({ label, nilai }: { label: string; nilai: string | null }) {
    return (
        <div className="daftar-baris flex items-baseline gap-3 px-3 py-2">
            <dt className="w-[84px] shrink-0 text-[11px] text-ink-3">{label}</dt>
            <dd className="angka min-w-0 flex-1 truncate text-[12px] text-ink">
                {nilai ?? 'belum terdata'}
            </dd>
        </div>
    );
}
