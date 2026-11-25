<x-document-print :title="$payment->title().' '.$payment->reference()" :pdf-assets="$pdfAssets ?? null" data-payment-print-page data-a4-print-page>
    <x-slot:controls>
        <nav class="mx-auto flex max-w-[860px] flex-wrap items-center justify-between gap-3 p-3" aria-label="พิมพ์เอกสาร">
            <a class="endu-text-action" href="{{ route('repairs.payments.show', [$branch, $repair, $payment]) }}">← กลับเอกสารรับเงิน</a>
            <a class="button button-primary" href="{{ route('repairs.payments.print', [$branch, $repair, $payment, 'format' => 'pdf']) }}" target="_blank" rel="noopener noreferrer">พิมพ์{{ $payment->title() }}</a>
        </nav>
    </x-slot:controls>

    @include('payments.paper')
</x-document-print>
