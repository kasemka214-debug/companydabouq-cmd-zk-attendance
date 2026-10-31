@extends('layouts.app')
@section('title', 'الأجهزة')

@php use App\Support\Clock; @endphp

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">الأجهزة</h1>
    @can('devices.create')
        <a href="{{ route('devices.create') }}" class="btn btn-sm btn-primary" style="background: var(--brand); border: 0;">
            <i class="bi bi-plus-lg"></i> جهازٌ جديد
        </a>
    @endcan
</div>

@php
    $homeless = $devices->whereNull('site_id');
    $skewed   = $devices->filter->clockSuspect();
@endphp

@if ($skewed->count())
    {{--
        ساعةٌ مائلةٌ تعني أوقاتَ حضورٍ كاذبةً في الكشوف كلِّها — لا في هذه
        الشاشة وحدَها. فالتنبيهُ فوق الجدول لا في سطرٍ يُتصفَّح.
    --}}
    <div class="alert alert-danger no-print">
        <strong><i class="bi bi-clock-history"></i>
            ساعةُ {{ $skewed->count() === 1 ? 'جهازٍ' : $skewed->count().' أجهزةٍ' }} غيرُ مضبوطة:</strong>
        @foreach ($skewed as $d)
            <a href="{{ route('devices.show', $d) }}" class="alert-link">{{ $d->name }}</a>
            <span class="small">({{ $d->clockLabel() }})</span>@if (! $loop->last)، @endif
        @endforeach
        <br>
        <span class="small">
            والبصماتُ تُختم بساعة الجهاز لا بساعة الخادم — فأوقاتُ الحضور الواصلةُ منها الآن غيرُ صحيحة.
            افتح الجهازَ واضغط «ضبطُ ساعة الجهاز».
        </span>
    </div>
@endif

@if ($homeless->count())
    {{--
        جهازٌ بلا موقعٍ لا يظهر حضورُه في كشف موقعٍ، ولا تُربط أرقامُه
        بأسماء — لأن الرقمَ يُفرَّق بموقعه. والتنبيهُ هنا لا في صفحةٍ
        أخرى: لا يفتح أحدٌ صفحةً ليبحث عن نقصٍ لا يعلم به.
    --}}
    <div class="alert alert-warning no-print">
        <strong><i class="bi bi-exclamation-triangle"></i>
            {{ $homeless->count() }} {{ $homeless->count() === 1 ? 'جهازٌ' : 'أجهزةٍ' }} بلا موقع:</strong>
        {{ $homeless->pluck('name')->implode('، ') }}.
        <br>
        <span class="small">
            وبصماتُها لا تظهر في كشف أيّ موقع، ولا تُربط أرقامُها بأسماء — فالرقمُ يُفرَّق بموقعه.
            أسنِد كلَّ جهازٍ إلى موقعه، وستُلحَق بصماتُه السابقةُ به تلقائياً.
        </span>
    </div>
@endif

<div class="card"><div class="card-body p-0">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th></th><th>الجهاز</th><th>الرقم التسلسلي</th><th>الموقع</th>
                <th>الطراز</th><th>آخر عنوان</th><th>آخر اتصال</th><th>آخر بصمة</th><th>النبضات</th>
            </tr></thead>
            <tbody>
            @forelse ($devices as $d)
                @php $s = $stats[$d->id] ?? null; @endphp
                <tr class="{{ $d->is_active ? '' : 'opacity-50' }}">
                    <td><span class="dot {{ $d->isOnline() ? 'dot-on' : 'dot-off' }}"
                              title="{{ $d->isOnline() ? 'متصل' : 'منقطع' }}"></span></td>
                    <td>
                        <a href="{{ route('devices.show', $d) }}">{{ $d->name }}</a>
                        @unless ($d->is_active)<span class="badge bg-secondary">مُطفأ</span>@endunless
                    </td>
                    <td class="mono">{{ $d->serial_number }}</td>
                    <td>{{ $d->site?->name ?? '—' }}</td>
                    <td class="small">{{ $d->model ?? '—' }}</td>
                    <td class="mono small">{{ $d->last_ip ?? '—' }}</td>
                    <td class="num small">{{ $d->lastSeenLabel() }}</td>
                    <td class="num small">{{ $s ? Clock::stamp($s->last_at) : '—' }}</td>
                    <td class="num">{{ number_format((int) ($s->total ?? 0)) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-5">
                    لا أجهزةَ بعد. أضف الجهازَ هنا برقمِه التسلسلي أوّلاً، ثم اضبط عنوانَ الخادم فيه.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div></div>

@endsection
