<?php

namespace App\Models;

use App\Services\ClockWatch;
use App\Support\Clock;
use App\Support\ClockJudge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Device extends Model
{
    protected $table = 'zk_devices';

    protected $fillable = [
        'serial_number', 'name', 'site_id', 'model', 'firmware',
        'last_ip', 'allowed_ips', 'last_seen_at', 'clock_drift', 'clock_checked_at', 'clock_good_at',
        'attlog_stamp', 'operlog_stamp', 'attphoto_stamp', 'device_type', 'is_active', 'note',
    ];

    protected $casts = [
        'last_seen_at'     => 'datetime',
        'clock_checked_at' => 'datetime',
        'clock_good_at'    => 'datetime',
        'is_active'    => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(Punch::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(Command::class);
    }

    /**
     * أحيٌّ هو؟
     *
     * الجهازُ يستعلم عن الأوامر كلَّ `poll_delay` ثانية. فإن مضى ضِعفُ المدّةِ
     * ولم يُسمع له صوتٌ فقد انقطع — إمّا إنترنتُ الموقع أو كهرباؤه أو الجهازُ
     * نفسُه. والمهلةُ ضِعفٌ لا مرّةٌ واحدة كي لا تُطفئه تأخيرةُ شبكةٍ عابرة.
     */
    public function isOnline(): bool
    {
        if (! $this->last_seen_at) {
            return false;
        }

        return $this->last_seen_at->gt(
            Carbon::now('UTC')->subSeconds(2 * (int) config('zk.poll_delay', 30))
        );
    }

    public function lastSeenLabel(): string
    {
        return $this->last_seen_at ? Clock::stamp($this->last_seen_at, 'Y-m-d H:i:s') : 'لم يتصل بعد';
    }

    /**
     * أحداثُ ساعةِ هذا الجهاز.
     *
     * والحدثُ مدّةٌ لا لحظة: يُفتح حين تُقرأ الساعةُ خارج الحدّ ويُغلق حين
     * تعود، وبينهما كلُّ ما وصل من الجهاز مشكوكٌ فيه.
     */
    public function clockEvents(): HasMany
    {
        return $this->hasMany(ClockEvent::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(Operation::class);
    }

    /** الحدثُ المفتوحُ إن كان. */
    public function openClockEvent(): ?ClockEvent
    {
        return $this->clockEvents()->whereNull('closed_at')->latest('opened_at')->first();
    }

    /**
     * قياسُ فرق ساعة الجهاز عن ساعة الخادم.
     *
     * ويُقاس بأحدثِ بصمةٍ في الدفعة: الجهازُ يدفع لحظةَ البصمة (`Realtime=1`)،
     * فالمفروضُ أن تكون لحظتُها هي الآنَ تقريباً.
     *
     * والحكمُ نفسُه ليس هنا. كان هنا سطرين يقيسان ويُنبّهان، فتبيّن أن
     * المسألةَ أكبرُ من سطرين: أكثرُ الانحرافِ أعذارٌ بريئةٌ — متراكمُ
     * انقطاعٍ ودفعةٌ مُعادةٌ وبلّورةٌ رخيصةٌ وبطاريةٌ ماتت وخطأُ منطقةٍ
     * زمنية — فتنبيهٌ بلا تمييزٍ بينها يُنذر كلَّ يومٍ بلا سببٍ فيُهمَل،
     * ويُهمَل معه العبثُ الحقيقيُّ حين يقع.
     *
     * فصار الحكمُ في `ClockJudge` خالصاً يُفحص بالمحاكاة، وجمعُ السياق في
     * `ClockWatch`. وبقيت هذه بابَ توافقٍ لمن كان يناديها.
     *
     * @param  \Illuminate\Support\Carbon  $newest  أحدثُ بصمةٍ في الدفعة (UTC)
     */
    public function watchClock(Carbon $newest, array $batch = []): void
    {
        app(ClockWatch::class)->read($this, $newest, $batch);
    }

    /** أساعةُ الجهاز مشكوكٌ فيها؟ */
    public function clockSuspect(): bool
    {
        return $this->clock_drift !== null
            && abs((int) $this->clock_drift) > ClockJudge::limits()['tolerance'];
    }

    /** وصفُ الفرق بالعربية — واللسانُ واحدٌ في الشاشات كلِّها. */
    public function clockLabel(): string
    {
        return ClockJudge::describe(
            $this->clock_drift === null ? null : (int) $this->clock_drift,
        );
    }

    /**
     * ترميزُ الوقت كما تفهمه أجهزة ZKTeco.
     *
     * صيغةٌ خاصّةٌ بها: تعدّ الشهرَ واحداً وثلاثين يوماً دائماً — لا تعرف
     * الأشهرَ القصيرة ولا الكبيسة. وحسابُها بالتقويم الميلادي يُخرج رقماً
     * آخرَ فتُضبط الساعةُ على يومٍ غير يومها.
     */
    public static function encodeTime(Carbon $t): int
    {
        return ((($t->year - 2000) * 12 * 31)
                + (($t->month - 1) * 31)
                + ($t->day - 1)) * 86400
            + ($t->hour * 3600)
            + ($t->minute * 60)
            + $t->second;
    }

    /**
     * إلحاقُ نبضات الجهاز بموقعه الحالي.
     *
     * موقعُ النبضة نسخةٌ من موقع جهازها تُكتب لحظةَ وصولها — وهي مخالفةٌ
     * لقاعدة «المحسوب لا يُخزَّن»، قُبلت لأن الكشوفَ تُرشّح بالموقع وتُجمّع
     * به، والضمُّ في كل استعلامٍ ثمنٌ يُدفع في كل فتحة.
     *
     * لكنّ ثمنَ النسخة أن تشيخ. فيُدعى هذا كلّما تغيّر موقعُ الجهاز، فلا
     * تبقى بصمةٌ تشير إلى موقعٍ هجره جهازُها — ولا يظهر عاملٌ في كشفٍ ليس
     * كشفَه، ولا يختفي من كشفه.
     *
     * @return int عددُ النبضات التي أُلحقت
     */
    public function syncPunchSites(): int
    {
        return $this->punches()->getQuery()->update(['site_id' => $this->site_id]);
    }

    /**
     * أمسموحٌ لهذا العنوان أن يتكلّم باسم الجهاز؟
     *
     * قائمةٌ فارغة = بلا تقييد، وهذا هو الافتراض لأن أغلب المواقع على عنوانٍ
     * متغيّر. ومن كان له عنوانٌ ثابتٌ فليُقيّده — فبروتوكولُ iclock لا يحمل
     * مصادقةً أصلاً، والرقمُ التسلسليُّ يُقرأ من ظهر الجهاز.
     */
    public function ipAllowed(?string $ip): bool
    {
        $list = array_filter(array_map('trim', explode(',', (string) $this->allowed_ips)));

        return $list === [] || in_array((string) $ip, $list, true);
    }
}
