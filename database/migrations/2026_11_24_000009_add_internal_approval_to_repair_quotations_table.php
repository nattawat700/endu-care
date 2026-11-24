<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_quotations', function (Blueprint $table) {
            $table->boolean('requires_internal_approval')->default(false);
            $table->foreignId('internal_requested_by')->nullable()->constrained('users');
            $table->timestamp('internal_requested_at')->nullable();
            $table->text('internal_request_reason')->nullable();
            $table->string('internal_decision', 12)->nullable();
            $table->foreignId('internal_decided_by')->nullable()->constrained('users');
            $table->timestamp('internal_decided_at')->nullable();
            $table->text('internal_decision_reason')->nullable();
            $table->index(['internal_decision', 'internal_requested_at', 'id'], 'repair_quotation_pending_review_index');
        });
    }

    public function down(): void
    {
        Schema::table('repair_quotations', function (Blueprint $table) {
            $table->dropForeign(['internal_requested_by']);
            $table->dropForeign(['internal_decided_by']);
            $table->dropIndex('repair_quotation_pending_review_index');
            $table->dropColumn([
                'requires_internal_approval', 'internal_requested_by', 'internal_requested_at', 'internal_request_reason',
                'internal_decision', 'internal_decided_by', 'internal_decided_at', 'internal_decision_reason',
            ]);
        });
    }
};
