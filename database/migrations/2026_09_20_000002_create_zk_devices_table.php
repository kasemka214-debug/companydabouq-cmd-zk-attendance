<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zk_devices')) {
            return;
        }

        Schema::create('zk_devices', function (Blueprint $table) {
            $table->id();

            /*
             * الرقمُ التسلسلي هو الهويةُ الوحيدة التي يرسلها الجهاز.
             * بروتوكولُ iclock بلا مصادقة، فهذا العمودُ — وقائمةُ العناوين
             * المسموحة معه — كلُّ ما يفصل جهازَنا عن أيّ أحدٍ يعرف العنوان.
             */
            $table->string('serial_number', 64)->unique();

            $table->string('name');                                  // اسمٌ يفهمه الناس: «بوابة المخزن»
            $table->foreignId('site_id')->nullable()->constrained('zk_sites')->nullOnDelete();
            $table->string('model', 64)->nullable();
            $table->string('firmware', 64)->nullable();
            $table->string('last_ip', 45)->nullable();

            /* عناوينُ IP مسموحة، مفصولةً بفواصل. فارغٌ = بلا تقييد. */
            $table->string('allowed_ips')->nullable();

            /*
             * آخرُ اتصالٍ أياً كان نوعه — وهذا وحدَه يُخزَّن، لأنه لا يُحسب
             * من شيء: جهازٌ يستعلم عن الأوامر كلَّ نصف دقيقةٍ بلا نبضةٍ واحدة
             * حيٌّ يعمل، ولا أثرَ له في جدول النبضات يدلّ عليه. أمّا آخرُ نبضةٍ
             * وعددُها فيُحسبان عند العرض — والمحسوبُ لا يشيخ.
             */
            $table->timestamp('last_seen_at')->nullable();

            /*
             * آخرُ طابعٍ أقرّ به الجهازُ لسجلّ الحضور وسجلّ العمليات.
             * يُرسل في المصافحة، والجهازُ يُكمل منه — فلو ضاع أعاد الجهازُ
             * إرسالَ ما أرسل، ولذلك القيدُ الفريدُ على النبضات لا الثقة.
             */
            $table->string('attlog_stamp', 32)->default('0');
            $table->string('operlog_stamp', 32)->default('0');

            $table->boolean('is_active')->default(true);             // مُطفأٌ = يُردّ عليه بلا قَبولِ بيانات
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('site_id');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_devices');
    }
};
