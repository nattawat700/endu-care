<x-layout title="พิมพ์ทดสอบ" active-module="INT">
    <style>
        .printer-test-sheet { width: min(100%, {{ $width }}mm); margin-inline: auto; background: white; }
        @page integration-printer-test { size: {{ $width }}mm {{ $height }}mm; margin: 0; }
        @media print {
            body:has(.printer-test-sheet) { page: integration-printer-test; min-height: 0; }
            .printer-test-sheet { width: calc({{ $width }}mm - 0.2mm); }
        }
    </style>
    <div class="endu-page-head catalog-print-controls">
        <div><a class="endu-text-action" href="{{ route('integrations.devices', $branch) }}">← เครื่องพิมพ์และสแกนเนอร์</a><h1>พิมพ์ทดสอบ</h1>
            <p>เลือกเครื่อง {{ $printerProfile->device_name }} และขนาดกระดาษตามโปรไฟล์ในหน้าพิมพ์ของเบราว์เซอร์</p></div>
        <button class="button button-primary" type="button" data-print-receipt>พิมพ์ทดสอบ</button>
    </div>
    <article class="printer-test-sheet p-[4mm] text-[9pt]">
        <div class="space-y-2 border border-black p-[3mm]">
            <h2 class="text-[11pt] font-bold">ทดสอบเครื่องพิมพ์ ENDU CARE</h2>
            <p class="wrap-break-word">{{ $branch->name }} · {{ $printerProfile->name }}</p>
            <p class="wrap-break-word">{{ $printerProfile->device_name }}</p>
            <p>{{ $width }} × {{ $height }} มม.</p>
            <div class="w-[40mm] border-b border-black">เส้นทดสอบ 40 มม.</div>
            <p>ทดสอบภาษาไทย · 0123456789</p>
        </div>
    </article>
</x-layout>
