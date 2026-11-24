<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_jobs', function (Blueprint $table) {
            $table->text('customer_address')->nullable();
            $table->string('product', 120)->nullable();
            $table->string('color', 80)->nullable();
            $table->string('imei', 15)->nullable();
            $table->string('serial_number', 80)->nullable();
            $table->text('intake_terms')->nullable();
            $table->string('policy_number', 80)->nullable();
            $table->text('device_condition')->nullable();
            $table->text('accessories')->nullable();
            $table->string('warranty_status', 12)->nullable();
            $table->text('device_passcode')->nullable();
            $table->text('device_unlock_pattern')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repair_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'customer_address', 'product', 'color', 'imei', 'serial_number', 'intake_terms',
                'policy_number', 'device_condition', 'accessories', 'warranty_status',
                'device_passcode', 'device_unlock_pattern',
            ]);
        });
    }
};
