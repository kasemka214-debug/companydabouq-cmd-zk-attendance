<?php

declare(strict_types=1);

/**
 * فحصُ المتغيّرات غير المعرَّفة في الدوالّ المغلقة.
 *
 * و`php -l` لا يمسك هذا أصلاً: الدالّةُ المغلقة لا ترث نطاقَ أبيها في
 * PHP، فمتغيّرٌ يُستعمل داخلها ولم يُذكر في `use` يكون **فارغاً** لا
 * خطأً نحوياً. والنحوُ سليم، والملفُّ يُصرَّف، والصفحةُ تسقط بخمسِ مئةٍ
 * في الإنتاج.
 *
 * وقد وقع: `$moves` في `EmployeeController::index` استُعملت في الحلقة
 * ولم تُمرَّر، فصارت `null` و`foreach` عليها يُسقط شاشةَ الموظفين كلَّها.
 * ولم يمسكها نحوٌ ولا قالبٌ ولا محاكاة — لأن المحاكاةَ تفحص المنطقَ
 * الخالص، وهذا في المتحكّم.
 *
 * والفحصُ خشنٌ عمداً: يقرأ كلَّ `function (...) use (...)`، ويجمع ما
 * فيها من `$متغيّرات`، ويطرح الوسائطَ والمُمرَّراتِ وما أُسند داخلها
 * و`$this`. فما بقي مشكوكٌ فيه.
 *
 *     C:\xampp\php\php.exe scripts/فحص-المتغيرات.php
 */

$root = dirname(__DIR__);

/** المتغيّراتُ العامّةُ التي لا تحتاج تمريراً */
const KNOWN = ['this', 'GLOBALS', '_GET', '_POST', '_SERVER', '_SESSION', '_REQUEST', '_FILES', '_ENV', '_COOKIE'];

$bad = 0;
$n   = 0;

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));

foreach ($files as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
        continue;
    }

    $src = file_get_contents($file->getPathname());

    /*
     * `function (…) use (…) {` ثم الجسدُ حتى قوسه المقابل.
     *
     * والبحثُ عن القوس المقابل بالعدّ لا بالنمط: جسدُ الدالّة فيه أقواسٌ
     * كثيرة، ونمطٌ كسولٌ يقف عند أوّلها فيفحص سطرين من عشرين.
     */
    if (! preg_match_all('/function\s*\(([^)]*)\)\s*use\s*\(([^)]*)\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        continue;
    }

    foreach ($m as $hit) {
        $n++;

        $args   = $hit[1][0];
        $passed = $hit[2][0];
        $start  = $hit[0][1] + strlen($hit[0][0]);

        /* الجسدُ: من بعد `{` إلى قوسه المقابل */
        $depth = 1;
        $i     = $start;
        $len   = strlen($src);

        while ($i < $len && $depth > 0) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
            }

            $i++;
        }

        $body = substr($src, $start, $i - $start - 1);

        /* ما هو مسموحٌ فيه: الوسائطُ، والمُمرَّرات، وما أُسند داخله */
        $allowed = [];

        foreach ([$args, $passed] as $list) {
            preg_match_all('/\$(\w+)/', $list, $v);
            $allowed = array_merge($allowed, $v[1]);
        }

        /* ما أُسند أو أُعلن داخل الجسد — إسنادٌ أو foreach أو catch أو دالّةٌ أعمق */
        preg_match_all('/\$(\w+)\s*(?:=[^=]|\+\+|--)/', $body, $a);
        preg_match_all('/as\s+\$(\w+)(?:\s*=>\s*\$(\w+))?/', $body, $f);
        preg_match_all('/catch\s*\([^)]*\$(\w+)\)/', $body, $c);
        preg_match_all('/function\s*\(([^)]*)\)/', $body, $inner);
        preg_match_all('/fn\s*\(([^)]*)\)/', $body, $arrow);

        $allowed = array_merge($allowed, $a[1], $f[1], array_filter($f[2]), $c[1]);

        foreach (array_merge($inner[1], $arrow[1]) as $sig) {
            preg_match_all('/\$(\w+)/', $sig, $v);
            $allowed = array_merge($allowed, $v[1]);
        }

        /*
         * والدوالُّ السهمية ترث النطاقَ تلقائياً، فما فيها ليس شاهداً.
         * فتُنزع من الجسد قبل الفحص لئلا يُتَّهم ما هو سليم.
         */
        $probe = preg_replace('/fn\s*\([^)]*\)\s*=>[^,;)]*/', '', $body) ?? $body;

        preg_match_all('/\$(\w+)/', $probe, $used);

        $allowed = array_flip(array_merge($allowed, KNOWN));

        foreach (array_unique($used[1]) as $v) {
            if (isset($allowed[$v])) {
                continue;
            }

            $line = substr_count(substr($src, 0, $start), "\n") + 1;
            $rel  = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());

            echo "  ✗ {$rel}:{$line} — \${$v} تُستعمل في دالّةٍ مغلقة ولم تُمرَّر في use\n";
            $bad++;
        }
    }
}

echo "\n".str_repeat('─', 60)."\n";
echo $bad === 0
    ? "  ✓ فُحصت {$n} دالّةً مغلقة، ولا متغيّرَ ناقصاً.\n"
    : "  ✗ فُحصت {$n} دالّةً مغلقة، وفيها {$bad} متغيّراً ناقصاً.\n";

exit($bad === 0 ? 0 : 1);
