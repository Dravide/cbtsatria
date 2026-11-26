{{--
    Tablo - daftar hadir per ruang.
    Menyelaraskan sistem desain konsol Admin (warna tema, Bricolage Grotesque & IBM Plex Mono).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Tablo daftar hadir</title>
    <style>
        @include('partials.pdf-fonts')
        @include('partials.pdf-tema')

        @page { margin: 12mm; }

        body {
            margin: 0;
            font-family: 'Bricolage Grotesque', 'DejaVu Sans', sans-serif;
            color: #161b24;
            font-size: 9px;
        }

        .halaman { page-break-after: always; }
        .halaman.terakhir { page-break-after: auto; }

        .logo { max-height: 11mm; max-width: 24mm; display: block; }

        .judul { font-size: 13px; font-weight: bold; color: #161b24; margin: 0 0 1mm; }
        .anak { font-size: 8.5px; color: #4a5565; margin: 0; }

        table.tablo {
            width: 100%;
            border-collapse: collapse;
        }

        table.tablo th {
            background: #edf1f7;
            border: 1px solid #d3d9e2;
            padding: 1.8mm 1.8mm;
            font-size: 7.5px;
            font-weight: bold;
            color: #4a5565;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        table.tablo td {
            border: 1px solid #e6eaf0;
            padding: 1.5mm 1.8mm;
            font-size: 9px;
            color: #161b24;
        }

        .c { text-align: center; }
        .mono { font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace; }

        .ttd { border-bottom: 1px dotted #c6cedb; }

        .kaki {
            margin-top: 4mm;
            padding: 2.5mm 3.5mm;
            background: #f2f4f8;
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
            font-size: 8.5px;
            color: #4a5565;
        }

        .kaki .mono { color: #161b24; font-weight: bold; }

        .tanda {
            margin-top: 9mm;
            text-align: right;
        }

        .tanda .nama {
            margin-top: 15mm;
            border-top: 1px solid #161b24;
            padding-top: 1mm;
            display: inline-block;
            min-width: 55mm;
        }

        .tanda .label { font-size: 8px; color: #4a5565; }
    </style>
</head>
<body>
    @foreach ($halaman as $i => $h)
        @php $terakhir = $i === count($halaman) - 1; @endphp
        <div class="halaman {{ $terakhir ? 'terakhir' : '' }}">
            @include('partials.pdf-kop', [
                'lencana' => 'Daftar Hadir',
                'sub' => $h['ujian'] ?? 'Ujian',
            ])

            <div class="pdf-meta-bar">
                <div class="judul">{{ $h['nama'] ? $h['kode'].' · '.$h['nama'] : 'Ruang '.$h['kode'] }}</div>
                <div class="anak">
                    {{ $h['ujian'] ?? 'Ujian' }}
                    @if ($h['sesi']) · {{ $h['sesi'] }} @endif
                    @if ($h['jam']) · {{ $h['jam'] }} @endif
                    · {{ $h['terisi'] }} peserta dari {{ $h['kapasitas'] }} kursi
                    · {{ $h['jumlah_meja'] }} meja, {{ $h['kursi_per_meja'] }} kursi per meja
                </div>
            </div>

            <table class="tablo">
                <thead>
                    <tr>
                        <th class="c" style="width:14mm">Kursi</th>
                        <th>Nama peserta</th>
                        <th style="width:24mm">NIS</th>
                        <th style="width:20mm">Kelas</th>
                        <th class="c" style="width:14mm">Meja</th>
                        <th class="c" style="width:26mm">Tanda tangan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($h['baris'] as $b)
                        <tr>
                            <td class="c mono">{{ str_pad($b['kursi'], 2, '0', STR_PAD_LEFT) }}</td>
                            <td>{{ $b['nama'] }}</td>
                            <td class="mono">{{ $b['nis'] ?? '' }}</td>
                            <td>{{ $b['kelas'] ?? '' }}</td>
                            <td class="c mono">M-{{ $b['meja'] }}</td>
                            <td class="ttd"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="kaki">
                Jumlah peserta: <span class="mono">{{ $h['terisi'] }}</span>
                &nbsp;·&nbsp; Hadir: <span class="mono">____________</span>
                &nbsp;·&nbsp; Tidak hadir: <span class="mono">____________</span>
            </div>

            <div class="tanda">
                <div class="label">Pengawas ruang {{ $h['kode'] }}</div>
                <div class="nama">&nbsp;</div>
            </div>
        </div>
    @endforeach
</body>
</html>
