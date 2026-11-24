@php
    $identitasPdf = \App\Domain\Tenancy\Support\IdentitasAplikasi::nilai();
    $warnaTema = $identitasPdf['warna'] ?? '#1e4fa3';
    $hexTema = ltrim($warnaTema, '#');
    $temaGelap = true;

    if (preg_match('/^[0-9a-f]{6}$/i', $hexTema) === 1) {
        $kanal = array_map(static function (int $i) use ($hexTema): float {
            $c = hexdec(substr($hexTema, $i, 2)) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, [0, 2, 4]);

        $temaGelap = (0.2126 * $kanal[0] + 0.7152 * $kanal[1] + 0.0722 * $kanal[2]) < 0.22;
    }

    $headerTeks = $temaGelap ? '#ffffff' : '#161b24';
    $headerSub = $temaGelap ? '#e3ebf9' : '#4a5565';
    $lencanaBg = $temaGelap ? '#ffffff' : '#161b24';
    $lencanaTeks = $temaGelap ? $warnaTema : '#ffffff';
@endphp
/* Token warna konsol Admin (resources/css/app.css) untuk DomPDF */
.pdf-kop-bar {
    background: {{ $warnaTema }};
    color: {{ $headerTeks }};
    border-radius: 2mm;
    padding: 3.8mm 5mm;
    margin-bottom: 4mm;
}

.pdf-kop-bar-kartu {
    background: {{ $warnaTema }};
    color: {{ $headerTeks }};
    padding: 4mm 5.5mm;
}

.pdf-kop-table {
    width: 100%;
    border-collapse: collapse;
}

.pdf-logo-bingkai {
    display: inline-block;
    background: #ffffff;
    border-radius: 1.5mm;
    padding: 1mm 1.5mm;
}

.pdf-kop-sekolah {
    font-size: 11.5px;
    font-weight: bold;
    color: {{ $headerTeks }};
    line-height: 1.25;
}

.pdf-kop-sub {
    font-size: 8px;
    color: {{ $headerSub }};
    margin-top: 0.6mm;
}

.pdf-kop-lencana {
    display: inline-block;
    padding: 1mm 2.5mm;
    border-radius: 1.5mm;
    font-size: 7px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: {{ $lencanaBg }};
    color: {{ $lencanaTeks }};
}

.pdf-meta-bar {
    background: #f2f4f8;
    border: 1px solid #d3d9e2;
    border-radius: 1.5mm;
    padding: 2.5mm 3.5mm;
    margin-bottom: 4mm;
    font-size: 8.5px;
    color: #4a5565;
    line-height: 1.45;
}

.pdf-meta-bar strong {
    color: #161b24;
}

.pdf-aksen {
    color: {{ $warnaTema }};
}
