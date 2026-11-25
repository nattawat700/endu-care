<?php

namespace App\Models;

use App\Enums\RepairKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['inspected_by', 'workflow_version', 'items', 'passed', 'note'])]
class RepairQualityCheck extends Model
{
    /** @return array<string, string> */
    public static function checklistFor(?RepairKind $kind): array
    {
        $specific = $kind === RepairKind::Quick
            ? ['agreed_service' => 'งานที่ทำตรงกับรายการที่ตกลงกับลูกค้า']
            : ['assessed_service' => 'ทดสอบฟังก์ชันที่เกี่ยวข้องกับผลประเมิน'];

        return [
            'symptom' => 'ทดสอบอาการที่ลูกค้าแจ้งหลังซ่อม',
            ...$specific,
            'condition' => 'ตรวจสภาพภายนอกและความเรียบร้อยของเครื่อง',
            'accessories' => 'ตรวจความครบถ้วนตามรายการอุปกรณ์ที่รับไว้',
        ];
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    protected function casts(): array
    {
        return ['items' => 'array', 'passed' => 'boolean'];
    }
}
