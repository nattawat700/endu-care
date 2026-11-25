@props(['title', 'pdfAssets' => null])

<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | ENDU CARE</title>
    @if ($pdfAssets)
        <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; font-src data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'">
        <style>{!! $pdfAssets['css'] !!}</style>
    @else
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body {{ $attributes->class(['endu-app']) }}>
    @unless ($pdfAssets)
        <header class="quotation-screen-only">
            {{ $controls }}
        </header>
    @endunless
    <main>
        {{ $slot }}
    </main>
    @if ($pdfAssets)
        <script>{!! $pdfAssets['script'] !!}</script>
    @endif
</body>
</html>
