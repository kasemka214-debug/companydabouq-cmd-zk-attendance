@extends('layouts.app')
@section('title', 'اللوحة')

@php use App\Support\Clock; @endphp

@section('content')

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">أجهزةٌ متصلة الآن</div>
            <div class="stat">{{ $devices->filter->isOnline()->count() }} <span class="text-muted fs-6">من {{ $devices->count() }}</span></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">بصماتُ اليوم ({{ $today }})</div>
            <div class="stat">{{ number_format($todayCount) }}</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">مَن بصم اليوم</div>
            <div class="stat">{{ number_format($todayPeople) }}</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100 {{ $orphans->count() ? 'border border-warning' : '' }}"><div class="card-body">
            <div class="text-muted small">أرقامٌ بلا اسم</div>
            <div class="stat {{ $orphans->count() ? 'text-warning' : '' }}">{{ $orphans->count() }}</div>
        </div></div>
    </div>
</div>

@if ($orphans->count())
    {{--
        هذا أهمُّ تنبيهٍ في الشاشة: رقمٌ يبصم ولا اسمَ له يعني عاملاً
        يحضر كلَّ يومٍ ولا يظهر في أيّ كشف. ولا يكتشفه أحدٌ إلا في آخر
        الشهر حين يُسأل عن غيابه.
    --}}
    <div class="alert alert-warning no-print">
        <strong><i class="bi bi-exclamation-triangle"></i> {{ $orphans->count() }} رقماً يبصم بلا اسم.</strong>
        وحضورُ أصحابها لا يظهر في الكشوف حتى تُربط.
        @can('pins.view')
            <a href="{{ route('pins.index') }}" class="alert-link">اربطها الآن</a>.
        @endcan
    </div>
@endif

{{--
    تنبيهُ الساعة — ويُعرض لمن لا يفتح شاشةَ الساعة أصلاً.

    ويُعدُّ ما لم يُقَرَّ به لا ما هو مفتوح: من قدّم الساعةَ وبصم ثم أعادها
    أُغلق حدثُه في دقائق — وهو أفصحُ ما يكون على قصدٍ — فلو عُدَّ المفتوحُ
    وحدَه لَما رآه أحدٌ أبداً.
--}}
@can('clock.view')
    @if ($clockEvents->isNotEmpty())
        @php $severe = $clockEvents->first(fn ($e) => $e->severe()); @endphp

        <div class="alert {{ $severe ? 'alert-danger' : 'alert-warning' }} no-print">
            <strong>
                <i class="bi bi-clock-history"></i>
                {{ $clockEvents->count() }} حدثَ ساعةٍ لم يُقَرّ به.
            </strong>

            @if ($severe)
                وفيها <strong>تغييرٌ مقصودٌ مؤكَّد</strong> على
                «{{ $severe->device?->name }}» — {{ $severe->driftLabel() }}.
                <span class="d-block small mt-1">
                    وساعةُ الجهاز هي التي تختم البصمة، فما وصل في تلك المدّة مشكوكٌ فيه.
                </span>
            @else
                وأكثرُ الانحرافِ علّةٌ في الجهاز لا في الناس — والتقريرُ يفصّل.
            @endif

            <a href="{{ route('clock.index', ['open' => 'new']) }}" class="alert-link">
                افتح التقرير
            </a>.
        </div>
    @endif
@endcan

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card"><div class="card-body">
            <h2 class="h6 mb-3">الأجهزة</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr>
                        <th></th><th>الجهاز</th><th>الموقع</th>
                        <th>آخر اتصال</th><th>آخر بصمة</th><th>النبضات</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($devices as $d)
                        @php $s = $stats[$d->id] ?? null; @endphp
                        <tr>
                            <td><span class="dot {{ $d->isOnline() ? 'dot-on' : 'dot-off' }}"></span></td>
                            <td>
                                @can('devices.view')
                                    <a href="{{ route('devices.show', $d) }}">{{ $d->name }}</a>
                                @else
                                    {{ $d->name }}
                                @endcan
                                @unless ($d->is_active)<span class="badge bg-secondary">مُطفأ</span>@endunless
                            </td>
                            <td>{{ $d->site?->name ?? '—' }}</td>
                            <td class="num small">{{ $d->lastSeenLabel() }}</td>
                            <td class="num small">{{ $s ? Clock::stamp($s->last_at) : '—' }}</td>
                            <td class="num">{{ number_format((int) ($s->total ?? 0)) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">
                            لا أجهزةَ بعد.
                            @can('devices.create')<a href="{{ route('devices.create') }}">أضف أوّلَ جهاز</a>.@endcan
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div></div>
    </div>

    <div class="col-lg-5">
        <div class="card"><div class="card-body">
            <h2 class="h6 mb-3">المواقع</h2>
            <table class="table table-sm mb-0">
                <thead><tr><th>الموقع</th><th>الأجهزة</th></tr></thead>
                <tbody>
                @forelse ($sites as $site)
                    <tr><td>{{ $site->name }}</td><td class="num">{{ $site->devices_count }}</td></tr>
                @empty
                    <tr><td colspan="2" class="text-center text-muted py-3">لا مواقعَ بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div></div>

        @can('raw.view')
            @if ($rejected->count())
                <div class="card mt-3"><div class="card-body">
                    <h2 class="h6 mb-3">اتصالاتٌ مردودة</h2>
                    <table class="table table-sm mb-0">
                        <thead><tr><th>الوقت</th><th>الرقم التسلسلي</th><th>السبب</th></tr></thead>
                        <tbody>
                        @foreach ($rejected as $r)
                            <tr>
                                <td class="num small">{{ Clock::stamp($r->created_at, 'm-d H:i') }}</td>
                                <td class="mono">{{ $r->serial_number ?? '—' }}</td>
                                <td><span class="badge bg-danger-subtle text-danger-emphasis">{{ $r->reasonLabel() }}</span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <a href="{{ route('raw.index', ['accepted' => '0']) }}" class="small">كلُّ المردود</a>
                </div></div>
            @endif
        @endcan
    </div>
</div>

@endsection
