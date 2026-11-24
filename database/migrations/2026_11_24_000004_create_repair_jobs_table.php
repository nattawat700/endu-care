<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('created_by');
            $table->foreignId('technician_id')->nullable();
            $table->foreignId('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->unsignedInteger('assignment_version')->default(0);
            $table->uuid('submission_id');
            $table->string('customer_name', 150);
            $table->string('customer_phone', 30);
            $table->string('device', 150);
            $table->text('issue');
            $table->timestamps();

            $table->unique(['branch_id', 'created_by', 'submission_id'], 'repair_intake_submission_unique');
            $table->index(['branch_id', 'id']);
            $table->index(['branch_id', 'technician_id', 'id']);

            $table->foreign(['branch_id', 'created_by'], 'repair_reception_membership_fk')
                ->references(['branch_id', 'user_id'])->on('branch_memberships');
            $table->foreign(['branch_id', 'technician_id'], 'repair_technician_membership_fk')
                ->references(['branch_id', 'user_id'])->on('branch_memberships');
            $table->foreign(['branch_id', 'assigned_by'], 'repair_assignment_membership_fk')
                ->references(['branch_id', 'user_id'])->on('branch_memberships');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_jobs');
    }
};
