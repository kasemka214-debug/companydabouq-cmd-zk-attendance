<?php

namespace App\Models;

use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Punch extends Model
{
    protected $table = 'zk_punches';

    public $timestamps = false;

    protected $fillable = [
        'device_id', 'site_id', 'device_pin', 'punched_at', 'work_date',
        'state', 'verify_mode', 'work_code', 'received_at',
    ];

    protected $casts = [
        'punched_at'  => 'datetime',
        'received_at' => 'datetime',
        'work_date'   => 'date',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * نبضاتُ مدّةٍ — نطاقُ تواريخَ على `work_date` لا دوالُّ على العمود.
     * تغليفُ العمود بدالّة يُعطّل فهرسَه ويُجبر MySQL على مسح جدولٍ ينمو
     * بمعدّل (عددُ العمال × عددُ البصمات في اليوم) كلَّ يوم.
     */
    public function scopeBetween(Builder $q, string $from, string $to): Builder
    {
        return $q->whereBetween('work_date', [$from, $to]);
    }

    /**
     * دخولٌ أم خروج؟
     *
     * يُقرأ من ترتيب البصمة في يوم صاحبها حين يُحسب (`seq`): الأولى دخولٌ
     * والثانية خروجٌ والثالثة دخول، وهكذا.
     *
     * ولا يُؤخذ من الجهاز — فنوعُه يعتمد على زرٍّ يضغطه العاملُ قبل أن يضع
     * إصبعه، وأكثرُهم لا يضغطه، فتأتي بصماتُ اليوم كلُّها موسومةً «دخول».
     * والزمنُ أصدقُ من الزرّ.
     *
     * وحيث لم يُحسب الترتيبُ يُرجَع إلى ما قاله الجهاز — فسطرٌ بوسمٍ
     * ضعيفٍ خيرٌ من سطرٍ بلا وسم.
     */
    public function stateLabel(): string
    {
        $seq = $this->seq ?? null;

        if ($seq !== null) {
            return ((int) $seq % 2 === 1) ? 'دخول' : 'خروج';
        }

        return config('zk.punch_states')[$this->state] ?? "نوع {$this->state}";
    }

    public function verifyLabel(): string
    {
        return config('zk.verify_modes')[$this->verify_mode] ?? "تحقّق {$this->verify_mode}";
    }

    /** اللحظةُ بتوقيت الكويت. */
    public function localTime(string $format = 'H:i:s'): string
    {
        return Clock::stamp($this->punched_at, $format);
    }
}
