<x-layout :title="$quotation->reference()">
    <div class="endu-page-head quotation-screen-only">
        <a class="endu-text-action" href="{{ route('repairs.quotations.show', [$branch, $repair, $quotation]) }}">← กลับใบเสนอราคา</a>
        <button type="button" class="button button-primary" data-print-receipt>พิมพ์ใบเสนอราคา</button>
    </div>
    @include('quotations.document')
</x-layout>
