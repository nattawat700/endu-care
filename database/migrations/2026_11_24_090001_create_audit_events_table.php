<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('actor_name')->nullable();
            $table->string('subject_type', 32);
            $table->string('subject_reference', 100);
            $table->string('operation', 24);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->json('protected_changes')->nullable();
            $table->dateTime('occurred_at');
            $table->index(['branch_id', 'id']);
            $table->index(['organization_id', 'branch_id', 'id'], 'audit_org_scope_index');
            $table->index(['subject_reference', 'id']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
