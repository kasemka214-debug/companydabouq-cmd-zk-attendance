<?php

namespace App\Http\Controllers\Iclock;

use App\Models\Command;
use App\Models\Device;
use App\Models\Pin;
use App\Services\AttlogParser;
use App\Services\PhotoIntake;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * طابورُ الأوامر — الجهازُ يسأل، والخادمُ لا يطرق.
 *
 * لا سبيلَ إلى مخاطبة جهازٍ خلف راوترِ موقع: لا عنوانَ له من الخارج، ولا
 * منفذَ مفتوحاً. فالبروتوكولُ يقلب الاتجاه — الجهازُ يستعلم كلَّ نصف
 * دقيقة: «هل عندك لي أمر؟» فيُسحب له ما في الطابور.
 *
 * وهذا يعني أن كلَّ أمرٍ مؤجَّلٌ بطبعه: زرٌّ يضغطه المسؤول لا يُنفَّذ الآن
 * بل حين يستعلم الجهاز. والشاشةُ تقول ذلك صراحةً كي لا يُعاد الضغطُ ظنّاً
 * أنه لم يصل.
 */
class CommandController extends IclockController
{
    /**
     * GET /iclock/getrequest?SN=…
     *
     * الردُّ إمّا OK إن خلا الطابور، وإمّا سطرٌ لكل أمر:
     *     C:<رقم>:<نصّ الأمر>
     */
    public function pull(Request $request): Response
    {
        /** @var Device $device */
        $device = $request->attributes->get('zk_device');

        $pending = Command::query()
            ->where('device_id', $device->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(10)
            ->get();

        if ($pending->isEmpty()) {
            return $this->plain('OK');
        }

        $now = Carbon::now('UTC');

        $lines = $pending->map(function (Command $cmd) use ($now): string {
            $cmd->forceFill(['status' => 'sent', 'sent_at' => $now])->save();

            return 'C:'.$cmd->id.':'.$cmd->body;
        })->all();

        return $this->lines($lines);
    }

    /**
     * POST /iclock/devicecmd?SN=…
     *
     * الجهازُ يُقرّ بالتنفيذ: ID=<رقم>&Return=<رمز>&CMD=<الأمر>
     * والرمزُ صفرٌ نجاحٌ وما دونه خطأ.
     */
    public function acknowledge(Request $request, AttlogParser $parser): Response
    {
        /** @var Device $device */
        $device = $request->attributes->get('zk_device');

        $body = (string) $request->getContent();

        /*
         * أجهزةُ التحكّم تُرجع جوابَ `DATA QUERY` في جسد الإقرار نفسِه —
         * لا في طلبٍ منفصل. وكان القارئُ يلتقط من هذا الجسد رقمَ الأمر
         * ورمزَ الردّ ويرمي ما سواهما، فيُقرّ الجهازُ بالأمر «تمّ» ولا
         * تصل الأسماءُ وقد وصلت.
         *
         * والقراءةُ آمنةٌ في كل حال: لا تُقبل إلا أسطرٌ فيها رقمٌ واسم،
         * فإقرارٌ خالصٌ لا يُنتج شيئاً.
         */
        $added = Pin::importFromDevice($device, $parser->parseUsers($body));

        if ($added > 0) {
            logger()->info('أسماءٌ وصلت في جسد الإقرار', [
                'serial' => $device->serial_number,
                'أُضيف'  => $added,
            ]);
        }

        foreach ($this->acks($body) as $ack) {
            $command = Command::query()
                ->where('device_id', $device->id)
                ->find($ack['id']);

            if (! $command) {
                continue;
            }

            /*
             * الصفرُ نجاحٌ، والسالبُ فشل، والموجبُ ليس فشلاً.
             *
             * كان الشرطُ `=== '0'` فحسب، فيُعدّ كلُّ ما سواه فشلاً — ومنه
             * الموجب. وردّ SPEEDFACE على طلب المستخدمين `1`، فظهر «فشل»
             * مرّاتٍ ونحن نُبدّل صيغةَ الأمر ونُعيد، والأمرُ لم يفشل أصلاً:
             * البروتوكولُ يجعل الموجبَ نتيجةً لا خطأً — عددَ ما نُفِّذ أو
             * ما أُرسل. والخطأُ وحدَه سالب.
             *
             * والرمزُ يبقى معروضاً في كل حال، فيحكم الإنسانُ بعينه.
             */
            $code = $ack['return'];
            $ok   = is_numeric($code) && (float) $code >= 0;

            $command->forceFill([
                'status'      => $ok ? 'done' : 'failed',
                'return_code' => $code,
                'replied_at'  => Carbon::now('UTC'),
            ])->save();
        }

        return $this->plain('OK');
    }

    /**
     * إقراراتُ الجسد الواحد.
     *
     * بعضُ الإصدارات تُقرّ بأمرٍ واحدٍ في الطلب، وبعضُها تجمع إقراراتٍ
     * بأسطر. فالقراءةُ سطراً سطراً تُغطّي الحالتين.
     *
     * @return array<int, array{id: int, return: string}>
     */
    private function acks(string $payload): array
    {
        $out = [];

        foreach (preg_split('/\r\n|\r|\n/', $payload) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            parse_str($line, $fields);

            $id = (int) ($fields['ID'] ?? 0);

            if ($id > 0) {
                $out[] = ['id' => $id, 'return' => (string) ($fields['Return'] ?? '')];
            }
        }

        return $out;
    }

    /** نبضةُ حياةٍ بسيطة يسألها بعضُ الإصدارات قبل كل شيء. */
    public function ping(): Response
    {
        return $this->plain('OK');
    }

    /**
     * مسارٌ لا نعرفه تحت `/iclock`.
     *
     * يُردّ عليه «OK» ويُقيَّد في سجلّ الاتصالات بمساره وجسده كاملَين.
     * والردُّ بالقبول مقصود: لو رددنا خطأً لأعاد الجهازُ المحاولةَ بلا
     * انقطاع، ولو رددنا 404 لما عرفنا شيئاً أصلاً — والغرضُ أن نرى.
     *
     * وعددُ الأسطر يُسجَّل معه، فإن جاء الجسدُ مملوءاً عرفنا أن الجهازَ
     * كان يدفع بياناتٍ في بابٍ لم نفتحه.
     */
    public function unknown(Request $request): Response
    {
        $body = (string) $request->getContent();

        /*
         * وقد تصل الصورةُ من هذا الباب المجهول — فتُلتقط قبل أن يُهمَل.
         * والقارئُ متشدّدٌ فلا يختلق: طلبٌ لا صورةَ فيه لا يُنتج شيئاً.
         */
        $device = $request->attributes->get('zk_device');

        if ($device) {
            app(PhotoIntake::class)->capture($device, $body, $request->query());
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($body));

        $request->attributes->set('zk_rows', count(array_filter($lines, fn ($l) => trim($l) !== '')));

        logger()->notice('مسارُ أجهزةٍ غيرُ معروف', [
            'serial' => $request->query('SN'),
            'path'   => $request->path(),
            'query'  => $request->getQueryString(),
        ]);

        return $this->plain('OK');
    }
}
