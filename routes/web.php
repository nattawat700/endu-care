<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchSettingsController;
use App\Http\Controllers\CashShiftController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CatalogLabelController;
use App\Http\Controllers\CatalogStockController;
use App\Http\Controllers\CatalogTransferController;
use App\Http\Controllers\CatalogWarehouseController;
use App\Http\Controllers\CustomerAppointmentController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerDeviceController;
use App\Http\Controllers\CustomerQuotationApprovalController;
use App\Http\Controllers\DocumentIssuanceSettingsController;
use App\Http\Controllers\ServiceDocumentController;
use App\Http\Controllers\DocumentProfileController;
use App\Http\Controllers\IntegrationAccountingController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\IntegrationDeviceController;
use App\Http\Controllers\IntegrationImportController;
use App\Http\Controllers\IntegrationOrderController;
use App\Http\Controllers\OrganizationApprovalController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PhaseOneController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\QuotationApprovalController;
use App\Http\Controllers\QuotationSignatureController;
use App\Http\Controllers\RepairAssessmentController;
use App\Http\Controllers\RepairController;
use App\Http\Controllers\RepairPaymentController;
use App\Http\Controllers\RepairPhotoController;
use App\Http\Controllers\RepairQualityCheckController;
use App\Http\Controllers\RepairQuotationController;
use App\Http\Controllers\RepairRefundController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StaffAccessController;
use App\Http\Controllers\StaffInvitationController;
use App\Http\Controllers\StaffMfaController;
use App\Http\Controllers\StaffPasswordController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/forgot-password', [StaffPasswordController::class, 'requestForm'])->name('password.request');
    Route::post('/forgot-password', [StaffPasswordController::class, 'send'])->middleware('throttle:staff-recovery')->name('password.email');
    Route::get('/reset-password/{token}', [StaffPasswordController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [StaffPasswordController::class, 'reset'])->middleware('throttle:staff-recovery')->name('password.update');
    Route::get('/staff-mfa', [StaffMfaController::class, 'show'])->name('staff-mfa.show');
    Route::post('/staff-mfa/setup', [StaffMfaController::class, 'setup'])->middleware('throttle:staff-mfa')->name('staff-mfa.setup');
    Route::post('/staff-mfa', [StaffMfaController::class, 'verify'])->middleware('throttle:staff-mfa')->name('staff-mfa.verify');
});

Route::prefix('staff-invitations')->name('staff-invitations.')->group(function (): void {
    Route::get('/{token}', [StaffInvitationController::class, 'show'])->where('token', '[a-f0-9]{64}')->name('show');
    Route::post('/{token}', [StaffInvitationController::class, 'accept'])->where('token', '[a-f0-9]{64}')
        ->middleware('throttle:staff-recovery')->name('accept');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/', [BranchController::class, 'index'])->name('dashboard');
    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
    Route::post('/workspace/branches/{branch}', [BranchController::class, 'select'])->name('workspace.branch');

    Route::prefix('branches/{branch}/integrations')->name('integrations.')->scopeBindings()->group(function (): void {
        Route::get('/', [IntegrationController::class, 'index'])->name('index');
        Route::get('/imports', [IntegrationImportController::class, 'index'])->name('imports.index');
        Route::get('/imports/template', [IntegrationImportController::class, 'template'])->name('imports.template');
        Route::post('/imports', [IntegrationImportController::class, 'preview'])->name('imports.preview');
        Route::get('/imports/{integrationImport}', [IntegrationImportController::class, 'show'])->name('imports.show');
        Route::post('/imports/{integrationImport}', [IntegrationImportController::class, 'apply'])->name('imports.apply');
        Route::get('/imports/{integrationImport}/report', [IntegrationImportController::class, 'report'])->name('imports.report');
        Route::get('/devices', [IntegrationDeviceController::class, 'index'])->name('devices');
        Route::get('/devices/{printerProfile}/print', [IntegrationDeviceController::class, 'print'])->name('devices.print');
        Route::post('/connections', [IntegrationController::class, 'save'])->name('connections.store');
        Route::get('/connections/{integrationConnection}', [IntegrationController::class, 'show'])->name('connections.show');
        Route::patch('/connections/{integrationConnection}', [IntegrationController::class, 'save'])->name('connections.update');
        Route::post('/connections/{integrationConnection}/links', [IntegrationController::class, 'link'])->name('connections.links');
        Route::get('/connections/{integrationConnection}/orders/{order}', [IntegrationOrderController::class, 'show'])->name('orders.show');
        Route::post('/connections/{integrationConnection}/orders/{order}', [IntegrationOrderController::class, 'confirm'])->name('orders.confirm');
        Route::get('/connections/{integrationConnection}/accounting', [IntegrationAccountingController::class, 'index'])->name('accounting.index');
        Route::post('/connections/{integrationConnection}/accounting', [IntegrationAccountingController::class, 'store'])->name('accounting.store');
        Route::get('/connections/{integrationConnection}/accounting/{accountingExport}/download', [IntegrationAccountingController::class, 'download'])->name('accounting.download');
    });

    Route::prefix('branches/{branch}/pos')->name('pos.')->scopeBindings()->group(function (): void {
        Route::get('/', [PosController::class, 'index'])->name('index');
        Route::get('/create', [PosController::class, 'form'])->name('create');
        Route::get('/history', [PosController::class, 'history'])->name('history');
        Route::get('/features', [PosController::class, 'features'])->name('features');
        Route::post('/', [PosController::class, 'save'])->name('store');
        Route::middleware('throttle:90,1')->group(function (): void {
            Route::get('/products', [PosController::class, 'products'])->name('products');
            Route::get('/products/{product}/units', [PosController::class, 'units'])->whereNumber('product')->name('units');
            Route::post('/customers/search', [PosController::class, 'customers'])->name('customers');
        });
        Route::get('/{posSale}', [PosController::class, 'show'])->name('show');
        Route::get('/{posSale}/edit', [PosController::class, 'form'])->name('edit');
        Route::patch('/{posSale}', [PosController::class, 'save'])->name('update');
        Route::post('/{posSale}/checkout', [PosController::class, 'checkout'])->name('checkout');
        Route::post('/{posSale}/approval', [PosController::class, 'requestApproval'])->name('approval');
        Route::post('/{posSale}/void', [PosController::class, 'void'])->name('void');
        Route::post('/{posSale}/credit', [PosController::class, 'credit'])->name('credit');
        Route::get('/{posSale}/receipt', [PosController::class, 'receipt'])->name('receipt');
    });

    Route::prefix('branches/{branch}/catalog')->name('catalog.')->scopeBindings()->group(function (): void {
        Route::get('/', [CatalogController::class, 'index'])->name('index');
        Route::get('/pos', [CatalogController::class, 'index'])->name('pos');
        Route::get('/products/create', [CatalogController::class, 'create'])->name('create');
        Route::post('/products', [CatalogController::class, 'store'])->name('store');
        Route::get('/products/{catalogProduct}', [CatalogController::class, 'show'])->name('show');
        Route::get('/products/{catalogProduct}/edit', [CatalogController::class, 'edit'])->name('edit');
        Route::patch('/products/{catalogProduct}', [CatalogController::class, 'update'])->name('update');
        Route::get('/products/{catalogProduct}/image', [CatalogController::class, 'image'])->name('image');
        Route::get('/products/{catalogProduct}/labels', [CatalogLabelController::class, 'print'])->name('labels');
        Route::get('/transfer', [CatalogTransferController::class, 'index'])->name('transfer');
        Route::post('/transfer/preview', [CatalogTransferController::class, 'preview'])->name('import.preview');
        Route::post('/transfer/apply', [CatalogTransferController::class, 'apply'])->block(10, 10)->name('import.apply');
        Route::get('/transfer/template', [CatalogTransferController::class, 'template'])->name('template');
        Route::get('/transfer/export', [CatalogTransferController::class, 'export'])->name('export');
        Route::get('/stock', [CatalogStockController::class, 'index'])->name('stock');
        Route::get('/stock/open-repair', [CatalogStockController::class, 'openRepair'])->name('open-repair');
        Route::post('/warehouses', [CatalogStockController::class, 'warehouse'])->name('warehouses.store');
        Route::get('/stock/receive', [CatalogStockController::class, 'create'])->name('stock.receive');
        Route::post('/stock/receive', [CatalogStockController::class, 'receive'])->name('stock.store');
        Route::get('/movements/{catalogMovement}/print', [CatalogStockController::class, 'print'])->name('movements.print');
    });

    Route::prefix('organizations')->name('organizations.')->group(function (): void {
        Route::get('/', [OrganizationController::class, 'index'])->name('index');
        Route::post('/', [OrganizationController::class, 'store'])->name('store');
        Route::get('/{organization}', [OrganizationController::class, 'show'])->name('show');
        Route::patch('/{organization}', [OrganizationController::class, 'update'])->name('update');
        Route::patch('/{organization}/status', [OrganizationController::class, 'setStatus'])->name('status');
        Route::post('/{organization}/branches', [BranchSettingsController::class, 'store'])->name('branches.store');
        Route::patch('/{organization}/document', [DocumentProfileController::class, 'organization'])->name('document');
        Route::patch('/{organization}/approval-rules', [OrganizationApprovalController::class, 'rules'])->name('rules');
    });

    Route::prefix('branches/{branch}/settings')->name('branch-settings.')->scopeBindings()->group(function (): void {
        Route::get('/', [BranchSettingsController::class, 'show'])->name('show');
        Route::patch('/', [BranchSettingsController::class, 'update'])->name('update');
        Route::patch('/schedule', [BranchSettingsController::class, 'schedule'])->name('schedule');
        Route::get('/warehouses', [CatalogWarehouseController::class, 'index'])->name('warehouses.index');
        Route::post('/warehouses', [CatalogWarehouseController::class, 'warehouse'])->name('warehouses.store');
        Route::patch('/warehouses/{catalogWarehouse}', [CatalogWarehouseController::class, 'warehouse'])->name('warehouses.update');
        Route::post('/warehouses/{catalogWarehouse}/locations', [CatalogWarehouseController::class, 'location'])->name('locations.store');
        Route::patch('/warehouses/{catalogWarehouse}/locations/{location}', [CatalogWarehouseController::class, 'location'])->name('locations.update');
        Route::patch('/document', [DocumentProfileController::class, 'branch'])->name('document');
        Route::get('/document/preview', [DocumentProfileController::class, 'preview'])->name('document.preview');
        Route::get('/document/logos/{logo}', [DocumentProfileController::class, 'logo'])
            ->where('logo', '[0-9a-f-]{36}\.(?:png|jpe?g|webp)')->name('document.logo');
        Route::post('/payment-points', [BranchSettingsController::class, 'paymentPoint'])->name('payment-points.store');
        Route::patch('/payment-points/{paymentPoint}', [BranchSettingsController::class, 'paymentPoint'])->name('payment-points.update');
        Route::post('/printer-profiles', [BranchSettingsController::class, 'printerProfile'])->name('printer-profiles.store');
        Route::patch('/printer-profiles/{printerProfile}', [BranchSettingsController::class, 'printerProfile'])->name('printer-profiles.update');
        Route::post('/counters', [BranchSettingsController::class, 'counter'])->name('counters.store');
        Route::patch('/counters/{counter}', [BranchSettingsController::class, 'counter'])->name('counters.update');
    });

    Route::prefix('branches/{branch}/organization-approvals')->name('organization-approvals.')->scopeBindings()->group(function (): void {
        Route::get('/', [OrganizationApprovalController::class, 'index'])->name('index');
        Route::post('/', [OrganizationApprovalController::class, 'store'])->name('store');
        Route::patch('/{approvalRequest}', [OrganizationApprovalController::class, 'decide'])->name('decide');
    });
    Route::get('/phase-one', PhaseOneController::class)->name('phase-one');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    Route::post('/staff-sessions/revoke-others', [SessionController::class, 'revokeOthers'])->name('staff-sessions.revoke-others');

    Route::prefix('branches/{branch}/crm')->name('crm.')->scopeBindings()->group(function (): void {
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::patch('/customers/{customer}/tax-profile', [CustomerController::class, 'taxProfile'])->name('customers.tax-profile');
        Route::post('/customers/{customer}/devices', [CustomerDeviceController::class, 'store'])->name('devices.store');
        Route::get('/customers/{customer}/devices/{customerDevice}', [CustomerDeviceController::class, 'show'])->name('devices.show');
        Route::patch('/customers/{customer}/devices/{customerDevice}', [CustomerDeviceController::class, 'update'])->name('devices.update');
        Route::post('/customers/{customer}/devices/{customerDevice}/repair-links', [CustomerDeviceController::class, 'linkRepair'])->name('devices.link-repair');
        Route::get('/customers/{customer}/appointments/create', [CustomerAppointmentController::class, 'create'])->name('appointments.create');
        Route::post('/customers/{customer}/appointments', [CustomerAppointmentController::class, 'store'])->name('appointments.store');
        Route::get('/appointments', [CustomerAppointmentController::class, 'index'])->name('appointments.index');
        Route::get('/appointments/{appointment}', [CustomerAppointmentController::class, 'edit'])->name('appointments.edit');
        Route::patch('/appointments/{appointment}', [CustomerAppointmentController::class, 'update'])->name('appointments.update');
    });

    Route::prefix('branches/{branch}/staff')->name('staff.')->scopeBindings()->group(function (): void {
        Route::get('/', [StaffAccessController::class, 'index'])->name('index');
        Route::post('/invitations', [StaffAccessController::class, 'invite'])->middleware('throttle:20,1')->name('invite');
        Route::delete('/invitations/{staffInvitation}', [StaffAccessController::class, 'cancelInvitation'])->name('invitations.cancel');
        Route::patch('/{membership}', [StaffAccessController::class, 'changeRole'])->name('change-role');
        Route::delete('/{membership}', [StaffAccessController::class, 'remove'])->name('remove');
        Route::patch('/{membership}/account', [StaffAccessController::class, 'account'])->name('account');
    });

    Route::prefix('branches/{branch}/cash-shifts')->name('cash-shifts.')->scopeBindings()->group(function (): void {
        Route::get('/', [CashShiftController::class, 'index'])->name('index');
        Route::post('/', [CashShiftController::class, 'open'])->name('open');
        Route::get('/qr/{paymentPoint}', [CashShiftController::class, 'qr'])->name('qr');
        Route::get('/{cashShift}', [CashShiftController::class, 'show'])->name('show');
        Route::post('/{cashShift}/movements', [CashShiftController::class, 'move'])->name('move');
        Route::patch('/{cashShift}/close', [CashShiftController::class, 'close'])->name('close');
    });
    Route::prefix('branches/{branch}/service-documents')->name('documents.')->scopeBindings()->group(function (): void {
        Route::get('/', [ServiceDocumentController::class, 'index'])->name('index');
        Route::get('/settings', [DocumentIssuanceSettingsController::class, 'show'])->name('settings');
        Route::patch('/settings/tax', [DocumentIssuanceSettingsController::class, 'tax'])->name('settings.tax');
        Route::patch('/settings/series', [DocumentIssuanceSettingsController::class, 'series'])->name('settings.series');
        Route::get('/issued/{issuedDocument}', [ServiceDocumentController::class, 'show'])->name('show');
        Route::post('/issued/{issuedDocument}/print', [ServiceDocumentController::class, 'print'])->middleware('throttle:20,1')->name('print');
        Route::get('/source/{source}/{id}', [ServiceDocumentController::class, 'source'])->whereNumber('id')->name('source');
        Route::post('/source/{source}/{id}', [ServiceDocumentController::class, 'issue'])->whereNumber('id')->name('issue');
        Route::post('/source/{source}/{id}/claim', [ServiceDocumentController::class, 'receiveClaim'])->whereNumber('id')->name('claims.store');
        Route::patch('/claims/{serviceClaim}', [ServiceDocumentController::class, 'reviewClaim'])->name('claims.review');
    });
    Route::get('/branches/{branch}/payment-documents', [RepairPaymentController::class, 'register'])->name('payment-documents.index');
    Route::prefix('branches/{branch}/repairs')->name('repairs.')->scopeBindings()->group(function (): void {
        Route::get('/', [RepairController::class, 'index'])->name('index');
        Route::get('/create', [RepairController::class, 'create'])->name('create');
        Route::get('/export', [RepairController::class, 'export'])->name('export');
        Route::post('/', [RepairController::class, 'store'])->name('store');
        Route::get('/quotation-approvals', [QuotationApprovalController::class, 'index'])->name('quotations.review-queue');
        Route::get('/{repair}', [RepairController::class, 'show'])->name('show');
        Route::get('/{repair}/print', [RepairController::class, 'print'])->name('print');
        Route::get('/{repair}/parts', [CatalogStockController::class, 'createForRepair'])->name('parts.create');
        Route::post('/{repair}/parts', [CatalogStockController::class, 'forRepair'])->name('parts.store');
        Route::get('/{repair}/catalog-lookup', [CatalogController::class, 'quotationLookup'])->name('catalog-lookup');
        Route::post('/{repair}/photos', [RepairPhotoController::class, 'store'])->name('photos.store');
        Route::get('/{repair}/photos/{photo}', [RepairPhotoController::class, 'show'])->name('photos.show');
        Route::post('/{repair}/quality-checks', [RepairQualityCheckController::class, 'store'])->name('quality-checks.store');
        Route::get('/{repair}/assessment', [RepairAssessmentController::class, 'create'])->name('assessment.create');
        Route::post('/{repair}/assessment', [RepairAssessmentController::class, 'store'])->name('assessment.store');
        Route::prefix('/{repair}/payments')->name('payments.')->group(function (): void {
            Route::get('/', [RepairPaymentController::class, 'index'])->name('index');
            Route::post('/', [RepairPaymentController::class, 'store'])->name('store');
            Route::get('/{payment}', [RepairPaymentController::class, 'show'])->name('show');
            Route::get('/{payment}/print', [RepairPaymentController::class, 'print'])->name('print');
            Route::get('/{payment}/deposit-proof', [RepairPaymentController::class, 'depositProof'])->name('deposit-proof');
            Route::get('/{payment}/slip', [RepairPaymentController::class, 'slip'])->name('slip');
            Route::patch('/{payment}/verification', [RepairPaymentController::class, 'verify'])->name('verify');
            Route::post('/{payment}/refunds', [RepairRefundController::class, 'request'])->name('refunds.request');
            Route::patch('/{payment}/refunds/{refund}/review', [RepairRefundController::class, 'review'])->name('refunds.review');
            Route::post('/{payment}/refunds/{refund}/complete', [RepairRefundController::class, 'complete'])->name('refunds.complete');
            Route::get('/{payment}/refunds/{refund}/proof', [RepairRefundController::class, 'proof'])->name('refunds.proof');
        });
        Route::prefix('/{repair}/quotations')->name('quotations.')->group(function (): void {
            Route::get('/', [RepairQuotationController::class, 'index'])->name('index');
            Route::get('/create', [RepairQuotationController::class, 'create'])->name('create');
            Route::post('/', [RepairQuotationController::class, 'store'])->name('store');
            Route::get('/{quotation}', [RepairQuotationController::class, 'show'])->name('show');
            Route::post('/{quotation}/void', [RepairQuotationController::class, 'void'])->name('void');
            Route::get('/{quotation}/print', [RepairQuotationController::class, 'print'])->name('print');
            Route::get('/{quotation}/signature', QuotationSignatureController::class)->name('signature');
            Route::post('/{quotation}/review', [QuotationApprovalController::class, 'submit'])->name('submit-review');
            Route::patch('/{quotation}/review', [QuotationApprovalController::class, 'decide'])->name('decide-review');
            Route::post('/{quotation}/approval', [CustomerQuotationApprovalController::class, 'requestApproval'])->name('request-customer-approval');
            Route::patch('/{quotation}/approval', [CustomerQuotationApprovalController::class, 'approve'])->name('approve');
        });
        Route::patch('/{repair}/technician', [RepairController::class, 'assign'])->name('assign');
        Route::patch('/{repair}/status', [RepairController::class, 'changeStatus'])->name('change-status');
        Route::patch('/{repair}/deadline', [RepairController::class, 'changeDeadline'])->name('change-deadline');
    });
});
