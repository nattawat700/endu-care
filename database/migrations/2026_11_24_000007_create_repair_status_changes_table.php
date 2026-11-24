<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repair_job_id')->constrained();
            $table->unsignedInteger('version');
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->foreignId('changed_by')->constrained('users');
            $table->text('note')->nullable();
            $table->timestamp('created_at');
            $table->unique(['repair_job_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_status_changes');
    }
};
