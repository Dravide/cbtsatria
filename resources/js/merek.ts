/**
 * Nama dan kalimat identitas aplikasi - SATU tempat.
 *
 * Sebelumnya tiga berkas menulis "School CBT" sendiri-sendiri sementara
 * `.env` sudah menyebut "SATRIA CBT": tab peramban, konsol Admin, dan laman
 * login menampilkan nama yang berbeda dari yang tertulis di `APP_NAME`.
 * Nama yang berbeda di tiga tempat bukan masalah selera - ia membuat
 * pengguna yang mencari aplikasi ini di layar beranda tidak menemukannya,
 * karena yang ia cari adalah nama yang ia lihat terakhir.
 *
 * `VITE_APP_NAME` diisi `.env` dari `APP_NAME`, dan cadangannya ditulis di
 * sini supaya nilai bawaan tidak tersebar ke setiap pemakainya.
 */
export const MEREK = import.meta.env.VITE_APP_NAME ?? 'SATRIA CBT';

/** Satu kalimat yang sama untuk deskripsi, pratinjau tautan, dan manifest. */
export const DESKRIPSI =
    'Computer based test untuk ujian sekolah: Admin, Guru, Pengawas, dan Siswa dalam satu aplikasi.';
