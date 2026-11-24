<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Analisis Butir · {{ $ujian['judul'] }}</title>
    <style>
        @include('partials.pdf-fonts')

        @page { margin: 14mm 12mm; }
        body { font-family: 'Bricolage Grotesque', 'DejaVu Sans', sans-serif; font-size: 9px; color: #161b24; }
        .kop { border-bottom: 2px solid #161b24; padding-bottom: 3mm; margin-bottom: 4mm; }
        .kop-nama { font-size: 13px; font-weight: bold; }
        .kop-sub { font-size: 9px; color: #4a5565; margin-top: 1mm; }
        .meta { margin-bottom: 4mm; font-size: 9px; color: #334155; }
        table { width: 100%; border-collapse: collapse; margin-top: 2mm; }
        th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 2mm; text-align: left; font-size: 8px; }
        td { border: 1px solid #e2e8f0; padding: 1.8mm 2mm; vertical-align: top; }
        .num { text-align: right; font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace; }
        .bad { color: #9b2530; font-weight: bold; }
        .ok { color: #186b47; font-weight: bold; }
        .warn { color: #8a5a06; font-weight: bold; }
    </style>
</head>
<body>
    <div class="kop">
        <div class="kop-nama">{{ $sekolah['nama'] }}</div>
        <div class="kop-sub">LAPORAN ANALISIS BUTIR SOAL</div>
    </div>

    <div class="meta">
        <strong>Ujian:</strong> {{ $ujian['judul'] }} &nbsp;|&nbsp;
        <strong>Mapel:</strong> {{ $ujian['mapel'] ?? '—' }} &nbsp;|&nbsp;
        <strong>Event:</strong> {{ $ujian['event'] ?? '—' }} &nbsp;|&nbsp;
        <strong>Dicetak:</strong> {{ now()->format('d/m/Y H:i') }}
    </div>

    <table>
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
                        <span style="color:#64748b; font-size:8px;">{{ $b['jenis_label'] }}</span>
                    </td>
                    <td class="num">{{ $b['dijawab'] ?? '—' }}</td>
                    <td class="num">{{ $b['persen_benar'] !== null ? number_format($b['persen_benar'], 1).'%' : '—' }}</td>
                    <td class="num">{{ $b['daya_beda'] !== null ? number_format($b['daya_beda'], 2) : '—' }}</td>
                    <td>
                        <span class="{{ $b['putusan_nada'] === 'bad' ? 'bad' : ($b['putusan_nada'] === 'ok' ? 'ok' : 'warn') }}">
                            {{ $b['putusan'] ?? 'belum dihitung' }}
                        </span>
                    </td>
                    <td>
                        {{ $b['rata_durasi_detik'] !== null ? $b['rata_durasi_detik'].' dtk' : '—' }}
                        {{ $b['kuadran'] ? '· '.$b['kuadran'] : '' }}
                        @if (! empty($b['opsi_mati']))
                            <br><span class="warn">Opsi mati: {{ implode(', ', $b['opsi_mati']) }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (! empty($kemiripan))
        <div style="margin-top: 5mm;">
            <strong>Catatan Korelasi Jawaban Salah Berdekatan:</strong>
            <ul style="margin: 1.5mm 0 0 4mm; padding: 0;">
                @foreach ($kemiripan as $k)
                    <li>
                        {{ $k['ruang'] }} · Kursi {{ $k['kursi_a'] }} ({{ $k['nama_a'] }}) &amp;
                        Kursi {{ $k['kursi_b'] }} ({{ $k['nama_b'] }}): {{ $k['salah_sama'] }} jawaban salah identik.
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</body>
</html>
