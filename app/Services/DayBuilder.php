<?php

namespace App\Services;

use App\Models\Pin;
use App\Models\Punch;
use App\Support\Clock;
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
 * ولا يُخزَّن من هذا شيء. لأن الحسابَ المخزَّن يشيخ: تُصحَّح نبضةٌ أو
 * يُربط رقمٌ باسمٍ بعد شهر، فيبقى المخزَّنُ على كذبه ولا أحدَ يعيد حسابه.
 */
class DayBuilder
{
    /**
     * أيامُ العمل المستخلَصة من مجموعةِ نبضاتٍ مرتَّبةٍ زمنياً.
     *
     * @param  Collection<int, Punch>  $punches
     * @return Collection<int, array<string, mixed>>
     */
    public function build(Collection $punches, ?array $names = null): Collection
    {
        $names ??= Pin::lookup();

        return $punches
            ->groupBy(fn (Punch $p) => $p->site_id.'|'.$p->device_pin.'|'.$p->work_date->toDateString())
            ->map(function (Collection $group) use ($names) {
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

                return [
                    'site_id'     => $first->site_id,
                    'device_pin'  => $first->device_pin,
                    'employee'    => $name->employee_name ?? null,
                    'employee_ref' => $name->employee_ref ?? null,
                    'job_title'   => $name->job_title ?? null,
                    'work_date'   => $first->work_date->toDateString(),
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
