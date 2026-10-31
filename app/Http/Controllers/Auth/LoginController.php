<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show()
    {
        return Auth::check() ? redirect()->route('dashboard') : view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string'],
        ], [], ['username' => 'اسم المستخدم', 'password' => 'كلمة المرور']);

        /*
         * الحدُّ على (الاسم + العنوان) معاً لا على أحدهما. فالحدُّ بالاسم
         * وحده يُمكّن مهاجماً من قفل حساب المدير بمحاولاتٍ فاشلةٍ متعمَّدة،
         * والحدُّ بالعنوان وحده يترك مكتباً كاملاً خلف عنوانٍ واحدٍ يُقفل
         * بخطأِ رجلٍ واحد.
         */
        $key = mb_strtolower($data['username']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'username' => 'محاولاتٌ كثيرة. انتظر '.RateLimiter::availableIn($key).' ثانية.',
            ]);
        }

        if (! Auth::attempt(['username' => $data['username'], 'password' => $data['password'], 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages([
                'username' => 'اسمُ المستخدم أو كلمةُ المرور غيرُ صحيحة.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
