<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_jobs', function (Blueprint $table): void {
            $table->dateTime('due_at')->nullable();
            $table->unsignedInteger('due_version')->default(0);
            $table->index(['branch_id', 'due_at', 'id']);
        });

        DB::table('repair_jobs')->whereExists(function ($query): void {
            $query->selectRaw('1')->from('repair_assessments')->whereColumn('repair_job_id', 'repair_jobs.id');
        })->update([
            'due_at' => DB::raw('(SELECT expected_completion_at FROM repair_assessments WHERE repair_job_id = repair_jobs.id ORDER BY revision DESC LIMIT 1)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('repair_jobs', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'due_at', 'id']);
            $table->dropColumn(['due_at', 'due_version']);
        });
    }
};
