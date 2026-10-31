# نظام حضور البصمة — ZKTeco

تطبيق Laravel يستقبل بصمات أجهزة ZKTeco من عدّة مواقع عبر بروتوكول
**iclock / ADMS** (الجهاز هو الذي يتّصل، فلا يلزم IP ثابت ولا VPN ولا فتح
منافذ في راوتر الموقع)، ويعرضها سجلّاً وكشوفاً بواجهة عربية RTL.

مستقلٌّ تماماً عن برنامج دابوق — مستودعاً وقاعدةً ونطاقاً — ولا يكتب فيه شيئاً.

- **الشرح الكامل، والتشغيل، وضبط الأجهزة، والأمان:** [`اقرأني-أولاً.md`](اقرأني-أولاً.md)
- **قواعد العمل على الشيفرة:** [`CLAUDE.md`](CLAUDE.md)

## تشغيل سريع

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
```

## الفحص بلا vendor

```bash
php scripts/محاكاة-النبضات.php
```

يشغّل `Clock` و`AttlogParser` الحقيقيَّين فوق بديل صغير لـCarbon — فحوصٌ
للتحويل الزمني، وحدّ منتصف الليل، وأشكال الفصل بين الحقول، والأسطر الفاسدة.

## مكدّس

Laravel 13 · PHP 8.3 · MySQL · Bootstrap 5 RTL · خط Cairo
