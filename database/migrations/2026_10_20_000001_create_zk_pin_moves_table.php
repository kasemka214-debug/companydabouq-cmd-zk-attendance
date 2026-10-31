<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * النقلُ بين المواقع — واقعةٌ مؤرَّخةٌ على صفّين.
 *
 * **ولا تُكتب بـ`left_on` ولو كان سببُها `transfer`.** فتلك تُقرأ على
 * العنقود في `Employment::until()`، فيرثها صفُّ الموقع الجديد فيُسكت
 * الرجلَ هناك **إلى الأبد**: يغيب شهراً ولا سطرَ يُنبّه. والحاجزُ هناك
 * `$on < $stintFrom` لا يُسقط التساوي، فنقلٌ بتاريخ اليوم ونشأةُ صفٍّ
 * اليومَ يتساويان فيمرّ القرارُ.
 *
 * فالقسمة: **تركُ الشركة واقعةٌ على العنقود**، و**الانتقالُ بين المواقع
 * واقعةٌ على الصفّ**. ولا دالّةَ تقرأ المحورين معاً.
 *
 * **ولا عمودان على `zk_pins`** (`served_from`/`moved_on`): النقلُ العائد
 * أ ← ب ← أ يحتاج صفَّ (أ) إلى **مدّتين**، وخانتان لا تحملان إلا واحدة —
 * فيُمحى الأوّلُ بالثاني وتسقط أيامٌ صُرف عليها أجرُها من ورقةٍ تُعاد
 * طباعتُها. وهو عينُ ما حرّمته قاعدةُ «نهايةُ الخدمة مدّةٌ لا عَلَم».
 *
 * **ولا صفوفُ ربطٍ إضافية** لتمثيل المُدَد: `UNIQUE(site_id, device_pin)`
 * يمنع صفّاً ثانياً للزوج نفسِه. فالجدولُ المنفصل ليس تفضيلاً — هو الشكلُ
 * الوحيد الذي يقبله المفتاحُ القائم.
 *
 * **ولا `from_site_id` ولا `to_site_id`**: يُشتقّان من صفّي الربط،
 * والمحسوبُ لا يُخزَّن.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zk_pin_moves')) {
            return;
        }

        Schema::create('zk_pin_moves', function (Blueprint $table) {
            $table->id();

            /*
             * الصفُّ المنقولُ منه والصفُّ المنقولُ إليه، وكلاهما مطلوب.
             *
             * و`restrictOnDelete` لا `cascadeOnDelete`: حذفُ صفّ ربطٍ طرفٍ
             * في نقلٍ يُسقط الواقعةَ فتنقلب مُدَدُ الطرف الآخر بلا أن يُدرى
             * — فيُسأل رجلٌ عن أيامٍ لم يكن فيها، أو يُسكت عن أيامٍ كان.
             * فالقاعدةُ تمنع الحذفَ، والمتحكّمُ يشرح المنعَ بالعربية.
             */
            $table->foreignId('from_pin_id')->constrained('zk_pins')->restrictOnDelete();
            $table->foreignId('to_pin_id')->constrained('zk_pins')->restrictOnDelete();

            /*
             * **أوّلُ يومِ دوامٍ في الموقع الجديد** — فآخرُ يومٍ في القديم
             * هو ما قبله. وهذا يخالف `left_on` وتلك **داخلةٌ** في الحساب،
             * والمخالفةُ مقصودةٌ ومكتوبةٌ لئلا تُقاس إحداهما على الأخرى.
             */
            $table->date('moved_on')->index();

            $table->string('note', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /* الاستعلامُ الأشيع: أحداثُ صفٍّ بعينه، وصولاً أو مغادرة */
            $table->index(['to_pin_id', 'moved_on'], 'ix_zk_move_in');
            $table->index(['from_pin_id', 'moved_on'], 'ix_zk_move_out');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_pin_moves');
    }
};
