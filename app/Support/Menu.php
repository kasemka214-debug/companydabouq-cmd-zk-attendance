<?php

namespace App\Support;

/**
 * تعريفُ القائمة — البابُ ومفتاحُه في مكانٍ واحد.
 *
 * وهذا شرطُ الاتّساق: القالبُ يقرأ المفتاحَ من هنا ليُظهر البابَ أو يُخفيه،
 * والمتحكّمُ يحرس نفسَه بالمفتاح نفسِه. فلا يقع بابٌ يُرى ولا يُفتح —
 * وهو أسوأُ من بابٍ لا يُرى، لأن الأولَ يَعِد ثم يُخلف.
 *
 * **والتقسيمُ هنا لا في القالب.** فالأبوابُ بلغت ثلاثةَ عشرَ باباً، وشريطٌ
 * أفقيٌّ يحملها يلتفّ سطرين على شاشةٍ عادية فيصير البحثُ عن بابٍ مسحاً
 * بالعين. والقالبُ يرسم ما يُملى عليه، فلو كُتب التقسيمُ فيه لافترق عن
 * المفاتيح يوماً ولا يُدرى.
 */
class Menu
{
    /** الأقسامُ مرتَّبةً كما تُعرض — ومفتاحُها يُطابق `group` في الأبواب */
    public const SECTIONS = [
        'main'    => null,            // بلا عنوان — بابٌ واحدٌ فوق الكلّ
        'reports' => 'الكشوف',
        'records' => 'السجلّات',
        'people'  => 'الناس والمواقع',
        'watch'   => 'الرقابة',
        'system'  => 'النظام',
    ];

    /** @return array<int, array{route: string, label: string, ability: string, icon: string, group: string}> */
    public static function definition(): array
    {
        return [
            ['route' => 'dashboard', 'label' => 'اللوحة', 'ability' => 'dashboard.view', 'icon' => 'speedometer2', 'group' => 'main'],

            ['route' => 'days.index',       'label' => 'كشف الأيام', 'ability' => 'days.view',    'icon' => 'calendar3',         'group' => 'reports'],
            ['route' => 'reports.weekly',   'label' => 'الأسبوعي',   'ability' => 'reports.view', 'icon' => 'calendar-week',     'group' => 'reports'],
            ['route' => 'reports.monthly',  'label' => 'الشهري',     'ability' => 'reports.view', 'icon' => 'calendar-month',    'group' => 'reports'],
            ['route' => 'reports.employee', 'label' => 'تقرير موظف', 'ability' => 'reports.view', 'icon' => 'person-lines-fill', 'group' => 'reports'],

            ['route' => 'punches.index', 'label' => 'النبضات', 'ability' => 'punches.view', 'icon' => 'fingerprint', 'group' => 'records'],
            ['route' => 'photos.index',  'label' => 'الصور',   'ability' => 'photos.view',  'icon' => 'camera',      'group' => 'records'],

            ['route' => 'employees.index', 'label' => 'الموظفون',   'ability' => 'employees.view', 'icon' => 'person-vcard', 'group' => 'people'],
            ['route' => 'pins.index',    'label' => 'ربط الأرقام', 'ability' => 'pins.view',    'icon' => 'person-badge', 'group' => 'people'],
            ['route' => 'devices.index', 'label' => 'الأجهزة',     'ability' => 'devices.view', 'icon' => 'hdd-network',  'group' => 'people'],
            ['route' => 'sites.index',   'label' => 'المواقع',     'ability' => 'sites.view',   'icon' => 'geo-alt',      'group' => 'people'],

            ['route' => 'clock.index', 'label' => 'ساعاتُ الأجهزة', 'ability' => 'clock.view', 'icon' => 'clock-history', 'group' => 'watch'],
            ['route' => 'raw.index',   'label' => 'سجلّ الاتصالات', 'ability' => 'raw.view',   'icon' => 'activity',      'group' => 'watch'],

            ['route' => 'users.index', 'label' => 'المستخدمون', 'ability' => 'users.manage', 'icon' => 'people', 'group' => 'system'],
        ];
    }

    /**
     * الأبوابُ مقسَّمةً، وليس فيها إلا ما يملك المستخدمُ مفتاحَه.
     *
     * **والقسمُ الذي فرغت أبوابُه يسقط عنوانُه معها.** فعنوانٌ تحته فراغٌ
     * يُخبر المستخدمَ أن ثَمَّ ما لا يراه، وذاك خبرٌ بذاته — والقاعدةُ في
     * هذا البرنامج أن ما لا يخصّ المرءَ غيرُ موجودٍ في حقّه، لا موجودٌ
     * ممنوع.
     *
     * @param  callable(string): bool  $allows
     * @return array<int, array{title: string|null, items: array<int, array<string, mixed>>}>
     */
    public static function grouped(callable $allows): array
    {
        $out = [];

        foreach (self::SECTIONS as $key => $title) {
            $items = [];

            foreach (self::definition() as $item) {
                if ($item['group'] === $key && $allows($item['ability'])) {
                    $items[] = $item;
                }
            }

            if ($items !== []) {
                $out[] = ['title' => $title, 'items' => $items];
            }
        }

        return $out;
    }
}
