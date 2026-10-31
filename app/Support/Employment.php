<?php

namespace App\Support;

/**
 * الخدمةُ والإجازة — منطقٌ خالصٌ بلا قاعدةِ بيانات ولا لارافيل.
 *
 * **ولا `use` في هذا الملفّ ولا Carbon**، كأخيه `AbsenceJudge`: التواريخُ
 * نصوصُ `Y-m-d` تُقارن لفظياً، فالصيغةُ مرتَّبةٌ بطبعها. وبهذا يُقرأ
 * بـ`require` في سكربت محاكاةٍ ويُفحص كاملاً، ولا `vendor/` على جهاز
 * التطوير.
 *
 * ومسألتُه واحدة: **الرجلُ ليس صفّاً.** جدولُ الربط مفتاحُه (موقع + رقم)،
 * فالرجلُ الذي نُقل جهازُه أو أُعيد ضبطُه أو نُسخ اسمُه إلى موقعٍ آخرَ له
 * صفّان أو ثلاثة. فلو كُتبت إجازتُه على صفٍّ ولم تُكتب على إخوته لخرج
 * «لم يبصم» بالأحمر وهو في إجازةٍ موقَّعة.
 *
 * فالواقعةُ **تُكتب على صفٍّ واحدٍ وتُقرأ على العنقود كلِّه**. ولا يُطلب
 * من صاحب الشركة أن يتذكّر صفّاً لم يُنشئه ولم يره.
 */
class Employment
{
    /** أسبابُ نهاية الخدمة — الرمزُ => نصُّه */
    public const REASONS = [
        'resigned'   => 'استقال',
        'terminated' => 'أُنهيت خدمتُه',
        'ended'      => 'انتهى عقدُه',
        'absconded'  => 'تركَ العملَ بلا إشعار',
        'transfer'   => 'نُقل إلى جهةٍ أخرى',
    ];

    /** أنواعُ الإجازة */
    public const KINDS = [
        'annual'   => 'إجازةٌ دورية',
        'sick'     => 'إجازةٌ مرضية',
        'unpaid'   => 'إجازةٌ بلا راتب',
        'emergency' => 'إجازةٌ اضطرارية',
        'official' => 'إجازةٌ رسمية',
    ];

    public static function reason(?string $key): string
    {
        return self::REASONS[(string) $key] ?? 'تركَ العمل';
    }

    public static function kind(?string $key): string
    {
        return self::KINDS[(string) $key] ?? 'إجازة';
    }

    /* ── العنقود: أيُّ الصفوف لرجلٍ واحد ───────────────────────────── */

    /**
     * أهذان الصفّان لرجلٍ واحد؟
     *
     * والمقياسُ هويّةٌ مشتركة (اسمٌ أو رقمٌ مرجعيّ) — **إلا أن يحمل الصفّان
     * رقمين مرجعيّين مختلفين**، فحينئذٍ هما رجلان ولو تطابق الاسمُ حرفاً
     * بحرف.
     *
     * وهذا الاستثناءُ ضرورة: «محمد علي» اسمٌ يحمله ثلاثةٌ في موقعٍ واحد،
     * فلو وُحِّدوا بالاسم وحدَه لأسكتت إجازةُ أحدهم غيابَ الآخرَين. والرقمُ
     * المرجعيُّ — حين يُكتب — **دليلٌ موجبٌ على الفرق** لا سكوتٌ عنه.
     *
     * @param  array{employee_ref: ?string, employee_name: ?string}  $a
     * @param  array{employee_ref: ?string, employee_name: ?string}  $b
     */
    public static function samePerson(array $a, array $b): bool
    {
        $refA = trim((string) ($a['employee_ref'] ?? ''));
        $refB = trim((string) ($b['employee_ref'] ?? ''));

        /* رقمان مرجعيّان مختلفان: رجلان، ولا يُنظر إلى الاسم */
        if ($refA !== '' && $refB !== '' && $refA !== $refB) {
            return false;
        }

        if ($refA !== '' && $refA === $refB) {
            return true;
        }

        $nameA = trim((string) ($a['employee_name'] ?? ''));
        $nameB = trim((string) ($b['employee_name'] ?? ''));

        return $nameA !== '' && $nameA === $nameB;
    }

    /**
     * عناقيدُ الصفوف — كلُّ عنقودٍ رجلٌ واحد.
     *
     * واتّحادُ مجموعاتٍ لا مقارنةُ كلٍّ بكلّ: الأولى تمرّ على الصفوف مرّةً،
     * والثانيةُ تمرّ عليها مربّعةً — وجدولُ الربط يبلغ الألفَ في شركةٍ ذات
     * خمسة مواقع، فالفرقُ بين لمحةٍ وثانية.
     *
     * **والغموضُ لا يُوحَّد.** اسمٌ واحدٌ تحته رقمان مرجعيّان مختلفان
     * يعني رجلين اتّفق اسمُهما — و«محمد علي» يحمله ثلاثةٌ في موقعٍ واحد.
     * فصفٌّ بلا رقمٍ مرجعيٍّ تحت اسمٍ كهذا **لا يُلحق بأحدهما**: إلحاقُه
     * بالخطأ يُسكت غيابَ رجلٍ آخر، وتركُه يُنتج سؤالاً يُراجَع. والسؤالُ
     * أهونُ من السكوت.
     *
     * @param  array<int, array<string, mixed>>  $rows  لكلٍّ `id` و`employee_ref` و`employee_name`
     * @return array<int, int> مُعرِّفُ الصفّ => مُعرِّفُ العنقود
     */
    public static function cluster(array $rows): array
    {
        $parent = [];

        $find = function (int $x) use (&$parent, &$find): int {
            while (($parent[$x] ?? $x) !== $x) {
                $parent[$x] = $parent[$parent[$x]] ?? $parent[$x];
                $x = $parent[$x];
            }

            return $x;
        };

        $union = function (int $a, int $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);

            if ($ra !== $rb) {
                /* الأصغرُ أصلاً — فيثبت مُعرِّفُ العنقود مهما كان الترتيب */
                $parent[max($ra, $rb)] = min($ra, $rb);
            }
        };

        $byRef  = [];   // الرقمُ المرجعيّ => أوّلُ صفٍّ حمله
        $byName = [];   // الاسمُ => صفوفُه
        $refsOf = [];   // الاسمُ => أرقامُه المرجعية المختلفة

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $parent[$id] ??= $id;

            $ref  = trim((string) ($row['employee_ref'] ?? ''));
            $name = trim((string) ($row['employee_name'] ?? ''));

            /* الرقمُ المرجعيُّ يَجمع ولو اختلف الاسم — وهو حالُ تصحيح الإملاء */
            if ($ref !== '') {
                if (isset($byRef[$ref])) {
                    $union($id, $byRef[$ref]);
                } else {
                    $byRef[$ref] = $id;
                }
            }

            if ($name !== '') {
                $byName[$name][] = $id;

                if ($ref !== '') {
                    $refsOf[$name][$ref] = true;
                }
            }
        }

        foreach ($byName as $name => $ids) {
            /*
             * اسمٌ تحته رقمان مرجعيّان مختلفان: رجلان. فلا يُجمع بالاسم،
             * والرقمُ المرجعيُّ قد جمع أصحابَه فوقُ.
             */
            if (count($refsOf[$name] ?? []) > 1) {
                continue;
            }

            foreach ($ids as $id) {
                $union($id, $ids[0]);
            }
        }

        $of = [];

        foreach ($rows as $row) {
            $id      = (int) $row['id'];
            $of[$id] = $find($id);
        }

        return $of;
    }

    /* ── نهايةُ الخدمة ─────────────────────────────────────────────── */

    /**
     * آخرُ يومٍ يُسأل فيه عن هذا الصفّ — أو `null` إن كان على رأس العمل.
     *
     * **وقرارٌ أقدمُ من الصفّ لا يصف الصفّ.** وهذا أخطرُ ما في الوحدة:
     * رجلٌ تركَ العملَ في مايو فسُجّل ذلك على صفّه، ثم عاد في سبتمبر —
     * أو أُعيد ضبطُ الجهاز فسُجّل إصبعُه برقمٍ جديد — فصار له صفٌّ ثانٍ.
     * فلو وُرِّث القرارُ القديمُ إلى الصفّ الجديد لسقط الرجلُ من كل سؤالٍ
     * إلى الأبد: غاب شهراً ولا سطرَ يُنبّه.
     *
     * فيُطرح من العنقود كلُّ قرارٍ سابقٍ لنشأة الصفّ المسؤول عنه.
     *
     * @param  array<int, array{left_on: ?string}>  $clusterRows  صفوفُ العنقود
     * @param  string  $since  تاريخُ نشأة الصفّ المسؤول (`Y-m-d`)
     */
    public static function until(array $clusterRows, string $stintFrom): ?string
    {
        $best = null;

        foreach ($clusterRows as $row) {
            $on = trim((string) ($row['left_on'] ?? ''));

            if ($on === '') {
                continue;
            }

            /* قرارٌ يسبق وجودَ الصفّ لا يصفه */
            if ($on < $stintFrom) {
                continue;
            }

            if ($best === null || $on > $best) {
                $best = $on;
            }
        }

        return $best;
    }

    /* ── مُدَدُ الخدمة في موقع ───────────────────────────────────────── */

    /**
     * مُدَدُ خدمة هذا الصفّ — متى كان **هو** الموضعَ الذي يُسأل فيه عن الرجل.
     *
     * **وقائمةُ مُدَدٍ لا مدّةٌ واحدة.** فالنقلُ العائد واقعٌ في المقاولات:
     * أ ← ب ← أ يحتاج صفَّ (أ) إلى مدّتين. وخانتان على الصفّ
     * (`served_from`/`moved_on`) لا تحملان إلا واحدة، فيُمحى الأوّلُ
     * بالثاني فتسقط أيامُه الأولى في (أ) من كل سؤال — **في ورقةٍ قد صُرف
     * عليها أجرُها**، وتسقط منها إن أُعيدت طباعتُها. وهو عينُ ما حرّمته
     * قاعدةُ «نهايةُ الخدمة مدّةٌ لا عَلَم».
     *
     * و`moved_on` **أوّلُ يومِ دوامٍ في الموقع الجديد**، فآخرُ يومٍ في
     * القديم هو ما قبله. وهذا يخالف `left_on` — وتلك **داخلةٌ** في الحساب.
     *
     * @param  string  $born  يومُ نشأة الصفّ (`Y-m-d`)
     * @param  array<int, array{date: string, out: bool}>  $events  أحداثُ هذا الصفّ وحدَه
     * @return array<int, array{from: string, to: string|null}>
     */
    public static function serves(string $born, array $events): array
    {
        if ($events === []) {
            return [['from' => $born, 'to' => null]];
        }

        /* بالتاريخ، والوصولُ قبل المغادرة في اليوم نفسِه */
        usort($events, fn (array $a, array $b): int => $a['date'] === $b['date']
            ? ((int) $a['out']) <=> ((int) $b['out'])
            : ($a['date'] < $b['date'] ? -1 : 1));

        /*
         * الحالُ قبل أوّل حدث: مفتوحةٌ من نشأة الصفّ **إلا أن يكون أوّلُ
         * الأحداث وصولاً**. فصفٌّ أوّلُ خبرٍ عنه «جاء إليه رجل» لم يكن
         * موضعَ سؤالٍ قبل ذلك.
         */
        $open = $events[0]['out'] ? $born : null;
        $out  = [];

        foreach ($events as $e) {
            if (! $e['out']) {
                $open ??= $e['date'];

                continue;
            }

            if ($open !== null) {
                $out[] = ['from' => $open, 'to' => date('Y-m-d', strtotime($e['date'].' -1 day'))];
                $open  = null;
            }
        }

        if ($open !== null) {
            $out[] = ['from' => $open, 'to' => null];
        }

        /*
         * **ولا تُرجع فراغاً أبداً.**
         *
         * فالمنادي يقرأ `$spans[0]['from']` ليُمرّره إلى `until()`، ومفتاحٌ
         * غيرُ موجودٍ يصير `null` في وسيطٍ من نوع `string` — خطأُ نوعٍ
         * وصفحةُ خمسِ مئةٍ على كشف الأيام كلِّه. ويقع فعلاً: رجلٌ نُقل
         * ثم صُحّح النقلُ في اليوم نفسِه، فالوصولُ والمغادرةُ في يومٍ واحد.
         *
         * فتُرجع مدّةً صفريةً مغلقة: الصفُّ موجودٌ ولا يوم له.
         */
        return $out !== [] ? $out : [['from' => $born, 'to' => $born]];
    }

    /** أكان هذا الصفُّ موضعَ السؤال في هذا اليوم؟ */
    public static function servesOn(array $spans, string $date): bool
    {
        foreach ($spans as $span) {
            if ($date >= $span['from'] && ($span['to'] === null || $date <= $span['to'])) {
                return true;
            }
        }

        return false;
    }

    /** أتتقاطع إحدى مُدَده مع هذه المدّة؟ */
    public static function servesBetween(array $spans, string $from, string $to): bool
    {
        foreach ($spans as $span) {
            if ($span['from'] <= $to && ($span['to'] === null || $span['to'] >= $from)) {
                return true;
            }
        }

        return false;
    }
    /* ── الإجازة ───────────────────────────────────────────────────── */

    /**
     * أهو في إجازةٍ في هذا اليوم؟ وأيُّ إجازة؟
     *
     * @param  array<int, array{from_date: string, to_date: string, kind: ?string}>  $leaves
     * @return array{kind: string, from: string, to: string}|null
     */
    public static function leaveOn(array $leaves, string $date): ?array
    {
        foreach ($leaves as $leave) {
            $from = (string) $leave['from_date'];
            $to   = (string) $leave['to_date'];

            if ($date >= $from && $date <= $to) {
                return [
                    'kind' => (string) ($leave['kind'] ?? 'annual'),
                    'from' => $from,
                    'to'   => $to,
                ];
            }
        }

        return null;
    }

    /** أله إجازةٌ تتقاطع مع هذه المدّة؟ */
    public static function hasLeaveBetween(array $leaves, string $from, string $to): bool
    {
        foreach ($leaves as $leave) {
            if ((string) $leave['from_date'] <= $to && (string) $leave['to_date'] >= $from) {
                return true;
            }
        }

        return false;
    }

    /**
     * أصحيحةٌ هذه المدّة؟
     *
     * ويُفحص في موضعين — المتحكّمُ ليُرى الخطأ، والنموذجُ عند الحفظ ليُمنع.
     * فـ`PinController::import` يُثبت أن مسارَ كتابةٍ يتجاوز تحقّقَ
     * المتحكّم أمرٌ واقع، لا احتمالٌ نظريّ.
     */
    public static function spanValid(?string $from, ?string $to): bool
    {
        $from = trim((string) $from);
        $to   = trim((string) $to);

        return $from !== '' && $to !== '' && $to >= $from;
    }

    /** «٢٠٢٦-٠٨-٠١ ← ٢٠٢٦-١٠-١٤ (٧٥ يوماً)» */
    public static function spanLabel(string $from, string $to): string
    {
        $days = (int) floor((strtotime($to) - strtotime($from)) / 86400) + 1;

        return $from.' ← '.$to.' ('.$days.' يوماً)';
    }
}
