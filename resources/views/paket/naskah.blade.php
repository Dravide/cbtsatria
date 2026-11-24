<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $mode === 'kunci' ? 'Kunci Jawaban' : 'Naskah Soal' }} · {{ $paket['nama'] }}</title>
    <style>
        @include('partials.pdf-fonts')
        @include('partials.pdf-tema')

        @page { margin: 14mm 14mm; }

        body {
            margin: 0;
            font-family: 'Bricolage Grotesque', 'DejaVu Sans', sans-serif;
            font-size: 9.5px;
            color: #161b24;
            line-height: 1.45;
        }

        .butir {
            margin-bottom: 4.5mm;
            padding-bottom: 3.5mm;
            border-bottom: 1px solid #e6eaf0;
            page-break-inside: avoid;
        }

        .butir:last-child {
            border-bottom: none;
        }

        .nomor {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-weight: bold;
        }

        .opsi { margin: 1.5mm 0 0 5mm; }
        .opsi-baris { margin-bottom: 0.8mm; }

        .kunci-box {
            margin-top: 2mm;
            padding: 2mm 3mm;
            background: #e2f2e9;
            border: 1px solid #b7dfc9;
            border-left: 3px solid #186b47;
            border-radius: 1.5mm;
            font-size: 8.5px;
            color: #161b24;
        }

        .kunci-box strong { color: #186b47; }
    </style>
</head>
<body>
    <div class="pdf-kop-bar">
        <table class="pdf-kop-table">
            <tr>
                <td style="vertical-align: middle;">
                    <div class="pdf-kop-sekolah">{{ $sekolah['nama'] }}</div>
                    <div class="pdf-kop-sub">{{ $paket['mapel'] ?? 'Mata Pelajaran' }}</div>
                </td>
                <td style="vertical-align: middle; text-align: right; width: 42mm;">
                    <span class="pdf-kop-lencana">
                        {{ $mode === 'kunci' ? 'Kunci Jawaban' : 'Naskah Soal' }}
                    </span>
                </td>
            </tr>
        </table>
    </div>

    <div class="pdf-meta-bar">
        <strong>Paket:</strong> {{ $paket['nama'] }} (<span style="font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;">{{ $paket['kode'] }}</span>) &nbsp;·&nbsp;
        <strong>Jumlah Butir:</strong> {{ count($butir) }} &nbsp;·&nbsp;
        <strong>Total Bobot:</strong> {{ number_format($totalBobot, 1) }}
    </div>

    @foreach ($butir as $idx => $b)
        <div class="butir">
            <div>
                <span class="nomor pdf-aksen">{{ $idx + 1 }}.</span>
                {!! $b['soal_html'] !!}
            </div>

            @if (! empty($b['opsi']))
                <div class="opsi">
                    @foreach ($b['opsi'] as $o)
                        <div class="opsi-baris">
                            <strong>{{ $o['kunci'] }}.</strong> {!! $o['teks_html'] !!}
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($mode === 'kunci')
                <div class="kunci-box">
                    <strong>Kunci:</strong> {{ $b['kunci_teks'] ?: 'Lihat rubrik / uraian' }}
                    @if (! empty($b['pembahasan_teks']))
                        <br><strong>Pembahasan:</strong> {{ $b['pembahasan_teks'] }}
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</body>
</html>
