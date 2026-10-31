<?php

namespace App\Services;

use App\Support\Clock;
use Illuminate\Support\Carbon;

/**
 * قراءةُ صورةِ بصمةٍ كما يدفعها الجهاز.
 *
 * والجسدُ هنا ليس نصّاً: ترويسةٌ نصّيةٌ قصيرة، ثم سطرٌ فاصل، ثم بايتاتُ
 * الصورة خاماً —
 *
 *     PIN=14 ⇥ SN=AJE… ⇥ size=8134 ⇥ CMD=uploadphoto
 *     <بايتات JPEG>
 *
 * وتختلف الإصداراتُ في هذه الترويسة: بعضُها يضع الاسمَ في `filename`
 * وفيه الطابعُ الزمني (`20260926141103-14.jpg`)، وبعضُها يكتفي بـ`PIN`،
 * وبعضُها يضع المفاتيحَ في عنوان الطلب لا في الجسد. فالقراءةُ تجمعها من
 * حيث وُجدت.
 *
 * **ولا يُقبل إلا ما ثبت أنه صورة.** النوعُ يُقرأ من البايتات الأولى لا
 * من تصريح الجهاز — والجهازُ لا يُصرّح أصلاً في أكثر الإصدارات.
 */
class PhotoParser
{
    /**
     * @param  array<string, mixed>  $query  معاملاتُ عنوان الطلب
     * @return array{pin: string, taken_at: Carbon, bytes: string}|null
     */
    public function parse(string $payload, array $query = []): ?array
    {
        if ($payload === '') {
            return null;
        }

        $queryFields = $this->fields(implode("\t", array_map(
            fn ($k, $v) => $k.'='.$v,
            array_keys($query),
            array_values($query),
        )));

        /*
         * شكلان للجسد، ويُجرَّب الأرجحُ أوّلاً:
         *
         *   الأول — كلُّه حقولٌ في سطرٍ واحد، والصورةُ فيها مُرمَّزةً
         *   base64 تحت اسم `photo`. وهذا ما يرسله SpeedFace فعلاً.
         *
         *   والثاني — ترويسةٌ ثم سطرٌ ثم بايتاتٌ خام.
         */
        $f     = $this->fields($payload) + $queryFields;
        $bytes = $this->decode($f);

        if ($bytes === '') {
            [$head, $bytes] = $this->split($payload);

            if ($bytes === '') {
                return null;
            }

            $f = $this->fields($head) + $queryFields;
        }

        $raw = (string) ($f['pin'] ?? '');

        /*
         * **وحقلُ `pin` قد يحمل اسمَ الملف لا الرقم** — كذا يرسله
         * SpeedFace: `pin=20260926080835-2005.jpg`. ولولا هذا التمييز
         * لخُزّن اسمُ الملف رقماً للموظف، فلا يوافق رقماً مربوطاً أبداً،
         * وظهرت كلُّ صورةٍ «غير مربوطة» بلا سببٍ ظاهر.
         */
        $name = (string) ($f['filename'] ?? $f['file'] ?? '');

        if ($name === '' && ! ctype_digit($raw)) {
            $name = $raw;
        }

        $pin = ctype_digit($raw) ? $raw : '';

        /* والرقمُ في آخر الاسم بعد الشرطة: …-2005.jpg */
        if ($pin === '' && preg_match('/(?:^|[-_])(\d+)\.\w+$/', $name, $m)) {
            $pin = $m[1];
        }

        if ($pin === '' || $pin === '0') {
            return null;
        }

        /*
         * `size` يقول الحجمَ المتوقَّع بعد فكّ الترميز. ومخالفتُه تعني
         * ترميزاً مقطوعاً — والصورةُ المقطوعة تُخزَّن ولا تُعرض، فيُظنّ
         * العطلُ في العرض وهو في الاستقبال.
         */
        $expected = (int) ($f['size'] ?? 0);

        if ($expected > 0 && $expected !== strlen($bytes)) {
            logger()->warning('حجمُ الصورة يخالف المصرَّح به', [
                'المصرَّح' => $expected,
                'الواصل'   => strlen($bytes),
                'name'     => $name,
            ]);
        }

        return [
            'pin'      => mb_substr($pin, 0, 32),
            'taken_at' => $this->when($f, $name),
            'bytes'    => $bytes,
        ];
    }

    /**
     * الصورةُ مُرمَّزةً base64 في حقل.
     *
     * ولا تُقبل إلا إن فُكّ ترميزُها وثبت أنها صورة — فحقلٌ اسمُه `photo`
     * وفيه نصٌّ ليس صورةً لا يُخزَّن.
     */
    private function decode(array $f): string
    {
        foreach (['photo', 'image', 'content', 'data'] as $key) {
            $value = (string) ($f[$key] ?? '');

            if ($value === '') {
                continue;
            }

            $bytes = base64_decode($value, true);

            if ($bytes !== false && \App\Models\PunchPhoto::sniff($bytes) !== null) {
                return $bytes;
            }
        }

        return '';
    }

    /**
     * فصلُ الترويسة عن البايتات.
     *
     * والفصلُ عند أوّل سطرٍ جديد: الترويسةُ سطرٌ واحد، وبايتاتُ الصورة
     * تلي. ولو لم يكن في الجسد سطرٌ جديدٌ أصلاً فكلُّه صورة.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $payload): array
    {
        /* بدأ الجسدُ بصورةٍ رأساً — لا ترويسةَ فيه */
        if (\App\Models\PunchPhoto::sniff($payload) !== null) {
            return ['', $payload];
        }

        $at = strpos($payload, "\n");

        if ($at === false) {
            return [$payload, ''];
        }

        return [
            substr($payload, 0, $at),
            ltrim(substr($payload, $at + 1), "\r"),
        ];
    }

    /** @return array<string, string> */
    private function fields(string $head): array
    {
        $out = [];

        foreach (preg_split('/[\t&]+/', $head) as $pair) {
            if (str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                $out[strtolower(trim($k))] = trim($v);
            }
        }

        return $out;
    }

    /**
     * لحظةُ التصوير.
     *
     * تُقرأ من حقلٍ صريحٍ إن وُجد، وإلا من الطابع المدفون في اسم الملف
     * (`20260926141103-14.jpg`)، وإلا فالآن. والأخيرُ ليس دقيقاً لكنّه
     * قريب: الصورةُ تُدفع لحظةَ التقاطها حين يكون `Realtime=1`.
     */
    private function when(array $f, string $name): Carbon
    {
        foreach (['time', 'phototime', 'datetime'] as $key) {
            if (($f[$key] ?? '') !== '') {
                $at = Clock::fromDevice($f[$key]);

                if ($at !== null) {
                    return $at;
                }
            }
        }

        if (preg_match('/(\d{14})/', $name, $m)) {
            $at = Clock::fromDevice(
                substr($m[1], 0, 4).'-'.substr($m[1], 4, 2).'-'.substr($m[1], 6, 2).' '.
                substr($m[1], 8, 2).':'.substr($m[1], 10, 2).':'.substr($m[1], 12, 2)
            );

            if ($at !== null) {
                return $at;
            }
        }

        return Carbon::now('UTC');
    }
}
