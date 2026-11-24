<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repair_job_id')->constrained();
            $table->unsignedInteger('revision');
            $table->uuid('submission_id');
            $table->foreignId('recorded_by')->constrained('users');
            $table->text('cause');
            $table->text('repair_plan');
            $table->dateTime('expected_completion_at');
            $table->json('items');
            $table->unsignedBigInteger('total_satang');
            $table->timestamps();
            $table->unique(['repair_job_id', 'revision'], 'repair_assessment_revision_unique');
            $table->unique(['repair_job_id', 'submission_id'], 'repair_assessment_submission_unique');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('repair_assessments');
    }
};
