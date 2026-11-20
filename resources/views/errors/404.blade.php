@php
    $identitas = \App\Domain\Tenancy\Support\IdentitasAplikasi::nilai();
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>404 — Halaman tidak ditemukan · {{ $identitas['nama'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            background: #eceff4;
            color: #161b24;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .card {
            width: 100%;
            max-width: 440px;
            background: #ffffff;
            border: 1px solid #d3d9e2;
            border-radius: 8px;
            padding: 24px;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            font-size: 11px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            background: #e4e9f0;
            border: 1px solid #d3d9e2;
            border-radius: 6px;
            color: #4a5565;
        }
        h1 {
            margin: 16px 0 8px;
            font-size: 20px;
            line-height: 1.3;
        }
        p {
            margin: 0;
            font-size: 14px;
            line-height: 1.6;
            color: #4a5565;
        }
        .actions {
            margin-top: 24px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 16px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-primary {
            background: #1e4fa3;
            color: #ffffff;
            border: 1px solid #1e4fa3;
        }
        .btn-secondary {
            background: #ffffff;
            color: #4a5565;
            border: 1px solid #d3d9e2;
        }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge">404 · Tidak Ditemukan</span>
        <h1>Halaman tidak ditemukan</h1>
        <p>
            Alamat yang Anda buka mungkin keliru, tautan ruang sudah kedaluwarsa atau dicabut,
            atau data yang diminta sudah tidak tersedia.
        </p>
        <div class="actions">
            <a href="/" class="btn btn-primary">Ke Beranda</a>
            <button type="button" onclick="history.back()" class="btn btn-secondary">Kembali</button>
        </div>
    </div>
</body>
</html>
