<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إعادةُ ضبط موقع كل نبضةٍ على موقع جهازها.
 *
 * كان الموقعُ يُنسخ من الجهاز لحظةَ وصول النبضة ويُجمَّد. فكلُّ بصمةٍ
 * وصلت قبل أن يُسنَد جهازُها إلى موقعه بقيت بلا موقعٍ إلى الأبد — ولو
 * أُسند بعدها بدقيقة.
 *
 * وأثرُه مضاعف: الأسماءُ تُطلب بمفتاح «الموقع|الرقم»، فتلك البصماتُ تظهر
 * «غير مربوط» وإن كان الاسمُ مربوطاً فعلاً. فيُسأل العاملُ عن غيابٍ لم
 * يغِبه، ولا شيءَ في الشاشة يدلّ على السبب.
 *
 * والترحيلُ يُصلح الماضي، و`Device::syncPunchSites` يمنع تكرارَه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zk_punches') || ! Schema::hasTable('zk_devices')) {
            return;
        }

        /*
         * استعلامٌ لكل جهازٍ لا استعلامٌ واحدٌ بضمّ الجدولين: الأجهزةُ
         * عشرات، والضمُّ في التحديث لهجةٌ تختلف بين MySQL وغيرها. وهذا
         * يعمل في كل قاعدةٍ ويُقرأ بلا شرح.
         */
        foreach (DB::table('zk_devices')->select('id', 'site_id')->cursor() as $device) {
            DB::table('zk_punches')
                ->where('device_id', $device->id)
                ->update(['site_id' => $device->site_id]);
        }
    }

    public function down(): void
    {
        // لا رجعة: القيمةُ المستعادة هي الصحيحة، والقديمةُ هي الخطأ.
    }
};
