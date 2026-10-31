<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zk_holidays')) {
            return;
        }

        Schema::create('zk_holidays', function (Blueprint $table) {
            $table->id();

            /*
             * العطلةُ يومٌ بعينه يختاره المسؤول — عيدٌ أو مناسبةٌ أو إغلاق.
             *
             * أمّا الجمعةُ فلا تُخزَّن: تُحسب من التاريخ نفسِه. وتخزينُ
             * اثنتين وخمسين جمعةً في السنة تخزينٌ لما يُحسب، ويُنسى منه
             * واحدةٌ فيختلّ شهرٌ بلا سببٍ ظاهر.
             */
            $table->date('date')->unique();
            $table->string('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_holidays');
    }
};
