<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Jadwal Pengawasan · {{ $event->name }}</title>
    <style>
        @include('partials.pdf-fonts')
        @include('partials.pdf-tema')

        @page { margin: 10mm 10mm; }

        body {
            margin: 0;
            font-family: 'Bricolage Grotesque', 'DejaVu Sans', sans-serif;
            font-size: 8px;
            color: #161b24;
        }

        .sesi-blok {
            margin-top: 3mm;
        }

        .sesi-kepala {
            background: #edf1f7;
            border: 1px solid #d3d9e2;
            border-bottom: none;
            padding: 1.2mm 2mm;
            font-size: 8px;
            page-break-after: avoid;
        }

        .sesi-kepala strong {
            color: #161b24;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
        }

        table.data thead {
            display: table-header-group;
        }

        table.data tr {
            page-break-inside: avoid;
        }

        table.data th {
            background: #f6f8fb;
            border: 1px solid #d3d9e2;
            padding: 1mm 1.5mm;
            text-align: left;
            font-size: 7px;
            font-weight: bold;
            color: #4a5565;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        table.data td {
            border: 1px solid #e6eaf0;
            padding: 1mm 1.5mm;
            vertical-align: top;
            line-height: 1.25;
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
            font-size: 7px;
        }

        .guru-blok {
            margin-top: 2.5mm;
            page-break-inside: avoid;
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
                <td style="vertical-align: middle; text-align: right; width: 46mm;">
                    <span class="pdf-kop-lencana">
                        {{ $format === 'guru' ? 'Jadwal Pengawas (Per Guru)' : 'Jadwal Pengawas (Per Ruang)' }}
                    </span>
                </td>
            </tr>
        </table>
    </div>

    <div class="pdf-meta-bar">
        <strong>Event:</strong> {{ $event->name }} &nbsp;·&nbsp;
        <strong>Kode:</strong> <span class="mono">{{ $event->code }}</span>
        @if ($event->academicYear)
            &nbsp;·&nbsp; <strong>TA:</strong> {{ $event->academicYear->name }}
        @endif
        @if ($event->semester)
            &nbsp;·&nbsp; <strong>Smt:</strong> {{ $event->semester->name }}
        @endif
        &nbsp;·&nbsp; <strong>Sesi:</strong> <span class="mono">{{ $ringkasan['sesi'] }}</span>
        &nbsp;·&nbsp; <strong>Ruang-sesi:</strong> <span class="mono">{{ $ringkasan['ruang'] }}</span>
        @if ($format === 'guru')
            &nbsp;·&nbsp; <strong>Pengawas:</strong> <span class="mono">{{ $ringkasan['guru'] }}</span>
        @endif
        &nbsp;·&nbsp; <strong>Penugasan:</strong> <span class="mono">{{ $ringkasan['penugasan'] }}</span>
        &nbsp;·&nbsp; <strong>Dicetak:</strong> {{ $dicetak }}
    </div>

    @if ($format === 'guru')
        @forelse ($perGuru as $g)
            <div class="guru-blok">
                <div class="sesi-kepala">
                    <strong>{{ $g['nama'] }}</strong>
                    @if (!empty($g['username']))
                        &nbsp;<span class="mono lokasi">({{ $g['username'] }})</span>
                    @endif
                    &nbsp;·&nbsp; <span class="mono">{{ count($g['tugas']) }} sesi</span>
                </div>
                <table class="data">
                    <thead>
                        <tr>
                            <th style="width: 5%; text-align: center;">No</th>
                            <th style="width: 24%;">Hari &amp; Tanggal</th>
                            <th style="width: 14%;">Waktu</th>
                            <th style="width: 9%;">Sesi</th>
                            <th style="width: 28%;">Mata Pelajaran</th>
                            <th style="width: 20%;">Ruang</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($g['tugas'] as $i => $t)
                            <tr>
                                <td class="num">{{ $i + 1 }}</td>
                                <td>{{ $t['hari_tanggal'] }}</td>
                                <td class="mono">{{ $t['waktu'] }}</td>
                                <td class="mono">{{ $t['sesi'] }}</td>
                                <td>{{ $t['mapel'] }}</td>
                                <td>
                                    <span class="mono"><strong>{{ $t['ruang_kode'] }}</strong></span>
                                    @if ($t['ruang_nama'] && $t['ruang_nama'] !== $t['ruang_kode'])
                                        <span class="lokasi">· {{ $t['ruang_nama'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <div style="border: 1px solid #e6eaf0; padding: 6mm; text-align: center; color: #77808f; margin-top: 3mm;">
                Belum ada penugasan pengawas pada event ini.
            </div>
        @endforelse
    @else
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
                            <th style="width: 5%; text-align: center;">No</th>
                            <th style="width: 11%;">Kode</th>
                            <th style="width: 26%;">Nama Ruang</th>
                            <th style="width: 58%;">Pengawas</th>
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
                                        <span class="lokasi">· {{ $r['lokasi'] }}</span>
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
            <div style="border: 1px solid #e6eaf0; padding: 6mm; text-align: center; color: #77808f; margin-top: 3mm;">
                Belum ada ruang-sesi yang dijadwalkan pada event ini.
            </div>
        @endforelse
    @endif
</body>
</html>
