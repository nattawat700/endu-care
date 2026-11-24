<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('suspended_at')->nullable();
            $table->unsignedInteger('staff_session_version')->default(1);
            $table->text('mfa_secret')->nullable();
            $table->json('mfa_recovery_hashes')->nullable();
            $table->unsignedBigInteger('mfa_last_step')->nullable();
            $table->string('mfa_setup_code_hash', 64)->nullable();
            $table->dateTime('mfa_setup_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'suspended_at', 'staff_session_version', 'mfa_secret', 'mfa_recovery_hashes', 'mfa_last_step',
            'mfa_setup_code_hash', 'mfa_setup_expires_at',
        ]));
    }
};
