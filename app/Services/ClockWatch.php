<?php

namespace App\Services;

use App\Models\ClockEvent;
use App\Models\Command;
use App\Models\Device;
use App\Support\ClockJudge;
use Illuminate\Support\Carbon;

/**
 * حارسُ الساعة — يجمع السياقَ ويسأل `ClockJudge` ثم يفتح الحدثَ ويُغلقه.
 *
 * والقسمةُ بينهما مقصودة: الحكمُ هناك خالصٌ بلا قاعدةِ بيانات فيُفحص كاملاً
 * بمحاكاةٍ على جهازٍ لا `vendor` فيه، وهذا هنا لا يحكم بشيء — يسأل ويكتب.
 *
 * وهو يُدعى في كل دفعةِ نبضات، وأكثرُ ما يفعله أن يسكت: يقيس، فيجد القراءةَ
 * غيرَ صالحةٍ للحكم، فيمرّ. وذاك هو المطلوب — فإنذارٌ كاذبٌ كلَّ يومٍ يُبلّد
 * العينَ عن العبث الحقيقي حين يقع.
 */
class ClockWatch
{
    /**
     * قراءةُ ساعةٍ من دفعةٍ وصلت.
     *
     * @param  Carbon  $newest  أحدثُ بصمةٍ في الدفعة (UTC)
     * @param  array{stored?: int, rows?: int, span?: int, prev_seen?: Carbon|null}  $batch
     */
    public function read(Device $device, Carbon $newest, array $batch = []): ?ClockEvent
    {
        $now   = Carbon::now('UTC');
        $drift = $newest->getTimestamp() - $now->getTimestamp();

        $gap = isset($batch['prev_seen']) && $batch['prev_seen'] instanceof Carbon
            ? $now->getTimestamp() - $batch['prev_seen']->getTimestamp()
            : null;

        $verdict = ClockJudge::eligible([
            'stored'     => (int) ($batch['stored'] ?? 0),
            'rows'       => (int) ($batch['rows'] ?? 0),
            'span'       => (int) ($batch['span'] ?? 0),
            'gap_before' => $gap,
            'after_sync' => $this->syncedRecently($device, $now),
        ]);

        /*
         * بصمةٌ من المستقبل تُقبل ولو رُدّت الدفعةُ في سائر شروطها.
         *
         * فكلُّ شروط الصلاحية إنما جاءت لتفسّر **التأخّر** — متراكمُ انقطاع،
         * دفعةٌ مُعادة، رفعٌ شامل. وليس فيها ما يفسّر التقدّم: لا شيءَ في
         * الدنيا يُنتج بصمةً لم تقع بعد. فردُّها هنا يُفلت أفصحَ دليلٍ
         * يمكن أن يصلنا.
         */
        if (! $verdict['ok'] && ! ClockJudge::fromFuture($drift)) {
            return null;
        }

        $prevDrift = $device->clock_drift === null ? null : (int) $device->clock_drift;

        $dt = $device->clock_checked_at
            ? $now->getTimestamp() - $device->clock_checked_at->getTimestamp()
            : 0;

        /* القراءةُ تُسجَّل على الجهاز أوّلاً — فهي أساسُ القراءة التالية */
        $device->forceFill([
            'clock_drift'      => $drift,
            'clock_checked_at' => $now,
        ] + (abs($drift) <= ClockJudge::limits()['tolerance']
            ? ['clock_good_at' => $now]
            : []))->saveQuietly();

        if (ClockJudge::shouldOpen($drift)) {
            return $this->open($device, $drift, $prevDrift, $dt, $gap, $now, $newest);
        }

        if (ClockJudge::shouldClose($drift)) {
            $this->close($device, $drift, $now);
        }

        /*
         * وما بين الحدّين لا يفتح ولا يُغلق — حزامُ التردّد.
         *
         * فجهازٌ واقفٌ على حدٍّ واحدٍ يفتح ويُغلق كلَّ دقيقة، فيصير الجدولُ
         * مئةَ حدثٍ لانحرافٍ واحدٍ لم يتغيّر.
         */
        return null;
    }

    /* ── الفتح ─────────────────────────────────────────────────────── */

    private function open(
        Device $device,
        int $drift,
        ?int $prevDrift,
        int $dt,
        ?int $gap,
        Carbon $now,
        Carbon $newest,
    ): ClockEvent {
        $open = ClockEvent::query()
            ->where('device_id', $device->id)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();

        /*
         * حدثٌ مفتوحٌ يُغذّى ولا يُستأنف.
         *
         * فالانحرافُ الواحد يُقرأ عشراتِ المرّات ما دام قائماً، ولو فُتح
         * لكلٍّ حدثٌ لصار جهازٌ ساعتُه مؤخَّرةٌ يوماً مئةَ سطرٍ في التقرير.
         */
        if ($open) {
            $open->forceFill([
                'readings'   => (int) $open->readings + 1,
                'peak_drift' => abs($drift) > abs((int) $open->peak_drift) ? $drift : $open->peak_drift,
                'confidence' => ClockJudge::confidence(
                    (string) $open->class,
                    (int) $open->peak_drift,
                    (int) $open->readings + 1,
                ),
            ])->save();

            return $open;
        }

        /*
         * حدثٌ أُغلق قبل قليلٍ يُستأنف ولا يُستجدّ.
         *
         * فساعةٌ تتذبذب حول الحدّ تُنتج حدثاً كلَّ دقيقتين، وكلُّها حدثٌ
         * واحد. والمهلةُ في الإعدادات.
         */
        $cooldown = (int) config('zk.clock.reopen_cooldown', 900);

        $recent = ClockEvent::query()
            ->where('device_id', $device->id)
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $now->copy()->subSeconds($cooldown))
            ->latest('closed_at')
            ->first();

        if ($recent) {
            $recent->forceFill([
                'closed_at'             => null,
                'close_drift'           => null,
                'resolution'            => null,
                'resolution_command_id' => null,
                'readings'              => (int) $recent->readings + 1,
                'peak_drift'            => abs($drift) > abs((int) $recent->peak_drift) ? $drift : $recent->peak_drift,
            ])->save();

            return $recent;
        }

        $class = ClockJudge::classify([
            'drift'              => $drift,
            'prev_drift'         => $prevDrift,
            'dt'                 => $dt,
            'gap_before'         => $gap,
            'max_gap_since_good' => $this->gapSinceGood($device, $now),
            'co_drift'           => $this->othersMovedToo($device, $drift, $now),
        ]);

        $event = ClockEvent::create([
            'device_id'    => $device->id,
            'opened_at'    => $now,
            'suspect_from' => $this->suspectFrom($device, $now),
            'open_drift'   => $drift,
            'peak_drift'   => $drift,
            'class'        => $class,
            'confidence'   => ClockJudge::confidence($class, $drift, 1),
            'readings'     => 1,
            'evidence'     => $this->evidence($device, $drift, $prevDrift, $dt, $gap, $newest),
        ]);

        /*
         * والإشعارُ سطرٌ في سجلّ المنصّة أيضاً، لا في الشاشة وحدَها.
         *
         * فمن لا يفتح الشاشةَ أسبوعاً لا يرى شيئاً، وسجلُّ المنصّة يُراقب
         * من خارجها. ودرجةُ السطر تتبع خطورةَ الحدث: `warning` لما يُتَّهم
         * به أحدٌ، و`info` لعلّةٍ في جهازٍ لا في الناس.
         */
        $line = [
            'الجهاز'   => $device->name,
            'الرقم'    => $device->serial_number,
            'التصنيف'  => ClockJudge::label($class),
            'الانحراف' => ClockJudge::describe($drift),
            'الثقة'    => $event->confidence,
            'نافذة'    => $event->suspect_from->toDateTimeString().' ← الآن',
        ];

        $event->blameless()
            ? logger()->info('انحرفت ساعةُ جهاز', $line)
            : logger()->warning('تغيّرت ساعةُ جهاز', $line);

        return $event;
    }

    /* ── الإغلاق ───────────────────────────────────────────────────── */

    private function close(Device $device, int $drift, Carbon $now): void
    {
        $events = ClockEvent::query()
            ->where('device_id', $device->id)
            ->whereNull('closed_at')
            ->get();

        foreach ($events as $event) {
            [$resolution, $commandId] = $this->explainReturn($device, $event, $now);

            $event->forceFill([
                'closed_at'             => $now,
                'close_drift'           => $drift,
                'resolution'            => $resolution,
                'resolution_command_id' => $commandId,
            ])->save();
        }
    }

    /**
     * كيف عادت الساعة؟
     *
     * ويُقرَّر الآنَ ويُخزَّن، ولا يُحسب عند العرض — لأن دليلَه أوامرُ تُقصّ
     * بعد حين، فيَنقلب الحكمُ بعد شهرٍ من «أعاده أمرُنا» إلى «أعادته يدٌ»
     * وقد حُذف دليلُ البراءة لا أكثر.
     *
     * @return array{0: string, 1: int|null}
     */
    private function explainReturn(Device $device, ClockEvent $event, Carbon $now): array
    {
        /*
         * أمرٌ أُرسل **داخل** المدّة — لا قبلها ولا بعدها.
         *
         * والحدُّ الأدنى `suspect_from` لا `opened_at`: أمرُ ضبطٍ أُرسل قبل
         * أن نكتشف الانحرافَ هو الذي أصلحه غالباً، فإخراجُه يُنتج تهمةً
         * سببُها ترتيبُ أحداثٍ لا فعلُ أحد.
         */
        $command = Command::query()
            ->where('device_id', $device->id)
            ->whereNotNull('sent_at')
            ->whereBetween('sent_at', [$event->suspect_from, $now])
            ->whereIn('kind', ['set_time', 'reboot'])
            ->where(function ($q) {
                /*
                 * الأمرُ المرفوضُ لا يفسّر شيئاً، والذي لم يُقَرّ به يفسّر.
                 *
                 * فأكثرُ الطُّرُز لا تُقِرّ بالضبط أصلاً — تنفّذ وتسكت. ولو
                 * شُرِط الإقرارُ لصار كلُّ ضبطٍ ناجحٍ «أعادته يدٌ»: تهمةٌ
                 * تُقال في نزاعِ أجرٍ سببُها سكوتُ جهاز. والاحتمالُ أن
                 * يُصلحها إنسانٌ في الدقائق التي أرسلنا فيها الأمرَ بعيدٌ
                 * جدّاً — فالخطأُ في الاتّهام أفدحُ من الخطأ في العذر.
                 */
                $q->whereNull('return_code')
                    ->orWhereRaw('CAST(return_code AS DECIMAL(20,0)) >= 0');
            })
            ->latest('sent_at')
            ->first();

        if ($command) {
            return [
                $command->kind === 'reboot' ? ClockEvent::BY_REBOOT : ClockEvent::BY_COMMAND,
                (int) $command->id,
            ];
        }

        /*
         * لا أمرَ منّا يفسّرها. فإن كانت العلّةُ في الجهاز لا في الناس —
         * زحفُ بلّورةٍ أو قياسٌ مضطرب — فقد انقضت من نفسها.
         *
         * وما سوى ذلك **يدٌ**: ومن ملك القائمةَ ليُصلحها ملكها ليُفسدها.
         */
        if ($event->blameless() && abs((int) $event->peak_drift) < ClockJudge::limits()['hard']) {
            return [ClockEvent::FADED, null];
        }

        return [ClockEvent::BY_HAND, null];
    }

    /* ── السياق ────────────────────────────────────────────────────── */

    /**
     * مبدأُ نافذة الشكّ: آخرُ قراءةٍ **نظيفة**.
     *
     * وليس لحظةَ الكشف — وهذا أهمُّ ما في الحارس. من قدّم الساعةَ الثامنةَ
     * صباحاً لا يُكتشف إلا عند أوّل بصمةٍ بعده، فلو بدأت النافذةُ من الكشف
     * لَسَلِمَ ما بين الفعل والكشف، وهو المقصودُ بالعبث.
     *
     * وله سقفٌ: جهازٌ لم تُقرأ ساعتُه نظيفةً منذ شهرين لا تُفتح عليه نافذةُ
     * شهرين — فتصير كلُّ بصماته مشكوكةً ولا يُقرأ التقرير.
     */
    private function suspectFrom(Device $device, Carbon $now): Carbon
    {
        $floor = $now->copy()->subSeconds(ClockJudge::limits()['lookback_max']);

        $good = $device->clock_good_at;

        return ($good && $good->gt($floor)) ? $good->copy() : $floor;
    }

    /** أطولُ انقطاعٍ منذ آخر قراءةٍ نظيفة — يُفرّق القفزةَ عن الإقلاع. */
    private function gapSinceGood(Device $device, Carbon $now): ?int
    {
        if (! $device->clock_good_at) {
            return null;
        }

        /*
         * ولا يُقاس بدقّةٍ من سجلٍّ لا نحفظه — يُقاس بأقصى ما يمكن: المدّةُ
         * كلُّها. فإن كانت أقصرَ من مهلة الاتّصال فالجهازُ لم ينقطع يقيناً،
         * وإن طالت فقد ينقطع وقد لا. والشكُّ يُفسَّر لصالح الجهاز.
         */
        return $now->getTimestamp() - $device->clock_good_at->getTimestamp();
    }

    /**
     * أانحرفت الأجهزةُ الأخرى معه في اللحظة نفسِها؟
     *
     * فإن فعلت فالذي قفز ساعةُ **الخادم** لا ساعاتُها — ولا يتواطأ عابثون
     * في مواقعَ متفرّقةٍ على الثانية والمقدار. وهذا يمنع أن يُتَّهم كلُّ
     * جهازٍ في الشركة لأن خادماً زُحزح وقتُه.
     */
    private function othersMovedToo(Device $device, int $drift, Carbon $now): bool
    {
        $others = Device::query()
            ->where('id', '!=', $device->id)
            ->whereNotNull('clock_drift')
            ->whereNotNull('clock_checked_at')
            ->where('clock_checked_at', '>=', $now->copy()->subSeconds(600))
            ->get(['id', 'site_id', 'clock_drift']);

        $agree = $others->filter(function ($o) use ($drift) {
            $d = (int) $o->clock_drift;

            /* الإشارةُ نفسُها والمقدارُ في الربع — لا تطابقٌ تامّ */
            return $d !== 0 && ($d > 0) === ($drift > 0)
                && abs(abs($d) - abs($drift)) <= 0.25 * abs($drift);
        });

        /*
         * ومن **موقعين مختلفين على الأقلّ**: خمسةُ أجهزةٍ في موقعٍ واحد
         * تنحرف معاً لعلّةٍ واحدة — كهرباءُ الموقع أو شبكتُه — وتلك علّةُ
         * موقعٍ لا علّةُ خادم.
         */
        return $agree->count() >= 2
            && $agree->pluck('site_id')->unique()->count() >= 2;
    }

    /**
     * أطلبنا منه رفعاً شاملاً قريباً؟
     *
     * فأمرُ المزامنة يجعل الجهازَ يرفع ذاكرتَه كلَّها — شهوراً من البصمات —
     * وهو متّصلٌ لا منقطع. فتبدو أحدثُ بصمةٍ في الدفعة قديمةً جدّاً وتُقرأ
     * «ساعةً مؤخَّرةً شهرين» وهي دفعةٌ نحن سبَبُها.
     */
    private function syncedRecently(Device $device, Carbon $now): bool
    {
        return Command::query()
            ->where('device_id', $device->id)
            ->whereIn('kind', ['check', 'clear_log'])
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', $now->copy()->subSeconds(ClockJudge::limits()['reupload_window']))
            ->exists();
    }

    /** نصُّ القراءة التي فتحت الحدث — تُقرأ بعد شهرٍ فتُفهم. */
    private function evidence(
        Device $device,
        int $drift,
        ?int $prevDrift,
        int $dt,
        ?int $gap,
        Carbon $newest,
    ): string {
        return implode("\n", [
            'أحدثُ بصمةٍ في الدفعة: '.$newest->toDateTimeString().' (بحسب الجهاز)',
            'الانحراف: '.$drift.' ثانية — '.ClockJudge::describe($drift),
            'الانحرافُ قبله: '.($prevDrift === null ? 'لا قراءةَ سابقة' : $prevDrift.' ثانية'),
            'بين القراءتين: '.$dt.' ثانية',
            'انقطاعٌ قبل الدفعة: '.($gap === null ? 'غيرُ معروف' : $gap.' ثانية'),
            'القفزة: '.(ClockJudge::isStep($drift, $prevDrift, $dt) ? 'نعم' : 'لا'),
            'نوعُ الجهاز: '.($device->device_type ?: 'غيرُ معروف'),
        ]);
    }
}
