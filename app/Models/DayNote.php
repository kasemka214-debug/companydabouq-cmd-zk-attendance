<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قرارُ يومٍ رُصد فيه تأخّرٌ أو نقصٌ: أبإذنٍ كان أم بغيره، وما سببُه.
 *
 * والرصدُ يبقى محسوباً عند العرض (`ShiftJudge`) — لا يُخزَّن منه رقم. وهذا
 * الجدولُ لا يحفظ الحكمَ بل **الجوابَ عنه**: ما لا يعرفه الحسابُ ولا يأتي
 * من جهاز.
 */
class DayNote extends Model
{
    protected $table = 'zk_day_notes';

    protected $fillable = ['site_id', 'device_pin', 'work_date', 'permitted', 'reason', 'note', 'decided_by'];

    protected $casts = ['permitted' => 'boolean'];

    /*
     * **ولا صبَّ على `work_date`.** فالصبُّ إلى Carbon يجعل الدمجَ النصّيَّ
     * يُخرج «2026-11-07 00:00:00» فلا يُطابق مفتاحَ صفّ اليوم صيغتُه
     * `Y-m-d` — وعطبُه صامتٌ: ملاحظةٌ مكتوبةٌ لا تُقرأ، ورجلٌ مستأذنٌ يبقى
     * أحمرَ في الكشف. وقد وقع مثلُه في الإجازات (`PinLeave`).
     */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeForRange(Builder $q, string $from, string $to): Builder
    {
        return $q->whereBetween('work_date', [$from, $to]);
    }

    /** مفتاحُ الالتصاق بصفّ اليوم في `DayBuilder`: موقعٌ ورقمٌ ويوم. */
    public static function key(?int $siteId, string $pin, string $date): string
    {
        return $siteId.'|'.$pin.'|'.$date;
    }

    /**
     * ملاحظاتُ مدّةٍ كلِّها باستعلامٍ واحد، مفهرسةً بمفتاح اليوم.
     *
     * فالكشفُ يعرض مئاتِ الصفوف، ولو سُئلت القاعدةُ عن كلّ صفٍّ على حِدة
     * لصار في الصفحة مئةُ استعلام.
     *
     * @return array<string, static>
     */
    public static function lookup(string $from, string $to, ?int $siteId = null): array
    {
        return static::query()
            ->forRange($from, $to)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->get()
            ->keyBy(fn (self $n) => self::key($n->site_id, $n->device_pin, (string) $n->work_date))
            ->all();
    }

    public function statusLabel(): string
    {
        return $this->permitted
            ? 'بإذن'.($this->reason ? ' — '.$this->reason : '')
            : 'بلا إذن';
    }
}
