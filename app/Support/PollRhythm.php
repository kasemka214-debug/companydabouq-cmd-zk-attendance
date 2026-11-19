<?php

namespace App\Support;

/**
 * وتيرةُ نداء الأجهزة: كم ينام الجهازُ بين نداءٍ ونداء — نهاراً وليلاً.
 *
 * **والعلّةُ فاتورةُ الخادم لا شيءَ غيرُها.** فالجهازُ ينادي كلَّ نصف
 * دقيقةٍ ليلاً ونهاراً، وكلُّ نداءٍ يوقظ الخادمَ قبل أن يصل إلى شيفرتنا —
 * فلا ينام ساعةً في اليوم، و«Scale to zero» لا يجد موضعاً يعمل فيه. ورفضُ
 * ما يصل ليلاً لا يوفّر فلساً: الطلبُ أيقظه قبل أن يُرفض.
 *
 * والذي يوفّر أن تكفّ الأجهزةُ عن النداء: `Delay` في المصافحة، وأمرُ
 * `SET OPTION Delay=` في الطابور — وهي الآليةُ نفسُها التي يضبط بها
 * البرنامجُ ساعةَ الجهاز اليوم (`Command::bodyFor('set_time')`)، وقد نجحت
 * على نوعَي الأجهزة معاً.
 *
 * **ولا تُطرح بصمة.** ما بُصم في النافذة الهادئة يبقى في ذاكرة الجهاز
 * ويُرفع في ندائه التالي — بعد نصف ساعةٍ لا إلى الصباح. فمن عمل متأخّراً
 * يظهر في الكشف كما لو لم يكن ثَمَّ نافذة.
 *
 * وهذا الصنفُ صافٍ لا يمسّ قاعدةَ بيانات، ليُحاكى بلا `vendor`
 * (`scripts/محاكاة-النافذة.php`).
 */
class PollRhythm
{
    public const DAY = 'day';

    public const NIGHT = 'night';

    public static function enabled(): bool
    {
        return (bool) config('zk.quiet.enabled', false);
    }

    /**
     * الوتيرةُ المطلوبةُ في لحظةٍ — بتوقيت العرض (الكويت).
     *
     * و`$hhmm` للمحاكاة وحدَها؛ وما سواها يسأل الساعةَ بنفسه.
     */
    public static function mode(?string $hhmm = null): string
    {
        if (! self::enabled()) {
            return self::DAY;
        }

        $now  = self::minutes($hhmm ?? Clock::now()->format('H:i'));
        $from = self::minutes((string) config('zk.quiet.from', '17:00'));
        $to   = self::minutes((string) config('zk.quiet.to', '06:00'));

        /* والنافذةُ تعبر منتصفَ الليل: من الخامسة مساءً إلى السادسة صباحاً */
        $quiet = $from <= $to
            ? ($now >= $from && $now < $to)
            : ($now >= $from || $now < $to);

        return $quiet ? self::NIGHT : self::DAY;
    }

    /** ثوانٍ بين نداءَين في هذه الوتيرة. */
    public static function delay(?string $mode = null): int
    {
        $mode ??= self::mode();

        return $mode === self::NIGHT
            ? max(60, (int) config('zk.quiet.delay', 1800))
            : max(5, (int) config('zk.poll_delay', 30));
    }

    /** نصُّ الأمر الذي يُرسل إلى الجهاز. */
    public static function body(?string $mode = null): string
    {
        return 'SET OPTION Delay='.self::delay($mode ?? self::mode());
    }

    /** وصفُ الوتيرة في الشاشة: «ينادي كلَّ نصف دقيقة». */
    public static function label(?string $mode): string
    {
        if ($mode === null) {
            return 'لم يُضبط بعد';
        }

        $seconds = self::delay($mode);

        $every = $seconds % 60 === 0
            ? intdiv($seconds, 60).' دقيقة'
            : $seconds.' ثانية';

        return ($mode === self::NIGHT ? 'ليلٌ — ' : 'نهارٌ — ').'ينادي كلَّ '.$every;
    }

    private static function minutes(string $hhmm): int
    {
        [$h, $m] = array_pad(explode(':', trim($hhmm)), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }
}
