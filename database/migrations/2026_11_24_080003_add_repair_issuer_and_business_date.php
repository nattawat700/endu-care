<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_jobs', function (Blueprint $table) {
            $table->json('seller_profile')->nullable();
            $table->date('business_date')->nullable();
            $table->index(['branch_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::table('repair_jobs', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'business_date']);
            $table->dropColumn(['seller_profile', 'business_date']);
        });
    }
};
