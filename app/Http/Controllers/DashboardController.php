<?php

namespace App\Http\Controllers;

use App\Models\ClockEvent;
use App\Models\Device;
use App\Models\Pin;
use App\Models\Punch;
use App\Models\RawLog;
use App\Models\Site;
use App\Support\Clock;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:dashboard.view')];
    }

    public function index()
    {
        $today = Clock::now()->toDateString();

        /*
         * آخرُ نبضةٍ لكل جهازٍ وعددُها — محسوبةً هنا لا مخزَّنةً في صفّ
         * الجهاز. استعلامٌ واحدٌ مجمَّعٌ لعشرين جهازاً أرخصُ من عمودَين
         * يشيخان كلّما حُذفت نبضةٌ أو نُقل جهازٌ من موقعٍ إلى آخر.
         */
        $stats = Punch::query()
            ->select('device_id', DB::raw('COUNT(*) AS total'), DB::raw('MAX(punched_at) AS last_at'))
            ->groupBy('device_id')
            ->get()
            ->keyBy('device_id');

        $devices = Device::with('site')->orderBy('name')->get();

        $todayCount = Punch::where('work_date', $today)->count();

        $todayPeople = Punch::where('work_date', $today)
            ->distinct()
            ->count(DB::raw('CONCAT(site_id, "|", device_pin)'));

        /*
         * الأرقامُ التي بصمت ولا اسمَ لها. وهذه أهمُّ خانةٍ في اللوحة:
         * رقمٌ بلا اسمٍ يعني عاملاً يبصم كلَّ يومٍ ولا يظهر في أيّ كشف،
         * ولا يكتشفه أحدٌ إلا حين يُسأل عن غيابه في آخر الشهر.
         */
        $known = Pin::query()->selectRaw('CONCAT(site_id, "|", device_pin) AS k')->pluck('k')->all();

        $orphans = Punch::query()
            ->selectRaw('site_id, device_pin, COUNT(*) AS total, MAX(punched_at) AS last_at')
            ->groupBy('site_id', 'device_pin')
            ->get()
            ->reject(fn ($row) => in_array($row->site_id.'|'.$row->device_pin, $known, true))
            ->values();

        /*
         * المردودون — **بنافذةٍ زمنيةٍ لا بأحدثِ عشرةٍ على الإطلاق.**
         *
         * فـ`latest('id')` بلا حدٍّ زمنيٍّ تُثبّت اللوحةَ على آخر عشرة
         * ردٍّ **وقعت يوماً** لا على ما يُردّ الآن: فمتى عُولجت العلّةُ
         * وصار كلُّ طلبٍ مقبولاً، لم تُنتَج صفوفٌ مردودةٌ جديدة، فبقيت
         * القديمةُ معروضةً حتى يمحوها التقليمُ بعد ثلاثين يوماً — ولوحةٌ
         * تُنذر من علّةٍ زالت تُعلِّم صاحبَها أن يتجاهل إنذارَها.
         *
         * وقد وقع هذا فعلاً: ثمانيةَ عشرَ ألفَ ردٍّ (`unknown_serial`)
         * تراكمت في نقل القاعدة، وكانت ستبقى في اللوحة شهراً بعد أن
         * تُقبل الأجهزة.
         */
        $rejected = RawLog::query()
            ->where('accepted', false)
            ->where('created_at', '>=', Clock::now()->subDay())
            ->latest('id')
            ->limit(10)
            ->get();

        /*
         * أحداثُ ساعةٍ لم يُقَرّ بها — تنبيهُ اللوحة.
         *
         * والحدُّ خمسةٌ لأن التنبيهَ إشارةٌ لا تقرير: من رأى ثلاثين حدثاً
         * في اللوحة لم يقرأ منها شيئاً، والتفصيلُ في شاشته.
         */
        $clockEvents = ClockEvent::query()
            ->with('device')
            ->unacknowledged()
            ->alarming()
            ->latest('opened_at')
            ->limit(5)
            ->get();

        return view('dashboard', [
            'clockEvents' => $clockEvents,
            'devices'     => $devices,
            'stats'       => $stats,
            'sites'       => Site::withCount('devices')->orderBy('name')->get(),
            'todayCount'  => $todayCount,
            'todayPeople' => $todayPeople,
            'today'       => $today,
            'orphans'     => $orphans,
            'rejected'    => $rejected,
        ]);
    }
}
