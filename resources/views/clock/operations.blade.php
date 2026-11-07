@extends('layouts.app')
@section('title', 'سجلُّ عمليات الأجهزة')

@php
    use App\Support\Clock;
    use App\Support\ClockJudge;
@endphp

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">
        <a href="{{ route('clock.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-right"></i>
        </a>
        سجلُّ عمليات الأجهزة
    </h1>
    @can('page.print')
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary no-print">
            <i class="bi bi-printer"></i> طباعة
        </button>
    @endcan
</div>

<div class="alert alert-light border mb-3 small">
    ما بلّغ به كلُّ جهازٍ عمّا جرى عليه — <strong>ومعه رقمُ من فعل</strong>.
    وهو أوسعُ من أحداث الساعة: الحدثُ لا يُفتح إلا إن انحرفت الساعةُ فعلاً، وأمّا من فتح
    القائمةَ ولم يغيّر شيئاً فلا حدثَ له ويبقى أثرُه هنا.
    <br>
    <span class="text-muted">
        ووقتُ «ادّعى الجهاز» بساعةِ الجهاز نفسِها، و«وصل» بساعةِ الخادم. والفرقُ بينهما
        قياسٌ ثانٍ للساعة من طريقٍ لا يمرّ بالبصمات — فيَكشف جهازاً غُيِّرت ساعتُه ولم يبصم
        عليه أحدٌ بعدها.
    </span>
</div>

<div class="card mb-3 no-print"><div class="card-body">
    <form class="row g-2 align-items-end">
        <div class="col-6 col-md-4">
            <label class="form-label small">الجهاز</label>
            <select name="device_id" class="form-select form-select-sm">
                <option value="">كل الأجهزة</option>
                @foreach ($devices as $d)
                    <option value="{{ $d->id }}" @selected($deviceId === $d->id)>{{ $d->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-4">
            <div class="form-check mt-4">
                <input type="checkbox" name="time" value="1" class="form-check-input"
                       id="only-time" @checked($onlyTime)>
                <label class="form-check-label small" for="only-time">
                    ما يمسّ الوقتَ وحدَه
                </label>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <button class="btn btn-sm btn-primary w-100" style="background: var(--brand); border: 0;">عرض</button>
        </div>
    </form>
</div></div>

<div class="card"><div class="card-body p-0">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 op">
            <thead><tr>
                <th>الجهاز</th>
                <th>العملية</th>
                <th>مَن</th>
                <th>ادّعى الجهاز</th>
                <th>وصلَنا</th>
                <th>الفرق</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr class="{{ $r->touchesTime() ? 'table-warning' : '' }}">
                    <td class="small">{{ $r->device?->name ?? '—' }}</td>
                    <td class="{{ $r->touchesTime() ? 'fw-bold text-danger' : '' }}">{{ $r->label() }}</td>
                    <td class="small">
                        @if ($r->operator_pin)
                            {{ $names[$r->operator_pin] ?? 'غير مربوط' }}
                            <span class="mono text-muted">{{ $r->operator_pin }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="num mono small">{{ $r->claimLabel() }}</td>
                    <td class="num mono small text-muted">{{ $r->stampLabel() }}</td>
                    @php $cd = $r->claimDrift(); @endphp
                    <td class="num small {{ $cd !== null && abs($cd) > 300 ? 'text-danger fw-bold' : 'text-muted' }}">
                        {{ $cd === null ? '—' : ClockJudge::describe($cd) }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-5">
                    لم يبلّغ جهازٌ بشيءٍ بعد.
                    <span class="d-block small mt-1">
                        وبعضُ الطُّرُز لا ترفع سجلَّ عملياتها إلا إن فُتحت خانتُه في إعداداتها.
                    </span>
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div></div>

<div class="mt-2 no-print">{{ $rows->links() }}</div>

@endsection

@push('head')
<style>
    .op { font-size: .82rem; }
    .op th, .op td { padding: .35rem .5rem; }

    @media print {
        @page { size: A4 landscape; margin: 10mm; }

        .op { font-size: 9pt; }
        .op th, .op td { padding: 2.2mm 1.4mm !important; border: .5pt solid #888 !important; }
        .op th { background: #e8f2f2 !important; }
        .table-warning { background: #fff3cd !important; }
    }
</style>
@endpush
