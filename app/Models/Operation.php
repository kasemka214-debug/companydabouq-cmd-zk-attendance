<?php

namespace App\Models;

use App\Services\OperationParser;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * عمليةٌ على الجهاز كما بلّغ بها هو — وفيها اسمُ الفاعل.
 *
 * وهذا هو الشاهدُ الذي يُسمّي: الانحرافُ يقول إن الساعةَ تغيّرت، وهذا يقول
 * **بيد من**. ولا يُغني أحدُهما عن الآخر.
 */
class Operation extends Model
{
    protected $table = 'zk_operations';

    protected $fillable = [
        'device_id', 'op_code', 'operator_pin', 'occurred_at', 'received_at', 'params', 'digest',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** العملياتُ التي تمسّ الوقتَ بذاته. */
    public function scopeTouchingTime(Builder $q): Builder
    {
        return $q->whereIn('op_code', (array) config('zk.oplog_time_codes', [21, 27]));
    }

    public function label(): string
    {
        return OperationParser::label((int) $this->op_code);
    }

    public function touchesTime(): bool
    {
        return OperationParser::touchesTime((int) $this->op_code);
    }

    /**
     * مَن فعلها.
     *
     * والرقمُ يُترجَم باسمِ صاحبه من جدول الربط — فرقمٌ عارٍ لا يُحاسَب به
     * أحد، والمسؤولُ على الجهاز غالباً موظَّفٌ مربوطٌ باسمه أصلاً.
     *
     * ويبقى الرقمُ ظاهراً بجنب الاسم: أرقامُ المسؤولين قد تكون من فضاءٍ
     * آخرَ غيرِ أرقام الحضور في بعض الطُّرُز، فيُرى الرقمُ ليُتحقَّق منه.
     */
    public function operatorName(): ?string
    {
        if ($this->operator_pin === null) {
            return null;
        }

        return Pin::query()
            ->where('device_pin', $this->operator_pin)
            ->when($this->device?->site_id, fn ($q, $id) => $q->where('site_id', $id))
            ->value('employee_name');
    }

    /**
     * فرقُ ما ادّعاه الجهازُ عن وقت وصوله.
     *
     * وهو قياسٌ ثانٍ للساعة من طريقٍ آخر: لا يمرّ بالبصمات أصلاً. فجهازٌ
     * غُيِّرت ساعتُه ولم يبصم عليه أحدٌ بعدها لا يُكشف بالبصمات — ويُكشف
     * بهذا، لأن العمليةَ نفسَها موسومةٌ بالساعة المغيَّرة.
     */
    public function claimDrift(): ?int
    {
        if (! $this->occurred_at || ! $this->received_at) {
            return null;
        }

        return $this->occurred_at->getTimestamp() - $this->received_at->getTimestamp();
    }

    public function stampLabel(): string
    {
        return Clock::stamp($this->received_at, 'Y-m-d H:i:s');
    }

    /** ما ادّعاه الجهاز — يُعرض بجنب الحقيقة ليُرى الفرق. */
    public function claimLabel(): string
    {
        return $this->occurred_at ? Clock::stamp($this->occurred_at, 'Y-m-d H:i:s') : '—';
    }

    /**
     * تخزينُ دفعةٍ من العمليات.
     *
     * `insertOrIgnore` والقيدُ الفريد يبتلعان المعاد — والإعادةُ قاعدة:
     * دفعةٌ لم يصلها إقرارُنا تُعاد كاملةً في الدورة التالية، فبغير القيد
     * صار «غُيِّرت الساعةُ مرّة» ثلاثَ مرّاتٍ في التقرير.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return int عددُ ما قُرئ
     */
    public static function remember(Device $device, array $rows, Carbon $now): int
    {
        if ($rows === []) {
            return 0;
        }

        $records = array_map(fn (array $r): array => [
            'device_id'    => $device->id,
            'op_code'      => $r['op_code'],
            'operator_pin' => $r['operator_pin'],
            'occurred_at'  => $r['occurred_at'],
            'received_at'  => $now,
            'params'       => $r['params'],
            'digest'       => self::digest($r),
            'created_at'   => $now,
            'updated_at'   => $now,
        ], $rows);

        foreach (array_chunk($records, 500) as $chunk) {
            self::query()->insertOrIgnore($chunk);
        }

        return count($rows);
    }

    /**
     * بصمةُ سطرٍ — ما يُميّزه عن غيره ولا يتغيّر بإعادة إرساله.
     *
     * و`received_at` خارجٌ منها عمداً: هو الذي يتغيّر في الإعادة، فلو دخل
     * البصمةَ لصارت كلُّ إعادةٍ سطراً جديداً — وهو ما جاء القيدُ لمنعه.
     *
     * @param  array<string, mixed>  $row
     */
    public static function digest(array $row): string
    {
        return sha1(implode('|', [
            (int) $row['op_code'],
            (string) ($row['operator_pin'] ?? ''),
            $row['occurred_at']?->toDateTimeString() ?? '',
            (string) ($row['params'] ?? ''),
        ]));
    }
}
