@extends('layouts.app')
@section('title', 'تفصيل اتصال')

@php use App\Support\Clock; @endphp

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">تفصيل اتصال #{{ $log->id }}</h1>
    <a href="{{ route('raw.index') }}" class="btn btn-sm btn-outline-secondary">رجوع</a>
</div>

<div class="card mb-3"><div class="card-body">
    <table class="table table-sm mb-0">
        <tr><th class="w-25">الوقت</th><td class="num">{{ Clock::stamp($log->created_at, 'Y-m-d H:i:s') }}</td></tr>
        <tr><th>الرقم التسلسلي</th><td class="mono">{{ $log->serial_number ?? '—' }}</td></tr>
        <tr><th>الجهاز</th><td>{{ $log->device?->name ?? 'غيرُ معروف' }}</td></tr>
        <tr><th>العنوان</th><td class="mono">{{ $log->ip }}</td></tr>
        <tr><th>الطلب</th><td class="mono">{{ $log->method }} /{{ $log->path }}?{{ $log->query }}</td></tr>
        <tr><th>النتيجة</th><td>
            <span class="badge {{ $log->accepted ? 'bg-success' : 'bg-danger' }}">{{ $log->reasonLabel() }}</span>
        </td></tr>
        <tr><th>الأسطر المقروءة</th><td class="num">{{ $log->rows }}</td></tr>
    </table>
</div></div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6">ما أرسله الجهاز</h2>
            {{-- محتوًى جاء من الشبكة: يُعرض نصّاً محجوباً لا HTML --}}
            <pre class="mono small bg-light p-3 rounded mb-0" style="max-height: 28rem; overflow: auto; direction: ltr;">{{ $log->body ?: '— بلا جسد —' }}</pre>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6">ما رددناه عليه</h2>
            <pre class="mono small bg-light p-3 rounded mb-0" style="max-height: 28rem; overflow: auto; direction: ltr;">{{ $log->response ?: '—' }}</pre>
        </div></div>
    </div>
</div>

@endsection
