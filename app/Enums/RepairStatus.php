<?php

namespace App\Enums;

enum RepairStatus: string
{
    case Received = 'received';
    case Diagnosing = 'diagnosing';
    case AwaitingApproval = 'awaiting_approval';
    case Repairing = 'repairing';
    case WaitingParts = 'waiting_parts';
    case AwaitingQualityCheck = 'awaiting_qc';
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
            self::WaitingParts => 'รออะไหล่',
            self::AwaitingQualityCheck => 'รอตรวจ QC',
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

    /** @return array<string, array{label: string, statuses: list<self>}> */
    public static function operationsQueues(): array
    {
        return [
            'inspection' => ['label' => 'รอตรวจและกำลังตรวจ', 'statuses' => [self::Received, self::Diagnosing]],
            'approval' => ['label' => 'รออนุมัติราคา', 'statuses' => [self::AwaitingApproval]],
            'parts' => ['label' => self::WaitingParts->label(), 'statuses' => [self::WaitingParts]],
            'qc' => ['label' => self::AwaitingQualityCheck->label(), 'statuses' => [self::AwaitingQualityCheck]],
        ];
    }

    /** @return list<self> */
    public function nextStatuses(BranchRole $role): array
    {
        if ($role->allows(BranchPermission::TechnicianWorkflow)) {
            return match ($this) {
                self::Received => [self::Diagnosing],
                self::Diagnosing => [self::AwaitingApproval],
                self::Repairing => [self::WaitingParts, self::AwaitingQualityCheck, self::Ready],
                self::WaitingParts => [self::Repairing],
                self::Ready => [self::Repairing],
                default => [],
            };
        }

        $next = match ($this) {
            self::AwaitingApproval => [self::Repairing, self::ReturnedUnrepaired],
            self::Ready => [self::Delivered, self::ReturnedUnrepaired],
            self::Received, self::Diagnosing, self::Repairing, self::WaitingParts, self::AwaitingQualityCheck => [self::ReturnedUnrepaired],
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
            self::Repairing => match ($this) {
                self::AwaitingQualityCheck => 'ส่งกลับไปแก้ไขหลังตรวจ QC',
                self::WaitingParts => 'ได้รับอะไหล่และเริ่มซ่อมต่อ',
                self::Ready => 'ส่งกลับไปซ่อมต่อ',
                default => 'ยืนยันลูกค้าอนุมัติซ่อม',
            },
            self::WaitingParts => 'ย้ายเข้าคิวรออะไหล่',
            self::AwaitingQualityCheck => 'ส่งเข้าคิวตรวจ QC',
            self::Ready => $this === self::AwaitingQualityCheck ? 'บันทึกผล QC และพร้อมส่งมอบ' : 'บันทึกผลทดสอบและแจ้งว่าพร้อมส่งมอบ',
            self::Delivered => 'ยืนยันส่งมอบให้ลูกค้าแล้ว',
            self::ReturnedUnrepaired => 'ยืนยันส่งเครื่องคืนโดยไม่ซ่อม',
            default => $next->label(),
        };
    }

    public function noteLabel(self $next): ?string
    {
        return match ($next) {
            self::AwaitingApproval => 'ผลตรวจเครื่อง',
            self::Repairing => match ($this) {
                self::AwaitingApproval => 'รายละเอียดการอนุมัติของลูกค้า',
                self::WaitingParts => null,
                default => 'เหตุผลที่ต้องซ่อมต่อ',
            },
            self::WaitingParts => 'อะไหล่ที่รอ',
            self::Ready => 'งานที่ทำและผลทดสอบ',
            self::ReturnedUnrepaired => 'เหตุผลที่ส่งคืนเครื่อง',
            default => null,
        };
    }
}
