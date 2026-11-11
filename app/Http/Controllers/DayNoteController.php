<?php

namespace App\Http\Controllers;

use App\Models\DayNote;
use App\Models\Site;
use App\Services\ShiftWatch;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * شاشةُ التأخّر والانصراف المبكّر — ما رصده الحساب، وما بُتَّ فيه.
 *
 * والبرنامجُ يرصد ولا يحكم: يقول «جاء بعد السماح بعشرين دقيقة»، ولا يعرف
 * أكان في مراجعةِ وزارةٍ بإذن مديره أم نام. فهذه الشاشةُ موضعُ الجواب —
 * ومن غيرها يبقى الكشفُ يُخرج رجلاً مستأذِناً بالأحمر كلَّ شهر، فيفقد
 * اللونُ معناه.
 */
class DayNoteController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:notes.view',   only: ['index']),
            new Middleware('can:notes.manage', only: ['store', 'destroy']),
        ];
    }

    public function index(Request $request, ShiftWatch $watch)
    {
        $f = $this->filters($request);

        /* الرصدُ مرّةً واحدة — وهو يمرّ بنبضات المدّة كلِّها، فلا يُكرَّر لعدّاد */
        $all = $watch->flagged($f['from'], $f['to'], $f['site_id'] ?: null, $f['pin']);

        /* والحالةُ تُرشَّح بعده: القرارُ صفٌّ ملصَقٌ لا عمودٌ يُستعلم به */
        $rows = match ($f['state']) {
            'pending'   => $all->whereNull('note')->values(),
            'permitted' => $all->filter(fn ($r) => $r['note'] && $r['note']->permitted)->values(),
            'denied'    => $all->filter(fn ($r) => $r['note'] && ! $r['note']->permitted)->values(),
            default     => $all,
        };

        return view('dayNotes.index', [
            'rows'    => $rows,
            'sites'   => Site::orderBy('name')->get(),
            'f'       => $f,
            'total'   => $all->count(),
            'pending' => $all->whereNull('note')->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'site_id'    => ['required', 'integer', 'exists:zk_sites,id'],
            'device_pin' => ['required', 'string', 'max:32'],
            'work_date'  => ['required', 'date'],
            'permitted'  => ['required', 'boolean'],

            /*
             * والسببُ **مطلوبٌ مع الإذن** لا اختياريّ. فإذنٌ بلا سببٍ لا
             * يُسأل عنه بعد شهرين: من أذِن؟ ولِمَ؟ — ورقةٌ تُحتجّ بها عند
             * خلافٍ لا تُكتب بنصف خبر.
             */
            'reason'     => ['nullable', 'string', 'max:190', 'required_if:permitted,1'],
            'note'       => ['nullable', 'string', 'max:2000'],
        ], [], [
            'permitted' => 'الإذن',
            'reason'    => 'السبب',
            'note'      => 'الملاحظة',
        ]);

        DayNote::updateOrCreate(
            [
                'site_id'    => (int) $data['site_id'],
                'device_pin' => $data['device_pin'],
                'work_date'  => Carbon::parse($data['work_date'])->toDateString(),
            ],
            [
                'permitted'  => (bool) $data['permitted'],
                'reason'     => $data['permitted'] ? $data['reason'] : null,
                'note'       => $data['note'] ?? null,
                'decided_by' => $request->user()->id,
            ],
        );

        ShiftWatch::forget();

        return back()->with('ok', 'حُفظ القرار.');
    }

    public function destroy(DayNote $note)
    {
        $note->delete();

        ShiftWatch::forget();

        /* ويعود اليومُ إلى «لم يُبتّ فيه» — لا يُمحى من الرصد */
        return back()->with('ok', 'أُلغي القرار، وعاد اليومُ إلى ما لم يُبتّ فيه.');
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $to   = $request->input('to')   ?: Clock::now()->toDateString();
        $from = $request->input('from') ?: Clock::now()->subDays(29)->toDateString();

        /* «من» بعد «إلى» تُخرج كشفاً فارغاً لا خطأً — فتُقلب */
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from'    => $from,
            'to'      => $to,
            'site_id' => (int) $request->input('site_id', 0),
            'pin'     => trim((string) $request->input('pin', '')),
            'state'   => in_array($request->input('state'), ['pending', 'permitted', 'denied'], true)
                ? $request->input('state')
                : 'all',
        ];
    }
}
