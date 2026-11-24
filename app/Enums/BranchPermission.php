<?php

namespace App\Enums;

enum BranchPermission
{
    case ReadRepairs;
    case ReadAssignedRepairs;
    case CreateRepair;
    case AssignRepair;
    case EditAssessment;
    case TechnicianWorkflow;
    case CompleteRepair;
    case CancelRepair;
    case PrintRepair;
    case ExportRepairs;
    case ReadQuotation;
    case DraftQuotation;
    case ReviewQuotation;
    case ConfirmCustomerPrice;
    case PrintQuotation;
    case CustomerData;
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
