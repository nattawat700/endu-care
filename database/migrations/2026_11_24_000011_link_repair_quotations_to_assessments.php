<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_quotations', function (Blueprint $table) {
            $table->foreignId('repair_assessment_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('repair_quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repair_assessment_id');
        });
    }
};
