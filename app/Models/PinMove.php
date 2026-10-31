<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نقلةٌ وقعت: رجلٌ تركَ رقمَه في موقعٍ وصار له رقمٌ في آخر.
 *
 * والحدثُ وحدَه يُخزَّن، والمُدَدُ تُحسب منه عند القراءة
 * (`Employment::serves`) — فالمحسوبُ لا يُخزَّن، وحذفُ النقلة يردّ المُدَدَ
 * إلى ما كانت عليه بلا تصحيحٍ يدويّ.
 */
class PinMove extends Model
{
    protected $table = 'zk_pin_moves';

    protected $fillable = ['from_pin_id', 'to_pin_id', 'moved_on', 'note', 'created_by'];

    /*
     * ولا `casts` على `moved_on`: الصبُّ إلى Carbon يُخرج
     * «2026-10-20 00:00:00» من الدمج النصّيّ فلا يُطابق مفتاحَ تقويمٍ
     * أبداً. وعطبُه صامتٌ — نقلةٌ مسجَّلةٌ لا تُقرأ.
     */

    public function fromPin(): BelongsTo
    {
        return $this->belongsTo(Pin::class, 'from_pin_id');
    }

    public function toPin(): BelongsTo
    {
        return $this->belongsTo(Pin::class, 'to_pin_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
