<?php

namespace App\Models;

use App\Support\Clock;
use App\Support\ClockJudge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * حدثُ ساعة — مدّةٌ كانت فيها ساعةُ جهازٍ خارج الحدّ.
 *
 * ونطاقُ الضرر يُحسب ولا يُخزَّن، وأمّا سببُ العودة فيُخزَّن. والسرُّ في
 * الفرق: نطاقُ الضرر يُحسب من نبضاتٍ باقية، وسببُ العودة يُقرأ من أوامرَ
 * تُقصّ — فالمحسوبُ منهما يشيخ ويكذب بعد شهر.
 */
class ClockEvent extends Model
{
    protected $table = 'zk_clock_events';

    protected $fillable = [
        'device_id', 'opened_at', 'suspect_from', 'closed_at',
        'open_drift', 'peak_drift', 'close_drift',
        'class', 'confidence', 'resolution', 'resolution_command_id',
        'readings', 'evidence', 'acknowledged_at', 'acknowledged_by', 'note',
    ];

    protected $casts = [
        'opened_at'       => 'datetime',
        'suspect_from'    => 'datetime',
        'closed_at'       => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    /* ── أسبابُ العودة ─────────────────────────────────────────────── */

    public const BY_COMMAND = 'by_command';  // أمرُ ضبطٍ أرسلناه نحن
    public const BY_REBOOT  = 'by_reboot';   // أُعيد تشغيلُ الجهاز
    public const BY_HAND    = 'by_hand';     // يدٌ — ولا أمرَ منّا يفسّرها
    public const FADED      = 'faded';       // عاد الانحرافُ وحدَه

    public const RESOLUTIONS = [
        self::BY_COMMAND => 'أعادها أمرُ ضبطٍ من النظام',
        self::BY_REBOOT  => 'عادت بإعادة تشغيل الجهاز',
        self::BY_HAND    => 'أعادها أحدٌ من الجهاز',
        self::FADED      => 'انقضت من نفسها',
    ];

    /** مفتاحُ عدّاد الجرس في المخزون. */
    public const ALERT_CACHE = 'clock.alerts';

    /**
     * كلُّ كتابةٍ تُبطل عدّادَ الجرس.
     *
     * وهو هنا لا في المتحكّم لأن الأحداثَ تُكتب من طريقين: يدُ المسؤول حين
     * يُقِرّ، وجهازٌ يدفع دفعةً فيُفتح له حدث. فلو أُبطل في أحدهما بقي
     * الجرسُ في الآخر دقيقةً يقول عدداً كاذباً — أو أخفى حدثاً جديداً.
     */
    protected static function booted(): void
    {
        $forget = fn () => Cache::forget(self::ALERT_CACHE);

        static::saved($forget);
        static::deleted($forget);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(Command::class, 'resolution_command_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /* ── المرشّحات ─────────────────────────────────────────────────── */

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('closed_at');
    }

    /**
     * ما يستحقّ إشعاراً: ما لم يُقَرّ به بعد.
     *
     * **لا «ما هو مفتوح»** — والفرقُ يُسقط أخطرَ الحالات: من غيّر الساعةَ
     * ثم أعادها بعد دقائق أُغلق حدثُه من نفسه، ولو كان الإشعارُ على
     * المفتوح لَما رآه أحد. وذاك بعينه فعلُ العابث: يُقدّم الساعة، يبصم،
     * يُعيدها.
     */
    public function scopeUnacknowledged(Builder $q): Builder
    {
        return $q->whereNull('acknowledged_at');
    }

    /**
     * ما يستحقّ أن يُوقَظ له المسؤول — لا كلُّ انحراف.
     *
     * فالحدثُ **يُسجَّل دائماً** ويبقى في التقرير، وأمّا الجرسُ فلا يُقرع
     * إلا لما يُستحقّ. والفرقُ ضرورة: زحفُ بلّورةٍ اثنتي عشرةَ دقيقةً حدثٌ
     * صحيحٌ يُكتب، ولو قُرع له الجرسُ لقُرع كلَّ أسبوع في كل جهازٍ رخيصٍ —
     * فتُبلَّد العينُ عن العبث الحقيقيّ حين يقع.
     *
     * وهذا هو `ClockJudge::alertsOnFirstReading` نفسُه مكتوباً باستعلام:
     * الأصنافُ التي أعذارُها البريئةُ كثيرةٌ لا تُنذر إلا إن جاوزت الحدَّ
     * القاطع.
     *
     * **والشرطُ مُجمَّعٌ في `where(function ...)`** — ولو كُتب `orWhere`
     * عارياً لخرج من التجميع فأبطل كلَّ مرشِّحٍ قبله: يصير «أحداثُ هذا
     * الجهاز التي تستحقّ إنذاراً» هي «أحداثُ هذا الجهاز **أو** أيُّ حدثٍ
     * مؤكَّدٍ في الشركة كلِّها».
     */
    public function scopeAlarming(Builder $q): Builder
    {
        $weak = [ClockJudge::BOOT_SKEW, ClockJudge::TIMEZONE, ClockJudge::DRIFT_SLOW];
        $hard = ClockJudge::limits()['hard'];

        return $q->where(function (Builder $w) use ($weak, $hard) {
            $w->whereNotIn('class', $weak)
                ->orWhereRaw('ABS(peak_drift) >= ?', [$hard]);
        });
    }

    /* ── الصياغة ───────────────────────────────────────────────────── */

    public function classLabel(): string
    {
        return ClockJudge::label((string) $this->class);
    }

    public function resolutionLabel(): string
    {
        if ($this->closed_at === null) {
            return 'ما زالت منحرفة';
        }

        return self::RESOLUTIONS[(string) $this->resolution] ?? 'غيرُ معروف';
    }

    public function driftLabel(): string
    {
        return ClockJudge::describe((int) $this->peak_drift);
    }

    /** مدّةُ الحدث بالعربية — «٣ ساعات» أو «ما زالت مفتوحة». */
    public function spanLabel(): string
    {
        if (! $this->closed_at) {
            return 'مفتوحٌ حتى الآن';
        }

        $seconds = $this->closed_at->getTimestamp() - $this->suspect_from->getTimestamp();

        if ($seconds < 3600) {
            return max(1, intdiv($seconds, 60)).' دقيقة';
        }

        if ($seconds < 86400) {
            return number_format($seconds / 3600, 1).' ساعة';
        }

        return number_format($seconds / 86400, 1).' يوم';
    }

    /**
     * أخطرُ الأحداث: تغييرٌ مقصودٌ مؤكَّد.
     *
     * والألوانُ تتبع هذا: الأحمرُ لما يُتَّهم به أحد، والأصفرُ لما يُحتمل،
     * والرماديُّ لعلّةٍ في الجهاز لا في الناس. وتسويةُ الثلاثة في اللون
     * تُبلّد العينَ عن أخطرها.
     */
    public function severe(): bool
    {
        return $this->class === ClockJudge::TAMPER && $this->confidence === 'confirmed';
    }

    public function blameless(): bool
    {
        return in_array((string) $this->class, [
            ClockJudge::RTC_DEAD, ClockJudge::SERVER_CLOCK,
            ClockJudge::DRIFT_SLOW, ClockJudge::NEVER_SET,
        ], true);
    }

    /**
     * النبضاتُ التي وصلت في نافذة الشكّ.
     *
     * **بوقت الوصول لا بوقت البصمة** — وهذه أهمُّ جملةٍ في الملفّ كلِّه:
     * لا تُستعمل ساعةٌ كاذبةٌ لتحديد ما أفسدته. فمن قدّم الساعةَ يوماً
     * كاملاً صارت بصماتُه موسومةً بالغد، فلو رُشّحت بـ`punched_at` سقطت
     * من النافذة كلُّها — والنافذةُ إنما فُتحت لها.
     *
     * و`received_at` يكتبه الخادمُ لحظةَ وصولها، فلا يملكه أحدٌ في الموقع.
     */
    public function suspectPunches()
    {
        return Punch::query()
            ->where('device_id', $this->device_id)
            ->where('received_at', '>=', $this->suspect_from)
            ->when($this->closed_at, fn ($q) => $q->where('received_at', '<=', $this->closed_at));
    }

    /** العملياتُ التي بلّغ بها الجهازُ في المدّة نفسِها — الشاهدُ المُسمِّي. */
    public function operations()
    {
        return Operation::query()
            ->where('device_id', $this->device_id)
            ->where('received_at', '>=', $this->suspect_from->copy()->subMinutes(5))
            ->when(
                $this->closed_at,
                fn ($q) => $q->where('received_at', '<=', $this->closed_at->copy()->addMinutes(5)),
            )
            ->orderBy('received_at');
    }

    public function openedLabel(): string
    {
        return Clock::stamp($this->opened_at, 'Y-m-d H:i:s');
    }
}
