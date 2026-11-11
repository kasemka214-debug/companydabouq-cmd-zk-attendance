<?php

namespace App\Services;

use App\Models\DayNote;
use App\Models\Holiday;
use App\Models\Pin;
use App\Models\Punch;
use App\Models\Site;
use App\Support\Clock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * ما رُصد من تأخّرٍ أو نقصٍ في الدوام، ومعه قرارُه إن كُتب.
 *
 * والرصدُ محسوبٌ لا مخزَّن (`DayBuilder` ← `ShiftJudge`)، فيُعاد حسابُه مع
 * كل قراءة — وهذا هو المطلوب: يُصحَّح جدولُ الموقع أو تُحذف نبضةٌ خاطئة
 * فيصحّ الرصدُ معها. والمخزَّنُ وحدَه هو **الجواب**: أبإذنٍ كان أم بغيره.
 */
class ShiftWatch
{
    /** مدّةُ عدّاد القائمة بالأيام — ما رُصد في أسبوعٍ يكفي إنذاراً */
    public const WINDOW = 7;

    private const KEY = 'zk.shift.pending';

    public function __construct(private DayBuilder $days) {}

    /**
     * أيامٌ رُصد فيها تأخّرٌ أو نقص، مرتَّبةً كما يرتّبها كشفُ الأيام.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function flagged(string $from, string $to, ?int $siteId = null, ?string $pin = null): Collection
    {
        $punches = Punch::query()
            ->between($from, $to)
            ->when($siteId, fn ($b) => $b->where('site_id', $siteId))
            ->when($pin !== null && $pin !== '', fn ($b) => $b->where('device_pin', $pin))
            ->orderBy('punched_at')
            ->get();

        if ($punches->isEmpty()) {
            return collect();
        }

        $calendar = Holiday::calendar($from, $to);
        $notes    = DayNote::lookup($from, $to, $siteId);

        return $this->days
            ->build($punches, Pin::lookup($siteId), Site::schedules(), $calendar['dates'])
            ->filter(fn (array $d) => $d['flagged'])
            ->map(fn (array $d) => $d + [
                'note' => $notes[DayNote::key($d['site_id'], $d['device_pin'], $d['work_date'])] ?? null,
            ])
            ->values();
    }

    /**
     * عددُ ما لم يُبتّ فيه في آخر أسبوع — وهو الإشعار.
     *
     * **ويُخزَّن خمسَ دقائق** لأنه يُسأل عنه في كل صفحةٍ تُفتح، وحسابُه
     * يمرّ بنبضات أسبوعٍ كاملٍ لكل المواقع. والخمسُ دقائقَ لا تُخفي خبراً:
     * من تأخّر صباحاً يُرى قبل أن يُشرب القهوة. ويُمسح المخزونُ عند كل
     * قرارٍ يُكتب فلا يبقى العدّادُ يُنادي على ما بُتَّ فيه.
     */
    public static function pending(): int
    {
        return (int) Cache::remember(self::KEY, 300, function () {
            $now = Clock::now();

            return app(self::class)
                ->flagged($now->copy()->subDays(self::WINDOW - 1)->toDateString(), $now->toDateString())
                ->whereNull('note')
                ->count();
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
