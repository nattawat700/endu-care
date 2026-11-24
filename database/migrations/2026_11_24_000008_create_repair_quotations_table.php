<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repair_job_id')->constrained();
            $table->unsignedInteger('revision');
            $table->uuid('submission_id');
            $table->string('organization_name');
            $table->string('branch_name');
            $table->string('customer_name', 150);
            $table->string('device', 150);
            $table->foreignId('issued_by')->constrained('users');
            $table->string('issuer_name');
            $table->json('items');
            $table->unsignedBigInteger('total_satang');
            $table->text('terms');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_channel', 12)->nullable();
            $table->text('approval_note')->nullable();
            $table->timestamps();
            $table->unique(['repair_job_id', 'revision'], 'repair_quote_revision_unique');
            $table->unique(['repair_job_id', 'submission_id'], 'repair_quote_submission_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_quotations');
    }
};
