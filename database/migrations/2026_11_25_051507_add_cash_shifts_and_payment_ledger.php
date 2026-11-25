<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_payment_points', function (Blueprint $table): void {
            $table->string('qr_filename', 64)->nullable();
            $table->string('other_channel_name', 60)->nullable();
        });
        Schema::create('cash_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('branch_counter_id')->constrained();
            $table->foreignId('branch_payment_point_id')->constrained();
            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->uuid('submission_id');
            $table->unsignedBigInteger('active_counter')->nullable()->unique();
            $table->unsignedBigInteger('active_operator')->nullable();
            $table->unsignedBigInteger('opening_satang');
            $table->json('closing')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'active_operator'], 'cash_active_operator_unique');
            $table->unique(['branch_id', 'submission_id'], 'cash_shift_submission_unique');
            $table->index(['branch_id', 'id']);
        });
        Schema::create('cash_shift_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_shift_id')->constrained();
            $table->foreignId('recorded_by')->constrained('users');
            $table->uuid('submission_id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('amount_satang');
            $table->string('reason', 400);
            $table->timestamps();
            $table->unique(['cash_shift_id', 'submission_id'], 'cash_movement_submission_unique');
        });
        Schema::table('repair_payments', function (Blueprint $table): void {
            $table->foreignId('cash_shift_id')->nullable()->constrained();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 16)->default('confirmed');
            $table->string('review_note', 400)->nullable();
            $table->index(['cash_shift_id', 'status']);
        });
        Schema::create('repair_payment_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repair_payment_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->string('channel', 16);
            $table->string('label', 60);
            $table->unsignedBigInteger('amount_satang');
            $table->unsignedBigInteger('tendered_satang')->nullable();
            $table->string('transfer_reference', 80)->nullable();
            $table->string('slip_filename', 64)->nullable();
            $table->string('slip_type', 30)->nullable();
            $table->char('slip_digest', 64)->nullable();
            $table->unique(['repair_payment_id', 'channel'], 'pay_line_channel_unique');
            $table->unique(['branch_id', 'transfer_reference'], 'pay_line_ref_unique');
            $table->unique(['branch_id', 'slip_digest'], 'pay_line_slip_unique');
        });
        Schema::create('repair_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('repair_payment_id')->constrained();
            $table->foreignId('repair_payment_line_id')->nullable()->constrained();
            $table->foreignId('cash_shift_id')->nullable()->constrained();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->uuid('submission_id');
            $table->string('channel', 16);
            $table->unsignedBigInteger('amount_satang');
            $table->string('reason', 400);
            $table->string('review_note', 400)->nullable();
            $table->string('review_role', 24)->nullable();
            $table->string('status', 16);
            $table->string('proof_filename', 64)->nullable();
            $table->string('proof_type', 30)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['repair_payment_id', 'submission_id'], 'pay_refund_submission_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_refunds');
        Schema::dropIfExists('repair_payment_lines');
        Schema::table('repair_payments', function (Blueprint $table): void {
            $table->dropForeign(['cash_shift_id']);
            $table->dropForeign(['verified_by']);
            $table->dropIndex(['cash_shift_id', 'status']);
            $table->dropColumn(['cash_shift_id', 'verified_by', 'verified_at', 'status', 'review_note']);
        });
        Schema::dropIfExists('cash_shift_movements');
        Schema::dropIfExists('cash_shifts');
        Schema::table('branch_payment_points', fn (Blueprint $table) => $table->dropColumn(['qr_filename', 'other_channel_name']));
    }
};
