@extends('layouts.app')
@section('title', 'تقرير الموظف')

@php
    use App\Services\AttendanceGrid;
    use Illuminate\Support\Carbon;

    $dates   = $calendar['dates'];
    $dayName = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    $dayTarget = (int) round($hours * 60);

    /* إحصاءُ المدّة — يُحسب هنا مرّةً لا في كل سطر */
    $attended = $absent = $partial = 0;

    if ($person) {
        foreach ($dates as $date => $kind) {
            $cell = $person['days'][$date] ?? null;

            if ($kind !== 'working') {
                continue;
            }

            if ($cell === null) {
                $absent++;
            } else {
                $attended++;
                if (! $cell['complete']) {
                    $partial++;
                }
            }
        }
    }
@endphp

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">تقرير الموظف</h1>
    @if ($person)
        @can('page.print')
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary no-print">
                <i class="bi bi-printer"></i> طباعة
            </button>
        @endcan
    @endif
</div>

<div class="card mb-3 no-print"><div class="card-body">
    <form class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small">الموقع</label>
            <select name="site_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">اختر الموقع…</option>
                @foreach ($sites as $s)
                    <option value="{{ $s->id }}" @selected($siteId === $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small">الموظف</label>
            <select name="pin" class="form-select form-select-sm" @disabled($people->isEmpty())>
                <option value="">{{ $people->isEmpty() ? 'اختر موقعاً أوّلاً' : 'اختر الموظف…' }}</option>
                @foreach ($people as $p)
                    <option value="{{ $p->device_pin }}" @selected($pin === $p->device_pin)>
                        {{ $p->employee_name }} ({{ $p->device_pin }})
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">من</label>
            <input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">إلى</label>
            <input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <button class="btn btn-sm btn-primary w-100" style="background: var(--brand); border: 0;">عرض</button>
        </div>
    </form>
</div></div>

@if (! $person)
    <div class="card"><div class="card-body text-center text-muted py-5">
        اختر موقعاً ثم موظفاً لعرض تقريره.
    </div></div>
@else
    @php $diff = $person['total'] - $target; @endphp

    <div class="card mb-3"><div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <h2 class="h5 mb-1">{{ $person['name'] ?? 'رقم '.$person['pin'] }}</h2>
                <div class="text-muted small">
                    {{ $person['job'] ?: 'بلا مسمّى' }} ·
                    <span class="mono">{{ $person['pin'] }}</span>
                    @if ($person['ref'])· دابوق <span class="mono">{{ $person['ref'] }}</span>@endif
                </div>
                <div class="text-muted small"><i class="bi bi-geo-alt"></i> {{ $site }}</div>
            </div>

            <div class="col-md-8">
                <div class="row g-2 text-center">
                    <div class="col"><div class="border rounded py-1">
                        <div class="stat" style="font-size:1.1rem">{{ $calendar['working'] }}</div>
                        <div class="small text-muted">يوم دوام</div>
                    </div></div>
                    <div class="col"><div class="border rounded py-1">
                        <div class="stat text-success" style="font-size:1.1rem">{{ $attended }}</div>
                        <div class="small text-muted">حضر</div>
                    </div></div>
                    <div class="col"><div class="border rounded py-1">
                        <div class="stat {{ $absent ? 'text-danger' : '' }}" style="font-size:1.1rem">{{ $absent }}</div>
                        <div class="small text-muted">غاب</div>
                    </div></div>
                    <div class="col"><div class="border rounded py-1">
                        <div class="stat {{ $partial ? 'text-warning' : '' }}" style="font-size:1.1rem">{{ $partial }}</div>
                        <div class="small text-muted">بصمة واحدة</div>
                    </div></div>
                    <div class="col"><div class="border rounded py-1">
                        <div class="stat" style="font-size:1.1rem">{{ AttendanceGrid::hhmm($person['total'], '0:00') }}</div>
                        <div class="small text-muted">الساعات</div>
                    </div></div>
                    <div class="col"><div class="border rounded py-1 {{ $diff < 0 ? 'border-danger' : 'border-success' }}">
                        <div class="stat {{ $diff < 0 ? 'text-danger' : 'text-success' }}" style="font-size:1.1rem">
                            {{ AttendanceGrid::hhmm($diff, '0:00') }}
                        </div>
                        <div class="small text-muted">الفرق</div>
                    </div></div>
                </div>
            </div>
        </div>

        <hr class="my-2">

        <div class="small">
            <span class="num">{{ count($dates) }}</span> يوماً في المدّة
            − <span class="num">{{ $calendar['weekend'] }}</span> جمعة
            @if ($calendar['holiday'])− <span class="num">{{ $calendar['holiday'] }}</span> عطلة @endif
            = <strong class="num">{{ $calendar['working'] }}</strong> يوم دوام
            × <span class="num">{{ rtrim(rtrim(number_format($hours, 1), '0'), '.') }}</span>
            = <strong class="num">{{ AttendanceGrid::hhmm($target, '0:00') }}</strong> ساعة مطلوبة
        </div>
    </div></div>

    <div class="card"><div class="card-body p-0">
        <table class="table table-sm table-bordered align-middle mb-0 emp">
            <thead><tr>
                <th>اليوم</th><th>التاريخ</th><th>الحالة</th>
                <th>دخول</th><th>خروج</th><th>البصمات</th><th>الساعات</th><th>الفرق</th>
                @can('photos.view')<th class="no-print">صورة</th>@endcan
            </tr></thead>
            <tbody>
            @foreach ($dates as $date => $kind)
                @php
                    $d    = Carbon::parse($date);
                    $cell = $person['days'][$date] ?? null;
                    $shot = $photos[$date] ?? null;

                    /* الفرقُ اليوميُّ لا يُحسب إلا في يوم دوام — وغيرُه لا مطلوبَ فيه */
                    $dayDiff = $kind === 'working' ? (($cell['minutes'] ?? 0) - $dayTarget) : null;
                @endphp
                <tr class="{{ $kind !== 'working' ? 'off' : ($cell === null ? 'absent' : '') }}">
                    <td>{{ $dayName[$d->dayOfWeek] }}</td>
                    <td class="num">{{ $date }}</td>
                    <td>
                        @if ($kind === 'weekend')
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">جمعة</span>
                        @elseif ($kind === 'holiday')
                            <span class="badge bg-info-subtle text-info-emphasis">عطلة</span>
                        @elseif ($cell === null)
                            <span class="badge bg-danger-subtle text-danger-emphasis">غياب</span>
                        @elseif (! $cell['complete'])
                            <span class="badge bg-warning-subtle text-warning-emphasis">بصمة واحدة</span>
                        @else
                            <span class="badge bg-success-subtle text-success-emphasis">حضور</span>
                        @endif
                    </td>
                    <td class="num">{{ $cell['first'] ?? '—' }}</td>
                    <td class="num">{{ $cell['last'] ?? '—' }}</td>
                    <td class="num">{{ $cell['punches'] ?? '—' }}</td>
                    <td class="num fw-bold">{{ AttendanceGrid::hhmm($cell['minutes'] ?? null) }}</td>
                    <td class="num {{ $dayDiff !== null && $dayDiff < 0 ? 'text-danger' : ($dayDiff ? 'text-success' : '') }}">
                        {{ $dayDiff === null ? '' : AttendanceGrid::hhmm($dayDiff, '0:00') }}
                    </td>

                    @can('photos.view')
                        <td class="no-print text-center">
                            @if ($shot)
                                <a href="{{ route('photos.index', [
                                        'from' => $date, 'to' => $date,
                                        'site_id' => $siteId, 'pin' => $person['pin'],
                                   ]) }}" class="btn btn-sm btn-outline-secondary py-0 px-1" title="صور اليوم">
                                    <i class="bi bi-camera"></i>
                                    @if ($shot['total'] > 1)<span class="small">{{ $shot['total'] }}</span>@endif
                                </a>
                            @endif
                        </td>
                    @endcan
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="6" class="text-start">المجموع</td>
                    <td class="num">{{ AttendanceGrid::hhmm($person['total'], '0:00') }}</td>
                    <td class="num {{ $diff < 0 ? 'text-danger' : 'text-success' }}">
                        {{ AttendanceGrid::hhmm($diff, '0:00') }}
                    </td>
                    @can('photos.view')<td class="no-print"></td>@endcan
                </tr>
            </tfoot>
        </table>
    </div></div>
@endif

@endsection

@push('head')
<style>
    .emp th, .emp td { padding: .3rem .5rem; }
    .emp .off { background: #f1f3f5; color: #868e96; }
    .emp .absent { background: #fff5f5; }
    .emp tfoot td { background: var(--brand-soft); }

    @media print {
        @page { size: A4 portrait; margin: 10mm; }
        .emp { font-size: 9pt; }
        .emp th, .emp td { padding: 1.6mm 1mm !important; border: .5pt solid #888 !important; }
        .emp thead th { background: #e8f2f2 !important; }
        .emp .off { background: #e9ecef !important; }
        .emp .absent { background: #f8e9e9 !important; }
        .emp tfoot td { background: #e8f2f2 !important; }
        .stat { font-size: 10pt !important; }
    }
</style>
@endpush
