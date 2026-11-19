<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وتيرةُ نداء الجهاز الحالية: نهارٌ أم ليل.
 *
 * وهي **حالةٌ لا حساب**: ما الذي قيل لهذا الجهاز آخرَ مرّة؟ فبغيرها
 * يُرسَل أمرُ `SET OPTION Delay=` في كل نداء — ألفَي أمرٍ في اليوم لكل
 * جهاز، وطابورٌ لا يفرغ، وسجلُّ أوامرَ لا يُقرأ.
 *
 * والفراغُ يعني «لم يُضبط بعد»، فأوّلُ نداءٍ بعد النشرة يضبطه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zk_devices') || Schema::hasColumn('zk_devices', 'poll_mode')) {
            return;
        }

        Schema::table('zk_devices', function (Blueprint $table) {
            $table->string('poll_mode', 8)->nullable();
            $table->timestamp('poll_mode_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('zk_devices')) {
            return;
        }

        Schema::table('zk_devices', function (Blueprint $table) {
            foreach (['poll_mode', 'poll_mode_at'] as $col) {
                if (Schema::hasColumn('zk_devices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
