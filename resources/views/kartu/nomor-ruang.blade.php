<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Nomor Ruang Ujian (A4)</title>
    <style>
        @include('partials.pdf-fonts')
        @include('partials.pdf-tema')

        @page { margin: 14mm 14mm; }

        body {
            margin: 0;
            font-family: 'Bricolage Grotesque', 'DejaVu Sans', sans-serif;
            color: #161b24;
        }

        .halaman {
            page-break-after: always;
        }

        .halaman.terakhir {
            page-break-after: auto;
        }

        .plakat {
            border: 1.5px solid #c6cedb;
            border-radius: 3mm;
            overflow: hidden;
            background: #ffffff;
        }

        .isi {
            padding: 22mm 12mm 24mm;
            text-align: center;
        }

        .event-badge {
            display: inline-block;
            padding: 2.5mm 6mm;
            border-radius: 2mm;
            background: #f2f4f8;
            border: 1px solid #d3d9e2;
            font-size: 15px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #161b24;
        }

        .ruang-label {
            margin-top: 18mm;
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 3px;
            color: #77808f;
        }

        .ruang-kode {
            margin-top: 4mm;
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: 96px;
            font-weight: bold;
            line-height: 1;
            letter-spacing: 2px;
        }

        .ruang-nama {
            margin-top: 5mm;
            font-size: 22px;
            font-weight: bold;
            color: #161b24;
        }

        .ruang-lokasi {
            margin-top: 2mm;
            font-size: 13px;
            color: #4a5565;
        }

        .kelas-blok {
            margin-top: 22mm;
            padding: 6mm 8mm;
            border-radius: 2.5mm;
            background: #f2f4f8;
            border: 1px solid #d3d9e2;
        }

        .kelas-judul {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #4a5565;
        }

        .kelas-nilai {
            margin-top: 3mm;
            font-size: 24px;
            font-weight: bold;
            color: #161b24;
            line-height: 1.35;
        }
    </style>
</head>
<body>
    @foreach ($halaman as $i => $h)
        @php $terakhir = $i === count($halaman) - 1; @endphp
        <div class="halaman {{ $terakhir ? 'terakhir' : '' }}">
            <div class="plakat">
                @include('partials.pdf-kop-kartu', [
                    'lencana' => 'Nomor Ruang',
                    'sub' => $h['event'] ?? null,
                ])

                <div class="isi">
                    @if (! empty($h['event']))
                        <div>
                            <span class="event-badge">{{ $h['event'] }}</span>
                        </div>
                    @endif

                    <div class="ruang-label">Ruang Ujian</div>
                    <div class="ruang-kode pdf-aksen">{{ $h['kode'] }}</div>

                    @if (! empty($h['nama']))
                        <div class="ruang-nama">{{ $h['nama'] }}</div>
                    @endif

                    @if (! empty($h['lokasi']))
                        <div class="ruang-lokasi">{{ $h['lokasi'] }}</div>
                    @endif

                    <div class="kelas-blok">
                        <div class="kelas-judul">Kelas Peserta</div>
                        <div class="kelas-nilai">
                            @if ($h['kelas'] !== [])
                                {{ implode(' · ', $h['kelas']) }}
                            @else
                                Belum ada penempatan kelas
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</body>
</html>
