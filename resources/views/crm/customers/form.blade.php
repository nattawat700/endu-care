@php
    use App\Models\Customer;

    $title = $customer->exists ? 'แก้ไขข้อมูลลูกค้า' : 'เพิ่มลูกค้า';
    $back = $customer->exists ? route('crm.customers.show', [$branch, $customer]) : route('crm.customers.index', $branch);
@endphp

<x-layout :title="$title">
    <div class="endu-page-head">
        <div>
            <a class="endu-text-action" href="{{ $back }}">← ทะเบียนลูกค้า</a>
            <h1>{{ $title }}</h1>
            <p>{{ $branch->name }}</p>
        </div>
    </div>
    <form class="panel max-w-3xl space-y-5 p-5 sm:p-7" method="POST"
        action="{{ $customer->exists ? route('crm.customers.update', [$branch, $customer]) : route('crm.customers.store', $branch) }}">
        @csrf
        @if ($customer->exists)
            @method('PATCH')
        @else
            <input type="hidden" name="submission_id" value="{{ $errors->has('submission_id') ? $submissionId : old('submission_id', $submissionId) }}">
        @endif

        @error('duplicate')
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="alert">
                <p>{{ $message }}</p>
                <div class="mt-2 flex flex-wrap gap-4">
                    <a class="endu-text-action" href="{{ route('crm.customers.index', ['branch' => $branch, 'q' => old('phone')]) }}">ตรวจจากเบอร์โทร</a>
                    <a class="endu-text-action" href="{{ route('crm.customers.index', ['branch' => $branch, 'q' => old('name')]) }}">ตรวจจากชื่อ</a>
                </div>
                <label class="mt-3 flex items-center gap-2">
                    <input type="checkbox" name="confirm_duplicate" value="1">
                    ตรวจแล้วและยืนยันบันทึก
                </label>
            </div>
        @enderror

        <div class="grid gap-4 sm:grid-cols-2">
            <x-repair-input name="name" label="ชื่อลูกค้า" :limit="150" :default="$customer->name" required />
            <x-repair-input name="phone" label="เบอร์โทรลูกค้า" type="tel" :limit="30" :default="$customer->phone" required />
            <x-repair-input name="contact_name" label="ชื่อผู้ติดต่อ" :limit="150" :default="$customer->contact_name" />
            <x-repair-input name="contact_phone" label="เบอร์โทรผู้ติดต่อ" type="tel" :limit="30" :default="$customer->contact_phone" />
            <x-repair-input name="email" label="อีเมล" type="email" :limit="254" :default="$customer->email" />
        </div>
        <div class="grid gap-4 border-t border-slate-200 pt-5 sm:grid-cols-2">
            <x-repair-input name="delivery_address" label="ที่อยู่รับ–ส่ง" :rows="3" :limit="500" :default="$customer->delivery_address" />
            <x-repair-input name="billing_address" label="ที่อยู่ออกเอกสาร" :rows="3" :limit="500" :default="$customer->billing_address" />
        </div>
        <div class="grid gap-4 border-t border-slate-200 pt-5 sm:grid-cols-2">
            <x-crm-select name="service_channel" label="ช่องทางแจ้งงานซ่อม" :options="Customer::CHANNELS" :default="$customer->service_channel" />
            <x-crm-select name="marketing_preference" label="ความประสงค์รับข่าวการตลาด" :options="Customer::MARKETING" :default="$customer->marketing_preference ?? 'unspecified'" />
        </div>
        <div class="flex flex-wrap justify-end gap-2">
            <a class="button button-secondary" href="{{ $back }}">ยกเลิก</a>
            <button class="button button-primary" type="submit">บันทึกลูกค้า</button>
        </div>
    </form>
</x-layout>
