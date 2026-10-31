@extends('layouts.app')
@section('title', 'المواقع')

@section('content')

<h1 class="h5 mb-3">المواقع</h1>

<div class="row g-3">
    @can('sites.create')
        <div class="col-lg-4">
            <div class="card"><div class="card-body">
                <h2 class="h6">موقعٌ جديد</h2>
                <form method="POST" action="{{ route('sites.store') }}">
                    @csrf
                    <div class="mb-2">
                        <label class="form-label small">الاسم</label>
                        <input name="name" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">رمزٌ مختصر</label>
                        <input name="code" class="form-control form-control-sm mono">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">ملاحظة</label>
                        <input name="note" class="form-control form-control-sm">
                    </div>
                    <input type="hidden" name="is_active" value="1">
                    <button class="btn btn-sm btn-primary w-100" style="background: var(--brand); border: 0;">إضافة</button>
                </form>
            </div></div>
        </div>
    @endcan

    <div class="col-lg-8">
        <div class="card"><div class="card-body p-0">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>الاسم</th><th>الرمز</th><th>الأجهزة</th><th>الأرقام المربوطة</th>
                    @canany(['sites.edit', 'sites.delete'])<th></th>@endcanany
                </tr></thead>
                <tbody>
                @forelse ($sites as $site)
                    <tr>
                        <td>
                            @can('sites.edit')
                                <input form="s-{{ $site->id }}" name="name" value="{{ $site->name }}"
                                       class="form-control form-control-sm">
                            @else
                                {{ $site->name }}
                            @endcan
                        </td>
                        <td>
                            @can('sites.edit')
                                <input form="s-{{ $site->id }}" name="code" value="{{ $site->code }}"
                                       class="form-control form-control-sm mono" style="max-width: 6rem">
                            @else
                                {{ $site->code }}
                            @endcan
                        </td>
                        <td class="num">{{ $site->devices_count }}</td>
                        <td class="num">{{ $site->pins_count }}</td>

                        @canany(['sites.edit', 'sites.delete'])
                            <td class="text-nowrap">
                                @can('sites.edit')
                                    <button form="s-{{ $site->id }}" class="btn btn-sm btn-outline-success py-0">حفظ</button>
                                @endcan
                                @can('sites.delete')
                                    <button form="sd-{{ $site->id }}" class="btn btn-sm btn-outline-danger py-0"
                                            onclick="return confirm('حذفُ الموقع؟')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @endcan
                            </td>
                        @endcanany
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">
                        لا مواقعَ بعد. أضف موقعاً أوّلاً ثم أضف أجهزتَه إليه.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div></div>
    </div>
</div>

@foreach ($sites as $site)
    @can('sites.edit')
        <form id="s-{{ $site->id }}" method="POST" action="{{ route('sites.update', $site) }}" class="d-none">
            @csrf @method('PUT')
            <input type="hidden" name="is_active" value="1">
        </form>
    @endcan
    @can('sites.delete')
        <form id="sd-{{ $site->id }}" method="POST" action="{{ route('sites.destroy', $site) }}" class="d-none">
            @csrf @method('DELETE')
        </form>
    @endcan
@endforeach

@endsection
