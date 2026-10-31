<?php

namespace App\Http\Controllers;

use App\Models\Pin;
use App\Models\Punch;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * ربطُ رقم الجهاز بالاسم.
 *
 * الجهازُ لا يعرف أسماءً — يعرف أرقاماً. وكلُّ قيمةِ هذا النظام في هذا
 * الجدول: نبضةٌ بلا اسمٍ سطرٌ لا يُقرأ، ونبضةٌ باسمٍ كشفُ حضور.
 */
class PinController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:pins.view',   only: ['index']),
            new Middleware('can:pins.create', only: ['store', 'import']),
            new Middleware('can:pins.edit',   only: ['update']),
            new Middleware('can:pins.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $siteId = (int) $request->query('site_id', 0);
        $q      = trim((string) $request->query('q', ''));

        $pins = Pin::with('site')
            ->when($siteId, fn ($b) => $b->where('site_id', $siteId))
            ->when($q !== '', fn ($b) => $b->where(function ($w) use ($q) {
                $w->where('employee_name', 'like', "%{$q}%")
                    ->orWhere('device_pin', 'like', "%{$q}%")
                    ->orWhere('employee_ref', 'like', "%{$q}%");
            }))
            ->orderBy('site_id')
            ->orderByRaw('CAST(device_pin AS UNSIGNED), device_pin')
            ->paginate(100)
            ->withQueryString();

        /*
         * الأرقامُ التي بصمت ولم تُربط بعد — تُعرض فوق الجدول لا في صفحةٍ
         * أخرى. لأن أحداً لن يفتح صفحةً ليبحث عن نقصٍ لا يعلم به، وهذا
         * النقصُ بالذات هو الذي يُسقط عاملاً من الكشف كلَّه.
         */
        $known = Pin::query()->selectRaw('CONCAT(site_id, "|", device_pin) AS k')->pluck('k')->all();

        $orphans = Punch::query()
            ->selectRaw('site_id, device_pin, COUNT(*) AS total, MAX(punched_at) AS last_at')
            ->when($siteId, fn ($b) => $b->where('site_id', $siteId))
            ->groupBy('site_id', 'device_pin')
            ->get()
            ->reject(fn ($row) => in_array($row->site_id.'|'.$row->device_pin, $known, true))
            ->sortByDesc('total')
            ->values();

        /*
         * ولمن بصم بلا اسمٍ سؤالٌ ثانٍ: أهو مجهولٌ حقاً، أم اسمُه مربوطٌ
         * في موقعٍ آخر؟
         *
         * فالرقمُ يُفرَّق بموقعه — «1001» في موقعٍ غيرُ «1001» في آخر —
         * فإن نُقل جهازٌ بين المواقع انتقلت بصماتُه معه وبقيت أسماؤه في
         * موقعه الأوّل، فتظهر الكشوفُ كلُّها «غير مربوط» بلا سببٍ ظاهر.
         *
         * وهذا يُعلن السببَ ويعرض العلاج: اسمٌ جاهزٌ يُنسخ بضغطة.
         */
        $elsewhere = Pin::query()
            ->whereIn('device_pin', $orphans->pluck('device_pin')->unique())
            ->with('site:id,name')
            ->get(['site_id', 'device_pin', 'employee_name', 'employee_ref', 'job_title'])
            ->groupBy('device_pin');

        $orphans = $orphans->map(function ($o) use ($elsewhere) {
            $o->elsewhere = ($elsewhere[$o->device_pin] ?? collect())
                ->firstWhere('site_id', '!=', $o->site_id);

            return $o;
        });

        /*
         * قائمةٌ مهيّأةٌ للّصق — الأرقامُ مكتوبةٌ وينقصها الأسماء.
         *
         * وهذا ما تبقّى بعد أن تبيّن أن أجهزة التحكّم لا ترسل أسماءَ
         * مستخدميها: تُنفَّذ وتُقِرّ ولا تُعطي. فبدل أن يقرأ المستخدمُ
         * الأرقامَ من جدولٍ ثم يكتبها في مربّعٍ آخر — وهو موضعُ الخطأ —
         * تُكتب له مرتَّبةً بالأكثرِ بصماتٍ أوّلاً، ولا يبقى عليه إلا
         * الاسم.
         *
         * ولا تُهيَّأ إلا لموقعٍ مختار: الاستيرادُ يلزمه موقعٌ واحد،
         * والرقمُ يُفرَّق بموقعه.
         */
        $prefill = $siteId
            ? $orphans->map(fn ($o) => $o->device_pin.', ')->implode("\n")
            : '';

        return view('pins.index', [
            'pins'    => $pins,
            'sites'   => Site::orderBy('name')->get(),
            'siteId'  => $siteId,
            'q'       => $q,
            'orphans' => $orphans,
            'prefill' => $prefill,
        ]);
    }

    public function store(Request $request)
    {
        Pin::create($this->validated($request));

        return back()->with('ok', 'أُضيف الربط.');
    }

    public function update(Request $request, Pin $pin)
    {
        $pin->update($this->validated($request, $pin));

        return back()->with('ok', 'حُفظ الربط.');
    }

    public function destroy(Pin $pin)
    {
        /*
         * حذفُ الربط لا يمسّ النبضات — تبقى بأرقامها بلا اسم. وهذا مقصود:
         * الحضورُ واقعةٌ حدثت، وخطأٌ في اسمٍ لا يجوز أن يمحوها.
         */
        $pin->delete();

        return back()->with('ok', 'حُذف الربط. والنبضاتُ باقيةٌ بأرقامها.');
    }

    /**
     * استيرادُ قائمةٍ ملصوقة.
     *
     * خمسةُ أجهزةٍ في الموقع وعشراتُ العمال: إدخالُ الربط سطراً سطراً
     * عملُ يومٍ يُخطئ فيه المرء مرّتين. والقائمةُ تُلصق من ورقة الموقع
     * كما هي: الرقم ثم الاسم ثم رقمُه في دابوق، بفاصلةٍ أو جدولة.
     */
    public function import(Request $request)
    {
        $data = $request->validate([
            'site_id' => ['required', 'exists:zk_sites,id'],
            'list'    => ['required', 'string'],
        ], [], ['site_id' => 'الموقع', 'list' => 'القائمة']);

        $added = $updated = $skipped = 0;

        foreach (preg_split('/\r\n|\r|\n/', $data['list']) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $cols = array_map('trim', preg_split('/[,;\t]+/', $line));

            $pin  = $cols[0] ?? '';
            $name = $cols[1] ?? '';

            if ($pin === '' || $name === '') {
                $skipped++;

                continue;
            }

            $row = Pin::firstOrNew([
                'site_id'    => (int) $data['site_id'],
                'device_pin' => mb_substr($pin, 0, 32),
            ]);

            $existed = $row->exists;

            $row->fill([
                'employee_name' => mb_substr($name, 0, 190),
                'employee_ref'  => ($cols[2] ?? '') !== '' ? mb_substr($cols[2], 0, 32) : $row->employee_ref,
                'job_title'     => ($cols[3] ?? '') !== '' ? mb_substr($cols[3], 0, 190) : $row->job_title,
                'is_active'     => true,
            ])->save();

            $existed ? $updated++ : $added++;
        }

        return back()->with('ok', "أُضيف {$added}، وحُدِّث {$updated}، وتُرك {$skipped} سطراً ناقصاً.");
    }

    private function validated(Request $request, ?Pin $pin = null): array
    {
        $siteId = (int) ($request->input('site_id') ?? $pin?->site_id);

        return $request->validate([
            'site_id'       => ['required', 'exists:zk_sites,id'],
            'device_pin'    => [
                'required', 'string', 'max:32',
                Rule::unique('zk_pins', 'device_pin')
                    ->where(fn ($q) => $q->where('site_id', $siteId))
                    ->ignore($pin?->id),
            ],
            'employee_name' => ['required', 'string', 'max:190'],
            'employee_ref'  => ['nullable', 'string', 'max:32'],
            'job_title'     => ['nullable', 'string', 'max:190'],
            'note'          => ['nullable', 'string', 'max:2000'],
        ], [], [
            'site_id'       => 'الموقع',
            'device_pin'    => 'رقم الجهاز',
            'employee_name' => 'اسم الموظف',
            'employee_ref'  => 'رقمه في دابوق',
            'job_title'     => 'المسمّى',
        ]) + [
            /*
             * الإضافةُ تُفعّل دائماً، والتعديلُ يتبع الخانة.
             *
             * ولا يُفرض التفعيلُ في التعديل: لو فُرض لما استطاع أحدٌ أن
             * يُعطّل رقماً أبداً. ولا يُترك لـ`boolean()` في الإضافة: النموذجُ
             * هناك بلا خانة، فتخرج `false` ويُضاف رقمٌ معطَّلٌ من حيث لا
             * يُدرى — وهو ما أخفى أسماءَ مواقعَ بأسرها عن الكشوف.
             */
            'is_active' => $pin === null ? true : $request->boolean('is_active'),
        ];
    }
}
