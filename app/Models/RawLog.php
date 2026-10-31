<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawLog extends Model
{
    protected $table = 'zk_raw_logs';

    public $timestamps = false;

    protected $fillable = [
        'serial_number', 'device_id', 'ip', 'method', 'path',
        'query', 'body', 'response', 'accepted', 'reason', 'rows',
    ];

    protected $casts = [
        'accepted'   => 'boolean',
        'created_at' => 'datetime',
    ];

    public const REASONS = [
        'ok'             => 'مقبول',
        'unknown_serial' => 'رقمٌ تسلسليٌّ غيرُ مسجَّل',
        'inactive'       => 'جهازٌ مُطفأ',
        'ip_blocked'     => 'عنوانٌ غيرُ مسموح',
        'rate_limited'   => 'تجاوزَ حدَّ الطلبات',
        'no_serial'      => 'بلا رقمٍ تسلسلي',
        'bad_rows'       => 'أسطرٌ مشوَّهة',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? (string) $this->reason;
    }
}
