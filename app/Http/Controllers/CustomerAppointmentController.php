<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAppointment;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerAppointmentController extends Controller
{
    public function index(Request $request, Branch $branch): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);
        $filter = $request->query('status');
        $filter = is_string($filter) && array_key_exists($filter, CustomerAppointment::STATUSES) ? $filter : 'scheduled';
        $appointments = $branch->appointments()->where('status', $filter)
            ->with(['customer:id,name', 'responsible:id,name'])
            ->orderBy('starts_at', $filter === 'scheduled' ? 'asc' : 'desc')->orderBy('id')
            ->cursorPaginate(25)->appends(['status' => $filter]);

        return response()->view('crm.appointments.index', compact('branch', 'appointments', 'filter'), headers: ['Cache-Control' => 'private, no-store']);
    }

    public function create(Branch $branch, Customer $customer): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);

        return $this->form($branch, $customer, new CustomerAppointment);
    }

    public function store(Request $request, Branch $branch, Customer $customer): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);
        $fields = $this->fields($request, ['submission_id' => ['required', 'uuid']]);

        DB::transaction(function () use ($branch, $customer, $fields): void {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            if ($branch->appointments()->where('submission_id', $fields['submission_id'])->exists()) {
                return;
            }

            $this->ensureActiveStaff($branch, (int) $fields['responsible_user_id']);
            $branch->appointments()->create($fields + ['customer_id' => $customer->id]);
        }, 3);

        return redirect()->route('crm.customers.show', [$branch, $customer])->with('status', 'บันทึกนัดหมายแล้ว');
    }

    public function edit(Branch $branch, CustomerAppointment $appointment): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);

        return $this->form($branch, $appointment->customer, $appointment);
    }

    public function update(Request $request, Branch $branch, CustomerAppointment $appointment): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);
        $action = $request->validate([
            'action' => ['required', Rule::in(['reschedule', 'completed', 'cancelled'])],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        $fields = $action['action'] === 'reschedule' ? $this->fields($request) : ['status' => $action['action']];

        DB::transaction(function () use ($branch, $appointment, $action, $fields): void {
            $current = $branch->appointments()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'scheduled' || $current->version !== (int) $action['version']) {
                throw ValidationException::withMessages(['action' => 'นัดนี้เปลี่ยนแปลงแล้ว กรุณาตรวจสถานะล่าสุด']);
            }

            if (isset($fields['responsible_user_id'])) {
                $this->ensureActiveStaff($branch, (int) $fields['responsible_user_id']);
            }

            $current->update($fields + ['version' => $current->version + 1]);
        }, 3);

        return back()->with('status', 'อัปเดตนัดหมายแล้ว');
    }

    private function form(Branch $branch, Customer $customer, CustomerAppointment $appointment): Response
    {
        $staff = $branch->memberships()->whereHas('user', fn ($query) => $query->whereNull('suspended_at'))
            ->with('user:id,name')->orderBy('user_id')->get();
        $submissionId = (string) Str::uuid();

        return response()->view('crm.appointments.form', compact('branch', 'customer', 'appointment', 'staff', 'submissionId'), headers: ['Cache-Control' => 'private, no-store']);
    }

    private function fields(Request $request, array $rules = []): array
    {
        $fields = $request->validate($rules + [
            'kind' => ['required', Rule::in(array_keys(CustomerAppointment::KINDS))],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'responsible_user_id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['starts_at.date_format' => 'กรุณาระบุวันและเวลานัดหมาย']);

        $time = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $fields['starts_at'], 'Asia/Bangkok')->utc();
        if ($time->lte(now())) {
            throw ValidationException::withMessages(['starts_at' => 'กรุณาเลือกวันและเวลานัดในอนาคต']);
        }

        $fields['starts_at'] = $time;

        return $fields;
    }

    private function ensureActiveStaff(Branch $branch, int $userId): void
    {
        $membership = $branch->memberships()->where('user_id', $userId)
            ->whereHas('user', fn ($query) => $query->whereNull('suspended_at'))
            ->lockForUpdate()->first();
        if ($membership === null) {
            throw ValidationException::withMessages(['responsible_user_id' => 'กรุณาเลือกพนักงานที่ยังทำงานในสาขานี้']);
        }
    }
}
