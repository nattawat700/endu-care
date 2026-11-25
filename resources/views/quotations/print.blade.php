<x-document-print :title="$quotation->reference()" :pdf-assets="$pdfAssets ?? null" data-a4-print-page>
    <x-slot:controls>
        <nav class="mx-auto flex max-w-[860px] flex-wrap items-center justify-between gap-3 p-3" aria-label="พิมพ์ใบเสนอราคา">
            <a class="endu-text-action" href="{{ route('repairs.quotations.show', [$branch, $repair, $quotation]) }}">← กลับใบเสนอราคา</a>
            <a class="button button-primary" href="{{ route('repairs.quotations.print', [$branch, $repair, $quotation, 'format' => 'pdf']) }}" target="_blank" rel="noopener noreferrer">พิมพ์ใบเสนอราคา</a>
        </nav>
    </x-slot:controls>

    @include('quotations.document')
</x-document-print>
