@extends('layouts.app')
@section('title', 'صور البصمات')

@php use App\Support\Clock; @endphp

@section('content')

<h1 class="h5 mb-1">صور البصمات</h1>
<p class="small text-muted">
    لقطةٌ تأخذها كاميرا الجهاز لحظةَ البصمة. تُربط بالبصمة إن وافقتها لحظةً، وتبقى وحدَها إن لم توافق —
    فالكاميرا صوّرت من وقف أمامها وإن ضاعت بصمتُه.
</p>

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

<div class="row g-3">
@forelse ($photos as $p)
        @php $name = $names[$p->site_id.'|'.$p->device_pin] ?? null; @endphp
        <div class="col-6 col-md-4 col-lg-3 col-xl-2">
            <div class="card h-100">
                {{--
                    الصورةُ تُطلب بمسارها لا تُدرَج في الصفحة base64:
                    ستّون صورةً مُدرَجةً تجعل الصفحةَ ميجابايتات، والمتصفّحُ
                    لا يخزّن منها شيئاً فتُعاد في كل فتحة.
                --}}
                <a href="{{ route('photos.show', $p) }}" target="_blank" rel="noopener">
                    <img src="{{ route('photos.show', $p) }}" alt="بصمة {{ $p->device_pin }}"
                         loading="lazy" class="card-img-top"
                         style="aspect-ratio: 3/4; object-fit: cover; background: #eee;">
                </a>
                <div class="card-body p-2">
                    <div class="small fw-bold text-truncate" title="{{ $name->employee_name ?? '' }}">
                        @if ($name)
                            {{ $name->employee_name }}
                        @else
                            <span class="text-warning">غير مربوط</span>
                        @endif
                    </div>
                    <div class="small text-muted">
                        <span class="mono">{{ $p->device_pin }}</span> ·
                        <span class="num">{{ Clock::stamp($p->taken_at, 'm-d H:i:s') }}</span>
                    </div>
                    <div class="small text-muted text-truncate">{{ $p->device?->name }}</div>

                    @unless ($p->punch_id)
                        <span class="badge bg-warning-subtle text-warning-emphasis mt-1">بلا بصمة</span>
                    @endunless

                    @can('photos.delete')
                        <button form="ph-{{ $p->id }}" class="btn btn-sm btn-outline-danger py-0 mt-1 no-print"
                                onclick="return confirm('حذفُ الصورة نهائي. متأكّد؟')">
                            <i class="bi bi-trash"></i>
                        </button>
                    @endcan
                </div>
            </div>
        </div>
@empty
    <div class="col-12">
        <div class="card"><div class="card-body text-center text-muted py-5">
            لا صورَ في هذه المدّة.
            @unless (config('zk.photos'))
                <div class="mt-2 small text-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    واستقبالُ الصور <strong>مُطفأ</strong> في الإعدادات (<span class="mono">ZK_PHOTOS</span>).
                </div>
            @endunless
        </div></div>
    </div>
@endforelse
</div>

<div class="mt-3 no-print">{{ $photos->links() }}</div>

@can('photos.delete')
    @foreach ($photos as $p)
        <form id="ph-{{ $p->id }}" method="POST" action="{{ route('photos.destroy', $p) }}" class="d-none">
            @csrf @method('DELETE')
        </form>
    @endforeach
@endcan

@endsection
