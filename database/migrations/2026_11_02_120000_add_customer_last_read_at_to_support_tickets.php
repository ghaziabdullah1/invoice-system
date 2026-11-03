<?php

use App\Models\SupportTicket;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = (new SupportTicket)->getTable();
        if (Schema::hasColumn($table, 'customer_last_read_at')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            $t->timestamp('customer_last_read_at')->nullable();
        });

        // التذاكر الموجودة تُعتبر مقروءة الآن حتى لا يظهر عدّاد قديم كاذب
        DB::table($table)->update(['customer_last_read_at' => now()]);
    }

    public function down(): void
    {
        $table = (new SupportTicket)->getTable();
        if (Schema::hasColumn($table, 'customer_last_read_at')) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('customer_last_read_at');
            });
        }
    }
};
