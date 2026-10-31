<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zk_punches')) {
            return;
        }

        Schema::create('zk_punches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')->constrained('zk_devices')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('zk_sites')->nullOnDelete();

            $table->string('device_pin', 32);

            /* الطابعُ UTC. الجهازُ أرسل ساعتَه المحلية، والتحويلُ تمّ عند الاستقبال. */
            $table->dateTime('punched_at');

            /* التاريخُ المحليُّ المحسوبُ بقاعدة بدءِ اليوم — مخزَّنٌ لأنه مفتاحُ التجميع لا نتيجةَ حساب. */
            $table->date('work_date');

            $table->unsignedTinyInteger('state')->default(0);        // 0 دخول 1 خروج 4/5 إضافي
            $table->unsignedTinyInteger('verify_mode')->default(1);  // 1 بصمة 15 وجه 4 بطاقة
            $table->string('work_code', 32)->nullable();

            $table->timestamp('received_at')->useCurrent();

            /*
             * القيدُ الفريد هو حجرُ الزاوية: الجهازُ يُعيد إرسالَ دفعةٍ لم يُقِرَّ
             * الخادمُ باستلامها، وخمسةُ أجهزةٍ في موقعٍ واحدٍ تُعيد معاً بعد انقطاعِ
             * إنترنت. بغيره تتضاعف النبضاتُ وتكذب التقارير.
             */
            $table->unique(['device_id', 'device_pin', 'punched_at'], 'uq_punch');

            $table->index(['site_id', 'work_date']);
            $table->index(['device_pin', 'work_date']);
            $table->index('punched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_punches');
    }
};
