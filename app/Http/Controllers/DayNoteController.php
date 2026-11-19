<?php

namespace App\Http\Controllers;

use App\Models\DayNote;
use App\Models\Site;
use App\Services\ShiftWatch;
use App\Support\Clock;
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
        $all  = $watch->flagged($f['from'], $f['to'], $f['site_id'] ?: null, $f['pin']);
        $rows = $this->sift($all, $f);

        return view('dayNotes.index', [
            'groups'  => $this->byPerson($rows),
            'sites'   => Site::orderBy('name')->get(),
            'f'       => $f,
            'total'   => $all->count(),
            'shown'   => $rows->count(),
            'pending' => $all->filter(fn (array $r) => ShiftWatch::awaiting($r))->count(),
        ]);
    }

    /** التصديرُ يتبع الشاشة: الترشيحُ نفسُه والترتيبُ نفسُه. */
    public function export(Request $request, ShiftWatch $watch)
    {
        $f     = $this->filters($request);
        $rows  = $this->sift($watch->flagged($f['from'], $f['to'], $f['site_id'] ?: null, $f['pin']), $f);
        $sites = Site::pluck('name', 'id');

        $out = [];
        $n   = 0;

        foreach ($this->byPerson($rows) as $g) {
            foreach ($g['rows'] as $r) {
                $note = $r['note'];
                $n++;

                $out[] = [
                    $n,
                    $sites[$r['site_id']] ?? '',
                    $r['employee'] ?? '— غير مربوط —',
                    $r['device_pin'],
                    $r['job_title'] ?? '',
                    $r['work_date'],
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
                ];
            }
        }

        return $this->csv(
            "الاستثناءات-{$f['from']}-إلى-{$f['to']}.csv",
            ['م', 'الموقع', 'الموظف', 'الرقم', 'المسمّى', 'اليوم', 'النوع', 'الدخول', 'الخروج',
                'المطلوب خروجاً', 'التأخّر', 'النقص', 'القرار', 'السبب', 'ملاحظة', 'من بتَّ'],
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
     * الترشيحُ بعد الرصد: النوعُ والحالةُ صفتان محسوبتان لا عمودان يُستعلم بهما.
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
                    'absent' => in_array($kind, ['absent', 'leave'], true),
                    'late'   => in_array($kind, ['late', 'both'], true),
                    'short'  => in_array($kind, ['short', 'both'], true),
                    default  => true,
                };

                $byState = match ($f['state']) {
                    'pending'   => ShiftWatch::awaiting($r),
                    'permitted' => ($r['note'] ?? null)?->permitted === true || $kind === 'leave',
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
        if (ShiftWatch::kindOf($row) === 'leave') {
            return 'بإذن — إجازةٌ مسجَّلة';
        }

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

        return [
            'from'    => $from,
            'to'      => $to,
            'site_id' => (int) $request->input('site_id', 0),
            'pin'     => trim((string) $request->input('pin', '')),
            'state'   => in_array($request->input('state'), ['pending', 'permitted', 'denied'], true)
                ? $request->input('state')
                : 'all',
            'kind'    => in_array($request->input('kind'), ['absent', 'late', 'short'], true)
                ? $request->input('kind')
                : 'all',
        ];
    }
}
