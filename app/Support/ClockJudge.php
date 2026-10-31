<?php

namespace App\Support;

/**
 * الحكمُ على قراءةِ ساعةِ جهاز — منطقٌ خالصٌ بلا قاعدة بيانات.
 *
 * وفُصل عن كل شيء عمداً: لا `vendor/` على جهاز التطوير، فما لا يُفصل لا
 * يُفحص. وهذا الملفُّ وحدَه يقرّر متى يُقال «عُبث بالساعة» — وهي تهمةٌ
 * تُقال في نزاعِ أجرٍ، فلا تُبنى على تخمين.
 *
 * والمسألةُ كلُّها أن البروتوكول لا يعطينا ساعةَ الجهاز مباشرةً. يعطينا
 * بصمةً موسومةً بها، فنقيسها على ساعتنا. والفرقُ له أسبابٌ بريئةٌ كثيرة:
 *
 *   • انقطاعُ إنترنتٍ ثم دفعُ ما فات — فتبدو البصماتُ «متأخّرة»؛
 *   • دفعةٌ مُعادةٌ لم يصلها إقرارُنا؛
 *   • جهازُ تحكّمٍ يرسل البصمةَ مرّتين بطبعه (`rtlog` ثم `transaction`)؛
 *   • بلّورةٌ رخيصةٌ تتأخّر ثوانيَ في اليوم؛
 *   • بطاريةُ ساعةٍ ماتت فعاد الجهازُ إلى سنة ٢٠٠٠؛
 *   • خطأُ منطقةٍ زمنية — وهو هنا بالذات ثلاثُ ساعاتٍ بالضبط؛
 *   • ساعةُ الخادم نفسِها قفزت، فيبدو كلُّ الأجهزة عابثة.
 *
 * فالحارسُ يمرّ بثلاث مراحل: أهذه القراءةُ صالحةٌ للحكم أصلاً؟ ثم أهي
 * قفزةٌ أم زحفٌ بطيء؟ ثم ما أرجحُ تفسيرٍ لها؟
 */
class ClockJudge
{
    /* ── أصنافُ الحدث ───────────────────────────────────────────────── */

    public const TAMPER       = 'tamper';        // يدٌ في القائمة — أقوى ادّعاء
    public const TIMEZONE     = 'timezone';      // خطأُ منطقةٍ زمنية
    public const RTC_DEAD     = 'rtc_dead';      // بطاريةُ الساعة ماتت
    public const BOOT_SKEW    = 'boot_skew';     // تغيّرت عبر إقلاعٍ أو انقطاع
    public const SERVER_CLOCK = 'server_clock';  // ساعةُ الخادم هي التي قفزت
    public const NEVER_SET    = 'never_set';     // جهازٌ جديدٌ بلا سابقةٍ نقيس عليها
    public const DRIFT_SLOW   = 'drift_slow';    // زحفُ بلّورة

    /** وصفُ الصنف بالعربية. */
    public const LABELS = [
        self::TAMPER       => 'تغييرٌ مقصود',
        self::TIMEZONE     => 'خطأُ منطقةٍ زمنية',
        self::RTC_DEAD     => 'بطاريةُ ساعة الجهاز',
        self::BOOT_SKEW    => 'تغيّرت عبر إقلاعٍ أو انقطاع',
        self::SERVER_CLOCK => 'ساعةُ الخادم',
        self::NEVER_SET    => 'جهازٌ لم تُضبط ساعتُه قطّ',
        self::DRIFT_SLOW   => 'زحفٌ بطيء',
    ];

    /* ── أسبابُ رفض القراءة ─────────────────────────────────────────── */

    public const RESEND   = 'resend';    // دفعةٌ مُعادة — لا جديدَ فيها
    public const BACKLOG  = 'backlog';   // متراكمُ انقطاع
    public const REUPLOAD = 'reupload';  // رفعٌ كاملٌ طلبناه نحن
    public const BULK     = 'bulk';      // دفعةٌ كبيرةٌ أو ممتدّة

    /* ── الحدود ─────────────────────────────────────────────────────── */

    /** @return array<string, int|float> */
    public static function limits(): array
    {
        $c = (array) config('zk.clock', []);

        return [
            /* دون هذا تُسمّى الساعةُ «مضبوطة» */
            'tolerance' => (int) ($c['tolerance'] ?? config('zk.clock_tolerance', 300)),

            /*
             * يُفتح الحدثُ فوق `open` ولا يُغلق إلا دون `close`.
             *
             * وهما مختلفان عمداً — حزامُ تردّد. فحدٌّ واحدٌ يجعل جهازاً
             * واقفاً على الحدّ يفتح ويغلق كلَّ دقيقة، فيصير الإشعارُ
             * ضجيجاً يُهمَل، ويُهمَل معه العبثُ الحقيقي.
             */
            'open'  => (int) ($c['open'] ?? 600),
            'close' => (int) ($c['close'] ?? 120),

            /* فوقه تُقال التهمةُ بيقينٍ لا باحتمال */
            'hard'  => (int) ($c['hard'] ?? 3600),

            /* أقلُّ فرقٍ يُعدّ «قفزة» */
            'step_min' => (int) ($c['step_min'] ?? 120),

            /*
             * أقصى انحرافٍ طبيعيٍّ للبلّورة — ثانيةٌ لكل خمسمئة.
             * وهو أسخى من أيّ بلّورةٍ حقيقية بأربعين ضعفاً، فما جاوزه
             * فليس زحفاً.
             */
            'rate_max' => (float) ($c['rate_max'] ?? 0.002),

            /* الجهازُ متّصلٌ إن لم يغب أكثر من ثلاث دورات استعلام */
            'online_grace' => (int) ($c['online_grace'] ?? 3 * (int) config('zk.poll_delay', 30)),

            /* دفعةٌ حيّة: قليلةُ الأسطر قصيرةُ المدى */
            'span_limit' => (int) ($c['span_limit'] ?? 180),
            'live_rows'  => (int) ($c['live_rows'] ?? 5),

            /* بعد أمرِ مزامنةٍ شاملة يرفع الجهازُ ذاكرتَه كلَّها */
            'reupload_window' => (int) ($c['reupload_window'] ?? 6 * 3600),
            'reupload_rows'   => (int) ($c['reupload_rows'] ?? 200),

            /* فرقٌ بالشهور: بطاريةٌ ماتت لا ساعةٌ عُبث بها */
            'absurd' => (int) ($c['absurd'] ?? 180 * 86400),

            /* قربُ الفرق من ساعةٍ تامّةٍ أو نصفِها */
            'zone_slack' => (int) ($c['zone_slack'] ?? 90),

            /* أقصى ما يُرجَع إليه في نافذة الشكّ حين لا سابقةَ نظيفة */
            'lookback_max' => (int) ($c['lookback_max'] ?? 24 * 3600),

            /* مهلةٌ يُستأنف فيها حدثٌ أُغلق بدل استجداد حدثٍ آخر */
            'reopen_cooldown' => (int) ($c['reopen_cooldown'] ?? 900),
        ];
    }

    /* ── ١) أصالحةٌ هذه القراءةُ للحكم؟ ────────────────────────────── */

    /**
     * القراءةُ الصالحة وحدَها تُحرّك شيئاً. وما سواها لا يفتح حدثاً ولا
     * يُغلقه ولا يُغيّر الأساسَ الذي نقيس عليه — يمرّ ولا أثرَ له.
     *
     * وهنا يموت متراكمُ الانقطاع بصمت، وهو أكثرُ أسباب الإنذار الكاذب.
     *
     * @param  array{stored:int, rows:int, span:int, gap_before:int|null, after_sync:bool}  $b
     * @return array{ok: bool, why: string|null}
     */
    public static function eligible(array $b): array
    {
        $l = self::limits();

        /*
         * دفعةٌ لم تُخزّن سطراً واحداً دفعةٌ مُعادة — والجهازُ يُعيد ما لم
         * يصله إقرارُنا، وجهازُ التحكّم يرسل كلَّ بصمةٍ مرّتين بطبعه.
         * والنسخةُ الثانيةُ تصل بعد دقائق، فتُقرأ «تأخُّرَ ساعة» وهي ليست
         * قراءةً أصلاً.
         */
        if (($b['stored'] ?? 0) < 1) {
            return ['ok' => false, 'why' => self::RESEND];
        }

        /*
         * الجهازُ الذي غاب ثم عاد يدفع ما فات. وأحدثُ بصمةٍ فيه قد تكون
         * من أمس بحقّ. فلا يُحكم إلا على جهازٍ لم ينقطع.
         */
        $gap = $b['gap_before'];

        if ($gap !== null && $gap > $l['online_grace']) {
            return ['ok' => false, 'why' => self::BACKLOG];
        }

        /* رفعٌ كاملٌ طلبناه نحن بأمرِ مزامنة — يحمل شهوراً وهو متّصل */
        if (! empty($b['after_sync']) || ($b['rows'] ?? 0) > $l['reupload_rows']) {
            return ['ok' => false, 'why' => self::REUPLOAD];
        }

        /* دفعةٌ تمتدّ ساعاتٍ ليست لحظةً واحدةً تُقاس */
        if (($b['rows'] ?? 0) > $l['live_rows'] || ($b['span'] ?? 0) > $l['span_limit']) {
            return ['ok' => false, 'why' => self::BULK];
        }

        return ['ok' => true, 'why' => null];
    }

    /* ── ٢) أهي قفزةٌ أم زحف؟ ──────────────────────────────────────── */

    /**
     * القفزةُ يدٌ، والزحفُ بلّورة.
     *
     * والفرقُ بينهما ستّةُ أضعافٍ عشرية: بلّورةٌ رديئةٌ تتأخّر ثوانيَ في
     * اليوم، ويدٌ في القائمة تُحرّك ساعتين بين استعلامين. فالفحصُ على
     * **المعدّل** لا على المقدار: ستُّ دقائقَ تراكمت في ثلاثة أيام زحفٌ،
     * وستُّ دقائقَ ظهرت في دقيقةٍ قفزة.
     */
    public static function isStep(int $drift, ?int $prevDrift, int $dtSeconds): bool
    {
        if ($prevDrift === null) {
            return false;
        }

        $l    = self::limits();
        $move = abs($drift - $prevDrift);

        if ($move < $l['step_min']) {
            return false;
        }

        return $move > $l['rate_max'] * max(0, $dtSeconds);
    }

    /* ── ٣) ما أرجحُ تفسير؟ ────────────────────────────────────────── */

    /**
     * أوّلُ مطابقٍ يفوز، والترتيبُ مقصود: تُستبعد الأعذارُ البريئةُ قبل
     * أن تُقال التهمة.
     *
     * @param  array{
     *   drift:int, prev_drift:int|null, dt:int, gap_before:int|null,
     *   max_gap_since_good:int|null, co_drift:bool
     * }  $r
     */
    public static function classify(array $r): string
    {
        $l     = self::limits();
        $drift = (int) $r['drift'];
        $abs   = abs($drift);

        /* شهورٌ من الفرق: بطاريةٌ ماتت فعاد الجهازُ إلى مبدأ تقويمه */
        if ($abs > $l['absurd']) {
            return self::RTC_DEAD;
        }

        /*
         * كلُّ الأجهزة انحرفت معاً بالقدر نفسِه في اللحظة نفسِها — فالذي
         * قفز ساعتُنا لا ساعاتُها. ولا يتواطأ أربعةُ عابثين في مواقعَ
         * متفرّقةٍ على الثانية.
         */
        if (! empty($r['co_drift'])) {
            return self::SERVER_CLOCK;
        }

        $zone = self::looksLikeTimezone($abs, $l['zone_slack']);

        /* لا سابقةَ نقيس عليها — جهازٌ رُكّب بساعة المصنع */
        if ($r['prev_drift'] === null) {
            return $zone ? self::TIMEZONE : self::NEVER_SET;
        }

        $step = self::isStep($drift, $r['prev_drift'], (int) $r['dt']);

        $gap     = $r['gap_before'];
        $max     = $r['max_gap_since_good'];
        $wasDown = ($gap !== null && $gap > $l['online_grace'])
            || ($max !== null && $max > $l['online_grace']);

        /*
         * فرقٌ يساوي ساعةً تامّةً أو نصفَها — وأخطرُه هنا ثلاثُ ساعاتٍ
         * بالضبط: فالمصافحةُ تدفع `TimeZone=3` وأمرُ الضبط يرسل توقيتَ
         * الكويت لا غرينتش. فجهازٌ أساء قراءةَ أحدهما يقع على ثلاثٍ تماماً.
         *
         * **ولا يُبرَّأ به إلا جهازٌ انقطع أو لم يُقَس قبلُ.** وكان يُبرَّأ
         * به كلُّ فرقٍ مستديرٍ فكُشف العيبُ بالمحاكاة: من أرجع الساعةَ
         * **ساعةً بالضبط** — وهو أرجحُ مقدارٍ يختاره عابثٌ لأنه أبسطُ ما
         * يُطبَع — كان يُصنَّف «خطأَ منطقةٍ» فيُبرَّأ. وهي أشيعُ صورِ العبث
         * وأسهلُ ما يُفلت.
         *
         * والفيزياءُ هي التي تفصل: خطأُ المنطقة يُطبَّق عند المصافحة —
         * أي بعد إقلاعٍ أو انقطاع — ولا يُطبَّق على جهازٍ قائمٍ يستعلم كلَّ
         * نصفِ دقيقة. فقفزةٌ مستديرةٌ وهو حيٌّ يدٌ في القائمة لا إعدادٌ
         * خطأ، ولو كان من غيّرها قاصداً المنطقةَ نفسَها — فقد فتح القائمةَ
         * على كل حال.
         */
        if ($zone && (! $step || $wasDown)) {
            return self::TIMEZONE;
        }

        if (! $step) {
            return self::DRIFT_SLOW;
        }

        /*
         * قفزةٌ عبر انقطاعٍ أو إقلاع: قد تكون ساعةً أعادها الجهازُ من
         * بطاريته، أو شبكةً أعطته وقتاً خطأً. تُقال ولا يُتّهم بها أحد.
         */
        if ($wasDown) {
            return self::BOOT_SKEW;
        }

        /*
         * قفزةٌ والجهازُ متّصلٌ طولَ الوقت يستعلم كلَّ نصف دقيقة.
         *
         * وهذا هو الادّعاءُ القويّ، وقوّتُه من سببٍ محدَّد: جهازُ ZKTeco
         * لا ينقطع عن الشبكة حين يفتح أحدٌ قائمتَه. فساعةٌ قفزت والجهازُ
         * قائمٌ يتنفّس كانت تحتها يد. ولا شيءَ آخرَ يُحرّك ساعةً وهي حيّة.
         */
        return self::TAMPER;
    }

    /** أيقرب الفرقُ من ساعةٍ تامّةٍ أو نصفِها في مدى المناطق الزمنية؟ */
    public static function looksLikeTimezone(int $abs, int $slack): bool
    {
        if ($abs < 1800 - $slack || $abs > 86400) {
            return false;
        }

        $half = 1800;
        $near = (int) round($abs / $half) * $half;

        return abs($abs - $near) <= $slack;
    }

    /* ── ٤) اليقين ─────────────────────────────────────────────────── */

    /**
     * قراءةٌ واحدةٌ تفتح الحدثَ باحتمال، وقراءةٌ ثانيةٌ توافقها — أو فرقٌ
     * يجاوز الحدَّ القاطع — ترفعه إلى يقين.
     *
     * والأصنافُ الضعيفة لا تُنذر بقراءةٍ واحدةٍ أصلاً: أعذارُها البريئةُ
     * أكثرُ من أن يُوقَظ الناسُ لها.
     */
    public static function confidence(string $class, int $drift, int $agreeing): string
    {
        $l = self::limits();

        if (abs($drift) >= $l['hard'] || $agreeing >= 2) {
            return 'confirmed';
        }

        return 'probable';
    }

    /** أيُنذَر بهذا الصنف من قراءةٍ واحدة؟ */
    public static function alertsOnFirstReading(string $class, int $drift): bool
    {
        if (in_array($class, [self::BOOT_SKEW, self::TIMEZONE, self::DRIFT_SLOW], true)) {
            return abs($drift) >= self::limits()['hard'];
        }

        return true;
    }

    /* ── ٥) الفتحُ والإغلاق ────────────────────────────────────────── */

    public static function shouldOpen(int $drift): bool
    {
        return abs($drift) > self::limits()['open'];
    }

    public static function shouldClose(int $drift): bool
    {
        return abs($drift) <= self::limits()['close'];
    }

    /**
     * دليلٌ قاطعٌ لا يحتمل تأويلاً: بصمةٌ لم تقع بعد.
     *
     * فالتأخّرُ يحتمل انقطاعاً، أمّا التقدّمُ فلا يحتمل شيئاً — لا انقطاعَ
     * في الدنيا يُنتج بصمةً من المستقبل. ولذلك يُحكم بها ولو كانت الدفعةُ
     * غيرَ صالحةٍ في سائر شروطها.
     */
    public static function fromFuture(int $drift): bool
    {
        return $drift > self::limits()['tolerance'];
    }

    /* ── ٦) الصياغة ────────────────────────────────────────────────── */

    /** «مقدَّمة ساعتين» / «مؤخَّرة ٣٥ دقيقة» / «مضبوطة» */
    public static function describe(?int $drift): string
    {
        if ($drift === null) {
            return 'لم تُقَس بعد';
        }

        $abs = abs($drift);

        if ($abs <= self::limits()['tolerance']) {
            return 'مضبوطة';
        }

        /*
         * والكسرُ الصفريُّ يُقَصّ: «مقدَّمة ٢ ساعة» لا «٢٫٠ ساعة». فالرقمُ
         * يُقرأ في تقريرٍ يُطبع ويوضع في ملفّ، والكسرُ الذي لا يعني شيئاً
         * يُشغل العينَ عمّا يعني.
         */
        $trim = fn (float $v): string => rtrim(rtrim(number_format($v, 1), '0'), '.');

        if ($abs >= 86400) {
            $unit = $trim($abs / 86400).' يوم';
        } elseif ($abs >= 3600) {
            $unit = $trim($abs / 3600).' ساعة';
        } else {
            $unit = intdiv($abs, 60).' دقيقة';
        }

        return ($drift > 0 ? 'مقدَّمة ' : 'مؤخَّرة ').$unit;
    }

    public static function label(string $class): string
    {
        return self::LABELS[$class] ?? $class;
    }
}
