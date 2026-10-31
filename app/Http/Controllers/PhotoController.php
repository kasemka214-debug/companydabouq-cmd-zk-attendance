<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Pin;
use App\Models\PunchPhoto;
use App\Models\Site;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PhotoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:photos.view',   only: ['index', 'show']),
            new Middleware('can:photos.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $today = Clock::now()->toDateString();

        $f = [
            'from'      => (string) $request->query('from', $today),
            'to'        => (string) $request->query('to', $today),
            'site_id'   => (int) $request->query('site_id', 0),
            'device_id' => (int) $request->query('device_id', 0),
            'pin'       => trim((string) $request->query('pin', '')),
        ];

        /*
         * `light` تنتقي الأعمدةَ كلَّها إلا المحتوى. ولو انتُقي المحتوى
         * هنا لجرّت صفحةٌ من ستّين صورةً ميجابايتاتٍ لا تُعرض منها إلا
         * عناوينُها — والصورةُ نفسُها تُطلب بمسارها واحدةً واحدة.
         */
        $photos = PunchPhoto::light()
            ->with(['device:id,name', 'site:id,name'])
            ->whereBetween('work_date', [$f['from'], $f['to']])
            ->when($f['site_id'], fn ($b) => $b->where('site_id', $f['site_id']))
            ->when($f['device_id'], fn ($b) => $b->where('device_id', $f['device_id']))
            ->when($f['pin'] !== '', fn ($b) => $b->where('device_pin', $f['pin']))
            ->orderByDesc('taken_at')
            ->paginate(60)
            ->withQueryString();

        return view('photos.index', [
            'photos'  => $photos,
            'names'   => Pin::lookup($f['site_id'] ?: null),
            'sites'   => Site::orderBy('name')->get(),
            'devices' => Device::orderBy('name')->get(),
            'f'       => $f,
        ]);
    }

    /**
     * الصورةُ نفسُها.
     *
     * وترويستُها تُؤخذ من القائمة الآمنة لا من المخزَّن حرفياً — فالمخزَّنُ
     * جاء من الشبكة، وعرضُ محتوًى بترويسةٍ يختارها مرسِلُه ثغرةٌ لا عرض.
     * والنوعُ فُحص عند الاستقبال أيضاً، وهذا حرسٌ ثانٍ: ما مرّ أوّلَ مرّة
     * قد يكون دخل من بابٍ آخر.
     */
    public function show(PunchPhoto $photo)
    {
        abort_unless(in_array($photo->mime, PunchPhoto::SAFE, true), 404);

        $bytes = base64_decode($photo->content, true);

        abort_if($bytes === false, 404);

        /* النوعُ من البايتات ثانيةً — لا من العمود المخزَّن */
        $mime = PunchPhoto::sniff($bytes);

        abort_if($mime === null, 404);

        return response($bytes, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="'.$photo->id.'.'.(str_contains($mime, 'png') ? 'png' : 'jpg').'"',
            'Cache-Control'       => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(PunchPhoto $photo)
    {
        logger()->notice('حُذفت صورةُ بصمة', [
            'id'  => $photo->id,
            'pin' => $photo->device_pin,
            'at'  => (string) $photo->taken_at,
            'by'  => request()->user()?->username,
        ]);

        $photo->delete();

        return back()->with('ok', 'حُذفت الصورة.');
    }
}
