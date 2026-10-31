<?php

return [

    'name' => env('APP_NAME', 'حضور البصمة'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    /*
     * التخزينُ UTC والعرضُ بتوقيت الكويت عبر App\Support\Clock —
     * كما في البرنامج الأمّ. قاعدةٌ تُخزّن التوقيتَ المحليَّ تكذب
     * ساعةً كاملة عند أيّ تغييرٍ موسميّ أو انتقالِ خادم.
     */
    'timezone' => 'UTC',

    'locale' => env('APP_LOCALE', 'ar'),
    'fallback_locale' => 'ar',
    'faker_locale' => 'ar_SA',

    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
    ],
];
