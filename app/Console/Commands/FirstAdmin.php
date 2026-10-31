<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * إنشاءُ أوّل حسابِ دخول — مرّةً واحدةً على قاعدةٍ جديدة.
 *
 * ══ لماذا هذا الأمر ══
 *
 * قاعدةٌ جديدةٌ تُنشأ بالترحيل فتخرج بجداولها كاملةً **وبلا مستخدمٍ
 * واحد**. فالبرنامجُ يعمل ولا يُفتح: صفحةُ الدخول تطلب حساباً، ولا حساب،
 * ولا بابَ لإنشائه — فشاشةُ المستخدمين نفسُها خلف تسجيلِ الدخول.
 *
 * وهذا يقع في كل نقلٍ إلى قاعدةٍ جديدة، وهو الموضعُ الذي يقف عنده صاحبُ
 * البرنامج لا يدري ما يصنع.
 *
 * ══ ولماذا لا يُفتح بابٌ في الشاشة ══
 *
 * «أنشئ أوّلَ حساب» في الواجهة بابٌ يبقى مفتوحاً بعد أن يُستغنى عنه:
 * فمن حذف المستخدمين جميعاً — أو وجد القاعدةَ فارغةً للحظة — دخل مديراً.
 * وأمرُ سطرٍ لا يُنفَّذ إلا ممّن يملك لوحةَ الخادم أصلاً.
 *
 * ══ الحارس ══
 *
 * **لا يعمل إن كان في القاعدة مستخدمٌ واحد.** فهو لإنشاء الأوّل لا
 * لإضافة غيره — وإضافةُ المستخدمين من شاشتهم بصلاحياتها. ولا يُستعمل
 * لانتزاع النظام ممن يملكه.
 *
 *     php artisan zk:first-admin
 *     php artisan zk:first-admin --username=kasem --name="قاسم"
 */
class FirstAdmin extends Command
{
    protected $signature = 'zk:first-admin
        {--username=admin : اسمُ المستخدم}
        {--name= : الاسمُ كما يُعرض}';

    protected $description = 'إنشاءُ أوّل حسابِ دخول على قاعدةٍ جديدة — ولا يعمل إن وُجد مستخدم';

    public function handle(): int
    {
        $existing = User::query()->count();

        if ($existing > 0) {
            $this->error("في القاعدة {$existing} مستخدماً بالفعل.");
            $this->line('وهذا الأمرُ لإنشاء الأوّل وحدَه. وإضافةُ غيره من شاشة «المستخدمون».');
            $this->newLine();
            $this->line('وإن نسيتَ كلمةَ المرور فبدّلها من الشاشة نفسِها بحسابٍ آخرَ يملكها.');

            return self::FAILURE;
        }

        $username = trim((string) $this->option('username')) ?: 'admin';
        $name     = trim((string) $this->option('name')) ?: 'مدير النظام';

        /*
         * كلمةٌ عشوائيةٌ تُولَّد ولا تُطلب.
         *
         * فكلمةٌ يكتبها صاحبُها في سطر الأوامر تبقى في تاريخ الصدَفة وفي
         * سجلّ لوحة الخادم. وهذه تُطبع مرّةً واحدةً ثم لا سبيلَ إليها —
         * وتُبدَّل من الشاشة بعد أوّل دخول.
         */
        $password = Str::password(16, symbols: false);

        $user = User::create([
            'name'        => $name,
            'username'    => $username,
            'password'    => $password,   // يُبصَم بـ`hashed` في النموذج
            'is_admin'    => true,
            'is_active'   => true,

            /*
             * ولا تُكتب له مفاتيح: المديرُ يملك كلَّ شيءٍ بـ`Gate::before`.
             * وكتابتُها تُوهم أنها هي مصدرُ صلاحيته، فيُنقَص منها يوماً
             * ولا يتغيّر شيء.
             */
            'permissions' => [],
        ]);

        $this->newLine();
        $this->info('✓ أُنشئ حسابُ الدخول.');
        $this->newLine();
        $this->line('  اسمُ المستخدم : '.$user->username);
        $this->line('  كلمةُ المرور  : '.$password);
        $this->newLine();
        $this->warn('  وهذه آخرُ مرّةٍ تُعرض فيها كلمةُ المرور — انسخها الآن.');
        $this->line('  وبدّلها بعد أوّل دخول من شاشة «المستخدمون».');
        $this->newLine();

        return self::SUCCESS;
    }
}
