<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zk_punch_photos')) {
            return;
        }

        Schema::create('zk_punch_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')->constrained('zk_devices')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('zk_sites')->nullOnDelete();

            /*
             * الصورةُ تصل مستقلّةً عن النبضة وقد تسبقها أو تتأخّر عنها،
             * فتُربط بها إن وُجدت ولا تُشترط. وصورةٌ بلا نبضةٍ تبقى
             * وتُعرض — فالكاميرا صوّرت من وقف أمامها وإن ضاعت بصمتُه.
             */
            $table->foreignId('punch_id')->nullable()->constrained('zk_punches')->nullOnDelete();

            $table->string('device_pin', 32);
            $table->dateTime('taken_at');
            $table->date('work_date');

            /*
             * النوعُ يُقرأ من بايتات الصورة لا من تصريح الجهاز، ويُقصر على
             * قائمةٍ آمنة قبل العرض. وجهازٌ يرسل ما ليس صورةً لا يُخزَّن
             * أصلاً — فالعرضُ inline لمحتوًى مجهولٍ ثغرةٌ لا خطأُ عرض.
             */
            $table->string('mime', 32);
            $table->unsignedInteger('size');

            /*
             * المحتوى base64 في عمودٍ لا يُنتقى في أيّ قائمة.
             * والقوائمُ تنتقي أعمدتَها صراحةً — وإلا جرّ كلُّ كشفٍ
             * ميجابايتاتٍ لا يعرضها.
             */
            $table->longText('content');

            $table->timestamp('received_at')->useCurrent();

            $table->index(['device_id', 'device_pin', 'taken_at']);
            $table->index(['site_id', 'work_date']);
            $table->index('punch_id');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_punch_photos');
    }
};
