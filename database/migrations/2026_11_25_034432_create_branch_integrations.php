<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->uuid('public_id')->unique();
            $table->string('kind', 16);
            $table->string('name', 100);
            $table->string('account_ref', 80);
            $table->boolean('enabled')->default(false);
            $table->text('secret')->nullable();
            $table->text('credentials')->nullable();
            $table->dateTime('last_received_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'kind', 'account_ref'], 'integration_connection_account_unique');
        });
        Schema::create('integration_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained();
            $table->foreignId('catalog_product_id')->constrained();
            $table->string('external_sku', 80);
            $table->string('external_model', 100)->nullable();
            $table->string('local_model', 100)->nullable();
            $table->timestamps();
            $table->unique(['integration_connection_id', 'external_sku'], 'integration_external_sku_unique');
        });
        Schema::create('integration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained();
            $table->string('external_id', 80);
            $table->string('topic', 32);
            $table->string('digest', 64);
            $table->string('status', 16);
            $table->string('reason', 160)->nullable();
            $table->timestamps();
            $table->unique(['integration_connection_id', 'external_id'], 'integration_event_identity');
        });
        Schema::create('integration_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained();
            $table->string('external_sku', 80);
            $table->string('name', 140);
            $table->string('device_model', 100)->nullable();
            $table->unsignedInteger('revision');
            $table->timestamps();
            $table->unique(['integration_connection_id', 'external_sku'], 'integration_offer_sku_unique');
        });
        Schema::create('integration_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained();
            $table->string('reference', 80);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('revision');
            $table->json('items');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['integration_connection_id', 'reference']);
        });
        Schema::create('integration_shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained();
            $table->foreignId('integration_order_id')->nullable()->constrained();
            $table->string('tracking_number', 80);
            $table->string('status', 20);
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->unique(['integration_connection_id', 'tracking_number'], 'integration_tracking_unique');
        });
        Schema::create('integration_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->json('report');
            $table->mediumText('payload')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'id']);
        });
        Schema::create('integration_accounting_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->uuid('submission_id')->unique();
            $table->json('rows');
            $table->timestamps();
        });
        Schema::table('catalog_movements', function (Blueprint $table): void {
            $table->foreignId('integration_order_id')->nullable()->constrained();
            $table->unsignedInteger('unit_cost_satang')->nullable();
        });
        Schema::create('integration_accounting_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained();
            $table->foreignId('integration_accounting_export_id')->constrained(indexName: 'integration_accounting_item_export_fk');
            $table->foreignId('catalog_movement_id')->constrained();
            $table->unique(['integration_connection_id', 'catalog_movement_id'], 'integration_accounting_movement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_accounting_items');
        Schema::table('catalog_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('integration_order_id');
            $table->dropColumn('unit_cost_satang');
        });
        Schema::dropIfExists('integration_accounting_exports');
        Schema::dropIfExists('integration_imports');
        Schema::dropIfExists('integration_shipments');
        Schema::dropIfExists('integration_orders');
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('integration_offers');
        Schema::dropIfExists('integration_links');
        Schema::dropIfExists('integration_connections');
    }
};
