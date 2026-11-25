<x-layout title="ชุดบัญชีเอกสารคลัง" active-module="INT">
    <div class="endu-page-head"><div><a class="endu-text-action" href="{{ route('integrations.connections.show', [$branch, $connection]) }}">← {{ $connection->name }}</a><h1>ชุดบัญชีเอกสารคลัง</h1><p>{{ $branch->name }}</p></div></div>
    @if ($errors->any())<ul class="panel mb-4 p-4 text-sm text-red-800" role="alert">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    <section class="panel space-y-3 p-5">
        <h2 class="text-lg font-semibold">ตรวจรายการก่อนส่งออก</h2>
        <p class="text-sm text-slate-600">ไฟล์ CSV มีเดบิต เครดิต รหัสบัญชี และเลขเอกสารเดิม รายการที่ไม่มีต้นทุนบันทึกไว้ให้กรอกต้นทุนต่อหน่วยที่ตรวจแล้ว</p>
        <form method="POST" action="{{ route('integrations.accounting.store', [$branch, $connection]) }}" class="space-y-3">
            @csrf
            <input type="hidden" name="submission_id" value="{{ $submissionId }}">
            <ul class="divide-y divide-slate-200">
                @forelse ($movements as $movement)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                        <label class="flex items-center gap-2"><input type="checkbox" name="movements[]" value="{{ $movement->id }}"> {{ $movement->reference() }} · {{ $movement->sku }} · {{ $movement->quantity }} {{ $movement->unit_name }}</label>
                        @php($cost = $movement->costForQuantity($movement->quantity))
                        @if ($cost === null)
                            <label class="flex items-center gap-2">ต้นทุนต่อหน่วย <input class="field-input w-28" inputmode="decimal" name="legacy_cost[{{ $movement->id }}]" maxlength="10" value="{{ old('legacy_cost.'.$movement->id) }}"></label>
                        @else
                            <span>ต้นทุนรวม {{ \App\Models\RepairQuotation::formatBaht($cost) }} บาท</span>
                        @endif
                    </li>
                @empty <li class="py-3 text-sm text-slate-600">ไม่มีเอกสารที่ยังไม่ส่งออก</li>
                @endforelse
            </ul>
            @if ($movements->isNotEmpty() && $connection->enabled)
                <button class="button button-primary" type="submit">ยืนยันและสร้างชุดบัญชี</button>
            @endif
        </form>
        {{ $movements->links() }}
    </section>
    <section class="panel mt-5 p-5">
        <h2 class="text-lg font-semibold">ชุดที่สร้างแล้ว</h2>
        <ul class="mt-2 divide-y divide-slate-200">
            @forelse ($exports as $export)
                <li class="py-3 text-sm"><a class="endu-text-action" href="{{ route('integrations.accounting.download', [$branch, $connection, $export]) }}">ดาวน์โหลดชุด #{{ $export->id }}</a>
                    · {{ $export->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</li>
            @empty <li class="py-3 text-sm text-slate-600">ยังไม่มีชุดบัญชี</li>
            @endforelse
        </ul>
        {{ $exports->links() }}
    </section>
</x-layout>
