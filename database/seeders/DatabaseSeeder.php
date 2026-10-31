<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * مديرٌ واحدٌ ليُفتح النظام أوّلَ مرّة — وكلمتُه تُقرأ من البيئة
         * لا تُكتب هنا. وكلمةُ مرورٍ افتراضيةٌ في شيفرةٍ على GitHub ليست
         * كلمةَ مرور: هي بابٌ مفتوحٌ يعرفه كلُّ من قرأ المستودع.
         */
        $password = env('ZK_ADMIN_PASSWORD');

        if (! $password) {
            $this->command?->warn('لم تُضبط ZK_ADMIN_PASSWORD في .env — لم يُنشأ مستخدم.');

            return;
        }

        User::updateOrCreate(
            ['username' => env('ZK_ADMIN_USERNAME', 'admin')],
            [
                'name'      => env('ZK_ADMIN_NAME', 'المدير'),
                'password'  => $password,
                'is_admin'  => true,
                'is_active' => true,
            ],
        );

        $this->command?->info('جاهز. سجّل الدخولَ ثم غيّر كلمةَ المرور واحذفها من .env.');
    }
}
