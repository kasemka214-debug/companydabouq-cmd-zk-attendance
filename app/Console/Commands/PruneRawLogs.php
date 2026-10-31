<?php

namespace App\Console\Commands;

use App\Models\PunchPhoto;
use App\Models\RawLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * كنسُ أرشيف الاتصالات الخام.
 *
 * الأرشيفُ ينمو بسرعةِ الأجهزة نفسِها: عشرون جهازاً تستعلم كلَّ نصف دقيقة
 * تكتب مليوناً ونصفاً من الصفوف في الشهر — أكثرُها «OK» على استعلامٍ فارغ.
 * وفائدتُه التشخيصيةُ لا تتجاوز أسابيعَ قليلة، فيُقصّ إلى مدّةِ الاحتفاظ.
 *
 * والحذفُ على دفعات: `delete` واحدٌ على مليونِ صفٍّ يقفل الجدولَ دقائقَ
 * فتُردّ نبضاتُ الأجهزة أثناءها.
 */
class PruneRawLogs extends Command
{
    protected $signature = 'zk:prune-raw {--days= : أيامُ الاحتفاظ، وإلا فمن الإعدادات}';

    protected $description = 'حذفُ سجلّات الاتصال الخام الأقدم من مدّة الاحتفاظ';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('zk.raw_retention_days', 30));

        if ($days < 1) {
            $this->error('مدّةُ الاحتفاظ يجب أن تكون يوماً فأكثر.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now('UTC')->subDays($days);
        $total  = 0;

        do {
            $deleted = RawLog::where('created_at', '<', $cutoff)->limit(5000)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        $this->info("حُذف {$total} سجلّاً أقدمَ من {$days} يوماً.");

        $this->prunePhotos();

        return self::SUCCESS;
    }

    /**
     * كنسُ صور البصمات القديمة.
     *
     * والصورةُ أثقلُ ما في القاعدة بمراحل: عشرون جهازاً بخمسين بصمةً يومياً
     * تكتب جيجابايتاً في الشهر، والحضورُ نفسُه لا يشغل منها واحداً في المئة.
     *
     * **والحذفُ لا يمسّ البصمة.** تبقى النبضةُ وسجلُّها ووقتُها، وتذهب
     * الصورةُ وحدَها — فالكشفُ لا ينقص، وإنما يفقد شاهدَه البصري.
     *
     * وصفرٌ يعني بلا حذف. وهو الافتراض: أن يُحذف ما لم يُطلب حذفُه أسوأُ
     * من أن تمتلئ قاعدةٌ يُرى امتلاؤها.
     */
    private function prunePhotos(): void
    {
        $days = (int) config('zk.photo_retention_days', 0);

        if ($days < 1) {
            $this->line('الصور: بلا حذف (ZK_PHOTO_RETENTION_DAYS غيرُ مضبوط).');

            return;
        }

        $cutoff = Carbon::now('UTC')->subDays($days);
        $total  = 0;

        do {
            $deleted = PunchPhoto::where('taken_at', '<', $cutoff)->limit(500)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        $this->info("حُذفت {$total} صورةً أقدمَ من {$days} يوماً. والبصماتُ باقية.");
    }
}
