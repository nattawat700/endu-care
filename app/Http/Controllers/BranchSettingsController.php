<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Enums\BranchRole;
use App\Models\Branch;
use App\Models\BranchCounter;
use App\Models\BranchPaymentPoint;
use App\Models\BranchPrinterProfile;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BranchSettingsController extends Controller
{
    public const WORKING_DAYS = [1 => 'จันทร์', 2 => 'อังคาร', 3 => 'พุธ', 4 => 'พฤหัสบดี', 5 => 'ศุกร์', 6 => 'เสาร์', 7 => 'อาทิตย์'];

    public function show(Branch $branch): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
        $branch->load(['paymentPoints' => fn ($query) => $query->orderBy('code'), 'printerProfiles' => fn ($query) => $query->orderBy('name'),
            'counters.paymentPoint', 'counters.printerProfile']);

        return view('organizations.branch', compact('branch'));
    }

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manage', $organization);
        $fields = $this->branchFields($request);
        $actor = $request->user();

        $branch = DB::transaction(function () use ($organization, $fields, $actor): Branch {
            $current = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('manage', $current);
            abort_if($current->suspended_at !== null, 409);
            $branch = $current->branches()->firstOrCreate(['code' => $fields['code']], $fields + ['receipt_layout' => 'generic']);
            if (! $branch->wasRecentlyCreated) {
                throw ValidationException::withMessages(['code' => 'รหัสสาขานี้มีอยู่ในกิจการแล้ว']);
            }

            $isOwner = $actor->memberships()->where('role', BranchRole::Owner)
                ->whereHas('branch', fn ($branches) => $branches->whereBelongsTo($current))->exists();
            $branch->memberships()->create(['user_id' => $actor->id, 'role' => $isOwner ? BranchRole::Owner : BranchRole::Hq]);
            if ($current->owner_id !== null && (int) $current->owner_id !== $actor->id) {
                $branch->memberships()->create(['user_id' => $current->owner_id, 'role' => BranchRole::Owner]);
            }

            return $branch;
        }, 3);

        return redirect()->route('branch-settings.show', $branch)->with('status', 'สร้างสาขาแล้ว');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
        $fields = $this->branchFields($request);

        DB::transaction(function () use ($branch, $fields): void {
            Organization::whereKey($branch->organization_id)->lockForUpdate()->firstOrFail();
            $current = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('permission', [$current, BranchPermission::ManageBranchSettings]);
            if ($current->organization->branches()->where('code', $fields['code'])->whereKeyNot($current->id)->exists()) {
                throw ValidationException::withMessages(['code' => 'รหัสสาขานี้มีอยู่ในกิจการแล้ว']);
            }

            $current->update($fields + ['receipt_layout' => $current->usesEnduFixReceipt() ? 'endu_fix' : 'generic']);
        }, 3);

        return back()->with('status', 'บันทึกข้อมูลสาขาแล้ว');
    }

    public function schedule(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
        $fields = $request->validate([
            'working_days' => ['required', 'array', 'list', 'min:1', 'max:7'],
            'working_days.*' => ['integer', 'between:1,7', 'distinct'],
            'shift_name' => ['required', 'string', 'max:80'],
            'shift_start' => ['required', 'date_format:H:i'],
            'shift_end' => ['required', 'date_format:H:i', 'different:shift_start'],
            'day_cutoff' => ['required', 'date_format:H:i'],
        ]);
        $fields['working_days'] = array_map('intval', $fields['working_days']);
        sort($fields['working_days']);
        DB::transaction(fn () => Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail()->update($fields), 3);

        return back()->with('status', 'บันทึกวันทำการ กะ และจุดตัดวันแล้ว');
    }

    public function paymentPoint(Request $request, Branch $branch, ?BranchPaymentPoint $paymentPoint = null): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
        $code = Rule::unique('branch_payment_points')->where('branch_id', $branch->id);
        if ($paymentPoint !== null) {
            $code->ignore($paymentPoint);
        }
        $this->normalizeCode($request);
        $fields = $request->validate(['code' => ['required', 'alpha_dash:ascii', 'max:32', $code], 'name' => ['required', 'string', 'max:100']]);
        DB::transaction(fn () => $paymentPoint
            ? $branch->paymentPoints()->whereKey($paymentPoint->id)->lockForUpdate()->firstOrFail()->update($fields)
            : $branch->paymentPoints()->create($fields), 3);

        return back()->with('status', 'บันทึกจุดรับเงินแล้ว');
    }

    public function printerProfile(Request $request, Branch $branch, ?BranchPrinterProfile $printerProfile = null): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
        $name = Rule::unique('branch_printer_profiles')->where('branch_id', $branch->id);
        if ($printerProfile !== null) {
            $name->ignore($printerProfile);
        }
        $fields = $request->validate([
            'name' => ['required', 'string', 'max:100', $name],
            'device_name' => ['required', 'string', 'max:150'],
            'paper' => ['required', Rule::in(array_keys(BranchPrinterProfile::PAPERS))],
        ]);
        DB::transaction(fn () => $printerProfile
            ? $branch->printerProfiles()->whereKey($printerProfile->id)->lockForUpdate()->firstOrFail()->update($fields)
            : $branch->printerProfiles()->create($fields), 3);

        return back()->with('status', 'บันทึกโปรไฟล์เครื่องพิมพ์แล้ว');
    }

    public function counter(Request $request, Branch $branch, ?BranchCounter $counter = null): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
        $code = Rule::unique('branch_counters')->where('branch_id', $branch->id);
        if ($counter !== null) {
            $code->ignore($counter);
        }
        $this->normalizeCode($request);
        $fields = $request->validate([
            'code' => ['required', 'alpha_dash:ascii', 'max:32', $code],
            'name' => ['required', 'string', 'max:100'],
            'payment_point_id' => ['required', 'integer', Rule::exists('branch_payment_points', 'id')->where('branch_id', $branch->id)],
            'printer_profile_id' => ['required', 'integer', Rule::exists('branch_printer_profiles', 'id')->where('branch_id', $branch->id)],
        ], ['payment_point_id.exists' => 'กรุณาเลือกจุดรับเงินของสาขานี้', 'printer_profile_id.exists' => 'กรุณาเลือกเครื่องพิมพ์ของสาขานี้']);
        DB::transaction(fn () => $counter
            ? $branch->counters()->whereKey($counter->id)->lockForUpdate()->firstOrFail()->update($fields)
            : $branch->counters()->create($fields), 3);

        return back()->with('status', 'บันทึกเคาน์เตอร์แล้ว');
    }

    private function branchFields(Request $request): array
    {
        $this->normalizeCode($request);

        return $request->validate([
            'code' => ['required', 'string', 'alpha_dash:ascii', 'max:32'],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:750'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ()-]{6,28}$/D'],
            'opening_hours' => ['required', 'string', 'max:250'],
        ], ['phone.regex' => 'กรุณากรอกเบอร์โทรของสาขา']);
    }

    private function normalizeCode(Request $request): void
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => Str::lower($request->input('code'))]);
        }
    }
}
