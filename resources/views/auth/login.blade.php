@extends('layouts.app')
@section('title', 'الدخول')

@section('content')
<div class="row justify-content-center mt-5">
    <div class="col-md-5 col-lg-4">
        <div class="card">
            <div class="card-body p-4">
                <h1 class="h5 mb-4 text-center">
                    <i class="bi bi-fingerprint"></i> {{ config('app.name') }}
                </h1>

                <form method="POST" action="{{ route('login') }}">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label">اسم المستخدم</label>
                        <input name="username" value="{{ old('username') }}"
                               class="form-control @error('username') is-invalid @enderror"
                               autofocus required>
                        @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">كلمة المرور</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>

                    <div class="form-check mb-3">
                        <input type="checkbox" name="remember" value="1" id="remember" class="form-check-input">
                        <label for="remember" class="form-check-label">تذكّرني</label>
                    </div>

                    <button class="btn btn-primary w-100" style="background: var(--brand); border: 0;">دخول</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
