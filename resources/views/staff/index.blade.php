<x-layout title="ผู้ใช้และสิทธิ์">
    <div class="endu-page-head">
        <div>
            <h1>ผู้ใช้และสิทธิ์</h1>
            <p>{{ $branch->organization->name }} / {{ $branch->name }}</p>
        </div>
    </div>

    @if ($errors->any())
        <ul class="mb-4 list-disc space-y-1 rounded-lg border border-red-200 bg-red-50 p-4 pl-8 text-red-800" role="alert">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <section class="panel mb-5 p-5" aria-labelledby="staff-invite">
        <h2 id="staff-invite" class="text-lg font-bold">เชิญพนักงานเข้าร่วมสาขา</h2>
        <form method="POST" action="{{ route('staff.invite', $branch) }}" class="mt-4 grid items-end gap-3 md:grid-cols-[1fr_1fr_1fr_auto]">
            @csrf
            <label class="min-w-0">
                <span class="field-label">ชื่อพนักงาน</span>
                <input class="field-input" name="name" value="{{ is_string(old('name')) ? old('name') : '' }}" maxlength="150" required>
            </label>
            <label class="min-w-0">
                <span class="field-label">อีเมลพนักงาน</span>
                <input class="field-input" type="email" name="email" value="{{ is_string(old('email')) ? old('email') : '' }}" maxlength="254" required>
            </label>
            <label class="min-w-0">
                <span class="field-label">บทบาทในสาขา</span>
                <select class="field-input" name="role" required>
                    <option value="">เลือกบทบาท</option>
                    @foreach ($assignable as $role)
                        <option value="{{ $role->value }}" @selected(old('role') === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </select>
            </label>
            <button class="button button-primary" type="submit">ส่งคำเชิญ</button>
        </form>
        @if ($invitations->isNotEmpty())
            <h3 class="mt-5 font-semibold">คำเชิญที่รอตอบรับ</h3>
            <ul class="mt-2 divide-y divide-slate-100">
                @foreach ($invitations as $invitation)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span class="wrap-break-word">{{ $invitation->email }} · {{ $invitation->role->label() }} · หมดอายุ {{ $invitation->expires_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</span>
                        @if ($actorRole->canManageRole($invitation->role))
                            <form method="POST" action="{{ route('staff.invitations.cancel', [$branch, $invitation]) }}">
                                @csrf
                                @method('DELETE')
                                <button class="button button-secondary" type="submit">ยกเลิกคำเชิญ</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="panel mb-5" aria-labelledby="staff-members">
        <div class="endu-panel-head"><h2 id="staff-members">พนักงานในสาขา</h2></div>
        <div class="endu-table-scroll">
            <table>
                <thead><tr><th scope="col">พนักงาน</th><th scope="col">บทบาท</th><th scope="col">บัญชี</th><th scope="col">จัดการ</th></tr></thead>
                <tbody>
                    @foreach ($memberships as $membership)
                        <tr>
                            <td class="min-w-44">
                                <strong>{{ $membership->user->name }}</strong>
                                <div class="text-xs text-slate-600">{{ $membership->user->email }}</div>
                            </td>
                            <td>{{ $membership->role->label() }}</td>
                            <td>{{ $membership->user->suspended_at ? 'ระงับ' : 'เปิดใช้งาน' }}</td>
                            <td>
                                @if ($membership->user_id !== auth()->id() && $actorRole->canManageRole($membership->role))
                                    <details>
                                        <summary class="endu-text-action cursor-pointer">จัดการสิทธิ์</summary>
                                        <form class="mt-2 flex flex-wrap items-center gap-2" method="POST" action="{{ route('staff.change-role', [$branch, $membership]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <select class="field-input w-auto" name="role" aria-label="บทบาทของ {{ $membership->user->name }}" required>
                                                @foreach ($assignable as $role)
                                                    <option value="{{ $role->value }}" @selected($role === $membership->role)>{{ $role->label() }}</option>
                                                @endforeach
                                            </select>
                                            <button class="button button-secondary" type="submit">บันทึกบทบาท</button>
                                        </form>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @if ($membership->user->memberships->every(fn ($assignment) => ($actorAssignments[$assignment->branch_id] ?? null)?->canManageRole($assignment->role)))
                                                <form class="flex flex-wrap gap-2" method="POST" action="{{ route('staff.account', [$branch, $membership]) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    @if ($membership->user->suspended_at)
                                                        <button class="button button-secondary" name="action" value="restore" type="submit">เปิดใช้บัญชีอีกครั้ง</button>
                                                    @else
                                                        <button class="button button-secondary" name="action" value="revoke_sessions" type="submit">ยุติเซสชัน</button>
                                                        <button class="button button-secondary" name="action" value="suspend" type="submit">ระงับบัญชี</button>
                                                    @endif
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('staff.remove', [$branch, $membership]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button class="button button-secondary" type="submit">ถอนสิทธิ์สาขา</button>
                                            </form>
                                        </div>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($memberships->hasPages())
            <div class="p-4">{{ $memberships->links() }}</div>
        @endif
    </section>

    <section class="panel p-5" aria-labelledby="staff-history">
        <h2 id="staff-history" class="text-lg font-bold">ประวัติการจัดการสิทธิ์</h2>
        <ul class="mt-3 divide-y divide-slate-100">
            @forelse ($history as $event)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span><strong>{{ $event->user->name }}</strong> · {{ match ($event->action) {
                        'invitation_accepted' => 'รับคำเชิญ', 'role_changed' => 'เปลี่ยนบทบาท',
                        'branch_removed' => 'ถอนสิทธิ์สาขา', 'suspend' => 'ระงับบัญชี',
                        'restore' => 'เปิดใช้บัญชีอีกครั้ง', 'revoke_sessions' => 'ยุติเซสชัน',
                        default => $event->action,
                    } }} · {{ $event->role?->label() }} · โดย {{ $event->actor->name }}</span>
                    <time class="text-slate-600" datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</time>
                </li>
            @empty
                <li class="py-2 text-sm text-slate-600">ยังไม่มีรายการในประวัติ</li>
            @endforelse
        </ul>
    </section>
</x-layout>
