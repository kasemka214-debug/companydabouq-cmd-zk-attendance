<?php

/**
 * محاكاةُ النافذة الهادئة: متى ينام الجهازُ ومتى يستيقظ.
 *
 * والنافذةُ تعبر منتصفَ الليل (من ٥ مساءً إلى ٦ صباحاً)، وحسابٌ ساذجٌ
 * «أكبرُ من البداية وأصغرُ من النهاية» يجعلها فارغةً أبداً — فلا ينام جهازٌ
 * ليلةً واحدة، ولا تنخفض فاتورة. وهذا لا يُرى بقراءة الشيفرة، إنما بساعاتٍ
 * تُعرض واحدةً واحدة.
 *
 *     C:\xampp\php\php.exe scripts/محاكاة-النافذة.php
 */

declare(strict_types=1);

/* ─── بديلُ `config()`: يُبدَّل بين الحالات ────────────────────────── */

$GLOBALS['cfg'] = [
    'app.display_timezone' => null,
    'zk.poll_delay'        => 30,
    'zk.quiet.enabled'     => true,
    'zk.quiet.from'        => '17:00',
    'zk.quiet.to'          => '06:00',
    'zk.quiet.delay'       => 1800,
];

function config(string $key, $default = null)
{
    return array_key_exists($key, $GLOBALS['cfg']) ? ($GLOBALS['cfg'][$key] ?? $default) : $default;
}

require __DIR__.'/../app/Support/Clock.php';
require __DIR__.'/../app/Support/PollRhythm.php';

use App\Support\PollRhythm;

$pass = 0;
$fail = 0;

function check(string $title, $expected, $actual): void
{
    global $pass, $fail;

    $ok = $expected === $actual;
    $ok ? $pass++ : $fail++;

    printf("  %s  %-44s المنتظر: %-10s والخارج: %s\n", $ok ? '✓' : '✗', $title,
        var_export($expected, true), var_export($actual, true));
}

$line = str_repeat('─', 76);

echo "\n$line\n  النافذةُ من ١٧:٠٠ إلى ٠٦:٠٠ — وهي تعبر منتصفَ الليل\n$line\n\n";

foreach ([
    '06:00' => 'day',
    '07:15' => 'day',
    '12:00' => 'day',
    '16:59' => 'day',
    '17:00' => 'night',
    '20:30' => 'night',
    '23:59' => 'night',
    '00:00' => 'night',
    '03:00' => 'night',
    '05:59' => 'night',
] as $at => $expected) {
    check("الساعة $at", $expected, PollRhythm::mode($at));
}

echo "\n$line\n  الوتيرةُ والأمر\n$line\n\n";

check('نهاراً ينادي كلَّ ٣٠ ثانية', 30, PollRhythm::delay('day'));
check('وليلاً كلَّ ١٨٠٠ ثانية', 1800, PollRhythm::delay('night'));
check('ونصُّ الأمر كما يفهمه الجهاز', 'SET OPTION Delay=1800', PollRhythm::body('night'));
check('وأمرُ الاستيقاظ', 'SET OPTION Delay=30', PollRhythm::body('day'));
check('ووصفُه في الشاشة', 'ليلٌ — ينادي كلَّ 30 دقيقة', PollRhythm::label('night'));
check('وجهازٌ لم يُضبط بعد', 'لم يُضبط بعد', PollRhythm::label(null));

echo "\n$line\n  وحينَ تُطفأ النافذة\n$line\n\n";

$GLOBALS['cfg']['zk.quiet.enabled'] = false;

check('منتصفُ الليل ونافذةٌ مطفأة', 'day', PollRhythm::mode('00:00'));
check('ولا يُبطَّأ النداء', 30, PollRhythm::delay(PollRhythm::mode('00:00')));

echo "\n$line\n  ونافذةٌ لا تعبر منتصفَ الليل (من ٠١:٠٠ إلى ٠٥:٠٠)\n$line\n\n";

$GLOBALS['cfg']['zk.quiet.enabled'] = true;
$GLOBALS['cfg']['zk.quiet.from']    = '01:00';
$GLOBALS['cfg']['zk.quiet.to']      = '05:00';

check('٠٠:٥٩ قبلها', 'day', PollRhythm::mode('00:59'));
check('٠١:٠٠ أوّلُها', 'night', PollRhythm::mode('01:00'));
check('٠٤:٥٩ آخرُها', 'night', PollRhythm::mode('04:59'));
check('٠٥:٠٠ بعدها', 'day', PollRhythm::mode('05:00'));
check('الظهرُ خارجَها', 'day', PollRhythm::mode('12:00'));

/*
 * وحارسٌ على الرقم نفسِه: ليلٌ بوتيرةٍ أسرعَ من النهار يعني أن أحداً كتب
 * `ZK_QUIET_DELAY=10` فصار «النوم» أثقلَ على الخادم من اليقظة.
 */
$GLOBALS['cfg']['zk.quiet.delay'] = 10;
check('وتيرةٌ ليليةٌ أقلُّ من دقيقةٍ تُرفع إلى دقيقة', 60, PollRhythm::delay('night'));

echo "\n$line\n";
printf("  نجح %d، وفشل %d.\n", $pass, $fail);
echo "$line\n\n";

exit($fail === 0 ? 0 : 1);
