<?php

namespace App\Http\Controllers;

use App\Models\Command;
use App\Models\Device;
use App\Models\Punch;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:devices.view',    only: ['index', 'show']),
            new Middleware('can:devices.create',  only: ['create', 'store']),
            new Middleware('can:devices.edit',    only: ['edit', 'update']),
            new Middleware('can:devices.delete',  only: ['destroy']),
            new Middleware('can:devices.command', only: ['command']),
        ];
    }

    public function index()
    {
        $stats = Punch::query()
            ->select('device_id', DB::raw('COUNT(*) AS total'), DB::raw('MAX(punched_at) AS last_at'))
            ->groupBy('device_id')
            ->get()
            ->keyBy('device_id');

        return view('devices.index', [
            'devices' => Device::with('site')->orderBy('site_id')->orderBy('name')->get(),
            'stats'   => $stats,
        ]);
    }

    public function show(Device $device)
    {
        return view('devices.show', [
            'device'   => $device->load('site'),
            'commands' => $device->commands()->latest('id')->limit(30)->get(),
            'total'    => $device->punches()->count(),
            'lastAt'   => $device->punches()->max('punched_at'),
            'kinds'    => Command::KINDS,
        ]);
    }

    public function create()
    {
        return view('devices.form', [
            'device' => new Device(['is_active' => true]),
            'sites'  => Site::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $device = Device::create($this->validated($request));

        return redirect()->route('devices.show', $device)->with('ok', 'أُضيف الجهاز. اضبط عنوانَ الخادم فيه ثم أعد تشغيله.');
    }

    public function edit(Device $device)
    {
        return view('devices.form', [
            'device' => $device,
            'sites'  => Site::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Device $device)
    {
        $before = $device->site_id;

        $device->update($this->validated($request, $device->id));

        /*
         * تغييرُ موقع الجهاز يجرّ نبضاتِه معه.
         *
         * فموقعُ النبضة نسخةٌ تُكتب لحظةَ وصولها، ولولا هذا لبقيت بصماتُ
         * أمسٍ في موقعٍ هجره الجهازُ اليوم — بل الأسوأ: جهازٌ وصلت بصماتُه
         * قبل أن يُسنَد إلى موقعٍ تبقى بصماتُه بلا موقعٍ أبداً، وتظهر
         * «غير مربوطة» وإن رُبطت أسماؤها. وقد وقع هذا فعلاً.
         */
        if ($before !== $device->site_id) {
            $moved = $device->syncPunchSites();

            if ($moved > 0) {
                return redirect()->route('devices.show', $device)
                    ->with('ok', "حُفظ الجهاز، وأُلحقت {$moved} نبضةً بموقعه الجديد.");
            }
        }

        return redirect()->route('devices.show', $device)->with('ok', 'حُفظ الجهاز.');
    }

    public function destroy(Device $device)
    {
        /*
         * حذفُ الجهاز يجرّ نبضاتِه بالمفتاح الأجنبي — وهي تاريخُ حضورٍ لا
         * يُستردّ. فمن أراد إيقافَ جهازٍ فليُطفئه لا ليحذفه: المُطفأُ يُردّ
         * عليه بلا قبولِ بيانات، وسجلُّه باقٍ.
         */
        if ($device->punches()->exists()) {
            return back()->with('err', 'لهذا الجهاز نبضاتٌ مسجَّلة — حذفُه يمحوها. أطفئه بدل حذفه.');
        }

        $device->delete();

        return redirect()->route('devices.index')->with('ok', 'حُذف الجهاز.');
    }

    /** إضافةُ أمرٍ إلى الطابور — لا يُنفَّذ الآن بل حين يستعلم الجهاز. */
    public function command(Request $request, Device $device)
    {
        $kind = (string) $request->input('kind');

        abort_unless(isset(Command::KINDS[$kind]), 422);

        Command::create([
            'device_id'  => $device->id,
            'kind'       => $kind,
            'body'       => Command::bodyFor($kind, $device),
            'label'      => Command::KINDS[$kind],
            'status'     => 'pending',
            'created_by' => $request->user()->id,
        ]);

        $delay = (int) config('zk.poll_delay', 30);

        return back()->with('ok', "أُضيف الأمرُ إلى الطابور. ينفّذه الجهازُ عند استعلامه القادم (خلال {$delay} ثانية تقريباً).");
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        $data = $request->validate([
            'serial_number' => ['required', 'string', 'max:64', 'unique:zk_devices,serial_number'.($ignore ? ",{$ignore}" : '')],
            'name'          => ['required', 'string', 'max:190'],
            'site_id'       => ['nullable', 'exists:zk_sites,id'],
            'allowed_ips'   => ['nullable', 'string', 'max:190'],
            'note'          => ['nullable', 'string', 'max:2000'],
        ], [], [
            'serial_number' => 'الرقم التسلسلي',
            'name'          => 'اسم الجهاز',
            'site_id'       => 'الموقع',
            'allowed_ips'   => 'العناوين المسموحة',
            'note'          => 'ملاحظة',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
