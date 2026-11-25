<?php

namespace App\Support;

use App\Models\Branch;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class DocumentPrintHistory
{
    public static function query(Branch $branch, string $type, int $id): Builder
    {
        return DB::table('document_prints')->where('branch_id', $branch->id)->where('document_type', $type)->where('document_id', $id);
    }

    public static function record(Response $pdf, Branch $branch, string $type, int $id, string $number): Response
    {
        DB::table('document_prints')->insert([
            'branch_id' => $branch->id, 'document_type' => $type, 'document_id' => $id, 'number' => $number,
            'printed_by' => auth()->id(), 'printed_at' => now(),
        ]);

        return $pdf;
    }
}
