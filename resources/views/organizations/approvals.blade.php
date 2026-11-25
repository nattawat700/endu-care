<x-layout title="คำขออนุมัติรายการ">
    <div class="endu-page-head">
        <div><h1>คำขออนุมัติรายการ</h1><p>{{ $branch->organization->name }} / {{ $branch->name }}</p></div>
    </div>

    @include('organizations.errors')

    <section class="panel mb-5 p-5">
        <h2 class="text-lg font-bold">บันทึกรายการ</h2>
        @if (collect($kinds)->contains(fn ($kind) => isset($rules[$kind->value])))
            <form method="POST" action="{{ route('organization-approvals.store', $branch) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                @csrf
                <input type="hidden" name="submission_id" value="{{ $submissionId }}">
                <label class="block"><span class="field-label">ประเภทรายการ</span>
                    <select class="field-input" name="kind" required>
                        @foreach ($kinds as $kind)
                            @if (isset($rules[$kind->value]))
                                <option value="{{ $kind->value }}" @selected(old('kind') === $kind->value)>{{ $kind->label() }} · เพดาน {{ $kind->format($rules[$kind->value]['limit']) }} {{ $kind->unit() }}</option>
                            @endif
                        @endforeach
                    </select>
                </label>
                <label class="block"><span class="field-label">เลขอ้างอิงรายการ</span><input class="field-input" name="reference" value="{{ old('reference') }}" maxlength="100" required></label>
                <label class="block"><span class="field-label">จำนวน (บาทสำหรับส่วนลด/คืนเงิน หรือหน่วยสำหรับปรับสต๊อก)</span><input class="field-input" name="amount" inputmode="decimal" value="{{ old('amount') }}" required></label>
                <label class="block sm:col-span-2"><span class="field-label">เหตุผล</span><textarea class="field-input" name="reason" maxlength="1500" rows="2" required>{{ old('reason') }}</textarea></label>
                <div class="sm:col-span-2"><button class="button button-primary" type="submit">บันทึกรายการ</button></div>
            </form>
        @else
            <p class="mt-3 text-sm text-slate-600">ให้เจ้าของกิจการหรือ HQ ตั้งเพดานและผู้อนุมัติก่อนบันทึกรายการ</p>
        @endif
    </section>

    <section class="panel p-5">
        <h2 class="text-lg font-bold">คิวและผลการพิจารณา</h2>
        <div class="mt-3 space-y-3">
            @forelse ($approvals as $approval)
                <article class="rounded-lg border border-slate-200 p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold wrap-break-word">{{ $approval->kind->label() }} · {{ $approval->reference }}</h3>
                        <span @class(['badge', 'bg-amber-100 text-amber-900' => $approval->status === 'pending', 'badge-neutral' => $approval->status !== 'pending'])>{{ $approval->statusLabel() }}</span>
                    </div>
                    @if ($approval->posSale)
                        <a class="endu-text-action mt-1 inline-block" href="{{ route('pos.show', [$branch, $approval->posSale]) }}">ดูบิลขาย</a>
                    @endif
                    <p class="mt-1 text-sm">จำนวน {{ $approval->kind->format($approval->amount) }} {{ $approval->kind->unit() }} · เพดาน {{ $approval->kind->format($approval->limit) }} {{ $approval->kind->unit() }}</p>
                    <p class="mt-1 text-sm text-slate-600">ผู้ขอ {{ $approval->requester->name }} · บทบาทผู้อนุมัติ {{ $approval->review_role->label() }}</p>
                    <p class="mt-1 whitespace-pre-wrap wrap-break-word text-sm">{{ $approval->reason }}</p>
                    @if ($approval->reviewed_at)
                        <p class="mt-2 text-sm">พิจารณาโดย {{ $approval->reviewer->name }} · {{ $approval->reviewed_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</p>
                        <p class="mt-1 whitespace-pre-wrap wrap-break-word text-sm">{{ $approval->decision_reason }}</p>
                    @elseif ($approval->status === 'pending' && $role === $approval->review_role && (int) $approval->requested_by !== auth()->id() && (int) $approval->posSale?->created_by !== auth()->id())
                        <form method="POST" action="{{ route('organization-approvals.decide', [$branch, $approval]) }}" class="mt-3 max-w-lg space-y-2">
                            @csrf
                            @method('PATCH')
                            <label class="block">
                                <span class="field-label">เหตุผลพิจารณา</span>
                                <textarea class="field-input" name="decision_reason" maxlength="1500" rows="2" required></textarea>
                            </label>
                            <div class="flex flex-wrap gap-2">
                                <button class="button button-primary" name="status" value="approved" type="submit">อนุมัติ</button>
                                <button class="button button-secondary" name="status" value="rejected" type="submit">ไม่อนุมัติ</button>
                            </div>
                        </form>
                    @endif
                </article>
            @empty
                <p class="text-sm text-slate-600">ยังไม่มีรายการ</p>
            @endforelse
        </div>
        @if ($approvals->hasPages()) <div class="mt-4">{{ $approvals->links() }}</div> @endif
    </section>
</x-layout>
