<?php

/**
 * محاكاةُ قراءة دفعةٍ من جهاز ZKTeco.
 *
 * لا vendor/ على جهاز التطوير، فلا artisan ولا اختبارات. وهذا الملفُّ
 * يُشغّل الشيفرةَ الحقيقية — App\Support\Clock و App\Services\AttlogParser
 * كما هي بلا نسخ — فوق بديلٍ صغيرٍ لـ Carbon يكفي ما تستعملانه منه.
 *
 * والغرضُ ليس التوثيق: هو كشفُ ما لا يُرى بالعين — انزياحُ المنطقة
 * الزمنية، وحدُّ منتصف الليل، وأشكالُ الفصل بين الحقول.
 *
 *     C:\xampp\php\php.exe scripts/محاكاة-النبضات.php
 */

declare(strict_types=1);

// ── بديلُ Carbon: ما تستعمله الشيفرةُ منه لا أكثر ───────────────────────

namespace Illuminate\Support {

    class Carbon extends \DateTimeImmutable
    {
        public static function parse(string $time, $tz = null): self
        {
            return new self($time, $tz ? new \DateTimeZone($tz) : null);
        }

        public static function instance(\DateTimeInterface $d): self
        {
            return new self($d->format('Y-m-d H:i:s.u'), $d->getTimezone());
        }

        public static function now(?string $tz = null): self
        {
            return new self('now', new \DateTimeZone($tz ?: 'UTC'));
        }

        public function utc(): self
        {
            return $this->setTimezone(new \DateTimeZone('UTC'));
        }

        public function copy(): self
        {
            return $this;
        }

        #[\ReturnTypeWillChange]
        public function setTimezone($tz): self
        {
            return parent::setTimezone(is_string($tz) ? new \DateTimeZone($tz) : $tz);
        }

        public function lt(self $other): bool
        {
            return $this < $other;
        }

        public function subDay(): self
        {
            return $this->modify('-1 day');
        }

        public function toDateString(): string
        {
            return $this->format('Y-m-d');
        }

        public function diffInMinutes(self $other): int
        {
            return (int) round(abs($other->getTimestamp() - $this->getTimestamp()) / 60);
        }
    }
}

// ── بديلُ الدوالّ المساعدة ──────────────────────────────────────────────

namespace {

    $GLOBALS['__config'] = [
        'zk.device_timezone' => 'Asia/Kuwait',
        'zk.day_starts_at'   => '04:00',
        'app.display_timezone' => null,
    ];

    function config(string $key, $default = null)
    {
        return $GLOBALS['__config'][$key] ?? $default;
    }

    require __DIR__.'/../app/Support/Clock.php';
    require __DIR__.'/../app/Services/AttlogParser.php';

    use App\Services\AttlogParser;
    use App\Support\Clock;

    $pass = 0;
    $fail = 0;

    function check(string $what, $got, $want): void
    {
        global $pass, $fail;

        $ok = $got === $want;
        $ok ? $pass++ : $fail++;

        printf("%s %-58s %s%s\n",
            $ok ? '  ✓' : '  ✗',
            $what,
            is_scalar($got) || $got === null ? var_export($got, true) : gettype($got),
            $ok ? '' : '   ← المتوقَّع: '.var_export($want, true),
        );
    }

    $parser = new AttlogParser();

    // ══ ١ ══ التحويل من توقيت الجهاز إلى غرينتش ═══════════════════════════
    echo "\n─── ١) الجهازُ يرسل ساعتَه المحلية، والقاعدةُ تخزّن غرينتش ───\n";

    $one = $parser->parse("14\t2026-09-20 07:14:03\t0\t1\t0\t0\t0");

    check('عددُ الأسطر المقروءة', count($one['rows']), 1);
    check('الرقم', $one['rows'][0]['device_pin'], '14');
    check('اللحظةُ غرينتش (٧:١٤ كويتياً ← ٤:١٤ غرينتش)',
        $one['rows'][0]['punched_at']->format('Y-m-d H:i:s'), '2026-09-20 04:14:03');
    check('يومُ العمل', $one['rows'][0]['work_date'], '2026-09-20');
    check('النوع', $one['rows'][0]['state'], 0);
    check('طريقةُ التحقّق', $one['rows'][0]['verify_mode'], 1);

    // ══ ٢ ══ حدُّ منتصف الليل ══════════════════════════════════════════════
    echo "\n─── ٢) حدُّ اليوم عند ٤:٠٠ — ما قبله من نوبة أمس ───\n";

    $night = $parser->parse(implode("\n", [
        "7\t2026-09-20 23:50:00\t0\t1",   // ليلة ٢٠
        "7\t2026-09-21 01:30:00\t1\t1",   // بعد منتصف الليل — من يوم ٢٠
        "7\t2026-09-21 03:59:59\t1\t1",   // قُبيل الحدّ — من يوم ٢٠
        "7\t2026-09-21 04:00:00\t0\t1",   // عند الحدّ — يوم ٢١
    ]));

    check('٢٣:٥٠ من ليلة ٢٠', $night['rows'][0]['work_date'], '2026-09-20');
    check('٠١:٣٠ تُنسب إلى ٢٠',  $night['rows'][1]['work_date'], '2026-09-20');
    check('٠٣:٥٩:٥٩ تُنسب إلى ٢٠', $night['rows'][2]['work_date'], '2026-09-20');
    check('٠٤:٠٠:٠٠ تُنسب إلى ٢١', $night['rows'][3]['work_date'], '2026-09-21');

    // ══ ٣ ══ أشكالُ الفصل بين الحقول ═══════════════════════════════════════
    echo "\n─── ٣) الإصداراتُ تختلف في الفاصل: جدولة، مسافتان، مسافة ───\n";

    $tabbed  = $parser->parse("3\t2026-09-20 08:00:00\t1\t15\tW7");
    $spaced2 = $parser->parse('3  2026-09-20 08:00:00  1  15  W7');
    $spaced1 = $parser->parse('3 2026-09-20 08:00:00 1 15 W7');

    check('بالجدولة',  $tabbed['rows'][0]['punched_at']->format('H:i'), '05:00');
    check('بمسافتين',  $spaced2['rows'][0]['punched_at']->format('H:i'), '05:00');
    check('بمسافةٍ واحدة (التاريخُ والوقت يُلمّان)', $spaced1['rows'][0]['punched_at']->format('H:i'), '05:00');
    check('رمزُ العمل بمسافةٍ واحدة', $spaced1['rows'][0]['work_code'], 'W7');
    check('بصمةُ الوجه تُقرأ ١٥', $spaced1['rows'][0]['verify_mode'], 15);

    // ══ ٤ ══ السطرُ الفاسد يُعزل ولا يُسقط الدفعة ═══════════════════════════
    echo "\n─── ٤) سطرٌ فاسدٌ واحد لا يُسقط تسعةً صحيحة ───\n";

    $mixed = $parser->parse(implode("\n", [
        "1\t2026-09-20 06:00:00\t0\t1",
        '',
        'طلاسمُ لا تاريخَ فيها',
        "2\t2026-13-45 99:99:99\t0\t1",
        "\t2026-09-20 06:05:00\t0\t1",
        "3\t2026-09-20 06:10:00\t0\t1",
    ]));

    check('الصحيحُ مرّ',   count($mixed['rows']), 2);
    check('الفاسدُ عُزل',  count($mixed['bad']), 3);
    check('الفارغُ لم يُحتسب فاسداً', in_array('', $mixed['bad'], true), false);

    // ══ ٥ ══ قِيمٌ خارج المدى ══════════════════════════════════════════════
    echo "\n─── ٥) قِيمٌ خارج مدى البايت تُردّ إلى صفر لا تُخزَّن ───\n";

    $odd = $parser->parse("9\t2026-09-20 06:00:00\t999\t-4\t");

    check('النوعُ ٩٩٩ ← ٠',     $odd['rows'][0]['state'], 0);
    check('التحقّقُ -٤ ← ٠',     $odd['rows'][0]['verify_mode'], 0);
    check('رمزُ عملٍ فارغٍ ← null', $odd['rows'][0]['work_code'], null);

    // ══ ٦ ══ دفعةُ يومٍ كامل: القاعدةُ «أوّلٌ دخولٌ وآخرٌ خروج» ═════════════
    echo "\n─── ٦) يومُ عاملٍ بصم على جهازين في الموقع نفسِه ───\n";

    $day = $parser->parse(implode("\n", [
        "21\t2026-09-20 06:52:11\t0\t1",   // بوابة المخزن
        "21\t2026-09-20 12:01:40\t0\t1",   // بصم للغداء ولم يضغط زرّ الخروج
        "21\t2026-09-20 12:44:05\t0\t1",
        "21\t2026-09-20 17:08:30\t0\t1",   // بوابة الإدارة — خروج، والزرُّ «دخول»
    ]));

    $times = array_map(fn ($r) => $r['punched_at']->getTimestamp(), $day['rows']);
    sort($times);

    $minutes = (int) round(($times[count($times) - 1] - $times[0]) / 60);

    check('أربعُ نبضاتٍ في يومٍ واحد', count($day['rows']), 4);
    check('كلُّها يومُ عملٍ واحد', count(array_unique(array_column($day['rows'], 'work_date'))), 1);
    check('المدّة من أوّلِها إلى آخرِها', intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT), '10:16');
    check('كلُّها موسومةٌ «دخول» من الجهاز',
        count(array_unique(array_column($day['rows'], 'state'))) === 1
            && $day['rows'][0]['state'] === 0, true);

    // ══ ٧ ══ المناوبةُ الليلية عند حدٍّ مضبوطٍ خطأً ══════════════════════════
    echo "\n─── ٧) حدُّ اليوم ومناوبةُ الليل — أثرُ الإعداد ───\n";

    $GLOBALS['__config']['zk.day_starts_at'] = '04:00';
    $a = $parser->parse("30\t2026-09-20 20:00:00\t0\t1\n30\t2026-09-21 05:00:00\t1\t1");

    check('بحدّ ٤:٠٠ — الدخولُ يوم ٢٠', $a['rows'][0]['work_date'], '2026-09-20');
    check('بحدّ ٤:٠٠ — الخروجُ ينزلق إلى ٢١ فينشطر اليوم', $a['rows'][1]['work_date'], '2026-09-21');

    $GLOBALS['__config']['zk.day_starts_at'] = '06:00';
    $b = $parser->parse("30\t2026-09-20 20:00:00\t0\t1\n30\t2026-09-21 05:00:00\t1\t1");

    check('بحدّ ٦:٠٠ — الدخولُ يوم ٢٠', $b['rows'][0]['work_date'], '2026-09-20');
    check('بحدّ ٦:٠٠ — الخروجُ يوم ٢٠ فيلتئم', $b['rows'][1]['work_date'], '2026-09-20');

    $GLOBALS['__config']['zk.day_starts_at'] = '04:00';

    // ══ ٨ ══ التكرار — ما يعتمد عليه القيدُ الفريد ══════════════════════════
    echo "\n─── ٨) الدفعةُ المُعادة تعطي المفاتيح نفسَها فيبتلعها القيد ───\n";

    $first  = $parser->parse("5\t2026-09-20 07:00:00\t0\t1");
    $repeat = $parser->parse("5\t2026-09-20 07:00:00\t0\t1");

    $keyOf = fn (array $r) => $r['device_pin'].'@'.$r['punched_at']->format('Y-m-d H:i:s');

    check('مفتاحُ النبضة واحدٌ في المرّتين',
        $keyOf($first['rows'][0]) === $keyOf($repeat['rows'][0]), true);

    // ══ ٩ ══ سجلُّ جهازِ التحكّم بالدخول — صيغةُ key=value ══════════════════
    echo "\n─── ٩) جهازُ التحكّم (DeviceType=acc) يرسل transaction لا ATTLOG ───\n";

    $acc = $parser->parseAccess(implode("\n", [
        "time=2026-09-24 07:02:11\tpin=14\tcardno=0\teventaddr=1\tevent=0\tinoutstate=0\tverifytype=1\tindex=871",
        "time=2026-09-24 16:31:40\tpin=14\tcardno=0\teventaddr=1\tevent=0\tinoutstate=1\tverifytype=15\tindex=872",
    ]));

    check('سطران مقروءان', count($acc['rows']), 2);
    check('الرقمُ من حقل pin', $acc['rows'][0]['device_pin'], '14');
    check('الوقتُ حُوِّل إلى غرينتش', $acc['rows'][0]['punched_at']->format('Y-m-d H:i:s'), '2026-09-24 04:02:11');
    check('inoutstate ← النوع (خروج)', $acc['rows'][1]['state'], 1);
    check('verifytype ١٥ ← بصمةُ وجه', $acc['rows'][1]['verify_mode'], 15);
    check('كلاهما يومُ عملٍ واحد', $acc['rows'][0]['work_date'] === $acc['rows'][1]['work_date'], true);

    // ══ ١٠ ══ أحداثُ الباب ليست بصمات ══════════════════════════════════════
    echo "\n─── ١٠) أحداثُ الباب في الجدول نفسِه — تُعزل ولا تُخزَّن ───\n";

    $doors = $parser->parseAccess(implode("\n", [
        "time=2026-09-24 08:00:00\tpin=0\tcardno=0\teventaddr=1\tevent=6\tinoutstate=2\tverifytype=200",
        "time=2026-09-24 08:00:05\tcardno=0\teventaddr=1\tevent=8\tinoutstate=2",
        "time=2026-09-24 08:01:00\tpin=21\tcardno=0\teventaddr=1\tevent=0\tinoutstate=0\tverifytype=1",
    ]));

    check('البصمةُ وحدَها مرّت',    count($doors['rows']), 1);
    check('صاحبُها الرقم ٢١',      $doors['rows'][0]['device_pin'], '21');
    check('حدثا البابِ عُزلا',      count($doors['bad']), 2);

    // ══ ١١ ══ الترتيبُ لا يهمّ في هذه الصيغة ════════════════════════════════
    echo "\n─── ١١) الأسماءُ تحمل المعنى، فاختلافُ الترتيب لا يضرّ ───\n";

    $shuffled = $parser->parseAccess(
        "verifytype=4\tpin=77\tinoutstate=1\tindex=9\ttime=2026-09-24 13:00:00\tevent=0"
    );

    check('قُرئ رغم اختلاف الترتيب', count($shuffled['rows']), 1);
    check('الرقم',    $shuffled['rows'][0]['device_pin'], '77');
    check('الوقت',    $shuffled['rows'][0]['punched_at']->format('H:i'), '10:00');
    check('البطاقةُ ٤', $shuffled['rows'][0]['verify_mode'], 4);

    // ══ ١٢ ══ أسماءُ المستخدمين من الجهاز ═══════════════════════════════════
    echo "\n─── ١٢) الأسماءُ المخزَّنة في الجهاز تُقرأ من سجلّ العمليات ───\n";

    $users = $parser->parseUsers(implode("\n", [
        "USER PIN=14\tName=أحمد محمد\tPri=0\tPasswd=\tCard=0\tGrp=1\tTZ=0000000100000000",
        "PIN=21\tName=سالم عبدالله\tPri=0\tCard=4521\tGrp=1",          // بلا بادئة USER
        "USER PIN=99\tName=\tPri=0\tCard=0",                            // بلا اسم
        "USER PIN=0\tName=مدير\tPri=14",                                // رقمٌ صفر
        "FP PIN=14\tFID=0\tTMP=Uf9jAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",   // قالبُ بصمة
        "OPLOG 4\t1\t2026-09-24 08:00:00\t0\t0",                        // عمليةٌ إدارية
    ]));

    check('قُرئ اثنان فقط',           count($users), 2);
    check('لا أثرَ لقالب البصمة',     in_array('Uf9jAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', array_column($users, 'name'), true), false);
    check('الأول باسمه',               $users[0]['name'], 'أحمد محمد');
    check('رقمُه',                     $users[0]['device_pin'], '14');
    check('الثاني قُرئ بلا بادئة USER', $users[1]['name'], 'سالم عبدالله');
    check('وبطاقتُه',                  $users[1]['card'], '4521');
    check('بطاقةُ الأول صفرٌ ← null',  $users[0]['card'], null);

    /*
     * الاسمُ الفارغ والرقمُ صفر مرفوضان، وقوالبُ البصمات وسجلُّ العمليات
     * لا تُقرأ أصلاً — وإلا لدخل في «ربط الأرقام» صفوفٌ لا معنى لها.
     */
    check('الاسمُ الفارغ رُفض',        in_array('99', array_column($users, 'device_pin'), true), false);
    check('الرقمُ صفرٌ رُفض',          in_array('0',  array_column($users, 'device_pin'), true), false);
    check('قالبُ البصمة لم يُقرأ',     count($users) === 2, true);

    // ══ ١٣ ══ صيغةُ أجهزة التحكّم للمستخدمين ════════════════════════════════
    echo "\n─── ١٣) جهازُ التحكّم يرسل المستخدمين بترتيبٍ آخرَ وببادئةٍ صغيرة ───\n";

    $accUsers = $parser->parseUsers(implode("\n", [
        "user Name=خالد الفهد\tPIN=31\tCardNo=0\tPassword=\tGroup=1\tPrivilege=0",
        "Name=منصور\tPIN=32\tCardNo=8890\tPrivilege=0",
        "user PIN=33\tCardNo=0\tPrivilege=0",                      // بلا اسم
    ]));

    check('قُرئ اثنان',                       count($accUsers), 2);
    check('الاسمُ سابقٌ للرقم ومع ذلك قُرئ', $accUsers[0]['name'], 'خالد الفهد');
    check('رقمُه',                             $accUsers[0]['device_pin'], '31');
    check('والثاني بلا بادئةٍ أصلاً',        $accUsers[1]['name'], 'منصور');
    check('وبطاقتُه',                          $accUsers[1]['card'], '8890');
    check('وما لا اسمَ له رُفض',             in_array('33', array_column($accUsers, 'device_pin'), true), false);

    // ══ ١٤ ══ جسدُ إقرارِ الأمر — قد تأتي فيه الأسماء ═══════════════════════
    echo "\n─── ١٤) قراءةُ جسد الإقرار: تلتقط الأسماء ولا تختلق شيئاً ───\n";

    $pureAck = $parser->parseUsers('ID=12&Return=1&CMD=DATA');

    check('إقرارٌ خالصٌ لا يُنتج أسماء', count($pureAck), 0);

    $ackWithData = $parser->parseUsers(implode("\n", [
        'ID=12&Return=1&CMD=DATA',
        "user PIN=7\tName=فهد الرشيد\tCardNo=0\tPrivilege=0",
        "user PIN=8\tName=بدر العتيبي\tCardNo=1201\tPrivilege=0",
    ]));

    check('والأسماءُ فيه تُلتقط', count($ackWithData), 2);
    check('أوّلُها',              $ackWithData[0]['name'], 'فهد الرشيد');
    check('وبطاقةُ الثاني',       $ackWithData[1]['card'], '1201');

    // ══ الخلاصة ═══════════════════════════════════════════════════════════
    echo "\n".str_repeat('─', 72)."\n";
    printf("  نجح %d، وفشل %d.\n\n", $pass, $fail);

    exit($fail === 0 ? 0 : 1);
}
