<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // آخر عتبة تنبيه أُرسلت (90 أو 60 أو 30 يوماً قبل الانتهاء)
            $table->unsignedSmallInteger('expiry_alert_days')->nullable()->after('termination_reason');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('expiry_alert_days');
        });
    }
};
