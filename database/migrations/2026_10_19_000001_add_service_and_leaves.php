<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نهايةُ الخدمة والإجازات.
 *
 * وكان النظامُ لا يعرف إلا «رقمٌ مربوطٌ باسم»، فلا يُفرَّق بين من غاب
 * اليومَ ومن تركَ العملَ في الصيف. وعُولج ذلك بتخمين — رقمٌ لم تصل منه
 * بصمةٌ منذ ستّين يوماً لا يُسأل عن غياب — وهو تخمينٌ يُخطئ في الجهتين:
 * يُسكت عاملاً في إجازةٍ دورية، ويُبقي أشباحاً سنةً كاملة.
 *
 * فصار الأصلُ مكتوباً: متى تركَ، ولماذا، ومتى كان في إجازة.
 *
 * **ولا جدولَ أشخاصٍ ولا `employee_id`.** فلا مُعرِّفَ شخصٍ في هذا النظام،
 * والتعريفُ القائمُ يُشتقّ من صفّ الربط عند كل قراءة (`identities()`).
 * فلو خُزّنت هويّةٌ في جدولٍ لشاخت عند أوّل تصحيحِ اسم: يُصحَّح «محمد احمد»
 * إلى «محمد أحمد» فينقطع الصفُّ عن موظفه، فتسقط إجازتُه ويخرج أحمر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zk_pins') && ! Schema::hasColumn('zk_pins', 'left_on')) {
            Schema::table('zk_pins', function (Blueprint $table) {
                /*
                 * `left_on` آخرُ يومٍ له في العمل — **داخلٌ في الحساب**.
                 * فمن تركَ العملَ في الخامس عشر يُسأل عن الخامس عشر ولا
                 * يُسأل عمّا بعده.
                 */
                $table->date('left_on')->nullable()->after('is_active');
                $table->string('left_reason', 24)->nullable()->after('left_on');

                $table->index('left_on');
            });
        }

        if (! Schema::hasTable('zk_pin_leaves')) {
            Schema::create('zk_pin_leaves', function (Blueprint $table) {
                $table->id();

                /*
                 * مفتاحُه صفُّ الربط — ويسري على إخوته بالهويّة عند القراءة.
                 *
                 * فالرجلُ قد يكون في الجدول صفّين (جهازٌ نُقل، أو أُعيد
                 * ضبطُه، أو زرُّ «انسخ الاسم»). فلو لزم تسجيلُ الإجازة على
                 * كلٍّ منهما لنُسي أحدُهما يوماً، فخرج الرجلُ «لم يبصم»
                 * بالأحمر وهو في إجازةٍ موقَّعة — وهو عينُ ما جاءت الميزةُ
                 * لتمنعه.
                 */
                $table->foreignId('pin_id')->constrained('zk_pins')->cascadeOnDelete();

                /*
                 * التواريخُ `date` لا `datetime`: الإجازةُ أيامٌ لا لحظات،
                 * وساعةٌ في الطرف تُخرج يوماً من الحساب أو تُدخله.
                 */
                $table->date('from_date');
                $table->date('to_date');

                /* نوعُها — نصٌّ قصيرٌ من قائمةٍ في `Employment::KINDS` */
                $table->string('kind', 24)->default('annual');
                $table->string('note', 500)->nullable();

                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                /* الاستعلامُ الأشيع: إجازاتٌ تتقاطع مع مدّة الكشف */
                $table->index(['pin_id', 'from_date', 'to_date'], 'ix_zk_leave_span');
                $table->index(['from_date', 'to_date'], 'ix_zk_leave_range');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_pin_leaves');

        if (Schema::hasTable('zk_pins') && Schema::hasColumn('zk_pins', 'left_on')) {
            Schema::table('zk_pins', function (Blueprint $table) {
                $table->dropIndex(['left_on']);
                $table->dropColumn(['left_on', 'left_reason']);
            });
        }
    }
};
