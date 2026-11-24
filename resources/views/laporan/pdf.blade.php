{{--
    Berkas PDF ekspor laporan.
    Menyelaraskan sistem desain konsol Admin (warna tema, Bricolage Grotesque & IBM Plex Mono).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $judul }}</title>
    <style>
        @include('partials.pdf-fonts')
        @include('partials.pdf-tema')

        @page { margin: 14mm 12mm; }

        body {
            margin: 0;
            font-family: 'Bricolage Grotesque', 'DejaVu Sans', sans-serif;
            font-size: 8.5px;
            color: #161b24;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
        }

        table.data th {
            background: #edf1f7;
            border: 1px solid #d3d9e2;
            padding: 2mm 2mm;
            text-align: left;
            font-size: 7px;
            font-weight: bold;
            color: #4a5565;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        table.data td {
            border: 1px solid #e6eaf0;
            padding: 1.6mm 2mm;
            text-align: left;
            color: #161b24;
        }

        .angka { text-align: right; font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace; }

        .catatan {
            margin-top: 3.5mm;
            padding: 2mm 3mm;
            background: #f2f4f8;
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
            font-size: 7.5px;
            color: #4a5565;
        }
    </style>
</head>
<body>
    <div class="pdf-kop-bar">
        <table class="pdf-kop-table">
            <tr>
                <td style="vertical-align: middle;">
                    <div class="pdf-kop-sekolah">{{ $judul }}</div>
                    <div class="pdf-kop-sub">Dibuat {{ $dibuat }} &middot; {{ $total }} baris</div>
                </td>
                <td style="vertical-align: middle; text-align: right; width: 32mm;">
                    <span class="pdf-kop-lencana">Laporan</span>
                </td>
            </tr>
        </table>
    </div>

    <table class="data">
        <thead>
            <tr>
                @foreach ($kolom as $label)
                    <th>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($baris as $satu)
                <tr>
                    @foreach ($satu as $sel)
                        <td>{{ $sel ?? '-' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($kolom) }}" style="text-align: center; padding: 5mm; color: #77808f;">
                        Tidak ada baris untuk penyaring ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($total > count($baris))
        <div class="catatan">
            Menampilkan {{ count($baris) }} dari {{ $total }} baris. Untuk daftar
            lengkap, unduh berkas XLSX dari laman Laporan &amp; ekspor.
        </div>
    @endif
</body>
</html>
