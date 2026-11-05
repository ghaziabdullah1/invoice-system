<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_rules', function (Blueprint $table) {
            $table->string('approver_email')->nullable()->after('approver_user_id');
            $table->string('approver_name')->nullable()->after('approver_email');
            $table->string('approver_title')->nullable()->after('approver_name');
            $table->unsignedSmallInteger('sla_hours')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('approval_rules', function (Blueprint $table) {
            $table->dropColumn(['approver_email', 'approver_name', 'approver_title', 'sla_hours']);
        });
    }
};
