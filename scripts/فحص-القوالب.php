<?php


/**
 * فحصُ قوالب Blade بلا vendor.
 *
 * لا مصرِّفَ Blade على جهاز التطوير، فخطأٌ في قالبٍ لا يظهر إلا على
 * الخادم — بعد النشر، وفي صفحةٍ بيضاء. وقد وقع: كُتب `@php` داخل تعليق،
 * وBlade يُصرّف الأوامر **قبل** أن يُزيل التعليقات، فيُصرَّف ما في
 * التعليق ويُفسد الصفحة.
 *
 * فهذا يحاكي المصرِّفَ محاكاةً خشنة ثم يُمرّر الناتجَ على `php -l`.
 * وهو لا يُغني عن المصرِّف الحقيقي، لكنه يمسك ما يُمسَك بلا تشغيل:
 * أمراً غيرَ مغلق، وقوساً ناقصاً، وخطأً نحوياً في `@php` أو `{{ }}`.
 *
 *     C:\xampp\php\php.exe scripts/فحص-القوالب.php
 */

declare(strict_types=1);

/*
 * قواعدُ CSS التي تُشبه توجيهاتِ Blade — تُترك نصّاً.
 */
if (! defined('CSS_AT_RULES')) {
    define('CSS_AT_RULES', [
        'media', 'supports', 'keyframes', 'font-face', 'page', 'import', 'charset',
        'layer', 'container', 'namespace', 'counter-style', 'property', 'scope',
        'starting-style', 'document', 'viewport', 'font-feature-values',
    ]);
}


$root = dirname(__DIR__);
$dir  = $root.'/resources/views';

/** أوامرُ تُغلق كتلةً — تُقابَل بقوسٍ مفتوح. */
const OPENS = [
    'if', 'foreach', 'for', 'while', 'forelse', 'unless',
    'can', 'cannot', 'canany', 'auth', 'guest', 'isset', 'hasSection', 'sectionMissing',
];

/** الأوامرُ التي تُغلق. */
const CLOSES = [
    'endif' => 'if', 'endforeach' => 'foreach', 'endfor' => 'for', 'endwhile' => 'while',
    'endforelse' => 'forelse', 'endunless' => 'unless', 'endcan' => 'can',
    'endcannot' => 'cannot', 'endcanany' => 'canany', 'endauth' => 'auth',
    'endguest' => 'guest', 'endisset' => 'isset',
];

/** أوامرُ بلا أثرٍ في النحو — تُمحى. */
const DROP = [
    'csrf', 'break', 'continue', 'endsection', 'endpush', 'endprepend', 'endphp',
    'parent', 'endverbatim', 'verbatim',
];

/**
 * أفي وسائط الأمر فاصلةٌ في المستوى الأوّل؟
 *
 * أي `('a', 'b')` نعم، و`(['a', 'b'])` لا — فالفاصلةُ فيها داخل مصفوفة.
 * وبها يُفرَّق بين `@section` الكتلة و`@section` السطر.
 */
function hasTopLevelComma(string $args): bool
{
    $depth = 0;
    $quote = '';

    foreach (str_split($args) as $ch) {
        if ($quote !== '') {
            if ($ch === $quote) {
                $quote = '';
            }

            continue;
        }

        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
        } elseif ($ch === '(' || $ch === '[') {
            $depth++;
        } elseif ($ch === ')' || $ch === ']') {
            $depth--;
        } elseif ($ch === ',' && $depth === 1) {
            return true;
        }
    }

    return false;
}

function compile(string $src): string
{
    /*
     * التعليقاتُ تُزال هنا بعد أن فُحصت على حِدة — فلو تُركت لدخل ما فيها
     * في التصريف، ولو مُحيت قبل الفحص لضاع الخطأُ الذي نبحث عنه.
     */
    $src = preg_replace('/\{\{--.*?--\}\}/s', '', $src) ?? $src;

    /* كتلةُ @php … @endphp */
    $src = preg_replace('/@php(?!\s*\()(.*?)@endphp/s', '<?php $1 ?>', $src) ?? $src;

    /* @php(expr) */
    $src = preg_replace('/@php\s*(\(.*?\))\s*/s', '<?php ' . '$1' . '; ?>', $src) ?? $src;

    /* الأصداء */
    $src = preg_replace('/\{!!\s*(.+?)\s*!!\}/s', '<?php echo $1; ?>', $src) ?? $src;
    $src = preg_replace('/\{\{\s*(.+?)\s*\}\}/s', '<?php echo e($1); ?>', $src) ?? $src;

    /* الأوامرُ سطراً سطراً */
    $out = preg_replace_callback(
        '/@(\w+)\s*(\((?:[^()]|(?2))*\))?/s',
        function (array $m): string {
            $name = $m[1];
            $args = $m[2] ?? '';

            /*
             * قواعدُ CSS تُترك كما هي.
             *
             * فـ`@media (max-width: 991.98px)` تشبه توجيهَ Blade شكلاً —
             * اسمٌ بعده قوسان — فكانت تُصرَّف `null(max-width: 991.98px)`
             * فيقع خطأُ نحوٍ على النقطتين، ويُتَّهم قالبٌ سليم.
             *
             * وBlade نفسُه لا يمسّها: لا يُصرّف إلا توجيهاً مسجَّلاً، وما
             * لم يعرفه تركه نصّاً. فالفاحصُ كان أشدَّ من المصرِّف، وشِدّةٌ
             * كاذبةٌ في أداةِ فحصٍ أسوأُ من تساهل: تُعلّم صاحبَها ألّا
             * يثق بها، فيُهمل إنذارَها الصادق.
             */
            if (in_array(strtolower($name), CSS_AT_RULES, true)) {
                return $m[0];
            }

            if (in_array($name, DROP, true)) {
                return $name === 'endsection' || $name === 'endpush' || $name === 'endprepend'
                    ? '<?php } ?>'
                    : '';
            }

            if (isset(CLOSES[$name])) {
                return '<?php } ?>';
            }

            if (in_array($name, OPENS, true)) {
                /* @auth و@guest قد تأتيان بلا وسيط */
                $cond = $args !== '' ? $args : '(true)';

                /* @forelse (a as b) تُقابَل بـforeach */
                if ($name === 'forelse') {
                    return '<?php foreach '.$cond.' { ?>';
                }

                $kw = in_array($name, ['if', 'foreach', 'for', 'while'], true) ? $name : 'if';

                return '<?php '.$kw.' '.$cond.' { ?>';
            }

            return match ($name) {
                'else'      => '<?php } else { ?>',
                'elseif'    => '<?php } elseif '.($args ?: '(true)').' { ?>',
                'empty'     => '<?php } if (true) { ?>',   /* @empty داخل @forelse */
                'extends', 'yield', 'include', 'includeIf', 'each', 'stack', 'method'
                            => '<?php '.($args ? 'null'.$args : 'null').'; ?>',
                /*
                 * `@section` وجهان: بوسيطٍ واحد كتلةٌ تُغلق بـ`@endsection`،
                 * وبوسيطين سطرٌ قائمٌ بنفسه لا يُغلق. وخلطُهما يفتح قوساً
                 * لا يُغلق فيتلو ذلك سيلُ أخطاءٍ كاذبة في كل قالب.
                 */
                'section'   => hasTopLevelComma($args)
                                ? '<?php null'.$args.'; ?>'
                                : '<?php if (true) { null'.($args ?: '()').'; ?>',

                'push', 'prepend'
                            => '<?php if (true) { null'.($args ?: '()').'; ?>',
                'selected', 'checked', 'disabled', 'readonly', 'required', 'class', 'style'
                            => '<?php echo (bool) '.($args ?: '(true)').'; ?>',
                default     => $args !== '' ? '<?php null'.$args.'; ?>' : '',
            };
        },
        $src,
    );

    return $out ?? $src;
}

/* ─── التشغيل ─── */

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$bad   = 0;
$n     = 0;

$tmp = sys_get_temp_dir().'/blade-check-'.getmypid().'.php';

foreach ($files as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
        continue;
    }

    $n++;
    $rel = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());

    file_put_contents($tmp, compile(file_get_contents($file->getPathname())));

    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1', $out, $code);

    if ($code !== 0) {
        $bad++;
        echo "  ✗ {$rel}\n";
        foreach ($out as $line) {
            if (str_contains($line, 'error')) {
                echo '      '.trim(str_replace($tmp, 'القالب', $line))."\n";
            }
        }
    }

    $out = [];
}

@unlink($tmp);

echo "\n".str_repeat('─', 60)."\n";
printf("  فُحص %d قالباً، وفشل %d.\n\n", $n, $bad);

exit($bad === 0 ? 0 : 1);
