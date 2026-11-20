import katex from 'katex';

/**
 * Render ekspresi LaTeX tunggal menjadi HTML KaTeX.
 * Mengembalikan pesan galat ringan bila sintaks belum lengkap (mis. saat mengetik).
 */
export function renderSatuRumus(ekspresi: string, blok = false): string {
    return katex.renderToString(ekspresi, {
        displayMode: blok,
        throwOnError: false,
        strict: false,
    });
}

/**
 * Mengubah delimiter `$$...$$` (blok) dan `$...$` (sebaris) di luar tag HTML
 * menjadi HTML KaTeX siap tampil. Tag HTML (`<...>`) dilewati utuh agar atribut
 * tidak tersentuh.
 */
export function renderRumusDalamHtml(html: string): string {
    if (!html.includes('$')) {
        return html;
    }

    const bagian = html.split(/(<[^>]+>)/g);

    return bagian
        .map((potongan) => {
            if (potongan.startsWith('<')) {
                return potongan;
            }

            // 1. Blok $$...$$ lebih dulu supaya tidak tertangkap sebagai $...$ kosong.
            const setelahBlok = potongan.replace(/\$\$([\s\S]+?)\$\$/g, (_, isi: string) =>
                renderSatuRumus(decodeEntitas(isi), true),
            );

            // 2. Sebaris $...$ (hindari \$ yang di-escape dan teks kosong).
            return setelahBlok.replace(/(?<!\\)\$([^$\n]+?)(?<!\\)\$/g, (_, isi: string) => {
                if (isi.trim() === '') {
                    return `$${isi}$`;
                }

                return renderSatuRumus(decodeEntitas(isi), false);
            });
        })
        .join('');
}

function decodeEntitas(teks: string): string {
    return teks
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&')
        .replace(/&nbsp;/g, ' ');
}
