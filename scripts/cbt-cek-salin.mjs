/*
| cbt-cek-salin - tombol "Salin tautan" pada laman Kelas benar-benar bekerja.
|
| ---------------------------------------------------------------------------
| Yang tidak bisa dibuktikan Pest, dan tidak bisa dibuktikan tes unit
| ---------------------------------------------------------------------------
| Penyalinan ke papan klip adalah API PERAMBAN. Tidak ada satu pun test PHP
| yang bisa memastikannya, dan versi pertama fungsi ini gagal di pemakaian
| nyata karena dua jalur yang keduanya tidak berbunyi:
|
|   1. `document.execCommand('copy')` mengembalikan `false`, bukan melempar,
|      sehingga `try/catch` tidak menangkapnya - dan pesannya berbunyi
|      "disalin" untuk penyalinan yang gagal;
|   2. nilai `null` membuat fungsinya `return` diam-diam.
|
| Skrip ini menekan tombolnya di peramban sungguhan, lalu MEMBACA papan klip
| kembali. Yang dibandingkan bukan pesannya, melainkan isi papan klipnya.
|
| Pemakaian:
|   node scripts/cbt-cek-salin.mjs
|   CBT_BASE=http://127.0.0.1:8781 CBT_USER=dravide node ...
|
| Prasyarat: kelasnya sudah dinyalakan (`wali_slug` terisi) dan sekolahnya
| punya `papan_slug`. Skrip ini TIDAK menyalakan apa pun - ia hanya
| memeriksa yang sudah ada, dan menyebutkan apa yang kurang bila belum.
*/
import { BASE, bukaPeramban, bersihkanKeluar, masuk } from './cek-common.mjs';

const USER = process.env.CBT_USER ?? 'dravide';
const PASS = process.env.CBT_PASS ?? 'password';

const gagal = [];
const lapor = (ok, pesan) => {
    console.log(`${ok ? 'OK   ' : 'GAGAL'} ${pesan}`);

    if (!ok) {
        gagal.push(pesan);
    }
};

await bersihkanKeluar();

const { peramban, halaman } = await bukaPeramban();

try {
    await masuk(halaman, USER, PASS);

    await halaman.goto(`${BASE}/admin/kelas`, { waitUntil: 'networkidle0' });

    // Izin papan klip: tanpa ini `navigator.clipboard.readText()` ditolak,
    // dan penolakannya akan terbaca sebagai "penyalinannya gagal" -
    // persis kebingungan yang skrip ini ada untuk menghilangkannya.
    await peramban
        .defaultBrowserContext()
        .overridePermissions(BASE, ['clipboard-read', 'clipboard-write']);

    const tombol = await halaman.$('xpath/.//button[normalize-space(.)="Salin tautan"]');

    if (tombol === null) {
        lapor(false, 'tombol "Salin tautan" tidak ada - tidak satu pun kelas tayang.');
    } else {
        const mati = await halaman.evaluate((el) => el.disabled, tombol);

        if (mati) {
            lapor(
                false,
                'tombol "Salin tautan" MATI - sebabnya di title: ' +
                    (await halaman.evaluate((el) => el.title, tombol)),
            );
        } else {
            await tombol.click();
            await halaman.waitForTimeout(400);

            const isi = await halaman.evaluate(() => navigator.clipboard.readText());

            lapor(
                typeof isi === 'string' && isi.includes('/wali/'),
                `papan klip berisi tautan wali: ${JSON.stringify(isi)}`,
            );

            const pesan = await halaman.evaluate(() => {
                const p = [...document.querySelectorAll('p')].find((x) =>
                    (x.textContent ?? '').includes('disalin'),
                );

                return p?.textContent ?? null;
            });

            // Pesannya harus berbunyi "disalin", bukan "tidak bisa menyalin".
            // Versi pertama melaporkan yang KEDUA untuk penyalinan yang
            // BERHASIL di sebagian peramban, dan sebaliknya - itulah yang
            // membuat pesan saja tidak cukup sebagai bukti.
            lapor(pesan !== null, `pesan di layar: ${JSON.stringify(pesan)}`);
        }

        const tombolKode = await halaman.$('xpath/.//button[normalize-space(.)="Salin kode"]');

        if (tombolKode !== null && !(await halaman.evaluate((el) => el.disabled, tombolKode))) {
            await tombolKode.click();
            await halaman.waitForTimeout(400);

            const kode = await halaman.evaluate(() => navigator.clipboard.readText());

            lapor(
                /^\d{6}$/.test(kode ?? ''),
                `papan klip berisi kode enam angka: ${JSON.stringify(kode)}`,
            );
        }
    }
} catch (e) {
    lapor(false, `skrip berhenti: ${e.message}`);
} finally {
    await peramban.close();
}

if (gagal.length > 0) {
    console.log(`\n${gagal.length} pemeriksaan gagal.`);
    process.exit(1);
}

console.log('\nPapan klip bekerja.');
