/*
| cbt-cek-pintu - the room door, and the token hidden until it is asked for.
|
| ---------------------------------------------------------------------------
| The two claims, both about state rather than markup
| ---------------------------------------------------------------------------
|
|   1. THE DOOR DOES NOT OPEN WITHOUT THE RIGHT CODE.
|      A door that only ever opened with the right code would pass every
|      happiness test while being wide open, and the failure would surface as
|      a stranger in the room. So the check posts a wrong code, then an empty
|      one, and demands both be refused - and only then posts the right one.
|
|   2. THE TOKEN IS NOT IN THE DOM UNTIL IT IS ASKED FOR.
|      Pengawas/Token.tsx says this is what `cbt-cek-pintu` tests. It reads the
|      rendered HTML, not the props - props would contain the token whenever
|      the server decides to send it, which is exactly the distinction being
|      tested.
|
| ---------------------------------------------------------------------------
| Nothing here is hardcoded, because everything here is generated
| ---------------------------------------------------------------------------
| The slug is random per issue. The CODE, sejak 2026-11-08, tidak lagi ikut
| terbit bersama tautan: kodenya milik ORANG, tersimpan terenkripsi, dan
| SELALU terlihat di laman Kepanitiaan (karena dipakai membuat kartu
| pengawas). Karena itu kodenya dibaca dari sana - lewat pengawas yang
| DITUGASKAN di ruang itu, yang terdaftar di laman Pengawas event.
|
| Set CBT_KODE untuk memaksa satu kode tertentu (berguna ketika ruang itu
| belum punya pengawas yang ditugaskan).
|
| Re-issuing the link revokes the previous one, which is harmless on a seeded
| database.
|
| The room-session id is read from the Pengawas dashboard after the door opens,
| not from an env var: the door grants exactly one room, and which one depends
| on what was seeded.
|
| Usage: node scripts/cbt-cek-pintu.mjs
*/
import { BASE, bukaPeramban, laporkan, masuk } from './cek-common.mjs';

const KODE_SALAH = '000000';

const hasil = [];

function catat(jalur, ok, keluhan = []) {
    hasil.push({ jalur, ok, keluhan, status: 0, panjang: 0 });
}

/** Props Inertia dari laman yang sedang terbuka (Inertia 3: `<script data-page>`). */
async function props(halaman) {
    return halaman
        .evaluate(() => {
            const skrip = document.querySelector('script[data-page]');

            return skrip === null ? null : JSON.parse(skrip.textContent);
        })
        .then((data) => data?.props ?? {});
}

/**
 * Terbitkan tautan pintu baru untuk satu ruang.
 *
 * Slug-nya dibaca dari props laman rincian sesudahnya (`aktif.slug`), bukan
 * dari respons penerbitannya. `redirect: 'manual'` tetap dipakai supaya
 * pengalihan tidak diikuti di sini - laman dibuka ulang secara eksplisit di
 * pemanggilnya.
 */
async function terbitkan(halaman, ruangId) {
    return halaman.evaluate(async (ruangId) => {
        const token = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((c) => c.startsWith('XSRF-TOKEN='))
                ?.split('=')[1] ?? '',
        );

        const respons = await fetch(`/admin/ruang/${ruangId}/terbitkan`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/html, application/xhtml+xml',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': token,
            },
            body: '{}',
            redirect: 'manual',
        });

        return { status: respons.status, tujuan: respons.headers.get('location') };
    }, ruangId);
}

/**
 * Kode pengawas untuk satu ruang, dibaca dari laman Kepanitiaan.
 *
 * Kodenya tidak lagi ikut terbit bersama tautan - ia milik ORANG. Yang
 * menghubungkan ruang ke orangnya adalah PENUGASAN, dan penugasan itu terlihat
 * di `/admin/events/{event}/pengawas` sebagai daftar pengawas per baris ruang.
 * Jadi jalannya dua langkah: cari pengawas ruang itu di sana, lalu cari baris
 * kepanitiaan orang itu (dicocokkan lewat `akun`, yang berisi username-nya) dan
 * baca `kode` dari situ.
 *
 * `CBT_KODE` tetap ada untuk memaksa satu kode tertentu, dan memang dibutuhkan
 * ketika ruang itu belum punya pengawas sama sekali.
 */
async function bacaKodePengawas(halaman, kodeRuang) {
    const paksa = process.env.CBT_KODE ?? null;

    if (paksa !== null) {
        return paksa;
    }

    await halaman.goto(`${BASE}/admin/events`, { waitUntil: 'networkidle0' });

    // `baris` di laman daftar event adalah paginator, bukan array - `.map()`
    // atasnya melempar, dan galatnya menunjuk ke skrip ini alih-alih ke
    // bentuk propnya.
    const daftar = await props(halaman);
    const barisEvent = daftar.baris?.data ?? daftar.baris ?? [];
    const idEvent = barisEvent.map((b) => b.id);

    for (const id of idEvent) {
        await halaman.goto(`${BASE}/admin/events/${id}/pengawas`, { waitUntil: 'networkidle0' });

        const pengawas = await props(halaman);
        const barisRuang = (pengawas.baris ?? []).find((b) => b.ruang === kodeRuang);

        if (barisRuang === undefined) {
            continue;
        }

        const akun = barisRuang.pengawas?.[0]?.username ?? null;

        if (akun === null) {
            continue;
        }

        await halaman.goto(`${BASE}/admin/events/${id}/panitia`, { waitUntil: 'networkidle0' });

        const panitia = await props(halaman);
        const cocok = (panitia.panitia ?? []).find((p) => p.akun === akun && p.kode);

        if (cocok) {
            return cocok.kode;
        }
    }

    return null;
}

async function main() {
    const { peramban, halaman } = await bukaPeramban({ lebar: 420, tinggi: 900 });

    try {
        /* ------------------------------------------- 0. terbitkan tautan */

        await masuk(halaman, process.env.CBT_ADMIN ?? 'admin');

        const RUANG = process.env.CBT_ROOM ?? '1';

        await halaman.goto(`${BASE}/admin/ruang/${RUANG}`, { waitUntil: 'networkidle0' });

        await terbitkan(halaman, RUANG);

        // Membuka halaman rincian sekali lagi memberi slug yang sedang
        // berlaku, dan kode ruangnya untuk mencari kode pengawasnya di laman
        // Kepanitiaan.
        await halaman.goto(`${BASE}/admin/ruang/${RUANG}`, { waitUntil: 'networkidle0' });

        const rincian = await props(halaman);
        const slug = rincian.aktif?.slug ?? null;
        const kodeRuang = rincian.ruang?.kode ?? null;
        const kode = kodeRuang === null ? null : await bacaKodePengawas(halaman, kodeRuang);

        if (slug === null || kode === null) {
            catat(
                'Menerbitkan tautan pintu lewat /admin/ruang/{id}/terbitkan',
                false,
                [
                    `slug=${slug}, kode=${kode} (ruang ${kodeRuang}). ` +
                        'Tautannya harus berlaku, dan kode pengawas ruang itu harus ' +
                        'terbaca dari laman Kepanitiaan - setel CBT_KODE untuk memaksanya.',
                ],
            );

            await peramban.close();
            await laporkan('pintu', hasil);

            return;
        }

        catat(`Menerbitkan tautan pintu (${slug})`, true);

        // Pemegang tautan TIDAK punya akun: sesi Admin harus dilepas, atau
        // pengujiannya berjalan sebagai orang yang sudah masuk dan pintunya
        // tidak pernah benar-benar diuji.
        await halaman.deleteCookie(...(await halaman.cookies()));

        /* ------------------------------------------------ 1. pintunya */

        await halaman.goto(`${BASE}/p/${slug}`, { waitUntil: 'networkidle0' });

        const terbuka = await halaman.evaluate(
            () => (document.querySelector('#app')?.innerHTML.length ?? 0) > 300,
        );

        catat(
            `GET /p/${slug}`,
            terbuka,
            terbuka ? [] : ['Laman pintu tidak merender apa pun'],
        );

        const masihDiPintu = () => halaman.url().includes('/p/');

        // Kode salah. Enam angka yang pasti bukan kodenya, DIKETIK lewat form
        // - bukan diposting langsung - karena yang diuji justru jalur yang
        // dilalui pengawas di depan pintu.
        await halaman.type('input[inputmode="numeric"]', KODE_SALAH);

        const tombolSalah = await halaman.$('button[type="submit"]');

        if (tombolSalah !== null) {
            await Promise.all([
                halaman.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}),
                halaman.click('button[type="submit"]'),
            ]);
        }

        catat(
            `POST /p/${slug} - kode salah ${KODE_SALAH}`,
            masihDiPintu(),
            masihDiPintu() ? [] : ['Pintu TERBUKA dengan kode yang salah'],
        );

        // Kosong. Tombolnya memang nonaktif sampai enam digit, jadi ini
        // memeriksa hal yang berbeda: bahwa NONAKTIFNYA tombol bukan satu-
        // satunya penjaga. Kalau kelak tombolnya dibuat selalu aktif, server
        // tetap harus menolak.
        await halaman.goto(`${BASE}/p/${slug}`, { waitUntil: 'networkidle0' });

        const nonaktif = await halaman.evaluate(() => {
            const tombol = document.querySelector('button[type="submit"]');

            return tombol ? tombol.disabled : null;
        });

        catat(
            'Tombol pintu nonaktif sebelum enam digit',
            nonaktif === true,
            nonaktif === true ? [] : [`Tombol aktif dengan kolom kosong (disabled=${nonaktif})`],
        );

        /* -------------------------------------- 2. kode yang sebenarnya */

        await halaman.type('input[inputmode="numeric"]', kode);

        await Promise.all([
            halaman.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}),
            halaman.click('button[type="submit"]').catch(() => {}),
        ]);

        const masukRuang = !halaman.url().includes('/p/');

        catat(`POST /p/${slug} - kode benar`, masukRuang, masukRuang ? [] : [
            'Pintu TIDAK terbuka dengan kode yang benar',
        ]);

        if (!masukRuang) {
            await peramban.close();
            await laporkan('pintu', hasil);

            return;
        }

        /* ------------------------------------------ 3. token tersembunyi */

        /*
        | Muat ulang penuh SEBELUM membaca props.
        |
        | Pintu membuka lewat `useForm().post()`, jadi pengalihannya ditangani
        | Inertia di dalam dokumen yang sama: alamatnya berubah, isinya
        | berubah, tetapi `<script data-page>` masih milik dokumen LAMA.
        | Membacanya di sini akan mengembalikan props halaman pintu - dan
        | pemeriksaan ini lalu melaporkan "tidak ada ruang" pada dasbor yang
        | justru sedang menampilkan ruangnya.
        */
        await halaman.reload({ waitUntil: 'networkidle0' });

        // Ruang-sesinya dibaca dari dasbor pengawas yang baru terbuka, bukan
        // ditebak: pintu hanya menghibahkan SATU ruang, dan ruang mana itu
        // ditentukan data seed.
        const dasbor = await props(halaman);
        const ruangSesi = dasbor.ruang?.[0]?.ruang_id ?? null;

        if (ruangSesi === null) {
            catat('Membaca ruang sesi dari dasbor pengawas', false, [
                'Dasbor tidak memuat satu pun ruang - pintu terbuka tanpa menghibahkan ruang.',
            ]);

            await peramban.close();
            await laporkan('pintu', hasil);

            return;
        }

        catat(`Dasbor pengawas menghibahkan ruang-sesi ${ruangSesi}`, true);

        await halaman.goto(`${BASE}/pengawas/ruang/${ruangSesi}/token`, {
            waitUntil: 'networkidle0',
        });

        const sebelum = await halaman.evaluate(() => document.querySelector('#app').innerHTML);
        const adaSebelum = /\b\d{6}\b/.test(sebelum);

        catat(
            'Token tidak ada di DOM sebelum tombolnya ditekan',
            !adaSebelum,
            adaSebelum
                ? ['Token sudah ada di DOM sejak halaman dibuka - ia terbaca siapa pun yang melihat layarnya']
                : [],
        );

        // Tombolnya memicu POST, dan yang membuka tampilannya adalah flash
        // pada respons berikutnya. Jadi harus IKUT menunggu responsnya -
        // menekan lalu segera membaca DOM memeriksa layar yang belum berubah.
        await Promise.all([
            halaman.waitForResponse(
                (r) => r.url().includes('/token/baca') && r.request().method() === 'POST',
                { timeout: 10_000 },
            ).catch(() => {}),
            halaman.evaluate(() => {
                const tombol = [...document.querySelectorAll('button')].find((b) =>
                    b.textContent.trim().startsWith('Tampilkan'),
                );

                tombol?.click();
            }),
        ]);

        await halaman.waitForFunction(
            () => /\b\d{6}\b/.test(document.querySelector('#app')?.innerHTML ?? ''),
            { timeout: 5_000 },
        ).catch(() => {});

        const sesudah = await halaman.evaluate(() => document.querySelector('#app').innerHTML);
        const adaSesudah = /\b\d{6}\b/.test(sesudah);

        catat(
            'Token muncul di DOM setelah tombolnya ditekan',
            adaSesudah,
            adaSesudah
                ? []
                : ['Token tidak pernah muncul meski tombolnya ditekan - pengawas tidak bisa membacakan token'],
        );
    } catch (e) {
        catat('menjalankan pemeriksaan', false, [e.message]);
    } finally {
        await peramban.close();
    }

    await laporkan('pintu', hasil);
}

await main();
