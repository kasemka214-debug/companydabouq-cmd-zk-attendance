<?php

namespace App\Services;

use App\Models\DayNote;
use App\Models\Holiday;
use App\Models\Pin;
use App\Models\Punch;
use App\Models\Site;
use App\Support\AbsenceJudge;
use App\Support\Clock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * الاستثناءاتُ في الدوام: غيابٌ، وتأخّرٌ، وانصرافٌ قبل إتمام الساعات —
 * ومعها قرارُها إن كُتب.
 *
 * وهي أصلُ شاشةٍ واحدةٍ وتقريرٍ واحد. والرصدُ محسوبٌ لا مخزَّن
 * (`DayBuilder` ← `ShiftJudge` للنبضات، و`AbsenceFinder` للغياب)، فيُعاد
 * حسابُه مع كل قراءة: يُصحَّح جدولُ الموقع، أو تُحذف نبضةٌ خاطئة، أو
 * تُسجَّل إجازةٌ متأخّرة — فيصحّ الرصدُ معها. والمخزَّنُ وحدَه هو **الجواب**:
 * أبإذنٍ كان أم بغيره، وما سببُه.
 *
 * **والغيابُ يأتي من `AbsenceFinder` لا من حسابٍ جديدٍ هنا.** فهو الذي
 * يعرف من تركَ العملَ ومن في إجازةٍ ومن نُقل ومن لم يصل من موقعه خبر، وفيه
 * خمسةُ حرّاسٍ تمنع التهمةَ الباطلة. وحسابٌ ثانٍ للغياب كان سيفترق عنه
 * يوماً، فيقول التقريرُ غيرَ ما يقوله كشفُ الأيام.
 */
class ShiftWatch
{
    /** مدّةُ عدّاد القائمة بالأيام — ما رُصد في أسبوعٍ يكفي إنذاراً */
    public const WINDOW = 7;

    private const KEY = 'zk.shift.pending';

    public function __construct(
        private DayBuilder $days,
        private AbsenceFinder $absences,
    ) {}

    /**
     * أيامُ الاستثناء في مدّة، ومعها قرارُها.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function flagged(string $from, string $to, ?int $siteId = null, ?string $pin = null): Collection
    {
        $notes = DayNote::lookup($from, $to, $siteId);

        return $this->fromPunches($from, $to, $siteId, $pin)
            ->concat($this->fromAbsences($from, $to, $siteId, $pin))
            ->map(fn (array $d) => $d + [
                'note' => $notes[DayNote::key($d['site_id'], $d['device_pin'], $d['work_date'])] ?? null,
            ])
            ->sortBy([['work_date', 'desc'], ['employee', 'asc'], ['device_pin', 'asc']])
            ->values();
    }

    /**
     * نوعُ الاستثناء في سطرٍ واحد — مفتاحٌ واحدٌ يقرؤه الترشيحُ والعرض.
     *
     * فيومٌ قد يجمع تأخّراً ونقصاً، ولو رُشّح بمفتاحين لظهر مرّتين في
     * التقرير أو سقط من أحدهما.
     */
    public static function kindOf(array $row): string
    {
        if (($row['kind'] ?? null) === AbsenceJudge::ON_LEAVE) {
            return 'leave';
        }

        if (($row['kind'] ?? null) === AbsenceJudge::ABSENT) {
            return 'absent';
        }

        if (($row['late'] ?? 0) > 0 && ($row['short'] ?? 0) > 0) {
            return 'both';
        }

        return ($row['late'] ?? 0) > 0 ? 'late' : 'short';
    }

    /** وصفُ النوع في الشاشة والورقة. */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'absent' => 'غياب',
            'leave'  => 'إجازةٌ مسجَّلة',
            'late'   => 'تأخّر',
            'short'  => 'انصرافٌ مبكّر',
            'both'   => 'تأخّرٌ وانصرافٌ مبكّر',
            default  => '—',
        };
    }

    /** أيُنتظر في هذا السطر قرار؟ — والإجازةُ المسجَّلةُ جوابٌ بذاتها. */
    public static function awaiting(array $row): bool
    {
        return ($row['note'] ?? null) === null && self::kindOf($row) !== 'leave';
    }

    /**
     * عددُ ما لم يُبتّ فيه في آخر أسبوع — وهو الإشعار.
     *
     * **ويُخزَّن خمسَ دقائق** لأنه يُسأل عنه في كل صفحةٍ تُفتح، وحسابُه يمرّ
     * بنبضات أسبوعٍ كاملٍ وبكشف الغياب معها. والخمسُ دقائقَ لا تُخفي خبراً:
     * من تأخّر صباحاً يُرى قبل أن تُشرب القهوة. ويُمسح المخزونُ عند كل قرارٍ
     * وكل تغييرِ جدولٍ، فلا يبقى العدّادُ يُنادي على ما بُتَّ فيه.
     */
    public static function pending(): int
    {
        return (int) Cache::remember(self::KEY, 300, function () {
            $now = Clock::now();

            return app(self::class)
                ->flagged($now->copy()->subDays(self::WINDOW - 1)->toDateString(), $now->toDateString())
                ->filter(fn (array $r) => self::awaiting($r))
                ->count();
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    /* ── الرصدُ من النبضات: تأخّرٌ ونقصٌ ─────────────────────────────── */

    /** @return Collection<int, array<string, mixed>> */
    private function fromPunches(string $from, string $to, ?int $siteId, ?string $pin): Collection
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

        return $this->days
            ->build($punches, Pin::lookup($siteId), Site::schedules(), $calendar['dates'])
            ->filter(fn (array $d) => $d['flagged'])
            ->map(fn (array $d) => $d + [
                'kind'  => $d['complete'] ? AbsenceJudge::FULL : AbsenceJudge::PARTIAL,
                'leave' => null,
            ])
            ->values();
    }

    /* ── والغيابُ من كشفه: لم يبصم، أو في إجازةٍ مسجَّلة ───────────────── */

    /** @return Collection<int, array<string, mixed>> */
    private function fromAbsences(string $from, string $to, ?int $siteId, ?string $pin): Collection
    {
        $found = $this->absences->find([
            'from'    => $from,
            'to'      => $to,
            'site_id' => (int) $siteId,
            'pin'     => (string) $pin,
            'only'    => '',
        ]);

        /*
         * ومن حالاتِ كشف الأيام الستّ اثنتان تدخلان هذا التقرير: «لم يبصم»
         * سؤالٌ يُسأل عنه، و«في إجازة» جوابُه مكتوبٌ سلفاً فيُعرض ولا يُنتظر
         * فيه قرار. وأمّا «لم يبصم بعد» و«بصم في موقعٍ آخر» و«لا بيانات من
         * الموقع» و«نُقل ولم يبصم بعد» فليست أحكاماً على رجل — هي انتظارٌ أو
         * خبرٌ عن جهاز، ووضعُها في ورقةٍ يُحاسَب بها تهمةٌ بلا دليل.
         */
        return collect($found['rows'])
            ->filter(fn (array $r) => in_array($r['kind'], [AbsenceJudge::ABSENT, AbsenceJudge::ON_LEAVE], true))
            ->map(fn (array $r) => $r + [
                'late'     => 0,
                'short'    => 0,
                'flagged'  => true,
                'judged'   => false,
                'due_out'  => '—',
                'schedule' => null,
            ])
            ->values();
    }
}
