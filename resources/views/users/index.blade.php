@extends('layouts.app')
@section('title', 'المستخدمون')

@php use App\Support\Abilities; @endphp

@section('content')

<h1 class="h5 mb-3">المستخدمون</h1>

<div class="card mb-3"><div class="card-body">
    <h2 class="h6">مستخدمٌ جديد</h2>
    <form method="POST" action="{{ route('users.store') }}">
        @csrf
        <div class="row g-2 mb-3">
            <div class="col-md-3">
                <label class="form-label small">الاسم</label>
                <input name="name" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small">اسم المستخدم</label>
                <input name="username" class="form-control form-control-sm mono" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small">كلمة المرور (١٠ أحرف فأكثر)</label>
                <input type="password" name="password" class="form-control form-control-sm" required minlength="10">
            </div>
            <div class="col-md-3 d-flex align-items-end gap-3">
                <div class="form-check">
                    <input type="checkbox" name="is_admin" value="1" id="na" class="form-check-input">
                    <label for="na" class="form-check-label small">مدير</label>
                </div>
                <div class="form-check">
                    <input type="checkbox" name="is_active" value="1" id="nc" class="form-check-input" checked>
                    <label for="nc" class="form-check-label small">مفعّل</label>
                </div>
            </div>
        </div>

        @include('users._abilities', ['selected' => Abilities::VIEWER, 'prefix' => 'new'])

        <button class="btn btn-sm btn-primary" style="background: var(--brand); border: 0;">إضافة</button>
    </form>
</div></div>

@foreach ($users as $user)
    <div class="card mb-2"><div class="card-body">
        <form method="POST" action="{{ route('users.update', $user) }}">
            @csrf @method('PUT')

            <div class="row g-2 mb-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">الاسم</label>
                    <input name="name" value="{{ $user->name }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">اسم المستخدم</label>
                    <input name="username" value="{{ $user->username }}" class="form-control form-control-sm mono">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">كلمة مرورٍ جديدة</label>
                    <input type="password" name="password" class="form-control form-control-sm"
                           placeholder="اتركه فارغاً" minlength="10">
                </div>
                <div class="col-md-3 d-flex gap-3">
                    <div class="form-check">
                        <input type="checkbox" name="is_admin" value="1" class="form-check-input"
                               id="a{{ $user->id }}" @checked($user->is_admin)
                               @disabled($user->id === auth()->id())>
                        <label for="a{{ $user->id }}" class="form-check-label small">مدير</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input"
                               id="c{{ $user->id }}" @checked($user->is_active)
                               @disabled($user->id === auth()->id())>
                        <label for="c{{ $user->id }}" class="form-check-label small">مفعّل</label>
                    </div>
                </div>
                <div class="col-md-2 text-start">
                    <button class="btn btn-sm btn-outline-success">حفظ</button>
                    @if ($user->id !== auth()->id())
                        <button form="ud-{{ $user->id }}" class="btn btn-sm btn-outline-danger"
                                onclick="return confirm('حذفُ المستخدم؟')">
                            <i class="bi bi-trash"></i>
                        </button>
                    @endif
                </div>
            </div>

            @if ($user->is_admin)
                <div class="small text-muted">مديرٌ — يملك كلَّ المفاتيح.</div>
            @else
                @include('users._abilities', ['selected' => $user->permissions ?? [], 'prefix' => $user->id])
            @endif
        </form>
    </div></div>
@endforeach

@foreach ($users as $user)
    @if ($user->id !== auth()->id())
        <form id="ud-{{ $user->id }}" method="POST" action="{{ route('users.destroy', $user) }}" class="d-none">
            @csrf @method('DELETE')
        </form>
    @endif
@endforeach

@endsection
