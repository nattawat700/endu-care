<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['closed_by', 'outcome', 'details', 'closed_at'])]
class RepairClosure extends Model
{
    protected $primaryKey = 'repair_job_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $hidden = ['details'];

    protected function casts(): array
    {
        return ['details' => 'encrypted:array', 'closed_at' => 'datetime'];
    }
}
