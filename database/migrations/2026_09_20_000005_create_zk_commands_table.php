<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zk_commands')) {
            return;
        }

        Schema::create('zk_commands', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')->constrained('zk_devices')->cascadeOnDelete();

            /*
             * الأمرُ لا يُدفع إلى الجهاز — الجهازُ هو الذي يسأل عنه في getrequest.
             * فالطابورُ هنا، والجهازُ يسحب منه متى اتصل.
             */
            $table->string('kind', 32);        // check · info · reboot · clear_log · set_time
            $table->text('body');              // نصُّ الأمر كما يُسلَّم للجهاز
            $table->string('label');           // وصفُه بالعربية للعرض

            $table->string('status', 16)->default('pending');  // pending · sent · done · failed
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->string('return_code', 16)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_commands');
    }
};
