<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            /*
             * مساراتُ الأجهزة خارج مجموعة web عمداً: لا جلسة، ولا كعكة، ولا CSRF.
             * الجهازُ لا يحمل شيئاً من ذلك، وإقحامُه في web يُنشئ لكل نبضةٍ جلسةً
             * ميتة في قاعدة البيانات — عشراتُ الآلاف منها في الشهر.
             */
            Route::middleware('iclock')
                ->prefix('iclock')
                ->group(base_path('routes/iclock.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->group('iclock', [
            \App\Http\Middleware\DeviceGate::class,
        ]);

        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
