@php
    $sekolahModel = $sekolahModel ?? \App\Domain\Tenancy\Support\IdentitasAplikasi::sekolah();

    $kopNama = $nama ?? (is_array($sekolah ?? null) ? ($sekolah['nama'] ?? null) : ($sekolah->name ?? null)) ?? $sekolahModel?->name ?? config('app.name');
    $kopNpsn = $npsn ?? (is_array($sekolah ?? null) ? ($sekolah['npsn'] ?? null) : ($sekolah->npsn ?? null)) ?? $sekolahModel?->npsn;
    $kopLogo = $logo ?? (is_array($sekolah ?? null) ? ($sekolah['logo'] ?? null) : null);

    if (empty($kopLogo) && ! empty($sekolahModel?->logo_path)) {
        $jalurLogo = storage_path('app/public/' . ltrim((string) $sekolahModel->logo_path, '/'));
        if (is_file($jalurLogo)) {
            $ext = strtolower(pathinfo($jalurLogo, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                default => 'image/png',
            };
            $isiLogo = @file_get_contents($jalurLogo);
            if ($isiLogo !== false) {
                $kopLogo = 'data:' . $mime . ';base64,' . base64_encode($isiLogo);
            }
        }
    }
@endphp

<div class="pdf-kop-bar-kartu {{ $kelas ?? '' }}">
    <table class="pdf-kop-table">
        <tr>
            @if (! empty($kopLogo))
                <td style="width: 15mm; vertical-align: middle; padding-right: 2.5mm;">
                    <span class="pdf-logo-bingkai">
                        <img class="pdf-kop-logo-kartu-img" src="{{ $kopLogo }}" alt="">
                    </span>
                </td>
            @endif
            <td style="vertical-align: middle;">
                <div class="pdf-kop-sekolah {{ $kelasNama ?? '' }}">{{ $kopNama }}</div>
                @if (! empty($kopNpsn))
                    <div class="pdf-kop-sub {{ $kelasSub ?? '' }}">NPSN {{ $kopNpsn }}</div>
                @endif
                @if (! empty($sub))
                    <div class="pdf-kop-sub {{ $kelasSub2 ?? '' }}">{{ $sub }}</div>
                @endif
            </td>
            @if (! empty($lencana))
                <td style="vertical-align: middle; text-align: right; width: 36mm;">
                    <span class="pdf-kop-lencana">{{ $lencana }}</span>
                </td>
            @endif
        </tr>
    </table>
</div>
