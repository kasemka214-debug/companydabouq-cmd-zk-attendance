<?php

namespace App\Support;

/**
 * الحكمُ بالغياب — منطقٌ خالصٌ بلا قاعدةِ بيانات ولا لارافيل.
 *
 * **ولا `use` في هذا الملفّ، ولا Carbon.** التواريخُ نصوصُ `Y-m-d` تُقارن
 * لفظياً — فالصيغةُ مرتَّبةٌ بطبعها، و«2026-10-12» أصغرُ من «2026-10-13»
 * بالحرف كما هي أصغرُ بالزمن. وبهذا يُقرأ الملفُّ بـ`require` في سكربت
 * محاكاةٍ ويُفحص كاملاً، ولا `vendor/` على جهاز التطوير.
 *
 * وسببُ الفصل أثقلُ من سببه في حارس الساعة: **هذه الورقةُ يُخصَم منها
 * أجر.** فسطرٌ أحمرُ باسم رجلٍ حضر ليس عيباً في برنامج — هو مالٌ يُؤخذ
 * من صاحبه. ولذلك القاعدةُ هنا صريحة:
 *
 *     حيث يستوي الاحتمالان يُفسَّر الشكُّ لصالح الإنسان.
 *
 * فكلُّ شكٍّ في التغطية أو الهويّة يُنزِل الحكمَ من «لم يبصم» الأحمر إلى
 * حالٍ رماديةٍ تقول «لا نعرف» — ولا يُرفع إلى الاتّهام إلا حين لا يبقى
 * تفسيرٌ آخر.
 */
class AbsenceJudge
{
    /* ── الحالات ───────────────────────────────────────────────────── */

    /** بصم ويومُه تامّ — ليس من صنع هذا الملفّ */
    public const FULL = 'full';

    /** بصم مرّةً واحدة — ليس من صنع هذا الملفّ */
    public const PARTIAL = 'partial';

    /** لم يبصم، واليومُ انقضى، والموقعُ كان مغطّى — وهذا وحدَه اتّهام */
    public const ABSENT = 'absent';

    /** لم يبصم بعدُ — واليومُ لم ينقضِ أو لم تُرفع بصماتُه */
    public const PENDING = 'pending';

    /** بصم، لكن في موقعٍ آخر */
    public const ELSEWHERE = 'elsewhere';

    /** لم يبصم، لكن تغطيةَ الموقع ناقصةٌ ذلك اليوم — فلا يُحكم */
    public const NODATA = 'nodata';

    /** في إجازةٍ مسجَّلة — يُرى ولا يُتَّهم */
    public const ON_LEAVE = 'on_leave';

    /** نُقل إلى هذا الموقع ولم تصل منه بصمةٌ بعد — يُرى ولا يُتَّهم */
    public const AWAITING = 'awaiting';

    /** الحالاتُ التي يصنعها هذا الملفّ — أي ما لا نبضةَ تحته */
    public const SYNTHETIC = [
        self::ABSENT, self::PENDING, self::ELSEWHERE, self::NODATA,
        self::ON_LEAVE, self::AWAITING,
    ];

    /** النصُّ في الشاشة والتصدير معاً — لسانٌ واحد */
    public const LABELS = [
        self::FULL      => 'تام',
        self::PARTIAL   => 'بصمةٌ واحدة',
        self::ABSENT    => 'لم يبصم',
        self::PENDING   => 'لم يبصم بعد',
        self::ELSEWHERE => 'بصم في موقعٍ آخر',
        self::NODATA    => 'لا بيانات من الموقع',
        self::ON_LEAVE  => 'في إجازة',
        self::AWAITING  => 'نُقل ولم يبصم بعد',
    ];

    /** صنفُ الشارة — والأحمرُ للاتّهام وحدَه */
    public const BADGES = [
        self::FULL      => 'bg-success-subtle text-success-emphasis',
        self::PARTIAL   => 'bg-warning-subtle text-warning-emphasis',
        self::ABSENT    => 'bg-danger-subtle text-danger-emphasis',
        self::PENDING   => 'bg-secondary-subtle text-secondary-emphasis',
        self::ELSEWHERE => 'bg-info-subtle text-info-emphasis',
        self::NODATA    => 'bg-secondary-subtle text-secondary-emphasis',

        /*
         * والإجازةُ لونُها لا لونٌ سادس: الأحمرُ والأصفرُ والأزرقُ
         * والرماديُّ مشغولةٌ كلُّها، ولونٌ سادسٌ لا يُفرَّق على ورقةٍ
         * مصوَّرة. وتُميَّز بنمطِ حدٍّ مقطَّعٍ في الصفّ — ونمطُ الحدّ لا
         * يُسقطه طابعٌ أبيضُ وأسود.
         */
        self::ON_LEAVE  => 'bg-light text-dark border',

        /*
         * رماديٌّ لا أحمر: الرجلُ منقولٌ بأمرِ صاحب العمل، وتأخُّرُ تسجيل
         * إصبعه على جهاز الموقع الجديد تقصيرُ مشرفٍ لا غيابُ عامل.
         * واتّهامُ رجلٍ بتقصير غيره هو ما بُني هذا الملفُّ على رفضه.
         */
        self::AWAITING  => 'bg-secondary-subtle text-secondary-emphasis',
    ];

    public static function synthetic(string $kind): bool
    {
        return in_array($kind, self::SYNTHETIC, true);
    }

    public static function label(string $kind): string
    {
        return self::LABELS[$kind] ?? $kind;
    }

    public static function badge(string $kind): string
    {
        return self::BADGES[$kind] ?? 'bg-secondary-subtle text-secondary-emphasis';
    }

    /**
     * هويّاتُ شخصٍ — لا هويّةٌ واحدة.
     *
     * والجمعُ ضرورةٌ لا احتياط: رقمُ الموظف في دابوق يُكتب بيدٍ وقد يُترك
     * فارغاً، و`Pin::importFromDevice` يملأ الاسمَ ويترك الرقمَ فارغاً
     * دائماً. فجهازٌ نُقل من موقعٍ إلى موقعٍ يُنشئ في الجديد صفَّ ربطٍ بلا
     * رقمٍ مرجعيّ، وفي القديم صفٌّ كتب فيه المالكُ الرقم.
     *
     * فلو قيست الهويّةُ بواحدٍ منهما — «الرقمُ إن كان وإلا الاسم» — قُورن
     * `r|E-120` بـ`n|محمد` فلم يتطابقا أبداً، **في الحالة التي بُنيت لها
     * المقارنةُ نفسُها**. فيُوسَم بالغياب في موقعه الأوّل كلَّ يومٍ عمله في
     * الثاني.
     *
     * @return array<int, string>
     */
    public static function identities(?string $ref, ?string $name): array
    {
        $keys = [];

        if ($ref !== null && trim($ref) !== '') {
            $keys[] = 'r|'.trim($ref);
        }

        if ($name !== null && trim($name) !== '') {
            $keys[] = 'n|'.trim($name);
        }

        return $keys;
    }

    /**
     * صفوفُ من لم يبصم.
     *
     * @param  array{
     *   roster: array<int, array<string, mixed>>,
     *   present: array<string, bool>,
     *   calendar: array<string, string>,
     *   anywhere: array<string, array<string, array<int, int|null>>>,
     *   coverage: array<string, bool>,
     *   site_names: array<int, string>,
     *   current: string,
     *   settled_through: string
     * }  $in
     * @return array<int, array<string, mixed>>
     */
    public static function rows(array $in): array
    {
        $roster   = $in['roster'] ?? [];
        $present  = $in['present'] ?? [];
        $calendar = $in['calendar'] ?? [];
        $anywhere = $in['anywhere'] ?? [];
        $coverage = $in['coverage'] ?? [];
        $names    = $in['site_names'] ?? [];
        $current  = (string) ($in['current'] ?? '');
        $settled  = (string) ($in['settled_through'] ?? '');

        /* أيامُ الدوام وحدَها — الجمعةُ والعطلةُ ليستا غياباً */
        $workdays = [];

        foreach ($calendar as $date => $kind) {
            if ($kind === 'working') {
                $workdays[] = (string) $date;
            }
        }

        $out = [];

        foreach ($roster as $person) {
            $site = $person['site_id'];
            $pin  = (string) $person['device_pin'];
            $ids  = self::identities($person['employee_ref'] ?? null, $person['employee'] ?? null);
            $serves = $person['serves'] ?? [['from' => '0000-01-01', 'to' => null]];

            /*
             * `awaiting` تاريخُ آخرِ وصولٍ إلى هذا الصفّ، و`seen_from`
             * أوّلُ بصمةٍ وصلت منه بعده. وبهما يُقصّ الانتظارُ قصّاً
             * مؤرَّخاً — لا يُلغى بأثرٍ رجعيّ.
             */
            $arrived  = $person['awaiting'] ?? null;
            $seenFrom = $person['seen_from'] ?? null;

            /*
             * `until` آخرُ يومٍ له في العمل — **داخلٌ في الحساب**. فمن تركَ
             * في الخامس عشر يُسأل عن الخامس عشر ولا يُسأل عمّا بعده.
             *
             * ويصل محلولاً من `Employment::until()` على عنقود صفوفه كلِّها،
             * لا مقروءاً من صفّه وحدَه — وإلا نُسي صفٌّ يوماً فخرج رجلٌ
             * تركَ العملَ في سطرٍ أحمر.
             */
            $until  = $person['until'] ?? null;
            $leaves = $person['leaves'] ?? [];

            foreach ($workdays as $date) {
                /*
                 * ١) يومٌ لم يجئ بعد.
                 *
                 * والمدى يقبل تاريخاً في المستقبل، ولا يُحكم بغيابٍ في يومٍ
                 * لم يقع. وهذا أوّلُ الشروط لأنه أوضحُها بطلاناً.
                 */
                if ($date > $current) {
                    continue;
                }

                /*
                 * ٢) أكان هذا الصفُّ موضعَ السؤال في هذا اليوم؟
                 *
                 * وكان الشرطُ «قبل أن يُربط رقمُه» يُقاس بتاريخ نشأة الصفّ
                 * وحدَه، وذاك يكفي حين لا ينتقل أحد. فلمّا صار النقلُ
                 * مسجَّلاً صار للصفّ **مُدَدٌ** لا مدّةٌ واحدة: الرجلُ قد
                 * يغادر الموقعَ ثم يعود إليه.
                 *
                 * ولا يصحّ إبقاءُ `$date < $since` بجانبه: صفُّ الموقع
                 * الجديد يُنشأ اليومَ والنقلُ مؤرَّخٌ قبل أيام، فلو قُصَّ
                 * بتاريخ النشأة لسقطت الأيامُ المؤرَّخةُ من السؤال —
                 * **صمتٌ**، وهو أخبثُ من سطرٍ أحمرَ كاذب.
                 */
                if (! Employment::servesOn($serves, $date)) {
                    continue;
                }

                /*
                 * ٣) بعد أن تركَ العمل — لم يعد من الموقع.
                 *
                 * **ولا سطرَ له أصلاً، لا سطرٌ رماديّ.** فمن تركَ العملَ في
                 * يونيو له في كشف الشهر اثنان وعشرون سطراً، وخمسون تركوا
                 * يصيرون ألفاً ومئة — وذاك الطوفانُ الذي بُني له حارسُ
                 * «الراكد» أصلاً، فيُطفئ المالكُ الميزةَ ولا يعود.
                 * وعددُهم يُقال في رأس القسم بدلاً من سطورهم.
                 *
                 * **وهذه مدّةٌ لا عَلَم**: يُفحص كلُّ يومٍ على حدة، لا
                 * يُرشَّح الرجلُ من القائمة. فالورقةُ تُقرأ في نوفمبر عن
                 * أكتوبر، والسؤالُ دائماً «أكان موظفاً **في ذلك اليوم**؟».
                 * فمن تركَ في الخامس عشر يبقى في أيامه الأربعةَ عشرَ
                 * الأولى — وإعادةُ طبع الورقة لا تُسقطه مما صُرف عليه أجرُه.
                 */
                if ($until !== null && $date > $until) {
                    continue;
                }

                /* ٣) بصم هنا بهذا الرقم — لا سطرَ له */
                if (isset($present[$site.'|'.$pin.'|'.$date])) {
                    continue;
                }

                /*
                 * ٤) أبصم في مكانٍ ما بإحدى هويّاته؟
                 *
                 * والسؤالُ على **الوجود** لا على المخالفة — وهذا موضعُ ثقبٍ
                 * كُشف قبل الكتابة: لو سُئل «أبصم في موقعٍ **يخالف** موقعَه؟»
                 * لسقط من بصم في الموقع نفسِه **برقمٍ آخر** بين الشرطين:
                 * الثالثُ لا يُطابقه (الرقمُ مختلف) والرابعُ لا يُفعَّل
                 * (الموقعُ لا يخالف) — فيُوسَم غائباً.
                 *
                 * وهذا يقع كثيراً: جهازٌ يُعطَب فيُعاد تسجيلُ الإصبع برقمٍ
                 * جديدٍ ويبقى القديمُ مفعَّلاً. فيخرج في الورقة الواحدة
                 * حضورُه تامّاً وغيابُه أحمرَ — باسمه مرّتين.
                 */
                $sites = self::punchedAt($anywhere, $date, $ids);

                if ($sites !== []) {
                    /*
                     * بصم هنا برقمٍ آخر، أو بصم على جهازٍ لم يُنسب إلى موقعٍ
                     * بعد (`null`). وكلاهما حضورٌ لا غياب.
                     *
                     * و`null` تُبرّئ عمداً: نبضةُ جهازٍ جديدٍ تُخزَّن بلا
                     * موقع، فتُطبع في قسم «بلا موقع» من الورقة نفسِها —
                     * فيكون في الصفحة بصمتُه وغيابُه معاً. والنقصُ الإداريُّ
                     * لا يصير تهمة.
                     */
                    if (in_array($site, $sites, true) || in_array(null, $sites, true)) {
                        continue;
                    }

                    $out[] = self::row($person, $date, self::ELSEWHERE, self::siteName($names, $sites));

                    continue;
                }

                /*
                 * ٥) في إجازةٍ مسجَّلة.
                 *
                 * **وموضعُها بعد التبرئة بالبصمة لا قبلها** — وهذا مقصود:
                 * من بصم وهو مسجَّلٌ في إجازة فقد حضر، والبصمةُ واقعةٌ
                 * والإجازةُ ورقة. فيُطبع حضورُه أخضرَ كما هو، ويبقى
                 * التناقضُ ظاهراً لمن يقرأ — فيُراجَع التاريخُ أو يُحتسب
                 * له اليوم.
                 *
                 * **وسطرٌ يُكتب لا سكوت**: المالكُ سأل أن *يرى* من هو في
                 * إجازة، والسكوتُ لا يُفرَّق عن «لم ينظر أحد». والسطرُ
                 * يُراجع نفسَه: سطرٌ رماديٌّ في يومٍ عمله الرجلُ يقول إن
                 * التواريخ خطأ.
                 */
                /*
                 * وتُستعار من `Employment` ولا تُكرَّر هنا: منطقٌ واحدٌ في
                 * موضعين يفترق يوماً ولا يُدرى. والاسمُ كاملٌ بلا `use`،
                 * فالصنفان في نطاقٍ واحد — والملفُّ يبقى خالصاً يُقرأ
                 * بـ`require`.
                 */
                $leave = Employment::leaveOn($leaves, $date);

                if ($leave !== null) {
                    $out[] = self::row($person, $date, self::ON_LEAVE, null, $leave);

                    continue;
                }

                /*
                 * ٦) اليومُ لم يُحسم بعد — جارٍ، أو لم تمضِ مهلةُ الرفع.
                 *
                 * فالجهازُ يدفع بعد وقوع البصمة لا قبلها، وموقعٌ انقطع
                 * إنترنتُه ليلاً يرفع صباحاً. فالحكمُ على يومٍ قبل أن تصل
                 * بصماتُه حكمٌ على الصمت.
                 */
                if ($date > $settled) {
                    $out[] = self::row($person, $date, self::PENDING, null);

                    continue;
                }

                /*
                 * ٦) تغطيةُ الموقع ناقصةٌ ذلك اليوم.
                 *
                 * ولا تُقاس التغطيةُ بمجموع نبضات الموقع — وهذا ثقبٌ ثانٍ
                 * كُشف قبل الكتابة: في الموقع خمسةُ أجهزةٍ على بوّابات، فلو
                 * مات أحدُها بقي المجموعُ عالياً بنبضات الأخرى، فيُوسَم
                 * عشرون رجلاً من بوّابته بالغياب في يومٍ عملوه كلُّهم.
                 *
                 * فالتغطيةُ تُقاس **بالأجهزة**: جهازٌ حيٌّ سكت يوماً يُنزِل
                 * حكمَ ذلك اليوم كلَّه في موقعه إلى «لا نعرف».
                 */
                if (($coverage[$site.'|'.$date] ?? false) !== true) {
                    $out[] = self::row($person, $date, self::NODATA, null);

                    continue;
                }

                /*
                 * ٩) نُقل إلى هنا ولم تصل منه بصمةٌ بعد.
                 *
                 * **وموضعُها آخرَ السلسلة مقصود**: كلُّ حكمٍ قائمٍ قبلها لا
                 * يتغيّر بحرف، وهي لا تأخذ إلا ما كان سيخرج أحمر.
                 *
                 * **ويُقصّ الانتظارُ بأوّل بصمةٍ بعد الوصول لا بآخرِ بصمة.**
                 * وهذا نقضٌ كُشف قبل الكتابة: لو قيس بآخر بصمةٍ لانقلب
                 * الحكمُ بأثرٍ رجعيّ — تُطبع الورقةُ يومَ ٢٤ فتقول «نُقل
                 * ولم يبصم» رماديّاً، ثم تُعاد طباعتُها في نوفمبر — وهو
                 * وقتُ تقرير الأجر — فتقول «لم يبصم» أحمرَ عن الأيام
                 * نفسِها. **وورقةٌ تُغيّر حكمَها عند إعادة طبعها لا يُوثق
                 * بها في شيء.**
                 */
                if ($arrived !== null && $date >= $arrived
                    && ($seenFrom === null || $date < $seenFrom)) {
                    $out[] = self::row($person, $date, self::AWAITING, moved: ['since' => $arrived]);

                    continue;
                }

                /* ١٠) ولم يبقَ تفسير */
                $out[] = self::row($person, $date, self::ABSENT, null);
            }
        }

        return $out;
    }

    /**
     * المواقعُ التي بصم فيها بإحدى هويّاته ذلك اليوم.
     *
     * @param  array<string, array<string, array<int, int|null>>>  $anywhere
     * @param  array<int, string>  $ids
     * @return array<int, int|null>
     */
    private static function punchedAt(array $anywhere, string $date, array $ids): array
    {
        $found = [];

        foreach ($ids as $id) {
            foreach ($anywhere[$date][$id] ?? [] as $site) {
                $found[] = $site;
            }
        }

        return $found;
    }

    /**
     * اسمُ أوّلِ موقعٍ معروفٍ — ليُقال «بصم في المرقاب» لا «بصم في ٧».
     *
     * @param  array<int, string>  $names
     * @param  array<int, int|null>  $sites
     */
    private static function siteName(array $names, array $sites): ?string
    {
        foreach ($sites as $site) {
            if ($site !== null && isset($names[$site])) {
                return $names[$site];
            }
        }

        return null;
    }

    /**
     * صفٌّ مطابقٌ لصفوف `DayBuilder` مفتاحاً بمفتاح.
     *
     * والمطابقةُ شرطٌ لا زينة: الصفوفُ تُخلط في مجموعةٍ واحدةٍ ثم تُرتَّب
     * وتُقسَم وتُصدَّر، ويقرؤها قالبٌ واحد. فمفتاحٌ ناقصٌ في صفٍّ واحدٍ
     * يُسقط الصفحة.
     *
     * و`complete => false` هنا لا يعني «بصمةٌ واحدة» — ولذلك **لا يُقرأ
     * `complete` بعد اليوم في عدٍّ ولا في عرض**، إنما `kind`. وإلا صار
     * الغائبُ يُعدّ في «الناقص» ويُعرض وسمُه «بصمةٌ واحدة» — وهي كذبةٌ
     * على رجلٍ لم يبصم أصلاً.
     *
     * @param  array<string, mixed>  $person
     * @return array<string, mixed>
     */
    private static function row(
        array $person,
        string $date,
        string $kind,
        ?string $atSite = null,
        ?array $leave = null,

        /*
         * **السادسُ، ولا يُمرَّر بالموضع أبداً.**
         *
         * فالخامسُ خانةُ الإجازة، ونداءٌ بالموضع يُصيبها بهذه فتختفي
         * مدّةُ الإجازة من كل سطرٍ رماديٍّ في الكشف. فيُنادى بالاسم:
         * `self::row(..., moved: $x)`.
         */
        ?array $moved = null,
    ): array {
        return [
            'site_id'      => $person['site_id'],
            'device_pin'   => (string) $person['device_pin'],
            'employee'     => $person['employee'] ?? null,
            'employee_ref' => $person['employee_ref'] ?? null,
            'job_title'    => $person['job_title'] ?? null,
            'work_date'    => $date,
            'first_at'     => null,
            'last_at'      => null,
            'first_time'   => '—',
            'last_time'    => '—',
            'punches'      => 0,
            'complete'     => false,
            'minutes'      => null,
            'devices'      => [],
            'kind'         => $kind,
            'at_site'      => $atSite,

            /* مدّةُ الإجازة ونوعُها — تُعرض تحت الوسم فتُراجَع */
            'leave'        => $leave,

            /* تاريخُ الوصول إلى هذا الموقع — يُعرض تحت «نُقل ولم يبصم» */
            'moved'        => $moved,
        ];
    }
}
