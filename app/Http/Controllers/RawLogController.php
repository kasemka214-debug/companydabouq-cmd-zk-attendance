<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\RawLog;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * سجلُّ الاتصالات الخام — شاشةُ التشخيص.
 *
 * حين يقول الموقعُ «بصمتُ ولم يظهر» فالجوابُ هنا: أوصل الطلبُ أصلاً؟ ومن
 * أيّ عنوان؟ وبأيّ رقمٍ تسلسلي؟ وما الذي رددناه عليه؟ وبغير هذه الشاشة
 * تصير كلُّ شكوى تخميناً.
 */
class RawLogController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:raw.view')];
    }

    public function index(Request $request)
    {
        $accepted = $request->query('accepted', '');
        $serial   = trim((string) $request->query('serial', ''));
        $q        = trim((string) $request->query('q', ''));

        $logs = RawLog::with('device')
            ->when($accepted !== '', fn ($b) => $b->where('accepted', $accepted === '1'))
            ->when($serial !== '', fn ($b) => $b->where('serial_number', 'like', "%{$serial}%"))

            /*
             * بحثٌ في المسار والعنوان والجسد معاً.
             *
             * وكانت الخانةُ معروضةً غيرَ موصولة — زرٌّ يُضغط فلا يفعل شيئاً،
             * وهو أسوأُ من غيابه. وبها يُسأل السؤالُ الذي لا يُجاب بغيرها:
             * «هل أرسل الجهازُ صورةً قطّ؟» — يُبحث عن ATTPHOTO فيُعرف.
             *
             * والجسدُ عمودٌ طويل، فالبحثُ فيه لا يستفيد من فهرس. ولا بأس:
             * سجلٌّ يُكنس كلَّ ثلاثين يوماً لا يبلغ حجماً يُتعب، والبحثُ
             * فيه فعلُ تشخيصٍ لا فعلُ تصفّح.
             */
            ->when($q !== '', fn ($b) => $b->where(function ($w) use ($q) {
                $w->where('path', 'like', "%{$q}%")
                    ->orWhere('query', 'like', "%{$q}%")
                    ->orWhere('reason', 'like', "%{$q}%")
                    ->orWhere('body', 'like', "%{$q}%");
            }))

            ->latest('id')
            ->paginate(60)
            ->withQueryString();

        return view('raw.index', [
            'logs'     => $logs,
            'devices'  => Device::orderBy('name')->get(),
            'accepted' => $accepted,
            'serial'   => $serial,
            'q'        => $q,
        ]);
    }

    public function show(RawLog $log)
    {
        return view('raw.show', ['log' => $log->load('device')]);
    }
}
