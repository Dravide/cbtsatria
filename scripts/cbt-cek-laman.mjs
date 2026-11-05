/*
| cbt-cek-laman - every page opens and React actually renders it.
|
| ---------------------------------------------------------------------------
| What this covers that `php artisan test` cannot
| ---------------------------------------------------------------------------
| Pest proves the routes answer 200 with the right props. It cannot see the
| browser, and the browser is where a component that throws during render
| produces a perfectly good 200 with a blank page underneath.
|
| Usage:
|   node scripts/cbt-cek-laman.mjs              # as Admin, the seeded set
|   node scripts/cbt-cek-laman.mjs --guru       # as Guru1
|   node scripts/cbt-cek-laman.mjs --pengawas   # as Pengawas1 (portal)
|   node scripts/cbt-cek-laman.mjs --siswa      # as Siswa 1 (Fase 7)
|
| The seeded ids live in `database/seeders/DatabaseSeeder.php`. They are read
| from env with the seeded values as defaults, so a database seeded
| differently does not mean editing this file.
|
| Two of those defaults are WRONG for any database but the one they were read
| from, and both fail with a 404 that is itself correct:
|
|   CBT_SLUG    - the room door slug, randomly regenerated on every seed.
|                 Read the `Pengawas: buka .../p/<slug>` line that `db:seed`
|                 prints and pass it in.
|   CBT_SISWA_* - the student usernames. Those are the NIS numbers and are
|                 stable, but the {peserta} ids they map to move with the
|                 seeding order; check `exam_participants` if they drift.
|
| Prerequisite: the app must be running AND backed by the SEEDED database.
| `public/hot` must be moved aside, otherwise Vite's dev server must also be
| up. Both are checked below with a message that says which one is wrong -
| a check that fails with "ECONNREFUSED" teaches nobody anything.
|
| Note that `CBT_BASE` is useful here for the same reason: the dev database
| and the seeded one are not always the same database, and pointing this at
| the wrong one produces a screenful of 404s that look like broken pages.
| `DB_DATABASE=cbt_satria_test php artisan serve --port=8783` runs a second
| server against the seeded test database without disturbing the first.
*/
import { BASE, bersihkanKeluar, bukaPeramban, hapusSesi, laporkan, masuk, periksa, simpanBukti } from './cek-common.mjs';

const PERAN = process.argv.includes('--guru')
    ? 'guru'
    : process.argv.includes('--pengawas')
      ? 'pengawas'
      : process.argv.includes('--siswa')
        ? 'siswa'
        : 'admin';

const EVENT = process.env.CBT_EVENT ?? '1';
const EVENT_KOSONG = process.env.CBT_EVENT_KOSONG ?? '2';
const SUBJECT = process.env.CBT_SUBJECT ?? '1';
const PACKAGE = process.env.CBT_PACKAGE ?? '1';
const SOAL = process.env.CBT_SOAL ?? '1';
const MEDIA = process.env.CBT_MEDIA ?? '1';
const BATCH = process.env.CBT_BATCH ?? '1';
const KELAS = process.env.CBT_KELAS ?? '1';
const SESI = process.env.CBT_SESI ?? '1';
const ROOM = process.env.CBT_ROOM ?? '1';

/**
 * `{ruang}` di portal pengawas adalah id RUANG-SESI (`exam_session_rooms`),
 * bukan id ruang. Dua hal yang berbeda, dan namanya di rute tidak membedakan
 * keduanya: `/admin/ruang/{id}` memakai `rooms.id`, sementara
 * `/pengawas/ruang/{id}` memakai `exam_session_rooms.id` - karena satu ruang
 * fisik muncul tiga kali bila dipakai tiga sesi, dan pengawas sesi pagi belum
 * tentu pengawas sesi siang.
 *
 * Nilai bawaannya 2: pengawas1 di seeder duduk di ruang-sesi 2, 3, dan 6.
 * Nilai yang salah di sini berakhir 404, dan 404-nya benar - jadi angka ini
 * harus dibaca dari seed, bukan ditebak.
 */
const RUANG_SESI = process.env.CBT_RUANG_SESI ?? '2';

/**
 * Slug pintu ruang - dan angka bawaannya TIDAK bisa dipercaya.
 *
 * Ia diacak setiap kali seedernya berjalan (`r-01-wngh`, `r-01-dobb`,
 * `r-01-e9iy` - tiga nilai berbeda dari tiga kali seed), jadi tidak ada
 * nilai bawaan yang benar untuk lebih dari satu basis data. Nilai yang
 * salah berakhir 404, dan 404 itu memang benar.
 *
 * Yang membacanya: baris `Pengawas: buka http://.../p/<slug> kode <kode>`
 * yang dicetak `db:seed`. Ambil dari sana, lalu:
 *
 *   CBT_SLUG=<slug> node scripts/cbt-cek-laman.mjs --siswa
 */
const SLUG = process.env.CBT_SLUG ?? 'r-01-wngh';
const UJIAN = process.env.CBT_UJIAN ?? '1';


/**
 * Laman yang dibuka SEBELUM masuk - dan sengaja ikut di sini.
 *
 * Laman tamu adalah laman yang paling jarang dibuka ulang oleh pengembang
 * yang sudah masuk, sehingga justru yang paling sering rusak diam-diam.
 */
const TAMU = ['/login', '/lupa-password', `/p/${SLUG}`];

const ADMIN = [
    '/admin',
    '/admin/sekolah',
    '/admin/pengaturan',
    '/admin/log',
    '/admin/pengguna',
    '/admin/tahun-ajaran',
    '/admin/kelas',
    '/admin/kelas/' + KELAS + '/anggota',
    '/admin/mapel',
    '/admin/ruang',
    '/admin/ruang/' + ROOM,
    '/admin/events',
    '/admin/events/create',
    `/admin/events/${EVENT}`,
    `/admin/events/${EVENT}/ubah`,
    `/admin/events/${EVENT}/panitia`,
    `/admin/events/${EVENT}/peserta`,
    `/admin/events/${EVENT}/mapel`,
    `/admin/events/${EVENT}/jadwal`,
    `/admin/events/${EVENT}/pengawas`,
    `/admin/events/${EVENT}/token`,
    `/admin/events/${EVENT}/presensi`,
    `/admin/events/${EVENT}/monitoring`,
    // Event kedua sengaja TIDAK lengkap (Fase 3): laman ringkasannya harus
    // tetap terbuka dan tetap menunjukkan syarat yang belum terpenuhi.
    `/admin/events/${EVENT_KOSONG}`,
    `/admin/events/${EVENT_KOSONG}/mapel`,
];

const GURU = [
    '/guru',
    '/guru/bank-soal',
    '/guru/bank-soal/baru',
    `/guru/bank-soal/${SOAL}/ubah`,
    `/guru/bank-soal/${SOAL}/pratinjau`,
    '/guru/bank-soal/impor',
    `/guru/berkas/${MEDIA}`,
    '/guru/paket',
    `/guru/paket/${PACKAGE}`,
    `/guru/paket/${PACKAGE}/pratinjau`,
];

const PENGAWAS = [
    '/pengawas',
    `/pengawas/ruang/${RUANG_SESI}/presensi`,
    `/pengawas/ruang/${RUANG_SESI}/koreksi`,
    `/pengawas/ruang/${RUANG_SESI}/token`,
    `/pengawas/ruang/${RUANG_SESI}/monitoring`,
    `/pengawas/ruang/${RUANG_SESI}/berita-acara`,
];

/**
 * Konsol siswa - Fase 7.
 *
 * =====================================================================
 * yang membedakan laman bukan URL-nya, melainkan keadaan attempt-nya
 * =====================================================================
 * Empat alamat `/siswa/ujian/{peserta}/...` sengaja dibuka dengan peserta
 * BERBEDA, karena masing-masing laman gerbang keadaannya sendiri:
 *
 *   gerbang  - belum mulai. Attempt yang sudah berjalan akan dialihkan ke
 *              laman pengerjaan, jadi peserta yang sudah mulai tidak bisa
 *              dipakai membuka laman ini.
 *   kerjakan - attempt berjalan (plate 22-23).
 *   terkunci - attempt terkunci (plate 25).
 *   selesai  - attempt yang sudah dikumpulkan (plate 26-27).
 *
 * Membukanya dengan satu peserta yang sama akan membuat tiga dari empat
 * laman mengalihkan, dan pemeriksaan ini akan melaporkan "lolos" untuk
 * laman yang sebenarnya tidak pernah dirender.
 *
 * ---------------------------------------------------------------------
 * Dan karena itu setiap baris membawa akunnya sendiri
 * ---------------------------------------------------------------------
 * `SiswaDasar::milikSaya()` menolak peserta yang bukan milik siswa yang
 * sedang masuk - 404, bukan 403. Satu akun tidak bisa membuka keempat
 * keadaan itu, karena keempatnya memang milik empat anak berbeda. Baris
 * yang salah akun menghasilkan 404, dan 404 itu BENAR; yang salah adalah
 * pemeriksanya.
 *
 * Nama akun diambil dari `username` siswa di seeder, yaitu NIS-nya.
 *
 * ---------------------------------------------------------------------
 * Berkas soal ikut diperiksa, dan itu bukan HTML
 * ---------------------------------------------------------------------
 * Sama seperti `/guru/berkas/{media}`, alamat ini mengirim gambar. Yang
 * diperiksa adalah status dan tipe isinya, bukan `#app` - lihat `BUKAN_HTML`.
 */
const SISWA_BERJALAN = process.env.CBT_SISWA_BERJALAN ?? '2024001';
const SISWA_DIKUNCI = process.env.CBT_SISWA_DIKUNCI ?? '2024011';
const SISWA_DIKIRIM = process.env.CBT_SISWA_DIKIRIM ?? '2024012';
const SISWA_BELUM = process.env.CBT_SISWA_BELUM ?? '2024002';

const PESERTA = process.env.CBT_PESERTA ?? '1';
const PESERTA_TERKUNCI = process.env.CBT_PESERTA_TERKUNCI ?? '11';
const PESERTA_SELESAI = process.env.CBT_PESERTA_SELESAI ?? '12';
const PESERTA_GERBANG = process.env.CBT_PESERTA_GERBANG ?? '2';

/**
 * Id butir snapshot yang membawa berkas, dan id berkasnya.
 *
 * Nilai bawaannya 9, bukan 1: rute `/siswa/ujian/{peserta}/berkas/{soal}/{media}`
 * menolak berkas yang tidak tercantum di `media_json` butir itu
 * (`ExamMediaSiswaController::tercantumDiSnapshot()`), dan seeder menaruh
 * gambar di butir KESEMBILAN - butir media, satu-satunya jenis yang membawa
 * berkas. Menebaknya sebagai butir 1 menghasilkan 404, dan 404 itu benar;
 * yang salah adalah angka di sini.
 */
const SOAL_MEDIA = process.env.CBT_SOAL_MEDIA ?? '9';

const SISWA = [
    // Laman konsol sendiri - dibuka oleh satu akun saja, karena isinya
    // memang milik satu anak.
    { jalur: '/siswa', akun: SISWA_BERJALAN },
    { jalur: '/siswa/jadwal', akun: SISWA_BERJALAN },
    { jalur: '/siswa/nilai', akun: SISWA_BERJALAN },
    { jalur: '/siswa/pelanggaran', akun: SISWA_BERJALAN },
    { jalur: `/siswa/ujian/${PESERTA}/kerjakan`, akun: SISWA_BERJALAN },
    { jalur: `/siswa/ujian/${PESERTA}/berkas/${SOAL_MEDIA}/${MEDIA}`, akun: SISWA_BERJALAN },
    { jalur: `/siswa/ujian/${PESERTA_GERBANG}/gerbang`, akun: SISWA_BELUM },
    { jalur: `/siswa/ujian/${PESERTA_TERKUNCI}/terkunci`, akun: SISWA_DIKUNCI },
    { jalur: `/siswa/ujian/${PESERTA_SELESAI}/selesai`, akun: SISWA_DIKIRIM },
];

const AKUN = { admin: 'admin', guru: 'guru1', pengawas: 'pengawas1' };

// Berkas media adalah PNG - laman gambar, bukan dokumen HTML - sehingga
// pemeriksaan `#app` tidak berlaku untuknya. Ia tetap dibuka untuk memastikan
// rutenya hidup, dengan `minimal: 0` dan pemeriksaan status saja.
const BUKAN_HTML = /\/(guru|siswa)\/(ujian\/\d+\/)?berkas\//;

async function main() {
    await bersihkanKeluar();

    const { peramban, halaman } = await bukaPeramban();
    const hasil = [];

    try {
        for (const jalur of TAMU) {
            hasil.push(await periksa(halaman, jalur, { minimal: 200 }));
        }

        // Berkas media TIDAK menuntut sesi Guru di rute tamu - ia ada di grup
        // `/guru`. Yang diperiksa di sini adalah bentuk laman, sehingga ia
        // dibuka setelah masuk, bersama sisanya.
        const daftar = (PERAN === 'admin'
            ? ADMIN
            : PERAN === 'guru'
              ? GURU
              : PERAN === 'pengawas'
                ? PENGAWAS
                : SISWA
        ).map((baris) => (typeof baris === 'string'
            ? { jalur: baris, akun: AKUN[PERAN] ?? null }
            : baris));

        let akunSekarang = null;

        for (const { jalur, akun } of daftar) {
            // Ganti akun berarti keluar dulu. Mengunjungi `/login` saat masih
            // masuk akan mengalihkan ke beranda, dan formulir yang tidak pernah
            // muncul membuat `halaman.type()` gagal dengan pesan yang menunjuk
            // ke selektor - bukan ke sebabnya. Cookie dibersihkan, bukan
            // diklik-keluar: satu permintaan lebih sedikit, dan tidak
            // bergantung pada letak tombol keluar.
            if (akun !== null && akun !== akunSekarang) {
                await hapusSesi(halaman);
                await masuk(halaman, akun);
                akunSekarang = akun;
            }

            const opsi = BUKAN_HTML.test(jalur) ? { minimal: 0 } : {};

            const hasilSatu = await periksa(halaman, jalur, opsi);

            // Berkas media harus benar-benar berupa gambar. Status 200 dengan
            // badan JSON galat adalah hal yang mungkin, dan tanpa pemeriksaan
            // ini ia lolos sebagai "lolos".
            if (BUKAN_HTML.test(jalur) && hasilSatu.status === 200) {
                const tipe = await halaman.evaluate(() => document.contentType);

                if (!tipe.startsWith('image/')) {
                    hasilSatu.keluhan.push(`content-type ${tipe}, bukan image/*`);
                    hasilSatu.ok = false;
                }
            }

            if (!hasilSatu.ok) {
                await simpanBukti(halaman, jalur);
            }

            // Akunnya ikut dicatat: 404 pada laman siswa hampir selalu berarti
            // "peserta ini bukan milik akun itu", dan laporan yang tidak
            // menyebut akunnya membuat orang menuduh rutenya.
            hasil.push({ ...hasilSatu, akun: akun ?? '-' });
        }
    } catch (e) {
        console.error(`\nGagal menjalankan pemeriksaan: ${e.message}`);
        console.error(`Pastikan aplikasi berjalan di ${BASE} dan basis datanya sudah di-seed.`);
        process.exitCode = 1;

        return;
    } finally {
        await peramban.close();
    }

    await laporkan(`laman-${PERAN}`, hasil);
}

await main();
