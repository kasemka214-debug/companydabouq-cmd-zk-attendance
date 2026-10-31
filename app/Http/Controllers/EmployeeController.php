<?php

namespace App\Http\Controllers;

use App\Models\Pin;
use App\Models\PinLeave;
use App\Models\PinMove;
use App\Models\Punch;
use App\Models\Site;
use App\Support\Clock;
use App\Support\Employment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * الموظفون — من على رأس العمل، ومن تركَ، ومن في إجازة.
 *
 * وشاشةُ «ربط الأرقام» تبقى كما هي: تلك تقنيةٌ بطبعها — أرقامٌ تبصم بلا
 * اسم، ولصقُ قائمةٍ من ورقة الموقع — وهذه سِجِلُّ الناس. والشاشتان
 * تُعدِّلان حقولاً **لا تتقاطع**: تلك الرقمَ والاسمَ والمسمّى، وهذه
 * نهايةَ الخدمة والإجازة. فلا تفترقان يوماً على حقلٍ واحد.
 */
class EmployeeController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:employees.view',  only: ['index']),
            new Middleware('can:employees.leave', only: ['storeLeave', 'destroyLeave']),
            new Middleware('can:employees.end',   only: ['end', 'unend']),
            new Middleware('can:employees.move',  only: ['move', 'destroyMove']),
        ];
    }

    public function index(Request $request)
    {
        $siteId = (int) $request->query('site_id', 0);
        $q      = trim((string) $request->query('q', ''));

        /*
         * والافتراضُ «على رأس العمل» — فشكوى المالك أن القائمة ملأى بمن
         * تركوا. لكنه لا يعني «بلا نهاية خدمةٍ مسجَّلة» وحدَه: يومَ النشر
         * لم يُسجَّل شيءٌ بعد، فتُفتح الصفحةُ على القائمة نفسِها التي شكا
         * منها، ويحتاج إلى مئةِ تسجيلٍ يدويٍّ قبل أن تصير قائمةَ عاملين.
         * وأغلبُ الظنّ أن يُغلقها ولا يعود.
         *
         * فهو يجمع الواقعةَ والتخمين: بلا نهايةِ خدمة **وله بصمةٌ قريبة**.
         * ويصير «لم تُسجَّل حالتُه» حوضاً ثالثاً مُعلَناً بعدده — هو
         * قائمةُ العمل التي تتقلّص كلّما سُجّل أحد.
         */
        $status = in_array($request->query('status'), ['serving', 'awaiting', 'left', 'leave', 'dormant', 'all'], true)
            ? (string) $request->query('status')
            : 'serving';

        $today = Clock::workDate(Clock::now());

        $dormantAfter = date(
            'Y-m-d',
            strtotime($today.' -'.(int) config('zk.absence.dormant_days', 60).' days'),
        );

        $pins = Pin::with('site')
            ->when($siteId, fn ($b) => $b->where('site_id', $siteId))
            ->when($q !== '', fn ($b) => $b->where(function ($w) use ($q) {
                $w->where('employee_name', 'like', "%{$q}%")
                    ->orWhere('device_pin', 'like', "%{$q}%")
                    ->orWhere('employee_ref', 'like', "%{$q}%");
            }))
            ->orderBy('site_id')
            ->orderByRaw('CAST(device_pin AS UNSIGNED), device_pin')
            ->get();

        /* آخرُ بصمةٍ لكل رقم — استعلامٌ مجمَّعٌ واحدٌ لا لكل سطر */
        $lastSeen = [];

        foreach (
            Punch::query()->toBase()
                ->selectRaw('site_id, device_pin, MAX(work_date) AS last_date')
                ->groupBy('site_id', 'device_pin')
                ->get() as $r
        ) {
            $lastSeen[$r->site_id.'|'.$r->device_pin] = substr((string) $r->last_date, 0, 10);
        }

        /* إجازاتُ الجميع — مفتَّحةً بصفّها، وتُعرض على العنقود */
        $moves = PinMove::query()->toBase()->get(['from_pin_id', 'to_pin_id', 'moved_on']);

        $leaves = PinLeave::query()
            ->orderByDesc('from_date')
            ->get()
            ->groupBy('pin_id');

        $clusterOf = Employment::cluster($pins->map(fn ($p) => [
            'id'            => (int) $p->id,
            'employee_ref'  => $p->employee_ref,
            'employee_name' => $p->employee_name,
        ])->all());

        /* إجازاتُ العنقود — فالواقعةُ تُكتب مرّةً وتُرى على كل صفوف صاحبها */
        $byCluster = [];
        $rowsOf    = [];

        foreach ($pins as $p) {
            $c = $clusterOf[(int) $p->id] ?? (int) $p->id;

            $rowsOf[$c][] = ['left_on' => $p->left_on === null ? null : substr((string) $p->left_on, 0, 10)];

            foreach ($leaves[$p->id] ?? [] as $l) {
                $byCluster[$c][] = $l;
            }
        }

        $rows = $pins->map(function (Pin $p) use ($clusterOf, $byCluster, $rowsOf, $lastSeen, $today, $dormantAfter, $moves) {
            $c     = $clusterOf[(int) $p->id] ?? (int) $p->id;
            $since = substr((string) $p->created_at, 0, 10) ?: '0000-01-01';

            /* مُدَدُ خدمته في هذا الموقع، وآخرُ وصولٍ إليه */
            $events = [];
            $arrived = null;

            foreach ($moves as $m) {
                $on = substr((string) $m->moved_on, 0, 10);

                if ((int) $m->from_pin_id === (int) $p->id) {
                    $events[] = ['date' => $on, 'out' => true];
                }

                if ((int) $m->to_pin_id === (int) $p->id) {
                    $events[] = ['date' => $on, 'out' => false];
                    $arrived  = ($arrived === null || $on > $arrived) ? $on : $arrived;
                }
            }

            $p->spans      = Employment::serves($since, $events);
            $p->arrived_on = $arrived;
            $p->moved_out  = ! Employment::servesOn($p->spans, $today);

            $p->cluster_id   = $c;
            $p->own_leaves   = $byCluster[$c] ?? [];
            $p->until        = Employment::until($rowsOf[$c] ?? [], $p->spans[0]['from']);
            $p->last_punch   = $lastSeen[$p->site_id.'|'.$p->device_pin] ?? null;

            $p->on_leave = Employment::leaveOn(
                collect($p->own_leaves)->map(fn ($l) => [
                    'from_date' => substr((string) $l->from_date, 0, 10),
                    'to_date'   => substr((string) $l->to_date, 0, 10),
                    'kind'      => $l->kind,
                ])->all(),
                $today,
            );

            /*
             * والقرارُ مسجَّلٌ على صفٍّ قد لا يكون هذا — فيُقال أين هو،
             * موصولاً به. وإلا ضغط المالكُ «أعِدْه إلى العمل» على صفٍّ
             * خانتُه فارغةٌ أصلاً فلا يتغيّر شيء، فيظنّ الأزرارَ تكذب.
             */
            $p->decided_elsewhere = $p->until !== null && trim((string) $p->left_on) === '';

            /* نُقل إلى هنا ولم تصل منه بصمةٌ بعد وصوله */
            $p->awaiting = $p->until === null
                && $arrived !== null
                && ($p->last_punch === null || $p->last_punch < $arrived);

            $p->dormant = $p->until === null
                && $arrived === null
                && $p->on_leave === null
                && ($p->last_punch === null || $p->last_punch < $dormantAfter);

            /* بصم بعد نهاية خدمته — تناقضٌ يُرى ولا يُسكت عنه */
            $p->punched_after_end = $p->until !== null
                && $p->last_punch !== null
                && $p->last_punch > $p->until;

            return $p;
        });

        $counts = [
            'serving' => $rows->filter(fn ($p) => $p->until === null && ! $p->dormant
                && ! $p->awaiting && ! $p->moved_out)->count(),
            'awaiting' => $rows->filter(fn ($p) => $p->awaiting)->count(),
            'left'    => $rows->filter(fn ($p) => $p->until !== null)->count(),
            'leave'   => $rows->filter(fn ($p) => $p->on_leave !== null)->count(),
            'dormant' => $rows->filter(fn ($p) => $p->dormant)->count(),
        ];

        $rows = (match ($status) {
            'serving'  => $rows->filter(fn ($p) => $p->until === null && ! $p->dormant
                && ! $p->awaiting && ! $p->moved_out),
            'awaiting' => $rows->filter(fn ($p) => $p->awaiting),
            'left'    => $rows->filter(fn ($p) => $p->until !== null),
            'leave'   => $rows->filter(fn ($p) => $p->on_leave !== null),
            'dormant' => $rows->filter(fn ($p) => $p->dormant),
            default   => $rows,
        })->values();

        return view('employees.index', [
            'rows'    => $rows,
            'counts'  => $counts,
            'total'   => $pins->count(),
            'sites'   => Site::orderBy('name')->get(),
            'siteId'  => $siteId,
            'q'       => $q,
            'status'  => $status,
            'today'   => $today,
            'reasons' => Employment::REASONS,
            'kinds'   => Employment::KINDS,
        ]);
    }

    /** تسجيلُ نهاية الخدمة — على الصفّ المضغوط، وتسري على إخوته بالهويّة. */
    public function end(Request $request, Pin $pin)
    {
        $data = $request->validate([
            'left_on'     => ['required', 'date', 'before_or_equal:today'],
            'left_reason' => ['required', Rule::in(array_keys(Employment::REASONS))],
        ], [], ['left_on' => 'آخر يوم عمل', 'left_reason' => 'السبب']);

        /*
         * **ولا تُسجَّل نهايةُ خدمةٍ أقدمُ من وصولِ نقلٍ — والمقارنةُ `>=`.**
         *
         * فلو سُجّلت لوَرِثَها صفُّ الموقع الجديد عبر العنقود فسكت عن
         * الرجل هناك أبداً. والتساوي داخلٌ في المنع: نقلٌ ونهايةُ خدمةٍ في
         * اليوم نفسِه هو أشيعُ ما يقع، لأن نصَّ «نُقل إلى جهةٍ أخرى» في
         * أسباب نهاية الخدمة يُغري بذلك.
         */
        $arrivals = PinMove::whereIn('to_pin_id', $this->clusterIds($pin))
            ->orderByDesc('moved_on')->value('moved_on');

        if ($arrivals !== null && substr((string) $arrivals, 0, 10) >= $data['left_on']) {
            $on = substr((string) $arrivals, 0, 10);

            return back()->with('err',
                "لا تُسجَّل نهايةُ خدمة {$pin->employee_name} في {$data['left_on']}:"
                ." مسجَّلٌ أنه نُقل إلى موقعٍ آخرَ في {$on}."
                .' فإن كان قد تركَ العملَ فعلاً فسجّلها على رقمه في موقعه الجديد.');
        }

        $pin->forceFill([
            'left_on'     => $data['left_on'],
            'left_reason' => $data['left_reason'],
        ])->save();

        return back()->with('ok', "سُجّل: {$pin->employee_name} — ".Employment::reason($data['left_reason'])
            ." في {$data['left_on']}.");
    }

    /**
     * إلغاءُ نهاية الخدمة — **على العنقود كلِّه**.
     *
     * وهذا نقضٌ كُشف قبل الكتابة: الإثباتُ يُقرأ على العنقود والإلغاءُ كان
     * يُكتب على الصفّ. فمن ورث القرارَ من صفٍّ آخرَ لا يستطيع ردَّه من
     * صفّه: يضغط «أعِدْه إلى العمل» فتُفرَّغ خانةٌ فارغةٌ أصلاً، ويبقى
     * القرارُ من الصفّ الشقيق، وتبقى الشاشةُ تقول «تركَ العمل».
     *
     * فيضغط ثانيةً وثالثة ثم يستنتج أن الأزرارَ تكذب — وهو ثمنٌ لا يُستردّ.
     * **فما يُقرأ على العنقود يُبطَل على العنقود.**
     */
    public function unend(Pin $pin)
    {
        $ids = $this->clusterIds($pin);

        $n = DB::transaction(fn () => Pin::whereIn('id', $ids)
            ->update(['left_on' => null, 'left_reason' => null]));

        return back()->with('ok', $n > 1
            ? "أُعيد {$pin->employee_name} إلى العمل — وأُزيل التسجيلُ من {$n} أرقامٍ تحمل اسمَه."
            : "أُعيد {$pin->employee_name} إلى العمل.");
    }

    public function storeLeave(Request $request, Pin $pin)
    {
        $data = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date'   => ['required', 'date', 'after_or_equal:from_date'],
            'kind'      => ['required', Rule::in(array_keys(Employment::KINDS))],
            'note'      => ['nullable', 'string', 'max:500'],
        ], [], [
            'from_date' => 'من تاريخ', 'to_date' => 'إلى تاريخ',
            'kind' => 'نوع الإجازة', 'note' => 'ملاحظة',
        ]);

        $pin->leaves()->create($data + ['created_by' => $request->user()->id]);

        return back()->with('ok', "سُجّلت إجازةُ {$pin->employee_name}: "
            .Employment::spanLabel($data['from_date'], $data['to_date']).'.');
    }

    /**
     * حذفُ إجازة.
     *
     * وتُحذف من موضعها — وهي مكتوبةٌ مرّةً واحدةً على صفٍّ واحد، فحذفُها
     * من ذلك الصفّ يُزيلها عن العنقود كلِّه بطبعه. ولا حاجةَ إلى تعميمٍ
     * كالذي لزم نهايةَ الخدمة: تلك خانةٌ في كل صفّ، وهذه سطرٌ واحد.
     */
    public function destroyLeave(PinLeave $leave)
    {
        $name = $leave->pin?->employee_name ?? '';
        $span = $leave->spanLabel();

        $leave->delete();

        return back()->with('ok', "حُذفت إجازةُ {$name}: {$span}.");
    }

    /**
     * نقلُ موظفٍ إلى موقعٍ آخر.
     *
     * ويُنشأ صفُّ الربط في الموقع الجديد **مفعَّلاً** في المعاملة نفسِها،
     * فتُنسب بصماتُه هناك إليه من أوّل بصمةٍ بلا «غير مربوط» — وهذا معنى
     * «وتتفعّل تلقائياً».
     *
     * **ولا تُكتب النقلةُ بـ`left_on`** ولو كان السببُ `transfer`: تلك
     * تُقرأ على العنقود، فيرثها الصفُّ الجديد فيُسكت الرجلَ هناك إلى
     * الأبد.
     */
    public function move(Request $request, Pin $pin)
    {
        $data = $request->validate([
            'site_id'    => ['required', 'exists:zk_sites,id', 'different:'.$pin->site_id],
            'device_pin' => ['required', 'string', 'max:32'],
            'moved_on'   => ['required', 'date'],
            'note'       => ['nullable', 'string', 'max:500'],
        ], [], [
            'site_id'    => 'الموقع الجديد',
            'device_pin' => 'رقمه في جهاز الموقع الجديد',
            'moved_on'   => 'يوم النقل',
        ]);

        /*
         * **الحارسُ بـ`>=` لا `>`.**
         *
         * فنقلٌ في اليوم نفسِه الذي سُجّلت فيه نهايةُ الخدمة يُنتج الحالَ
         * الفاسدة التي بُنيت الميزةُ كلُّها على منعها: الحاجزُ في `until()`
         * هو `$on < $stintFrom`، ولا يُسقط التساوي — فيمرّ القرارُ ويُسكت
         * الرجلَ في موقعه الجديد.
         */
        $until = Employment::until(
            Pin::whereIn('id', $this->clusterIds($pin))->get(['left_on'])
                ->map(fn ($r) => ['left_on' => $r->left_on === null ? null : substr((string) $r->left_on, 0, 10)])
                ->all(),
            '0000-01-01',
        );

        if ($until !== null && $data['moved_on'] >= $until) {
            return back()->with('err', "لا يُنقَل {$pin->employee_name}: مسجَّلٌ أنه تركَ العملَ في {$until}."
                .' أعِدْه إلى العمل أوّلاً، ثم انقله.');
        }

        $target = Pin::where('site_id', $data['site_id'])
            ->where('device_pin', $data['device_pin'])->first();

        /*
         * ورقمٌ مشغولٌ برجلٍ آخر لا يُؤخذ: القيدُ الفريد يمنعه، والرسالةُ
         * تُسمّي صاحبَه فيُختار غيرُه — ولا تُترك قاعدةُ البيانات تشرح.
         */
        if ($target && ! in_array((int) $target->id, $this->clusterIds($pin), true)) {
            return back()->with('err', "الرقم {$data['device_pin']} في هذا الموقع مشغولٌ بـ«{$target->employee_name}»."
                .' اختر رقماً آخر.');
        }

        DB::transaction(function () use ($pin, $data, $target, $request) {
            $to = $target ?? Pin::create([
                'site_id'       => (int) $data['site_id'],
                'device_pin'    => $data['device_pin'],
                'employee_name' => $pin->employee_name,
                'employee_ref'  => $pin->employee_ref,
                'job_title'     => $pin->job_title,
                'is_active'     => true,
                'note'          => 'نُقل من '.($pin->site?->name ?? 'موقعٍ آخر'),
            ]);

            PinMove::create([
                'from_pin_id' => $pin->id,
                'to_pin_id'   => $to->id,
                'moved_on'    => $data['moved_on'],
                'note'        => $data['note'] ?? null,
                'created_by'  => $request->user()->id,
            ]);
        });

        $site = Site::find($data['site_id'])?->name;

        return back()->with('ok', "نُقل {$pin->employee_name} إلى «{$site}» برقم {$data['device_pin']}"
            ." من {$data['moved_on']}. وتبقى خطوةٌ واحدة: سجّل إصبعَه على جهاز ذلك الموقع"
            .' بهذا الرقم بعينه — فالبرنامجُ لا ينقل البصمةَ نفسَها.');
    }

    /**
     * حذفُ نقلة — يردّ المُدَدَ إلى ما كانت عليه.
     *
     * فالمخزَّنُ حدثٌ والمُدَدُ محسوبةٌ منه، فلا تصحيحَ يدويّاً بعد الحذف.
     */
    public function destroyMove(PinMove $move)
    {
        $name = $move->fromPin?->employee_name ?? '';
        $on   = substr((string) $move->moved_on, 0, 10);

        $move->delete();

        return back()->with('ok', "حُذفت نقلةُ {$name} المؤرَّخة {$on}، وعادت مُدَدُ خدمته كما كانت.");
    }

    /**
     * مُعرِّفاتُ صفوف العنقود الذي ينتمي إليه هذا الصفّ.
     *
     * @return array<int, int>
     */
    private function clusterIds(Pin $pin): array
    {
        $all = Pin::query()->get(['id', 'employee_ref', 'employee_name'])
            ->map(fn ($p) => [
                'id'            => (int) $p->id,
                'employee_ref'  => $p->employee_ref,
                'employee_name' => $p->employee_name,
            ])->all();

        $map  = Employment::cluster($all);
        $mine = $map[(int) $pin->id] ?? (int) $pin->id;

        return array_keys(array_filter($map, fn ($c) => $c === $mine));
    }
}
