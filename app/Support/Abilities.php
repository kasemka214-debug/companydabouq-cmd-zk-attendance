<?php

namespace App\Support;

/**
 * مفاتيحُ الصلاحيات — مفتاحٌ لكل فعل.
 *
 * ولا يُجمع فعلان تحت مفتاح. الفرقُ بين «يرى الأجهزة» و«يحذف جهازاً» ليس
 * فرقَ درجة: حذفُ جهازٍ يجرّ معه نبضاتِه كلَّها بالمفتاح الأجنبي، فمن مَلَك
 * التعديلَ لا يلزم أن يملك ذلك.
 *
 * وما يُعرض في القائمة يجب أن يُفتح: كلُّ بابٍ في Menu له مفتاحٌ هنا،
 * والمتحكّمُ يحرسه بالمفتاح نفسِه.
 */
class Abilities
{
    /** المفتاح => وصفُه في شاشة المستخدمين */
    public const ALL = [
        'dashboard.view'  => 'عرض اللوحة',

        'sites.view'      => 'عرض المواقع',
        'sites.create'    => 'إضافة موقع',
        'sites.edit'      => 'تعديل موقع',
        'sites.delete'    => 'حذف موقع',

        'devices.view'    => 'عرض الأجهزة',
        'devices.create'  => 'إضافة جهاز',
        'devices.edit'    => 'تعديل جهاز',
        'devices.delete'  => 'حذف جهاز',
        'devices.command' => 'إرسال أمرٍ إلى جهاز',

        'employees.view'  => 'عرض شاشة الموظفين',
        'employees.leave' => 'تسجيلُ إجازةٍ وحذفُها',
        'employees.end'   => 'تسجيلُ نهاية الخدمة وإلغاؤها',
        'employees.move'  => 'نقلُ موظفٍ بين المواقع',

        'pins.view'       => 'عرض ربط الأرقام بالأسماء',
        'pins.create'     => 'إضافة ربط',
        'pins.edit'       => 'تعديل ربط',
        'pins.delete'     => 'حذف ربط',

        'punches.view'    => 'عرض النبضات',
        'punches.delete'  => 'حذف نبضة',

        'photos.view'     => 'عرض صور البصمات',
        'photos.delete'   => 'حذف صورة',

        'days.view'       => 'عرض كشف الأيام',
        'reports.view'    => 'عرض التقارير الأسبوعية والشهرية',
        'holidays.manage' => 'اختيار عطل الشهر',

        'clock.view'      => 'عرض تقرير ساعات الأجهزة',
        'clock.ack'       => 'الإقرارُ بحدث ساعة',

        'raw.view'        => 'عرض سجلّ الاتصالات الخام',

        'users.manage'    => 'إدارة المستخدمين',

        /*
         * التصديرُ إخراجٌ كالطباعة — مفتاحٌ واحدٌ للبابين. مَن مُنع من طباعة
         * كشفٍ ثم نزّله إكسل لم يُمنع من شيء.
         */
        'page.print'      => 'الطباعة والتصدير',
    ];

    /** المجموعاتُ كما تُعرض مرتَّبةً في شاشة المستخدمين. */
    public const GROUPS = [
        'اللوحة'      => ['dashboard.view'],
        'المواقع'     => ['sites.view', 'sites.create', 'sites.edit', 'sites.delete'],
        'الأجهزة'     => ['devices.view', 'devices.create', 'devices.edit', 'devices.delete', 'devices.command'],
        'الموظفون'    => ['employees.view', 'employees.leave', 'employees.end', 'employees.move'],
        'ربط الأرقام' => ['pins.view', 'pins.create', 'pins.edit', 'pins.delete'],
        'النبضات'     => ['punches.view', 'punches.delete'],
        'الصور'       => ['photos.view', 'photos.delete'],
        'الكشوف'      => ['days.view', 'reports.view', 'page.print'],
        'العطل'       => ['holidays.manage'],
        'الساعة'      => ['clock.view', 'clock.ack'],
        'التشخيص'     => ['raw.view'],
        'النظام'      => ['users.manage'],
    ];

    /**
     * حِزَمٌ جاهزة — تُطبَّق بضغطة ثم تُعدَّل.
     *
     * فالمفاتيحُ بلغت الثلاثين، وتأشيرُها واحداً واحداً لكل مستخدمٍ عملُ
     * يدٍ يُخطئ: يُنسى مفتاحٌ فيُفتح بابٌ لا يُفتح، أو يُزاد فيُفتح ما لا
     * يُراد. والحِزَمُ تُقرّب، والخاناتُ تبقى ظاهرةً فيُدقَّق فيها.
     *
     * **ولا تُخزَّن الحزمةُ على المستخدم** — إنما تُؤشَّر خاناتُها ثم
     * يُحفظ ما أُشِّر. فلو خُزّنت لصارت رُتبةً تُغيَّر من مكانٍ واحدٍ
     * فتنقلب صلاحياتُ عشرةٍ بلا أن يُدرى — وقاعدةُ هذا البرنامج أن
     * الصلاحيةَ لكل فعلٍ صريحةً على صاحبها.
     */
    public const PACKS = [
        'مراجعٌ يقرأ ولا يُغيّر' => self::VIEWER,

        'مسؤولُ حضور' => [
            'dashboard.view', 'days.view', 'reports.view', 'page.print',
            'punches.view', 'photos.view', 'sites.view',
            'employees.view', 'employees.leave', 'employees.end',
            'pins.view', 'pins.create', 'pins.edit',
            'holidays.manage',
        ],

        'فنّيُّ أجهزة' => [
            'dashboard.view', 'devices.view', 'devices.edit', 'devices.command',
            'sites.view', 'clock.view', 'clock.ack', 'raw.view',
            'punches.view', 'photos.view',
        ],

        'محاسبٌ يقرأ الكشوف' => [
            'dashboard.view', 'days.view', 'reports.view', 'page.print',
            'employees.view', 'sites.view', 'pins.view',
        ],
    ];

    /** الحزمةُ الافتراضية لمن يُراجع ولا يُغيّر. */
    public const VIEWER = [
        'dashboard.view', 'sites.view', 'devices.view',
        'pins.view', 'punches.view', 'photos.view', 'days.view', 'reports.view', 'page.print',
        'clock.view',
    ];

    public static function label(string $key): string
    {
        return self::ALL[$key] ?? $key;
    }
}
