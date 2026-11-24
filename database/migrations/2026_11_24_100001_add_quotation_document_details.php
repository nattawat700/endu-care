<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_quotations', function (Blueprint $table) {
            $table->date('valid_until')->nullable();
            $table->json('document_details')->nullable();
            $table->json('amount_breakdown')->nullable();
            $table->string('signature_filename', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repair_quotations', fn (Blueprint $table) => $table->dropColumn([
            'valid_until', 'document_details', 'amount_breakdown', 'signature_filename',
        ]));
    }
};
