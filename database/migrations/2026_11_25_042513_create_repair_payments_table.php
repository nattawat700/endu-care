<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('repair_job_id')->constrained();
            $table->foreignId('repair_quotation_id')->nullable()->constrained();
            $table->foreignId('received_by')->constrained('users');
            $table->uuid('submission_id');
            $table->string('channel', 16);
            $table->string('document_type', 16);
            $table->unsignedBigInteger('job_total_satang');
            $table->unsignedBigInteger('payable_satang');
            $table->unsignedBigInteger('paid_before_satang');
            $table->unsignedBigInteger('amount_satang');
            $table->string('transfer_reference', 80)->nullable();
            $table->string('slip_filename', 64)->nullable();
            $table->string('slip_type', 30)->nullable();
            $table->char('slip_digest', 64)->nullable();
            $table->mediumText('document');
            $table->timestamps();

            $table->unique(['repair_job_id', 'submission_id'], 'repair_payment_submission_unique');
            $table->unique(['branch_id', 'transfer_reference'], 'repair_payment_transfer_unique');
            $table->unique(['branch_id', 'slip_digest'], 'repair_payment_slip_unique');
            $table->index(['branch_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_payments');
    }
};
