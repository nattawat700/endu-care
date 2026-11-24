<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('payment_point_id')->constrained('branch_payment_points');
            $table->foreignId('printer_profile_id')->constrained('branch_printer_profiles');
            $table->string('code', 32);
            $table->string('name', 100);
            $table->unique(['branch_id', 'code']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_counters');
    }
};
