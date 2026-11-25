<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['uploaded_by', 'phase', 'filename', 'content_type'])]
#[Hidden(['filename'])]
class RepairPhoto extends Model
{
    public const PHASES = ['before' => 'ก่อนซ่อม', 'after' => 'หลังซ่อม'];

    public static function directory(RepairJob $repair): string
    {
        return 'repair-evidence/'.$repair->branch_id.'/'.$repair->id;
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
