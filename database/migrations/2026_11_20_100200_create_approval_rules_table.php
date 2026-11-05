<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // null = ينطبق على كل الأقسام (fallback)
            $table->foreignId('requester_department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();

            $table->decimal('min_amount', 14, 2)->default(0);
            $table->decimal('max_amount', 14, 2)->nullable(); // null = بلا حد

            $table->unsignedSmallInteger('step');

            // requester_dept_manager | specific_dept_manager | specific_user
            $table->string('approver_type', 30);

            $table->foreignId('approver_department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();

            $table->foreignId('approver_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'requester_department_id', 'min_amount', 'max_amount'], 'approval_rules_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_rules');
    }
};
