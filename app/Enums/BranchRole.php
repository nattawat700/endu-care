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
            BranchPermission::EditAssessment, BranchPermission::EditRepairDeadline, BranchPermission::CompleteRepair, BranchPermission::CancelRepair,
            BranchPermission::PrintRepair, BranchPermission::IssueServiceDocuments, BranchPermission::ViewRepairDeposits, BranchPermission::ManagePaymentDocuments, BranchPermission::ReadQuotation, BranchPermission::DraftQuotation,
            BranchPermission::ConfirmCustomerPrice, BranchPermission::PrintQuotation, BranchPermission::CustomerData,
            BranchPermission::DeviceAccess, BranchPermission::PrivateCustomerDetails, BranchPermission::InternalNotes,
            BranchPermission::ManageCustomers,
            BranchPermission::RepairEvidence,
            BranchPermission::ReadCatalog, BranchPermission::CatalogGroupPrices, BranchPermission::ReadPartsDocuments, BranchPermission::ManageRepairParts,
        ];
        $management = [
            ...$reception, BranchPermission::ApproveDepositRetention, BranchPermission::ReviewServiceClaims, BranchPermission::ReviewQuotation, BranchPermission::ExportRepairs,
            BranchPermission::Costs, BranchPermission::Profit, BranchPermission::ManageStaff, BranchPermission::InspectRepair,
            BranchPermission::ManageCatalog, BranchPermission::ManageCatalogStock,
            BranchPermission::ManageIntegrations, BranchPermission::VerifyPayments, BranchPermission::UsePos, BranchPermission::SetPosPriceGroup, BranchPermission::ManagePos,
        ];

        return in_array($permission, match ($this) {
            self::Reception => $reception,
            self::Manager => $management,
            self::Owner => [...$management, BranchPermission::Compensation, BranchPermission::Commissions, BranchPermission::ManageBranchSettings, BranchPermission::CancelIssuedQuotation],
            self::Hq => [...$management, BranchPermission::Commissions, BranchPermission::ManageBranchSettings, BranchPermission::CancelIssuedQuotation],
            self::Technician => [
                BranchPermission::ReadAssignedRepairs, BranchPermission::EditAssessment, BranchPermission::EditRepairDeadline, BranchPermission::TechnicianWorkflow,
                BranchPermission::MaskedDeviceIdentifiers, BranchPermission::ViewRepairDeposits,
                BranchPermission::RepairEvidence, BranchPermission::InspectRepair, BranchPermission::ReadCatalog, BranchPermission::ReadPartsDocuments, BranchPermission::ManageRepairParts,
            ],
            self::Pc => [BranchPermission::UsePos, BranchPermission::ReadRepairs, BranchPermission::CreateRepair, BranchPermission::ReadCatalog],
            self::Cashier => [BranchPermission::IssueServiceDocuments, BranchPermission::CompleteRepair, BranchPermission::CancelRepair, BranchPermission::ViewRepairDeposits, BranchPermission::UsePos, BranchPermission::SetPosPriceGroup, BranchPermission::ManagePaymentDocuments, BranchPermission::ReadRepairs, BranchPermission::ReadQuotation, BranchPermission::ConfirmCustomerPrice, BranchPermission::PrintQuotation, BranchPermission::CustomerData, BranchPermission::ReadCatalog, BranchPermission::ReadPartsDocuments, BranchPermission::ManageRepairParts],
            self::Stock => [BranchPermission::Costs, BranchPermission::ReadCatalog, BranchPermission::ManageCatalog, BranchPermission::CatalogGroupPrices, BranchPermission::ManageCatalogStock, BranchPermission::ReadPartsDocuments],
            self::Accounting => [BranchPermission::IssueServiceDocuments, BranchPermission::ManagePaymentDocuments, BranchPermission::VerifyPayments, BranchPermission::Costs, BranchPermission::Profit, BranchPermission::Commissions, BranchPermission::ReadCatalog, BranchPermission::ReadPartsDocuments],
            self::Hr => [BranchPermission::Compensation, BranchPermission::Commissions, BranchPermission::ManageStaff],
        }, true);
    }
}
