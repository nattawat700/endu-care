<x-document-print :title="$document->number" :pdf-assets="$pdfAssets ?? null" data-a4-print-page :data-combined-service-print="$attachedReceipt !== null">
    <x-slot:controls>
        <a class="endu-text-action" href="{{ route('documents.show', [$branch, $document]) }}">กลับเอกสาร</a>
    </x-slot:controls>
    @include('documents.paper')
    @if ($attachedReceipt)
        <div class="quotation-wrap" data-attached-receipt>
            @include('payments.paper', ['payment' => $attachedReceipt, 'repair' => $attachedReceipt->repairJob])
        </div>
    @endif
</x-document-print>
