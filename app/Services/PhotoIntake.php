<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Punch;
use App\Models\PunchPhoto;
use App\Support\Clock;

/**
 * التقاطُ صورةِ بصمةٍ من أيّ طلبٍ يحملها.
 *
 * وموضعُها خدمةٌ مستقلّة لا متحكّمٌ واحد، لأن الصورة **لا تصل من بابٍ
 * واحد**: تأتي في `table=ATTPHOTO` في طرازٍ، وفي جدولٍ آخرَ في غيره، وفي
 * مسارٍ لم نعرفه بعدُ في ثالث. وقد كلّفنا ظنُّ الأبواب معروفةً أياماً في
 * `registry`.
 *
 * فالقاعدةُ صارت: كلُّ طلبٍ يُجرَّب عليه القارئ، فإن أخرج صورةً خُزّنت،
 * وإلا مضى الطلبُ في سبيله. والقارئُ متشدّدٌ فلا يختلق: لا يقبل إلا ما
 * فُكّ ترميزُه وثبت أنه صورةٌ وله رقمٌ صالح — فطلبٌ عاديٌّ لا يُنتج شيئاً.
 */
class PhotoIntake
{
    public function __construct(private PhotoParser $parser) {}

    /**
     * @param  array<string, mixed>  $query
     * @return bool أخُزّنت صورة؟
     */
    public function capture(Device $device, string $payload, array $query = []): bool
    {
        if (! config('zk.photos') || $payload === '') {
            return false;
        }

        /*
         * فحصٌ رخيصٌ قبل الفحص الغالي: لا يُفكَّك جسدٌ لا أثرَ فيه لصورة.
         * فأكثرُ الطلبات استعلاماتٌ فارغةٌ وسجلّاتُ حضور، ولا يجوز أن
         * يدفع كلُّ واحدٍ منها ثمنَ تفكيكٍ لا يُخرج شيئاً.
         */
        if (! $this->smells($payload)) {
            return false;
        }

        $read = $this->parser->parse($payload, $query);

        if ($read === null) {
            return false;
        }

        /*
         * النوعُ من البايتات لا من التصريح. وما ليس صورةً لا يُخزَّن —
         * فالعرضُ inline لمحتوًى مجهولٍ ثغرةٌ لا خطأُ عرض.
         */
        $mime = PunchPhoto::sniff($read['bytes']);

        if ($mime === null) {
            logger()->warning('محتوًى غيرُ صورةٍ في طلبِ صورة — رُفض', [
                'serial' => $device->serial_number,
                'size'   => strlen($read['bytes']),
            ]);

            return false;
        }

        $taken = $read['taken_at'];

        /*
         * أقربُ نبضةٍ لصاحب الرقم في دقيقةٍ حولها. والصورةُ قد تسبق
         * النبضةَ أو تتأخّر عنها بثوانٍ — الجهازُ يرسلهما في طلبين.
         */
        $punchId = Punch::query()
            ->where('device_id', $device->id)
            ->where('device_pin', $read['pin'])
            ->whereBetween('punched_at', [
                $taken->copy()->subSeconds(60),
                $taken->copy()->addSeconds(60),
            ])
            ->orderByRaw('ABS(TIMESTAMPDIFF(SECOND, punched_at, ?))', [$taken->toDateTimeString()])
            ->value('id');

        PunchPhoto::updateOrCreate(
            [
                'device_id'  => $device->id,
                'device_pin' => $read['pin'],
                'taken_at'   => $taken,
            ],
            [
                'site_id'   => $device->site_id,
                'punch_id'  => $punchId,
                'work_date' => Clock::workDate($taken),
                'mime'      => $mime,
                'size'      => strlen($read['bytes']),
                'content'   => base64_encode($read['bytes']),
            ],
        );

        return true;
    }

    /**
     * أفي هذا الجسد رائحةُ صورة؟
     *
     * إمّا حقلٌ مُرمَّزٌ باسمٍ معروف، وإمّا بايتاتُ صورةٍ في أوّله.
     */
    private function smells(string $payload): bool
    {
        if (PunchPhoto::sniff($payload) !== null) {
            return true;
        }

        return (bool) preg_match('/\b(photo|image|content|data)=[A-Za-z0-9+\/]{100,}/i', $payload);
    }
}
