<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Jadwal Ujian · {{ $event->name }}</title>
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
        .num { text-align: center; font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace; }
        .mono { font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace; }
    </style>
</head>
<body>
    @include('partials.pdf-kop', [
        'lencana' => 'Jadwal Ujian',
        'sub' => $event->name,
        'kananSub' => 'Dicetak ' . $dicetak,
    ])

    <div class="pdf-meta-bar">
        <strong>Event:</strong> {{ $event->name }} &nbsp;·&nbsp;
        <strong>Kode:</strong> <span class="mono">{{ $event->code }}</span>
        @if ($event->academicYear)
            &nbsp;·&nbsp; <strong>Tahun Ajaran:</strong> {{ $event->academicYear->name }}
        @endif
        @if ($event->semester)
            &nbsp;·&nbsp; <strong>Semester:</strong> {{ $event->semester->name }}
        @endif
        &nbsp;·&nbsp; <strong>Dicetak:</strong> {{ $dicetak }}
    </div>

    <table class="data">
        <thead>
            <tr>
                <th style="width: 6%; text-align: center;">No</th>
                <th style="width: 24%;">Hari &amp; Tanggal</th>
                <th style="width: 16%;">Waktu</th>
                <th style="width: 12%;">Sesi</th>
                <th style="width: 42%;">Mata Pelajaran</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($baris as $i => $b)
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td>{{ $b['hari_tanggal'] }}</td>
                    <td class="mono">{{ $b['waktu'] }}</td>
                    <td class="mono">{{ $b['sesi'] }}</td>
                    <td><strong>{{ $b['mapel'] }}</strong></td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; padding: 6mm; color: #77808f;">
                        Belum ada sesi ujian yang dijadwalkan pada event ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
