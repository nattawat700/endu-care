<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('opening_hours', 250)->nullable();
            $table->string('receipt_layout', 20)->nullable();
            $table->json('document_profile')->nullable();
            $table->json('working_days')->nullable();
            $table->string('shift_name', 80)->nullable();
            $table->string('shift_start', 5)->nullable();
            $table->string('shift_end', 5)->nullable();
            $table->string('day_cutoff', 5)->default('00:00');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['address', 'phone', 'opening_hours', 'receipt_layout', 'document_profile', 'working_days', 'shift_name', 'shift_start', 'shift_end', 'day_cutoff']);
        });
    }
};
