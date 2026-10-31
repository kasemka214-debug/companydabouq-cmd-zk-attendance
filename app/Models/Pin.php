<?php

namespace App\Models;

use App\Support\Employment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pin extends Model
{
    protected $table = 'zk_pins';

    protected $fillable = [
        'site_id', 'device_pin', 'employee_name', 'employee_ref',
        'job_title', 'is_active', 'note', 'left_on', 'left_reason',
    ];

    /*
     * `is_active` وحدَه مصبوب. و`left_on` يبقى نصّاً عمداً: الصبُّ إلى
     * Carbon يُخرج «2026-10-15 00:00:00» من الدمج النصّيّ فلا يُطابق
     * مفتاحَ تقويمٍ أبداً — عطبٌ صامتٌ يُسكت القرارَ المكتوب.
     */
    protected $casts = ['is_active' => 'boolean'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(PinLeave::class, 'pin_id');
    }

    /** أتركَ العملَ قبل هذا اليوم؟ — وبلا نظرٍ إلى إخوته في الهويّة */
    public function leftBefore(string $date): bool
    {
        $on = trim((string) $this->left_on);

        return $on !== '' && $on < $date;
    }

    public function leftLabel(): string
    {
        return Employment::reason($this->left_reason);
    }

    /**
     * من لم يترك العملَ قبل بداية المدّة.
     *
     * ويُستعمل في التقارير الأسبوعية والشهرية: من تركَ العملَ في يونيو لا
     * شأنَ له بكشف أكتوبر، وبقاؤه فيه صفّاً فارغاً يُطيل الورقةَ بأسماء
     * ليست من الشركة. **ومن تركَ في وسط المدّة يبقى** — فأيامُه الأولى
     * عملٌ صُرف عليه أجرُه.
     */
    public function scopeServingOn(Builder $q, string $from): Builder
    {
        return $q->where(function (Builder $w) use ($from) {
            $w->whereNull('left_on')->orWhere('left_on', '>=', $from);
        });
    }

    /**
     * ربطُ الأرقام بالأسماء الواصلة من جهاز.
     *
     * **يُضيف الناقصَ ولا يمسّ الموجود.** وهذه القاعدةُ مقصودة: مَن صحّح
     * اسماً في البرنامج لا يجوز أن يعود الجهازُ فيكتب فوقه خطأه القديم —
     * فيُصحَّح الاسمُ اليوم ويرتدّ غداً بلا أن يدري أحد. فالجهازُ يملأ
     * الفراغَ، والإنسانُ يملك التصحيح.
     *
     * وموضعُها النموذجُ لا المتحكّم: الأسماءُ تصل من ثلاثة أبوابٍ — سجلُّ
     * العمليات، وجدولُ المستخدمين، وجسدُ إقرارِ الأمر — ولو كُتبت القاعدةُ
     * في كلٍّ منها لافترقت يوماً ولا يُدرى.
     *
     * @param  array<int, array{device_pin: string, name: string, card: string|null}>  $users
     * @return int عددُ ما أُضيف
     */
    public static function importFromDevice(Device $device, array $users): int
    {
        if ($users === []) {
            return 0;
        }

        /*
         * بلا موقعٍ لا ربط: الرقمُ يُفرَّق بموقعه لا بذاته — «١» في موقعٍ
         * غيرُ «١» في آخر. فجهازٌ لم يُنسب إلى موقعٍ بعدُ تُترك أسماؤه
         * حتى يُنسب، ولا تُخزَّن في فراغٍ يُخلط بغيره.
         */
        if (! $device->site_id) {
            logger()->warning('أسماءٌ من جهازٍ بلا موقع — تُركت', [
                'serial' => $device->serial_number,
                'count'  => count($users),
            ]);

            return 0;
        }

        $added = 0;

        foreach ($users as $u) {
            $pin = static::firstOrNew([
                'site_id'    => $device->site_id,
                'device_pin' => $u['device_pin'],
            ]);

            if ($pin->exists) {
                continue;
            }

            $pin->fill([
                'employee_name' => $u['name'],
                'is_active'     => true,
                'note'          => 'وصل الاسمُ من الجهاز '.$device->name,
            ])->save();

            $added++;
        }

        return $added;
    }

    /**
     * خريطةُ (موقع ← رقم) إلى الاسم، لموقعٍ واحدٍ أو للكلّ.
     *
     * تُقرأ مرّةً ثم تُستعمل في تسمية آلاف النبضات. والبديلُ — علاقةٌ على
     * كل نبضة — يسأل القاعدةَ سؤالاً لكل سطرٍ في الكشف.
     */
    public static function lookup(?int $siteId = null): array
    {
        return static::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->get(['site_id', 'device_pin', 'employee_name', 'employee_ref', 'job_title'])
            ->keyBy(fn ($p) => $p->site_id.'|'.$p->device_pin)
            ->all();
    }
}
