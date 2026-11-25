<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->uuid('submission_id');
            $table->string('name', 150);
            $table->string('name_key', 150);
            $table->string('phone', 30);
            $table->string('phone_key', 15);
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('email', 254)->nullable();
            $table->text('delivery_address')->nullable();
            $table->text('billing_address')->nullable();
            $table->string('service_channel', 16);
            $table->string('marketing_preference', 16);
            $table->timestamps();

            $table->unique(['branch_id', 'submission_id']);
            $table->unique(['branch_id', 'id']);
            $table->index(['branch_id', 'phone_key']);
            $table->index(['branch_id', 'name_key']);
        });

        Schema::create('customer_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('model', 150);
            $table->string('serial_number', 80)->nullable();
            $table->string('imei', 15)->nullable();
            $table->date('warranty_until')->nullable();
            $table->text('warranty_note')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'id']);
            $table->unique(['customer_id', 'serial_number']);
            $table->unique(['customer_id', 'imei']);
        });

        Schema::create('customer_appointments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id');
            $table->foreignId('responsible_user_id')->constrained('users');
            $table->uuid('submission_id');
            $table->string('kind', 16);
            $table->string('status', 16)->default('scheduled');
            $table->dateTime('starts_at');
            $table->text('note')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->foreign(['branch_id', 'customer_id'], 'crm_appointment_customer_fk')
                ->references(['branch_id', 'id'])->on('customers');
            $table->unique(['branch_id', 'submission_id'], 'crm_appointment_submission_unique');
            $table->index(['branch_id', 'status', 'starts_at', 'id'], 'crm_appointment_queue_index');
            $table->index(['customer_id', 'starts_at']);
        });

        Schema::table('repair_jobs', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable();
            $table->foreignId('customer_device_id')->nullable();

            $table->foreign(['branch_id', 'customer_id'])
                ->references(['branch_id', 'id'])->on('customers');
            $table->foreign(['customer_id', 'customer_device_id'])
                ->references(['customer_id', 'id'])->on('customer_devices');
            $table->index(['customer_id', 'id'], 'repair_crm_customer_index');
            $table->index(['customer_device_id', 'id'], 'repair_crm_device_index');
        });
    }

    public function down(): void
    {
        Schema::table('repair_jobs', function (Blueprint $table): void {
            $table->dropForeign(['branch_id', 'customer_id']);
            $table->dropForeign(['customer_id', 'customer_device_id']);
            $table->dropIndex('repair_crm_customer_index');
            $table->dropIndex('repair_crm_device_index');
            $table->dropColumn(['customer_id', 'customer_device_id']);
        });

        Schema::dropIfExists('customer_appointments');
        Schema::dropIfExists('customer_devices');
        Schema::dropIfExists('customers');
    }
};
