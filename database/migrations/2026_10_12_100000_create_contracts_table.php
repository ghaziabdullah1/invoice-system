<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_number')->unique();

            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date');

            $table->decimal('rent_amount', 12, 2);
            $table->string('payment_frequency', 20);
            $table->unsignedTinyInteger('payment_day')->default(1);
            $table->decimal('security_deposit', 12, 2)->default(0);
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->decimal('annual_increase_percent', 5, 2)->nullable();

            $table->string('status', 20);
            $table->string('contract_document_path')->nullable();

            $table->foreignId('renewed_from_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->timestamp('terminated_at')->nullable();
            $table->string('termination_reason')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'end_date']);
            $table->index(['unit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
