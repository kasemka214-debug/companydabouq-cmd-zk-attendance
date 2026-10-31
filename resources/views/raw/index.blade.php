@extends('layouts.app')
@section('title', 'سجلّ الاتصالات')

@php use App\Support\Clock; @endphp

@section('content')

<h1 class="h5 mb-1">سجلّ الاتصالات الخام</h1>
<p class="small text-muted">
    كلُّ ما وصل من الأجهزة — مقبولاً كان أو مردوداً. وهذه الشاشةُ هي الجوابُ حين يقول موقعٌ
    «بصمتُ ولم يظهر»: أوصل الطلبُ أصلاً؟ ومن أيّ عنوان؟ وبأيّ رقم؟
    والسجلُّ يُكنس بعد {{ (int) config('zk.raw_retention_days', 30) }} يوماً.
</p>

<div class="card mb-3"><div class="card-body">
    <form class="row g-2">
        <div class="col-md-3">
            <select name="accepted" class="form-select form-select-sm">
                <option value="">الكل</option>
                <option value="1" @selected($accepted === '1')>المقبول</option>
                <option value="0" @selected($accepted === '0')>المردود</option>
            </select>
        </div>
        <div class="col-md-3">
            <input name="serial" value="{{ $serial }}" class="form-control form-control-sm mono"
                   placeholder="رقمٌ تسلسلي…">
        </div>
        <div class="col-md-4">
            <input name="q" value="{{ $q }}" class="form-control form-control-sm"
                   placeholder="ابحث في المسار والعنوان والجسد…">
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-outline-secondary w-100">بحث</button>
        </div>
    </form>

    {{--
        اختصاراتٌ لأكثر الأسئلة تكراراً في التشخيص. والسؤالُ الأوّل منها
        هو الذي لا يُجاب بغيره: هل أرسل الجهازُ صورةً قطّ؟
    --}}
    <div class="mt-2 small">
        <span class="text-muted">أسئلةٌ سريعة:</span>
        <a href="{{ route('raw.index', ['q' => 'PHOTO']) }}" class="me-2">هل وصلت صورة؟</a>
        <a href="{{ route('raw.index', ['q' => 'ATTLOG']) }}" class="me-2">دفعاتُ الحضور</a>
        <a href="{{ route('raw.index', ['q' => 'transaction']) }}" class="me-2">دفعاتُ التحكّم</a>
        <a href="{{ route('raw.index', ['q' => 'registry']) }}" class="me-2">التسجيل</a>
        <a href="{{ route('raw.index', ['accepted' => '0']) }}" class="me-2">المردود</a>
    </div>
</div></div>

<div class="card"><div class="card-body p-0">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th>الوقت</th><th>الرقم التسلسلي</th><th>الجهاز</th><th>العنوان</th>
                <th>الطلب</th><th>الأسطر</th><th>النتيجة</th><th></th>
            </tr></thead>
            <tbody>
            @forelse ($logs as $log)
                <tr class="{{ $log->accepted ? '' : 'table-danger' }}">
                    <td class="num small">{{ Clock::stamp($log->created_at, 'Y-m-d H:i:s') }}</td>
                    <td class="mono small">{{ $log->serial_number ?? '—' }}</td>
                    <td class="small">{{ $log->device?->name ?? '—' }}</td>
                    <td class="mono small">{{ $log->ip }}</td>
                    <td class="mono small">{{ $log->method }} /{{ $log->path }}</td>
                    <td class="num">{{ $log->rows ?: '' }}</td>
                    <td>
                        <span class="badge {{ $log->accepted ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">
                            {{ $log->reasonLabel() }}
                        </span>
                    </td>
                    <td><a href="{{ route('raw.show', $log) }}" class="small">تفصيل</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">لا شيءَ بعد.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div></div>

<div class="mt-2 no-print">{{ $logs->links() }}</div>

@endsection
