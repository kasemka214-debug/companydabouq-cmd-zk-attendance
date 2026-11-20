<?php

namespace App\Http\Controllers;

use App\Models\DayNote;
use App\Models\Site;
use App\Services\ShiftWatch;
use App\Support\Clock;
use App\Support\Employment;
use App\Support\ShiftJudge;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * تقريرُ الاستثناءات: الغيابُ والتأخّرُ والانصرافُ قبل إتمام الدوام — وقرارُها.
 *
 * والبرنامجُ يرصد ولا يحكم: يقول «غاب» و«جاء بعد السماح بعشرين دقيقة»، ولا
 * يعرف أكان في مراجعةِ وزارةٍ بإذن مديره أم نام. فهذه الشاشةُ موضعُ الجواب —
 * ومن غيرها يبقى الكشفُ يُخرج رجلاً مستأذِناً بالأحمر كلَّ شهر، فيفقد اللونُ
 * معناه.
 *
 * **والصفوفُ مقسومةٌ على الأشخاص لا مسرودةٌ بالتاريخ.** فالسؤالُ الذي
 * يُسأل: «فلانٌ كم غاب وكم تأخّر؟» — لا «ماذا جرى يوم الثلاثاء». وسردٌ
 * بالتاريخ يُجبر قارئَه أن يجمع أيامَ الرجل بعينه من عشرة مواضع.
 *
 * **وتبويبان لا واحد**: «الاستثناءات» وهي ما يُسأل عنه ويُبتّ فيه،
 * و«الإجازات المسجَّلة» وهي ما جوابُه مكتوبٌ سلفاً في ورقةٍ موقَّعة —
 * وسببُ الفصل في `split()`.
 */
class DayNoteController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:notes.view',   only: ['index', 'export']),
            new Middleware('can:page.print',   only: ['export']),
            new Middleware('can:notes.manage', only: ['store', 'destroy']),
        ];
    }

    public function index(Request $request, ShiftWatch $watch)
    {
        $f = $this->filters($request);

        /* الرصدُ مرّةً واحدة — يمرّ بنبضات المدّة وبكشف الغياب، فلا يُكرَّر */
        $all = $watch->flagged($f['from'], $f['to'], $f['site_id'] ?: null, $f['pin']);

        [$leaves, $exceptions] = $this->split($all);

        /* وعددُ التبويب الآخرِ يُحسب دائماً — الشارةُ على رأسه تقول ما فيه */
        $base = $f['tab'] === 'leave' ? $leaves : $exceptions;
        $rows = $this->sift($base, $f);

        return view('dayNotes.index', [
            'groups'  => $this->byPerson($rows),
            'sites'   => Site::orderBy('name')->get(),
            'f'       => $f,
            'tabs'    => ['exceptions' => $exceptions->count(), 'leave' => $leaves->count()],
            'total'   => $base->count(),
            'shown'   => $rows->count(),
            'pending' => $exceptions->filter(fn (array $r) => ShiftWatch::awaiting($r))->count(),
        ]);
    }

    /** التصديرُ يتبع الشاشة: التبويبُ نفسُه والترشيحُ نفسُه والترتيبُ نفسُه. */
    public function export(Request $request, ShiftWatch $watch)
    {
        $f = $this->filters($request);

        [$leaves, $exceptions] = $this->split(
            $watch->flagged($f['from'], $f['to'], $f['site_id'] ?: null, $f['pin'])
        );

        $onLeave = $f['tab'] === 'leave';
        $rows    = $this->sift($onLeave ? $leaves : $exceptions, $f);
        $sites   = Site::pluck('name', 'id');

        $out = [];
        $n   = 0;

        foreach ($this->byPerson($rows) as $g) {
            foreach ($g['rows'] as $r) {
                $note = $r['note'];
                $n++;

                /* ستُّ خاناتٍ يشترك فيها التبويبان: من هو، وفي أيّ يوم */
                $who = [
                    $n,
                    $sites[$r['site_id']] ?? '',
                    $r['employee'] ?? '— غير مربوط —',
                    $r['device_pin'],
                    $r['job_title'] ?? '',
                    $r['work_date'],
                ];

                $out[] = $onLeave
                    ? array_merge($who, [
                        Employment::kind($r['leave']['kind'] ?? null),
                        $r['leave']['from'] ?? '',
                        $r['leave']['to'] ?? '',
                    ])
                    : array_merge($who, [
                        ShiftWatch::kindLabel(ShiftWatch::kindOf($r)),
                        $r['first_time'],
                        $r['last_time'],
                        $r['due_out'],
                        $r['late'] ? ShiftJudge::hhmm($r['late']) : '',
                        $r['short'] ? ShiftJudge::hhmm($r['short']) : '',
                        $this->decisionLabel($r),
                        $note?->reason ?? '',
                        $note?->note ?? '',
                        $note?->decider?->name ?? '',
                    ]);
            }
        }

        $who = ['م', 'الموقع', 'الموظف', 'الرقم', 'المسمّى', 'اليوم'];

        return $onLeave
            ? $this->csv(
                "الإجازات-{$f['from']}-إلى-{$f['to']}.csv",
                array_merge($who, ['نوعُ الإجازة', 'من', 'إلى']),
                $out,
            )
            : $this->csv(
                "الاستثناءات-{$f['from']}-إلى-{$f['to']}.csv",
                array_merge($who, ['النوع', 'الدخول', 'الخروج', 'المطلوب خروجاً', 'التأخّر',
                    'النقص', 'القرار', 'السبب', 'ملاحظة', 'من بتَّ']),
                $out,
            );
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
             * يُسأل عنه بعد شهرين: من أذِن؟ ولِمَ؟ — ورقةٌ يُحتجّ بها عند
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

    /* ── المشترك ─────────────────────────────────────────────────────── */

    /**
     * الإجازاتُ في كفّةٍ وما يُسأل عنه في كفّة.
     *
     * فالإجازةُ المسجَّلةُ ليست استثناءً يُنتظر فيه قرار — جوابُها مكتوبٌ
     * سلفاً في ورقةٍ موقَّعة، وإنما تُعرض ليُعلم أن اليومَ محسوبٌ لا منسيّ.
     * ومزجُها بالغياب يُثقل الورقةَ بما لا يُبتّ فيه، ويُوهم قارئَها أن على
     * الرجل خمسةَ عشرَ استثناءً — وإنما عليه ثلاثةٌ وله اثنتا عشرةَ إجازةً
     * بإذنٍ موقَّع.
     *
     * @param  Collection<int, array<string, mixed>>  $all
     * @return array{0: Collection<int, array<string, mixed>>, 1: Collection<int, array<string, mixed>>}
     */
    private function split(Collection $all): array
    {
        $isLeave = fn (array $r) => ShiftWatch::kindOf($r) === 'leave';

        return [$all->filter($isLeave)->values(), $all->reject($isLeave)->values()];
    }

    /**
     * الترشيحُ بعد الرصد: النوعُ والحالةُ صفتان محسوبتان لا عمودان يُستعلم بهما.
     *
     * **ولا يمرّ به تبويبُ الإجازات**: لا نوعَ يُسأل عنه فيه ولا حالةَ بتّ،
     * و`filters()` تُطفئ المفتاحين هناك.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sift(Collection $rows, array $f): Collection
    {
        return $rows
            ->filter(function (array $r) use ($f) {
                $kind = ShiftWatch::kindOf($r);

                $byKind = match ($f['kind']) {
                    'absent' => $kind === 'absent',
                    'late'   => in_array($kind, ['late', 'both'], true),
                    'short'  => in_array($kind, ['short', 'both'], true),
                    default  => true,
                };

                $byState = match ($f['state']) {
                    'pending'   => ShiftWatch::awaiting($r),
                    'permitted' => ($r['note'] ?? null)?->permitted === true,
                    'denied'    => ($r['note'] ?? null)?->permitted === false,
                    default     => true,
                };

                return $byKind && $byState;
            })
            ->values();
    }

    /**
     * القسمةُ على الأشخاص: موقعٌ ورقمٌ شخصٌ واحد — فـ«١٤» في الأحمدي غيرُ
     * «١٤» في الجهراء، وجمعُهما يخلط رجلين في سطرٍ واحد.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function byPerson(Collection $rows): array
    {
        $sites  = Site::pluck('name', 'id');
        $groups = [];
        $uid    = 0;

        foreach ($rows as $r) {
            $key = $r['site_id'].'|'.$r['device_pin'];

            $groups[$key] ??= [
                'site_id'   => $r['site_id'],
                'site_name' => $sites[$r['site_id']] ?? '—',
                'pin'       => $r['device_pin'],
                'name'      => $r['employee'],
                'job'       => $r['job_title'] ?? null,
                'rows'      => [],
                'tally'     => ['absent' => 0, 'leave' => 0, 'late' => 0, 'short' => 0, 'pending' => 0],
            ];

            $kind = ShiftWatch::kindOf($r);

            /* ويومٌ يجمع تأخّراً ونقصاً يُعدّ في البابين — فكلاهما وقع فيه */
            foreach ($kind === 'both' ? ['late', 'short'] : [$kind] as $k) {
                $groups[$key]['tally'][$k]++;
            }

            if (ShiftWatch::awaiting($r)) {
                $groups[$key]['tally']['pending']++;
            }

            $groups[$key]['rows'][] = $r + ['uid' => $uid++, 'exception' => $kind];
        }

        /* الأكثرُ استثناءً أوّلاً — فالورقةُ تُقرأ من أعلاها */
        usort($groups, fn ($a, $b) => count($b['rows']) <=> count($a['rows']));

        return $groups;
    }

    private function decisionLabel(array $row): string
    {
        return $row['note']?->statusLabel() ?? 'لم يُبتّ فيه';
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

        /*
         * والتبويبُ يَحكم على الترشيح: في تبويب الإجازات لا نوعَ يُسأل عنه
         * ولا حالةَ بتّ، فيُطفأ المفتاحان صراحةً. وبغير هذا يحمل المستخدمُ
         * معه `kind=absent` من التبويب الأوّل فتخرج صفحةٌ فارغةٌ بلا سببٍ
         * ظاهر — وحقلا الترشيح مخفيّان هناك فلا يُرى من أين فرغت.
         */
        $tab = $request->input('tab') === 'leave' ? 'leave' : 'exceptions';

        return [
            'from'    => $from,
            'to'      => $to,
            'tab'     => $tab,
            'site_id' => (int) $request->input('site_id', 0),
            'pin'     => trim((string) $request->input('pin', '')),
            'state'   => $tab !== 'leave'
                && in_array($request->input('state'), ['pending', 'permitted', 'denied'], true)
                ? $request->input('state')
                : 'all',
            'kind'    => $tab !== 'leave'
                && in_array($request->input('kind'), ['absent', 'late', 'short'], true)
                ? $request->input('kind')
                : 'all',
        ];
    }
}
