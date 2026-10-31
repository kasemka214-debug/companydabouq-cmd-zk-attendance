<?php

/**
 * محاكاةُ قراءة صورة البصمة.
 *
 * والغرضُ ثلاثة فحوص لا رابعَ لها في الأهمية:
 *
 *   ١) ألّا يُخزَّن ما ليس صورة — فالعرضُ inline لمحتوًى مجهولٍ ثغرة.
 *   ٢) ألّا تُقتطع بايتةٌ من الصورة — صورةٌ ناقصةُ بايتةٍ لا تُعرض أصلاً،
 *      والخطأُ لا يظهر إلا بعد شهرٍ حين يُطلب شاهدٌ على حضور.
 *   ٣) أن يُقرأ الرقمُ والوقتُ من حيث وُجدا — الترويسةُ تختلف بين الطُرُز.
 *
 *     C:\xampp\php\php.exe scripts/محاكاة-الصور.php
 */

declare(strict_types=1);

namespace Illuminate\Support {

    class Carbon extends \DateTimeImmutable
    {
        public static function parse(string $t, $tz = null): self
        {
            return new self($t, $tz ? new \DateTimeZone($tz) : null);
        }

        public static function now(?string $tz = null): self
        {
            return new self('now', new \DateTimeZone($tz ?: 'UTC'));
        }

        public function utc(): self { return $this->setTimezone(new \DateTimeZone('UTC')); }
        public function copy(): self { return $this; }

        #[\ReturnTypeWillChange]
        public function setTimezone($tz): self
        {
            return parent::setTimezone(is_string($tz) ? new \DateTimeZone($tz) : $tz);
        }

        public function lt(self $o): bool { return $this < $o; }
        public function subDay(): self { return $this->modify('-1 day'); }
        public function toDateString(): string { return $this->format('Y-m-d'); }
    }
}

namespace App\Models {

    /** ما يستعمله القارئُ من النموذج فقط. */
    class PunchPhoto
    {
        public const SAFE = [
            "\xFF\xD8\xFF"      => 'image/jpeg',
            "\x89PNG\r\n\x1A\n" => 'image/png',
        ];

        public static function sniff(string $bytes): ?string
        {
            foreach (self::SAFE as $magic => $mime) {
                if (str_starts_with($bytes, $magic)) {
                    return $mime;
                }
            }

            return null;
        }
    }
}

namespace {

    $GLOBALS['__c'] = ['zk.device_timezone' => 'Asia/Kuwait', 'zk.day_starts_at' => '04:00'];

    function config(string $k, $d = null) { return $GLOBALS['__c'][$k] ?? $d; }

    require __DIR__.'/../app/Support/Clock.php';
    require __DIR__.'/../app/Services/PhotoParser.php';

    use App\Models\PunchPhoto;
    use App\Services\PhotoParser;

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

    /* صورةُ JPEG مصطنعة: الترويسةُ الحقيقية ثم بايتاتٌ فيها كلُّ القيم */
    $jpeg = "\xFF\xD8\xFF\xE0".random_bytes(300)."\xFF\xD9";
    $png  = "\x89PNG\r\n\x1A\n".random_bytes(120);

    $parser = new PhotoParser();

    // ══ ٠ ══ الشكلُ الحقيقيُّ الذي يرسله SpeedFace ══════════════════════════
    echo "\n─── ٠) الشكلُ الواصل فعلاً: حقولٌ في سطرٍ واحد والصورةُ base64 ───\n";

    /*
     * ملتقطٌ من سجلّ الاتصالات حرفياً — والفخُّ فيه أن حقل `pin` يحمل
     * اسمَ الملف لا الرقم، وأن الصورةَ مُرمَّزةٌ لا خام.
     */
    $real = "pin=20260926080835-2005.jpg\tsn=AJE1262000376\tsize=".strlen($jpeg)
        ."\tphoto=".base64_encode($jpeg);

    $r = $parser->parse($real);

    check('قُرئ',                          $r !== null, true);
    check('الرقمُ ٢٠٠٥ لا اسمُ الملف',   $r['pin'], '2005');
    check('الوقتُ من الاسم (٨:٠٨ كويتياً)',
        $r['taken_at']->format('Y-m-d H:i:s'), '2026-09-26 05:08:35');
    check('الصورةُ فُكَّ ترميزُها كاملةً', $r['bytes'] === $jpeg, true);
    check('ونوعُها يُعرف',                PunchPhoto::sniff($r['bytes']), 'image/jpeg');

    /* حقلُ photo فيه نصٌّ ليس صورة */
    check('نصٌّ مُرمَّزٌ ليس صورةً يُرفض',
        $parser->parse("pin=20260926080835-7.jpg\tphoto=".base64_encode('<?php echo 1;')), null);

    // ══ ١ ══ الشكلُ المعتاد ═══════════════════════════════════════════════
    echo "\n─── ١) ترويسةٌ ثم بايتات (الشكلُ الآخر) ───\n";

    $one = $parser->parse("PIN=14\tSN=AJE1262000202\tsize=".strlen($jpeg)."\tCMD=uploadphoto\n".$jpeg);

    check('قُرئ',              $one !== null, true);
    check('الرقم',             $one['pin'], '14');
    check('البايتاتُ كاملة',   strlen($one['bytes']), strlen($jpeg));
    check('ولم تُمسّ بايتةٌ',  $one['bytes'] === $jpeg, true);
    check('والنوعُ يُعرف',     PunchPhoto::sniff($one['bytes']), 'image/jpeg');

    // ══ ٢ ══ الوقتُ من اسم الملف ═══════════════════════════════════════════
    echo "\n─── ٢) الوقتُ يُستخرج من اسم الملف حين لا يُصرَّح ───\n";

    $named = $parser->parse("PIN=21\tfilename=20260926141103-21.jpg\tCMD=uploadphoto\n".$jpeg);

    check('الرقم',                 $named['pin'], '21');
    check('الوقتُ غرينتش (١٤:١١ كويتياً)',
        $named['taken_at']->format('Y-m-d H:i:s'), '2026-09-26 11:11:03');

    /* والرقمُ يُستخرج من الاسم أيضاً إن غاب من الترويسة */
    $fromName = $parser->parse("filename=20260926141103-77.jpg\tCMD=uploadphoto\n".$jpeg);

    check('الرقمُ من الاسم حين غاب', $fromName['pin'], '77');

    // ══ ٣ ══ وقتٌ مُصرَّحٌ به يسبق اسمَ الملف ═══════════════════════════════
    echo "\n─── ٣) الحقلُ الصريح مقدَّمٌ على الاسم ───\n";

    $explicit = $parser->parse(
        "PIN=5\ttime=2026-09-26 08:00:00\tfilename=20260926141103-5.jpg\n".$jpeg
    );

    check('الوقتُ من الحقل لا الاسم',
        $explicit['taken_at']->format('H:i:s'), '05:00:00');

    // ══ ٤ ══ المفاتيحُ في عنوان الطلب ══════════════════════════════════════
    echo "\n─── ٤) بعضُ الطُرُز تضع المفاتيح في العنوان لا في الجسد ───\n";

    $viaQuery = $parser->parse($jpeg, ['PIN' => '31', 'table' => 'ATTPHOTO']);

    check('قُرئ بلا ترويسةٍ في الجسد', $viaQuery !== null, true);
    check('الرقمُ من العنوان',          $viaQuery['pin'], '31');
    check('والبايتاتُ كلُّها صورة',     $viaQuery['bytes'] === $jpeg, true);

    // ══ ٥ ══ ما ليس صورةً لا يمرّ ══════════════════════════════════════════
    echo "\n─── ٥) الحرسُ: النوعُ من البايتات لا من التصريح ───\n";

    check('نصٌّ يدّعي أنه صورة',
        PunchPhoto::sniff("<?php echo 'x';"), null);
    check('HTML',
        PunchPhoto::sniff('<svg onload=alert(1)>'), null);
    check('فارغ',
        PunchPhoto::sniff(''), null);
    check('JPEG يُعرف',
        PunchPhoto::sniff($jpeg), 'image/jpeg');
    check('PNG يُعرف',
        PunchPhoto::sniff($png), 'image/png');

    // ══ ٦ ══ حالاتٌ طرفية ══════════════════════════════════════════════════
    echo "\n─── ٦) ما لا يُقرأ يُردّ ولا يُخزَّن نصفَ صورة ───\n";

    check('جسدٌ فارغ',            $parser->parse(''), null);
    check('ترويسةٌ بلا بايتات',  $parser->parse("PIN=14\tCMD=uploadphoto"), null);
    check('بلا رقمٍ ولا اسم',    $parser->parse("CMD=uploadphoto\n".$jpeg), null);
    check('رقمٌ صفر',            $parser->parse("PIN=0\tCMD=uploadphoto\n".$jpeg), null);

    // ══ ٧ ══ بايتةٌ تشبه سطراً جديداً داخل الصورة ═══════════════════════════
    echo "\n─── ٧) الفصلُ عند أوّل سطرٍ فقط — ولو تكرّر في الصورة ───\n";

    $tricky = "\xFF\xD8\xFF\x0A\x0A\x0A".random_bytes(50)."\xFF\xD9";
    $read   = $parser->parse("PIN=9\tCMD=uploadphoto\n".$tricky);

    check('البايتاتُ كاملةٌ رغم أسطرٍ داخلها', $read['bytes'] === $tricky, true);
    check('ولم يُقتطع شيء',                     strlen($read['bytes']), strlen($tricky));

    echo "\n".str_repeat('─', 72)."\n";
    printf("  نجح %d، وفشل %d.\n\n", $pass, $fail);

    exit($fail === 0 ? 0 : 1);
}
