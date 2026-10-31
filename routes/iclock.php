<?php

use App\Http\Controllers\Iclock\CdataController;
use App\Http\Controllers\Iclock\CommandController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| مساراتُ الأجهزة — بروتوكول iclock / ADMS
|--------------------------------------------------------------------------
|
| هذه المساراتُ الأربعةُ هي كلُّ ما يعرفه جهازُ ZKTeco. وأسماؤُها ليست
| اختيارَنا: الجهازُ يطلبها حرفياً كما هي مكتوبةٌ في برنامجه، فلا تُبدَّل
| ولا تُترجَم ولا يُزاد عليها بادئةٌ غيرُ /iclock.
|
| وهي وحدها الواجهةُ المفتوحة للعالم بلا مصادقة — حرسُها كلُّه في
| App\Http\Middleware\DeviceGate، وما وراءها لا يُفتح إلا بتسجيل دخول.
|
| والـ GET و الـ POST على المسار نفسه فعلان مختلفان لا فعلٌ واحد:
| الأولُ مصافحةٌ تُعطي الإعدادات، والثاني دفعُ سجلّ.
|
*/

Route::get('cdata', [CdataController::class, 'handshake'])->name('iclock.handshake');
Route::post('cdata', [CdataController::class, 'receive'])->name('iclock.receive');

/*
 * التسجيل — لا تعرفه أجهزةُ الحضور القديمة، ولا غنى عنه لأجهزة الدفع 3.x.
 * الجهازُ يُعرّف بنفسه وينتظر رمزاً، فإن لم يجده عاد إلى أوّل الطريق.
 */
Route::post('registry', [CdataController::class, 'registry'])->name('iclock.registry');

Route::get('getrequest', [CommandController::class, 'pull'])->name('iclock.getrequest');
Route::post('devicecmd', [CommandController::class, 'acknowledge'])->name('iclock.devicecmd');
Route::match(['get', 'post'], 'ping', [CommandController::class, 'ping'])->name('iclock.ping');

/*
|--------------------------------------------------------------------------
| ملتقطُ ما لا نعرفه — ويبقى آخرَ المسارات دائماً
|--------------------------------------------------------------------------
|
| المساراتُ فوقُ هي ما نعرفه من البروتوكول. وما سواها كان يسقط في 404 من
| المُوجِّه **قبل أن يبلغ الحارس**، فلا يُسجَّل ولا يُرى — فيبدو الجهازُ
| صامتاً وهو يتكلّم في بابٍ آخر.
|
| وهذا ليس فرضاً نظرياً: الأجهزةُ تختلف في أسماء مسارات الدفع بين
| `DeviceType=att` و`acc`، وبين إصدارٍ وإصدار. فهذا الملتقطُ يقبل كلَّ ما
| تحت `/iclock`، ويردّ «OK»، **ويُقيّده في سجلّ الاتصالات بمساره وجسده** —
| فيتحوّل السؤالُ «لماذا لا يرسل؟» إلى جوابٍ مقروء.
|
| وهو آمن: لا يفعل شيئاً سوى التسجيل، ولا يمرّ إلا من الحارس نفسِه.
|
*/
Route::any('{path}', [CommandController::class, 'unknown'])
    ->where('path', '.*')
    ->name('iclock.unknown');
