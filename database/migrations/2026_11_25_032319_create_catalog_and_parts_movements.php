<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('sku', 32);
            $table->string('name', 140);
            $table->string('kind', 16);
            $table->string('tracking', 16);
            $table->string('category', 80)->nullable();
            $table->string('brand', 80)->nullable();
            $table->string('unit', 30);
            $table->unsignedInteger('retail_satang');
            $table->unsignedInteger('dealer_satang')->nullable();
            $table->unsignedInteger('partner_satang')->nullable();
            $table->unsignedInteger('cost_satang')->nullable();
            $table->string('image', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['branch_id', 'sku']);
            $table->unique(['branch_id', 'id']);
        });

        Schema::create('catalog_barcodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id');
            $table->foreignId('catalog_product_id');
            $table->string('code', 40);
            $table->string('unit', 30);
            $table->unsignedInteger('multiplier');
            $table->unique(['branch_id', 'code']);
            $table->foreign(['branch_id', 'catalog_product_id'], 'catalog_barcode_product_fk')
                ->references(['branch_id', 'id'])->on('catalog_products')->cascadeOnDelete();
        });

        Schema::create('catalog_compatibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_product_id')->constrained()->cascadeOnDelete();
            $table->string('device_model', 100);
            $table->unique(['catalog_product_id', 'device_model']);
            $table->index('device_model');
        });

        Schema::create('catalog_warehouses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 80);
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
            $table->unique(['branch_id', 'id']);
        });

        Schema::create('catalog_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id');
            $table->foreignId('catalog_product_id');
            $table->foreignId('catalog_warehouse_id');
            $table->unsignedInteger('quantity')->default(0);
            $table->unique(['catalog_product_id', 'catalog_warehouse_id'], 'catalog_balance_unique');
            $table->foreign(['branch_id', 'catalog_product_id'], 'catalog_balance_product_fk')
                ->references(['branch_id', 'id'])->on('catalog_products');
            $table->foreign(['branch_id', 'catalog_warehouse_id'], 'catalog_balance_warehouse_fk')
                ->references(['branch_id', 'id'])->on('catalog_warehouses');
        });

        Schema::create('catalog_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id');
            $table->foreignId('catalog_product_id');
            $table->foreignId('catalog_warehouse_id');
            $table->string('serial_number', 80)->nullable();
            $table->string('imei', 15)->nullable();
            $table->boolean('available')->default(true);
            $table->unique(['branch_id', 'serial_number']);
            $table->unique(['branch_id', 'imei']);
            $table->index(['catalog_product_id', 'available', 'id']);
            $table->foreign(['branch_id', 'catalog_product_id'], 'catalog_unit_product_fk')
                ->references(['branch_id', 'id'])->on('catalog_products');
            $table->foreign(['branch_id', 'catalog_warehouse_id'], 'catalog_unit_warehouse_fk')
                ->references(['branch_id', 'id'])->on('catalog_warehouses');
        });

        Schema::create('catalog_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id');
            $table->foreignId('catalog_product_id');
            $table->foreignId('catalog_warehouse_id');
            $table->foreignId('repair_job_id')->nullable()->constrained();
            $table->foreignId('catalog_unit_id')->nullable()->constrained();
            $table->foreignId('issue_id')->nullable()->constrained('catalog_movements');
            $table->foreignId('created_by')->constrained('users');
            $table->uuid('submission_id');
            $table->string('kind', 12);
            $table->unsignedInteger('quantity');
            $table->string('sku', 32);
            $table->string('product_name', 140);
            $table->string('unit_name', 30);
            $table->string('warehouse_name', 80);
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'submission_id']);
            $table->index(['repair_job_id', 'id']);
            $table->foreign(['branch_id', 'catalog_product_id'], 'catalog_move_product_fk')
                ->references(['branch_id', 'id'])->on('catalog_products');
            $table->foreign(['branch_id', 'catalog_warehouse_id'], 'catalog_move_warehouse_fk')
                ->references(['branch_id', 'id'])->on('catalog_warehouses');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_movements');
        Schema::dropIfExists('catalog_units');
        Schema::dropIfExists('catalog_balances');
        Schema::dropIfExists('catalog_warehouses');
        Schema::dropIfExists('catalog_compatibilities');
        Schema::dropIfExists('catalog_barcodes');
        Schema::dropIfExists('catalog_products');
    }
};
