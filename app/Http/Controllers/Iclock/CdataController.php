<?php

namespace App\Http\Controllers\Iclock;

use App\Models\Device;
use App\Models\Pin;
use App\Models\Punch;
use App\Models\PunchPhoto;
use App\Models\Operation;
use App\Services\AttlogParser;
use App\Services\ClockWatch;
use App\Services\OperationParser;
use App\Services\PhotoIntake;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * نقطةُ cdata — المصافحةُ ثم الدفع.
 *
 * وهي قلبُ البروتوكول: الجهازُ يبدأ بـ GET يسأل فيه عن إعداداته، فيُجيبه
 * الخادمُ بجدولٍ من المفاتيح، ثم يبقى الجهازُ يدفع سجلَّه بـ POST كلّما
 * بُصم عليه.
 */
class CdataController extends IclockController
{
    /**
     * المصافحة — GET /iclock/cdata?SN=…&options=all&pushver=…
     *
     * الجوابُ يُعلّم الجهازَ أربعةَ أشياء: من أين يُكمل (الطوابع)، وكم
     * ينتظر بين استعلامَين، وأيَّ الجداولِ يدفع، وعلى أيّ منطقةٍ يضبط
     * ساعتَه.
     *
     * والطابعُ أخطرُها: هو موضعُ القراءة في ذاكرة الجهاز. فإن أرسلنا صفراً
     * أعاد الجهازُ سجلَّه من أوّله — عشراتُ الآلاف من النبضات دفعةً واحدة —
     * وإن أرسلنا طابعاً أكبرَ مما وصلنا فَقَدْنا ما بينهما بلا رجعة.
     */
    public function handshake(Request $request): Response
    {
        /** @var Device $device */
        $device = $request->attributes->get('zk_device');

        /*
         * إصدارُ البروتوكول الذي يتكلّمه الجهاز — يرسله في `pushver`.
         * والأجهزةُ الحديثة (3.x) تنتظر من الخادم أن يعلن إصدارَه، فإن
         * سكت ظنّته لا يفهمها وأعاد المصافحةَ كلَّ بضع ثوانٍ بلا أن يدفع
         * شيئاً — وهذا ما وقع فعلاً مع SPEEDFACE-V5L.
         */
        $pushVer = trim((string) $request->query('pushver', '2.4.1')) ?: '2.4.1';

        /*
         * نوعُ الجهاز يُحفظ هنا لأن هذه هي اللحظةُ الوحيدة التي يقوله فيها.
         * والأوامرُ تُسحب لاحقاً في `getrequest` وليس فيه ما يدلّ عليه،
         * فلو لم يُحفظ لخاطبنا جهازَ التحكّم بلغة جهاز الحضور — وهو ما كان.
         */
        $type = strtolower(trim((string) $request->query('DeviceType', '')));

        if ($type !== '' && $type !== (string) $device->device_type) {
            $device->forceFill(['device_type' => mb_substr($type, 0, 16)])->saveQuietly();
        }

        $lines = [
            'GET OPTION FROM: '.$device->serial_number,

            /*
             * الطابعان مكرّران بالاسمين عمداً. الإصداراتُ قبل 2.2.14 تقرأ
             * Stamp و OpStamp، وما بعدها يقرأ ATTLOGStamp و OPERLOGStamp،
             * وكلٌّ يتجاهل ما لا يعرف. وخمسةُ أجهزةٍ في موقعٍ واحد قد تكون
             * من دفعتَي إنتاجٍ مختلفتين.
             */
            'Stamp='.$device->attlog_stamp,
            'OpStamp='.$device->operlog_stamp,
            'ATTLOGStamp='.$device->attlog_stamp,
            'OPERLOGStamp='.$device->operlog_stamp,
            /*
             * صفرٌ حين تُطفأ الصور، ورقمٌ حين تُقبل.
             *
             * والصفرُ يعني للجهاز «أنت محدَّث فلا ترسل»، فيسكت عن الصور
             * حتى لو أذِن لها `TransFlag`. ولذلك لا يكفي فتحُ الخانة
             * وحدَها — وهذا موضعٌ يسهل أن يُنسى فتُفتح الخانةُ ولا تصل صورة.
             */
            'ATTPHOTOStamp='.(config('zk.photos') ? $device->attphoto_stamp : '0'),

            'ErrorDelay='.(int) config('zk.error_delay', 60),
            'Delay='.(int) config('zk.poll_delay', 30),
            'TransTimes=00:00;12:00',
            'TransInterval=1',

            /*
             * `TransFlag` يقول للجهاز أيَّ الجداول يدفع — وله صيغتان:
             *
             *   أجهزةُ الحضور (`att`) تقرأ خاناتٍ رقمية، خانةٌ لكل جدول:
             *   حضور، عمليات، صورةُ حضور، تسجيلُ مستخدم…
             *
             *   وأجهزةُ التحكّم (`acc`) تقرأ أسماءَ الجداول صراحةً. ورقمٌ
             *   يُرسل إليها لا يعني شيئاً في لغتها، فتقف عند المصافحة ولا
             *   تدفع — وهو ما نراه مع SPEEDFACE.
             *
             * وفي الحالتين تبقى الصورُ مطفأة: النظامُ سجلٌّ ومراجعة،
             * وصورةُ كل بصمةٍ تُضخّم القاعدةَ بلا غرضٍ يخدمها.
             */
            $this->isAccessDevice($request)
                ? 'TransFlag=TransData AttLog OpLog'.(config('zk.photos') ? ' AttPhoto' : '')
                    .' EnrollUser ChgUser EnrollFP ChgFP UserPic'
                : 'TransFlag=11'.(config('zk.photos') ? '1' : '0').'0000000',

            'TimeZone='.(int) config('zk.timezone_offset', 3),
            'Realtime=1',
            'Encrypt=0',

            /*
             * إعلانُ الإصدار — نردّ عليه بإصداره هو لا بإصدارٍ نختاره.
             * فالجهازُ يقارن، فإن وجد الخادمَ أقدمَ منه عامله معاملةَ
             * القديم أو رفضه. وردُّ إصداره يعني: «أفهم لغتَك».
             */
            'ServerVer='.$pushVer,
            'PushProtVer='.$pushVer,
        ];

        /*
         * `PushOptionsFlag=1` يعني: «قل لي أيَّ خصائصي تريد أن أبلّغك بها».
         * والجوابُ قائمةُ أسماءٍ يدفعها الجهازُ بعدها في `table=options`،
         * فنعرف منها طرازَه وإصدارَه وعنوانَه. وإن لم نُجب بقيت المصافحةُ
         * ناقصةً في نظره.
         */
        if ($request->query('PushOptionsFlag') === '1') {
            $lines[] = 'PushOptions=FirmVer,DeviceName,PlatForm,MAC,IPAddress,'
                .'FingerFunOn,FaceFunOn,FPVersion,FaceVersion,TransactionCount,UserCount';
        }

        return $this->lines($lines);
    }

    /**
     * أهو جهازُ تحكّمٍ بالدخول؟
     *
     * يقوله الجهازُ عن نفسه في `DeviceType` عند المصافحة. والفرقُ ليس
     * تصنيفاً: نوعُ الجهاز يغيّر صيغةَ `TransFlag` واسمَ الجدول الذي يدفع
     * فيه سجلَّه (`transaction` لا `ATTLOG`) وصيغةَ أسطره.
     */
    private function isAccessDevice(Request $request): bool
    {
        /** @var Device|null $device */
        $device = $request->attributes->get('zk_device');

        return strtolower((string) $request->query('DeviceType', $device?->device_type ?? '')) === 'acc';
    }

    /**
     * الدفع — POST /iclock/cdata?SN=…&table=ATTLOG&Stamp=…
     *
     * الجسدُ نصٌّ خام، سطرٌ لكل نبضة. والردُّ المتوقَّع «OK: عدد» — وإن
     * تأخّر أو جاء غيرَ ذلك أعاد الجهازُ الدفعةَ نفسها.
     */
    public function receive(Request $request, AttlogParser $parser): Response
    {
        /** @var Device $device */
        $device = $request->attributes->get('zk_device');

        $table = strtoupper((string) $request->query('table', ''));

        return match ($table) {
            'ATTLOG' => $this->attlog($request, $device, $parser),

            /*
             * أجهزةُ التحكّم بالدخول (`DeviceType=acc`) لا ترسل ATTLOG أبداً.
             * ترسل `transaction` لسجلّها المخزَّن و`rtlog` للحظة بلحظة،
             * وكلاهما بصيغةٍ أخرى: `key=value` مفصولةً بجدولة لا حقولاً
             * بمواضعها. وقبل هذا كان الجدولان يسقطان في `default` فيُردّ
             * عليهما بـOK وتُرمى بياناتُهما بصمت — فيظنّ الجهازُ أنه سلّم
             * وقد ضاع ما سلّم.
             */
            'TRANSACTION', 'RTLOG' => $this->access($request, $device, $parser),

            'OPTIONS' => $this->options($device, (string) $request->getContent()),
            'OPERLOG' => $this->operlog($request, $device, $parser),

            /* سجلُّ المستخدمين حين يُطلب من الجهاز سجلُّه كلُّه */
            'USER', 'USERINFO' => $this->users($request, $device, $parser),

            /*
             * صورةُ البصمة. وأسماءُ الجدول تختلف بين الطُرُز — `ATTPHOTO`
             * في أجهزة الحضور، و`BIOPHOTO` أو `PHOTO` في غيرها — فتُقبل
             * كلُّها، وما شذّ التقطه المسارُ الجامع.
             */
            'ATTPHOTO', 'BIOPHOTO', 'PHOTO' => $this->photo($request, $device),

            /*
             * جدولٌ لا نعرفه — تُجرَّب عليه الصورةُ قبل أن يُهمَل. فأسماءُ
             * جدول الصور تختلف بين الطُرُز، وظنُّ الأبواب معروفةً كلّفنا
             * أياماً في `registry`.
             */
            default => $this->photo($request, $device),
        };
    }

    /** سجلُّ جهازِ التحكّم بالدخول — يُخزَّن نبضاتٍ كسجلّ الحضور سواء. */
    private function access(Request $request, Device $device, AttlogParser $parser): Response
    {
        $parsed = $parser->parseAccess((string) $request->getContent());

        $this->store($request, $device, $parsed['rows']);
        $this->rememberStamp($request, $device, 'attlog_stamp');

        $request->attributes->set('zk_rows', count($parsed['rows']));

        if ($parsed['bad'] !== []) {
            logger()->warning('أسطرُ تحكّمٍ مشوَّهة', [
                'serial' => $device->serial_number,
                'count'  => count($parsed['bad']),
                'sample' => array_slice($parsed['bad'], 0, 3),
            ]);
        }

        return $this->plain('OK: '.count($parsed['rows']));
    }

    private function attlog(Request $request, Device $device, AttlogParser $parser): Response
    {
        $parsed = $parser->parse((string) $request->getContent());
        $rows   = $parsed['rows'];
        $bad    = $parsed['bad'];

        $this->store($request, $device, $rows);

        $this->rememberStamp($request, $device, 'attlog_stamp');

        $request->attributes->set('zk_rows', count($rows));

        if ($bad !== []) {
            logger()->warning('نبضاتٌ مشوَّهة من جهاز', [
                'serial' => $device->serial_number,
                'count'  => count($bad),
                'sample' => array_slice($bad, 0, 3),
            ]);
        }

        /*
         * العددُ المُقَرُّ به هو عددُ ما قُرئ لا عددُ ما خُزّن. فلو أقررنا
         * بالمخزَّن وحده لظنّ الجهازُ أن المكرَّرَ لم يصل فأعاده أبداً.
         */
        return $this->plain('OK: '.count($rows));
    }

    /**
     * تخزينُ النبضات — واحدٌ لجدولَي الحضور والتحكّم معاً.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function store(Request $request, Device $device, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = Carbon::now('UTC');

        $records = array_map(fn (array $r): array => [
            'device_id'   => $device->id,
            'site_id'     => $device->site_id,
            'device_pin'  => $r['device_pin'],
            'punched_at'  => $r['punched_at'],
            'work_date'   => $r['work_date'],
            'state'       => $r['state'],
            'verify_mode' => $r['verify_mode'],
            'work_code'   => $r['work_code'],
            'received_at' => $now,
        ], $rows);

        /*
         * insertOrIgnore لا insert: القيدُ الفريد على (الجهاز، الرقم،
         * اللحظة) يبتلع المكرّر بصمت. والمكرّرُ ليس نادراً — هو القاعدة:
         * جهازٌ لم يصله ردُّنا يُعيد الدفعةَ كاملةً في الدورة التالية.
         * وجهازُ التحكّم يُرسل النبضةَ مرّتين بطبعه: مرّةً في `rtlog`
         * لحظةَ وقوعها، ومرّةً في `transaction` ضمن سجلّه المخزَّن.
         *
         * والتقسيمُ إلى خمسمئة لأن جهازاً انقطع أسبوعاً يدفع آلافاً دفعةً
         * واحدة، واستعلامٌ بعشرة آلاف قيمةٍ يتجاوز حدَّ حِزمة MySQL.
         */
        $stored = 0;

        foreach (array_chunk($records, 500) as $chunk) {
            $stored += Punch::query()->insertOrIgnore($chunk);
        }

        /*
         * قياسُ ساعة الجهاز — **بعد** التخزين لا قبله، ومعزولاً في try.
         *
         * وكان قبله فوقع فيه عيبٌ صامت: الحارسُ يقرأ ويكتب في جدولين
         * ويستعلم عن الأجهزة كلِّها، فاستثناءٌ فيه — قفلٌ أو انقطاعُ قاعدة —
         * يُنهي الطلبَ قبل أن تُخزَّن بصمةٌ واحدة، فيصل الجهازَ خطأٌ لا
         * «OK»، فيُعيد الدفعةَ… فيسقط الحارسُ من جديد. حلقةٌ تُفقد الحضورَ
         * كلَّه من أجل تنبيهٍ عن الساعة.
         *
         * والترتيبُ الصحيح يقول ما هو الأصلُ وما هو الفرع: **حفظُ الحضور
         * أصلٌ، والتنبيهُ فرع.** فالفرعُ لا يُسقط أصلَه أبداً، ولو سقط هو.
         */
        try {
            $newest = collect($rows)->max(fn (array $r) => $r['punched_at']->getTimestamp());
            $oldest = collect($rows)->min(fn (array $r) => $r['punched_at']->getTimestamp());

            app(ClockWatch::class)->read(
                $device,
                Carbon::createFromTimestamp($newest, 'UTC'),
                [
                    /*
                     * `stored` لا `count($rows)`: الدفعةُ التي لم تُخزّن
                     * سطراً واحداً دفعةٌ مُعادة، ووقتُها قديمٌ بطبعه. ولو
                     * قُرئت ساعةً لكذبت في كل جهاز تحكّمٍ — فهو يرسل كلَّ
                     * بصمةٍ مرّتين بطبعه، وتصل الثانيةُ بعد دقائق.
                     */
                    'stored'    => $stored,
                    'rows'      => count($rows),
                    'span'      => (int) ($newest - $oldest),
                    'prev_seen' => $request->attributes->get('zk_prev_seen'),
                ],
            );
        } catch (\Throwable $e) {
            logger()->error('تعذّر قياسُ ساعة الجهاز — والنبضاتُ محفوظة', [
                'serial' => $device->serial_number,
                'خطأ'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * سجلُّ العمليات — أسماءُ المستخدمين، وما جرى على الجهاز.
     *
     * فيه كلُّ شيء: من فتح قائمتَه، ومن غيّر ساعتَه، ومن سجّل موظفاً. وكان
     * لا يُقرأ منه إلا الأسماءُ بحجّة أن «النظامَ سجلُّ حضورٍ لا سجلُّ
     * إدارة» — وكانت حجّةً صحيحةً وناقصة.
     *
     * فالسؤالُ الذي لا يُجيبه انحرافُ الساعة هو **مَن** غيّرها. الانحرافُ
     * يكشف أنها تغيّرت ولا يقول بيدِ من، وهذا السجلُّ يحمل رقمَ من فعلها.
     * وفي نزاعِ أجرٍ لا ينفع أن يُقال «عُبث بجهاز الموقع» وحسب.
     *
     * ويُنتقى منه ما يُعنينا ويُطرح الباقي — انظر `OperationParser`.
     */
    private function operlog(Request $request, Device $device, AttlogParser $parser): Response
    {
        $body = (string) $request->getContent();

        $this->rememberUsers($device, $parser->parseUsers($body));

        /*
         * ومعزولٌ في try: سجلُّ العمليات فرعٌ، والإقرارُ بالاستلام أصل.
         * فخطأٌ في قراءته لا يجوز أن يمنع «OK» — وإلا أعاد الجهازُ الدفعةَ
         * أبداً، وضاع معها ما فيها من أسماء.
         */
        try {
            $count = Operation::remember(
                $device,
                app(OperationParser::class)->parse($body),
                Carbon::now('UTC'),
            );

            if ($count > 0) {
                $request->attributes->set('zk_rows', $count);
            }
        } catch (\Throwable $e) {
            logger()->error('تعذّر حفظُ سجلّ عمليات الجهاز', [
                'serial' => $device->serial_number,
                'خطأ'    => $e->getMessage(),
            ]);
        }

        $this->rememberStamp($request, $device, 'operlog_stamp');

        return $this->plain('OK');
    }

    /**
     * صورةُ البصمة — POST /iclock/cdata?SN=…&table=ATTPHOTO
     *
     * تصل مستقلّةً عن النبضة وقد تسبقها أو تتأخّر عنها بثوانٍ، فتُربط
     * بأقرب نبضةٍ لصاحب الرقم نفسِه في نافذةٍ ضيّقة. وما لم يُوجَد له
     * نبضةٌ يُخزَّن على حاله: الكاميرا صوّرت من وقف أمامها وإن ضاعت بصمتُه،
     * وتلك الصورةُ بالذات أنفعُ ما يكون حين يُنازَع في حضور.
     */
    private function photo(Request $request, Device $device): Response
    {
        $stored = app(PhotoIntake::class)->capture(
            $device,
            (string) $request->getContent(),
            $request->query(),
        );

        if ($stored) {
            $this->rememberStamp($request, $device, 'attphoto_stamp');
            $request->attributes->set('zk_rows', 1);
        }

        return $this->plain('OK');
    }

    /** سجلُّ المستخدمين كاملاً — يدفعه الجهازُ حين يُطلب منه. */
    private function users(Request $request, Device $device, AttlogParser $parser): Response
    {
        $rows  = $parser->parseUsers((string) $request->getContent());
        $added = $this->rememberUsers($device, $rows);

        $request->attributes->set('zk_rows', count($rows));

        logger()->info('وصلت أسماءٌ من جهاز', [
            'serial' => $device->serial_number,
            'قُرئ'   => count($rows),
            'أُضيف'  => $added,
        ]);

        return $this->plain('OK: '.count($rows));
    }

    /** القاعدةُ في النموذج — انظر Pin::importFromDevice. */
    private function rememberUsers(Device $device, array $users): int
    {
        return Pin::importFromDevice($device, $users);
    }

    /**
     * تسجيلُ الجهاز — POST /iclock/registry?SN=…
     *
     * بابٌ لا تعرفه أجهزةُ الحضور القديمة، ولا غنى عنه لأجهزة الدفع 3.x:
     * الجهازُ يُعرّف بنفسه ثم **ينتظر رمزَ تسجيلٍ من الخادم**. فإن لم يجده
     * في الردّ اعتبر نفسَه غيرَ مسجَّل، وعاد إلى أوّل الطريق: مصافحةٌ ثم
     * تسجيلٌ ثم مصافحة… بلا نهاية، ولا يبلغ مرحلةَ الدفع أصلاً.
     *
     * وهذا بالضبط ما حبس SPEEDFACE-V5L أياماً: كان يتصل ويُقبل ويُعرّف
     * بنفسه، ويُردّ عليه «OK» بلا رمز — فيبدأ من جديد. ولم يُرَ ذلك في
     * السجلّ لأن المسار لم يكن معرَّفاً أصلاً، فكان يسقط في 404 قبل الحارس.
     */
    public function registry(Request $request): Response
    {
        /** @var Device $device */
        $device = $request->attributes->get('zk_device');

        $this->rememberDeviceInfo($device, (string) $request->getContent());

        return $this->lines(['RegistryCode='.self::registryCode($device->serial_number)]);
    }

    /**
     * رمزُ تسجيل الجهاز — يُشتقّ من رقمه التسلسلي ولا يُخزَّن.
     *
     * والاشتقاقُ لا التخزين لأنه محسوبٌ لا مُنشأ: ثابتٌ للجهاز الواحد مهما
     * أُعيد تشغيلُ الخادم أو استُبدلت قاعدتُه — فلا يُضطرّ جهازٌ في موقعٍ
     * بعيدٍ إلى إعادة تسجيلٍ لأننا نسينا رمزَه.
     *
     * وهو ليس سرّاً ولا يحرس شيئاً — البروتوكولُ يستعمله علامةَ «قد عرفتُك»
     * لا مفتاحَ إذن. والحراسةُ كلُّها في DeviceGate كما كانت.
     */
    public static function registryCode(string $serial): string
    {
        return strtoupper(substr(md5('zk-registry|'.$serial), 0, 16));
    }

    /**
     * بطاقةُ تعريف الجهاز — POST /iclock/cdata?table=options
     * الجسدُ سطرٌ واحد على هيئة: ~DeviceName=K40,FirmVer=Ver 6.60,...
     */
    private function options(Device $device, string $payload): Response
    {
        $this->rememberDeviceInfo($device, $payload);

        return $this->plain('OK');
    }

    /**
     * قراءةُ بطاقة التعريف وحفظُ ما يهمّ منها.
     *
     * والبطاقةُ تصل في موضعين — `registry` و`table=options` — بالصيغة
     * نفسِها، فالقراءةُ في موضعٍ واحد.
     */
    private function rememberDeviceInfo(Device $device, string $payload): void
    {
        $fields = [];

        foreach (explode(',', str_replace(["\r", "\n", "\t"], ',', $payload)) as $pair) {
            if (str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                $fields[strtolower(trim($k, " \t~"))] = trim($v);
            }
        }

        $found = array_filter([
            'model'    => $fields['devicename'] ?? null,
            'firmware' => $fields['firmver'] ?? null,
        ]);

        if ($found !== []) {
            $device->forceFill($found)->saveQuietly();
        }
    }

    /**
     * حفظُ الطابع الذي أقرّ به الجهاز.
     *
     * يُكتب بعد المعالجة لا قبلها — فلو سقط الاستعلامُ في المنتصف بقي
     * الطابعُ القديم وأعاد الجهازُ الدفعة، وهذا هو المطلوب. أمّا كتابتُه
     * أوّلاً فتضيع الدفعةَ إلى الأبد.
     */
    private function rememberStamp(Request $request, Device $device, string $column): void
    {
        $stamp = (string) $request->query('Stamp', '');

        if ($stamp !== '' && ctype_digit($stamp)) {
            $device->forceFill([$column => $stamp])->saveQuietly();
        }
    }
}
