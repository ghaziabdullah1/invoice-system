<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        // الفهرس المركّب كان يخدم المفتاح الأجنبي أيضاً، فنضيف فهرساً عادياً قبل حذفه.
        // الفريدية تُفرض في الـ Requests مع تجاهل السجلات المحذوفة ناعماً.
        Schema::table('floors', function (Blueprint $table) {
            $table->index('property_id');
            $table->dropUnique(['property_id', 'floor_number']);
        });

        Schema::table('units', function (Blueprint $table) {
            $table->index('floor_id');
            $table->dropUnique(['floor_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->unique(['floor_id', 'name']);
            $table->dropIndex(['floor_id']);
        });

        Schema::table('floors', function (Blueprint $table) {
            $table->unique(['property_id', 'floor_number']);
            $table->dropIndex(['property_id']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('is_active')->constrained('branches')->nullOnDelete();
        });
    }
};
