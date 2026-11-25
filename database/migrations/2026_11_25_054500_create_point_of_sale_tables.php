<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('branch_counter_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('salesperson_id')->constrained('users');
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->foreignId('approval_id')->nullable()->unique()->constrained('organization_approval_requests');
            $table->uuid('submission_id');
            $table->string('status', 12)->default('draft');
            $table->string('price_group', 12);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('subtotal_satang');
            $table->unsignedBigInteger('line_discount_satang');
            $table->unsignedBigInteger('document_discount_satang');
            $table->unsignedBigInteger('total_satang');
            $table->json('seller')->nullable();
            $table->json('buyer');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 400)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'submission_id']);
            $table->index(['branch_id', 'status', 'branch_counter_id', 'id']);
        });

        Schema::create('pos_sale_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_product_id')->constrained();
            $table->string('barcode', 40)->nullable();
            $table->foreignId('catalog_unit_id')->nullable()->constrained();
            $table->unsignedInteger('catalog_version');
            $table->string('sku', 64);
            $table->string('name', 200);
            $table->string('kind', 16);
            $table->string('unit_name', 40);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('multiplier');
            $table->unsignedBigInteger('unit_satang');
            $table->unsignedBigInteger('discount_satang');
            $table->string('imei', 32)->nullable();
            $table->string('serial_number', 100)->nullable();
        });

        Schema::create('pos_tenders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_sale_id')->constrained();
            $table->foreignId('cash_shift_id')->constrained();
            $table->foreignId('recorded_by')->constrained('users');
            $table->string('direction', 12);
            $table->unsignedBigInteger('amount_satang');
            $table->unsignedBigInteger('tendered_satang')->nullable();
            $table->timestamps();
            $table->unique(['pos_sale_id', 'direction']);
            $table->index(['cash_shift_id', 'direction']);
        });

        Schema::create('pos_credit_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_sale_id')->constrained();
            $table->foreignId('previous_user_id')->constrained('users');
            $table->foreignId('next_user_id')->constrained('users');
            $table->foreignId('changed_by')->constrained('users');
            $table->string('reason', 400);
            $table->timestamps();
        });

        Schema::table('catalog_movements', function (Blueprint $table): void {
            $table->foreignId('pos_sale_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pos_sale_id');
        });
        Schema::dropIfExists('pos_credit_changes');
        Schema::dropIfExists('pos_tenders');
        Schema::dropIfExists('pos_sale_lines');
        Schema::dropIfExists('pos_sales');
    }
};
