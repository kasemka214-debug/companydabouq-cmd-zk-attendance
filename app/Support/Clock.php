<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * وقتُ العرض بتوقيت الكويت — والتخزينُ غرينتش.
 *
 * وهذا النظامُ أشدُّ حاجةً إلى القاعدة من غيره: الجهازُ يرسل ساعتَه المحليةَ
 * عاريةً بلا منطقة، فلو خُزّنت كما جاءت لصار في العمود الواحد توقيتان —
 * توقيتُ جهازٍ ضُبط على الكويت، وتوقيتُ آخرَ نُسي على ضبط المصنع — ولا علامةَ
 * تفرّق بينهما بعد سنة.
 *
 * فالاستقبالُ يُفسّر الطابعَ بمنطقة الجهاز ثم يحوّله إلى غرينتش، والعرضُ
 * يعكس الطريق. والقاعدةُ على لسانٍ واحد.
 */
class Clock
{
    public const ZONE = 'Asia/Kuwait';

    /** منطقةُ العرض. */
    public static function zone(): string
    {
        return (string) (config('app.display_timezone') ?: self::ZONE);
    }

    /** المنطقةُ التي يُفسَّر بها ما يرسله الجهاز. */
    public static function deviceZone(): string
    {
        return (string) (config('zk.device_timezone') ?: self::ZONE);
    }

    /**
     * طابعٌ جاء من الجهاز («2026-09-20 07:14:03») محوَّلاً إلى غرينتش للتخزين.
     *
     * والفشلُ يُرجع null لا استثناءً: سطرٌ مشوَّهٌ واحدٌ في دفعةٍ من مئة
     * لا يجوز أن يُسقط الدفعةَ كلَّها، وإلا أعاد الجهازُ إرسالَها إلى الأبد.
     */
    public static function fromDevice(?string $stamp): ?Carbon
    {
        $stamp = trim((string) $stamp);

        if ($stamp === '') {
            return null;
        }

        try {
            return Carbon::parse($stamp, self::deviceZone())->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** قيمةٌ من القاعدة محوَّلةً إلى توقيت العرض. */
    public static function at($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof \DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse((string) $value, 'UTC');

            return $date->setTimezone(self::zone());
        } catch (\Throwable) {
            return null;
        }
    }

    /** الطابعُ جاهزاً للعرض، أو شرطةٌ إن لم يكن. */
    public static function stamp($value, string $format = 'Y-m-d H:i'): string
    {
        return self::at($value)?->format($format) ?? '—';
    }

    /** الآنَ بتوقيت العرض. */
    public static function now(): Carbon
    {
        return Carbon::now(self::zone());
    }

    /**
     * يومُ العمل الذي تنتمي إليه لحظةٌ محلية.
     *
     * مَن بصم خروجاً الواحدةَ بعد منتصف الليل فخروجُه من نوبةِ أمس. وبغير
     * هذه النقلة يظهر يومان ناقصان — أحدهما بلا خروج والآخر بلا دخول —
     * بدل يومٍ تامّ. وساعةُ الفصل في الإعدادات لا في الشيفرة.
     */
    public static function workDate(Carbon $utc): string
    {
        $local = $utc->copy()->setTimezone(self::zone());

        [$h, $m] = array_pad(explode(':', (string) config('zk.day_starts_at', '04:00')), 2, '0');

        $boundary = $local->copy()->setTime((int) $h, (int) $m, 0);

        return $local->lt($boundary)
            ? $local->copy()->subDay()->toDateString()
            : $local->toDateString();
    }
}
