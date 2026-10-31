@extends('layouts.app')
@section('title', 'التقرير الأسبوعي')

@php
    use App\Services\AttendanceGrid;
    use Illuminate\Support\Carbon;

    $dates = $calendar['dates'];
    $names = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
@endphp

@section('print_note')
    {{ $from }} ← {{ $to }} ·
    {{ $calendar['working'] }} يوم دوام × {{ rtrim(rtrim(number_format($hours, 1), '0'), '.') }}
    = {{ AttendanceGrid::hhmm($target, '0:00') }} ساعة مطلوبة
@endsection

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">
        التقرير الأسبوعي
        <span class="num small text-muted">{{ $from }} ← {{ $to }}</span>
    </h1>
    @can('page.print')
        <div class="no-print">
            <a href="{{ route('reports.weekly.excel', request()->query()) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-file-earmark-spreadsheet"></i> تصدير
            </a>
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer"></i> طباعة
            </button>
        </div>
    @endcan
</div>

<div class="card mb-3 no-print"><div class="card-body">
    <form class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small">أيُّ أسبوع (اختر يوماً منه)</label>
            <input type="date" name="week" value="{{ $from }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small">الموقع</label>
            <select name="site_id" class="form-select form-select-sm">
                <option value="">كل المواقع</option>
                @foreach ($sites as $s)
                    <option value="{{ $s->id }}" @selected($siteId === $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">رقم موظف</label>
            <input name="pin" value="{{ $pin }}" class="form-control form-control-sm mono" placeholder="الكل">
        </div>
        <div class="col-6 col-md-2">
            <button class="btn btn-sm btn-primary w-100" style="background: var(--brand); border: 0;">عرض</button>
        </div>
    </form>
</div></div>

<div class="alert alert-light border mb-3">
    <span class="num">7</span> أيام
    − <span class="num">{{ $calendar['weekend'] }}</span> جمعة
    @if ($calendar['holiday'])
        − <span class="num">{{ $calendar['holiday'] }}</span> عطلة
    @endif
    = <strong class="num">{{ $calendar['working'] }}</strong> يومَ دوام
    × <span class="num">{{ rtrim(rtrim(number_format($hours, 1), '0'), '.') }}</span>
    = <strong class="num text-success">{{ AttendanceGrid::hhmm($target, '0:00') }}</strong> ساعةَ عمل مطلوبة
</div>

@forelse ($groups as $g)
    @php
        $people = $pin !== ''
            ? array_values(array_filter($g['people'], fn ($p) => $p['pin'] === $pin))
            : $g['people'];
    @endphp

    @continue($people === [])

    <div class="card mb-3">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0"><i class="bi bi-geo-alt"></i> {{ $g['name'] }}</h2>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0 wk">
                    <thead>
                        <tr>
                            <th>م</th><th>الموظف</th><th>المسمّى</th>
                            @foreach ($dates as $date => $kind)
                                @php $d = Carbon::parse($date); @endphp
                                <th class="{{ $kind !== 'working' ? 'off' : '' }}">
                                    {{ $names[$d->dayOfWeek] }}
                                    <span class="d-block num fw-normal" style="font-size:.7rem">{{ $d->format('j/n') }}</span>
                                </th>
                            @endforeach
                            <th class="tot">المجموع</th>
                            <th class="tot">الفرق</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($people as $i => $p)
                        @php $diff = $p['total'] - $target; @endphp
                        <tr>
                            <td class="num">{{ $i + 1 }}</td>
                            <td>
                                @if ($p['name'])
                                    <a href="{{ route('reports.employee', [
                                            'site_id' => $g['site_id'], 'pin' => $p['pin'],
                                            'from' => $from, 'to' => $to,
                                       ]) }}" class="text-decoration-none text-body">
                                        {{ $p['name'] }}
                                        <span class="text-muted mono" style="font-size:.7rem">{{ $p['pin'] }}</span>
                                    </a>
                                @else
                                    <a href="{{ route('pins.index', ['site_id' => $g['site_id']]) }}"
                                       class="text-decoration-none text-warning" title="اربط هذا الرقم باسم">
                                        غير مربوط
                                        <span class="mono" style="font-size:.7rem">{{ $p['pin'] }}</span>
                                    </a>
                                @endif
                            </td>
                            <td class="small text-muted">{{ $p['job'] ?: '—' }}</td>

                            @foreach ($dates as $date => $kind)
                                @php $cell = $p['days'][$date] ?? null; @endphp
                                <td class="num {{ $kind !== 'working' ? 'off' : '' }} {{ $cell && ! $cell['complete'] ? 'partial' : '' }}">
                                    @if ($cell)
                                        <strong>{{ AttendanceGrid::hhmm($cell['minutes'], '·') }}</strong>
                                        {{-- التفصيل في الأسبوعي يتّسع له المقام، وفي الشهري لا --}}
                                        <span class="d-block text-muted" style="font-size:.66rem">
                                            {{ $cell['first'] }} ← {{ $cell['last'] }}
                                        </span>
                                    @endif
                                </td>
                            @endforeach

                            <td class="tot num fw-bold">{{ AttendanceGrid::hhmm($p['total'], '0:00') }}</td>
                            <td class="tot num fw-bold {{ $diff < 0 ? 'text-danger' : 'text-success' }}">
                                {{ AttendanceGrid::hhmm($diff, '0:00') }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@empty
    <div class="card"><div class="card-body text-center text-muted py-5">
        لا موظفين في هذه المدّة.
    </div></div>
@endforelse

@endsection

@push('head')
<style>
    .wk { font-size: .78rem; }
    .wk th, .wk td { padding: .3rem .35rem; text-align: center; }
    .wk td:nth-child(2), .wk td:nth-child(3) { text-align: right; }
    .wk .tot { background: var(--brand-soft); min-width: 4rem; }
    .wk .off { background: #f1f3f5; color: #adb5bd; }
    .wk .partial { background: #fff8e1; }

    /*
     * الأسبوعيُّ اثنا عشرَ عموداً لا ستّةٌ وثلاثون، فيتّسع له العرضُ
     * بخطٍّ مقروءٍ وسطرٍ عريض — وهو المقصودُ من التقرير: يُقرأ لا يُفكّ.
     */
    @media print {
        @page { size: A4 landscape; margin: 8mm; }

        .wk { font-size: 8.5pt; table-layout: fixed; }
        .wk th, .wk td { padding: 2.2mm 1mm !important; border: 0.5pt solid #888 !important; }
        .wk th { background: #e8f2f2 !important; }
        .wk .off { background: #e9ecef !important; }
        .wk .partial { background: #fff3cd !important; }
        .wk td:nth-child(1) { width: 8mm; }
        .wk td:nth-child(2), .wk th:nth-child(2) { width: 48mm; white-space: normal; }
        .wk td:nth-child(3), .wk th:nth-child(3) { width: 26mm; white-space: normal; }

        .card + .card { break-before: page; page-break-before: always; }
    }
</style>
@endpush
