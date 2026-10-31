<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SiteController extends Controller implements HasMiddleware
{
    /** مفتاحٌ لكل فعل — والحذفُ مفتاحُه غيرُ مفتاح التعديل. */
    public static function middleware(): array
    {
        return [
            new Middleware('can:sites.view',   only: ['index']),
            new Middleware('can:sites.create', only: ['store']),
            new Middleware('can:sites.edit',   only: ['update']),
            new Middleware('can:sites.delete', only: ['destroy']),
        ];
    }

    public function index()
    {
        return view('sites.index', [
            'sites' => Site::withCount(['devices', 'pins'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        Site::create($this->validated($request));

        return redirect()->route('sites.index')->with('ok', 'أُضيف الموقع.');
    }

    public function update(Request $request, Site $site)
    {
        $site->update($this->validated($request, $site->id));

        return redirect()->route('sites.index')->with('ok', 'حُفظ الموقع.');
    }

    public function destroy(Site $site)
    {
        /*
         * الموقعُ لا يُحذف وفيه جهازٌ أو رقمٌ مربوط. والمنعُ خيرٌ من الحذف
         * المتسلسل: حذفُ موقعٍ بالخطأ يجرّ أرقامَه وأسماءَها كلَّها، ولا
         * تُستردّ — بينما النبضاتُ تبقى يتيمةً بلا موقعٍ ولا اسم.
         */
        if ($site->devices()->exists() || $site->pins()->exists()) {
            return back()->with('err', 'لا يُحذف موقعٌ فيه أجهزةٌ أو أرقامٌ مربوطة. انقلها أو احذفها أولاً.');
        }

        $site->delete();

        return redirect()->route('sites.index')->with('ok', 'حُذف الموقع.');
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        return $request->validate([
            'name'      => ['required', 'string', 'max:190', 'unique:zk_sites,name'.($ignore ? ",{$ignore}" : '')],
            'code'      => ['nullable', 'string', 'max:32'],
            'note'      => ['nullable', 'string', 'max:190'],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'name' => 'اسم الموقع',
            'code' => 'الرمز',
            'note' => 'ملاحظة',
        ]) + ['is_active' => $request->boolean('is_active')];
    }
}
