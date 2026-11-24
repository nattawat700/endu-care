<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_quotations', function (Blueprint $table) {
            $table->foreignId('customer_requested_by')->nullable()->constrained('users');
            $table->dateTime('customer_requested_at')->nullable();
            $table->string('customer_request_channel', 12)->nullable();
            $table->string('customer_approver_name', 150)->nullable();
            $table->dateTime('customer_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repair_quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_requested_by');
            $table->dropColumn([
                'customer_requested_at', 'customer_request_channel', 'customer_approver_name', 'customer_confirmed_at',
            ]);
        });
    }
};
