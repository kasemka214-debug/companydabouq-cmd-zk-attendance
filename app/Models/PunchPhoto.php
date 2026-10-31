<?php

namespace App\Models;

use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PunchPhoto extends Model
{
    protected $table = 'zk_punch_photos';

    public $timestamps = false;

    protected $fillable = [
        'device_id', 'site_id', 'punch_id', 'device_pin',
        'taken_at', 'work_date', 'mime', 'size', 'content', 'received_at',
    ];

    protected $casts = [
        'taken_at'    => 'datetime',
        'received_at' => 'datetime',
        'work_date'   => 'date',
    ];

    /**
     * الأنواعُ التي تُعرض inline.
     *
     * قائمةٌ مغلقة لا مفتوحة: ما ليس فيها لا يُخزَّن أصلاً. فعرضُ محتوًى
     * مجهولٍ في المتصفّح بلا ترويسةٍ صحيحة ثغرةٌ لا خطأُ عرض — وقد سدّ
     * هذا في البرنامج الأمّ ثغرةَ XSS مخزَّنةً حقيقية.
     */
    public const SAFE = [
        "\xFF\xD8\xFF"                 => 'image/jpeg',
        "\x89PNG\r\n\x1A\n"            => 'image/png',
    ];

    /**
     * نوعُ الصورة من بايتاتها الأولى — لا من تصريح الجهاز.
     *
     * والجهازُ لا يُصرّح أصلاً في أكثر الإصدارات، فلا بديلَ عن القراءة.
     * وما لم يُعرف فليس صورة.
     */
    public static function sniff(string $bytes): ?string
    {
        foreach (self::SAFE as $magic => $mime) {
            if (str_starts_with($bytes, $magic)) {
                return $mime;
            }
        }

        return null;
    }

    /** الأعمدةُ التي تُنتقى في القوائم — كلُّها إلا المحتوى. */
    public const LIGHT = [
        'id', 'device_id', 'site_id', 'punch_id', 'device_pin',
        'taken_at', 'work_date', 'mime', 'size', 'received_at',
    ];

    /** قائمةٌ بلا محتوًى — وإلا جرّ كلُّ كشفٍ ميجابايتاتٍ لا يعرضها. */
    public function scopeLight(Builder $q): Builder
    {
        return $q->select(self::LIGHT);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function punch(): BelongsTo
    {
        return $this->belongsTo(Punch::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** اللحظةُ بتوقيت الكويت. */
    public function localTime(string $format = 'H:i:s'): string
    {
        return Clock::stamp($this->taken_at, $format);
    }

    /** الحجمُ مقروءاً. */
    public function sizeLabel(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1).' م.ب'
            : number_format($this->size / 1024).' ك.ب';
    }
}
