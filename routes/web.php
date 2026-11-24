<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchSettingsController;
use App\Http\Controllers\CustomerQuotationApprovalController;
use App\Http\Controllers\DocumentProfileController;
use App\Http\Controllers\OrganizationApprovalController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PhaseOneController;
use App\Http\Controllers\QuotationApprovalController;
use App\Http\Controllers\QuotationSignatureController;
use App\Http\Controllers\RepairAssessmentController;
use App\Http\Controllers\RepairController;
use App\Http\Controllers\RepairQuotationController;
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

    Route::prefix('branches/{branch}/staff')->name('staff.')->scopeBindings()->group(function (): void {
        Route::get('/', [StaffAccessController::class, 'index'])->name('index');
        Route::post('/invitations', [StaffAccessController::class, 'invite'])->middleware('throttle:20,1')->name('invite');
        Route::delete('/invitations/{staffInvitation}', [StaffAccessController::class, 'cancelInvitation'])->name('invitations.cancel');
        Route::patch('/{membership}', [StaffAccessController::class, 'changeRole'])->name('change-role');
        Route::delete('/{membership}', [StaffAccessController::class, 'remove'])->name('remove');
        Route::patch('/{membership}/account', [StaffAccessController::class, 'account'])->name('account');
    });

    Route::prefix('branches/{branch}/repairs')->name('repairs.')->scopeBindings()->group(function (): void {
        Route::get('/', [RepairController::class, 'index'])->name('index');
        Route::get('/create', [RepairController::class, 'create'])->name('create');
        Route::get('/export', [RepairController::class, 'export'])->name('export');
        Route::post('/', [RepairController::class, 'store'])->name('store');
        Route::get('/quotation-approvals', [QuotationApprovalController::class, 'index'])->name('quotations.review-queue');
        Route::get('/{repair}', [RepairController::class, 'show'])->name('show');
        Route::get('/{repair}/print', [RepairController::class, 'print'])->name('print');
        Route::get('/{repair}/assessment', [RepairAssessmentController::class, 'create'])->name('assessment.create');
        Route::post('/{repair}/assessment', [RepairAssessmentController::class, 'store'])->name('assessment.store');
        Route::prefix('/{repair}/quotations')->name('quotations.')->group(function (): void {
            Route::get('/', [RepairQuotationController::class, 'index'])->name('index');
            Route::get('/create', [RepairQuotationController::class, 'create'])->name('create');
            Route::post('/', [RepairQuotationController::class, 'store'])->name('store');
            Route::get('/{quotation}', [RepairQuotationController::class, 'show'])->name('show');
            Route::get('/{quotation}/print', [RepairQuotationController::class, 'print'])->name('print');
            Route::get('/{quotation}/signature', QuotationSignatureController::class)->name('signature');
            Route::post('/{quotation}/review', [QuotationApprovalController::class, 'submit'])->name('submit-review');
            Route::patch('/{quotation}/review', [QuotationApprovalController::class, 'decide'])->name('decide-review');
            Route::post('/{quotation}/approval', [CustomerQuotationApprovalController::class, 'requestApproval'])->name('request-customer-approval');
            Route::patch('/{quotation}/approval', [CustomerQuotationApprovalController::class, 'approve'])->name('approve');
        });
        Route::patch('/{repair}/technician', [RepairController::class, 'assign'])->name('assign');
        Route::patch('/{repair}/status', [RepairController::class, 'changeStatus'])->name('change-status');
    });
});
