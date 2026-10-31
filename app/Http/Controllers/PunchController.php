<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Pin;
use App\Models\Punch;
use App\Models\Site;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PunchController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:punches.view',   only: ['index', 'export']),
            // التصديرُ إخراجٌ كالطباعة — مفتاحٌ واحدٌ للبابين
            new Middleware('can:page.print',     only: ['export']),
            new Middleware('can:punches.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $f = $this->filters($request);

        $punches = $this->query($f)->paginate(200)->withQueryString();

        return view('punches.index', [
            'punches' => $punches,
            'names'   => Pin::lookup($f['site_id'] ?: null),
            'sites'   => Site::orderBy('name')->get(),
            'devices' => Device::orderBy('name')->get(),
            'f'       => $f,
        ]);
    }

    public function export(Request $request)
    {
        $f = $this->filters($request);

        $names = Pin::lookup($f['site_id'] ?: null);

        $rows = function () use ($f, $names) {
            /*
             * `lazy` لا `get`: تصديرُ شهرٍ لخمسةِ مواقعَ عشراتُ الآلاف من
             * الصفوف، وجلبُها جميعاً إلى الذاكرة قبل الكتابة يُسقط الطلب.
             * والبثُّ يمرّ عليها ألفاً ألفاً.
             */
            foreach ($this->query($f)->lazy(1000) as $p) {
                $name = $names[$p->site_id.'|'.$p->device_pin] ?? null;

                yield [
                    Clock::stamp($p->punched_at, 'Y-m-d'),
                    Clock::stamp($p->punched_at, 'H:i:s'),
                    $p->work_date->toDateString(),
                    $p->device_pin,
                    $name->employee_name ?? '— غير مربوط —',
                    $name->employee_ref ?? '',
                    $p->site?->name ?? '',
                    $p->device?->name ?? '',
                    $p->stateLabel(),
                    $p->verifyLabel(),
                ];
            }
        };

        return $this->csv(
            'النبضات-'.$f['from'].'-إلى-'.$f['to'].'.csv',
            ['التاريخ', 'الوقت', 'يوم العمل', 'رقم الجهاز', 'الموظف', 'رقمه في دابوق', 'الموقع', 'الجهاز', 'النوع', 'التحقّق'],
            $rows(),
        );
    }

    public function destroy(Punch $punch)
    {
        /*
         * الحذفُ بابٌ يُفتح للتصحيح لا للتنظيف: بصمةٌ سُجّلت لجهازٍ كانت
         * ساعتُه غلطاً، أو تجربةٌ أثناء التركيب. وكلُّ حذفٍ يُقيَّد في
         * السجلّ كي لا يضيع مَن فعله.
         */
        logger()->notice('حُذفت نبضة', [
            'id'    => $punch->id,
            'pin'   => $punch->device_pin,
            'at'    => (string) $punch->punched_at,
            'by'    => request()->user()?->username,
        ]);

        $punch->delete();

        return back()->with('ok', 'حُذفت النبضة.');
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $today = Clock::now()->toDateString();

        return [
            'from'      => (string) $request->query('from', $today),
            'to'        => (string) $request->query('to', $today),
            'site_id'   => (int) $request->query('site_id', 0),
            'device_id' => (int) $request->query('device_id', 0),
            'pin'       => trim((string) $request->query('pin', '')),
        ];
    }

    private function query(array $f)
    {
        return Punch::with(['site', 'device'])
            ->select('zk_punches.*')

            /*
             * ترتيبُ البصمة في يوم صاحبها — أوّلُها دخولٌ وثانيتُها خروج.
             *
             * وكان النوعُ يُؤخذ من الجهاز، فتظهر بصماتُ اليوم كلُّها
             * «دخول»: النوعُ عند ZKTeco يعتمد على زرٍّ يضغطه العاملُ قبل
             * إصبعه، وأكثرُهم لا يضغطه فيبقى على الافتراضي.
             *
             * والترتيبُ أصدقُ من الزرّ، ويُحسب عند العرض بنافذةٍ على
             * القاعدة — فلا يُخزَّن ولا يشيخ إن حُذفت بصمةٌ أو صُحّحت.
             */
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY site_id, device_pin, work_date ORDER BY punched_at) AS seq'
            )

            ->between($f['from'], $f['to'])
            ->when($f['site_id'], fn ($b) => $b->where('site_id', $f['site_id']))
            ->when($f['device_id'], fn ($b) => $b->where('device_id', $f['device_id']))
            ->when($f['pin'] !== '', fn ($b) => $b->where('device_pin', $f['pin']))
            ->orderByDesc('punched_at');
    }
}
