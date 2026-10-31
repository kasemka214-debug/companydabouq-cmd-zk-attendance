<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * توريثُ صلاحية «عرض تقرير ساعات الأجهزة».
 *
 * **ولا يستيقظ أحدٌ صباحاً وقد أُغلقت في وجهه شاشة** — ولا يُلام على شاشةٍ
 * لم يعلم بها. فالمفتاحُ جديدٌ لم يكن في أحدٍ، ولو تُرك لَما رأى التقريرَ
 * إلا المديرُ، ولَما نفعت حراستُه أصلاً: من يراجع الكشوفَ هو الذي يحتاج
 * أن يعرف أن ساعةَ الجهاز كانت مضبوطةً أم لا.
 *
 * فيُمنح لمن كان يملك `reports.view` — إذ التقريرُ جوابٌ على سؤالٍ يُثار في
 * كشفٍ يقرأه هو.
 *
 * **و`clock.ack` لا يُورَّث:** الإقرارُ يُسكت الإشعارَ عن الجميع، فهو
 * مسؤوليةٌ تُمنح بقصدٍ لا بأثرٍ رجعيّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'permissions')) {
            return;
        }

        /*
         * والتعديلُ في PHP لا في SQL: العمودُ `json`، وتحريرُ مصفوفةٍ فيه
         * بلهجة MySQL يختلف بين إصداراتها — ودالّةٌ غيرُ موجودةٍ في إصدارٍ
         * تُفشل الترحيلَ على الإنتاج وحدَه. والمستخدمون عشراتٌ لا ملايين،
         * فثمنُ الدورةِ لا شيء.
         */
        foreach (DB::table('users')->select('id', 'permissions')->get() as $user) {
            $keys = json_decode((string) $user->permissions, true);

            if (! is_array($keys)) {
                continue;
            }

            if (! in_array('reports.view', $keys, true) || in_array('clock.view', $keys, true)) {
                continue;
            }

            $keys[] = 'clock.view';

            DB::table('users')->where('id', $user->id)->update([
                'permissions' => json_encode(array_values($keys), JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'permissions')) {
            return;
        }

        foreach (DB::table('users')->select('id', 'permissions')->get() as $user) {
            $keys = json_decode((string) $user->permissions, true);

            if (! is_array($keys) || ! in_array('clock.view', $keys, true)) {
                continue;
            }

            DB::table('users')->where('id', $user->id)->update([
                'permissions' => json_encode(
                    array_values(array_diff($keys, ['clock.view'])),
                    JSON_UNESCAPED_UNICODE,
                ),
            ]);
        }
    }
};
