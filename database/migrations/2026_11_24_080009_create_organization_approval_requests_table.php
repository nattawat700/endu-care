<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->uuid('submission_id');
            $table->foreignId('requested_by')->constrained('users');
            $table->string('kind', 24);
            $table->string('reference', 100);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('limit');
            $table->string('review_role', 20);
            $table->string('status', 20);
            $table->text('reason');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->dateTime('reviewed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->unique(['branch_id', 'submission_id'], 'org_approval_submission_unique');
            $table->index(['branch_id', 'status', 'id'], 'org_approval_queue_index');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_approval_requests');
    }
};
