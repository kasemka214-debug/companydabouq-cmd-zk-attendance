<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * حكمُ الدوام على يومٍ تامّ: أتأخّرَ صاحبُه عن بدايته، وأنقصَ من ساعاته؟
 *
 * وهو دالّةٌ صافيةٌ لا تمسّ قاعدةَ بيانات — كـ`ClockJudge` و`AbsenceJudge` —
 * **ولا تمسّ لارافيل**: لا كاربون ولا مجموعة، إنما `DateTimeImmutable`
 * المحضة. وذلك ليُحاكى بأرقامٍ مصطنعةٍ في ملفٍّ يُشغَّل بلا `vendor`
 * (`scripts/محاكاة-الدوام.php`) — فحسابٌ يُخصم به من أجرِ رجلٍ لا يُسلَّم
 * إلا بتجربةٍ تُعرض أرقامُها.
 *
 * **والسماحُ معوَّضٌ لا موهوب.** مهلةُ الربع ساعةٍ ترفع عنه اسمَ «المتأخّر»،
 * ولا ترفع عنه ربعَ ساعةٍ من دوامه: من بصم السابعةَ والربع خرج الثالثةَ
 * والربع. ولذلك قياسان لا واحد — التأخّرُ يُقاس إلى بداية الموقع، والنقصُ
 * يُقاس إلى بصمة الرجل نفسِه.
 *
 * **واليومُ الناقصُ لا يُحكم عليه.** بصمةٌ واحدةٌ في اليوم ليست انصرافاً
 * مبكّراً — هي خروجٌ لم يُبصم أو دخولٌ لم يُبصم، ولا يُعرف أيُّهما. والحكمُ
 * على صمتٍ ظلمٌ يُكتب في ورقةٍ يُخصم بها.
 */
class ShiftJudge
{
    /**
     * @param  array{start: string, minutes: int, grace: int}  $schedule
     * @return array{
     *     judged: bool, late: int, short: int, worked: ?int,
     *     starts_at: DateTimeImmutable, ends_at: DateTimeImmutable, due_out: ?DateTimeImmutable
     * }
     */
    public static function judge(string $workDate, $firstAt, $lastAt, array $schedule): array
    {
        $zone = new DateTimeZone(Clock::zone());

        [$h, $m] = array_pad(explode(':', (string) $schedule['start']), 2, '0');

        /* بدايةُ الدوام ونهايتُه الرسمية — بتوقيت العرض، في يوم العمل نفسِه */
        $startsAt = (new DateTimeImmutable($workDate, $zone))->setTime((int) $h, (int) $m);
        $endsAt   = $startsAt->modify('+'.(int) $schedule['minutes'].' minutes');

        $first = self::moment($firstAt, $zone);
        $last  = self::moment($lastAt, $zone);

        if (! $first || ! $last) {
            return [
                'judged'    => false,
                'late'      => 0,
                'short'     => 0,
                'worked'    => null,
                'starts_at' => $startsAt,
                'ends_at'   => $endsAt,
                'due_out'   => null,
            ];
        }

        /*
         * بالطوابع لا بفروق المكتبات: إشارةُ الفرق تختلف بين إصدارٍ
         * وإصدار، وهذا الرقمُ يُقرأ على أنه تأخّرُ رجل. والطابعُ لا
         * يختلف عليه اثنان.
         */
        $graceEnd = $startsAt->modify('+'.(int) $schedule['grace'].' minutes');

        $late   = (int) floor(max(0, $first->getTimestamp() - $graceEnd->getTimestamp()) / 60);
        $worked = (int) floor(($last->getTimestamp() - $first->getTimestamp()) / 60);
        $short  = max(0, (int) $schedule['minutes'] - $worked);

        return [
            'judged'    => true,
            'late'      => $late,
            'short'     => $short,
            'worked'    => $worked,
            'starts_at' => $startsAt,
            'ends_at'   => $endsAt,

            /* المطلوبُ خروجاً: من بصمته هو — وهذا هو التعويض بعينه */
            'due_out'   => $first->modify('+'.(int) $schedule['minutes'].' minutes'),
        ];
    }

    /**
     * الجدولُ الفعّال: ما كُتب للموقع، وما تُرك فارغاً وَرِثَه من الإعدادات.
     *
     * وهو هنا لا في النموذج ليُحاكى: ملفُّ المحاكاة يُشغَّل بلا `vendor`،
     * فلا يستطيع تحميلَ نموذجٍ يرث Eloquent. و`Site::schedule()` ينادي هذا.
     *
     * @return array{start: string, minutes: int, grace: int}
     */
    public static function schedule($start, $minutes, $grace): array
    {
        $start = trim((string) ($start ?? ''));
        $start = $start !== '' ? $start : (string) config('zk.work_start', '07:00');

        /* العمودُ `time` يعود «07:00:00» والشاشةُ تكتب «07:00» — يُسوّى الاثنان */
        [$h, $m] = array_pad(explode(':', $start), 2, '0');

        $minutes = $minutes !== null && $minutes !== ''
            ? (int) $minutes
            : (int) round((float) config('zk.daily_hours', 8) * 60);

        $grace = $grace !== null && $grace !== ''
            ? (int) $grace
            : (int) config('zk.grace_minutes', 15);

        return [
            'start'   => str_pad((string) (int) $h, 2, '0', STR_PAD_LEFT)
                .':'.str_pad((string) (int) $m, 2, '0', STR_PAD_LEFT),
            'minutes' => max(1, $minutes),
            'grace'   => max(0, $grace),
        ];
    }

    /**
     * «من ٠٧:٠٠ إلى ١٥:٠٠» ⇐ دقائقُ الدوام.
     *
     * **والنهايةُ قبل البداية مناوبةٌ ليلية** لا خطأً: حارسٌ يبدأ السادسةَ
     * مساءً وينتهي السادسةَ صباحاً دوامُه اثنتا عشرةَ ساعة، لا سالبَ اثنتي
     * عشرة — ولو حُسبت سالباً لخرج الرجلُ ناقصاً كلَّ ليلةٍ يعملها.
     */
    public static function minutesBetween(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', array_pad(explode(':', $start), 2, '0'));
        [$eh, $em] = array_map('intval', array_pad(explode(':', $end), 2, '0'));

        $minutes = ($eh * 60 + $em) - ($sh * 60 + $sm);

        return $minutes > 0 ? $minutes : $minutes + 24 * 60;
    }

    /** دقائقُ إلى «٠:١٥» للعرض. */
    public static function hhmm(int $minutes): string
    {
        return intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /** لحظةٌ من القاعدة (غرينتش) أو من كاربون — بتوقيت العرض. */
    private static function moment($value, DateTimeZone $zone): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $at = $value instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($value)
                : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));

            return $at->setTimezone($zone);
        } catch (\Throwable) {
            return null;
        }
    }
}
