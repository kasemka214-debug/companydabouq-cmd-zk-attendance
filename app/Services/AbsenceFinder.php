<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Holiday;
use App\Models\Pin;
use App\Models\PinLeave;
use App\Models\PinMove;
use App\Models\Punch;
use App\Models\Site;
use App\Support\AbsenceJudge;
use App\Support\Employment;
use App\Support\Clock;

/**
 * جمعُ ما يحتاجه الحكمُ بالغياب من القاعدة — والحكمُ نفسُه في `AbsenceJudge`.
 *
 * والقسمةُ كقسمة حارس الساعة: الحكمُ خالصٌ يُفحص بالمحاكاة، وهذا يقرأ
 * ويُسلّم ولا يقرّر شيئاً.
 *
 * **وعددُ الاستعلامات ثابتٌ لا ينمو بالأسطر** — خمسةٌ مهما بلغ عددُ
 * العمّال أو الأيام. فكشفُ شهرٍ يُفتح في الصفحة نفسِها التي يُفتح فيها
 * كشفُ يوم.
 */
class AbsenceFinder
{
    /**
     * @param  array<string, mixed>  $f  مرشّحاتُ الكشف
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   calendar: array<string, mixed>,
     *   dormant: array<int, int>,
     *   notice: string|null
     * }
     */
    public function find(array $f): array
    {
        $from = (string) $f['from'];
        $to   = (string) $f['to'];

        $calendar = Holiday::calendar($from, $to);

        /*
         * والمفاتيحُ كلُّها حاضرةٌ في مسار الخروج المبكر أيضاً.
         * فمفتاحٌ ناقصٌ هنا يصير خطأَ نوعٍ في المتحكّم — وصفحةَ خمسِ مئةٍ
         * على كشف الأيام كلِّه حين تُفتح في جمعةٍ أو بمدّةٍ طويلة.
         */
        $empty = [
            'rows' => [], 'calendar' => $calendar,
            'dormant' => [], 'ended' => [], 'notice' => null,
        ];

        if ($calendar['working'] === 0) {
            return $empty;
        }

        /*
         * سقفُ المدّة.
         *
         * والعلّةُ ليست بطءَ الاستعلام — هو مجمَّعٌ ثابت — بل الورقة:
         * مئةُ عاملٍ في ستّةٍ وعشرين يومَ دوامٍ ألفان وستُّ مئةِ احتمال،
         * وما لم يحضره أصحابُه منها يُطبع. ومن أراد شهراً كاملاً فالتقريرُ
         * الشهريُّ مبنيٌّ له: صفٌّ لكل رجلٍ لا صفٌّ لكل يومٍ من أيامه.
         */
        $span = count($calendar['dates']);
        $cap  = (int) config('zk.absence.max_days', 31);

        if ($span > $cap) {
            return $empty + [
                'notice' => "المدّةُ {$span} يوماً، وحسابُ من لم يبصم يقف عند {$cap}. "
                    .'ضيّق المدّة، أو اقرأ التقريرَ الشهريّ — فهو صفٌّ لكل موظفٍ لا صفٌّ لكل يوم.',
            ];
        }

        /* ── الاستعلامات الخمسة ─────────────────────────────────────── */

        /*
         * (١) الأرقامُ المربوطةُ كلُّها — بلا ترشيحٍ بموقعٍ ولا برقم.
         *
         * فمنها يُبنى شيئان: قائمةُ من يُسأل عنهم (مرشَّحةً)، وجدولُ
         * الهويّات لحلّ صاحبِ كل نبضةٍ **في كل المواقع** (غيرَ مرشَّح).
         * ولو رُشّح الثاني لَما عُرف أن من بصم في موقعٍ آخرَ هو هو، فيُتَّهم
         * في موقعه.
         */
        $pins = Pin::query()->get([
            'id', 'site_id', 'device_pin', 'employee_name', 'employee_ref', 'job_title',
            'is_active', 'created_at', 'left_on', 'left_reason',
        ]);

        /* (٢) آخرُ بصمةٍ لكل رقمٍ على الإطلاق — لتمييز الراكد */
        $lastSeen = [];

        foreach (
            Punch::query()->toBase()
                ->selectRaw('site_id, device_pin, MAX(work_date) AS last_date')
                ->groupBy('site_id', 'device_pin')
                ->get() as $r
        ) {
            $lastSeen[$r->site_id.'|'.$r->device_pin] = self::day($r->last_date);
        }

        /*
         * (٣) مَن بصم، أين، ومتى — في المدّة، **بلا ترشيحٍ بتاتاً**.
         *
         * ولا يُرشَّح بموقعٍ ولا برقم: المقصودُ منه التبرئةُ لا العرض،
         * فترشيحُه يجعل مُرشِّحَ المستخدم سبباً في تهمة.
         */
        $punched = Punch::between($from, $to)->toBase()
            ->selectRaw('DISTINCT site_id, device_pin, work_date')
            ->get();

        /* (٤) نبضاتُ كل جهازٍ في كل يوم — لقياس التغطية */
        $deviceDays = [];

        foreach (
            Punch::between($from, $to)->toBase()
                ->selectRaw('device_id, work_date, COUNT(*) AS n')
                ->groupBy('device_id', 'work_date')
                ->get() as $r
        ) {
            $deviceDays[(int) $r->device_id][self::day($r->work_date)] = true;
        }

        /* (٥) الأجهزةُ ومواقعُها */
        $devices = Device::query()->get(['id', 'site_id']);

        /* ── التهيئة ────────────────────────────────────────────────── */

        $byPin = [];   // رقمُ الجهاز => هويّاتُ كل من يحمله في أيّ موقع
        $byKey = [];   // موقع|رقم  => هويّاتُ صاحبه

        foreach ($pins as $p) {
            $ids = AbsenceJudge::identities($p->employee_ref, $p->employee_name);

            $byKey[$p->site_id.'|'.$p->device_pin] = $ids;
            $byPin[$p->device_pin] = array_merge($byPin[$p->device_pin] ?? [], $ids);
        }

        $present  = [];
        $anywhere = [];

        foreach ($punched as $r) {
            $date = self::day($r->work_date);
            $site = $r->site_id === null ? null : (int) $r->site_id;
            $pin  = (string) $r->device_pin;

            if ($site !== null) {
                $present[$site.'|'.$pin.'|'.$date] = true;
            }

            /*
             * هويّةُ صاحب النبضة.
             *
             * ونبضةُ جهازٍ لم يُنسب إلى موقعٍ بعدُ لا موقعَ لها، فلا تُحلّ
             * بـ«موقع|رقم». فتُنسب حينئذٍ إلى **كل** من يحمل ذلك الرقمَ في
             * أيّ موقع. وقد تُبرّئ من لم يبصم — وهو الاتّجاهُ المقصود:
             * الخطأُ في الاتّهام أفدحُ من الخطأ في العذر.
             */
            $ids = $site !== null
                ? ($byKey[$site.'|'.$pin] ?? $byPin[$pin] ?? [])
                : ($byPin[$pin] ?? []);

            foreach ($ids as $id) {
                $anywhere[$date][$id][] = $site;
            }
        }

        /*
         * (٦) الإجازاتُ المتقاطعةُ مع المدّة — استعلامٌ واحدٌ لا لكل صفّ.
         *
         * وتُجمع بالعنقود لا بالصفّ: الواقعةُ تُكتب على صفٍّ واحدٍ وتسري
         * على إخوته بالهويّة. ولولا ذلك لنُسي صفٌّ يوماً فخرج رجلٌ في
         * إجازةٍ موقَّعة في سطرٍ أحمر.
         */
        $clusterOf = Employment::cluster($pins->map(fn ($p) => [
            'id'            => (int) $p->id,
            'employee_ref'  => $p->employee_ref,
            'employee_name' => $p->employee_name,
        ])->all());

        $leavesOfCluster = [];

        foreach (
            PinLeave::query()->overlapping($from, $to)->toBase()
                ->get(['pin_id', 'from_date', 'to_date', 'kind']) as $l
        ) {
            $cluster = $clusterOf[(int) $l->pin_id] ?? null;

            if ($cluster === null) {
                continue;
            }

            $leavesOfCluster[$cluster][] = [
                'from_date' => self::day($l->from_date),
                'to_date'   => self::day($l->to_date),
                'kind'      => $l->kind,
            ];
        }

        /* وصفوفُ كل عنقودٍ — منها يُحلّ «آخرُ يومٍ في العمل» */
        $rowsOfCluster = [];

        foreach ($pins as $p) {
            $rowsOfCluster[$clusterOf[(int) $p->id] ?? (int) $p->id][] = [
                'left_on' => $p->left_on === null ? null : self::day($p->left_on),
            ];
        }

        /*
         * (٧) سجلُّ النقل — استعلامٌ واحدٌ لا لكل صفّ.
         *
         * وكلُّ النقلات لا ما وقع في المدّة: مدّةُ خدمةٍ فُتحت قبل سنةٍ
         * تحكم أيامَ هذا الشهر. وعددُ النقلات عشراتٌ لا آلاف.
         */
        $events = [];
        $arrival = [];

        foreach (
            PinMove::query()->toBase()->get(['from_pin_id', 'to_pin_id', 'moved_on']) as $m
        ) {
            $on = self::day($m->moved_on);

            $events[(int) $m->from_pin_id][] = ['date' => $on, 'out' => true];
            $events[(int) $m->to_pin_id][]   = ['date' => $on, 'out' => false];

            /* آخرُ وصولٍ إلى هذا الصفّ — به يُفتح الانتظار */
            $to = (int) $m->to_pin_id;

            if (! isset($arrival[$to]) || $on > $arrival[$to]) {
                $arrival[$to] = $on;
            }
        }

        /*
         * (٨) أوّلُ بصمةٍ وصلت قبل المدّة — لمن نُقل إليه صفٌّ.
         *
         * ويُقصّ بها الانتظار: فلو قيس بآخر بصمةٍ لانقلب الحكمُ بأثرٍ
         * رجعيّ — تُطبع الورقةُ فتقول «نُقل ولم يبصم» رماديّاً، ثم تُعاد
         * طباعتُها بعد تسجيل إصبعه فتقول «لم يبصم» أحمرَ عن الأيام نفسِها.
         * **وورقةٌ تُغيّر حكمَها عند إعادة طبعها لا يُوثق بها في شيء.**
         *
         * والاستعلامُ مقصورٌ على أرقام المنقول إليهم — عددُها عددُ النقلات
         * لا عددُ العمّال — ويُترك كلّياً إن لم يكن ثَمّ نقلٌ أصلاً.
         */
        $punchedBefore = [];

        if ($arrival !== []) {
            $movedPins = $pins->whereIn('id', array_keys($arrival));

            foreach (
                Punch::query()->toBase()
                    ->where('work_date', '<', $from)
                    ->whereIn('device_pin', $movedPins->pluck('device_pin')->unique()->all())
                    ->whereIn('site_id', $movedPins->pluck('site_id')->unique()->all())
                    ->selectRaw('site_id, device_pin, MAX(work_date) AS last_before')
                    ->groupBy('site_id', 'device_pin')
                    ->get() as $r
            ) {
                $punchedBefore[$r->site_id.'|'.$r->device_pin] = self::day($r->last_before);
            }
        }

        /* ── التغطية: جهازٌ حيٌّ سكت يوماً يُسقط الحكمَ في موقعه ────── */        /* ── التغطية: جهازٌ حيٌّ سكت يوماً يُسقط الحكمَ في موقعه ────── */

        $coverage = $this->coverage($calendar['dates'], $devices, $deviceDays, $punched);

        /* ── الروستر: المفعَّلُ غيرُ الراكد ─────────────────────────── */

        $dormantAfter = date(
            'Y-m-d',
            strtotime($from.' -'.(int) config('zk.absence.dormant_days', 60).' days'),
        );

        $roster  = [];
        $dormant = [];
        $ended   = [];

        foreach ($pins as $p) {
            if (! $p->is_active) {
                continue;
            }

            if ($f['site_id'] && (int) $p->site_id !== (int) $f['site_id']) {
                continue;
            }

            if ($f['pin'] !== '' && (string) $p->device_pin !== (string) $f['pin']) {
                continue;
            }

            $cluster = $clusterOf[(int) $p->id] ?? (int) $p->id;
            $born    = substr((string) $p->created_at, 0, 10) ?: '0000-01-01';
            $leaves  = $leavesOfCluster[$cluster] ?? [];

            /*
             * مُدَدُ خدمة هذا الصفّ — قائمةٌ لا واحدة، فالنقلُ العائد
             * أ ← ب ← أ يحتاج صفَّ (أ) إلى مدّتين.
             */
            $spans = Employment::serves($born, $events[(int) $p->id] ?? []);

            /*
             * وآخرُ وصولٍ إلى هذا الصفّ، وأوّلُ بصمةٍ بعده.
             *
             * فإن وصلت بصمةٌ قبل المدّة وبعد الوصول فالانتظارُ انقضى قبل
             * أن تبدأ الورقةُ أصلاً.
             */
            $arrivedOn = $arrival[(int) $p->id] ?? null;
            $seenFrom  = null;

            if ($arrivedOn !== null) {
                $before = $punchedBefore[$p->site_id.'|'.$p->device_pin] ?? null;

                if ($before !== null && $before >= $arrivedOn) {
                    /* بصم قبل أن تبدأ الورقةُ أصلاً — فالانتظارُ منقضٍ كلُّه */
                    $seenFrom = $arrivedOn;
                } else {
                    /*
                     * وإلّا فأوّلُ بصمةٍ **داخل المدّة** بعد وصوله — تُقرأ
                     * من `$present` بلا استعلامٍ جديد.
                     *
                     * ولا يُقاس بآخر بصمة: فمن سُجّل إصبعُه يوم ٢٢ تبقى
                     * أيامُ ٢٠ و٢١ رماديةً «نُقل ولم يبصم»، ولا تنقلب
                     * حمراءَ حين تُعاد طباعةُ الورقة في نوفمبر.
                     */
                    foreach ($calendar['dates'] as $d => $kind) {
                        if ($d >= $arrivedOn && isset($present[$p->site_id.'|'.$p->device_pin.'|'.$d])) {
                            $seenFrom = $d;

                            break;
                        }
                    }
                }
            }

            /*
             * «آخرُ يومٍ في العمل» يُحلّ على العنقود لا على الصفّ وحدَه —
             * فالواقعةُ تُكتب مرّةً وتسري على إخوته بالهويّة.
             *
             * و`since` تُمرَّر معه ليُطرح قرارٌ أقدمُ من نشأة الصفّ: رجلٌ
             * تركَ في مايو ثم عاد في سبتمبر بصفٍّ جديد لا يُورَّث عليه
             * قرارُ مايو، وإلا سقط من كل سؤالٍ إلى الأبد.
             */
            $until = Employment::until($rowsOfCluster[$cluster] ?? [], $spans[0]['from']);

            /* مَن انتهت خدمتُه قبل المدّة: يُعَدّ ولا يُسأل */
            if ($until !== null && $until < $from) {
                $ended[(int) $p->site_id] = ($ended[(int) $p->site_id] ?? 0) + 1;

                continue;
            }

            /*
             * الراكد: رقمٌ لم تصل منه بصمةٌ قطُّ، أو لم تصل منذ شهرين.
             *
             * وهو تخمينٌ وُضع حين لم يكن في النظام ما يقول مَن تركَ العمل.
             * فجدولُ الربط ليس قائمةَ العاملين — هو قائمةُ كلِّ من سُجّلت
             * بصمتُه في جهازٍ يوماً، والأسماءُ تصل من الأجهزة مفعَّلةً ولا
             * يُقلّمها أحد. فبغيره يخرج في كشف الشهر ألفا سطرٍ أحمر.
             *
             * **والقرارُ يسبق التخمين.** فمن سُجّلت له إجازةٌ تتقاطع مع
             * المدّة لا يُطبَّق عليه الحارسُ بتّةً — وهذا نقضٌ كُشف قبل
             * الكتابة كاد يُلغي الميزةَ من أصلها: عاملٌ مصريٌّ يسافر في
             * الإجازة الدورية أوّلَ أغسطس ويعود منتصفَ أكتوبر — وهي
             * القاعدةُ في مقاولات الكويت لا الشاذّة — آخرُ بصمةٍ له في
             * يوليو، فيطويه الحارسُ قبل أن يُسأل عنه. فلا يظهر له سطرُ
             * «في إجازة» ولا أثر، وقد سجّلها المالكُ بيده.
             *
             * **ولا يُطوى الراكدُ صامتاً**: عددُ المطويّين في رأس القسم.
             */
            $last = $lastSeen[$p->site_id.'|'.$p->device_pin] ?? null;

            /*
             * والمنقولُ حديثاً قرارٌ كغيره: صفٌّ نُقل إليه رجلٌ لم تصل منه
             * بصمةٌ في ذلك الموقع قطُّ يطويه الحارسُ قبل أن يُسأل عنه، فلا
             * يظهر «نُقل ولم يبصم» أصلاً — صمتٌ. والحدُّ ضروريٌّ مع ذلك:
             * نقلٌ عمرُه سنةٌ ولم يُنفَّذ لا يصبّ ألفَ سطرٍ رماديٍّ أبداً.
             */
            $decided = $until !== null
                || Employment::hasLeaveBetween($leaves, $from, $to)
                || ($arrivedOn !== null && $arrivedOn >= $dormantAfter);

            if (! $decided && ($last === null || $last < $dormantAfter)) {
                $dormant[(int) $p->site_id] = ($dormant[(int) $p->site_id] ?? 0) + 1;

                continue;
            }

            $roster[] = [
                'site_id'      => (int) $p->site_id,
                'device_pin'   => (string) $p->device_pin,
                'employee'     => $p->employee_name,
                'employee_ref' => $p->employee_ref,
                'job_title'    => $p->job_title,

                /* مُدَدُ خدمته في هذا الموقع — ومفتاحٌ واحدٌ للحقيقة */
                'serves'       => $spans,

                /* ومتى وصل إليه، ومتى أوّلُ بصمةٍ بعده */
                'awaiting'     => $arrivedOn,
                'seen_from'    => $seenFrom,

                /* ولا عن يومٍ بعد أن تركَ العمل */
                'until'        => $until,

                'leaves'       => $leaves,
            ];
        }

        /* ── الحكم ──────────────────────────────────────────────────── */

        $rows = AbsenceJudge::rows([
            'roster'     => $roster,
            'present'    => $present,
            'calendar'   => $calendar['dates'],
            'anywhere'   => $anywhere,
            'coverage'   => $coverage,
            'site_names' => Site::query()->pluck('name', 'id')->all(),
            'current'    => Clock::workDate(Clock::now()),
            'settled_through' => $this->settledThrough(),
        ]);

        return [
            'rows'     => $rows,
            'calendar' => $calendar,
            'dormant'  => $dormant,
            'ended'    => $ended,
            'notice'   => null,
        ];
    }

    /**
     * آخرُ يومٍ يجوز الحكمُ عليه بـ«لم يبصم».
     *
     * فالجهازُ يدفع بعد وقوع البصمة لا قبلها، وموقعٌ انقطع إنترنتُه ليلاً
     * يرفع صباحاً. فالحكمُ على يومٍ قبل أن تصل بصماتُه حكمٌ على الصمت لا
     * على الغياب.
     *
     * والمهلةُ تُطرح من الآن ثم يُؤخذ يومُ العمل ثم يُنقص يوم: الساعةَ
     * السابعةَ صباحاً يكون أمسُ قد حُسم، والساعةَ الخامسةَ فجراً لا يكون
     * — وبينهما ساعاتُ الرفع.
     */
    private function settledThrough(): string
    {
        $grace = (int) config('zk.absence.grace_hours', 3);

        /*
         * والحسابُ سطرٌ واحد: يومُ العمل الذي كنّا فيه قبل مهلةِ الرفع،
         * ناقصَ يوم.
         *
         *   السابعةَ والنصف صباحاً − ٣ ساعاتٍ = ٤:٣٠، ويومُ عملها اليوم،
         *   فالمحسومُ أمس.
         *   والخامسةَ فجراً − ٣ = ٢:٠٠، ويومُ عملها أمس (الحدُّ ٤:٠٠)،
         *   فالمحسومُ أوّلُ من أمس — وأمسُ ما زال «لم يبصم بعد».
         *
         * والنقصُ بـ`strtotime` على النصّ لا بـ`Clock`: `Clock::at` تُفسّر
         * غرينتش ثم تحوّل، فتُزحزح التاريخَ عند الحدود. وهنا لا منطقةَ
         * أصلاً — يومٌ ناقص يوم.
         */
        $day = Clock::workDate(Clock::now()->copy()->subHours($grace));

        return date('Y-m-d', strtotime($day.' -1 day'));
    }

    /**
     * أكانت تغطيةُ الموقع تامّةً ذلك اليوم؟
     *
     * **ولا تُقاس بمجموع نبضات الموقع.** في الموقع الواحد خمسةُ أجهزةٍ
     * على بوّابات — `DayBuilder` يقول ذلك صراحةً — فلو مات أحدُها بقي
     * المجموعُ عالياً بنبضات الأخرى، فيُوسَم كلُّ من يدخل من تلك البوّابة
     * بالغياب في يومٍ عملوه كلُّهم. عشرون يومَ أجرٍ تُخصم على كذب.
     *
     * فالقياسُ على الجهاز: جهازٌ رفع قبلَ اليومِ وبعدَه ولم يرفع فيه كان
     * ساكتاً — انقطعت شبكتُه أو كهرباؤه. فيسقط الحكمُ في موقعه ذلك اليوم
     * إلى «لا نعرف».
     *
     * @param  array<string, string>  $dates
     * @return array<string, bool>
     */
    private function coverage(array $dates, $devices, array $deviceDays, $punched): array
    {
        /* نشاطُ الموقع: هل وصلت منه بصمةٌ واحدةٌ ذلك اليوم؟ */
        $siteActive = [];

        foreach ($punched as $r) {
            if ($r->site_id !== null) {
                $siteActive[(int) $r->site_id.'|'.self::day($r->work_date)] = true;
            }
        }

        /* أيامُ السكوت لكل موقعٍ بسبب جهازٍ حيٍّ لم يرفع */
        $silent = [];

        foreach ($devices as $device) {
            if ($device->site_id === null) {
                continue;
            }

            $days = array_keys($deviceDays[(int) $device->id] ?? []);

            /*
             * جهازٌ له يومٌ واحدٌ أو لا شيء لا يُقاس عليه: لا يُعرف أكان
             * حيّاً فسكت أم لم يكن مركَّباً أصلاً. والسكوتُ لا يُفترض.
             */
            if (count($days) < 2) {
                continue;
            }

            sort($days);

            $first = $days[0];
            $last  = $days[count($days) - 1];
            $has   = array_flip($days);

            foreach ($dates as $date => $kind) {
                if ($kind !== 'working' || $date < $first || $date > $last) {
                    continue;
                }

                if (! isset($has[$date])) {
                    $silent[(int) $device->site_id.'|'.$date] = true;
                }
            }
        }

        $coverage = [];

        foreach ($devices as $device) {
            if ($device->site_id === null) {
                continue;
            }

            foreach ($dates as $date => $kind) {
                if ($kind !== 'working') {
                    continue;
                }

                $key = (int) $device->site_id.'|'.$date;

                $coverage[$key] = isset($siteActive[$key]) && ! isset($silent[$key]);
            }
        }

        return $coverage;
    }

    /**
     * تاريخٌ نصّاً `Y-m-d` مهما جاء.
     *
     * و`Punch::$casts` يصبّ `work_date` إلى Carbon، و`Carbon::__toString()`
     * يطبع `Y-m-d H:i:s`. فمفتاحٌ يُبنى بالدمج النصّيّ يصير
     * «5|2026-10-13 00:00:00» ولا يُطابق مفتاحاً من التقويم أبداً.
     *
     * وعطبُه **صامت**: لا استثناءَ ولا سطرَ خطأ — إنما كشفٌ كلُّه «لا
     * بيانات»، وورقةٌ تقول إن الأجهزة ميتةٌ وهي حيّة. ولذلك تُقصّ العشرةُ
     * الأولى صراحةً ولا يُوثق بالصبّ.
     */
    private static function day(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }
}
