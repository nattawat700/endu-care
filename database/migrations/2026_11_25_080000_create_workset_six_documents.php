<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->text('tax_profile')->nullable());
        Schema::table('branches', fn (Blueprint $table) => $table->json('issuance_settings')->nullable());

        Schema::create('repair_closures', function (Blueprint $table): void {
            $table->foreignId('repair_job_id')->primary()->constrained();
            $table->foreignId('closed_by')->constrained('users');
            $table->string('outcome', 30);
            $table->text('details');
            $table->timestamp('closed_at');
        });
        Schema::create('service_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->foreignId('repair_job_id')->nullable()->constrained();
            $table->foreignId('pos_sale_id')->nullable()->constrained();
            $table->foreignId('received_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->uuid('submission_id');
            $table->text('intake');
            $table->string('status', 16)->default('received');
            $table->text('review')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'submission_id']);
            $table->index(['branch_id', 'customer_id', 'id']);
        });
        Schema::create('document_series', function (Blueprint $table): void {
            $table->foreignId('branch_id')->constrained();
            $table->string('kind', 20);
            $table->string('prefix', 12);
            $table->unsignedBigInteger('next_number');
            $table->primary(['branch_id', 'kind']);
        });
        Schema::create('issued_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('issued_by')->constrained('users');
            $table->string('kind', 20);
            $table->string('number', 80);
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->string('source_reference', 80);
            $table->text('snapshot');
            $table->timestamps();
            $table->unique(['branch_id', 'number']);
            $table->unique(['branch_id', 'kind', 'source_type', 'source_id'], 'issued_doc_source_unique');
        });
        Schema::create('document_prints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('printed_by')->constrained('users');
            $table->string('document_type', 24);
            $table->unsignedBigInteger('document_id');
            $table->string('number', 80);
            $table->timestamp('printed_at');
            $table->index(['branch_id', 'document_type', 'document_id', 'id'], 'document_print_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_prints');
        Schema::dropIfExists('issued_documents');
        Schema::dropIfExists('document_series');
        Schema::dropIfExists('service_claims');
        Schema::dropIfExists('repair_closures');
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('issuance_settings'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('tax_profile'));
    }
};
