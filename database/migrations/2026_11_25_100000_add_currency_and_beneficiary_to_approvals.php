<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_rules', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('max_amount');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('beneficiary_department_id')
                ->nullable()
                ->after('requester_department_id')
                ->constrained('departments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('beneficiary_department_id');
        });

        Schema::table('approval_rules', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};