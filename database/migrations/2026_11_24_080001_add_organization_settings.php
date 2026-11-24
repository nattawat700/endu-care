<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->string('operator_type', 20)->default('business');
            $table->dateTime('suspended_at')->nullable();
            $table->json('document_profile')->nullable();
            $table->json('approval_rules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['operator_type', 'suspended_at', 'document_profile', 'approval_rules']);
        });
    }
};
