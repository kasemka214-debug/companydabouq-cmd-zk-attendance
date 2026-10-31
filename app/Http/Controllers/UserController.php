<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Abilities;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class UserController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:users.manage')];
    }

    public function index()
    {
        return view('users.index', [
            'users'  => User::orderBy('name')->get(),
            'groups' => Abilities::GROUPS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $data['password'] = $request->validate(
            ['password' => ['required', 'string', 'min:10']],
            [],
            ['password' => 'كلمة المرور'],
        )['password'];

        User::create($data);

        return back()->with('ok', 'أُضيف المستخدم.');
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user->id);

        /*
         * كلمةُ المرور لا تُمحى بحقلٍ فارغ. من فتح الشاشة ليُعدّل صلاحيةً
         * ولم يلمس الحقل لا يقصد إلغاءَ كلمة المرور — وإلغاؤها يُخرج
         * الرجلَ من النظام بلا أن يدري لماذا.
         */
        if ($request->filled('password')) {
            $data['password'] = $request->validate(
                ['password' => ['string', 'min:10']],
                [],
                ['password' => 'كلمة المرور'],
            )['password'];
        }

        /*
         * ولا يُطفئ المرءُ نفسَه ولا ينزع عن نفسه صفةَ المدير: لو فعل
         * بقي النظامُ بلا أحدٍ يملك مفاتيحه ولا سبيلَ إلى فتحه إلا من
         * القاعدة مباشرةً.
         */
        if ($user->id === $request->user()->id) {
            unset($data['is_active']);
            $data['is_admin'] = true;
        }

        $user->update($data);

        return back()->with('ok', 'حُفظ المستخدم.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('err', 'لا تحذف نفسك.');
        }

        if (User::where('is_admin', true)->where('is_active', true)->count() <= 1 && $user->is_admin) {
            return back()->with('err', 'هذا آخرُ مديرٍ فعّال — حذفُه يُغلق النظام على الجميع.');
        }

        $user->delete();

        return back()->with('ok', 'حُذف المستخدم.');
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:190'],
            'username'      => ['required', 'string', 'max:64', Rule::unique('users', 'username')->ignore($ignore)],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(Abilities::ALL))],
        ], [], [
            'name'        => 'الاسم',
            'username'    => 'اسم المستخدم',
            'permissions' => 'الصلاحيات',
        ]);

        $data['permissions'] = array_values($data['permissions'] ?? []);
        $data['is_admin']    = $request->boolean('is_admin');
        $data['is_active']   = $request->boolean('is_active');

        return $data;
    }
}
