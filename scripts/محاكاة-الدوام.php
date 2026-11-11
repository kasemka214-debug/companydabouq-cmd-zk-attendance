<?php

/**
 * محاكاةُ حكم الدوام: التأخّرُ، والنقصُ، والسماحُ المعوَّض.
 *
 * وهذا الحسابُ يُخصم به من أجرِ رجل، فلا يُسلَّم بقراءةِ شيفرةٍ — إنما
 * بأرقامٍ تُعرض: «جاء ٧:١٥ وخرج ٣:١٥ ⇐ لا تأخّرَ ولا نقص».
 *
 * **ويُشغَّل بلا `vendor` ولا قاعدةِ بيانات**: `ShiftJudge` صافٍ لا يعرف
 * كاربون ولا Eloquent، و`config()` يُبدَّل هنا بدالّةٍ صغيرةٍ تُرجع ما في
 * `config/zk.php` من افتراضات.
 *
 *     C:\xampp\php\php.exe scripts/محاكاة-الدوام.php
 */

declare(strict_types=1);

/* ─── بديلُ `config()`: الافتراضاتُ كما هي في config/zk.php ─────────── */

function config(string $key, $default = null)
{
    $values = [
        'app.display_timezone' => null,
        'zk.work_start'        => '07:00',
        'zk.grace_minutes'     => 15,
        'zk.daily_hours'       => 8,
    ];

    return array_key_exists($key, $values) ? ($values[$key] ?? $default) : $default;
}

require __DIR__.'/../app/Support/Clock.php';
require __DIR__.'/../app/Support/ShiftJudge.php';

use App\Support\ShiftJudge;

/* ─── عدّةُ الفحص ──────────────────────────────────────────────────── */

$pass = 0;
$fail = 0;

function check(string $title, $expected, $actual): void
{
    global $pass, $fail;

    $ok = $expected === $actual;
    $ok ? $pass++ : $fail++;

    printf(
        "  %s  %-46s المنتظر: %-22s والخارج: %s\n",
        $ok ? '✓' : '✗',
        $title,
        is_array($expected) ? json_encode($expected, JSON_UNESCAPED_UNICODE) : var_export($expected, true),
        is_array($actual) ? json_encode($actual, JSON_UNESCAPED_UNICODE) : var_export($actual, true),
    );
}

/** يومُ عملٍ بتوقيت الكويت — كما يصل من الجهاز بعد تحويله */
function kw(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time, new DateTimeZone('Asia/Kuwait'));
}

/** @return array{late: int, short: int, judged: bool, due: string} */
function judge(array $schedule, string $date, $first, $last): array
{
    $r = ShiftJudge::judge($date, $first, $last, $schedule);

    return [
        'late'   => $r['late'],
        'short'  => $r['short'],
        'judged' => $r['judged'],
        'due'    => $r['due_out']?->format('H:i') ?? '—',
    ];
}

$line = str_repeat('─', 76);

echo "\n$line\n  محاكاةُ حكم الدوام — جدولُ الموقع: ٧:٠٠ ← ٣:٠٠، سماحٌ ١٥ دقيقة\n$line\n\n";

$day   = '2026-11-11';
$shift = ['start' => '07:00', 'minutes' => 480, 'grace' => 15];

/* ١) السماحُ المعوَّض — وهو أصلُ الميزة كما طلبها صاحبُ البرنامج */
check('جاء ٧:١٥ وخرج ٣:١٥ — سماحٌ عُوّض',
    ['late' => 0, 'short' => 0, 'judged' => true, 'due' => '15:15'],
    judge($shift, $day, kw("$day 07:15"), kw("$day 15:15")));

/* ٢) تأخّرٌ بعد السماح — ولو أتمّ ساعاته */
check('جاء ٧:٣٠ وخرج ٣:٣٠ — تأخّرٌ ١٥ ولا نقص',
    ['late' => 15, 'short' => 0, 'judged' => true, 'due' => '15:30'],
    judge($shift, $day, kw("$day 07:30"), kw("$day 15:30")));

/* ٣) دخل في السماح ولم يعوّض — نقصٌ لا تأخّر */
check('جاء ٧:١٥ وخرج ٣:٠٠ — نقصٌ ١٥ ولا تأخّر',
    ['late' => 0, 'short' => 15, 'judged' => true, 'due' => '15:15'],
    judge($shift, $day, kw("$day 07:15"), kw("$day 15:00")));

/* ٤) جاء مبكّراً وخرج بقدر ما جاء — دوامُه ثمانٍ فهو سليم */
check('جاء ٦:٤٥ وخرج ٢:٤٥ — سليم',
    ['late' => 0, 'short' => 0, 'judged' => true, 'due' => '14:45'],
    judge($shift, $day, kw("$day 06:45"), kw("$day 14:45")));

/* ٥) تأخّرٌ ونقصٌ معاً */
check('جاء ٨:٠٠ وخرج ٣:٠٠ — تأخّرٌ ٤٥ ونقصٌ ٦٠',
    ['late' => 45, 'short' => 60, 'judged' => true, 'due' => '16:00'],
    judge($shift, $day, kw("$day 08:00"), kw("$day 15:00")));

/* ٦) اليومُ الناقصُ لا يُحكم عليه — بصمةٌ واحدةٌ لا تقول متى خرج */
check('بصمةٌ واحدةٌ ٧:٤٠ — لا حكم',
    ['late' => 0, 'short' => 0, 'judged' => false, 'due' => '—'],
    judge($shift, $day, kw("$day 07:40"), null));

echo "\n$line\n  الحدودُ والكسور\n$line\n\n";

/* ٧) البصمةُ على حدّ السماح تماماً داخلةٌ فيه لا خارجةٌ عنه */
check('٧:١٥:٠٠ بالضبط — داخلٌ في السماح',
    0, ShiftJudge::judge($day, kw("$day 07:15:00"), kw("$day 15:15"), $shift)['late']);

/* ٨) والكسرُ لا يُحسب دقيقةً: تسعٌ وخمسون ثانيةً بعده ليست تأخّراً */
check('٧:١٥:٥٩ — تسعٌ وخمسون ثانيةً لا تُعدّ دقيقة',
    0, ShiftJudge::judge($day, kw("$day 07:15:59"), kw("$day 15:16"), $shift)['late']);

check('٧:١٦:٠٠ — دقيقةٌ كاملةٌ تُعدّ',
    1, ShiftJudge::judge($day, kw("$day 07:16:00"), kw("$day 15:16"), $shift)['late']);

echo "\n$line\n  الطابعُ يأتي من القاعدة غرينتش — والحكمُ بتوقيت الكويت\n$line\n\n";

/*
 * ٩) الكويتُ +٣: «04:15» غرينتش هي السابعةُ والربع عندنا. ولو حُكم على
 *    الطابع كما هو لخرج الرجلُ متأخّراً ثلاثَ ساعاتٍ كلَّ يوم.
 */
check('«04:15» غرينتش = ٧:١٥ الكويت — سماحٌ لا تأخّر',
    ['late' => 0, 'short' => 0, 'judged' => true, 'due' => '15:15'],
    judge($shift, $day, "$day 04:15:00", "$day 12:15:00"));

echo "\n$line\n  الجدولُ الموروثُ والمكتوب\n$line\n\n";

/* ١٠) موقعٌ لم يُكتب له جدولٌ يرث الافتراضَ العامّ */
check('موقعٌ بلا جدول — يرث ٧:٠٠ و٤٨٠ و١٥',
    ['start' => '07:00', 'minutes' => 480, 'grace' => 15],
    ShiftJudge::schedule(null, null, null));

/* ١١) وما كتبه الموقعُ يغلب — و«07:00:00» من عمود `time` تُسوّى */
check('موقعٌ كتب ٦:٣٠ و٤٢٠ وصفراً',
    ['start' => '06:30', 'minutes' => 420, 'grace' => 0],
    ShiftJudge::schedule('06:30:00', 420, 0));

/* ١٢) جدولٌ نصفُه مكتوب: البدايةُ منه والباقي موروث */
check('بدايةٌ مكتوبةٌ وسماحٌ موروث',
    ['start' => '06:00', 'minutes' => 480, 'grace' => 15],
    ShiftJudge::schedule('06:00', null, null));

/* ١٣) «من ٧:٠٠ إلى ٣:٠٠» ⇐ ٤٨٠ دقيقة */
check('من ٠٧:٠٠ إلى ١٥:٠٠ = ٤٨٠ دقيقة', 480, ShiftJudge::minutesBetween('07:00', '15:00'));

/*
 * ١٤) والنهايةُ قبل البداية مناوبةٌ ليلية.
 *
 *     وتنبيهٌ لمن يُفعّلها: تجميعُ النبضات في يومِ عملٍ واحدٍ يتبع
 *     `zk.day_starts_at` (الرابعةَ فجراً افتراضاً)، فمناوبةٌ تنتهي
 *     السادسةَ صباحاً تقع في اليوم التالي ما لم تُؤخَّر تلك الساعة.
 */
check('من ١٨:٠٠ إلى ٠٦:٠٠ = ٧٢٠ دقيقة', 720, ShiftJudge::minutesBetween('18:00', '06:00'));

echo "\n$line\n";
printf("  نجح %d، وفشل %d.\n", $pass, $fail);
echo "$line\n\n";

exit($fail === 0 ? 0 : 1);
