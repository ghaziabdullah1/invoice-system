<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_approvals', function (Blueprint $table) {
            $table->unsignedSmallInteger('sla_hours')->nullable()->after('deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_approvals', function (Blueprint $table) {
            $table->dropColumn('sla_hours');
        });
    }
};
