<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zk_devices')) {
            return;
        }

        Schema::table('zk_devices', function (Blueprint $table) {
            /*
             * فرقُ ساعة الجهاز عن ساعة الخادم بالثواني — موجبٌ إن كانت
             * ساعتُه متقدّمة.
             *
             * وهو الحارسُ الوحيد الممكن على العبث بالوقت: البصمةُ تُختم
             * بساعة الجهاز، فمن أخّرها سجّل حضوراً قبل وقته. ولا يظهر ذلك
             * في السطر نفسِه — يظهر في الفرق بين ما ادّعاه الجهازُ ومتى
             * وصل ادّعاؤه.
             */
            if (! Schema::hasColumn('zk_devices', 'clock_drift')) {
                $table->integer('clock_drift')->nullable()->after('last_seen_at');
            }

            if (! Schema::hasColumn('zk_devices', 'clock_checked_at')) {
                $table->timestamp('clock_checked_at')->nullable()->after('clock_drift');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('zk_devices')) {
            return;
        }

        Schema::table('zk_devices', function (Blueprint $table) {
            foreach (['clock_drift', 'clock_checked_at'] as $col) {
                if (Schema::hasColumn('zk_devices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
