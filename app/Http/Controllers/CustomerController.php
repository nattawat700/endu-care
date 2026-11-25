<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Enums\RepairStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Support\CustomerData;
use App\Support\CustomerTaxProfile;
use App\Support\RepairBilling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(Request $request, Branch $branch): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);
        $search = $request->query('q');
        $search = is_string($search) ? Str::substr(trim($search), 0, 150) : '';
        $customers = $branch->customers()
            ->when($search !== '', function ($query) use ($search): void {
                if (preg_match('/^\+?[0-9][0-9 ()-]*$/D', $search)) {
                    $query->whereLike('phone_key', Customer::phoneKey($search).'%');

                    return;
                }

                $name = strtr(Customer::nameKey($search), ['!' => '!!', '%' => '!%', '_' => '!_']);
                $query->whereRaw("name_key LIKE ? ESCAPE '!'", ['%'.$name.'%']);
            })->orderByDesc('id')->cursorPaginate(25)->appends(['q' => $search]);

        return response()->view('crm.customers.index', compact('branch', 'customers', 'search'), headers: ['Cache-Control' => 'private, no-store']);
    }

    public function create(Branch $branch): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);

        return response()->view('crm.customers.form', [
            'branch' => $branch, 'customer' => new Customer, 'submissionId' => (string) Str::uuid(),
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);
        $fields = CustomerData::prepare($request->all(), ['submission_id' => ['required', 'uuid']]);

        $customer = DB::transaction(function () use ($request, $branch, $fields): Customer {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $existing = $branch->customers()->where('submission_id', $fields['submission_id'])->first();
            if ($existing !== null) {
                return $existing;
            }

            $this->checkDuplicate($request, $branch, $fields);

            return $branch->customers()->create($fields);
        }, 3);

        return redirect()->route('crm.customers.show', [$branch, $customer])->with('status', 'บันทึกลูกค้าแล้ว');
    }

    public function show(Branch $branch, Customer $customer): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);
        $devices = $customer->customerDevices()->orderByDesc('id')->cursorPaginate(15, cursorName: 'devices');
        $repairs = $customer->repairs()->whereBelongsTo($branch)->orderByDesc('id')->cursorPaginate(15, cursorName: 'repairs');
        $appointments = $customer->appointments()->whereBelongsTo($branch)->with('responsible:id,name')
            ->orderByDesc('starts_at')->orderByDesc('id')->cursorPaginate(10, cursorName: 'appointments');

        $sales = $customer->posSales()->where('branch_id', $branch->id)->whereNotNull('paid_at')
            ->latest('id')->cursorPaginate(10, cursorName: 'pos_sales');

        $claims = $branch->serviceClaims()->where('customer_id', $customer->id)->latest('id')->cursorPaginate(10, cursorName: 'claims');
        $outstanding = collect($repairs->items())->reject(fn ($job) => $job->status === RepairStatus::ReturnedUnrepaired)
            ->mapWithKeys(fn ($job) => [$job->id => RepairBilling::summary($job)['remaining']])
            ->filter(fn ($amount) => $amount > 0);

        return response()->view('crm.customers.show', compact('branch', 'customer', 'devices', 'repairs', 'appointments', 'sales', 'claims', 'outstanding'), headers: ['Cache-Control' => 'private, no-store']);
    }

    public function taxProfile(Request $request, Branch $branch, Customer $customer): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);
        Gate::authorize('permission', [$branch, BranchPermission::PrivateCustomerDetails]);
        $profile = CustomerTaxProfile::fromRequest($request);
        DB::transaction(function () use ($branch, $customer, $profile) {
            Branch::lockForUpdate()->findOrFail($branch->id);
            $current = $branch->customers()->findOrFail($customer->id);
            $current->tax_profile = $profile;
            $current->save();
        }, 3);

        return back()->with('status', 'บันทึกข้อมูลออกใบกำกับภาษีแล้ว');
    }

    public function edit(Branch $branch, Customer $customer): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);

        return response()->view('crm.customers.form', compact('branch', 'customer'), headers: ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request, Branch $branch, Customer $customer): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);
        $fields = CustomerData::prepare($request->all());

        DB::transaction(function () use ($request, $branch, $customer, $fields): void {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $current = $branch->customers()->lockForUpdate()->findOrFail($customer->id);
            if ($current->phone_key !== $fields['phone_key'] || $current->name_key !== $fields['name_key']) {
                $this->checkDuplicate($request, $branch, $fields, $current);
            }

            $current->update($fields);
        }, 3);

        return redirect()->route('crm.customers.show', [$branch, $customer])->with('status', 'อัปเดตข้อมูลลูกค้าแล้ว');
    }

    private function checkDuplicate(Request $request, Branch $branch, array $fields, ?Customer $except = null): void
    {
        if ($request->boolean('confirm_duplicate')) {
            return;
        }

        $duplicate = $branch->customers()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->where(fn ($query) => $query->where('phone_key', $fields['phone_key'])->orWhere('name_key', $fields['name_key']))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['duplicate' => 'พบลูกค้าที่มีชื่อหรือเบอร์โทรตรงกันในสาขานี้ กรุณาตรวจทะเบียนก่อนยืนยันบันทึก']);
        }
    }
}
