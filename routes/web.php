<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ClockController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\DayController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\PinController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\PunchController;
use App\Http\Controllers\RawLogController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| شاشاتُ المراجعة
|--------------------------------------------------------------------------
|
| كلُّ ما هنا خلف تسجيل دخول. ومفتاحُ كل بابٍ هو نفسُه المكتوبُ في
| App\Support\Menu — فما يُعرض في القائمة يُفتح، وما لا يُفتح لا يُعرض.
|
*/

Route::get('login', [LoginController::class, 'show'])->name('login')->middleware('guest');
Route::post('login', [LoginController::class, 'login'])->middleware('guest');
Route::post('logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('days', [DayController::class, 'index'])->name('days.index');
    Route::get('days/export', [DayController::class, 'export'])->name('days.export');

    Route::get('reports/weekly', [ReportController::class, 'weekly'])->name('reports.weekly');
    Route::get('reports/weekly/excel', [ReportController::class, 'weeklyExcel'])->name('reports.weekly.excel');
    Route::get('reports/employee', [ReportController::class, 'employee'])->name('reports.employee');
    Route::get('reports/monthly', [ReportController::class, 'monthly'])->name('reports.monthly');
    Route::get('reports/monthly/excel', [ReportController::class, 'monthlyExcel'])->name('reports.monthly.excel');
    Route::post('reports/holidays', [ReportController::class, 'saveHolidays'])->name('reports.holidays');

    Route::get('photos', [PhotoController::class, 'index'])->name('photos.index');
    Route::get('photos/{photo}', [PhotoController::class, 'show'])->name('photos.show');
    Route::delete('photos/{photo}', [PhotoController::class, 'destroy'])->name('photos.destroy');

    Route::get('punches', [PunchController::class, 'index'])->name('punches.index');
    Route::get('punches/export', [PunchController::class, 'export'])->name('punches.export');
    Route::delete('punches/{punch}', [PunchController::class, 'destroy'])->name('punches.destroy');

    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::get('devices/create', [DeviceController::class, 'create'])->name('devices.create');
    Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
    Route::get('devices/{device}', [DeviceController::class, 'show'])->name('devices.show');
    Route::get('devices/{device}/edit', [DeviceController::class, 'edit'])->name('devices.edit');
    Route::put('devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
    Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');
    Route::post('devices/{device}/command', [DeviceController::class, 'command'])->name('devices.command');

    /*
     * الموظفون — ومساراتُ الأفعال مفصولةٌ عن العرض بمفاتيحها، فكلاهما
     * يمسّ المال: الإجازةُ تُسقط سطوراً من ورقةِ خصم، ونهايةُ الخدمة
     * تُسقط الرجلَ من كل سؤالٍ قادم.
     */
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::post('employees/{pin}/leave', [EmployeeController::class, 'storeLeave'])->name('employees.leave');
    Route::delete('employees/leave/{leave}', [EmployeeController::class, 'destroyLeave'])->name('employees.leave.destroy');
    Route::post('employees/{pin}/end', [EmployeeController::class, 'end'])->name('employees.end');
    Route::put('employees/{pin}/unend', [EmployeeController::class, 'unend'])->name('employees.unend');

    /* النقلُ بين المواقع — و`move/{move}` قبل `{pin}/move` فلا يلتقطها */
    Route::delete('employees/move/{move}', [EmployeeController::class, 'destroyMove'])->name('employees.move.destroy');
    Route::post('employees/{pin}/move', [EmployeeController::class, 'move'])->name('employees.move');

    Route::get('pins', [PinController::class, 'index'])->name('pins.index');
    Route::post('pins', [PinController::class, 'store'])->name('pins.store');
    Route::post('pins/import', [PinController::class, 'import'])->name('pins.import');
    Route::put('pins/{pin}', [PinController::class, 'update'])->name('pins.update');
    Route::delete('pins/{pin}', [PinController::class, 'destroy'])->name('pins.destroy');

    Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
    Route::post('sites', [SiteController::class, 'store'])->name('sites.store');
    Route::put('sites/{site}', [SiteController::class, 'update'])->name('sites.update');
    Route::delete('sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');

    /*
     * تقريرُ ساعات الأجهزة.
     *
     * و`operations` قبل `{event}` عمداً: لو تأخّر لَالتقطه مسارُ الحدث
     * ظانّاً أن «operations» رقمُ حدث، فرُدّ ٤٠٤ على بابٍ موجود.
     */
    Route::get('clock', [ClockController::class, 'index'])->name('clock.index');
    Route::get('clock/operations', [ClockController::class, 'operations'])->name('clock.operations');
    Route::get('clock/{event}', [ClockController::class, 'show'])->name('clock.show');
    Route::post('clock/{event}/ack', [ClockController::class, 'acknowledge'])->name('clock.ack');

    Route::get('raw', [RawLogController::class, 'index'])->name('raw.index');
    Route::get('raw/{log}', [RawLogController::class, 'show'])->name('raw.show');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});
