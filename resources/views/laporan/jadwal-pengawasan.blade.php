<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Jadwal Pengawasan · {{ $event->name }}</title>
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

        .sesi-blok {
            margin-top: 4mm;
            page-break-inside: avoid;
        }

        .sesi-kepala {
            background: #edf1f7;
            border: 1px solid #d3d9e2;
            border-bottom: none;
            padding: 1.8mm 2.5mm;
            font-size: 8.5px;
        }

        .sesi-kepala strong {
            color: #161b24;
        }

        .sesi-meta {
            color: #4a5565;
            font-size: 8px;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
        }

        table.data th {
            background: #f6f8fb;
            border: 1px solid #d3d9e2;
            padding: 1.6mm 2mm;
            text-align: left;
            font-size: 7.5px;
            font-weight: bold;
            color: #4a5565;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        table.data td {
            border: 1px solid #e6eaf0;
            padding: 1.6mm 2mm;
            vertical-align: top;
        }

        .num {
            text-align: center;
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
        }

        .mono {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
        }

        .kosong {
            color: #9aa3b2;
            font-style: italic;
        }

        .lokasi {
            color: #6b7688;
            font-size: 7.5px;
        }
    </style>
</head>
<body>
    <div class="pdf-kop-bar">
        <table class="pdf-kop-table">
            <tr>
                <td style="vertical-align: middle;">
                    <div class="pdf-kop-sekolah">{{ $sekolah?->name ?? config('app.name') }}</div>
                    <div class="pdf-kop-sub">{{ $event->name }}</div>
                </td>
                <td style="vertical-align: middle; text-align: right; width: 42mm;">
                    <span class="pdf-kop-lencana">Jadwal Pengawasan</span>
                </td>
            </tr>
        </table>
    </div>

    <div class="pdf-meta-bar">
        <strong>Event:</strong> {{ $event->name }} &nbsp;·&nbsp;
        <strong>Kode:</strong> <span class="mono">{{ $event->code }}</span>
        @if ($event->academicYear)
            &nbsp;·&nbsp; <strong>Tahun Ajaran:</strong> {{ $event->academicYear->name }}
        @endif
        @if ($event->semester)
            &nbsp;·&nbsp; <strong>Semester:</strong> {{ $event->semester->name }}
        @endif
        &nbsp;·&nbsp; <strong>Sesi:</strong> <span class="mono">{{ $ringkasan['sesi'] }}</span>
        &nbsp;·&nbsp; <strong>Ruang-sesi:</strong> <span class="mono">{{ $ringkasan['ruang'] }}</span>
        &nbsp;·&nbsp; <strong>Penugasan:</strong> <span class="mono">{{ $ringkasan['penugasan'] }}</span>
        &nbsp;·&nbsp; <strong>Dicetak:</strong> {{ $dicetak }}
    </div>

    @forelse ($sesi as $s)
        <div class="sesi-blok">
            <div class="sesi-kepala">
                <strong>{{ $s['hari_tanggal'] }}</strong>
                &nbsp;·&nbsp; <span class="mono">{{ $s['waktu'] }}</span>
                &nbsp;·&nbsp; <span class="mono">{{ $s['label'] }}</span>
                &nbsp;—&nbsp; <strong>{{ $s['mapel'] }}</strong>
            </div>
            <table class="data">
                <thead>
                    <tr>
                        <th style="width: 6%; text-align: center;">No</th>
                        <th style="width: 14%;">Kode Ruang</th>
                        <th style="width: 30%;">Nama Ruang</th>
                        <th style="width: 50%;">Pengawas</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($s['ruang'] as $i => $r)
                        <tr>
                            <td class="num">{{ $i + 1 }}</td>
                            <td class="mono"><strong>{{ $r['kode'] }}</strong></td>
                            <td>
                                {{ $r['nama'] }}
                                @if (!empty($r['lokasi']))
                                    <div class="lokasi">{{ $r['lokasi'] }}</div>
                                @endif
                            </td>
                            <td>
                                @if (count($r['pengawas']) > 0)
                                    {{ implode(', ', $r['pengawas']) }}
                                @else
                                    <span class="kosong">Belum ditugaskan</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <div style="border: 1px solid #e6eaf0; padding: 8mm; text-align: center; color: #77808f; margin-top: 4mm;">
            Belum ada ruang-sesi yang dijadwalkan pada event ini.
        </div>
    @endforelse
</body>
</html>
