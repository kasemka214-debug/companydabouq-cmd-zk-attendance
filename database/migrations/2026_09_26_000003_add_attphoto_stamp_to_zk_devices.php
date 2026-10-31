<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zk_devices') || Schema::hasColumn('zk_devices', 'attphoto_stamp')) {
            return;
        }

        Schema::table('zk_devices', function (Blueprint $table) {
            /*
             * طابعُ الصور — موضعُ القراءة في ذاكرة الجهاز، كطابعَي الحضور
             * والعمليات. وقيمتُه الابتدائية «1» لا «0»: الصفرُ يقول للجهاز
             * «أنت محدَّث فلا ترسل»، فيسكت عن الصور وإن أذِن لها TransFlag.
             */
            $table->string('attphoto_stamp', 32)->default('1')->after('operlog_stamp');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('zk_devices') && Schema::hasColumn('zk_devices', 'attphoto_stamp')) {
            Schema::table('zk_devices', function (Blueprint $table) {
                $table->dropColumn('attphoto_stamp');
            });
        }
    }
};
