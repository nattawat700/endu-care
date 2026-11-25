<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_jobs', function (Blueprint $table): void {
            $table->string('work_kind', 16)->nullable();
            $table->unsignedInteger('fixed_price_satang')->nullable();
            $table->string('storage_location', 120)->nullable();
        });

        Schema::create('repair_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repair_job_id')->constrained();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('phase', 12);
            $table->string('filename', 64);
            $table->string('content_type', 20);
            $table->timestamps();
            $table->index(['repair_job_id', 'id']);
        });

        Schema::create('repair_quality_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repair_job_id')->constrained();
            $table->foreignId('inspected_by')->constrained('users');
            $table->unsignedInteger('workflow_version');
            $table->json('items');
            $table->boolean('passed');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['repair_job_id', 'workflow_version'], 'repair_quality_workflow_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_quality_checks');
        Schema::dropIfExists('repair_photos');
        Schema::table('repair_jobs', fn (Blueprint $table) => $table->dropColumn(['work_kind', 'fixed_price_satang', 'storage_location']));
    }
};
