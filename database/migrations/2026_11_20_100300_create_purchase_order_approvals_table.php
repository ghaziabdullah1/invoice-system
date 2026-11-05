<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('step');

            // approver_id = null يعني أي مستخدم له الصلاحية (نادراً ما يُستخدم)
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();

            // waiting | pending | approved | rejected | escalated | skipped
            $table->string('status', 20)->default('waiting');

            $table->string('signature_path')->nullable();
            $table->string('reason', 500)->nullable();

            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('escalated_at')->nullable();

            $table->foreignId('escalated_to_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['purchase_order_id', 'step']);
            $table->index(['approver_id', 'status']);
            $table->index(['status', 'deadline_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_approvals');
    }
};
