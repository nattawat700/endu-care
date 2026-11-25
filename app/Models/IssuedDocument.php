<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'number', 'source_type', 'source_id', 'source_reference', 'issued_by', 'snapshot'])]
class IssuedDocument extends Model
{
    public const KINDS = [
        'tax_full' => ['ใบกำกับภาษี', 'TAX'],
        'tax_short' => ['ใบกำกับภาษีอย่างย่อ', 'TAS'],
        'delivery' => ['ใบส่งมอบเครื่อง', 'DLV'],
        'warranty' => ['ใบรับประกันงานซ่อม', 'WRT'],
        'delivery_warranty' => ['ใบส่งมอบและใบรับประกัน', 'DWR'],
        'claim' => ['ใบรับเคลม', 'CLM'],
        'claim_result' => ['ใบแจ้งผลเคลม', 'CLR'],
        'return' => ['ใบส่งคืนเครื่อง', 'RTN'],
        'refund' => ['หลักฐานคืนเงิน', 'RFD'],
    ];

    protected $hidden = ['snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array'];
    }
}
