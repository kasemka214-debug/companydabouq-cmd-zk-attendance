<?php

namespace App\Models;

use App\Support\Employment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إجازةٌ مسجَّلة — مدّةٌ لا يُسأل فيها صاحبُها عن غياب.
 *
 * وتُكتب على صفّ ربطٍ واحد وتسري على إخوته بالهويّة عند القراءة: فالرجلُ
 * قد يكون في جدول الربط صفّين، ولو لزم تسجيلُها على كلٍّ منهما لنُسي
 * أحدُهما يوماً فخرج الرجلُ «لم يبصم» بالأحمر وهو في إجازةٍ موقَّعة.
 */
class PinLeave extends Model
{
    protected $table = 'zk_pin_leaves';

    protected $fillable = ['pin_id', 'from_date', 'to_date', 'kind', 'note', 'created_by'];

    /*
     * **ولا `casts` على التواريخ.**
     *
     * فالصبُّ إلى Carbon يجعل الدمجَ النصّيَّ يُخرج «2026-10-15 00:00:00»،
     * فلا يُطابق مفتاحَ تقويمٍ صيغتُه `Y-m-d` أبداً. وعطبُه صامتٌ: لا
     * استثناءَ ولا سطرَ خطأ — إنما إجازةٌ مسجَّلةٌ لا تُقرأ، ورجلٌ في
     * إجازةٍ يخرج أحمر. وقد وقع مثلُه في `AbsenceFinder::day()`.
     */

    public function pin(): BelongsTo
    {
        return $this->belongsTo(Pin::class, 'pin_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** إجازاتٌ تتقاطع مع مدّة — لا التي تقع داخلها وحدَها */
    public function scopeOverlapping(Builder $q, string $from, string $to): Builder
    {
        return $q->where('from_date', '<=', $to)->where('to_date', '>=', $from);
    }

    public function kindLabel(): string
    {
        return Employment::kind($this->kind);
    }

    public function spanLabel(): string
    {
        return Employment::spanLabel((string) $this->from_date, (string) $this->to_date);
    }

    /**
     * الحارسُ الحقيقيُّ على صحّة المدّة.
     *
     * وهو في النموذج لا في المتحكّم وحدَه: `PinController::import` يُثبت
     * أن مسارَ كتابةٍ يتجاوز تحقّقَ المتحكّم أمرٌ واقعٌ في هذا البرنامج لا
     * احتمالٌ نظريّ. وتحقّقُ المتحكّم يبقى ليُرى الخطأُ في الشاشة.
     */
    protected static function booted(): void
    {
        static::saving(function (self $leave): void {
            if (! Employment::spanValid($leave->from_date, $leave->to_date)) {
                throw new \InvalidArgumentException(
                    'مدّةُ الإجازة غيرُ صحيحة: «إلى» يجب ألّا تسبق «من».'
                );
            }
        });
    }
}
