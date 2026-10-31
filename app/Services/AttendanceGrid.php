<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\Pin;
use App\Models\Punch;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * جدولُ الحضور: صفٌّ لكل موظفٍ وعمودٌ لكل يوم.
 *
 * وهو أصلُ التقريرين معاً — الأسبوعيِّ والشهريِّ — فلا يفترقان يوماً في
 * حسابِ ساعةٍ ثم يُسأل: أيُّهما الصحيح؟ الفرقُ بينهما مدّةٌ لا منطق.
 *
 * ويُبنى على `DayBuilder` نفسِه الذي يبني كشفَ الأيام، فالساعةُ المعروضة
 * في الكشف هي الساعةُ المجموعة في التقرير — لا حسابان لشيءٍ واحد.
 *
 * **والساعاتُ من أوّل بصمةٍ إلى آخرها**، لا مجموعَ ما بين كل دخولٍ وخروج.
 * والفرقُ بينهما الاستراحة. واختير الأوّل لأن البصمات تتكرّر في الواقع:
 * يبصم العاملُ مرّتين وثلاثاً في الدقيقة الواحدة، فمزاوجةُ «دخول/خروج»
 * تُخرج أرقاماً عجيبة — عشرُ بصماتٍ في ساعةٍ ونصف تصير دقائقَ معدودة.
 * ومن أراد خصمَ الاستراحة فليُنقص من المطلوب، أو يُطلب فيُبنى بمزاوجةٍ
 * تتجاهل ما تقارب.
 */
class AttendanceGrid
{
    public function __construct(private DayBuilder $days) {}

    /**
     * @return array{
     *     calendar: array{dates: array<string, string>, working: int, weekend: int, holiday: int},
     *     target: int,
     *     groups: array<int, array<string, mixed>>
     * }
     */
    public function build(
        string $from,
        string $to,
        ?int $siteId = null,
        bool $onlyActive = true,
        ?string $pin = null,
    ): array {
        $calendar = Holiday::calendar($from, $to);

        /* المطلوبُ بالدقائق — لا بالساعات — فالمقارنةُ كلُّها بالدقائق */
        $target = (int) round($calendar['working'] * (float) config('zk.daily_hours', 8) * 60);

        $punches = Punch::query()
            ->between($from, $to)
            ->when($siteId, fn ($b) => $b->where('site_id', $siteId))
            ->when($pin !== null && $pin !== '', fn ($b) => $b->where('device_pin', $pin))
            ->orderBy('punched_at')
            ->get();

        $names = Pin::lookup($siteId);
        $rows  = $this->days->build($punches, $names);

        return [
            'calendar' => $calendar,
            'target'   => $target,
            'groups'   => $this->pivot($rows, $siteId, $onlyActive, $pin, $from),
        ];
    }

    /**
     * قلبُ الصفوف إلى جدول: موظفٌ في السطر، ويومٌ في العمود.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function pivot(
        Collection $rows,
        ?int $siteId,
        bool $onlyActive,
        ?string $pin = null,
        string $from = '0000-01-01',
    ): array
    {
        $sites = Site::query()
            ->when($siteId, fn ($q) => $q->where('id', $siteId))
            ->orderBy('name')
            ->get();

        /*
         * الموظفون من جدول الربط لا من البصمات.
         *
         * فمن لم يبصم في الشهر كلِّه له سطرٌ فارغ — وغيابُ شهرٍ كاملٍ خبرٌ
         * أهمُّ من حضوره. ولو بُني الجدولُ من البصمات وحدَها لاختفى الغائبُ
         * من الكشف، فلا يُسأل عنه أحد.
         */
        $out = [];

        foreach ($sites as $site) {
            /*
             * **الأرقامُ كلُّها، مفعّلةً كانت أو لا.**
             *
             * فالتفعيلُ شأنُه أن يُظهر موظفاً بلا بصماتٍ في الكشف أو
             * يُخفيه — لا أن يمحو اسمَ من بصم. ولمّا كان يُرشَّح هنا ظهرت
             * مواقعُ بأسرها «غير مربوطة» وأسماؤها في الجدول كما هي، ولا
             * شيءَ في الشاشة يدلّ على السبب: الرقمُ مربوطٌ ومعطَّل.
             *
             * والاسمُ يُحلّ أوّلاً، ثم يُقرَّر بعدُ أيظهر السطرُ أم لا.
             */
            $all = Pin::query()
                ->where('site_id', $site->id)
                ->when($pin !== null && $pin !== '', fn ($q) => $q->where('device_pin', $pin))

                /*
                 * **ومن تركَ العملَ قبل بداية المدّة لا يُعرض.**
                 *
                 * فلا شأنَ لمن تركَ في يونيو بكشف أكتوبر، وبقاؤه فيه صفّاً
                 * فارغاً يُطيل الورقةَ بأسماءٍ ليست من الشركة، ويُظهر لكلٍّ
                 * منهم «−٢٠٨:٠٠» في عمود الفرق — رقمٌ يُقرأ على أنه دَينٌ
                 * على الشركة وهو لا شيء.
                 *
                 * **ومن تركَ في وسط المدّة يبقى**: أيامُه الأولى عملٌ صُرف
                 * عليه أجرُه، وإسقاطُه يمحو ما يُحاسَب عليه.
                 */
                ->servingOn($from)

                ->orderByRaw('CAST(device_pin AS UNSIGNED), device_pin')
                ->get(['device_pin', 'employee_name', 'employee_ref', 'job_title', 'is_active']);

            $siteRows = $rows->where('site_id', $site->id);

            /* مَن له بصمةٌ في المدّة — يظهر ولو كان معطَّلاً */
            $punched = $siteRows->pluck('device_pin')->unique()->all();

            $people = [];

            foreach ($all as $row) {
                if ($onlyActive && ! $row->is_active && ! in_array($row->device_pin, $punched, true)) {
                    continue;
                }

                $people[$row->device_pin] = [
                    'pin'   => $row->device_pin,
                    'name'  => $row->employee_name,
                    'ref'   => $row->employee_ref,
                    'job'   => $row->job_title,
                    'days'  => [],
                    'total' => 0,
                ];
            }

            foreach ($siteRows as $row) {
                $key = $row['device_pin'];

                /* رقمٌ بصم ولم يُربط باسم — يظهر ولا يُطوى */
                $people[$key] ??= [
                    'pin'   => $key,
                    'name'  => null,
                    'ref'   => null,
                    'job'   => null,
                    'days'  => [],
                    'total' => 0,
                ];

                $minutes = (int) ($row['minutes'] ?? 0);

                $people[$key]['days'][$row['work_date']] = [
                    'minutes'  => $row['minutes'],
                    'first'    => $row['first_time'],
                    'last'     => $row['last_time'],
                    'punches'  => $row['punches'],
                    'complete' => $row['complete'],
                ];

                $people[$key]['total'] += $minutes;
            }

            $people = array_values($people);

            if ($people !== []) {
                $out[] = [
                    'site_id' => $site->id,
                    'name'    => $site->name,
                    'people'  => $people,
                ];
            }
        }

        return $out;
    }

    /** دقائقُ إلى «٨:١١»، وشرطةٌ للصفر. */
    public static function hhmm(?int $minutes, string $zero = '—'): string
    {
        if ($minutes === null || $minutes === 0) {
            return $zero;
        }

        $sign = $minutes < 0 ? '−' : '';
        $abs  = abs($minutes);

        return $sign.intdiv($abs, 60).':'.str_pad((string) ($abs % 60), 2, '0', STR_PAD_LEFT);
    }
}
