<?php

namespace App\Services;

use App\Support\Clock;

/**
 * قارئُ سجلّ العمليات — أسطرُ `OPLOG` من دفعة `OPERLOG`.
 *
 * والدفعةُ مخلوطة: فيها المستخدمون وقوالبُ بصماتهم وصورُهم وأسطرُ العمليات
 * معاً. فالقراءةُ تنتقي ما بدأ بـ`OPLOG` وتطرح الباقي — والباقي يقرأه
 * `parseUsers` لغرضه.
 *
 * والسطرُ سبعةُ حقولٍ بعد الاسم:
 *
 *     OPLOG ⇥ الرمز ⇥ رقمُ المسؤول ⇥ الوقت ⇥ ع١ ⇥ ع٢ ⇥ ع٣ ⇥ ع٤
 *
 * وقيمةُ هذا السجلّ كلُّها في الحقل الثاني: **رقمُ من فعلها**. فالانحرافُ
 * يقول إن الساعةَ تغيّرت، ولا يقول بيدِ من — وهذا يقول.
 *
 * ولا يُوثق به وحده: جدولُ الرموز يختلف بين إصدارات الثابتة، فرقمُ «تغييرُ
 * الساعة» في طرازٍ قد يكون «تغييرُ إعداد» في آخر. فهو شاهدٌ يُسمّي ويؤكّد،
 * والحاكمُ هو الانحرافُ لأنه يُقاس بساعتنا لا بإقرار الجهاز.
 */
class OperationParser
{
    /**
     * @return array<int, array{op_code: int, operator_pin: string|null, occurred_at: \Illuminate\Support\Carbon|null, params: string|null}>
     */
    public function parse(string $payload): array
    {
        $rows = [];
        $kept = (array) config('zk.oplog_codes', []);

        foreach (preg_split('/\r\n|\r|\n/', $payload) as $line) {
            $line = trim($line);

            if ($line === '' || stripos($line, 'OPLOG') !== 0) {
                continue;
            }

            /*
             * الاسمُ يُقطع بجدولةٍ في إصداراتٍ وبمسافةٍ في أخرى
             * (`OPLOG 21…` و`OPLOG⇥21…`)، فيُقطع بأيّهما كان.
             */
            $body = preg_replace('/^OPLOG[\s\t]+/i', '', $line, 1) ?? '';

            if ($body === '') {
                continue;
            }

            $f = preg_split('/[\t]+|\s{2,}/', $body);

            if ($f === false || count($f) < 2) {
                /* صيغةُ مسافةٍ واحدة — آخرُ ما يُجرَّب لأنها تكسر الوقت */
                $f = explode(' ', $body);
            }

            $code = $f[0] ?? '';

            if (! is_numeric($code)) {
                continue;
            }

            $code = (int) $code;

            /*
             * ما ليس في القائمة يُطرح ولا يُخزَّن.
             *
             * فالجهازُ يبلّغ كلَّ شيءٍ — وفيه «فتحُ الباب» مئاتَ المرّات في
             * اليوم على جهاز تحكّم. وتخزينُها يُغرق الجدولَ الذي نبحث فيه
             * عن سطرٍ واحدٍ يقول «غُيِّرت الساعة».
             */
            if (! array_key_exists($code, $kept)) {
                continue;
            }

            $pin = trim((string) ($f[1] ?? ''));

            /*
             * الوقتُ حقلٌ واحدٌ بمسافةٍ في وسطه («2026-09-28 08:00:00»)،
             * فقد يُقسَم إلى حقلين إن كان الفاصلُ مسافةً. فيُجمع ما بعد
             * الرقم حتى يُقرأ طابعاً.
             */
            $stamp = trim((string) ($f[2] ?? ''));

            if ($stamp !== '' && ! str_contains($stamp, ':') && isset($f[3])) {
                $stamp = $stamp.' '.trim((string) $f[3]);
            }

            $rows[] = [
                'op_code'      => $code,
                'operator_pin' => ($pin !== '' && $pin !== '0') ? mb_substr($pin, 0, 32) : null,
                'occurred_at'  => Clock::fromDevice($stamp),
                'params'       => mb_substr(implode(' ', array_slice($f, 3)), 0, 190) ?: null,
            ];
        }

        return $rows;
    }

    /** أيمسّ هذا الرمزُ الوقتَ بذاته؟ */
    public static function touchesTime(int $code): bool
    {
        return in_array($code, (array) config('zk.oplog_time_codes', [21, 27]), true);
    }

    public static function label(int $code): string
    {
        return (string) (config('zk.oplog_codes')[$code] ?? "عمليةٌ رقم {$code}");
    }
}
