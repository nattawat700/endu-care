<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_warehouses', function (Blueprint $table): void {
            $table->string('kind', 16)->default('sale');
        });
        Schema::create('catalog_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_warehouse_id')->constrained();
            $table->string('code', 32);
            $table->string('name', 80);
            $table->timestamps();
            $table->unique(['catalog_warehouse_id', 'code']);
        });
        Schema::create('catalog_location_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_balance_id')->constrained();
            $table->foreignId('catalog_location_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->unique(['catalog_balance_id', 'catalog_location_id'], 'catalog_balance_location_unique');
        });
        Schema::table('catalog_balances', function (Blueprint $table): void {
            $table->unsignedBigInteger('stock_value_satang')->nullable();
        });
        Schema::table('catalog_units', function (Blueprint $table): void {
            $table->foreignId('catalog_location_id')->nullable()->constrained();
            $table->unsignedInteger('cost_satang')->nullable();
        });
        Schema::table('catalog_movements', function (Blueprint $table): void {
            $table->foreignId('catalog_location_id')->nullable()->constrained();
            $table->string('location_label', 116)->nullable();
            $table->unsignedBigInteger('total_cost_satang')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('catalog_location_id');
            $table->dropColumn(['location_label', 'total_cost_satang']);
        });
        Schema::table('catalog_units', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('catalog_location_id');
            $table->dropColumn('cost_satang');
        });
        Schema::table('catalog_balances', function (Blueprint $table): void {
            $table->dropColumn('stock_value_satang');
        });
        Schema::dropIfExists('catalog_location_balances');
        Schema::dropIfExists('catalog_locations');
        Schema::table('catalog_warehouses', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
