@extends('layouts.app')
@section('title', 'التأخّر والغياب')

@php
    use App\Services\ShiftWatch;
    use App\Support\Employment;
    use App\Support\ShiftJudge;
@endphp

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">التأخّر والغياب والانصراف المبكّر</h1>
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
        <div class="col-6 col-md-2">
            <label class="form-label small">الموقع</label>
            <select name="site_id" class="form-select form-select-sm">
                <option value="">كل المواقع</option>
                @foreach ($sites as $s)
                    <option value="{{ $s->id }}" @selected($f['site_id'] === $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
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
    رُصد <strong class="num">{{ $total }}</strong> يوماً،
    @if ($shown !== $total) وعُرض منها <strong class="num">{{ $shown }}</strong>، @endif
    @if ($pending)
        ولم يُبتّ في <strong class="num text-danger">{{ $pending }}</strong>
    @else
        وبُتَّ فيها كلِّها
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
                @if ($g['tally']['absent'])
                    <span class="badge bg-danger">غياب {{ $g['tally']['absent'] }}</span>
                @endif
                @if ($g['tally']['leave'])
                    <span class="badge bg-info text-dark">إجازة {{ $g['tally']['leave'] }}</span>
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
            </span>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead><tr>
                        <th>اليوم</th>
                        <th>النوع</th>
                        <th>الدخول</th>
                        <th>الخروج</th>
                        <th>المطلوب خروجاً</th>
                        <th>الرصد</th>
                        <th>القرار</th>
                        {{-- الخليّةُ تتبع العمود لا الزرّ: الرأسُ يظهر فتُفتح الخليّةُ في كل صفّ --}}
                        @can('notes.manage')
                            <th class="no-print">البتُّ فيه</th>
                            <th class="no-print"></th>
                        @endcan
                    </tr></thead>
                    <tbody>
                    @foreach ($g['rows'] as $r)
                        @php
                            $note = $r['note'];
                            $kind = $r['exception'];
                        @endphp
                        <tr>
                            <td class="num">{{ $r['work_date'] }}</td>
                            <td class="text-nowrap">
                                <span class="badge {{ match ($kind) {
                                        'absent' => 'bg-danger',
                                        'leave'  => 'bg-info text-dark',
                                        'late'   => 'bg-warning text-dark',
                                        'short'  => 'bg-secondary',
                                        default  => 'bg-warning text-dark',
                                    } }}">{{ ShiftWatch::kindLabel($kind) }}</span>

                                @if ($r['leave'] ?? null)
                                    <span class="d-block text-muted num" style="font-size:.7rem">
                                        {{ Employment::kind($r['leave']['kind']) }} · إلى {{ $r['leave']['to'] }}
                                    </span>
                                @endif
                            </td>
                            <td class="num">{{ $r['first_time'] }}</td>
                            <td class="num">{{ $r['last_time'] }}</td>
                            <td class="num text-muted">{{ $r['due_out'] }}</td>
                            <td class="text-nowrap">
                                @if ($r['late'])
                                    <span class="badge bg-danger">تأخّر {{ ShiftJudge::hhmm($r['late']) }}</span>
                                @endif
                                @if ($r['short'])
                                    <span class="badge bg-warning text-dark">نقص {{ ShiftJudge::hhmm($r['short']) }}</span>
                                @endif
                                @if (! $r['late'] && ! $r['short'])
                                    —
                                @endif
                            </td>

                            {{-- القرارُ نصٌّ لا حقل: هو ما يُقرأ على الورقة بعد شهرين --}}
                            <td>
                                @if ($kind === 'leave')
                                    بإذن — إجازةٌ مسجَّلة
                                @else
                                    {{ $note?->statusLabel() ?? 'لم يُبتّ فيه' }}
                                @endif

                                @if ($note?->note)
                                    <span class="d-block text-muted" style="font-size:.72rem">{{ $note->note }}</span>
                                @endif
                                @if ($note?->decider)
                                    <span class="d-block text-muted" style="font-size:.7rem">{{ $note->decider->name }}</span>
                                @endif
                            </td>

                            @can('notes.manage')
                                <td class="no-print" style="min-width: 18rem">
                                    @if ($kind === 'leave')
                                        <span class="text-muted small">لا يُسأل عنه — إجازتُه مسجَّلة</span>
                                    @else
                                        <div class="d-flex gap-1">
                                            <select form="n-{{ $r['uid'] }}" name="permitted"
                                                    class="form-select form-select-sm" style="max-width: 7rem">
                                                <option value="0" @selected($note && ! $note->permitted)>بلا إذن</option>
                                                <option value="1" @selected($note && $note->permitted)>بإذن</option>
                                            </select>
                                            <input form="n-{{ $r['uid'] }}" name="reason" value="{{ $note?->reason }}"
                                                   class="form-control form-control-sm" placeholder="السبب — يلزم مع الإذن">
                                        </div>
                                        <input form="n-{{ $r['uid'] }}" name="note" value="{{ $note?->note }}"
                                               class="form-control form-control-sm mt-1" placeholder="ملاحظة">
                                    @endif
                                </td>
                                <td class="no-print text-nowrap">
                                    @unless ($kind === 'leave')
                                        <button form="n-{{ $r['uid'] }}" class="btn btn-sm btn-outline-success py-0">حفظ</button>
                                        @if ($note)
                                            <button form="nd-{{ $r['uid'] }}" class="btn btn-sm btn-outline-danger py-0"
                                                    onclick="return confirm('إلغاءُ القرار؟ يعود اليومُ إلى ما لم يُبتّ فيه.')">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                            </button>
                                        @endif
                                    @endunless
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@empty
    <div class="card"><div class="card-body text-center text-muted py-5">
        لا غيابَ ولا تأخّرَ ولا انصرافَ مبكّراً في هذه المدّة بهذا الترشيح.
    </div></div>
@endforelse

{{--
    النماذجُ خارج الجداول: المتصفّحُ ينتزع «form» من داخل «tr» فيفسد البناء،
    والحقولُ تُنسب إليها بالسمة `form`.
--}}
@can('notes.manage')
    @foreach ($groups as $g)
        @foreach ($g['rows'] as $r)
            @continue($r['exception'] === 'leave')

            <form id="n-{{ $r['uid'] }}" method="POST" action="{{ route('notes.store') }}" class="d-none">
                @csrf
                <input type="hidden" name="site_id" value="{{ $r['site_id'] }}">
                <input type="hidden" name="device_pin" value="{{ $r['device_pin'] }}">
                <input type="hidden" name="work_date" value="{{ $r['work_date'] }}">
            </form>
            @if ($r['note'])
                <form id="nd-{{ $r['uid'] }}" method="POST" action="{{ route('notes.destroy', $r['note']) }}" class="d-none">
                    @csrf @method('DELETE')
                </form>
            @endif
        @endforeach
    @endforeach
@endcan

@endsection
