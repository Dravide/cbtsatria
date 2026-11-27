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

        .seksi-judul {
            margin-top: 4mm;
            padding: 1.5mm 2.5mm;
            background: #edf1f7;
            border-left: 3px solid #2b4c7e;
            font-size: 8.5px;
            font-weight: bold;
            color: #161b24;
            page-break-after: avoid;
        }

        .seksi-judul.panitia {
            border-left-color: #8a5a14;
            background: #fbf6ec;
        }

        .lencana-jabatan {
            display: inline-block;
            padding: 0.3mm 1.5mm;
            font-size: 6.5px;
            background: #f3e8d2;
            color: #6d470f;
            border: 1px solid #e2c998;
            border-radius: 2px;
        }
    </style>
</head>
<body>
    @include('partials.pdf-kop', [
        'lencana' => $format === 'guru' ? 'Jadwal Pengawas (Per Guru)' : 'Jadwal Pengawas (Per Ruang)',
        'sub' => $event->name,
        'kananSub' => 'Dicetak ' . $dicetak,
    ])

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
            &nbsp;·&nbsp; <strong>Pengawas:</strong> <span class="mono">{{ $ringkasan['pengawas'] ?? count($perPengawas ?? []) }}</span>
            &nbsp;·&nbsp; <strong>Panitia:</strong> <span class="mono">{{ $ringkasan['panitia'] ?? count($perPanitia ?? []) }}</span>
        @endif
        &nbsp;·&nbsp; <strong>Penugasan:</strong> <span class="mono">{{ $ringkasan['penugasan'] }}</span>
        &nbsp;·&nbsp; <strong>Dicetak:</strong> {{ $dicetak }}
    </div>

    @if ($format === 'guru')
        @php
            $kelompokGuru = [
                [
                    'judul' => 'Pengawas (Oleh Pengawas)',
                    'daftar' => $perPengawas ?? $perGuru,
                    'panitia' => false,
                ],
                [
                    'judul' => 'Pengawas oleh Panitia',
                    'daftar' => $perPanitia ?? [],
                    'panitia' => true,
                ],
            ];
            $adaPenugasanGuru = collect($kelompokGuru)->contains(fn (array $k) => count($k['daftar']) > 0);
        @endphp

        @if ($adaPenugasanGuru)
            @foreach ($kelompokGuru as $kelompok)
                @if (count($kelompok['daftar']) > 0)
                    <div class="seksi-judul {{ $kelompok['panitia'] ? 'panitia' : '' }}">
                        {{ $kelompok['judul'] }} · <span class="mono">{{ count($kelompok['daftar']) }} orang</span>
                    </div>
                    @foreach ($kelompok['daftar'] as $g)
                        <div class="guru-blok">
                            <div class="sesi-kepala">
                                <strong>{{ $g['nama'] }}</strong>
                                @if (!empty($g['username']))
                                    &nbsp;<span class="mono lokasi">({{ $g['username'] }})</span>
                                @endif
                                @if ($kelompok['panitia'] && !empty($g['jabatan']))
                                    &nbsp;<span class="lencana-jabatan">{{ $g['jabatan'] }}</span>
                                @endif
                                &nbsp;·&nbsp; <span class="mono">{{ count($g['tugas']) }} sesi</span>
                            </div>
                            <table class="data">
                                <thead>
                                    <tr>
                                        <th style="width: 5%; text-align: center;">No</th>
                                        <th style="width: 22%;">Hari &amp; Tanggal</th>
                                        <th style="width: 13%;">Waktu</th>
                                        <th style="width: 8%;">Sesi</th>
                                        <th style="width: 24%;">Mata Pelajaran</th>
                                        <th style="width: 28%;">Ruang &amp; Lokasi / Kelas</th>
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
                                                    <span>· {{ $t['ruang_nama'] }}</span>
                                                @endif
                                                @if (!empty($t['lokasi']))
                                                    <span class="lokasi">({{ $t['lokasi'] }})</span>
                                                @endif
                                                @if (!empty($t['kelas']))
                                                    <div class="lokasi">Kelas: {{ implode(', ', $t['kelas']) }}</div>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                @endif
            @endforeach
        @else
            <div style="border: 1px solid #e6eaf0; padding: 6mm; text-align: center; color: #77808f; margin-top: 3mm;">
                Belum ada penugasan pengawas pada event ini.
            </div>
        @endif
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
                            <th style="width: 10%;">Kode</th>
                            <th style="width: 25%;">Nama Ruang</th>
                            <th style="width: 33%;">Pengawas (Oleh Pengawas)</th>
                            <th style="width: 27%;">Pengawas oleh Panitia</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($s['ruang'] as $i => $r)
                            @php
                                $reguler = $r['pengawas_reguler'] ?? $r['pengawas'];
                                $panitia = $r['pengawas_panitia'] ?? [];
                            @endphp
                            <tr>
                                <td class="num">{{ $i + 1 }}</td>
                                <td class="mono"><strong>{{ $r['kode'] }}</strong></td>
                                <td>
                                    {{ $r['nama'] }}
                                    @if (!empty($r['lokasi']))
                                        <span class="lokasi">({{ $r['lokasi'] }})</span>
                                    @endif
                                    @if (!empty($r['kelas']))
                                        <div class="lokasi">Kelas: {{ implode(', ', $r['kelas']) }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if (count($reguler) > 0)
                                        {{ implode(', ', $reguler) }}
                                    @else
                                        <span class="kosong">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if (count($panitia) > 0)
                                        {{ implode(', ', $panitia) }}
                                    @else
                                        <span class="kosong">—</span>
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
