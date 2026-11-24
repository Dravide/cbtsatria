<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Analisis Butir · {{ $ujian['judul'] }}</title>
    <style>
        @include('partials.pdf-fonts')
        @include('partials.pdf-tema')

        @page { margin: 14mm 12mm; }

        body {
            margin: 0;
            font-family: 'Bricolage Grotesque', 'DejaVu Sans', sans-serif;
            font-size: 9px;
            color: #161b24;
        }

        table.data { width: 100%; border-collapse: collapse; margin-top: 2mm; }
        table.data th {
            background: #edf1f7;
            border: 1px solid #d3d9e2;
            padding: 2mm;
            text-align: left;
            font-size: 7.5px;
            font-weight: bold;
            color: #4a5565;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        table.data td { border: 1px solid #e6eaf0; padding: 1.8mm 2mm; vertical-align: top; }
        .num { text-align: right; font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace; }
        .chip {
            display: inline-block;
            padding: 0.6mm 1.8mm;
            border-radius: 1mm;
            font-size: 7.5px;
            font-weight: bold;
        }
        .bad { color: #9b2530; background: #fae4e6; }
        .ok { color: #186b47; background: #e2f2e9; }
        .warn { color: #8a5a06; background: #fbf0d9; }
    </style>
</head>
<body>
    <div class="pdf-kop-bar">
        <table class="pdf-kop-table">
            <tr>
                <td style="vertical-align: middle;">
                    <div class="pdf-kop-sekolah">{{ $sekolah['nama'] }}</div>
                    <div class="pdf-kop-sub">Laporan Analisis Butir Soal</div>
                </td>
                <td style="vertical-align: middle; text-align: right; width: 36mm;">
                    <span class="pdf-kop-lencana">Analisis Butir</span>
                </td>
            </tr>
        </table>
    </div>

    <div class="pdf-meta-bar">
        <strong>Ujian:</strong> {{ $ujian['judul'] }} &nbsp;·&nbsp;
        <strong>Mapel:</strong> {{ $ujian['mapel'] ?? '-' }} &nbsp;·&nbsp;
        <strong>Event:</strong> {{ $ujian['event'] ?? '-' }} &nbsp;·&nbsp;
        <strong>Dicetak:</strong> {{ now()->format('d/m/Y H:i') }}
    </div>

    <table class="data">
        <thead>
            <tr>
                <th style="width: 6%;">No</th>
                <th style="width: 30%;">Judul / Jenis</th>
                <th style="width: 9%; text-align: right;">Dijawab</th>
                <th style="width: 10%; text-align: right;">% Benar</th>
                <th style="width: 10%; text-align: right;">Daya Beda</th>
                <th style="width: 14%;">Putusan</th>
                <th style="width: 21%;">Telemetri &amp; Opsi Mati</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($baris as $b)
                <tr>
                    <td class="num">{{ $b['nomor'] }}</td>
                    <td>
                        <strong>{{ $b['judul'] ?? 'Tanpa judul' }}</strong><br>
                        <span style="color:#77808f; font-size:8px;">{{ $b['jenis_label'] }}</span>
                    </td>
                    <td class="num">{{ $b['dijawab'] ?? '-' }}</td>
                    <td class="num">{{ $b['persen_benar'] !== null ? number_format($b['persen_benar'], 1).'%' : '-' }}</td>
                    <td class="num">{{ $b['daya_beda'] !== null ? number_format($b['daya_beda'], 2) : '-' }}</td>
                    <td>
                        <span class="chip {{ $b['putusan_nada'] === 'bad' ? 'bad' : ($b['putusan_nada'] === 'ok' ? 'ok' : 'warn') }}">
                            {{ $b['putusan'] ?? 'belum dihitung' }}
                        </span>
                    </td>
                    <td style="font-size: 8px; color: #4a5565;">
                        @if ($b['waktu_median_detik'] !== null)
                            Median: {{ $b['waktu_median_detik'] }}d<br>
                        @endif
                        @if (! empty($b['opsi_mati']))
                            Opsi mati: {{ implode(', ', $b['opsi_mati']) }}
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
