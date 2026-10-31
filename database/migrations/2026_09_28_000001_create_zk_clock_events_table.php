<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أحداثُ الساعة — حكايةُ انحرافٍ من أوّله إلى آخره.
 *
 * والحدثُ مدّةٌ لا لحظة: يُفتح حين تُقرأ ساعةُ الجهاز خارج الحدّ، ويُغلق
 * حين تعود. وبينهما كلُّ ما وصل من ذلك الجهاز مشكوكٌ فيه.
 *
 * وسطرٌ لكل مدّةٍ لا لكل قراءة — فجهازٌ ساعتُه مؤخَّرةٌ ساعتين يدفع مئةَ
 * بصمةٍ في اليوم، فلو كُتب سطرٌ لكل قراءةٍ صار الجدولُ مئةَ إنذارٍ لحدثٍ
 * واحد، ولا يُقرأ منها شيء.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zk_clock_events')) {
            Schema::create('zk_clock_events', function (Blueprint $table) {
                $table->id();

                $table->foreignId('device_id')->constrained('zk_devices')->cascadeOnDelete();

                /*
                 * ثلاثةُ أوقاتٍ لا واحد، ولكلٍّ معنى:
                 *
                 *   `opened_at`   — متى **علمنا**. وهو بساعتنا.
                 *   `suspect_from`— متى **بدأ الشكّ**: آخرُ قراءةٍ نظيفةٍ قبلها.
                 *                   وهو أبعدُ من `opened_at`، وبينهما ما وصل
                 *                   ونحن لا ندري.
                 *   `closed_at`   — متى عادت.
                 *
                 * والفصلُ بين الأوّلين هو صلبُ المسألة: من غيّر الساعةَ
                 * الثامنةَ صباحاً لا يُكتشف إلا عند أوّل بصمةٍ بعدها. فلو
                 * بدأت نافذةُ الشكّ من لحظة الكشف لسلمت البصماتُ التي
                 * وقعت بينهما — وهي المقصودةُ بالعبث.
                 */
                $table->timestamp('opened_at')->index();
                $table->timestamp('suspect_from');
                $table->timestamp('closed_at')->nullable()->index();

                /* الانحرافُ بالثواني: موجبٌ مقدَّمٌ وسالبٌ مؤخَّر */
                $table->integer('open_drift');
                $table->integer('peak_drift');
                $table->integer('close_drift')->nullable();

                /* تصنيفُ السبب وثقتُنا فيه — انظر ClockJudge */
                $table->string('class', 24)->index();
                $table->string('confidence', 16)->default('probable');

                /*
                 * كيف عادت — يُقرَّر لحظةَ الإغلاق و**يُخزَّن**.
                 *
                 * وهذا استثناءٌ مقصودٌ من «المحسوب لا يُخزَّن»: الحكمُ يُبنى
                 * على أوامرَ أُرسلت في تلك المدّة، والأوامرُ تُقصّ بعد حين.
                 * فلو حُسب الحكمُ عند العرض لانقلب بعد شهرٍ من «أعاده أمرُنا»
                 * إلى «أعادته يدٌ» — وهي تهمةٌ لا تُقال بأثرٍ رجعيّ لأن
                 * دليلَ البراءة حُذف.
                 */
                $table->string('resolution', 16)->nullable();
                $table->foreignId('resolution_command_id')->nullable()
                    ->constrained('zk_commands')->nullOnDelete();

                /* القراءةُ التي فتحت الحدث، ونصُّها كما كان — للمراجعة */
                $table->unsignedInteger('readings')->default(1);
                $table->text('evidence')->nullable();

                /*
                 * إقرارُ المسؤول بأنه رأى الإشعار.
                 *
                 * والإشعارُ يُخفى بالإقرار لا بالإغلاق: حدثٌ فُتح وأُغلق في
                 * دقيقةٍ — وهو أفصحُ ما يكون على عبثٍ سريع — لو اختفى
                 * بإغلاقه لما رآه أحدٌ أصلاً.
                 */
                $table->timestamp('acknowledged_at')->nullable();
                $table->foreignId('acknowledged_by')->nullable()
                    ->constrained('users')->nullOnDelete();
                $table->string('note', 1000)->nullable();

                $table->timestamps();

                /* الاستعلامُ الأشيع: أحداثُ جهازٍ مفتوحةً كانت أو مغلقة */
                $table->index(['device_id', 'closed_at']);
            });
        }

        /*
         * سجلُّ عمليات الجهاز — شاهدٌ يُسمّي الفاعل.
         *
         * الجهازُ يبلّغ في `OPERLOG` مَن فتح قائمتَه ومَن غيّر ساعتَه
         * **برقمه**. وهذا هو الجوابُ الوحيد على «مَن» — فالانحرافُ يكشف أن
         * الساعةَ تغيّرت ولا يقول بيدِ من.
         *
         * ويبقى شاهداً لا حاكماً: جدولُ الرموز يختلف بين الإصدارات، ووقتُه
         * بساعة الجهاز نفسِها — أي بالساعة المتّهمة. فيُخزَّن الوقتان:
         * ما ادّعاه، ومتى وصل.
         */
        if (! Schema::hasTable('zk_operations')) {
            Schema::create('zk_operations', function (Blueprint $table) {
                $table->id();

                $table->foreignId('device_id')->constrained('zk_devices')->cascadeOnDelete();

                $table->unsignedSmallInteger('op_code')->index();
                $table->string('operator_pin', 32)->nullable()->index();

                /* ما ادّعاه الجهاز — وهو بساعته، فقد يكون كاذباً */
                $table->timestamp('occurred_at')->nullable();

                /* متى وصلَنا — بساعتنا، وهو الذي يُعتمد في الترتيب */
                $table->timestamp('received_at')->index();

                /* الحقولُ الأربعة التي تتبع الرمز في السطر */
                $table->string('params', 190)->nullable();

                /*
                 * بصمةُ السطر كما وصل — والقيدُ الفريد عليها وحدَها.
                 *
                 * ولم يُجعل القيدُ على الحقول مباشرةً لعلّةٍ في MySQL: يعدّ
                 * `NULL` مخالفاً لـ`NULL`، فسطرٌ لا مسؤولَ له أو وقتُه
                 * مشوَّهٌ لا يمنعه القيدُ من التكرار. وذاك أشيعُ ما يقع —
                 * فالجهازُ يُعيد الدفعةَ كلَّها إن لم يصله إقرارُنا، فكان
                 * «غُيِّرت الساعةُ مرّة» يصير ثلاثاً في التقرير.
                 *
                 * والبصمةُ لا تعرف `NULL`: تُرمَّز الحقولُ كلُّها نصّاً.
                 */
                $table->char('digest', 40);

                $table->timestamps();

                $table->unique(['device_id', 'digest'], 'uq_zk_op_once');
            });
        }

        /*
         * آخرُ قراءةٍ **نظيفة** — وهي مبدأُ نافذة الشكّ.
         *
         * ولا يكفي `clock_checked_at`: ذاك آخرُ قراءةٍ أيّاً كانت، فإن
         * كانت منحرفةً بدأت النافذةُ من داخل الانحراف نفسِه فضاق ما يُفحص.
         */
        if (Schema::hasTable('zk_devices') && ! Schema::hasColumn('zk_devices', 'clock_good_at')) {
            Schema::table('zk_devices', function (Blueprint $table) {
                $table->timestamp('clock_good_at')->nullable()->after('clock_checked_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_operations');
        Schema::dropIfExists('zk_clock_events');

        if (Schema::hasTable('zk_devices') && Schema::hasColumn('zk_devices', 'clock_good_at')) {
            Schema::table('zk_devices', function (Blueprint $table) {
                $table->dropColumn('clock_good_at');
            });
        }
    }
};
