<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * أيامُ العطل — ما يختاره المسؤولُ منها.
 *
 * والجمعةُ ليست منها: تُحسب من التاريخ، فلا تُخزَّن. وهي عطلةُ الكويت
 * الثابتة، ولو خُزّنت لصارت اثنتين وخمسين صفّاً في السنة يُنسى منها واحدٌ
 * فيختلّ شهرٌ بلا سببٍ ظاهر.
 */
class Holiday extends Model
{
    protected $table = 'zk_holidays';

    protected $fillable = ['date', 'note', 'created_by'];

    protected $casts = ['date' => 'date'];

    /** أهو يومُ الجمعة؟ */
    public static function isWeekend(Carbon $date): bool
    {
        return $date->dayOfWeek === Carbon::FRIDAY;
    }

    /**
     * أيامُ العطل المختارة في مدّةٍ — مجموعةَ تواريخَ نصّية.
     *
     * @return array<int, string>
     */
    public static function chosenBetween(string $from, string $to): array
    {
        return static::query()
            ->whereBetween('date', [$from, $to])
            ->pluck('date')
            ->map(fn ($d) => $d->toDateString())
            ->all();
    }

    /**
     * أيامُ الدوام في مدّة — ما ليس جمعةً ولا عطلةً مختارة.
     *
     * وعليها يُبنى المطلوبُ من الساعات، فهي أصلُ الحساب كلِّه: يومٌ يُعدّ
     * خطأً يُغيّر المطلوبَ ثماني ساعات، والفرقُ يظهر على كلِّ موظفٍ في
     * الكشف.
     *
     * @return array{dates: array<int, string>, working: int, weekend: int, holiday: int}
     */
    public static function calendar(string $from, string $to): array
    {
        $chosen  = static::chosenBetween($from, $to);
        $dates   = [];
        $weekend = 0;
        $holiday = 0;
        $working = 0;

        $cursor = Carbon::parse($from);
        $end    = Carbon::parse($to);

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();

            if (static::isWeekend($cursor)) {
                $kind = 'weekend';
                $weekend++;
            } elseif (in_array($key, $chosen, true)) {
                $kind = 'holiday';
                $holiday++;
            } else {
                $kind = 'working';
                $working++;
            }

            $dates[$key] = $kind;
            $cursor      = $cursor->addDay();
        }

        return [
            'dates'   => $dates,
            'working' => $working,
            'weekend' => $weekend,
            'holiday' => $holiday,
        ];
    }
}
