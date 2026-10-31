<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Models\Pin;
use App\Models\PunchPhoto;
use App\Models\Site;
use App\Services\AttendanceGrid;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller implements HasMiddleware
{
    public function __construct(private AttendanceGrid $grid) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:reports.view',      only: ['weekly', 'monthly', 'employee', 'weeklyExcel', 'monthlyExcel']),
            new Middleware('can:page.print',        only: ['weeklyExcel', 'monthlyExcel']),
            new Middleware('can:holidays.manage',   only: ['saveHolidays']),
        ];
    }

    // ── الأسبوعي ──────────────────────────────────────────────────────────

    public function weekly(Request $request)
    {
        [$from, $to] = $this->week($request);

        $siteId = (int) $request->query('site_id', 0);
        $built  = $this->grid->build($from, $to, $siteId ?: null);

        return view('reports.weekly', $this->payload($built, $from, $to, $siteId) + [
            'pin' => trim((string) $request->query('pin', '')),
        ]);
    }

    public function weeklyExcel(Request $request)
    {
        [$from, $to] = $this->week($request);

        $siteId = (int) $request->query('site_id', 0);

        return $this->sheet($this->grid->build($from, $to, $siteId ?: null), $from, $to, 'التقرير-الأسبوعي');
    }

    // ── الشهري ────────────────────────────────────────────────────────────

    public function monthly(Request $request)
    {
        [$from, $to] = $this->month($request);

        $siteId = (int) $request->query('site_id', 0);
        $built  = $this->grid->build($from, $to, $siteId ?: null);

        return view('reports.monthly', $this->payload($built, $from, $to, $siteId) + [
            'year'  => (int) Carbon::parse($from)->year,
            'month' => (int) Carbon::parse($from)->month,
        ]);
    }

    public function monthlyExcel(Request $request)
    {
        [$from, $to] = $this->month($request);

        $siteId = (int) $request->query('site_id', 0);

        return $this->sheet($this->grid->build($from, $to, $siteId ?: null), $from, $to, 'التقرير-الشهري');
    }

    // ── الموظف ────────────────────────────────────────────────────────────

    /**
     * تقريرُ موظفٍ واحد — يومٌ بيومٍ في مدّةٍ يختارها.
     *
     * والكشوفُ الأخرى تُقرأ عرضاً: مَن نقص ومَن زاد. وهذا يُقرأ طولاً:
     * لماذا نقص هذا بعينه؟ فيُعرض كلُّ يومٍ في المدّة — ولو لم يبصم فيه —
     * بحالته ودخوله وخروجه وفرقه عن المطلوب. والغيابُ سطرٌ ظاهرٌ لا فراغ.
     */
    public function employee(Request $request)
    {
        [$from, $to] = $this->range($request);

        $siteId = (int) $request->query('site_id', 0);
        $pin    = trim((string) $request->query('pin', ''));

        $person = null;
        $site   = null;
        $built  = null;

        if ($pin !== '' && $siteId) {
            $built = $this->grid->build($from, $to, $siteId, false, $pin);

            foreach ($built['groups'] as $g) {
                foreach ($g['people'] as $p) {
                    if ($p['pin'] === $pin) {
                        $person = $p;
                        $site   = $g['name'];
                    }
                }
            }
        }

        return view('reports.employee', [
            'person'   => $person,
            'site'     => $site,
            'calendar' => $built['calendar'] ?? Holiday::calendar($from, $to),
            'target'   => $built['target'] ?? 0,
            'photos'   => $person ? $this->photoIndex($siteId, $pin, $from, $to) : [],
            'sites'    => Site::orderBy('name')->get(),
            'people'   => $siteId ? Pin::where('site_id', $siteId)
                ->orderByRaw('CAST(device_pin AS UNSIGNED), device_pin')
                ->get(['device_pin', 'employee_name', 'job_title']) : collect(),
            'from'     => $from,
            'to'       => $to,
            'siteId'   => $siteId,
            'pin'      => $pin,
            'hours'    => (float) config('zk.daily_hours', 8),
        ]);
    }

    /** أيُّ يومٍ له صورة — استعلامٌ واحدٌ مجمَّع لا واحدٌ لكل سطر. */
    private function photoIndex(int $siteId, string $pin, string $from, string $to): array
    {
        if (! Gate::allows('photos.view')) {
            return [];
        }

        return PunchPhoto::query()
            ->selectRaw('work_date, COUNT(*) AS total, MIN(id) AS first_id')
            ->where('site_id', $siteId)
            ->where('device_pin', $pin)
            ->whereBetween('work_date', [$from, $to])
            ->groupBy('work_date')
            ->get()
            ->mapWithKeys(fn ($r) => [
                Carbon::parse($r->work_date)->toDateString() => ['total' => (int) $r->total, 'first' => (int) $r->first_id],
            ])
            ->all();
    }

    // ── العطل ─────────────────────────────────────────────────────────────

    /**
     * حفظُ عطل الشهر — ما أُشّر عليه يبقى وما لا فيُحذف.
     *
     * والمدّةُ تُمرَّر صراحةً لأن الحذفَ يقتصر عليها: لولا ذلك لمحا حفظُ
     * شهرٍ عطلَ السنة كلِّها، ولا شيءَ في الشاشة ينذر بذلك.
     */
    public function saveHolidays(Request $request)
    {
        $data = $request->validate([
            'from'    => ['required', 'date'],
            'to'      => ['required', 'date', 'after_or_equal:from'],
            'days'    => ['nullable', 'array'],
            'days.*'  => ['date'],
        ], [], ['from' => 'من', 'to' => 'إلى', 'days' => 'الأيام']);

        $keep = collect($data['days'] ?? [])
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->filter(fn ($d) => $d >= $data['from'] && $d <= $data['to'])
            ->values();

        Holiday::whereBetween('date', [$data['from'], $data['to']])
            ->whereNotIn('date', $keep)
            ->delete();

        foreach ($keep as $date) {
            Holiday::firstOrCreate(['date' => $date], ['created_by' => $request->user()->id]);
        }

        return back()->with('ok', 'حُفظت عطل المدّة: '.$keep->count().' يوماً.');
    }

    // ── المشترك ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function payload(array $built, string $from, string $to, int $siteId): array
    {
        return [
            'calendar' => $built['calendar'],
            'target'   => $built['target'],
            'groups'   => $built['groups'],
            'sites'    => Site::orderBy('name')->get(),
            'from'     => $from,
            'to'       => $to,
            'siteId'   => $siteId,
            'hours'    => (float) config('zk.daily_hours', 8),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function week(Request $request): array
    {
        $ref = $this->date($request->query('week'), Clock::now());

        /*
         * الأسبوعُ يبدأ السبت في الكويت — الجمعةُ آخرُه لا أوّلُه. ولو بدأ
         * الاثنين كما في أوروبا لانقسمت عطلةُ الأسبوع بين تقريرين.
         */
        $start = $ref->copy()->startOfWeek(Carbon::SATURDAY);

        return [$start->toDateString(), $start->copy()->addDays(6)->toDateString()];
    }

    /**
     * مدّةٌ حرّة — وافتراضُها الشهرُ الجاري.
     *
     * @return array{0: string, 1: string}
     */
    private function range(Request $request): array
    {
        $now = Clock::now();

        $from = $this->date($request->query('from'), $now->copy()->startOfMonth());
        $to   = $this->date($request->query('to'), $now->copy()->endOfMonth());

        /* مدّةٌ مقلوبةٌ تُعاد إلى نصابها بدل أن تُخرج كشفاً فارغاً */
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    /** @return array{0: string, 1: string} */
    private function month(Request $request): array
    {
        $now   = Clock::now();
        $year  = (int) $request->query('year', $now->year);
        $month = (int) $request->query('month', $now->month);

        if ($month < 1 || $month > 12) {
            $month = $now->month;
        }

        if ($year < 2000 || $year > 2100) {
            $year = $now->year;
        }

        $start = Carbon::create($year, $month, 1);

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    private function date(?string $value, Carbon $fallback): Carbon
    {
        try {
            return $value ? Carbon::parse($value) : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * التصدير: صفٌّ لكل موظفٍ وعمودٌ لكل يوم — كالشاشة سواء.
     */
    private function sheet(array $built, string $from, string $to, string $title)
    {
        $dates = array_keys($built['calendar']['dates']);

        $head = array_merge(
            ['م', 'الموقع', 'الموظف', 'المسمّى', 'الرقم'],
            array_map(fn ($d) => Carbon::parse($d)->format('j'), $dates),
            ['المجموع', 'المطلوب', 'الفرق'],
        );

        $rows = [];
        $n    = 0;

        foreach ($built['groups'] as $group) {
            foreach ($group['people'] as $p) {
                $n++;

                $cells = array_map(
                    fn ($d) => AttendanceGrid::hhmm($p['days'][$d]['minutes'] ?? null, ''),
                    $dates,
                );

                $rows[] = array_merge(
                    [$n, $group['name'], $p['name'] ?? '— غير مربوط —', $p['job'] ?? '', $p['pin']],
                    $cells,
                    [
                        AttendanceGrid::hhmm($p['total'], '0:00'),
                        AttendanceGrid::hhmm($built['target'], '0:00'),
                        AttendanceGrid::hhmm($p['total'] - $built['target'], '0:00'),
                    ],
                );
            }
        }

        return $this->csv("{$title}-{$from}-إلى-{$to}.csv", $head, $rows);
    }
}
