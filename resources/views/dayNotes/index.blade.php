@extends('layouts.app')
@section('title', $f['tab'] === 'leave' ? 'الإجازات المسجَّلة' : 'التأخّر والغياب')

@section('content')

@php
    $onLeave = $f['tab'] === 'leave';
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">
        {{ $onLeave ? 'الإجازاتُ المسجَّلة' : 'التأخّرُ والغيابُ والانصرافُ المبكّر' }}
    </h1>
    @can('page.print')
        <div class="no-print">
            <a href="{{ route('notes.export', request()->query()) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-file-earmark-spreadsheet"></i> تصدير
            </a>
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer"></i> طباعة
            </button>
        </div>
    @endcan
</div>

{{--
    تبويبان: ما يُسأل عنه، وما جوابُه مكتوبٌ سلفاً.

    وهما مسارٌ لا تبويبٌ في المتصفّح — كلُّ تبويبٍ صفحةٌ تُطلب بمفتاحها.
    وبهذا **يتبعه التصديرُ والطباعة**: ورقةُ الاستثناءات لا يُدسّ فيها سطرٌ
    من الإجازات، وورقةُ الإجازات لا تحمل خاناتِ بتٍّ لا تُملأ. وتبويبُ
    المتصفّح يُخفي القسمَ الآخر بـ`display:none` ثم يظهر في إكسل كاملاً.
--}}
<ul class="nav nav-tabs mb-3 no-print">
    @foreach (['exceptions' => 'الاستثناءات', 'leave' => 'الإجازات المسجَّلة'] as $key => $label)
        <li class="nav-item">
            <a class="nav-link @if ($f['tab'] === $key) active @endif"
               href="{{ route('notes.index', array_merge(request()->query(), ['tab' => $key])) }}">
                {{ $label }}
                <span class="badge bg-secondary-subtle text-secondary-emphasis num">{{ $tabs[$key] }}</span>
            </a>
        </li>
    @endforeach
</ul>

<div class="card mb-3 no-print"><div class="card-body">
    <form class="row g-2 align-items-end">
        {{-- والتبويبُ يُحمل مع الترشيح، وإلا رجع العرضُ إلى الاستثناءات --}}
        <input type="hidden" name="tab" value="{{ $f['tab'] }}">

        <div class="col-6 col-md-2">
            <label class="form-label small">من</label>
            <input type="date" name="from" value="{{ $f['from'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">إلى</label>
            <input type="date" name="to" value="{{ $f['to'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">الموقع</label>
            <select name="site_id" class="form-select form-select-sm">
                <option value="">كل المواقع</option>
                @foreach ($sites as $s)
                    <option value="{{ $s->id }}" @selected($f['site_id'] === $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>

        {{-- النوعُ وحالةُ البتّ لا معنى لهما في الإجازات — فلا يُعرضان فيها --}}
        @unless ($onLeave)
            <div class="col-6 col-md-2">
                <label class="form-label small">النوع</label>
                <select name="kind" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    <option value="absent" @selected($f['kind'] === 'absent')>الغياب وحدَه</option>
                    <option value="short" @selected($f['kind'] === 'short')>الانصراف قبل إتمام الدوام</option>
                    <option value="late" @selected($f['kind'] === 'late')>التأخّر وحدَه</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small">الحالة</label>
                <select name="state" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    <option value="pending" @selected($f['state'] === 'pending')>ما لم يُبتّ فيه</option>
                    <option value="permitted" @selected($f['state'] === 'permitted')>بإذن</option>
                    <option value="denied" @selected($f['state'] === 'denied')>بلا إذن</option>
                </select>
            </div>
        @endunless

        <div class="col-6 col-md-1">
            <label class="form-label small">الرقم</label>
            <input name="pin" value="{{ $f['pin'] }}" class="form-control form-control-sm" placeholder="١٤">
        </div>
        <div class="col-6 col-md-1">
            <button class="btn btn-sm btn-primary w-100" style="background: var(--brand); border: 0;">عرض</button>
        </div>
    </form>
</div></div>

{{--
    خلاصةٌ تُطبع: ما رُصد، وما عُرض بعد الترشيح، وما لم يُبتّ فيه. فورقةٌ
    تُسلَّم بلا هذا السطر يُظنّ أنها كلُّ ما في المدّة، وإنما هي ما رُشّح منها.
--}}
<p class="small text-muted">
    من <span class="num">{{ $f['from'] }}</span> إلى <span class="num">{{ $f['to'] }}</span> —
    @if ($onLeave)
        <strong class="num">{{ $total }}</strong> يومَ إجازةٍ مسجَّلة — جوابُها مكتوبٌ سلفاً فلا يُسأل عنها.
        @if ($pending)
            وفي تبويب الاستثناءات <strong class="num text-danger">{{ $pending }}</strong> لم يُبتّ فيه
        @endif
    @else
        رُصد <strong class="num">{{ $total }}</strong> يوماً،
        @if ($shown !== $total) وعُرض منها <strong class="num">{{ $shown }}</strong>، @endif
        @if ($pending)
            ولم يُبتّ في <strong class="num text-danger">{{ $pending }}</strong>
        @else
            وبُتَّ فيها كلِّها
        @endif
    @endif
</p>

@forelse ($groups as $g)
    <div class="card mb-3">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0">
                {{ $g['name'] ?? '— غير مربوط —' }}
                <span class="num text-muted" style="font-size:.78rem">{{ $g['pin'] }}</span>
                <span class="text-muted" style="font-size:.78rem">· {{ $g['site_name'] }}</span>
                @if ($g['job'])
                    <span class="text-muted" style="font-size:.78rem">· {{ $g['job'] }}</span>
                @endif
            </h2>

            <span>
                @if ($onLeave)
                    <span class="badge bg-info text-dark">إجازة {{ $g['tally']['leave'] }} يوماً</span>
                @else
                    @if ($g['tally']['absent'])
                        <span class="badge bg-danger">غياب {{ $g['tally']['absent'] }}</span>
                    @endif
                    @if ($g['tally']['late'])
                        <span class="badge bg-warning text-dark">تأخّر {{ $g['tally']['late'] }}</span>
                    @endif
                    @if ($g['tally']['short'])
                        <span class="badge bg-secondary">انصرافٌ مبكّر {{ $g['tally']['short'] }}</span>
                    @endif
                    @if ($g['tally']['pending'])
                        <span class="badge bg-dark">لم يُبتّ فيه {{ $g['tally']['pending'] }}</span>
                    @endif
                @endif
            </span>
        </div>

        <div class="card-body p-0">
            @if ($onLeave)
                @include('dayNotes._leaves', ['g' => $g])
            @else
                @include('dayNotes._exceptions', ['g' => $g])
            @endif
        </div>
    </div>
@empty
    <div class="card"><div class="card-body text-center text-muted py-5">
        @if ($onLeave)
            لا إجازاتٍ مسجَّلةً في هذه المدّة.
        @else
            لا غيابَ ولا تأخّرَ ولا انصرافَ مبكّراً في هذه المدّة بهذا الترشيح.
        @endif
    </div></div>
@endforelse

@endsection
