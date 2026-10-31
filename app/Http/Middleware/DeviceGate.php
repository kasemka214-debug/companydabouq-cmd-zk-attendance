<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\RawLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * البوّابُ الوحيد بين أجهزة البصمة والنظام.
 *
 * بروتوكولُ iclock بلا مصادقةٍ من أصله: الجهازُ لا يرسل مفتاحاً ولا كلمةَ سرّ،
 * يرسل رقمَه التسلسليَّ في عنوان الطلب ليس غير. فمن عرف عنوانَ الخادم ورقمَ
 * جهازٍ واحد استطاع أن يدفع حضوراً مزوَّراً بأمرِ curl واحد.
 *
 * ولأن البروتوكولَ لا يُصلَح — الأجهزةُ لا تعرف غيره — فالحمايةُ طبقات:
 *
 *   ١) لا يُقبل إلا رقمٌ تسلسليٌّ مسجَّلٌ بيدِ مسؤولٍ في الشاشة.
 *   ٢) ولمن أراد الشدَّ: قائمةُ عناوينَ مسموحةٍ لكل جهاز.
 *   ٣) وحدٌّ للطلبات يمنع الإغراق.
 *   ٤) وأرشيفٌ لكلّ ما وصل — مقبولاً كان أو مردوداً.
 *
 * والردُّ واحدٌ في كل الأحوال: «OK» بحالة 200.
 *
 * وهذا مقصود لسببين. الأول أمنيّ: من جرّب رقماً تسلسلياً لا يعرف أصاب أم
 * أخطأ. والثاني عمليّ: جهازُ ZKTeco الذي يتلقّى خطأً يُعيد المحاولةَ بلا
 * انقطاع، فخمسةُ أجهزةٍ مرفوضةٍ في موقعٍ واحد تُغرق السجلَّ في ساعة.
 */
class DeviceGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $serial = trim((string) $request->query('SN', ''));
        $ip     = $request->ip();

        if ($serial === '') {
            return $this->reject($request, null, null, 'no_serial');
        }

        $limit = (int) config('zk.rate_limit', 120);

        if (RateLimiter::tooManyAttempts("zk:{$serial}", $limit)) {
            return $this->reject($request, $serial, null, 'rate_limited');
        }

        RateLimiter::hit("zk:{$serial}", 60);

        $device = Device::where('serial_number', $serial)->first();

        if (! $device) {
            return $this->reject($request, $serial, null, 'unknown_serial');
        }

        if (! $device->is_active) {
            return $this->reject($request, $serial, $device, 'inactive');
        }

        if (! $device->ipAllowed($ip)) {
            return $this->reject($request, $serial, $device, 'ip_blocked');
        }

        /*
         * «آخرُ اتصال» يُكتب هنا لا في المتحكّم، فيشمل كلَّ نوعِ طلب —
         * حتى استعلامَ الأوامر الفارغ. وهو ما يجعل شاشةَ الأجهزة تُفرّق
         * بين جهازٍ لا يبصم عليه أحدٌ وجهازٍ انقطع.
         */
        $previous = $device->last_seen_at?->copy();

        $device->forceFill([
            'last_seen_at' => Carbon::now('UTC'),
            'last_ip'      => $ip,
        ])->saveQuietly();

        $request->attributes->set('zk_device', $device);

        /*
         * وقتُ الاتصال **السابق** يُحمل مع الطلب — وإلا ضاع في السطر فوقُ.
         *
         * وحارسُ الساعة لا يستغني عنه: به وحده يُفرَّق جهازٌ انقطع ثم عاد
         * يدفع ما فاته — فأحدثُ بصماته قديمةٌ بحقّ — من جهازٍ لم ينقطع
         * وساعتُه تأخّرت. والفرقُ بينهما تهمةٌ تُقال أو لا تُقال.
         */
        $request->attributes->set('zk_prev_seen', $previous);

        $response = $next($request);

        $this->archive($request, $serial, $device, true, 'ok', $response);

        return $response;
    }

    private function reject(Request $request, ?string $serial, ?Device $device, string $reason): Response
    {
        $response = $this->plain('OK');

        $this->archive($request, $serial, $device, false, $reason, $response);

        return $response;
    }

    private function archive(
        Request $request,
        ?string $serial,
        ?Device $device,
        bool $accepted,
        string $reason,
        Response $response,
    ): void {
        /*
         * الجسدُ يُقصّ عند ٦٤ كيلوبايت. دفعةٌ من جهازٍ انقطع شهراً تبلغ
         * ميجابايتات، وأرشفتُها كاملةً تُثقل القاعدةَ بلا فائدةٍ تشخيصية —
         * أوّلُ الدفعة يكفي لمعرفة شكلها.
         */
        $body = (string) $request->getContent();

        /*
         * والجسدُ غيرُ النصّي يُوصَف ولا يُؤرشَف.
         *
         * فصورةُ بصمةٍ في سجلّ التشخيص بايتاتٌ لا تُقرأ، وتُضاعف حجمَ ما
         * خُزّن مرّةً في جدول الصور — ولا تُجيب عن سؤالٍ لا يُجيب عنه
         * وصفُها: أوصلت؟ وكم حجمُها؟
         */
        if ($body !== '' && ! mb_check_encoding($body, 'UTF-8')) {
            $body = '— جسدٌ ثنائيّ، '.number_format(strlen($body)).' بايت —';
        }

        /*
         * والصورةُ المُرمَّزة تُحذف من الأرشيف ويبقى ما حولها.
         *
         * فالجهازُ يرسلها base64 — وهي نصٌّ صالح، فلا يمنعها الحارسُ فوق —
         * فتُخزَّن مرّتين: في جدول الصور وفي هذا السجلّ. وهو ضِعفُ حجمٍ
         * بلا فائدة: أوّلُ الترميز لا يقول عن الصورة شيئاً.
         *
         * ويبقى `pin` و`sn` و`size` ظاهرةً — وهي وحدَها ما يُسأل عنه في
         * التشخيص: أوصلت؟ ولمن؟ وبأيّ حجم؟
         */
        $body = preg_replace(
            '/\b(photo|image|content|data)=[A-Za-z0-9+\/=]{200,}/i',
            '$1=— صورةٌ مُرمَّزة، حُذفت من الأرشيف —',
            $body,
        ) ?? $body;

        RawLog::create([
            'serial_number' => $serial,
            'device_id'     => $device?->id,
            'ip'            => $request->ip(),
            'method'        => $request->method(),
            'path'          => $request->path(),
            'query'         => mb_substr((string) $request->getQueryString(), 0, 2000),
            'body'          => mb_substr($body, 0, 65000),
            'response'      => mb_substr((string) $response->getContent(), 0, 2000),
            'accepted'      => $accepted,
            'reason'        => $reason,
            'rows'          => (int) $request->attributes->get('zk_rows', 0),
        ]);
    }

    /**
     * كلُّ ردٍّ للجهاز نصٌّ صِرف بلا BOM ولا ترويسةِ HTML.
     * إصداراتُ ZKTeco القديمة تقرأ الردَّ حرفاً حرفاً، فبايتُ BOM واحدٌ
     * في أوّله يجعلها تظنّ الردَّ فاسداً وتُعيد الدفعة.
     */
    private function plain(string $text): Response
    {
        return new Response($text, 200, [
            'Content-Type'  => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
