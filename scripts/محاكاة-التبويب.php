<?php

/**
 * محاكاةُ تبويب التقرير: الإجازاتُ في كفّةٍ وما يُسأل عنه في كفّة.
 *
 * والفحصُ الأوّلُ هنا — كما في محاكاة القسمة — **ألّا يسقط صفّ**. فالقسمةُ
 * على تبويبين تُوزّع المرصودَ على كفّتين، وصفٌّ لا يقع في كفّةٍ يختفي من
 * الورقتين معاً: لا يُرى في الاستثناءات ولا في الإجازات، فلا يُبتّ فيه
 * أبداً ولا يُدرى أنه كان.
 *
 * والثاني أن الترشيحَ القديم لا يبقى حاكماً بعد التبويب: كان «الغياب
 * وحدَه» يجمع الغيابَ والإجازة، وكانت «بإذن» تجرّ كلَّ إجازةٍ مسجَّلة —
 * وذاك صوابٌ يومَ كانا في ورقةٍ واحدة، وخطأٌ بعد أن صار للإجازة تبويبُها.
 *
 * **ويُشغَّل بلا `vendor` ولا قاعدةِ بيانات**، ويستدعي المتحكّمَ نفسَه لا
 * نسخةً من منطقه: لارافيل يُستعاض عنه بأصنافٍ صغيرةٍ هنا، والدوالُّ
 * الخاصّةُ تُنادى بالانعكاس. فما يُفحص هو الشيفرةُ التي تعمل في الإنتاج.
 *
 *     C:\xampp\php\php.exe scripts/محاكاة-التبويب.php
 */

declare(strict_types=1);

/* ─── بديلُ مجموعة لارافيل: ما يستعمله المتحكّمُ منها لا أكثر ──────── */

namespace Illuminate\Support {

    class Collection implements \IteratorAggregate, \Countable
    {
        public function __construct(private array $items = []) {}

        /** ويُبقي المفاتيحَ كما تُبقيها لارافيل — فـ`values()` ليست زينة */
        public function filter(callable $fn): self
        {
            return new self(array_filter($this->items, $fn));
        }

        public function reject(callable $fn): self
        {
            return new self(array_filter($this->items, fn ($i) => ! $fn($i)));
        }

        public function values(): self
        {
            return new self(array_values($this->items));
        }

        public function count(): int
        {
            return count($this->items);
        }

        public function all(): array
        {
            return $this->items;
        }

        public function getIterator(): \ArrayIterator
        {
            return new \ArrayIterator($this->items);
        }
    }

    /** لا يُنادى في هذه المحاكاة — الحفظُ وحدَه يستعمله */
    class Carbon {}
}

namespace Illuminate\Routing\Controllers {

    interface HasMiddleware {}

    class Middleware
    {
        public function __construct(public string $ability, public array $only = []) {}
    }
}

namespace Illuminate\Http {

    class Request
    {
        public function __construct(private array $in = []) {}

        public function input(string $key, $default = null)
        {
            return $this->in[$key] ?? $default;
        }
    }
}

namespace App\Models {

    /** أسماءُ المواقع كما تقرؤها `byPerson` — مصفوفةٌ تكفي مكان المجموعة */
    class Site
    {
        public static array $names = [];

        public static function pluck(string $value, string $key): array
        {
            return self::$names;
        }
    }

    class DayNote {}
}

namespace {

    function config(string $key, $default = null)
    {
        return $default;
    }

    require __DIR__.'/../app/Support/AbsenceJudge.php';
    require __DIR__.'/../app/Support/Employment.php';
    require __DIR__.'/../app/Support/ShiftJudge.php';
    require __DIR__.'/../app/Services/ShiftWatch.php';
    require __DIR__.'/../app/Http/Controllers/Controller.php';
    require __DIR__.'/../app/Http/Controllers/DayNoteController.php';

    use App\Http\Controllers\DayNoteController;
    use App\Models\Site;
    use App\Services\ShiftWatch;
    use App\Support\AbsenceJudge;
    use Illuminate\Http\Request;
    use Illuminate\Support\Collection;

    Site::$names = [1 => 'المرقاب'];

    $pass = 0;
    $fail = 0;

    function check(string $title, $expected, $actual): void
    {
        global $pass, $fail;

        $ok = $expected === $actual;
        $ok ? $pass++ : $fail++;

        printf("  %s  %-50s المنتظر: %-16s والخارج: %s\n", $ok ? '✓' : '✗', $title,
            is_array($expected) ? json_encode($expected, JSON_UNESCAPED_UNICODE) : var_export($expected, true),
            is_array($actual) ? json_encode($actual, JSON_UNESCAPED_UNICODE) : var_export($actual, true));
    }

    /* ─── عدّةُ النداء: الدوالُّ خاصّةٌ فتُنادى بالانعكاس ──────────────── */

    $ctl = new DayNoteController();

    $call = function (string $method, ...$args) use ($ctl) {
        $m = new ReflectionMethod($ctl, $method);
        $m->setAccessible(true);

        return $m->invokeArgs($ctl, $args);
    };

    /** قرارٌ مكتوبٌ على يوم — ما يقرؤه الترشيحُ منه: `permitted` */
    function note(bool $permitted): object
    {
        return new class($permitted)
        {
            public function __construct(public bool $permitted) {}

            public function statusLabel(): string
            {
                return $this->permitted ? 'بإذن' : 'بلا إذن';
            }
        };
    }

    /** صفٌّ كما يبنيه `ShiftWatch::flagged()` — بمفاتيحه كلِّها */
    function row(string $pin, string $date, string $kind, int $late = 0, int $short = 0,
        ?object $note = null, ?array $leave = null): array
    {
        return [
            'site_id'    => 1,
            'device_pin' => $pin,
            'employee'   => $pin === '14' ? 'سالم' : 'مبارك',
            'job_title'  => 'نجّار',
            'work_date'  => $date,
            'first_time' => '—',
            'last_time'  => '—',
            'due_out'    => '—',
            'kind'       => $kind,
            'late'       => $late,
            'short'      => $short,
            'flagged'    => true,
            'leave'      => $leave,
            'note'       => $note,
        ];
    }

    $period = ['kind' => 'annual', 'from' => '2026-11-08', 'to' => '2026-11-14'];

    $all = new Collection([
        row('14', '2026-11-10', AbsenceJudge::FULL, late: 20),
        row('14', '2026-11-11', AbsenceJudge::FULL, short: 45, note: note(true)),
        row('14', '2026-11-12', AbsenceJudge::FULL, late: 10, short: 30),
        row('14', '2026-11-13', AbsenceJudge::ABSENT),
        row('22', '2026-11-10', AbsenceJudge::ON_LEAVE, leave: $period),
        row('22', '2026-11-11', AbsenceJudge::ON_LEAVE, leave: $period),
        row('22', '2026-11-12', AbsenceJudge::ABSENT, note: note(false)),
    ]);

    $line = str_repeat('─', 84);

    echo "\n$line\n  القسمةُ على التبويبين — سبعةُ صفوفٍ: خمسةُ استثناءاتٍ وإجازتان\n$line\n\n";

    [$leaves, $exceptions] = $call('split', $all);

    check('الإجازاتُ في كفّتها', 2, $leaves->count());
    check('وما يُسأل عنه في كفّته', 5, $exceptions->count());

    /* وهذا هو الحارسُ: لا صفَّ يسقط بين الكفّتين */
    check('ولا صفَّ يسقط', $all->count(), $leaves->count() + $exceptions->count());

    check('وكفّةُ الإجازات إجازاتٌ كلُّها', ['leave', 'leave'],
        array_map(fn (array $r) => ShiftWatch::kindOf($r), $leaves->all()));

    check('ولا إجازةَ في كفّة الاستثناءات', ['late', 'short', 'both', 'absent', 'absent'],
        array_map(fn (array $r) => ShiftWatch::kindOf($r), $exceptions->all()));

    echo "\n$line\n  الترشيحُ بعد التبويب — ومفاتيحُه ما كانت تجرّ الإجازات\n$line\n\n";

    /** الترشيحُ بمفاتيحَ تُقرأ من الطلب، كما تُقرأ في الإنتاج */
    $sift = function (Collection $rows, array $query) use ($call) {
        $f = $call('filters', new Request($query + ['from' => '2026-11-01', 'to' => '2026-11-30']));

        return $call('sift', $rows, $f);
    };

    /* «الغياب وحدَه» كان يجمع الغيابَ والإجازة — واليومَ الغيابُ وحدَه */
    check('«الغياب وحدَه» ⇐ غيابان لا أربعة', 2, $sift($exceptions, ['kind' => 'absent'])->count());
    check('«التأخّر وحدَه» يشمل من تأخّر ونقص', 2, $sift($exceptions, ['kind' => 'late'])->count());
    check('و«الانصراف قبل الإتمام» كذلك', 2, $sift($exceptions, ['kind' => 'short'])->count());

    /* و«بإذن» كانت تجرّ كلَّ إجازةٍ مسجَّلة — واليومَ هي القرارُ المكتوب */
    check('«بإذن» ⇐ قرارٌ واحدٌ مكتوب', 1, $sift($exceptions, ['state' => 'permitted'])->count());
    check('«بلا إذن» ⇐ واحد', 1, $sift($exceptions, ['state' => 'denied'])->count());
    check('«ما لم يُبتّ فيه» ⇐ ثلاثة', 3, $sift($exceptions, ['state' => 'pending'])->count());

    /*
     * وتبويبُ الإجازات لا يُرشَّح: مفتاحٌ محمولٌ من التبويب الأوّل كان
     * سيُفرغ الصفحةَ وحقلُه مخفيٌّ فلا يُرى من أين فرغت.
     */
    check('إجازتان ولو حُمل معهما «الغياب وحدَه»', 2,
        $sift($leaves, ['tab' => 'leave', 'kind' => 'absent', 'state' => 'pending'])->count());

    echo "\n$line\n  مفتاحُ التبويب\n$line\n\n";

    $tab = fn (array $q) => $call('filters', new Request($q + ['from' => '2026-11-01', 'to' => '2026-11-30']))['tab'];

    check('بلا مفتاحٍ ⇐ الاستثناءات', 'exceptions', $tab([]));
    check('ومفتاحٌ لا يُعرف ⇐ الاستثناءات', 'exceptions', $tab(['tab' => 'xyz']));
    check('و«leave» ⇐ الإجازات', 'leave', $tab(['tab' => 'leave']));

    $f = $call('filters', new Request(['from' => '2026-11-01', 'to' => '2026-11-30',
        'tab' => 'leave', 'kind' => 'absent', 'state' => 'pending']));

    check('ويُطفئ النوعَ في الإجازات', 'all', $f['kind']);
    check('ويُطفئ حالةَ البتّ', 'all', $f['state']);

    echo "\n$line\n  القسمةُ على الأشخاص في كلّ تبويب\n$line\n\n";

    $groups = $call('byPerson', $exceptions);

    check('شخصان في الاستثناءات', 2, count($groups));
    check('والأكثرُ استثناءً أوّلاً', '14', $groups[0]['pin']);
    check('وحصيلتُه: غيابٌ وتأخّرانِ ونقصان وثلاثةٌ لم يُبتّ فيها',
        ['absent' => 1, 'leave' => 0, 'late' => 2, 'short' => 2, 'pending' => 3],
        $groups[0]['tally']);

    $onLeave = $call('byPerson', $leaves);

    check('وشخصٌ واحدٌ في الإجازات', 1, count($onLeave));
    check('ويومان لا يُنتظر فيهما قرار',
        ['absent' => 0, 'leave' => 2, 'late' => 0, 'short' => 0, 'pending' => 0],
        $onLeave[0]['tally']);

    /* والمدّةُ تُطوى في القالب على مفتاحها: نوعٌ ومن وإلى */
    check('ومدّةُ الإجازة واحدةٌ في اليومين', 1, count(array_unique(array_map(
        fn (array $r) => $r['leave']['kind'].'|'.$r['leave']['from'].'|'.$r['leave']['to'],
        $onLeave[0]['rows']))));

    echo "\n$line\n";
    printf("  نجح %d، وفشل %d.\n", $pass, $fail);
    echo "$line\n\n";

    exit($fail === 0 ? 0 : 1);
}
