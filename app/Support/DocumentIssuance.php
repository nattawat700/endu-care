<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\IssuedDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentIssuance
{
    /** Called while the branch row is locked. */
    public static function issue(Branch $branch, string $kind, string $source, int $sourceId, string $reference, array $snapshot): IssuedDocument
    {
        $criteria = ['kind' => $kind, 'source_type' => $source, 'source_id' => $sourceId];
        $existing = $branch->issuedDocuments()->where($criteria)->first();
        if ($existing) {
            return $existing;
        }

        $seriesKey = ['branch_id' => $branch->id, 'kind' => $kind];
        $series = DB::table('document_series')->where($seriesKey)->first();
        $prefix = $series?->prefix ?? IssuedDocument::KINDS[$kind][1];
        $next = (int) ($series?->next_number ?? 1);
        if ($next > 999999999999) {
            throw ValidationException::withMessages(['kind' => 'เลขเอกสารถึงขีดจำกัด กรุณาติดต่อผู้ดูแล']);
        }
        $number = $prefix.'-'.strtoupper($branch->code).'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        DB::table('document_series')->updateOrInsert($seriesKey, ['prefix' => $prefix, 'next_number' => $next + 1]);

        return $branch->issuedDocuments()->create($criteria + [
            'number' => $number, 'issued_by' => auth()->id(), 'source_reference' => $reference,
            'snapshot' => $snapshot + ['seller' => $branch->documentSeller()],
        ]);
    }
}
