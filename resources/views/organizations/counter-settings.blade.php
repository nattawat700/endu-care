@php
    use App\Models\BranchPrinterProfile;
@endphp

<section class="panel p-5">
    <h2 class="text-lg font-bold">จุดรับเงิน</h2>
    @foreach ([null, ...$branch->paymentPoints] as $point)
        <details class="mt-3 border-b border-slate-100 pb-3">
            <summary class="endu-text-action cursor-pointer">{{ $point ? $point->name.' · '.$point->code : 'เพิ่มจุดรับเงิน' }}</summary>
            <form method="POST" action="{{ $point ? route('branch-settings.payment-points.update', [$branch, $point]) : route('branch-settings.payment-points.store', $branch) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                @csrf
                @if ($point) @method('PATCH') @endif
                <label class="block"><span class="field-label">ชื่อจุดรับเงิน</span><input class="field-input" name="name" value="{{ $point?->name }}" maxlength="100" required></label>
                <label class="block"><span class="field-label">รหัสจุดรับเงิน</span><input class="field-input" name="code" value="{{ $point?->code }}" maxlength="32" required></label>
                <div class="sm:col-span-2"><button class="button button-primary" type="submit">บันทึกจุดรับเงิน</button></div>
            </form>
        </details>
    @endforeach
</section>

<section class="panel p-5">
    <h2 class="text-lg font-bold">โปรไฟล์เครื่องพิมพ์</h2>
    @foreach ([null, ...$branch->printerProfiles] as $printer)
        <details class="mt-3 border-b border-slate-100 pb-3">
            <summary class="endu-text-action cursor-pointer">{{ $printer ? $printer->name.' · '.BranchPrinterProfile::PAPERS[$printer->paper] : 'เพิ่มโปรไฟล์เครื่องพิมพ์' }}</summary>
            <form method="POST" action="{{ $printer ? route('branch-settings.printer-profiles.update', [$branch, $printer]) : route('branch-settings.printer-profiles.store', $branch) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                @csrf
                @if ($printer) @method('PATCH') @endif
                <label class="block"><span class="field-label">ชื่อโปรไฟล์</span><input class="field-input" name="name" value="{{ $printer?->name }}" maxlength="100" required></label>
                <label class="block"><span class="field-label">ชื่อเครื่องพิมพ์</span><input class="field-input" name="device_name" value="{{ $printer?->device_name }}" maxlength="150" required></label>
                <label class="block"><span class="field-label">ขนาดกระดาษ</span>
                    <select class="field-input" name="paper">
                        @foreach (BranchPrinterProfile::PAPERS as $key => $label)
                            <option value="{{ $key }}" @selected($printer?->paper === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="self-end"><button class="button button-primary" type="submit">บันทึกโปรไฟล์</button></div>
            </form>
        </details>
    @endforeach
</section>

<section class="panel p-5 xl:col-span-2">
    <h2 class="text-lg font-bold">เคาน์เตอร์</h2>
    @if ($branch->paymentPoints->isEmpty() || $branch->printerProfiles->isEmpty())
        <p class="mt-3 text-sm text-slate-600">เพิ่มจุดรับเงินและโปรไฟล์เครื่องพิมพ์ของสาขาก่อนตั้งเคาน์เตอร์</p>
    @else
        @foreach ([null, ...$branch->counters] as $counter)
            <details class="mt-3 border-b border-slate-100 pb-3">
                <summary class="endu-text-action cursor-pointer">{{ $counter ? $counter->name.' · '.$counter->paymentPoint->name.' · '.$counter->printerProfile->name : 'เพิ่มเคาน์เตอร์' }}</summary>
                <form method="POST" action="{{ $counter ? route('branch-settings.counters.update', [$branch, $counter]) : route('branch-settings.counters.store', $branch) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                    @csrf
                    @if ($counter) @method('PATCH') @endif
                    <label class="block"><span class="field-label">ชื่อเคาน์เตอร์</span><input class="field-input" name="name" value="{{ $counter?->name }}" maxlength="100" required></label>
                    <label class="block"><span class="field-label">รหัสเคาน์เตอร์</span><input class="field-input" name="code" value="{{ $counter?->code }}" maxlength="32" required></label>
                    <label class="block"><span class="field-label">จุดรับเงิน</span>
                        <select class="field-input" name="payment_point_id" required>
                            @foreach ($branch->paymentPoints as $point)
                                <option value="{{ $point->id }}" @selected($counter?->payment_point_id == $point->id)>{{ $point->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block"><span class="field-label">โปรไฟล์เครื่องพิมพ์</span>
                        <select class="field-input" name="printer_profile_id" required>
                            @foreach ($branch->printerProfiles as $printer)
                                <option value="{{ $printer->id }}" @selected($counter?->printer_profile_id == $printer->id)>{{ $printer->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="sm:col-span-2"><button class="button button-primary" type="submit">บันทึกเคาน์เตอร์</button></div>
                </form>
            </details>
        @endforeach
    @endif
</section>
