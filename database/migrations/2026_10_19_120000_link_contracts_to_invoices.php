<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('rent_amount');
        });

        // حساب الفوترة للمستأجر: عميل عادي في نظام الفواتير
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('unit_id')->constrained('clients')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('recurring_invoice_template_id')->constrained('contracts')->nullOnDelete();
            $table->date('period_start')->nullable()->after('contract_id');
            $table->date('period_end')->nullable()->after('period_start');
            $table->index(['contract_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
            $table->dropColumn(['period_start', 'period_end']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
