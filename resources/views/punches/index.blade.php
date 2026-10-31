@extends('layouts.app')
@section('title', 'النبضات')

@php use App\Support\Clock; @endphp

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">النبضات</h1>
    @can('page.print')
        <a href="{{ route('punches.export', request()->query()) }}" class="btn btn-sm btn-outline-success no-print">
            <i class="bi bi-file-earmark-spreadsheet"></i> تصدير
        </a>
    @endcan
</div>

<div class="card mb-3 no-print"><div class="card-body">
    <form class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label small">من</label>
            <input type="date" name="from" value="{{ $f['from'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">إلى</label>
            <input type="date" name="to" value="{{ $f['to'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small">الموقع</label>
            <select name="site_id" class="form-select form-select-sm">
                <option value="">كل المواقع</option>
                @foreach ($sites as $s)
                    <option value="{{ $s->id }}" @selected($f['site_id'] === $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small">الجهاز</label>
            <select name="device_id" class="form-select form-select-sm">
                <option value="">كل الأجهزة</option>
                @foreach ($devices as $d)
                    <option value="{{ $d->id }}" @selected($f['device_id'] === $d->id)>{{ $d->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-1">
            <label class="form-label small">الرقم</label>
            <input name="pin" value="{{ $f['pin'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-1">
            <button class="btn btn-sm btn-primary w-100" style="background: var(--brand); border: 0;">عرض</button>
        </div>
    </form>
</div></div>

<div class="card"><div class="card-body p-0">
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
            <thead><tr>
                <th>اللحظة</th><th>يوم العمل</th><th>الموظف</th><th>الرقم</th>
                <th>الموقع</th><th>الجهاز</th><th>النوع</th><th>التحقّق</th>
                @can('punches.delete')<th class="no-print"></th>@endcan
            </tr></thead>
            <tbody>
            @forelse ($punches as $p)
                @php $name = $names[$p->site_id.'|'.$p->device_pin] ?? null; @endphp
                <tr>
                    <td class="num">{{ Clock::stamp($p->punched_at, 'Y-m-d H:i:s') }}</td>
                    <td class="num">{{ $p->work_date->toDateString() }}</td>
                    <td>
                        @if ($name)
                            {{ $name->employee_name }}
                        @else
                            <span class="text-warning">غير مربوط</span>
                        @endif
                    </td>
                    <td class="num mono">{{ $p->device_pin }}</td>
                    <td>{{ $p->site?->name ?? '—' }}</td>
                    <td>{{ $p->device?->name ?? '—' }}</td>
                    <td><span class="badge bg-light text-dark">{{ $p->stateLabel() }}</span></td>
                    <td class="small text-muted">{{ $p->verifyLabel() }}</td>

                    {{--
                        الخليةُ تتبع العمود لا الزرّ: ما دام الرأسُ ظاهراً
                        فالخليةُ تُفتح دائماً وإلا انزاح الجدولُ صفّاً.
                    --}}
                    @can('punches.delete')
                        <td class="no-print">
                            <button form="del-{{ $p->id }}" class="btn btn-sm btn-outline-danger py-0"
                                    onclick="return confirm('حذفُ هذه النبضة نهائي. متأكّد؟')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    @endcan
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">لا نبضاتٍ بهذه المرشّحات.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div></div>

<div class="mt-2 no-print">{{ $punches->links() }}</div>

{{--
    النموذجُ خارج الجدول: المتصفّحُ ينتزع النموذجَ من داخل صفّ الجدول فيفسد البناء،
    والحقولُ تُنسب إليه بالسمة form.
--}}
@can('punches.delete')
    @foreach ($punches as $p)
        <form id="del-{{ $p->id }}" method="POST" action="{{ route('punches.destroy', $p) }}" class="d-none">
            @csrf @method('DELETE')
        </form>
    @endforeach
@endcan

@endsection
