<?php

namespace App\Providers;

use App\Models\ClockEvent;
use App\Models\User;
use App\Support\Abilities;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * أزرارُ التنقّل بأسلوب Bootstrap لا Tailwind.
         *
         * لارافيل يرسمها افتراضياً بأصناف Tailwind، والبرنامجُ يُحمّل
         * Bootstrap وحده — فتخرج الأصنافُ بلا تعريفٍ يقابلها، وتظهر
         * الأزرارُ عاريةً متباعدةً كأنها نصٌّ لا أزرار.
         */
        Paginator::useBootstrapFive();

        /*
         * المديرُ يملك كلَّ مفتاح — وهذا هو الاستثناءُ الوحيد. وما عداه
         * يُسأل عن المفتاح بعينه، فلا يُفتَح له بابٌ بحجّة أنه فتح جارَه.
         */
        Gate::before(fn (User $user) => $user->is_admin ? true : null);

        foreach (array_keys(Abilities::ALL) as $key) {
            Gate::define($key, fn (User $user) => $user->hasAbility($key));
        }

        /*
         * عددُ أحداث الساعة التي لم يُقَرّ بها — للجرس في الشريط.
         *
         * ويُحمل بمُركِّبٍ لا باستعلامٍ في القالب: الشريطُ في كل صفحة، فلو
         * استعلم القالبُ لنفسه لصار استعلاماً في كل فتحةٍ لا يُرى في شيفرةٍ
         * ولا يُقاس في تشخيص.
         *
         * ويُخبَّأ دقيقةً: العددُ لا يتغيّر إلا أن يدفع جهازٌ دفعةً، ورقمٌ
         * متأخّرٌ دقيقةً لا يضرّ — وأمّا استعلامٌ في كل صفحةٍ لكل مستخدمٍ
         * فيضرّ. والإقرارُ يمسح المخزون فلا يبقى الجرسُ بعد إسكاته.
         */
        View::composer('layouts.app', function ($view) {
            $view->with('clockAlerts', Cache::remember(
                ClockEvent::ALERT_CACHE,
                60,
                fn () => ClockEvent::query()->unacknowledged()->alarming()->count(),
            ));
        });
    }
}
