<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerDevice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CustomerDeviceController extends Controller
{
    public function show(Branch $branch, Customer $customer, CustomerDevice $customerDevice): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);
        $repairs = $customerDevice->repairs()->whereBelongsTo($branch)
            ->orderByDesc('id')->cursorPaginate(20);

        return response()->view('crm.customers.device', [
            'branch' => $branch, 'customer' => $customer, 'device' => $customerDevice, 'repairs' => $repairs,
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, Branch $branch, Customer $customer): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);
        $fields = $request->validate([
            'model' => ['required', 'string', 'max:150'],
            'serial_number' => ['nullable', 'required_without:imei', 'string', 'max:80'],
            'imei' => ['nullable', 'digits:15'],
        ] + $this->warrantyRules(), [
            'serial_number.required_without' => 'กรุณาระบุ Serial No. หรือ IMEI อย่างน้อยหนึ่งรายการ',
            'imei.digits' => 'IMEI ต้องเป็นตัวเลข 15 หลัก',
        ]);
        $fields['serial_number'] = CustomerDevice::serialKey($fields['serial_number'] ?? null);

        $device = DB::transaction(function () use ($customer, $fields): CustomerDevice {
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            foreach (['serial_number', 'imei'] as $key) {
                if (isset($fields[$key]) && $customer->customerDevices()->where($key, $fields[$key])->exists()) {
                    throw ValidationException::withMessages([$key => 'หมายเลขนี้มีอยู่ในทะเบียนเครื่องของลูกค้ารายนี้แล้ว']);
                }
            }

            return $customer->customerDevices()->create($fields);
        }, 3);

        return redirect()->route('crm.devices.show', [$branch, $customer, $device])->with('status', 'บันทึกเครื่องแล้ว');
    }

    public function update(Request $request, Branch $branch, Customer $customer, CustomerDevice $customerDevice): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);
        $fields = $request->validate(['model' => ['required', 'string', 'max:150']] + $this->warrantyRules());
        DB::transaction(fn () => $customerDevice->update($fields), 3);

        return back()->with('status', 'อัปเดตข้อมูลเครื่องแล้ว');
    }

    public function linkRepair(Request $request, Branch $branch, Customer $customer, CustomerDevice $customerDevice): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);
        $fields = $request->validate(['reference' => ['required', 'string', 'regex:/^(?:REP-)?[0-9]{1,18}$/iD']]);
        $repairId = (int) preg_replace('/^REP-/i', '', $fields['reference']);

        DB::transaction(function () use ($branch, $customer, $customerDevice, $repairId): void {
            $repair = $branch->repairs()->whereKey($repairId)->lockForUpdate()->first();
            if ($repair === null || ($repair->customer_id !== null && $repair->customer_id !== $customer->id)
                || ($repair->customer_device_id !== null && $repair->customer_device_id !== $customerDevice->id)
                || ($repair->customer_id === null && Customer::phoneKey($repair->customer_phone) !== $customer->phone_key)
                || ! $customerDevice->matchesIntake($repair)) {
                throw ValidationException::withMessages(['reference' => 'เชื่อมใบงานไม่ได้ กรุณาตรวจเลขใบงาน ลูกค้า และ Serial No. หรือ IMEI ของเครื่อง']);
            }

            $repair->update(['customer_id' => $customer->id, 'customer_device_id' => $customerDevice->id]);
        }, 3);

        return back()->with('status', 'เชื่อมใบรับซ่อมแล้ว');
    }

    private function warrantyRules(): array
    {
        return ['warranty_until' => ['nullable', 'date_format:Y-m-d'], 'warranty_note' => ['nullable', 'string', 'max:1000']];
    }
}
