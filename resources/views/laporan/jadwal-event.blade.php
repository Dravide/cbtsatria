<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Jadwal Ujian · {{ $event->name }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #161b24; }
        .kop { border-bottom: 2px solid #161b24; padding-bottom: 3mm; margin-bottom: 4mm; }
        .kop-nama { font-size: 13px; font-weight: bold; }
        .kop-sub { font-size: 9px; color: #4a5565; margin-top: 1mm; }
        .meta { margin-bottom: 4mm; font-size: 9px; color: #334155; }
        table { width: 100%; border-collapse: collapse; margin-top: 2mm; }
        th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 2mm; text-align: left; font-size: 8px; }
        td { border: 1px solid #e2e8f0; padding: 1.8mm 2mm; vertical-align: top; }
        .num { text-align: center; font-family: DejaVu Sans Mono, monospace; }
        .mono { font-family: DejaVu Sans Mono, monospace; }
    </style>
</head>
<body>
    <div class="kop">
        <div class="kop-nama">{{ $sekolah?->name ?? config('app.name') }}</div>
        <div class="kop-sub">JADWAL UJIAN · {{ strtoupper($event->name) }}</div>
    </div>

    <div class="meta">
        <strong>Event:</strong> {{ $event->name }} &nbsp;|&nbsp;
        <strong>Kode:</strong> {{ $event->code }}
        @if ($event->academicYear)
            &nbsp;|&nbsp; <strong>Tahun Ajaran:</strong> {{ $event->academicYear->name }}
        @endif
        @if ($event->semester)
            &nbsp;|&nbsp; <strong>Semester:</strong> {{ $event->semester->name }}
        @endif
        &nbsp;|&nbsp; <strong>Dicetak:</strong> {{ $dicetak }}
    </div>

    <table>
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
                    <td colspan="5" style="text-align: center; padding: 6mm; color: #64748b;">
                        Belum ada sesi ujian yang dijadwalkan pada event ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
