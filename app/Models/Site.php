<?php

namespace App\Models;

use App\Support\ShiftJudge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $table = 'zk_sites';

    protected $fillable = ['name', 'code', 'note', 'is_active', 'work_start', 'work_minutes', 'grace_minutes'];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * جدولُ دوام الموقع — ما كتبه الموقعُ، وما تركه فارغاً وَرِثَه من
     * `config/zk.php`.
     *
     * وهو المرجعُ الوحيد: لا يُعاد تركيبُه في متحكّمٍ ولا في قالب. فلو
     * قُرئت الأعمدةُ في موضعين لافترقا يوماً — يُضاف استثناءٌ في أحدهما
     * ويُنسى الآخرُ — فيقول الكشفُ غيرَ ما يقوله التقرير.
     *
     * @return array{start: string, minutes: int, grace: int}
     */
    public function schedule(): array
    {
        return static::resolveSchedule($this->work_start, $this->work_minutes, $this->grace_minutes);
    }

    /** أكتبَ الموقعُ جدولَه بنفسه أم ورثه؟ — تُقرأ في شاشة المواقع. */
    public function hasOwnSchedule(): bool
    {
        return $this->work_start !== null
            || $this->work_minutes !== null
            || $this->grace_minutes !== null;
    }

    /**
     * جداولُ المواقع كلِّها بمفتاح رقم الموقع — استعلامٌ واحدٌ لكشفٍ كامل.
     *
     * فكشفُ الأيام يعرض مئاتِ الصفوف من مواقعَ شتّى، ولو سُئل كلُّ صفٍّ عن
     * جدول موقعه لصار في الصفحة مئةُ استعلام.
     *
     * @return array<int, array{start: string, minutes: int, grace: int}>
     */
    public static function schedules(): array
    {
        return static::query()
            ->get(['id', 'work_start', 'work_minutes', 'grace_minutes'])
            ->mapWithKeys(fn (self $site) => [$site->id => $site->schedule()])
            ->all();
    }

    /** جدولُ من لا جدولَ له — الافتراضُ العامّ. */
    public static function defaultSchedule(): array
    {
        return static::resolveSchedule(null, null, null);
    }

    /**
     * @return array{start: string, minutes: int, grace: int}
     */
    public static function resolveSchedule($start, $minutes, $grace): array
    {
        /* والحسابُ في `ShiftJudge` لا هنا: هناك يُحاكى بلا قاعدةِ بياناتٍ ولا لارافيل */
        return ShiftJudge::schedule($start, $minutes, $grace);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function pins(): HasMany
    {
        return $this->hasMany(Pin::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(Punch::class);
    }
}
