@extends('layouts.app')
@section('title', 'ساعاتُ الأجهزة')

@php
    use App\Support\Clock;
    use App\Support\ClockJudge;
@endphp

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">
        ساعاتُ الأجهزة
        <span class="small text-muted">مَن غيّرها، ومتى أُعيدت، وما مسّه ذلك</span>
    </h1>
    <div class="no-print d-flex gap-2">
        <a href="{{ route('clock.operations') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-list-columns"></i> سجلُّ العمليات
        </a>
        @can('page.print')
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer"></i> طباعة
            </button>
        @endcan
    </div>
</div>

{{--
    شرحُ المقصود قبل الجدول.

    فهذه الشاشةُ تُقرأ في نزاعٍ على أجرٍ، ورقمٌ فيها يُتَّهم به إنسان. فمن
    لم يفهم أن أكثرَ الانحرافِ أعذارٌ بريئةٌ قرأ «تغيّرت ساعةُ الجهاز»
    اتّهاماً — وهي في أكثر الأحوال بطاريةٌ ماتت.
--}}
<div class="alert alert-light border mb-3 small">
    <p class="mb-1">
        <strong>ساعةُ الجهاز هي التي تختم البصمة</strong> — فمن أخّرها سجّل حضوراً قبل وقته.
        ولا سبيلَ إلى قياسها إلا بمقارنة ما يقوله الجهازُ بساعةِ الخادم لحظةَ وصول بصماته.
    </p>
    <p class="mb-0 text-muted">
        وأكثرُ الانحرافِ <strong>بريءٌ</strong>: بطاريةُ ساعةٍ ماتت، أو بلّورةٌ تزحف، أو خطأُ منطقةٍ
        زمنية، أو انقطاعٌ دفع الجهازُ بعده ما فاته. ولا يُقال «<span class="text-danger fw-bold">تغييرٌ
        مقصود</span>» إلا حين تقفز الساعةُ <strong>والجهازُ متّصلٌ يستعلم</strong> — فلا شيءَ يُحرّك
        ساعةً وهي حيّةٌ إلا يدٌ في قائمتها.
    </p>
</div>

<div class="card mb-3 no-print"><div class="card-body">
    <form class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small">الجهاز</label>
            <select name="device_id" class="form-select form-select-sm">
                <option value="">كل الأجهزة</option>
                @foreach ($devices as $d)
                    <option value="{{ $d->id }}" @selected($deviceId === $d->id)>{{ $d->name }}</option>
                @endforeach
            </select>
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
        <div class="col-6 col-md-3">
            <label class="form-label small">التصنيف</label>
            <select name="class" class="form-select form-select-sm">
                <option value="">الكل</option>
                @foreach ($classes as $key => $label)
                    <option value="{{ $key }}" @selected($class === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">الحال</label>
            <select name="open" class="form-select form-select-sm">
                {{--
                    و«الكل» يعرض الزحفَ البطيءَ أيضاً — وهو حدثٌ صحيحٌ
                    يُسجَّل ولا يُقرع له جرس، وإلا قُرع كلَّ أسبوعٍ في كل
                    جهازٍ رخيصٍ فبُلّدت العينُ عن العبث.
                --}}
                <option value="">الكل — حتى ما لا يُنذَر به</option>
                <option value="new" @selected($open === 'new')>ما يستحقّ إنذاراً ولم يُقَرّ به</option>
                <option value="1" @selected($open === '1')>ما زال منحرفاً</option>
            </select>
        </div>
        <div class="col-6 col-md-1">
            <button class="btn btn-sm btn-primary w-100" style="background: var(--brand); border: 0;">عرض</button>
        </div>
    </form>
</div></div>

<div class="card"><div class="card-body p-0">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 ck">
            <thead><tr>
                <th>الجهاز</th>
                <th>التصنيف</th>
                <th>الانحراف</th>
                <th>نافذةُ الشكّ</th>
                <th>المدّة</th>
                <th>كيف عادت</th>
                <th>بصماتٌ مشكوكة</th>
                <th>مَن</th>
                @can('clock.ack')<th class="no-print"></th>@endcan
            </tr></thead>
            <tbody>
            @forelse ($events as $e)
                <tr class="{{ $e->severe() ? 'table-danger' : ($e->blameless() ? '' : 'table-warning') }}">
                    <td>
                        <a href="{{ route('clock.show', $e) }}" class="text-decoration-none text-body fw-bold">
                            {{ $e->device?->name ?? '—' }}
                        </a>
                        <span class="d-block text-muted" style="font-size:.7rem">
                            {{ $e->device?->site?->name ?? 'بلا موقع' }}
                        </span>
                    </td>

                    <td>
                        {{ $e->classLabel() }}
                        <span class="d-block" style="font-size:.7rem">
                            @if ($e->confidence === 'confirmed')
                                <span class="text-danger fw-bold">مؤكَّد</span>
                            @else
                                <span class="text-muted">مُحتمَل</span>
                            @endif
                            · {{ $e->readings }} قراءة
                        </span>
                    </td>

                    <td class="num">{{ $e->driftLabel() }}</td>

                    <td class="num small">
                        {{ Clock::stamp($e->suspect_from, 'm-d H:i') }}
                        ←
                        {{ $e->closed_at ? Clock::stamp($e->closed_at, 'm-d H:i') : 'الآن' }}
                    </td>

                    <td class="num small">{{ $e->spanLabel() }}</td>

                    <td class="small">
                        @if (! $e->closed_at)
                            <span class="text-danger fw-bold">ما زالت منحرفة</span>
                        @else
                            {{ $e->resolutionLabel() }}
                        @endif
                    </td>

                    {{--
                        نطاقُ الضرر — **بوقت الوصول لا بوقت البصمة.**

                        فلا تُستعمل ساعةٌ كاذبةٌ لتحديد ما أفسدته: من قدّم
                        الساعةَ يوماً صارت بصماتُه موسومةً بالغد، فلو
                        رُشّحت بوقت البصمة سقطت من النافذة كلُّها.
                    --}}
                    <td class="num">
                        @if ($e->suspect_count > 0)
                            <strong>{{ number_format($e->suspect_count) }}</strong>
                            <span class="d-block text-muted" style="font-size:.7rem">
                                {{ $e->suspect_pins }} موظفاً
                            </span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td class="small">
                        @forelse ($e->culprits as $pin)
                            <span class="d-block">
                                {{ $names[$pin] ?? 'رقم' }}
                                <span class="mono text-muted">{{ $pin }}</span>
                            </span>
                        @empty
                            <span class="text-muted">لم يبلّغ الجهاز</span>
                        @endforelse
                    </td>

                    {{-- الخليةُ تتبع العمود لا الزرّ --}}
                    @can('clock.ack')
                        <td class="no-print text-nowrap">
                            @if ($e->acknowledged_at)
                                <span class="text-success small" title="{{ Clock::stamp($e->acknowledged_at) }}">
                                    <i class="bi bi-check2-all"></i>
                                    {{ $e->acknowledgedBy?->name }}
                                </span>
                            @else
                                <button form="ack-{{ $e->id }}" class="btn btn-sm btn-outline-success py-0">
                                    أُقِرُّ به
                                </button>
                            @endif
                        </td>
                    @endcan
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-5">
                    <i class="bi bi-check2-circle fs-3 d-block mb-2 text-success"></i>
                    لا حدثَ — ساعاتُ الأجهزة كلُّها في حدّها.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div></div>

<div class="mt-2 no-print">{{ $events->links() }}</div>

{{-- النماذجُ خارج الجدول، والأزرارُ تُنسب إليها بالسمة form --}}
@can('clock.ack')
    @foreach ($events as $e)
        @unless ($e->acknowledged_at)
            <form id="ack-{{ $e->id }}" method="POST" action="{{ route('clock.ack', $e) }}" class="d-none">
                @csrf
            </form>
        @endunless
    @endforeach
@endcan

@endsection

@push('head')
<style>
    .ck { font-size: .82rem; }
    .ck th, .ck td { padding: .4rem .5rem; vertical-align: middle; }

    @media print {
        @page { size: A4 landscape; margin: 10mm; }

        .ck { font-size: 9pt; }
        .ck th, .ck td { padding: 2.4mm 1.4mm !important; border: .5pt solid #888 !important; }
        .ck th { background: #e8f2f2 !important; }
        .table-danger  { background: #f8d7da !important; }
        .table-warning { background: #fff3cd !important; }
    }
</style>
@endpush
