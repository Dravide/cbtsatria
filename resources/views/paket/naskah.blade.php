<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $mode === 'kunci' ? 'Kunci Jawaban' : 'Naskah Soal' }} · {{ $paket['nama'] }}</title>
    <style>
        @page { margin: 16mm 15mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #161b24; line-height: 1.45; }
        .kop { border-bottom: 2px solid #161b24; padding-bottom: 3mm; margin-bottom: 5mm; }
        .kop-nama { font-size: 13px; font-weight: bold; }
        .kop-sub { font-size: 9px; color: #4a5565; margin-top: 1mm; }
        .meta { background: #f4f6f9; border: 1px solid #d3d9e2; padding: 3mm 4mm; margin-bottom: 5mm; font-size: 9px; }
        .butir { margin-bottom: 4.5mm; page-break-inside: avoid; }
        .nomor { font-weight: bold; }
        .opsi { margin: 1.5mm 0 0 5mm; }
        .opsi-baris { margin-bottom: 0.8mm; }
        .kunci-box { margin-top: 1.5mm; padding: 1.5mm 2.5mm; background: #eef6f0; border-left: 3px solid #186b47; font-size: 9px; }
    </style>
</head>
<body>
    <div class="kop">
        <div class="kop-nama">{{ $sekolah['nama'] }}</div>
        <div class="kop-sub">
            {{ $mode === 'kunci' ? 'KUNCI JAWABAN & PEMBAHASAN' : 'NASKAH SOAL UJIAN' }}
            &mdash; {{ $paket['mapel'] ?? 'Mata Pelajaran' }}
        </div>
    </div>

    <div class="meta">
        <strong>Paket:</strong> {{ $paket['nama'] }} ({{ $paket['kode'] }}) &nbsp;|&nbsp;
        <strong>Jumlah Butir:</strong> {{ count($butir) }} &nbsp;|&nbsp;
        <strong>Total Bobot:</strong> {{ number_format($totalBobot, 1) }}
    </div>

    @foreach ($butir as $idx => $b)
        <div class="butir">
            <div>
                <span class="nomor">{{ $idx + 1 }}.</span>
                {!! $b['soal_html'] !!}
            </div>

            @if (! empty($b['opsi']))
                <div class="opsi">
                    @foreach ($b['opsi'] as $o)
                        <div class="opsi-baris">
                            <strong>{{ $o['kunci'] }}.</strong> {!! $o['teks_html'] !!}
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($mode === 'kunci')
                <div class="kunci-box">
                    <strong>Kunci:</strong> {{ $b['kunci_teks'] ?: 'Lihat rubrik / uraian' }}
                    @if (! empty($b['pembahasan_teks']))
                        <br><strong>Pembahasan:</strong> {{ $b['pembahasan_teks'] }}
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</body>
</html>
