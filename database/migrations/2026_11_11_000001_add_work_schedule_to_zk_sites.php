<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدولُ الدوام في الموقع: متى يبدأ، وكم يطول، وكم يُسمح.
 *
 * وكان الدوامُ رقماً واحداً في الإعدادات لكل المواقع — ثماني ساعات — يُضرب
 * في أيام الشهر فيخرج المطلوب. فالبرنامجُ يعرف **كم** يُنتظر من الرجل ولا
 * يعرف **متى**: لا يفرّق بين من بصم السابعةَ وبصم العاشرة، وكلاهما ثماني
 * ساعاتٍ في الورقة.
 *
 * والمواقعُ تختلف: مدرسةٌ تبدأ السابعة وموقعٌ يبدأ السادسة والنصف. فصار
 * الجدولُ في الموقع.
 *
 * **والأعمدةُ كلُّها تقبل الفراغ، والفراغُ يرث الإعدادات العامّة** — فيومَ
 * النشر لا يتغيّر على أحدٍ حساب، ولا يُنتظر من صاحب البرنامج أن يفتح شاشةَ
 * المواقع قبل أن تعمل كشوفُه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zk_sites')) {
            return;
        }

        Schema::table('zk_sites', function (Blueprint $table) {
            /* بدايةُ الدوام بتوقيت الكويت — «07:00» */
            if (! Schema::hasColumn('zk_sites', 'work_start')) {
                $table->time('work_start')->nullable()->after('note');
            }

            /* طولُ الدوام بالدقائق لا بالساعات: نصفُ ساعةٍ وربعُها يُكتبان */
            if (! Schema::hasColumn('zk_sites', 'work_minutes')) {
                $table->unsignedSmallInteger('work_minutes')->nullable()->after('work_start');
            }

            /* مهلةُ السماح بالدقائق — ترفع اسمَ التأخّر ولا ترفع الدقائقَ من الدوام */
            if (! Schema::hasColumn('zk_sites', 'grace_minutes')) {
                $table->unsignedTinyInteger('grace_minutes')->nullable()->after('work_minutes');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('zk_sites')) {
            return;
        }

        Schema::table('zk_sites', function (Blueprint $table) {
            foreach (['grace_minutes', 'work_minutes', 'work_start'] as $col) {
                if (Schema::hasColumn('zk_sites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
