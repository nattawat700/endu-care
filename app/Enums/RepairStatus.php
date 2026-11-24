<?php

namespace App\Enums;

enum RepairStatus: string
{
    case Received = 'received';
    case Diagnosing = 'diagnosing';
    case AwaitingApproval = 'awaiting_approval';
    case Repairing = 'repairing';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case ReturnedUnrepaired = 'returned_unrepaired';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'รับเครื่องแล้ว',
            self::Diagnosing => 'กำลังตรวจเครื่อง',
            self::AwaitingApproval => 'รอลูกค้าอนุมัติ',
            self::Repairing => 'กำลังซ่อม',
            self::Ready => 'พร้อมส่งมอบ',
            self::Delivered => 'ส่งมอบแล้ว',
            self::ReturnedUnrepaired => 'ส่งคืนโดยไม่ซ่อม',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Delivered || $this === self::ReturnedUnrepaired;
    }

    public function allowsQuotation(): bool
    {
        return in_array($this, [self::Diagnosing, self::AwaitingApproval, self::Repairing], true);
    }

    /** @return list<self> */
    public function nextStatuses(BranchRole $role): array
    {
        if ($role->allows(BranchPermission::TechnicianWorkflow)) {
            return match ($this) {
                self::Received => [self::Diagnosing],
                self::Diagnosing => [self::AwaitingApproval],
                self::Repairing => [self::Ready],
                self::Ready => [self::Repairing],
                default => [],
            };
        }

        $next = match ($this) {
            self::AwaitingApproval => [self::Repairing, self::ReturnedUnrepaired],
            self::Ready => [self::Delivered],
            default => [],
        };

        return array_values(array_filter($next, fn (self $status): bool => $role->allows(
            $status === self::ReturnedUnrepaired ? BranchPermission::CancelRepair : BranchPermission::CompleteRepair,
        )));
    }

    public function actionLabel(self $next): string
    {
        return match ($next) {
            self::Diagnosing => 'เริ่มตรวจเครื่อง',
            self::AwaitingApproval => 'ส่งผลตรวจให้หน้าร้าน',
            self::Repairing => $this === self::Ready ? 'ส่งกลับไปซ่อมต่อ' : 'ยืนยันลูกค้าอนุมัติซ่อม',
            self::Ready => 'บันทึกผลทดสอบและแจ้งว่าพร้อมส่งมอบ',
            self::Delivered => 'ยืนยันส่งมอบให้ลูกค้าแล้ว',
            self::ReturnedUnrepaired => 'ยืนยันส่งเครื่องคืนโดยไม่ซ่อม',
            default => $next->label(),
        };
    }

    public function noteLabel(self $next): ?string
    {
        return match ($next) {
            self::AwaitingApproval => 'ผลตรวจเครื่อง',
            self::Repairing => $this === self::Ready ? 'เหตุผลที่ต้องซ่อมต่อ' : 'รายละเอียดการอนุมัติของลูกค้า',
            self::Ready => 'งานที่ทำและผลทดสอบ',
            self::ReturnedUnrepaired => 'เหตุผลที่ส่งคืนเครื่อง',
            default => null,
        };
    }
}
