<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\IssuedDocument;
use App\Support\QuotationAmounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentIssuanceSettingsController extends Controller
{
    public function show(Branch $branch): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
        $series = DB::table('document_series')->where('branch_id', $branch->id)->get()->keyBy('kind');

        return response()->view('documents.settings', compact('branch', 'series'))->header('Cache-Control', 'private, no-store');
    }

    public function tax(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
        $fields = $request->validate([
            'enabled' => ['required', 'boolean'],
            'abbreviated' => ['required', 'boolean'],
            'rate' => ['required_if:enabled,1', 'nullable', QuotationAmounts::MONEY, 'numeric', 'min:0', 'max:100'],
            'office_number' => ['required_if:enabled,1', 'nullable', 'digits:5'],
        ]);
        DB::transaction(function () use ($branch, $fields): void {
            $branch = Branch::lockForUpdate()->findOrFail($branch->id);
            Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
            $seller = $branch->documentSeller();
            if ($fields['enabled'] && (empty($seller['address']) || empty($seller['name']) || ! preg_match('/^[0-9]{13}$/D', $seller['tax_id'] ?? ''))) {
                throw ValidationException::withMessages(['enabled' => 'กรุณาตั้งชื่อ ที่อยู่ และเลขผู้เสียภาษี 13 หลักของผู้ขายในหัวเอกสารสาขาก่อนเปิดใช้งาน']);
            }
            $branch->issuance_settings = [
                'enabled' => (bool) $fields['enabled'], 'abbreviated' => (bool) $fields['enabled'] && (bool) $fields['abbreviated'],
                'rate' => QuotationAmounts::hundredths($fields['rate'] ?? 0), 'office_number' => $fields['office_number'] ?? null,
            ];
            $branch->save();
        }, 3);

        return back()->with('status', 'บันทึกการตั้งค่าใบกำกับภาษีแล้ว');
    }

    public function series(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
        $fields = $request->validate([
            'kind' => ['required', Rule::in(array_keys(IssuedDocument::KINDS))],
            'prefix' => ['required', 'regex:/^[A-Z][A-Z0-9]{1,11}$/D'],
            'next_number' => ['required', 'integer', 'between:1,999999999999'],
        ]);
        DB::transaction(function () use ($request, $branch, $fields): void {
            $branch = Branch::lockForUpdate()->findOrFail($branch->id);
            Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
            $key = ['branch_id' => $branch->id, 'kind' => $fields['kind']];
            $series = DB::table('document_series')->where('branch_id', $branch->id);
            $row = (clone $series)->where('kind', $fields['kind'])->first();
            $current = (int) ($row?->next_number ?? 1);
            $otherDefault = collect(IssuedDocument::KINDS)->except($fields['kind'])->contains(fn ($definition) => $definition[1] === $fields['prefix']);
            $previousUse = $branch->issuedDocuments()->where('kind', '<>', $fields['kind'])->where('number', 'like', $fields['prefix'].'-%')->exists();
            if ($otherDefault || $previousUse || $series->where('kind', '<>', $fields['kind'])->where('prefix', $fields['prefix'])->exists()) {
                throw ValidationException::withMessages(['prefix' => 'คำนำหน้านี้สงวนไว้หรือใช้งานในเอกสารประเภทอื่น']);
            }
            if ((int) $fields['next_number'] < $current) {
                throw ValidationException::withMessages(['next_number' => 'เลขถัดไปต้องไม่น้อยกว่าเลขปัจจุบัน']);
            }
            $before = ['prefix' => $row?->prefix ?? IssuedDocument::KINDS[$fields['kind']][1], 'next_number' => $current];
            $after = ['prefix' => $fields['prefix'], 'next_number' => (int) $fields['next_number']];
            if ($before === $after) {
                return;
            }
            DB::table('document_series')->updateOrInsert($key, $after);
            AuditEvent::create([
                'organization_id' => $branch->organization_id, 'branch_id' => $branch->id, 'actor_id' => $request->user()->id,
                'actor_name' => $request->user()->name, 'subject_type' => 'document_series',
                'subject_reference' => strtoupper($fields['kind']), 'operation' => 'updated',
                'before_values' => $before, 'after_values' => $after, 'occurred_at' => now(),
            ]);
        }, 3);

        return back()->with('status', 'บันทึกชุดเลขเอกสารแล้ว');
    }
}
