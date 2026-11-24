@php
    $jalurFont = static fn (string $berkas): string => str_replace(
        '\\',
        '/',
        resource_path('fonts/'.$berkas)
    );
@endphp
@font-face {
    font-family: 'Bricolage Grotesque';
    font-style: normal;
    font-weight: 400;
    src: url("{{ $jalurFont('BricolageGrotesque-Regular.ttf') }}") format("truetype");
}
@font-face {
    font-family: 'Bricolage Grotesque';
    font-style: normal;
    font-weight: 700;
    src: url("{{ $jalurFont('BricolageGrotesque-Bold.ttf') }}") format("truetype");
}
@font-face {
    font-family: 'IBM Plex Mono';
    font-style: normal;
    font-weight: 400;
    src: url("{{ $jalurFont('IBMPlexMono-Regular.ttf') }}") format("truetype");
}
@font-face {
    font-family: 'IBM Plex Mono';
    font-style: normal;
    font-weight: 700;
    src: url("{{ $jalurFont('IBMPlexMono-Bold.ttf') }}") format("truetype");
}
