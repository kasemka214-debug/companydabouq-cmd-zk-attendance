<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Models\Pin;
use App\Models\Punch;
use App\Models\PunchPhoto;
use App\Models\Site;
use App\Services\AbsenceFinder;
use App\Services\DayBuilder;
use App\Support\AbsenceJudge;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * كشفُ الأيام — الوجهُ الذي يقرؤه الناس.
 *
 * ولا يُخزَّن منه شيء: يُحسب من النبضات عند كل فتحة. فلو خُزّن لشاخ عند
 * أوّل تصحيح — رقمٌ يُربط باسمٍ بعد أسبوع، أو نبضةُ تجربةٍ تُحذف — ولبقي
 * الكشفُ القديم يُعرض وهو كاذب.
 */
class DayController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:days.view',  only: ['index', 'export']),
            new Middleware('can:page.print', only: ['export']),
        ];
    }

    public function index(Request $request)
    {
        $f = $this->filters($request);

        $r     = $this->report($f);
        $days  = $r['days'];
        $sites = Site::orderBy('name')->get();

        return view('days.index', [
            'days'     => $days,
            'groups'   => $this->groupBySite($days, $sites, $r['dormant'], $r['ended']),
            'photos'   => $this->photoIndex($f),
            'sites'    => $sites,
            'f'        => $f,
            'calendar' => $r['calendar'],
            'notice'   => $r['notice'],
            'tally'    => $this->tally($days),

            /*
             * عددُ الأشخاص بـ«موقع|رقم» لا بالرقم وحدَه.
             *
             * فالرقمُ يُفرَّق بموقعه: «١٤» في الأحمدي غيرُ «١٤» في الجهراء،
             * وعدُّهما واحداً يُنقص الرأسَ عن الجدول تحته.
             */
            'people'   => $days->map(fn (array $d) => $d['site_id'].'|'.$d['device_pin'])
                ->unique()->count(),
        ]);
    }

    public function export(Request $request)
    {
        $f = $this->filters($request);

        $r     = $this->report($f);
        $days  = $r['days'];
        $sites = Site::orderBy('name')->get();

        /*
         * التصديرُ يتبع الشاشة: مقسومٌ على المواقع وبالترتيب نفسِه، وعمودُ
         * الموقع أوّلُ الأعمدة. فمن صدّر كشفاً ثم فتحه في إكسل يجده كما
         * رآه — ولو خرج مبعثراً لأعاد ترتيبَه بيده كلَّ مرّة.
         */
        $rows = [];

        foreach ($this->groupBySite($days, $sites, $r['dormant'], $r['ended']) as $group) {
            foreach ($group['rows'] as $d) {
                $synthetic = AbsenceJudge::synthetic($d['kind']);

                $rows[] = [
                    $group['name'],
                    $d['work_date'],
                    $d['device_pin'],
                    $d['employee'] ?? '— غير مربوط —',
                    $d['employee_ref'] ?? '',
                    $d['job_title'] ?? '',
                    $d['first_time'],
                    $d['last_time'],
                    DayBuilder::duration($d['minutes']),

                    /* صفرُ بصماتٍ شرطةٌ لا صفر — فالصفرُ يُقرأ قياساً */
                    $synthetic ? '—' : $d['punches'],

                    /* الحالةُ بلسانِ الشاشة نفسِه — لا «ناقص» لمن لم يبصم */
                    AbsenceJudge::label($d['kind']).($d['at_site'] ? ': '.$d['at_site'] : ''),
                ];
            }
        }

        /*
         * وإن حُجب حسابُ الغياب فالملفُّ يقول ذلك في سطرٍ أخير.
         *
         * فالورقةُ والملفُّ هما ما يُحفظ ويُقرَّر منه الخصمُ بعد شهر، وقارئٌ
         * لا يجد فيهما سطرَ «لم يبصم» يظنّ أن أحداً لم يغب — وإنما لم
         * يُحسب. وسكوتٌ يُقرأ براءةً أسوأُ من تنبيهٍ يُقرأ.
         */
        if ($r['notice']) {
            $rows[] = ['— ملاحظة —', $r['notice'], '', '', '', '', '', '', '', '', ''];
        }

        return $this->csv(
            'كشف-الأيام-'.$f['from'].'-إلى-'.$f['to'].'.csv',
            ['الموقع', 'اليوم', 'رقم الجهاز', 'الموظف', 'رقمه في دابوق', 'المسمّى', 'أول بصمة', 'آخر بصمة', 'المدة', 'عدد البصمات', 'الحالة'],
            $rows,
        );
    }

    /**
     * أيُّ (موقعٍ ورقمٍ ويوم) له صورة — وأوّلُ صورةٍ فيه.
     *
     * استعلامٌ واحدٌ مجمَّعٌ لا استعلامٌ لكل سطر: كشفُ شهرٍ آلافُ الأسطر،
     * وسؤالُ القاعدة عن صورة كلٍّ منها يقتل الصفحة. والمحتوى لا يُنتقى
     * هنا أصلاً — إنما العددُ ومُعرِّفُ أوّلِ صورة.
     *
     * @return array<string, array{total: int, first: int}>
     */
    private function photoIndex(array $f): array
    {
        if (! Gate::allows('photos.view')) {
            return [];
        }

        return PunchPhoto::query()
            ->selectRaw('site_id, device_pin, work_date, COUNT(*) AS total, MIN(id) AS first_id')
            ->whereBetween('work_date', [$f['from'], $f['to']])
            ->when($f['site_id'], fn ($b) => $b->where('site_id', $f['site_id']))
            ->when($f['pin'] !== '', fn ($b) => $b->where('device_pin', $f['pin']))
            ->groupBy('site_id', 'device_pin', 'work_date')
            ->get()
            ->mapWithKeys(fn ($r) => [
                $r->site_id.'|'.$r->device_pin.'|'.Carbon::parse($r->work_date)->toDateString()
                    => ['total' => (int) $r->total, 'first' => (int) $r->first_id],
            ])
            ->all();
    }

    /**
     * قسمةُ الكشف على المواقع — كلُّ موقعٍ قسمٌ قائمٌ بذاته.
     *
     * والترتيبُ باسم الموقع لا بمُعرّفه: المُعرّفُ ترتيبُ إدخالٍ لا يعرفه
     * أحد، والاسمُ هو ما يبحث عنه القارئُ في الورقة.
     *
     * ويُختم بقسم «بلا موقع» إن وُجد — وهو جهازٌ لم يُنسب بعد. ولا يُطوى
     * صامتاً: أن تختفي بصماتٌ من الكشف لأن جهازها بلا موقعٍ خطأٌ لا يُكتشف
     * إلا بعد شهر.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $days
     * @param  \Illuminate\Support\Collection<int, Site>  $sites
     * @return array<int, array<string, mixed>>
     */
    private function groupBySite($days, $sites, array $dormant = [], array $ended = []): array
    {
        $groups = [];
        $taken  = 0;

        foreach ($sites as $site) {
            $rows = $days->where('site_id', $site->id)->values();

            if ($rows->isNotEmpty()) {
                $groups[] = $this->summarise(
                    $site->id, $site->name, $rows,
                    $dormant[$site->id] ?? 0,
                    $ended[$site->id] ?? 0,
                );
                $taken += $rows->count();
            }
        }

        /*
         * ما لم يقع في قسم.
         *
         * ويشمل جهازاً بلا موقع، **وصفّاً موقعُه محذوف** — وهذا هو الخطر:
         * لو أُخذ الباقي بـ`whereNull` وحده لاختفى من الكشف بصمت كلُّ ما
         * يشير إلى موقعٍ لم يعد في القائمة. وحضورٌ يختفي من كشفٍ بلا أثرٍ
         * أسوأُ من حضورٍ يظهر في غير بابه: الأوّلُ لا يُكتشف إلا حين يُسأل
         * عاملٌ عن غيابٍ لم يغِبه، والثاني يُرى فيُصحَّح.
         *
         * فالباقي يُحسب طرحاً لا شرطاً — فلا يسقط شيءٌ مهما كان سببُه.
         */
        if ($taken < $days->count()) {
            $known = $sites->pluck('id')->all();

            $loose = $days->reject(
                fn (array $d) => $d['site_id'] !== null && in_array($d['site_id'], $known, true)
            )->values();

            $groups[] = $this->summarise(null, 'بلا موقع', $loose, 0, 0);
        }

        return $groups;
    }

    /**
     * رأسُ القسم.
     *
     * و`site_id` يُحمل معه لأن الرأسَ صار يعرض ما يخصّ الموقعَ بعينه —
     * عددَ أرقامه الراكدة — ويصل إلى شاشة الربط بموقعه. وقسمُ «بلا موقع»
     * لا موقعَ له، فيُمرَّر `null` ويُحرَس في القالب.
     *
     * @return array<string, mixed>
     */
    private function summarise(?int $siteId, string $name, $rows, int $dormant, int $ended): array
    {
        return [
            'site_id' => $siteId,
            'name'    => $name,
            'rows'    => $rows,
            'dormant' => $dormant,
            'ended'   => $ended,

            /* الموقعُ هنا واحدٌ، فالرقمُ وحدَه يُفرّق */
            'people'  => $rows->pluck('device_pin')->unique()->count(),
        ] + $this->tally($rows);
    }

    /**
     * عدُّ الحالات — **بـ`kind` لا بـ`complete`**.
     *
     * فصفُّ من لم يبصم `complete = false` بالضرورة، إذ لا نبضةَ تحته. فلو
     * عُدَّ بها لدخل الغائبُ في عدّاد «ناقص»، ولعُرض وسمُه «بصمةٌ واحدة» —
     * وهي كذبةٌ على رجلٍ لم يبصم أصلاً، في ورقةٍ يُخصَم منها.
     *
     * @return array<string, int>
     */
    private function tally($rows): array
    {
        $count = fn (string $kind): int => $rows->where('kind', $kind)->count();

        return [
            'complete'  => $count(AbsenceJudge::FULL),
            'partial'   => $count(AbsenceJudge::PARTIAL),
            'absent'    => $count(AbsenceJudge::ABSENT),
            'pending'   => $count(AbsenceJudge::PENDING),
            'elsewhere' => $count(AbsenceJudge::ELSEWHERE),
            'nodata'    => $count(AbsenceJudge::NODATA),
            'on_leave'  => $count(AbsenceJudge::ON_LEAVE),
            'awaiting'  => $count(AbsenceJudge::AWAITING),
            'unlinked'  => $rows->whereNull('employee')->count(),
        ];
    }

    /**
     * الكشفُ كلُّه: من بصم ومن لم يبصم.
     *
     * وتُنادى من `index()` و`export()` معاً — فلا يفترق الكشفُ عن ملفّه.
     *
     * @return array{
     *   days: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *   calendar: array<string, mixed>,
     *   dormant: array<int, int>,
     *   notice: string|null
     * }
     */
    private function report(array $f): array
    {
        $punches = Punch::query()
            ->between($f['from'], $f['to'])
            ->when($f['site_id'], fn ($b) => $b->where('site_id', $f['site_id']))
            ->when($f['pin'] !== '', fn ($b) => $b->where('device_pin', $f['pin']))
            ->orderBy('punched_at')
            ->get();

        /*
         * `DayBuilder` لا يُمَسّ ولا يُحقَن فيه صفّ.
         *
         * فهو مشتركٌ مع `AttendanceGrid` — أصلِ التقريرين الأسبوعيّ
         * والشهريّ وتقريرِ الموظف. و`pivot()` يقرأ من صفوفه مَن بصم
         * ليقرّر إظهارَ رقمٍ معطَّل، فحقنُ صفٍّ هناك يُعيد كلَّ من ترك
         * العمل إلى التقارير الثلاثة، ويملأ خلاياها «بصمةً واحدة».
         *
         * فالغيابُ يُضمّ **هنا**، في هذا المتحكّم وحدَه.
         */
        $days = app(DayBuilder::class)
            ->build($punches, Pin::lookup($f['site_id'] ?: null))
            ->map(fn (array $d) => $d + [
                'kind'    => $d['complete'] ? AbsenceJudge::FULL : AbsenceJudge::PARTIAL,
                'at_site' => null,
            ]);

        $absence = [
            'rows' => [], 'calendar' => null,
            'dormant' => [], 'ended' => [], 'notice' => null,
        ];

        /*
         * ومتى لا يُحسب الغياب؟
         *
         * حين يكون المطلوبُ شيئاً آخر: «الحضور فقط» صريحٌ في طلبه،
         * و«الأيام الناقصة» و«غير المربوطين» بابان لا يدخلهما من لم يبصم
         * أصلاً — فلا غيرُ المربوط له اسمٌ في جدول الربط، ولا من لم يبصم
         * له بصمةٌ ناقصة.
         *
         * وحجبُه هنا يوفّر الاستعلاماتِ الخمسةَ كلَّها حين لا تُطلب.
         */
        if (! in_array($f['only'], ['present', 'partial', 'unlinked'], true)) {
            $absence = app(AbsenceFinder::class)->find($f);

            $days = $days
                ->concat($absence['rows'])

                /* ترتيبُ `DayBuilder` نفسُه — فلا يتزحزح سطرٌ اعتاده صاحبُه */
                ->sortBy([['work_date', 'desc'], ['employee', 'asc'], ['device_pin', 'asc']])
                ->values();
        }

        /*
         * الترشيحُ بعد البناء لا قبله: الاسمُ لا يُعرف إلا بعد أن يُجمع
         * الرقمُ بموقعه، وترشيحُ النبضات قبل ذلك يعني استعلاماً ثانياً
         * بقائمةِ أرقامٍ قد تبلغ المئات.
         */
        $days = match ($f['only']) {
            'present'  => $days->reject(fn (array $d) => AbsenceJudge::synthetic($d['kind']))->values(),
            'absent'   => $days->filter(fn (array $d) => AbsenceJudge::synthetic($d['kind']))->values(),

            /* `kind` لا `complete` — وإلا دخل الغائبُ في «الناقص» */
            'partial'  => $days->where('kind', AbsenceJudge::PARTIAL)->values(),
            'unlinked' => $days->where('employee', null)->values(),
            default    => $days,
        };

        return [
            'days'     => $days,
            'calendar' => $absence['calendar'] ?? Holiday::calendar($f['from'], $f['to']),
            'dormant'  => $absence['dormant'] ?? [],
            'ended'    => $absence['ended'] ?? [],
            'notice'   => $absence['notice'],
        ];
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $today = Clock::now()->toDateString();

        return [
            'from'    => (string) $request->query('from', $today),
            'to'      => (string) $request->query('to', $today),
            'site_id' => (int) $request->query('site_id', 0),
            'pin'     => trim((string) $request->query('pin', '')),
            'only'    => in_array($request->query('only'), ['present', 'absent', 'unlinked', 'partial'], true)
                ? (string) $request->query('only')
                : '',
        ];
    }
}
