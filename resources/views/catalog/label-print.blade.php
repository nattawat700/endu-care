<x-layout title="พิมพ์ฉลาก" active-module="CAT">
    <style>
        @page catalog-label { size: {{ $width }}mm {{ $height }}mm; margin: 0; }
        .catalog-label { box-sizing: border-box; width: {{ $width }}mm; height: {{ $height }}mm; background: white; color: black; padding: 3mm 4mm; text-align: center; overflow: hidden; }
        .catalog-label svg { width: 100%; height: 9mm; display: block; margin: 0 auto; }
        @media print {
            body:has(.catalog-label) { page: catalog-label; min-height: 0; }
            .catalog-label { width: calc({{ $width }}mm - .2mm); height: calc({{ $height }}mm - .2mm); break-after: page; break-inside: avoid; }
            .catalog-label:last-child { break-after: auto; }
        }
    </style>
    <div class="endu-page-head catalog-print-controls">
        <div><a class="endu-text-action" href="{{ route('catalog.show', [$branch, $product]) }}">← ข้อมูลสินค้า</a><h1>พิมพ์ฉลาก</h1><p>{{ $width }} × {{ $height }} มม. · {{ $copies }} ฉลาก</p></div>
        <button class="button button-primary" type="button" data-print-receipt>พิมพ์ฉลาก</button>
    </div>
    <div class="space-y-2 overflow-auto print:space-y-0 print:overflow-visible">
        @for ($index = 0; $index < $copies; $index++)
            <article class="catalog-label">
                <p class="truncate text-xs font-bold">{{ $product->name }}</p>
                @if ($price !== null)<p class="text-base font-bold">{{ \App\Models\RepairQuotation::formatBaht($price) }} บาท</p>@endif
                <svg viewBox="0 0 {{ $barcode['width'] }} 32" preserveAspectRatio="none" shape-rendering="crispEdges" role="img" aria-label="บาร์โค้ด {{ $code }}">
                    @foreach ($barcode['bars'] as [$x, $barWidth])
                        <rect x="{{ $x }}" y="0" width="{{ $barWidth }}" height="32" fill="#000"/>
                    @endforeach
                </svg>
                <p class="whitespace-nowrap text-[8px]">{{ $type === 'imei' ? 'IMEI ' : '' }}{{ $code }}</p>
            </article>
        @endfor
    </div>
</x-layout>
