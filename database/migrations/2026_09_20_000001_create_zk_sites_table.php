<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zk_sites')) {
            return;
        }

        Schema::create('zk_sites', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // اسم الموقع
            $table->string('code', 32)->nullable();       // رمزٌ مختصر للتقارير
            $table->string('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_sites');
    }
};
