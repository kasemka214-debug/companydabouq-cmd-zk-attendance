<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zk_pins')) {
            return;
        }

        Schema::create('zk_pins', function (Blueprint $table) {
            $table->id();

            /*
             * الجهازُ لا يعرف إلا رقماً. والرقمُ يُعاد استعمالُه في كل موقع —
             * «١» في موقعٍ غيرُ «١» في آخر. فالفرادةُ بالموقع لا بالرقم وحده.
             */
            $table->foreignId('site_id')->constrained('zk_sites')->cascadeOnDelete();
            $table->string('device_pin', 32);

            $table->string('employee_name');                 // الاسمُ كما يُعرض في التقارير
            $table->string('employee_ref', 32)->nullable();  // رقمُه في برنامج دابوق — للمطابقة اليدوية
            $table->string('job_title')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'device_pin']);
            $table->index('employee_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_pins');
    }
};
