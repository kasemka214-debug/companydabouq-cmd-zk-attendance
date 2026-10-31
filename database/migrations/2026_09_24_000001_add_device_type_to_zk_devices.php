<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zk_devices') || Schema::hasColumn('zk_devices', 'device_type')) {
            return;
        }

        Schema::table('zk_devices', function (Blueprint $table) {
            /*
             * نوعُ الجهاز كما يقوله عن نفسه في المصافحة: `att` جهازُ حضور،
             * `acc` جهازُ تحكّمٍ بالدخول.
             *
             * ولم يكن يُخزَّن، فلم نعرف بمن نخاطب حين نضع أمراً في الطابور —
             * والطابورُ يُسحب في `getrequest` وليس فيه من يقول نوعَه. فكنّا
             * نرسل صيغةَ أجهزة الحضور للجميع، فيردّها جهازُ التحكّم «فشل».
             *
             * وهو مخزَّنٌ لا محسوب: خبرٌ يقوله الجهازُ مرّةً في لحظةٍ لا
             * تتكرّر، ولا يُستنبط من شيءٍ آخر في القاعدة.
             */
            $table->string('device_type', 16)->nullable()->after('firmware');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('zk_devices') && Schema::hasColumn('zk_devices', 'device_type')) {
            Schema::table('zk_devices', function (Blueprint $table) {
                $table->dropColumn('device_type');
            });
        }
    }
};
