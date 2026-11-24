<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('actor_id')->constrained('users');
            $table->foreignId('user_id')->constrained();
            $table->string('action', 20);
            $table->string('role', 20)->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_access_events');
    }
};
