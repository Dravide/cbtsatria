/*
| cbt-cek-bentrok - the same supervisor cannot stand in two rooms at once.
|
| ---------------------------------------------------------------------------
| What this covers that an HTTP test does not
| ---------------------------------------------------------------------------
| PengawasService::tugaskan() throws on a clash, and the unit path is covered by
| Pest. What Pest never exercises is the round trip the Admin actually makes:
| the panel on `/admin/events/{id}/pengawas` posting a supervisor the server
| then refuses, and the page telling them why. A refusal that arrives as a
| blank page or a silent no-op is a refusal the Admin does not learn from.
|
| ---------------------------------------------------------------------------
| How it learns the current state without querying MySQL
| ---------------------------------------------------------------------------
| Inertia embeds the page props in `data-page` on the root element, so the
| already-rendered Admin page IS the state: every room-session row, every
| supervisor already standing there, and which session each row belongs to.
| Reading MySQL directly would mean a second source of truth about what the
| screen shows.
|
| The check performs two posts and then puts the room back the way it found
| it, so it is safe to run repeatedly against a seeded database:
|
|   1. An appointment that MUST be refused - a supervisor already standing in
|      another room whose session window overlaps.
|   2. An appointment that MUST be accepted - a free candidate in the same
|      room - followed by an immediate delete.
|
| Usage: node scripts/cbt-cek-bentrok.mjs
*/
import { BASE, bukaPeramban, laporkan, masuk } from './cek-common.mjs';

const EVENT = process.env.CBT_EVENT ?? '1';

/**
 * Baca props Inertia dari laman yang sedang terbuka.
 *
 * Inertia 3 TIDAK lagi menaruh JSON-nya di `data-page` milik `<div id="app">`
 * seperti versi lama. Ia memisahkannya menjadi elemen `<script data-page
 * type="application/json">` di sebelah div kosong itu - lihat
 * `vendor/inertiajs/inertia-laravel/src/Directive.php`. Membaca atribut div
 * menghasilkan `undefined`, dan galatnya muncul sebagai "undefined is not
 * valid JSON" dari tempat yang tidak menyebut Inertia sama sekali.
 */
async function props(halaman) {
    return halaman.evaluate(() => {
        const skrip = document.querySelector('script[data-page]');

        return skrip === null ? null : JSON.parse(skrip.textContent);
    }).then((halamanData) => halamanData?.props ?? {});
}

/**
 * Kirim POST lewat `fetch` dari dalam laman, lalu BACA props dari responsnya.
 *
 * `page.click` tidak dipakai karena panelnya modal dengan pemilih yang
 * berubah antar versi markup - dan pemeriksaan yang rusak setiap kali markup
 * berubah adalah pemeriksaan yang berhenti dipakai.
 *
 * Props dibaca dari BADAN respons, bukan dengan membuka ulang lamannya.
 * Alasannya menentukan: `back()->with('galat', ...)` menaruh pesannya di
 * flash, dan flash hidup TEPAT SATU respons. Membuka ulang laman berarti
 * melewatkan satu respons penuh, dan pesan yang justru sedang diperiksa
 * sudah menguap - pemeriksaannya lalu melaporkan "alasan tidak sampai ke
 * layar" padahal alasannya sampai, hanya saja sudah terpakai.
 */
async function kirim(halaman, jalur, badan) {
    return halaman.evaluate(
        async (jalur, badan) => {
            const bacaToken = () =>
                decodeURIComponent(
                    document.cookie
                        .split('; ')
                        .find((c) => c.startsWith('XSRF-TOKEN='))
                        ?.split('=')[1] ?? '',
                );

            const respons = await fetch(jalur, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/html, application/xhtml+xml',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': bacaToken(),
                },
                body: JSON.stringify(badan),
                redirect: 'follow',
            });

            const teks = await respons.text();

            // Respons bisa berupa HTML penuh (pengalihan yang diikuti) atau
            // JSON Inertia bila `X-Inertia` terkirim. Keduanya ditangani
            // supaya harness tidak diam-diam berhenti memeriksa saat bentuk
            // responsnya berubah.
            let props = null;

            try {
                const dok = new DOMParser().parseFromString(teks, 'text/html');
                const skrip = dok.querySelector('script[data-page]');

                if (skrip !== null) {
                    props = JSON.parse(skrip.textContent).props;
                }
            } catch {
                // Bukan HTML ber-Inertia - biarkan `props` null, pemanggil
                // yang memutuskan artinya.
            }

            if (props === null) {
                try {
                    props = JSON.parse(teks).props;
                } catch {
                    props = null;
                }
            }

            return { status: respons.status, props };
        },
        jalur,
        badan,
    );
}

async function main() {
    const { peramban, halaman } = await bukaPeramban();
    const hasil = [];

    try {
        await masuk(halaman, process.env.CBT_ADMIN ?? 'admin');
        await halaman.goto(`${BASE}/admin/events/${EVENT}/pengawas`, { waitUntil: 'networkidle0' });

        const p = await props(halaman);
        const baris = p.baris ?? [];
        const calon = p.calon ?? [];

        // Ruang yang sudah berisi pengawas DAN punya sesi - dua-duanya perlu:
        // yang pertama memberi tahu siapa yang bentrok, yang kedua bahwa ada
        // jendela waktu untuk bentrok.
        const berisi = baris.filter((b) => (b.pengawas ?? []).length > 0 && b.sesi_id !== null);

        if (berisi.length < 2) {
            hasil.push({
                jalur: 'prasyarat',
                ok: false,
                status: 0,
                panjang: 0,
                keluhan: [
                    `Butuh minimal dua ruang ber-pengawas, ditemukan ${berisi.length}. ` +
                        'Seed basis datanya lebih dulu: php artisan db:seed',
                ],
            });

            await peramban.close();
            await laporkan('bentrok', hasil);

            return;
        }

        const asal = berisi[0];
        const orang = asal.pengawas[0];
        const sesiAsal = asal.sesi_id;

        // Tujuan: ruang di SESI YANG SAMA dengan asal, supaya jendelanya
        // pasti bertumpuk - dan orangnya belum berdiri di sana.
        const tujuan = berisi.find(
            (b) =>
                b.sesi_id === sesiAsal &&
                b.id !== asal.id &&
                !(b.pengawas ?? []).some((x) => x.user_id === orang.user_id),
        );

        if (tujuan === undefined) {
            hasil.push({
                jalur: 'prasyarat',
                ok: false,
                status: 0,
                panjang: 0,
                keluhan: [
                    `Tidak ada ruang kedua di sesi ${sesiAsal} yang belum diisi ${orang.nama}. ` +
                        'Jalankan `php artisan migrate:fresh --seed` untuk keadaan awal yang dikenal.',
                ],
            });

            await peramban.close();
            await laporkan('bentrok', hasil);

            return;
        }

        const dasar = `/admin/events/${EVENT}/pengawas/ruang/${tujuan.id}`;

        /* ------------------------------------------- 1. yang HARUS ditolak */

        const sebelum = (await props(halaman)).baris.find((b) => b.id === tujuan.id).pengawas.length;

        const tolak = await kirim(halaman, dasar, { supervisor_id: orang.user_id });

        const pesan = tolak.props?.flash?.galat ?? null;
        const jumlahTolak = tolak.props?.baris?.find((b) => b.id === tujuan.id)?.pengawas.length ?? null;

        const ditolak = jumlahTolak === sebelum;

        hasil.push({
            jalur: `POST ${dasar} (bentrok: ${orang.nama} sudah di ${asal.ruang} sesi ${asal.sesi})`,
            status: tolak.status,
            panjang: 0,
            ok: ditolak && pesan !== null,
            keluhan: ditolak
                ? pesan === null
                    ? ['Penugasan ditolak, tetapi alasannya TIDAK sampai ke laman - Admin tidak belajar apa pun']
                    : []
                : [`Penugasan bentrok DITERIMA (${sebelum} menjadi ${jumlahTolak} pengawas)`],
        });

        /* ------------------------------------------ 2. yang HARUS diterima */

        /*
        | Calon harus BEBAS DI SELURUH SESI TUJUAN, bukan hanya di ruang tujuan.
        |
        | Menyaring hanya pada ruang tujuan akan memilih orang yang sudah
        | berdiri di ruang lain pada sesi yang sama - yaitu orang yang baru saja
        | ditolak pada langkah 1 - dan pemeriksaannya lalu melaporkan
        | "gerbangnya terlalu ketat" padahal gerbangnya benar.
        |
        | Seeder menugaskan KEDUA pengawas ke seluruh sesi, sehingga pada basis
        | data hasil seed tidak ada satu pun calon yang bebas. Memeriksa jalur
        | penolakan lalu berhenti berarti setengah pemeriksaan - dan jalur
        | "yang sah diterima" justru yang paling mudah rusak tanpa disadari,
        | karena penolakan yang terlalu ketat terlihat persis seperti penolakan
        | yang benar.
        |
        | Karena itu seluruh penugasan calon di SESI TUJUAN dilepas dulu, lalu
        | dikembalikan di akhir. Yang dilepas adalah penugasan di sesi itu saja,
        | bukan di sesi lain: hanya sesi tujuan yang menentukan apakah ia bisa
        | ditugaskan di ruang tujuan.
        */
        const tugasDiSesiTujuan = (id) =>
            baris
                .filter((b) => b.sesi_id === tujuan.sesi_id)
                .flatMap((b) => (b.pengawas ?? []).map((x) => ({ ...x, ruangSesi: b.id })))
                .filter((x) => x.user_id === id);

        // Calon dengan penugasan paling sedikit di sesi tujuan - yang paling
        // murah dipinjam, dan yang paling kecil kemungkinan baliknya keliru.
        const bebas = [...calon]
            .sort((a, b) => tugasDiSesiTujuan(a.id).length - tugasDiSesiTujuan(b.id).length)
            .find((c) => c.id !== orang.user_id);

        if (bebas === undefined) {
            hasil.push({
                jalur: 'prasyarat',
                ok: false,
                status: 0,
                panjang: 0,
                keluhan: ['Tidak ada calon pengawas kedua untuk menguji jalur yang diterima.'],
            });
        } else {
            const dilepas = tugasDiSesiTujuan(bebas.id);

            for (const t of dilepas) {
                await halaman.evaluate(
                    (jalur) =>
                        fetch(jalur, {
                            method: 'DELETE',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-XSRF-TOKEN': decodeURIComponent(
                                    document.cookie
                                        .split('; ')
                                        .find((c) => c.startsWith('XSRF-TOKEN='))
                                        ?.split('=')[1] ?? '',
                                ),
                            },
                        }),
                    `/admin/events/${EVENT}/pengawas/${t.id}`,
                );
            }

            const terima = await kirim(halaman, dasar, { supervisor_id: bebas.id });

            const barisTujuan = terima.props?.baris?.find((b) => b.id === tujuan.id);
            const masuk = (barisTujuan?.pengawas ?? []).some((x) => x.user_id === bebas.id);

            hasil.push({
                jalur: `POST ${dasar} (sah: ${bebas.nama}${dilepas.length > 0 ? `, ${dilepas.length} penugasan dilepas dulu` : ''})`,
                status: terima.status,
                panjang: 0,
                ok: masuk,
                keluhan: masuk ? [] : ['Penugasan yang sah DITOLAK - gerbangnya terlalu ketat'],
            });

            // Kembalikan ke keadaan semula, dalam dua langkah dan urutannya
            // menentukan: penugasan uji dibuang LEBIH DULU, baru yang dilepas
            // dipasang kembali. Membalik urutannya membuat pemasangan kembali
            // ditolak sebagai bentrok - oleh penugasan uji yang belum dibuang.
            const tugasBaru = (barisTujuan?.pengawas ?? []).find((x) => x.user_id === bebas.id);

            if (tugasBaru && !dilepas.some((t) => t.id === tugasBaru.id)) {
                await halaman.evaluate(
                    (jalur) =>
                        fetch(jalur, {
                            method: 'DELETE',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-XSRF-TOKEN': decodeURIComponent(
                                    document.cookie
                                        .split('; ')
                                        .find((c) => c.startsWith('XSRF-TOKEN='))
                                        ?.split('=')[1] ?? '',
                                ),
                            },
                        }),
                    `/admin/events/${EVENT}/pengawas/${tugasBaru.id}`,
                );
            }

            for (const t of dilepas) {
                await kirim(halaman, `/admin/events/${EVENT}/pengawas/ruang/${t.ruangSesi}`, {
                    supervisor_id: bebas.id,
                });
            }

            // Verifikasi akhir: basis data harus berakhir persis seperti ia
            // ditemukan. Pemeriksaan yang meninggalkan perubahan adalah
            // pemeriksaan yang mengubah apa yang ia ukur pada jalannya yang
            // berikutnya.
            await halaman.goto(`${BASE}/admin/events/${EVENT}/pengawas`, { waitUntil: 'networkidle0' });

            const akhir = await props(halaman);
            const supAkhir = akhir.baris
                .flatMap((b) => (b.pengawas ?? []).map((x) => `${b.id}:${x.user_id}`))
                .sort()
                .join(',');
            const supAwal = baris
                .flatMap((b) => (b.pengawas ?? []).map((x) => `${b.id}:${x.user_id}`))
                .sort()
                .join(',');

            hasil.push({
                jalur: 'keadaan kembali seperti semula',
                status: 200,
                panjang: 0,
                ok: supAkhir === supAwal,
                keluhan:
                    supAkhir === supAwal
                        ? []
                        : [`Penugasan berbeda setelah pemeriksaan. Sebelum: ${supAwal}. Sesudah: ${supAkhir}.`],
            });
        }
    } catch (e) {
        console.error(`\nGagal menjalankan pemeriksaan: ${e.message}`);
        console.error(`Pastikan aplikasi berjalan di ${BASE} dan basis datanya sudah di-seed.`);
        process.exitCode = 1;

        return;
    } finally {
        await peramban.close();
    }

    await laporkan('bentrok', hasil);
}

await main();
