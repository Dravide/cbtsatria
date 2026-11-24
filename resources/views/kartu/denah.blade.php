{{--
    Denah tempat duduk - lembar yang ditempel di meja pengawas.
    Menyelaraskan sistem desain konsol Admin (warna tema, Bricolage Grotesque & IBM Plex Mono).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Denah tempat duduk</title>
    <style>
        @include('partials.pdf-fonts')
        @include('partials.pdf-tema')

        @page { margin: 10mm; }

        body {
            margin: 0;
            font-family: 'Bricolage Grotesque', 'DejaVu Sans', sans-serif;
            color: #161b24;
            font-size: 9px;
        }

        .halaman { page-break-after: always; }
        .halaman.terakhir { page-break-after: auto; }

        .logo { max-height: 11mm; max-width: 24mm; display: block; }

        .judul { font-size: 12.5px; font-weight: bold; color: #161b24; margin: 0 0 0.8mm; }
        .anak { font-size: 8.5px; color: #4a5565; margin: 0; }

        .papan {
            margin: 3mm 0 2mm;
            border: 1px solid #d3d9e2;
            background: #edf1f7;
            border-radius: 1.5mm;
            text-align: center;
            padding: 1.4mm;
            font-size: 7.2px;
            font-weight: bold;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #4a5565;
        }

        table.denah { width: 100%; border-collapse: separate; border-spacing: 1.6mm; }

        table.denah td.baris-kosong {
            border: none;
            background: none;
        }

        .meja {
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
            background: #f2f4f8;
            padding: 1mm;
        }

        table.kursi { width: 100%; border-collapse: separate; border-spacing: 1mm; }

        td.kursi {
            border: 1px solid #d3d9e2;
            border-radius: 1mm;
            background: #ffffff;
            padding: 1.2mm 1.2mm;
            vertical-align: top;
            width: 50%;
        }

        td.kursi.kosong {
            border: 1px dashed #c6cedb;
            background: #f8fafc;
        }

        .nomor { font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace; font-size: 7.8px; color: #77808f; }
        .nama { font-size: 8.8px; font-weight: bold; color: #161b24; }
        .kecil { font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace; font-size: 7px; color: #77808f; }

        .kaki {
            margin-top: 3mm;
            padding: 2mm 3mm;
            background: #f2f4f8;
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
            font-size: 7.8px;
            color: #4a5565;
        }

        .kaki .mono { font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace; font-weight: bold; color: #161b24; }

        .tanda { margin-top: 7mm; text-align: right; }
        .tanda .nama { margin-top: 13mm; border-top: 1px solid #161b24; padding-top: 1mm; display: inline-block; min-width: 55mm; font-weight: normal; }
        .tanda .label { font-size: 8px; color: #4a5565; }
    </style>
</head>
<body>
    @foreach ($halaman as $i => $h)
        @php $terakhir = $i === count($halaman) - 1; @endphp
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
                        <td style="vertical-align:middle; text-align:right; width:42mm;">
                            <span class="pdf-kop-lencana">Denah Tempat Duduk</span>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="pdf-meta-bar">
                <div class="judul">{{ $h['nama'] ? $h['kode'].' · '.$h['nama'] : 'Ruang '.$h['kode'] }}</div>
                <div class="anak">
                    {{ $h['ujian'] ?? 'Ujian' }}
                    @if ($h['sesi']) · {{ $h['sesi'] }} @endif
                    @if ($h['tanggal']) · {{ $h['tanggal'] }} @endif
                    @if ($h['jam']) · {{ $h['jam'] }} @endif
                    · {{ $h['terisi'] }} peserta dari {{ $h['kapasitas'] }} kursi
                    · {{ $h['jumlah_meja'] }} meja, {{ $h['kursi_per_meja'] }} kursi per meja
                </div>
            </div>

            <div class="papan">Papan tulis / arah depan · pintu masuk di sisi bawah</div>

            <table class="denah">
                @foreach ($h['baris_meja'] as $baris)
                    <tr>
                        @for ($k = 0; $k < $h['meja_per_baris']; $k++)
                            @php $meja = $baris[$k] ?? null; @endphp
                            <td class="{{ $meja === null ? 'baris-kosong' : '' }}" style="width:{{ round(100 / $h['meja_per_baris'], 2) }}%">
                                @if ($meja !== null)
                                    <div class="meja">
                                        <table class="kursi">
                                            <tr>
                                                @foreach ($meja as $s)
                                                    <td class="kursi {{ $s['peserta'] === null ? 'kosong' : '' }}">
                                                        <div class="nomor">{{ str_pad($s['kursi'], 2, '0', STR_PAD_LEFT) }}</div>
                                                        @if ($s['peserta'] === null)
                                                            <div class="nomor">-</div>
                                                        @else
                                                            <div class="nama">{{ $s['peserta']['nama'] }}</div>
                                                            <div class="kecil">
                                                                {{ $s['peserta']['kelas'] ?? '-' }}@if ($s['peserta']['nis']) · {{ $s['peserta']['nis'] }}@endif
                                                            </div>
                                                        @endif
                                                    </td>
                                                @endforeach
                                            </tr>
                                        </table>
                                        <div class="kecil" style="text-align:right; padding-right:1mm;">M-{{ $meja[0]['meja'] }}</div>
                                    </div>
                                @endif
                            </td>
                        @endfor
                    </tr>
                @endforeach
            </table>

            <div class="kaki">
                Kursi terisi: <span class="mono">{{ $h['terisi'] }}</span>
                &nbsp;·&nbsp; Kursi kosong: <span class="mono">{{ max(0, $h['kapasitas'] - $h['terisi']) }}</span>
                &nbsp;·&nbsp; Nomor kursi sama dengan yang dibaca pengawas di daftar hadir.
                &nbsp;·&nbsp; Lembar ini memuat nama: simpan di dalam ruang, jangan di koridor.
            </div>

            <div class="tanda">
                <div class="label">Pengawas ruang {{ $h['kode'] }}</div>
                <div class="nama">&nbsp;</div>
            </div>
        </div>
    @endforeach
</body>
</html>
