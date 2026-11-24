<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_quotations', function (Blueprint $table) {
            $table->text('revision_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repair_quotations', function (Blueprint $table) {
            $table->dropColumn('revision_reason');
        });
    }
};
