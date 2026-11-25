<?php

namespace App\Enums;

enum BranchPermission
{
    case UsePos;
    case SetPosPriceGroup;
    case ManagePos;
    case ManageIntegrations;
    case ReadCatalog;
    case ManageCatalog;
    case CatalogGroupPrices;
    case ManageCatalogStock;
    case ReadPartsDocuments;
    case ManageRepairParts;
    case ReadRepairs;
    case ReadAssignedRepairs;
    case CreateRepair;
    case AssignRepair;
    case EditRepairDeadline;
    case RepairEvidence;
    case InspectRepair;
    case EditAssessment;
    case TechnicianWorkflow;
    case CompleteRepair;
    case CancelRepair;
    case PrintRepair;
    case ExportRepairs;
    case ManagePaymentDocuments;
    case ViewRepairDeposits;
    case IssueServiceDocuments;
    case ReviewServiceClaims;
    case ApproveDepositRetention;
    case CancelIssuedQuotation;
    case VerifyPayments;
    case ReadQuotation;
    case DraftQuotation;
    case ReviewQuotation;
    case ConfirmCustomerPrice;
    case PrintQuotation;
    case CustomerData;
    case ManageCustomers;
    case PrivateCustomerDetails;
    case MaskedDeviceIdentifiers;
    case InternalNotes;
    case DeviceAccess;
    case Costs;
    case Profit;
    case Compensation;
    case Commissions;
    case ManageStaff;
    case ManageBranchSettings;
}
