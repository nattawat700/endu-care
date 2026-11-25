<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_quotations', function (Blueprint $table): void {
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repair_quotations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
