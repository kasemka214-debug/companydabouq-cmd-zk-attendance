<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ملاحظةُ يومٍ: أبإذنٍ تأخّرَ أم بغير إذن، وما سببُه.
 *
 * والبرنامجُ يرصد التأخّرَ والنقصَ بالحساب — من البصمات وجدولِ الموقع — ولا
 * يعرف لهما سبباً. والسببُ عند الناس: رجلٌ استأذن مديرَه، وآخرُ تأخّر بلا
 * إذن. فلا بدّ من صفٍّ يُكتب فيه القرار، وإلا بقي الكشفُ يُظهر رجلاً
 * مستأذِناً بالأحمر كلَّ شهر.
 *
 * **ومفتاحُه يومُ العمل لا صفُّ الربط** — بخلاف الإجازات. فالإجازةُ مدّةٌ
 * لشخصٍ تسري على صفوف ربطه كلِّها، والملاحظةُ واقعةُ يومٍ بعينه: موقعٌ ورقمٌ
 * ويوم — وهي الثلاثةُ التي يجمع بها `DayBuilder` صفوفَه، فتُلصق به بلا
 * وسيط. ولو عُلّقت بصفّ الربط لانقطعت عن يومها يومَ يُحذف الصفُّ ويُعاد.
 *
 * **ولا موقعَ ⇐ لا جدولَ ⇐ لا حكم**: نبضةٌ من جهازٍ بلا موقعٍ لا يُقاس
 * عليها تأخّر، فلا ملاحظةَ لها — ولذلك الموقعُ هنا مطلوبٌ لا يقبل الفراغ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zk_day_notes')) {
            return;
        }

        Schema::create('zk_day_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_id')->constrained('zk_sites')->cascadeOnDelete();
            $table->string('device_pin', 32);
            $table->date('work_date');

            /* القرار: بإذنٍ أو بغير إذن — وهو أصلُ الملاحظة لا زينتُها */
            $table->boolean('permitted')->default(false);

            /* السببُ يُكتب مع الإذن: «مراجعةُ وزارة»، «ظرفٌ عائلي» */
            $table->string('reason', 190)->nullable();

            /* وملاحظةٌ حرّةٌ لما لا يسعه السطر */
            $table->text('note')->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * قرارٌ واحدٌ لليوم الواحد — لا قراران متناقضان في صفّين،
             * والحفظُ `updateOrCreate` يعتمد عليه.
             */
            $table->unique(['site_id', 'device_pin', 'work_date'], 'uq_zk_day_note');

            /* الاستعلامُ الأشيع: ملاحظاتُ مدّةِ الكشف كلِّها دفعةً واحدة */
            $table->index(['work_date', 'site_id'], 'ix_zk_day_note_range');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_day_notes');
    }
};
