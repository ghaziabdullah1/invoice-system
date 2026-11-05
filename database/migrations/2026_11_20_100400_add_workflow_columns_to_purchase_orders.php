<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('requester_id')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('requester_department_id')->nullable()->after('requester_id')
                ->constrained('departments')->nullOnDelete();
            $table->foreignId('procurement_officer_id')->nullable()->after('requester_department_id')
                ->constrained('users')->nullOnDelete();

            $table->unsignedSmallInteger('current_step')->default(0)->after('status');
            $table->timestamp('approval_chain_set_at')->nullable()->after('current_step');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requester_id');
            $table->dropConstrainedForeignId('requester_department_id');
            $table->dropConstrainedForeignId('procurement_officer_id');
            $table->dropColumn(['current_step', 'approval_chain_set_at']);
        });
    }
};
