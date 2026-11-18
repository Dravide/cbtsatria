/*
| cbt-cek-lomba - lomba dua pemain berjalan di peramban sungguhan.
|
| ---------------------------------------------------------------------------
| Yang diperiksa di sini dan tidak bisa diperiksa Pest
| ---------------------------------------------------------------------------
| Pest sudah membuktikan `/siswa/luang/lomba` menjawab 200 dengan prop yang
| benar, dan bahwa POST-nya mengalihkan ke alamat yang benar. Yang TIDAK
| bisa dilihat Pest adalah apakah React-nya ikut berubah - dan di sinilah
| bug pertamanya muncul: `/siswa/luang/lomba` dan `/siswa/luang/lomba/{kode}`
| merender komponen yang SAMA, sehingga Inertia memakai ulang instance lama
| dan `useState(awal)` mengabaikan prop yang baru. Alamatnya berubah, layarnya
| tidak, sampai halaman dimuat ulang paksa.
|
| Skrip ini menjalankan dua peramban senyata mungkin: satu membuat undangan,
| satu menerima kodenya, lalu keduanya bermain sampai skor terkunci.
|
| Pemakaian:
|   node scripts/cbt-cek-lomba.mjs
|   CBT_SISWA_A=26277001 CBT_SISWA_B=26277002 node scripts/cbt-cek-lomba.mjs
|
| Prasyarat: aplikasi berjalan, basis data berisi siswa yang jendela
| luangnya terbuka. `public/hot` boleh ada (Vite dev) atau tidak (build).
*/
import { BASE, bukaPeramban, hapusSesi, masuk, simpanBukti } from './cek-common.mjs';

const SISWA_A = process.env.CBT_SISWA_A ?? '26277001';
const SISWA_B = process.env.CBT_SISWA_B ?? '26277002';

let gagal = 0;

function cek(syarat, pesan) {
    if (syarat) {
        console.log(`  ok   ${pesan}`);
        return;
    }

    gagal++;
    console.log(`  GAGAL ${pesan}`);
}

/** Teks yang benar-benar terlihat di layar, tanpa tag. */
async function teks(halaman) {
    return halaman.$eval('#app', (el) => el.innerText);
}

/**
 * Menunggu sepotong teks muncul, TANPA peka huruf besar-kecil.
 *
 * `innerText` mengembalikan teks sesudah `text-transform`, dan tombol di
 * aplikasi ini memakai `uppercase`. Mencocokkan "Buat undangan" terhadap
 * "BUAT UNDANGAN" akan gagal pada layar yang sebenarnya benar.
 */
async function tungguTeks(halaman, potongan, batasMs = 15000) {
    const cari = potongan.toLowerCase();
    const mulai = Date.now();

    while (Date.now() - mulai < batasMs) {
        if ((await teks(halaman)).toLowerCase().includes(cari)) {
            return true;
        }

        await new Promise((r) => setTimeout(r, 250));
    }

    return false;
}

/**
 * Catat setiap jawaban 4xx/5xx pada jalur lomba, beserta sebabnya.
 *
 * Tanpa ini, yang terlihat di konsol hanyalah "Failed to load resource: 422"
 * - dan angka itu tidak menyebut kolom mana yang ditolak.
 */
function pantauGalat(halaman, nama) {
    halaman.on('response', async (r) => {
        if (!r.url().includes('/luang/lomba') || r.status() < 400) {
            return;
        }

        let isi = '';

        try {
            isi = (await r.text()).slice(0, 300);
        } catch {
            // Jawaban 3xx tidak punya badan yang bisa dibaca.
        }

        console.log(`  [${nama}] ${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`);
        console.log(`        kirim: ${(r.request().postData() ?? '').slice(0, 200)}`);
        console.log(`        jawab: ${isi}`);
    });
}

const A = await bukaPeramban({ lebar: 430, tinggi: 900 });
const B = await bukaPeramban({ lebar: 430, tinggi: 900 });

pantauGalat(A.halaman, 'A');
pantauGalat(B.halaman, 'B');

try {
    console.log('\n== Masuk sebagai dua siswa ==');
    await masuk(A.halaman, SISWA_A);
    await masuk(B.halaman, SISWA_B);
    console.log(`  ok   ${SISWA_A} dan ${SISWA_B} masuk`);

    console.log('\n== A membuka ruang lomba ==');
    await A.halaman.goto(`${BASE}/siswa/luang/lomba`, { waitUntil: 'networkidle0' });
    cek(await tungguTeks(A.halaman, 'Buat undangan'), 'ruang tunggu tampil dengan tombol Buat undangan');

    console.log('\n== A menekan Buat undangan ==');
    // INI yang gagal sebelum perbaikan: alamat berubah, layar tidak.
    await A.halaman.click('button ::-p-text(Buat undangan)');

    cek(
        await tungguTeks(A.halaman, 'Kode undangan', 10000),
        'layar berganti ke ruang tunggu undangan TANPA muat ulang',
    );

    // Dicari lewat BENTUK kodenya, bukan lewat kelas atau posisi DOM: yang
    // diperiksa adalah "ada enam karakter dari alfabet yang benar di layar",
    // dan itu tetap benar meski tata letaknya berubah.
    //
    // `waitForFunction` lebih dulu, bukan `$eval` langsung: `tungguTeks` di
    // atas hanya membuktikan LABELNYA sudah ada, dan label itu dirender
    // beberapa milidetik sebelum kodenya. Membaca terlalu cepat
    // mengembalikan string kosong yang terlihat seperti kode yang gagal
    // dibuat.
    await A.halaman.waitForFunction(
        () =>
            [...document.querySelectorAll('#app b')].some((el) =>
                /^[A-Z0-9]{6}$/.test(el.textContent.trim()),
            ),
        { timeout: 10000 },
    );

    const kode = await A.halaman.evaluate(() => {
        const cocok = [...document.querySelectorAll('#app b')]
            .map((el) => el.textContent.trim())
            .find((t) => /^[A-Z0-9]{6}$/.test(t));

        return cocok ?? '';
    });

    cek(kode !== '', `kode undangan terbaca: ${kode || '(kosong)'}`);
    cek(/^[A-Z0-9]{6}$/.test(kode), 'kode undangan enam karakter');
    cek(!/[IO01LZ5]/.test(kode), 'kode tidak memuat huruf/angka yang mudah tertukar');

    console.log('\n== B memasukkan kode ==');
    await B.halaman.goto(`${BASE}/siswa/luang/lomba`, { waitUntil: 'networkidle0' });
    await tungguTeks(B.halaman, 'Masukkan kode');

    await B.halaman.type('input[inputmode="text"]', kode);
    await B.halaman.click('button ::-p-text(Masuk)');

    cek(
        await tungguTeks(B.halaman, 'melawan', 10000),
        'B masuk ke lomba dan melihat nama kedua pemain',
    );

    console.log('\n== Hitung mundur berjalan ==');
    // A HARUS ikut berganti sendiri - inilah bug aslinya. Kode undangan yang
    // masih tampil di A berarti Inertia memakai ulang komponennya dan prop
    // baru diabaikan.
    const aBerganti = await tungguTeks(A.halaman, 'Bersiap', 12000);

    cek(aBerganti, 'A ikut berganti ke hitung mundur TANPA muat ulang');
    cek(!(await teks(A.halaman)).includes('Kode undangan'), 'kode undangan A sudah hilang dari layar');

    console.log('\n== Enam puluh detik bermain ==');
    cek(await tungguTeks(A.halaman, 'dari 60', 25000), 'A melihat soal pertama');
    cek(await tungguTeks(B.halaman, 'dari 60', 25000), 'B melihat soal pertama');

    // Menjawab terus sampai waktunya habis. Yang diperiksa bukan skornya,
    // melainkan bahwa layarnya BERGANTI sendiri ke hasil saat waktunya
    // habis - satu-satunya bagian yang bergantung pada jam server.
    const mulai = Date.now();
    const batas = mulai + 90000;

    while (Date.now() < batas) {
        const isi = await teks(A.halaman);

        if (isi.includes('menang') || isi.includes('Seri')) {
            break;
        }

        // Empat tombol terakhir di layar adalah empat pilihan jawaban.
        const tombol = await A.halaman.$$('button');

        if (tombol.length >= 4) {
            const pilih = tombol[tombol.length - 1 - Math.floor(Math.random() * 4)];
            await pilih.click().catch(() => {});
        }

        await new Promise((r) => setTimeout(r, 150));
    }

    // `menang` atau `Seri` hanya muncul di layar HASIL - bukan di layar
    // bermain, bukan di ruang tunggu. Itu yang membuatnya jadi bukti bahwa
    // layarnya berganti sendiri, bukan sekadar bahwa lamannya masih hidup.
    const diHasil = (await teks(A.halaman)).match(/menang|Seri/) !== null;

    cek(diHasil, 'A berganti sendiri ke layar hasil sesudah 60 detik');
    cek(
        !(await teks(A.halaman)).includes('= ?'),
        'soal sudah tidak tampil - lomba benar-benar berakhir',
    );

    // Skor dikirim sesudah fase `selesai`; server butuh satu putaran denyut
    // untuk menampilkannya kembali.
    await new Promise((r) => setTimeout(r, 4000));

    const hasilA = await teks(A.halaman);
    const angka = hasilA.match(/\d[\d.]*/);

    cek(angka !== null, `skor A tampil sebagai angka (${angka?.[0] ?? 'tidak ada'})`);
    cek(!hasilA.includes('Skor Anda belum tercatat'), 'skor A tercatat di server, bukan lingkaran kosong');

    await simpanBukti(A.halaman, 'lomba-a');
    await simpanBukti(B.halaman, 'lomba-b');

    if (A.galat.length > 0) {
        console.log('\nGalat konsol A:');
        A.galat.forEach((g) => console.log('  ' + g));
        gagal += A.galat.length;
    }

    if (B.galat.length > 0) {
        console.log('\nGalat konsol B:');
        B.galat.forEach((g) => console.log('  ' + g));
        gagal += B.galat.length;
    }
} catch (e) {
    gagal++;
    console.error('\nGagal di tengah jalan: ' + e.message);
} finally {
    await hapusSesi(A.halaman).catch(() => {});
    await hapusSesi(B.halaman).catch(() => {});
    await A.peramban.close();
    await B.peramban.close();
}

console.log(gagal === 0 ? '\nSEMUA LOLOS' : `\n${gagal} PEMERIKSAAN GAGAL`);
process.exit(gagal === 0 ? 0 : 1);
