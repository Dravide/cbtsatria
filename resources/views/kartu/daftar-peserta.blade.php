{{--
    Daftar nama peserta per ruang - tanpa kolom tanda tangan maupun blok pengesahan.
    Menyelaraskan sistem desain konsol Admin (warna tema, Bricolage Grotesque & IBM Plex Mono).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Daftar nama peserta</title>
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

        table.daftar {
            width: 100%;
            border-collapse: collapse;
        }

        table.daftar th {
            background: #edf1f7;
            border: 1px solid #d3d9e2;
            padding: 1.8mm 2mm;
            font-size: 7.5px;
            font-weight: bold;
            color: #4a5565;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        table.daftar td {
            border: 1px solid #e6eaf0;
            padding: 1.6mm 2mm;
            font-size: 9px;
            color: #161b24;
        }

        .c { text-align: center; }
        .mono { font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace; }

        .kaki {
            margin-top: 3.5mm;
            padding-top: 2.5mm;
            border-top: 1px solid #e6eaf0;
            font-size: 7.8px;
            color: #77808f;
        }

        .kaki-table {
            width: 100%;
            border-collapse: collapse;
        }
    </style>
</head>
<body>
    @foreach ($halaman as $i => $h)
        @php
            $terakhir = $i === count($halaman) - 1;
            $peserta = array_values(array_filter(
                $h['baris'],
                static fn (array $b): bool => trim((string) ($b['nama'] ?? '')) !== '',
            ));
        @endphp
        <div class="halaman {{ $terakhir ? 'terakhir' : '' }}">
            <div class="pdf-kop-bar">
                <table class="pdf-kop-table">
                    <tr>
                        @if (! empty($sekolah['logo']))
                            <td style="width:20mm; vertical-align:middle; padding-right:3mm;">
                                <span class="pdf-logo-bingkai">
                                    <img class="logo" src="{{ $sekolah['logo'] }}" alt="">
                                </span>
                            </td>
                        @endif
                        <td style="vertical-align:middle;">
                            <div class="pdf-kop-sekolah">{{ $sekolah['nama'] }}</div>
                            @if (! empty($sekolah['npsn']))
                                <div class="pdf-kop-sub">NPSN {{ $sekolah['npsn'] }}</div>
                            @endif
                        </td>
                        <td style="vertical-align:middle; text-align:right; width:38mm;">
                            <span class="pdf-kop-lencana">Daftar Peserta</span>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="pdf-meta-bar">
                <div class="judul">{{ $h['nama'] ? $h['kode'].' · '.$h['nama'] : 'Ruang '.$h['kode'] }}</div>
                <div class="anak">
                    {{ $h['ujian'] ?? 'Ujian' }}
                    @if ($h['sesi']) · {{ $h['sesi'] }} @endif
                    @if ($h['jam']) · {{ $h['jam'] }} @endif
                    · {{ count($peserta) }} peserta
                </div>
            </div>

            <table class="daftar">
                <thead>
                    <tr>
                        <th class="c" style="width:14mm">Kursi</th>
                        <th>Nama peserta</th>
                        <th style="width:26mm">NIS</th>
                        <th style="width:24mm">Kelas</th>
                        <th class="c" style="width:16mm">Meja</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($peserta as $b)
                        <tr>
                            <td class="c mono">{{ str_pad($b['kursi'], 2, '0', STR_PAD_LEFT) }}</td>
                            <td>{{ $b['nama'] }}</td>
                            <td class="mono">{{ $b['nis'] ?? '-' }}</td>
                            <td>{{ $b['kelas'] ?? '-' }}</td>
                            <td class="c mono">M-{{ $b['meja'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="c" style="padding: 5mm; color: #77808f;">
                                Belum ada peserta yang ditempatkan di ruang ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="kaki">
                <table class="kaki-table">
                    <tr>
                        <td>Total peserta: <strong style="color: #161b24;">{{ count($peserta) }}</strong></td>
                        <td style="text-align: right;" class="mono">{{ now()->format('d/m/Y') }}</td>
                    </tr>
                </table>
            </div>
        </div>
    @endforeach
</body>
</html>
