<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_jobs', function (Blueprint $table) {
            $table->string('status', 24)->default('received');
            $table->unsignedInteger('workflow_version')->default(0);
            $table->index(['branch_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('repair_jobs', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'status', 'id']);
            $table->dropColumn(['status', 'workflow_version']);
        });
    }
};
