<?php

namespace App\Services;

use App\Support\Clock;

/**
 * قارئُ دفعةِ ATTLOG كما يدفعها جهازُ ZKTeco.
 *
 * الدفعةُ نصٌّ خام، سطرٌ لكل نبضة، وحقولُه مفصولةٌ بمسافةِ جدولة:
 *
 *     الرقم ⇥ 2026-09-20 07:14:03 ⇥ النوع ⇥ التحقّق ⇥ رمزُ العمل ⇥ محجوز ⇥ محجوز
 *
 * وبعضُ الإصدارات تفصل بمسافاتٍ عادية بدل الجدولة، وبعضُها يزيد حقولاً
 * ويُنقص أخرى. فالقراءةُ متساهلةٌ في الشكل، متشدّدةٌ في المعنى: ما لم يكن
 * له رقمٌ وطابعٌ صالحان فليس نبضة.
 *
 * والسطرُ المشوَّه يُعزل ولا يُسقط الدفعة. ولهذا سببٌ عمليّ: الجهازُ يُعيد
 * إرسالَ دفعةٍ لم نُقِرَّ باستلامها، فلو رفضنا مئةَ سطرٍ من أجل واحدٍ فاسد
 * لدار الجهازُ على الدفعةِ نفسِها إلى الأبد ولَما وصل شيء.
 */
class AttlogParser
{
    /** @return array{rows: array<int, array<string, mixed>>, bad: array<int, string>} */
    public function parse(string $payload): array
    {
        $rows = [];
        $bad  = [];

        foreach (preg_split('/\r\n|\r|\n/', $payload) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $row = $this->parseLine($line);

            if ($row === null) {
                $bad[] = $line;

                continue;
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'bad' => $bad];
    }

    /**
     * قراءةُ سجلّ جهازِ التحكّم بالدخول — `transaction` و`rtlog`.
     *
     * صيغتُه أخرى: لا حقولَ بمواضعها بل `key=value` مفصولةً بجدولة —
     *
     *     time=2026-09-24 11:39:36 ⇥ pin=14 ⇥ cardno=0 ⇥ eventaddr=1
     *     ⇥ event=0 ⇥ inoutstate=0 ⇥ verifytype=1 ⇥ index=873
     *
     * والاسمُ يحمل المعنى، فلا يضرّ اختلافُ الترتيب ولا زيادةُ حقلٍ
     * ونقصانُه بين إصدارٍ وآخر — وهي علّةُ الصيغة الأولى.
     *
     * **وما لا رقمَ له ليس بصمة.** الجهازُ يرسل في الجدول نفسِه أحداثَ
     * الباب: فُتح، أُغلق، بقي مفتوحاً، جرسٌ ضُغط. وكلُّها بلا `pin` أو
     * بـ`pin=0`. فلو خُزّنت لظهر في الكشف «موظفٌ رقمه صفر» يداوم أربعاً
     * وعشرين ساعة.
     *
     * @return array{rows: array<int, array<string, mixed>>, bad: array<int, string>}
     */
    public function parseAccess(string $payload): array
    {
        $rows = [];
        $bad  = [];

        foreach (preg_split('/\r\n|\r|\n/', $payload) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $f = [];

            foreach (preg_split('/\t+/', $line) as $pair) {
                if (str_contains($pair, '=')) {
                    [$k, $v] = explode('=', $pair, 2);
                    $f[strtolower(trim($k))] = trim($v);
                }
            }

            $pin = (string) ($f['pin'] ?? '');
            $at  = Clock::fromDevice($f['time'] ?? null);

            if ($pin === '' || $pin === '0' || $at === null) {
                $bad[] = $line;

                continue;
            }

            $rows[] = [
                'device_pin'  => mb_substr($pin, 0, 32),
                'punched_at'  => $at,
                'work_date'   => Clock::workDate($at),
                'state'       => $this->byte($f['inoutstate'] ?? 0),
                'verify_mode' => $this->byte($f['verifytype'] ?? 1),
                'work_code'   => null,
            ];
        }

        return ['rows' => $rows, 'bad' => $bad];
    }

    /**
     * قراءةُ سجلّات المستخدمين — الأسماءُ التي في الجهاز نفسِه.
     *
     * يرسلها الجهازُ ضمن `OPERLOG` كلّما سُجّل موظفٌ أو عُدّل، وضمن
     * `table=USER` حين يُطلب منه سجلُّه كلُّه:
     *
     *     USER PIN=14 ⇥ Name=أحمد محمد ⇥ Pri=0 ⇥ Card=0 ⇥ Grp=1 ⇥ TZ=…
     *
     * والبادئةُ `USER` تسقط في بعض الإصدارات فيبدأ السطرُ بـ`PIN=` رأساً،
     * فكلاهما مقبول.
     *
     * **ولا يُقرأ ما سواها.** السجلُّ نفسُه يحمل قوالبَ البصمات والوجوه
     * (`FP`, `FACE`, `BIODATA`) وهي كتلٌ ضخمةٌ من base64 لا نفعَ لنا بها،
     * وقراءتُها تُضخّم الذاكرةَ بلا غرض.
     *
     * @return array<int, array{device_pin: string, name: string, card: string|null}>
     */
    public function parseUsers(string $payload): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $payload) as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }

            /*
             * قوالبُ البصمات والوجوه تحمل `PIN` أيضاً، لكنها كتلُ base64
             * تبلغ آلافَ الأحرف. فتُستبعد بعلامتها (`TMP=`) وبطولها قبل
             * أن تُفكَّك — فتفكيكُ ألفِ سطرٍ منها يُثقل الذاكرة بلا غرض.
             */
            if (stripos($line, 'TMP=') !== false || mb_strlen($line) > 2000) {
                continue;
            }

            /*
             * بادئةُ اسم الجدول تسقط في بعض الإصدارات وتبقى في أخرى
             * (`USER PIN=…` أو `PIN=…`)، وقد يختلف ترتيبُ الحقول. فتُقطع
             * البادئةُ إن وُجدت ثم يُقرأ الباقي بالأسماء لا بالمواضع.
             */
            $body = preg_replace('/^\s*\w+\s+(?=\w+=)/u', '', $line, 1) ?? $line;

            $f = [];

            foreach (preg_split('/\t+/', $body) as $pair) {
                if (str_contains($pair, '=')) {
                    [$k, $v] = explode('=', $pair, 2);
                    $f[strtolower(trim($k))] = trim($v);
                }
            }

            $pin  = (string) ($f['pin'] ?? '');
            $name = (string) ($f['name'] ?? '');

            /*
             * الاسمُ الفارغ لا يُقبل. فالربطُ باسمٍ فارغ يُخرج الرقمَ من
             * قائمة «أرقامٌ بلا اسم» ويُسكِت التنبيه — فيبقى صاحبُه مجهولاً
             * ولا شيءَ يدلّ عليه. والبقاءُ في القائمة خيرٌ من ربطٍ كاذب.
             */
            if ($pin === '' || $pin === '0' || $name === '') {
                continue;
            }

            /*
             * البطاقةُ اسمُها `Card` في أجهزة الحضور و`CardNo` في أجهزة
             * التحكّم. وكشفت المحاكاةُ هذا الفرقَ قبل النشر: كان الاسمُ
             * يُقرأ والبطاقةُ تضيع صامتةً في نصف الأجهزة.
             */
            $card = (string) ($f['cardno'] ?? $f['card'] ?? '');

            $rows[] = [
                'device_pin' => mb_substr($pin, 0, 32),
                'name'       => mb_substr($name, 0, 190),
                'card'       => ($card !== '' && $card !== '0') ? mb_substr($card, 0, 32) : null,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function parseLine(string $line): ?array
    {
        $parts = str_contains($line, "\t")
            ? explode("\t", $line)
            : preg_split('/\s{2,}/', $line);

        /*
         * لا جدولةَ ولا مسافاتٌ مزدوجة: الحقولُ مفصولةٌ بمسافةٍ واحدة،
         * والطابعُ نفسُه فيه مسافة. فيُقرأ بموضعه: رقمٌ ثم تاريخٌ ثم وقت.
         */
        if (count($parts) < 2) {
            $parts = $this->splitBySingleSpace($line);
        }

        $parts = array_values(array_map('trim', $parts));

        $pin   = $parts[0] ?? '';
        $stamp = $parts[1] ?? '';

        if ($pin === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $stamp)) {
            return null;
        }

        $at = Clock::fromDevice($stamp);

        if ($at === null) {
            return null;
        }

        return [
            'device_pin'  => mb_substr($pin, 0, 32),
            'punched_at'  => $at,
            'work_date'   => Clock::workDate($at),
            'state'       => $this->byte($parts[2] ?? 0),
            'verify_mode' => $this->byte($parts[3] ?? 1),
            'work_code'   => ($parts[4] ?? '') !== '' ? mb_substr((string) $parts[4], 0, 32) : null,
        ];
    }

    /** @return array<int, string> */
    private function splitBySingleSpace(string $line): array
    {
        $bits = preg_split('/\s+/', $line) ?: [];

        if (count($bits) < 3) {
            return $bits;
        }

        // الرقم، ثم (التاريخ + الوقت) حقلاً واحداً، ثم الباقي كما هو.
        return array_merge([$bits[0], $bits[1].' '.$bits[2]], array_slice($bits, 3));
    }

    /** قيمةٌ في مدى البايت — الجهازُ قد يرسل فراغاً أو حرفاً. */
    private function byte(mixed $value): int
    {
        $n = (int) $value;

        return ($n >= 0 && $n <= 255) ? $n : 0;
    }
}
