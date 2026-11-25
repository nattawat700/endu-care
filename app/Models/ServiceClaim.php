<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['customer_id', 'repair_job_id', 'pos_sale_id', 'received_by', 'submission_id', 'intake'])]
class ServiceClaim extends Model
{
    public const STATUSES = ['received' => 'รับเคลม · รอพิจารณา', 'approved' => 'อนุมัติเคลม', 'rejected' => 'ไม่อนุมัติเคลม'];

    protected $attributes = ['status' => 'received'];

    protected $hidden = ['intake', 'review'];

    public function reference(): string
    {
        return 'CLM-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    protected function casts(): array
    {
        return ['intake' => 'encrypted:array', 'review' => 'encrypted:array', 'reviewed_at' => 'datetime'];
    }
}
