<?php

/**
 * محاكاةُ قسمة كشف الأيام على المواقع.
 *
 * والغرضُ فحصٌ واحدٌ قبل كلِّ شيء: **ألّا يسقط صفٌّ**. فالقسمةُ تُوزّع
 * الصفوفَ على أقسام، وأيُّ صفٍّ لا يقع في قسمٍ يختفي من الكشف بصمت —
 * ولا يُكتشف إلا حين يُسأل عاملٌ عن غيابٍ لم يغِبه.
 *
 *     C:\xampp\php\php.exe scripts/محاكاة-القسمة.php
 */

declare(strict_types=1);

/* ─── بديلٌ صغيرٌ لمجموعة لارافيل: ما تستعمله القسمةُ منها لا أكثر ─── */

final class Coll implements Countable
{
    public function __construct(private array $items = []) {}

    public function where(string $key, $value): self
    {
        return new self(array_values(array_filter($this->items, fn ($i) => ($i[$key] ?? null) === $value)));
    }

    public function whereNull(string $key): self
    {
        return new self(array_values(array_filter($this->items, fn ($i) => ($i[$key] ?? null) === null)));
    }

    public function reject(callable $fn): self
    {
        return new self(array_values(array_filter($this->items, fn ($i) => ! $fn($i))));
    }

    public function pluck(string $key): self
    {
        return new self(array_map(fn ($i) => $i[$key] ?? null, $this->items));
    }

    public function unique(): self
    {
        return new self(array_values(array_unique($this->items, SORT_REGULAR)));
    }

    public function values(): self { return new self(array_values($this->items)); }
    public function isNotEmpty(): bool { return $this->items !== []; }
    public function count(): int { return count($this->items); }
    public function all(): array { return $this->items; }
}

/* ─── القسمةُ كما هي في DayController، حرفاً بحرف ─── */

function summarise(string $name, Coll $rows): array
{
    return [
        'name'     => $name,
        'rows'     => $rows,
        'people'   => $rows->pluck('device_pin')->unique()->count(),
        'complete' => $rows->where('complete', true)->count(),
        'partial'  => $rows->where('complete', false)->count(),
        'unlinked' => $rows->whereNull('employee')->count(),
    ];
}

function groupBySite(Coll $days, array $sites): array
{
    $groups = [];
    $taken  = 0;

    foreach ($sites as $site) {
        $rows = $days->where('site_id', $site['id'])->values();

        if ($rows->isNotEmpty()) {
            $groups[] = summarise($site['name'], $rows);
            $taken += $rows->count();
        }
    }

    if ($taken < $days->count()) {
        $known = array_column($sites, 'id');

        $loose = $days->reject(
            fn (array $d) => $d['site_id'] !== null && in_array($d['site_id'], $known, true)
        )->values();

        $groups[] = summarise('بلا موقع', $loose);
    }

    return $groups;
}

/* ─── الفحص ─── */

$pass = 0;
$fail = 0;

function check(string $what, $got, $want): void
{
    global $pass, $fail;

    $ok = $got === $want;
    $ok ? $pass++ : $fail++;

    printf("%s %-54s %s%s\n", $ok ? '  ✓' : '  ✗', $what,
        var_export($got, true), $ok ? '' : '   ← المتوقَّع: '.var_export($want, true));
}

function day(?int $site, string $pin, bool $complete = true, ?string $emp = 'اسم'): array
{
    return ['site_id' => $site, 'device_pin' => $pin, 'complete' => $complete, 'employee' => $emp];
}

$sites = [
    ['id' => 1, 'name' => 'الاعاقة'],
    ['id' => 2, 'name' => '3مساجد'],
];

echo "\n─── ١) القسمةُ العادية ───\n";

$days = new Coll([
    day(1, '14'), day(1, '21'), day(1, '14', false),
    day(2, '5001'), day(2, '5002'),
]);

$g = groupBySite($days, $sites);

check('قسمان',                 count($g), 2);
check('الأول «الاعاقة»',       $g[0]['name'], 'الاعاقة');
check('وفيه ثلاثةُ أيام',      $g[0]['rows']->count(), 3);
check('وشخصان',                $g[0]['people'], 2);
check('تامّان',                $g[0]['complete'], 2);
check('وناقصٌ واحد',           $g[0]['partial'], 1);
check('الثاني «3مساجد»',       $g[1]['name'], '3مساجد');
check('وفيه يومان',            $g[1]['rows']->count(), 2);

echo "\n─── ٢) لا يسقط صفٌّ مهما كان موقعُه ───\n";

$mixed = new Coll([
    day(1, '14'),
    day(null, '99'),      // جهازٌ بلا موقع
    day(77, '55'),        // موقعٌ محذوف — وهذا هو الفخّ
    day(2, '5001'),
]);

$g2    = groupBySite($mixed, $sites);
$total = array_sum(array_map(fn ($x) => $x['rows']->count(), $g2));

check('المجموعُ يساوي المُدخَل', $total, $mixed->count());
check('قسمُ «بلا موقع» ظهر',     end($g2)['name'], 'بلا موقع');
check('وفيه الاثنان معاً',        end($g2)['rows']->count(), 2);

$loosePins = end($g2)['rows']->pluck('device_pin')->all();
sort($loosePins);
check('الجهازُ بلا موقع والموقعُ المحذوف', $loosePins, ['55', '99']);

echo "\n─── ٣) حالاتٌ طرفية ───\n";

check('كشفٌ فارغ ← بلا أقسام',   count(groupBySite(new Coll([]), $sites)), 0);
check('بلا مواقعَ مُعرَّفة ← قسمٌ جامع',
    count(groupBySite(new Coll([day(1, '14')]), [])), 1);

$allLoose = groupBySite(new Coll([day(null, '1'), day(null, '2')]), $sites);
check('كلُّه بلا موقع ← قسمٌ واحد', count($allLoose), 1);
check('وفيه الصفّان',               $allLoose[0]['rows']->count(), 2);

echo "\n─── ٤) «بلا اسم» يُحصى ───\n";

$unl = groupBySite(new Coll([
    day(1, '14', true, null),
    day(1, '21', true, 'أحمد'),
    day(1, '33', true, null),
]), $sites);

check('اثنان بلا اسم', $unl[0]['unlinked'], 2);
check('والأشخاصُ ثلاثة', $unl[0]['people'], 3);

echo "\n".str_repeat('─', 72)."\n";
printf("  نجح %d، وفشل %d.\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
