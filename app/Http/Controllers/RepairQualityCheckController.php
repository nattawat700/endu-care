<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Enums\RepairStatus;
use App\Models\Branch;
use App\Models\RepairJob;
use App\Models\RepairQualityCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RepairQualityCheckController extends Controller
{
    public function store(Request $request, Branch $branch, RepairJob $repair): RedirectResponse
    {
        $repair->setRelation('branch', $branch);
        Gate::authorize('view', $repair);
        Gate::authorize('permission', [$branch, BranchPermission::InspectRepair]);
        $checklist = RepairQualityCheck::checklistFor($repair->work_kind);
        $rules = [
            'assignment_version' => ['required', 'integer', 'min:0'],
            'workflow_version' => ['required', 'integer', 'min:0'],
            'qc_results' => ['required', 'array:'.implode(',', array_keys($checklist))],
            'qc_note' => ['nullable', 'string', 'max:2000'],
        ];
        foreach (array_keys($checklist) as $key) {
            $rules['qc_results.'.$key] = ['required', Rule::in(['pass', 'fail'])];
        }

        $fields = $request->validate($rules, [
            'qc_results.required' => 'กรุณาตรวจและระบุผลทุกข้อ',
            'qc_results.*.required' => 'กรุณาระบุผลตรวจข้อนี้',
            'qc_results.*.in' => 'กรุณาเลือกผ่านหรือไม่ผ่าน',
        ]);

        $passed = ! in_array('fail', $fields['qc_results'], true);
        $note = $fields['qc_note'] ?? null;
        if (! $passed && $note === null) {
            throw ValidationException::withMessages(['qc_note' => 'กรุณาระบุสิ่งที่ต้องแก้ไขเมื่อ QC ไม่ผ่าน']);
        }

        DB::transaction(function () use ($request, $branch, $repair, $checklist, $fields, $passed, $note): void {
            $job = $branch->repairs()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $job->setRelation('branch', $branch);
            Gate::authorize('view', $job);
            Gate::authorize('permission', [$branch, BranchPermission::InspectRepair]);
            if ($job->status !== RepairStatus::AwaitingQualityCheck || $job->workflow_version !== (int) $fields['workflow_version']
                || $job->assignment_version !== (int) $fields['assignment_version']) {
                throw ValidationException::withMessages(['qc_results' => 'คิวงานหรือผู้รับผิดชอบเปลี่ยนแล้ว กรุณาตรวจใบงานล่าสุด']);
            }

            $items = [];
            foreach ($checklist as $key => $label) {
                $items[] = ['key' => $key, 'label' => $label, 'result' => $fields['qc_results'][$key]];
            }
            $job->qualityChecks()->create([
                'inspected_by' => $request->user()->id,
                'workflow_version' => $job->workflow_version + 1,
                'items' => $items,
                'passed' => $passed,
                'note' => $note,
            ]);

            $job->moveTo(
                $passed ? RepairStatus::Ready : RepairStatus::Repairing,
                $request->user(),
                $passed ? 'ตรวจ QC ผ่าน' : 'ตรวจ QC ไม่ผ่าน ส่งกลับแก้ไข',
            );
        }, 3);

        return redirect()->to(route('repairs.show', [$branch, $repair]).'#repair-qc')
            ->with('status', $passed ? 'QC ผ่าน พร้อมส่งมอบ' : 'QC ไม่ผ่าน ส่งกลับซ่อมแล้ว');
    }
}
