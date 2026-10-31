<?php

namespace App\Http\Controllers;

use App\Models\ClockEvent;
use App\Models\Device;
use App\Models\Operation;
use App\Models\Pin;
use App\Models\Site;
use App\Support\Clock;
use App\Support\ClockJudge;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * تقريرُ ساعات الأجهزة — مَن غيّرها، ومتى، ومتى أُعيدت، وما مسّه ذلك.
 *
 * وهذه الشاشةُ تُقرأ في نزاع: عاملٌ يقول حضرتُ السابعةَ والكشفُ يقول
 * الثامنة. فالمطلوبُ منها ليس «إنذاراً» بل **جواباً**: أكانت ساعةُ الجهاز
 * مضبوطةً تلك الساعة؟ وإن لم تكن، فما مدى ما أفسدته؟
 */
class ClockController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:clock.view', only: ['index', 'show', 'operations']),
            new Middleware('can:clock.ack',  only: ['acknowledge']),
        ];
    }

    public function index(Request $request)
    {
        $deviceId = (int) $request->query('device_id', 0);
        $siteId   = (int) $request->query('site_id', 0);
        $class    = trim((string) $request->query('class', ''));
        $open     = $request->query('open');

        $events = ClockEvent::query()
            ->with(['device.site', 'acknowledgedBy'])
            ->when($deviceId, fn ($q) => $q->where('device_id', $deviceId))
            ->when($siteId, fn ($q) => $q->whereHas('device', fn ($d) => $d->where('site_id', $siteId)))
            ->when($class !== '', fn ($q) => $q->where('class', $class))
            ->when($open === '1', fn ($q) => $q->whereNull('closed_at'))
            /*
             * و«لم يُقَرّ به» هو **ما يقرعه الجرسُ بعينه** — لا كلُّ ما لم
             * يُقَرّ به. فالجرسُ يعدّ ما يستحقّ إنذاراً، فلو سرد المرشَّحُ
             * كلَّ شيءٍ قال الجرسُ اثنين وعرض الجدولُ خمسةً، فلا يُوثق
             * بواحدٍ منهما. والزحفُ البطيءُ في «الكل».
             */
            ->when($open === 'new', fn ($q) => $q->whereNull('acknowledged_at')->alarming())
            ->latest('opened_at')
            ->paginate(25)
            ->withQueryString();

        /*
         * نطاقُ الضرر يُحسب لكل حدثٍ عند العرض ولا يُخزَّن.
         *
         * فالنبضاتُ تُحذف وتُضاف، والعددُ المخزَّنُ يشيخ فيكذب — يقول «٤٠
         * بصمةً مشكوكة» وقد صارت عشراً. والحسابُ استعلامٌ مفهرسٌ واحدٌ لكل
         * سطرٍ في صفحةٍ من خمسةٍ وعشرين، وثمنُه مقبول.
         */
        $events->getCollection()->transform(function (ClockEvent $e) {
            $e->suspect_count = $e->suspectPunches()->count();
            $e->suspect_pins  = $e->suspectPunches()->distinct()->count('device_pin');

            /*
             * ومَن فعلها إن بلّغ الجهازُ بذلك — رقمُه من سجلّ العمليات.
             * وهو الجوابُ الوحيد على «مَن»، فالانحرافُ لا يقوله.
             */
            $e->culprits = $e->operations()
                ->touchingTime()
                ->whereNotNull('operator_pin')
                ->pluck('operator_pin')
                ->unique()
                ->values();

            return $e;
        });

        return view('clock.index', [
            'events'   => $events,
            'devices'  => Device::orderBy('name')->get(['id', 'name']),
            'sites'    => Site::orderBy('name')->get(['id', 'name']),
            'deviceId' => $deviceId,
            'siteId'   => $siteId,
            'class'    => $class,
            'open'     => (string) $open,
            'classes'  => ClockJudge::LABELS,
            'names'    => Pin::query()->pluck('employee_name', 'device_pin'),
        ]);
    }

    /**
     * تفصيلُ حدثٍ واحد — ومقصودُه أن يُقرأ في مواجهة.
     *
     * فيه ثلاثةُ أشياء: ما قِيس (الدليل)، وما بلّغ به الجهازُ (الشاهد)،
     * وما وصل في المدّة (نطاقُ الضرر).
     */
    public function show(ClockEvent $event)
    {
        $event->load(['device.site', 'command', 'acknowledgedBy']);

        /*
         * **النبضاتُ بوقت الوصول لا بوقت البصمة.**
         *
         * وهي القاعدةُ التي لا يُقرأ التقريرُ بغيرها: لا تُستعمل ساعةٌ
         * كاذبةٌ لتحديد ما أفسدته. فمن قدّم الساعةَ يوماً صارت بصماتُه
         * موسومةً بالغد، فلو رُشّحت بـ`punched_at` سقطت من النافذة كلُّها
         * — والنافذةُ إنما فُتحت لها.
         */
        $punches = $event->suspectPunches()
            ->orderBy('received_at')
            ->paginate(100);

        return view('clock.show', [
            'event'      => $event,
            'punches'    => $punches,
            'operations' => $event->operations()->get(),
            'names'      => Pin::query()
                ->when($event->device?->site_id, fn ($q, $id) => $q->where('site_id', $id))
                ->pluck('employee_name', 'device_pin'),
        ]);
    }

    /**
     * سجلُّ ما جرى على الأجهزة — بإقرارها هي.
     *
     * وهو أوسعُ من أحداث الساعة: فيه من فتح القائمةَ ومن أعاد التشغيل ومن
     * مسح سجلاً. والحدثُ لا يُفتح إلا إن انحرفت الساعةُ فعلاً، وأمّا من
     * فتح القائمةَ ولم يغيّر شيئاً فلا حدثَ له — ويبقى أثرُه هنا.
     */
    public function operations(Request $request)
    {
        $deviceId = (int) $request->query('device_id', 0);
        $onlyTime = $request->query('time') === '1';

        $rows = Operation::query()
            ->with('device')
            ->when($deviceId, fn ($q) => $q->where('device_id', $deviceId))
            ->when($onlyTime, fn ($q) => $q->touchingTime())
            ->latest('received_at')
            ->paginate(100)
            ->withQueryString();

        return view('clock.operations', [
            'rows'     => $rows,
            'devices'  => Device::orderBy('name')->get(['id', 'name']),
            'deviceId' => $deviceId,
            'onlyTime' => $onlyTime,
            'names'    => Pin::query()->pluck('employee_name', 'device_pin'),
        ]);
    }

    /**
     * إقرارُ المسؤول بأنه رأى الحدث.
     *
     * والإقرارُ هو الذي يُسكت الإشعار لا الإغلاق. ومن غيّر الساعةَ ثم
     * أعادها بعد دقائق أُغلق حدثُه من نفسه — وهو أفصحُ ما يكون على عبثٍ
     * مقصود — فلو سكت الإشعارُ بالإغلاق لَما رآه أحدٌ أصلاً.
     */
    public function acknowledge(Request $request, ClockEvent $event)
    {
        $data = $request->validate(
            ['note' => ['nullable', 'string', 'max:1000']],
            [],
            ['note' => 'الملاحظة'],
        );

        $event->forceFill([
            'acknowledged_at' => Carbon::now('UTC'),
            'acknowledged_by' => $request->user()->id,
            'note'            => $data['note'] ?? $event->note,
        ])->save();

        return back()->with('ok', 'أُقِرَّ بالحدث في '.Clock::stamp(Carbon::now('UTC'), 'H:i').'.');
    }
}
