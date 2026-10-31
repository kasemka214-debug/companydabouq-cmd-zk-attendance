<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Command extends Model
{
    protected $table = 'zk_commands';

    protected $fillable = [
        'device_id', 'kind', 'body', 'label',
        'status', 'sent_at', 'replied_at', 'return_code', 'created_by',
    ];

    protected $casts = [
        'sent_at'    => 'datetime',
        'replied_at' => 'datetime',
    ];

    /** الأوامرُ المتاحة: الرمزُ => الوصفُ في الشاشة */
    public const KINDS = [
        /*
         * جلبُ الأسماء: الجهازُ يحمل اسمَ كلِّ موظفٍ مع رقمه، فلا حاجةَ
         * إلى إدخالها بيدٍ إن كانت مُدخَلةً فيه. ويُرسلها فتُربط بالأرقام
         * تلقائياً.
         */
        'query_users' => 'جلبُ أسماء الموظفين من الجهاز',

        /*
         * و`CHECK` أوسعُ منه: يأمر الجهازَ أن يُعيد رفعَ بياناته كلِّها —
         * مستخدمين وسجلَّ حضورٍ ومعلوماتِ جهاز. فإن لم يستجب الأولُ فهذا
         * بديلُه، وثمنُه دفعةٌ أثقل.
         */
        'check'       => 'مزامنةٌ شاملة (كلُّ البيانات)',

        'set_time'    => 'ضبطُ ساعة الجهاز على ساعة الخادم',
        'info'        => 'طلبُ معلومات الجهاز',
        'reboot'      => 'إعادةُ تشغيل الجهاز',
        'clear_log'   => 'مسحُ سجلّ الحضور من الجهاز',
    ];

    /**
     * نصُّ الأمر بلغة هذا الجهاز بعينه.
     *
     * فأوامرُ البيانات لها لغتان. أجهزةُ الحضور (`att`) تفهم اسمَ الجدول
     * كلمةً واحدة (`USERINFO`)، وأجهزةُ التحكّم (`acc`) تفهم وصفاً
     * مفصّلاً (`tablename=user,fielddesc=*,filter=*`). وكلٌّ يردّ «فشل»
     * على لغة الآخر.
     *
     * وكان الأمرُ نصّاً ثابتاً في الجدول فوقُ، فنجح مع UA300 وفشل مع
     * SPEEDFACE مرّتين — وهو ما دلّ على الفرق.
     *
     * والأوامرُ العامّة (`CHECK`، `INFO`، `REBOOT`) واحدةٌ في اللغتين،
     * وقد نجحت على الجهازين معاً.
     */
    public static function bodyFor(string $kind, ?Device $device = null): string
    {
        $isAccess = strtolower((string) $device?->device_type) === 'acc';

        return match ($kind) {
            'query_users' => $isAccess
                ? 'DATA QUERY tablename=user,fielddesc=*,filter=*'
                : 'DATA QUERY USERINFO',

            'clear_log'   => $isAccess
                ? 'CLEAR DATA tablename=transaction'
                : 'CLEAR LOG',

            /*
             * ضبطُ الساعة — علاجُ العبث لا كشفُه فحسب. والوقتُ يُرسل
             * بتوقيت الجهاز المحلّي لا بغرينتش: الجهازُ يعرض ما يُعطى
             * ولا يحوّل، فإرسالُ غرينتش يُقدّم ساعتَه ثلاثاً في الكويت.
             */
            'set_time'    => 'SET OPTION DateTime='.Device::encodeTime(\App\Support\Clock::now()),

            'check'       => 'CHECK',
            'info'        => 'INFO',
            'reboot'      => 'REBOOT',

            default       => throw new \InvalidArgumentException("أمرٌ غيرُ معروف: {$kind}"),
        };
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'بانتظار الجهاز',
            'sent'    => 'أُرسل — بانتظار الإقرار',
            'done'    => 'تمّ',
            'failed'  => 'فشل',
            default   => $this->status,
        };
    }
}
