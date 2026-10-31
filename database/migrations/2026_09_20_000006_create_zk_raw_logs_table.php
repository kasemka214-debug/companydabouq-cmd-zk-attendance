<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zk_raw_logs')) {
            return;
        }

        Schema::create('zk_raw_logs', function (Blueprint $table) {
            $table->id();

            /*
             * أرشيفُ كلِّ ما وصل — بما فيه ما رُفض. حين يقول الموقعُ «بصمتُ ولم يظهر»
             * فالجوابُ هنا لا في التخمين: أوصلت النبضةُ أصلاً؟ ومن أيّ عنوان؟
             * وما الذي رددناه عليها؟
             */
            $table->string('serial_number', 64)->nullable()->index();
            $table->foreignId('device_id')->nullable()->constrained('zk_devices')->nullOnDelete();

            $table->string('ip', 45)->nullable();
            $table->string('method', 8);
            $table->string('path');
            $table->text('query')->nullable();
            $table->longText('body')->nullable();
            $table->text('response')->nullable();

            $table->boolean('accepted')->default(false);
            $table->string('reason', 64)->nullable();   // unknown_serial · inactive · ip_blocked · rate_limited · ok
            $table->unsignedInteger('rows')->default(0);

            $table->timestamp('created_at')->useCurrent();

            $table->index(['accepted', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_raw_logs');
    }
};
