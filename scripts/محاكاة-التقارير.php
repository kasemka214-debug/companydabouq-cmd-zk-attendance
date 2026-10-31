<?php

/**
 * محاكاةُ حساب التقارير: أيامُ الدوام، والمطلوبُ من الساعات، والفرق.
 *
 * وهذا أخطرُ حسابٍ في البرنامج: رقمُ «الفرق» يُقرأ على أنه دَينٌ على
 * الموظف أو له، ويومٌ يُعدّ خطأً يُزحزح المطلوبَ ثماني ساعات فيظهر الخللُ
 * على كلِّ سطرٍ في الكشف — ولا يُكتشف إلا حين يُنازَع في راتب.
 *
 *     C:\xampp\php\php.exe scripts/محاكاة-التقارير.php
 */

declare(strict_types=1);

/* ─── تقويمُ الشهر كما في Holiday::calendar، حرفاً بحرف ─── */

function isFriday(DateTimeImmutable $d): bool
{
    return (int) $d->format('w') === 5;   // الأحد 0 … الجمعة 5
}

/**
 * @param  array<int, string>  $chosen
 * @return array{dates: array<string, string>, working: int, weekend: int, holiday: int}
 */
function calendar(string $from, string $to, array $chosen = []): array
{
    $dates = [];
    $weekend = $holiday = $working = 0;

    $cursor = new DateTimeImmutable($from);
    $end    = new DateTimeImmutable($to);

    while ($cursor <= $end) {
        $key = $cursor->format('Y-m-d');

        if (isFriday($cursor)) {
            $kind = 'weekend';
            $weekend++;
        } elseif (in_array($key, $chosen, true)) {
            $kind = 'holiday';
            $holiday++;
        } else {
            $kind = 'working';
            $working++;
        }

        $dates[$key] = $kind;
        $cursor = $cursor->modify('+1 day');
    }

    return compact('dates', 'working', 'weekend', 'holiday');
}

function hhmm(?int $minutes, string $zero = '—'): string
{
    if ($minutes === null || $minutes === 0) {
        return $zero;
    }

    $sign = $minutes < 0 ? '−' : '';
    $abs  = abs($minutes);

    return $sign.intdiv($abs, 60).':'.str_pad((string) ($abs % 60), 2, '0', STR_PAD_LEFT);
}

/* ─── الفحص ─── */

$pass = 0;
$fail = 0;

function check(string $what, $got, $want): void
{
    global $pass, $fail;

    $ok = $got === $want;
    $ok ? $pass++ : $fail++;

    printf("%s %-52s %s%s\n", $ok ? '  ✓' : '  ✗', $what,
        is_scalar($got) || $got === null ? var_export($got, true) : gettype($got),
        $ok ? '' : '   ← المتوقَّع: '.var_export($want, true));
}

echo "\n─── ١) الجمعةُ تُحسب من التاريخ ولا تُخزَّن ───\n";

/* سبتمبر ٢٠٢٦: ٣٠ يوماً، وأوّلُه ثلاثاء. جُمَعُه: ٤ و١١ و١٨ و٢٥ */
$sep = calendar('2026-09-01', '2026-09-30');

check('أيامُ الشهر',      count($sep['dates']), 30);
check('عددُ الجُمَع',     $sep['weekend'], 4);
check('بلا عطلٍ مختارة',  $sep['holiday'], 0);
check('أيامُ الدوام',     $sep['working'], 26);
check('الرابعُ جمعة',     $sep['dates']['2026-09-04'], 'weekend');
check('والخامسُ دوام',    $sep['dates']['2026-09-05'], 'working');

echo "\n─── ٢) مثالُ صاحب النظام: ٢٦ يوماً × ٨ = ٢٠٨ ساعة ───\n";

$target = (int) round($sep['working'] * 8 * 60);

check('المطلوبُ بالدقائق', $target, 12480);
check('وبالساعات',         hhmm($target), '208:00');

echo "\n─── ٣) العطلُ المختارة تُنقص، والجمعةُ لا تُحتسب مرّتين ───\n";

/* عطلتان: واحدةٌ في يوم دوام، وأخرى تصادف جمعةً */
$withHolidays = calendar('2026-09-01', '2026-09-30', ['2026-09-10', '2026-09-18']);

check('الجُمَعُ كما هي',            $withHolidays['weekend'], 4);
check('والعطلةُ المحسوبة واحدة',   $withHolidays['holiday'], 1);
check('أيامُ الدوام نقصت يوماً',   $withHolidays['working'], 25);
check('والمطلوبُ ٢٠٠ ساعة',        hhmm((int) round($withHolidays['working'] * 8 * 60)), '200:00');

/*
 * والعطلةُ التي تصادف جمعةً لا تُنقص يوماً ثانياً — وهذا موضعُ خطأٍ
 * مألوف: تُطرح الجمعةُ ثم تُطرح العطلةُ نفسُها، فينقص المطلوبُ ثماني
 * ساعاتٍ على كلِّ موظف.
 */
check('الثامنَ عشرَ بقي جمعةً لا عطلة', $withHolidays['dates']['2026-09-18'], 'weekend');

echo "\n─── ٤) صياغةُ الساعات ───\n";

check('ثماني ساعاتٍ تامة',   hhmm(480), '8:00');
check('ثمانٍ وإحدى عشرة',    hhmm(491), '8:11');
check('سبعٌ وخمسٌ وخمسون',   hhmm(475), '7:55');
check('صفرٌ بلا قيمة',       hhmm(0), '—');
check('وصفرٌ بقيمةٍ مطلوبة', hhmm(0, '0:00'), '0:00');
check('والسالبُ بعلامته',    hhmm(-125), '−2:05');
check('ومئتان وثمانٍ',       hhmm(12480), '208:00');

echo "\n─── ٥) الفرقُ عن المطلوب ───\n";

$who = [
    'مَن أتمّ'      => [12480, '0:00'],
    'مَن زاد ساعة'  => [12540, '1:00'],
    'مَن نقص ساعة'  => [12420, '−1:00'],
    'مَن غاب شهراً' => [0,     '−208:00'],
];

foreach ($who as $label => [$total, $want]) {
    check($label, hhmm($total - $target, '0:00'), $want);
}

echo "\n─── ٦) شهرٌ آخرُ يبدأ بجمعة ───\n";

/* مايو ٢٠٢٦: ٣١ يوماً، أوّلُه جمعة. جُمَعُه: ١ و٨ و١٥ و٢٢ و٢٩ */
$may = calendar('2026-05-01', '2026-05-31');

check('أيامُ الشهر',   count($may['dates']), 31);
check('خمسُ جُمَع',    $may['weekend'], 5);
check('أيامُ الدوام',  $may['working'], 26);
check('الأوّلُ جمعة',  $may['dates']['2026-05-01'], 'weekend');

echo "\n─── ٧) الأسبوع: من السبت إلى الجمعة ───\n";

$week = calendar('2026-09-26', '2026-10-02');

check('سبعةُ أيام',       count($week['dates']), 7);
check('جمعةٌ واحدة',      $week['weekend'], 1);
check('ستةُ أيام دوام',   $week['working'], 6);
check('والمطلوبُ ٤٨ ساعة', hhmm((int) round($week['working'] * 8 * 60)), '48:00');

echo "\n─── ٨) ترتيبُ البصمة: دخولٌ ثم خروج ───\n";

$label = fn (int $seq) => ($seq % 2 === 1) ? 'دخول' : 'خروج';

check('الأولى دخول',  $label(1), 'دخول');
check('الثانية خروج', $label(2), 'خروج');
check('الثالثة دخول', $label(3), 'دخول');
check('الرابعة خروج', $label(4), 'خروج');
check('العاشرة خروج', $label(10), 'خروج');

echo "\n".str_repeat('─', 72)."\n";
printf("  نجح %d، وفشل %d.\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
