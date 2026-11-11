<?php

namespace App\Services;

use App\Models\Pin;
use App\Models\Punch;
use App\Models\Site;
use App\Support\Clock;
use App\Support\ShiftJudge;
use Illuminate\Support\Collection;

/**
 * تحويلُ النبضات إلى أيام — حساباً عند العرض لا تخزيناً.
 *
 * الجهازُ يعطي لحظات، والناسُ يقرؤون أياماً. وبين الاثنين قاعدتان:
 *
 *   الأولى — أن أوّلَ نبضةٍ في يوم العمل دخولٌ وآخرَها خروج، أيّاً كان
 *   «النوعُ» الذي وسمها به الجهاز. فالنوعُ يعتمد على زرٍّ يضغطه العاملُ
 *   قبل أن يضع إصبعه، وأكثرُهم لا يضغطه — فتأتي بصماتُ اليوم كلُّها
 *   موسومةً «دخول». والاعتمادُ على ترتيب الزمن أصدقُ من الاعتماد عليه.
 *
 *   والثانية — أن خمسةَ أجهزةٍ في الموقع الواحد جهازٌ واحدٌ في التقرير:
 *   مَن دخل من بوابةٍ وخرج من أخرى يومُه تامٌّ لا يومان ناقصان. فالتجميعُ
 *   بالموقع والرقم، لا بالجهاز.
 *
 * ومعها حكمُ الدوام: أتأخّرَ عن بداية موقعه، وأنقصَ من ساعاته؟ وهو هنا
 * لا في الشاشة، ليقرأ كشفُ الأيام والتقريرُ الشهريُّ وشاشةُ التأخّر رقماً
 * واحداً — لا ثلاثةَ حساباتٍ تفترق يوماً فيُسأل: أيُّها الصحيح؟
 *
 * ولا يُخزَّن من هذا شيء. لأن الحسابَ المخزَّن يشيخ: تُصحَّح نبضةٌ أو
 * يُربط رقمٌ باسمٍ بعد شهر، أو يُغيَّر جدولُ الموقع، فيبقى المخزَّنُ على
 * كذبه ولا أحدَ يعيد حسابه.
 */
class DayBuilder
{
    /**
     * أيامُ العمل المستخلَصة من مجموعةِ نبضاتٍ مرتَّبةٍ زمنياً.
     *
     * @param  Collection<int, Punch>  $punches
     * @param  array<int, array{start: string, minutes: int, grace: int}>|null  $schedules
     * @param  array<string, string>|null  $calendar  يومٌ => working|weekend|holiday
     * @return Collection<int, array<string, mixed>>
     */
    public function build(
        Collection $punches,
        ?array $names = null,
        ?array $schedules = null,
        ?array $calendar = null,
    ): Collection
    {
        $names ??= Pin::lookup();

        /* جداولُ المواقع كلِّها باستعلامٍ واحد — لا استعلامَ لكل صفّ */
        $schedules ??= Site::schedules();

        return $punches
            ->groupBy(fn (Punch $p) => $p->site_id.'|'.$p->device_pin.'|'.$p->work_date->toDateString())
            ->map(function (Collection $group) use ($names, $schedules, $calendar) {
                /** @var Collection<int, Punch> $sorted */
                $sorted = $group->sortBy(fn (Punch $p) => $p->punched_at->getTimestamp())->values();

                $first = $sorted->first();
                $last  = $sorted->last();

                $key  = $first->site_id.'|'.$first->device_pin;
                $name = $names[$key] ?? null;

                /*
                 * نبضةٌ واحدة في اليوم ليست «دوامَ صفر» — هي بصمةٌ ناقصة:
                 * دخلَ ولم يبصم خروجاً، أو بصم خروجاً بلا دخول. وتمييزُها
                 * واجبٌ لأن حسابَها صفراً يُظهر حاضراً كأنه لم يعمل.
                 */
                $complete = $sorted->count() > 1;

                $date = $first->work_date->toDateString();

                /*
                 * **ولا موقعَ ⇐ لا جدولَ ⇐ لا حكم.** نبضةٌ من جهازٍ لم
                 * يُسنَد إلى موقعٍ لا يُعرف متى يبدأ دوامُها، فلا يُقال
                 * لصاحبها «تأخّرتَ» بحدسٍ عامّ.
                 */
                $schedule = $first->site_id ? ($schedules[$first->site_id] ?? null) : null;

                /*
                 * **ولا حكمَ في يومٍ ليس يومَ دوام.** من بصم في جمعةٍ أو
                 * عطلةٍ فعملُه زيادةٌ لا نقص، ورصدُه «متأخّراً» لأنه جاء
                 * التاسعةَ في يومٍ لا دوامَ فيه قلبٌ للحقيقة.
                 */
                $working = ($calendar[$date] ?? 'working') === 'working';

                $judge = $schedule && $working
                    ? ShiftJudge::judge($date, $first->punched_at, $complete ? $last->punched_at : null, $schedule)
                    : null;

                return [
                    'site_id'     => $first->site_id,
                    'device_pin'  => $first->device_pin,
                    'employee'    => $name->employee_name ?? null,
                    'employee_ref' => $name->employee_ref ?? null,
                    'job_title'   => $name->job_title ?? null,
                    'work_date'   => $date,
                    'first_at'    => $first->punched_at,
                    'last_at'     => $complete ? $last->punched_at : null,
                    'first_time'  => Clock::stamp($first->punched_at, 'H:i'),
                    'last_time'   => $complete ? Clock::stamp($last->punched_at, 'H:i') : '—',
                    'punches'     => $sorted->count(),
                    'complete'    => $complete,
                    'minutes'     => $complete
                        ? $first->punched_at->diffInMinutes($last->punched_at)
                        : null,
                    'devices'     => $sorted->pluck('device_id')->unique()->values()->all(),

                    /* حكمُ الدوام — أصفارٌ لمن لا جدولَ له، أو لم يتمّ يومُه، أو كان يومَ عطلة */
                    'schedule'    => $schedule,
                    'off_day'     => ! $working,
                    'judged'      => (bool) ($judge['judged'] ?? false),
                    'late'        => (int) ($judge['late'] ?? 0),
                    'short'       => (int) ($judge['short'] ?? 0),
                    'flagged'     => (bool) (($judge['late'] ?? 0) > 0 || ($judge['short'] ?? 0) > 0),
                    'due_out'     => isset($judge['due_out']) && $judge['due_out']
                        ? $judge['due_out']->format('H:i')
                        : '—',
                ];
            })
            ->sortBy([['work_date', 'desc'], ['employee', 'asc'], ['device_pin', 'asc']])
            ->values();
    }

    /** المدّةُ بصيغة «٨:٣٠» أو شرطةٌ إن كان اليومُ ناقصاً. */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        return intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
