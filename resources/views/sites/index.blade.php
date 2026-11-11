@extends('layouts.app')
@section('title', 'المواقع')

@php
    use App\Models\Site;
    use Illuminate\Support\Carbon;

    $fallback = Site::defaultSchedule();
@endphp

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
                    <div class="mb-2">
                        <label class="form-label small">ملاحظة</label>
                        <input name="note" class="form-control form-control-sm">
                    </div>

                    {{--
                        جدولُ الدوام — يُترك فارغاً فيرث العامّ. فمن أضاف
                        موقعاً اليومَ وقرّر دوامَه غداً لا يُحبس عن الإضافة.
                    --}}
                    <div class="row g-1 mb-3">
                        <div class="col-4">
                            <label class="form-label small">الدوام من</label>
                            <input type="time" name="work_start" class="form-control form-control-sm">
                        </div>
                        <div class="col-4">
                            <label class="form-label small">إلى</label>
                            <input type="time" name="work_end" class="form-control form-control-sm">
                        </div>
                        <div class="col-4">
                            <label class="form-label small">سماح (د)</label>
                            <input type="number" name="grace_minutes" min="0" max="120"
                                   class="form-control form-control-sm" placeholder="{{ $fallback['grace'] }}">
                        </div>
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
                    <th>الاسم</th><th>الرمز</th><th>الدوام</th><th>الأجهزة</th><th>الأرقام</th>
                    @canany(['sites.edit', 'sites.delete'])<th></th>@endcanany
                </tr></thead>
                <tbody>
                @forelse ($sites as $site)
                    @php
                        $sched = $site->schedule();
                        $own   = $site->hasOwnSchedule();
                        $ends  = Carbon::parse($sched['start'])->addMinutes($sched['minutes'])->format('H:i');
                    @endphp
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

                        {{--
                            الدوامُ يُكتب كما يُقال: «من ٧:٠٠ إلى ٣:٠٠» —
                            والدقائقُ تُشتقّ منهما في المتحكّم. ونهايةٌ قبل
                            بدايةٍ مناوبةٌ ليليةٌ لا خطأ.
                        --}}
                        <td style="min-width: 15rem">
                            @can('sites.edit')
                                <div class="d-flex gap-1 align-items-center">
                                    <input form="s-{{ $site->id }}" type="time" name="work_start"
                                           value="{{ $own ? $sched['start'] : '' }}"
                                           class="form-control form-control-sm" style="max-width: 6.2rem">
                                    <span class="text-muted">←</span>
                                    <input form="s-{{ $site->id }}" type="time" name="work_end"
                                           value="{{ $own ? $ends : '' }}"
                                           class="form-control form-control-sm" style="max-width: 6.2rem">
                                    <input form="s-{{ $site->id }}" type="number" name="grace_minutes"
                                           min="0" max="120" value="{{ $own ? $sched['grace'] : '' }}"
                                           class="form-control form-control-sm" style="max-width: 4.2rem"
                                           title="مهلةُ السماح بالدقائق">
                                </div>
                                @unless ($own)
                                    <span class="text-muted" style="font-size: .7rem">
                                        يرث العامّ: {{ $sched['start'] }} ← {{ $ends }} · سماح {{ $sched['grace'] }} د
                                    </span>
                                @endunless
                            @else
                                <span class="num">{{ $sched['start'] }} ← {{ $ends }}</span>
                                <span class="text-muted" style="font-size: .72rem">· سماح {{ $sched['grace'] }} د</span>
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
                    <tr><td colspan="6" class="text-center text-muted py-4">
                        لا مواقعَ بعد. أضف موقعاً أوّلاً ثم أضف أجهزتَه إليه.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div></div>

        {{--
            جدولٌ واحدٌ للمواقع كلِّها.

            فأكثرُ الشركات دوامُها واحدٌ في مواقعها، وكتابتُه موقعاً موقعاً
            في عشرين موقعاً عملٌ يُنسى في منتصفه — فيبقى نصفُها على جدولٍ
            ونصفُها على آخرَ ولا شيءَ في الشاشة ينبّه.
        --}}
        @can('sites.edit')
            <div class="card mt-3"><div class="card-body">
                <h2 class="h6">جدولٌ لكل المواقع دفعةً واحدة</h2>
                <p class="small text-muted mb-2">
                    يُكتب على المواقع كلِّها ({{ $sites->count() }} موقعاً) ويمحو ما كُتب لكلٍّ منها قبلُ.
                </p>
                <form method="POST" action="{{ route('sites.schedule') }}" class="row g-2 align-items-end"
                      onsubmit="return confirm('سيُكتب هذا الجدولُ على المواقع كلِّها ويمحو جداولَها الحالية. أتُتابع؟')">
                    @csrf
                    <div class="col-6 col-md-3">
                        <label class="form-label small">الدوام من</label>
                        <input type="time" name="work_start" value="{{ $fallback['start'] }}"
                               class="form-control form-control-sm" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small">إلى</label>
                        <input type="time" name="work_end"
                               value="{{ Carbon::parse($fallback['start'])->addMinutes($fallback['minutes'])->format('H:i') }}"
                               class="form-control form-control-sm" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small">مهلة السماح (د)</label>
                        <input type="number" name="grace_minutes" min="0" max="120" value="{{ $fallback['grace'] }}"
                               class="form-control form-control-sm" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <button class="btn btn-sm btn-outline-primary w-100">طبّق على الكلّ</button>
                    </div>
                </form>
            </div></div>
        @endcan
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
