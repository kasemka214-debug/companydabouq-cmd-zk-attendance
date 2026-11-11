<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\ShiftWatch;
use App\Support\ShiftJudge;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SiteController extends Controller implements HasMiddleware
{
    /** مفتاحٌ لكل فعل — والحذفُ مفتاحُه غيرُ مفتاح التعديل. */
    public static function middleware(): array
    {
        return [
            new Middleware('can:sites.view',   only: ['index']),
            new Middleware('can:sites.create', only: ['store']),
            new Middleware('can:sites.edit',   only: ['update', 'applyAll']),
            new Middleware('can:sites.delete', only: ['destroy']),
        ];
    }

    public function index()
    {
        return view('sites.index', [
            'sites' => Site::withCount(['devices', 'pins'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        Site::create($this->validated($request));

        return redirect()->route('sites.index')->with('ok', 'أُضيف الموقع.');
    }

    public function update(Request $request, Site $site)
    {
        $site->update($this->validated($request, $site->id));

        return redirect()->route('sites.index')->with('ok', 'حُفظ الموقع.');
    }

    public function destroy(Site $site)
    {
        /*
         * الموقعُ لا يُحذف وفيه جهازٌ أو رقمٌ مربوط. والمنعُ خيرٌ من الحذف
         * المتسلسل: حذفُ موقعٍ بالخطأ يجرّ أرقامَه وأسماءَها كلَّها، ولا
         * تُستردّ — بينما النبضاتُ تبقى يتيمةً بلا موقعٍ ولا اسم.
         */
        if ($site->devices()->exists() || $site->pins()->exists()) {
            return back()->with('err', 'لا يُحذف موقعٌ فيه أجهزةٌ أو أرقامٌ مربوطة. انقلها أو احذفها أولاً.');
        }

        $site->delete();

        return redirect()->route('sites.index')->with('ok', 'حُذف الموقع.');
    }

    /**
     * جدولُ الدوام على المواقع كلِّها دفعةً واحدة.
     *
     * فأكثرُ الشركات دوامُها واحدٌ في مواقعها كلِّها، وكتابتُه موقعاً موقعاً
     * في عشرين موقعاً عملٌ يُنسى في منتصفه — فيبقى نصفُها على الافتراض
     * ونصفُها على الجدول، ولا شيءَ في الشاشة ينبّه.
     */
    public function applyAll(Request $request)
    {
        $data = $request->validate([
            'work_start'    => ['required', 'date_format:H:i'],
            'work_end'      => ['required', 'date_format:H:i'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:120'],
        ], [], [
            'work_start'    => 'بداية الدوام',
            'work_end'      => 'نهاية الدوام',
            'grace_minutes' => 'مهلة السماح',
        ]);

        $count = Site::query()->update(self::columns($data));

        /* الجدولُ تغيّر ⇐ الرصدُ تغيّر ⇐ عدّادُ الإشعار كاذبٌ حتى يُمسح */
        ShiftWatch::forget();

        return redirect()->route('sites.index')
            ->with('ok', 'طُبّق الجدولُ على '.$count.' موقعاً.');
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:190', 'unique:zk_sites,name'.($ignore ? ",{$ignore}" : '')],
            'code'       => ['nullable', 'string', 'max:32'],
            'note'       => ['nullable', 'string', 'max:190'],
            'is_active'  => ['nullable', 'boolean'],

            /*
             * الجدولُ يُكتب كما يقوله الناس: «من السابعة إلى الثالثة» —
             * لا «أربعُ مئةٍ وثمانون دقيقة». والدقائقُ تُشتقّ منهما.
             *
             * وتركُهما فارغين يرجع بالموقع إلى الافتراض العامّ، ولا يجوز
             * أن يُكتب أحدُهما وحدَه: بدايةٌ بلا نهايةٍ لا يُعرف منها دوام.
             */
            'work_start'    => ['nullable', 'date_format:H:i', 'required_with:work_end'],
            'work_end'      => ['nullable', 'date_format:H:i', 'required_with:work_start'],
            'grace_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
        ], [], [
            'name'          => 'اسم الموقع',
            'code'          => 'الرمز',
            'note'          => 'ملاحظة',
            'work_start'    => 'بداية الدوام',
            'work_end'      => 'نهاية الدوام',
            'grace_minutes' => 'مهلة السماح',
        ]);

        ShiftWatch::forget();

        /* و«نهايةُ الدوام» حقلُ شاشةٍ لا عمودَ جدول — تُترك هنا بعد أن أدّت معناها */
        unset($data['work_end']);

        return array_merge(
            $data,
            ['is_active' => $request->boolean('is_active')],
            self::columns($data + ['work_end' => $request->input('work_end')]),
        );
    }

    /**
     * «من ٧:٠٠ إلى ٣:٠٠» ⇐ الأعمدةُ الثلاثةُ كما تُخزَّن.
     *
     * **والنهايةُ قبل البداية مناوبةٌ ليلية** لا خطأً: حارسٌ يبدأ السادسة
     * مساءً وينتهي السادسة صباحاً دوامُه اثنتا عشرة ساعة، لا سالبَ اثنتي
     * عشرة.
     *
     * @return array{work_start: ?string, work_minutes: ?int, grace_minutes: ?int}
     */
    private static function columns(array $data): array
    {
        $start = $data['work_start'] ?? null;
        $end   = $data['work_end'] ?? null;

        if (! $start || ! $end) {
            return ['work_start' => null, 'work_minutes' => null, 'grace_minutes' => null];
        }

        return [
            'work_start'    => $start,
            'work_minutes'  => ShiftJudge::minutesBetween($start, $end),
            'grace_minutes' => (int) ($data['grace_minutes'] ?? 0),
        ];
    }
}
