<?php

namespace App\Enums;

enum BranchRole: string
{
    case Manager = 'manager';
    case Reception = 'reception';
    case Technician = 'technician';
    case Pc = 'pc';
    case Cashier = 'cashier';
    case Stock = 'stock';
    case Accounting = 'accounting';
    case Hr = 'hr';
    case Owner = 'owner';
    case Hq = 'hq';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'ผู้จัดการ',
            self::Reception => 'พนักงานหน้าร้าน',
            self::Technician => 'ช่างซ่อม',
            self::Pc => 'PC',
            self::Cashier => 'แคชเชียร์',
            self::Stock => 'คลัง',
            self::Accounting => 'บัญชี',
            self::Hr => 'HR',
            self::Owner => 'เจ้าของ',
            self::Hq => 'สำนักงานใหญ่ (HQ)',
        };
    }

    public function requiresMfa(): bool
    {
        return in_array($this, [self::Manager, self::Accounting, self::Hr, self::Owner, self::Hq], true);
    }

    public function canManageRole(self $role): bool
    {
        return match ($this) {
            self::Owner => $role !== self::Owner,
            self::Hq => ! in_array($role, [self::Owner, self::Hq], true),
            self::Manager, self::Hr => ! $role->requiresMfa(),
            default => false,
        };
    }

    public function allows(BranchPermission $permission): bool
    {
        $reception = [
            BranchPermission::ReadRepairs, BranchPermission::CreateRepair, BranchPermission::AssignRepair,
            BranchPermission::EditAssessment, BranchPermission::CompleteRepair, BranchPermission::CancelRepair,
            BranchPermission::PrintRepair, BranchPermission::ReadQuotation, BranchPermission::DraftQuotation,
            BranchPermission::ConfirmCustomerPrice, BranchPermission::PrintQuotation, BranchPermission::CustomerData,
            BranchPermission::DeviceAccess, BranchPermission::PrivateCustomerDetails, BranchPermission::InternalNotes,
        ];
        $management = [
            ...$reception, BranchPermission::ReviewQuotation, BranchPermission::ExportRepairs,
            BranchPermission::Costs, BranchPermission::Profit, BranchPermission::ManageStaff,
        ];

        return in_array($permission, match ($this) {
            self::Reception => $reception,
            self::Manager => $management,
            self::Owner => [...$management, BranchPermission::Compensation, BranchPermission::Commissions, BranchPermission::ManageBranchSettings],
            self::Hq => [...$management, BranchPermission::Commissions, BranchPermission::ManageBranchSettings],
            self::Technician => [
                BranchPermission::ReadAssignedRepairs, BranchPermission::EditAssessment, BranchPermission::TechnicianWorkflow,
                BranchPermission::MaskedDeviceIdentifiers,
            ],
            self::Pc => [BranchPermission::ReadRepairs, BranchPermission::CreateRepair],
            self::Cashier => [BranchPermission::ReadRepairs, BranchPermission::ReadQuotation, BranchPermission::ConfirmCustomerPrice, BranchPermission::PrintQuotation, BranchPermission::CustomerData],
            self::Stock => [BranchPermission::Costs],
            self::Accounting => [BranchPermission::Costs, BranchPermission::Profit, BranchPermission::Commissions],
            self::Hr => [BranchPermission::Compensation, BranchPermission::Commissions, BranchPermission::ManageStaff],
        }, true);
    }
}
