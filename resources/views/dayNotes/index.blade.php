@extends('layouts.app')
@section('title', 'التأخّر والانصراف')

@php
    use App\Support\ShiftJudge;
@endphp

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">التأخّر والانصراف المبكّر</h1>
    @can('page.print')
        <div class="no-print">
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
        <div class="col-6 col-md-3">
            <label class="form-label small">الموقع</label>
            <select name="site_id" class="form-select form-select-sm">
                <option value="">كل المواقع</option>
                @foreach ($sites as $s)
                    <option value="{{ $s->id }}" @selected($f['site_id'] === $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">رقم الجهاز</label>
            <input name="pin" value="{{ $f['pin'] }}" class="form-control form-control-sm" placeholder="مثلاً 14">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">اعرض</label>
            <select name="state" class="form-select form-select-sm">
                <option value="">الكل</option>
                <option value="pending" @selected($f['state'] === 'pending')>ما لم يُبتّ فيه</option>
                <option value="permitted" @selected($f['state'] === 'permitted')>بإذن</option>
                <option value="denied" @selected($f['state'] === 'denied')>بلا إذن</option>
            </select>
        </div>
        <div class="col-6 col-md-1">
            <button class="btn btn-sm btn-primary w-100" style="background: var(--brand); border: 0;">عرض</button>
        </div>
    </form>
</div></div>

{{--
    خلاصةٌ تُطبع: رُصد كذا، ولم يُبتّ في كذا. فورقةٌ تُسلَّم بلا هذا السطر
    يُظنّ أنها كلُّ ما في المدّة، وإنما هي ما رُشّح منها.
--}}
<p class="small text-muted">
    رُصد <strong class="num">{{ $total }}</strong> يوماً في المدّة
    @if ($pending)
        — منها <strong class="num text-danger">{{ $pending }}</strong> لم يُبتّ فيه بعد
    @else
        — بُتَّ فيها كلِّها
    @endif
</p>

<div class="card"><div class="card-body p-0">
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle mb-0">
            <thead><tr>
                <th>م</th>
                <th>الموقع</th>
                <th>الموظف</th>
                <th>اليوم</th>
                <th>الدخول</th>
                <th>الخروج</th>
                <th>الرصد</th>
                <th>القرار</th>
                @can('notes.manage')<th>السببُ والملاحظة</th><th></th>@endcan
            </tr></thead>
            <tbody>
            @forelse ($rows as $i => $r)
                @php $note = $r['note']; @endphp
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td>{{ $sites->firstWhere('id', $r['site_id'])?->name ?? '—' }}</td>
                    <td>
                        {{ $r['employee'] ?? '— غير مربوط —' }}
                        <span class="d-block num text-muted" style="font-size:.72rem">{{ $r['device_pin'] }}</span>
                    </td>
                    <td class="num">{{ $r['work_date'] }}</td>
                    <td class="num">{{ $r['first_time'] }}</td>
                    <td class="num">
                        {{ $r['last_time'] }}
                        <span class="d-block text-muted" style="font-size:.72rem">المطلوب {{ $r['due_out'] }}</span>
                    </td>
                    <td class="text-nowrap">
                        @if ($r['late'])
                            <span class="badge bg-danger">تأخّر {{ ShiftJudge::hhmm($r['late']) }}</span>
                        @endif
                        @if ($r['short'])
                            <span class="badge bg-warning text-dark">نقص {{ ShiftJudge::hhmm($r['short']) }}</span>
                        @endif
                    </td>
                    <td class="text-nowrap">
                        @can('notes.manage')
                            <select form="n-{{ $i }}" name="permitted" class="form-select form-select-sm" style="min-width:7rem">
                                <option value="0" @selected($note && ! $note->permitted)>بلا إذن</option>
                                <option value="1" @selected($note && $note->permitted)>بإذن</option>
                            </select>
                        @else
                            {{ $note?->statusLabel() ?? 'لم يُبتّ فيه' }}
                        @endcan
                        @if ($note)
                            <span class="d-block text-muted" style="font-size:.7rem">{{ $note->decider?->name }}</span>
                        @endif
                    </td>

                    {{--
                        الخليّةُ تتبع العمود لا الزرّ: رأسُ «السبب» يظهر لمن
                        يملك البتّ، فتُفتح الخليّةُ في كل صفٍّ وإلا انزاح الجدول.
                    --}}
                    @can('notes.manage')
                        <td>
                            <input form="n-{{ $i }}" name="reason" value="{{ $note?->reason }}"
                                   class="form-control form-control-sm mb-1" placeholder="السبب — يلزم مع الإذن">
                            <input form="n-{{ $i }}" name="note" value="{{ $note?->note }}"
                                   class="form-control form-control-sm" placeholder="ملاحظة">
                        </td>
                        <td class="text-nowrap">
                            <button form="n-{{ $i }}" class="btn btn-sm btn-outline-success py-0">حفظ</button>
                            @if ($note)
                                <button form="nd-{{ $i }}" class="btn btn-sm btn-outline-danger py-0"
                                        onclick="return confirm('إلغاءُ القرار؟ يعود اليومُ إلى ما لم يُبتّ فيه.')">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                            @endif
                        </td>
                    @endcan
                </tr>
            @empty
                <tr><td colspan="10" class="text-center text-muted py-4">
                    لا تأخّرَ ولا انصرافَ مبكّراً في هذه المدّة — أو لم يُكتب لمواقعها جدولُ دوام.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div></div>

{{--
    النماذجُ خارج الجدول: المتصفّحُ ينتزع «form» من داخل «tr» فيفسد البناء،
    والحقولُ تُنسب إليها بالسمة `form`.
--}}
@can('notes.manage')
    @foreach ($rows as $i => $r)
        <form id="n-{{ $i }}" method="POST" action="{{ route('notes.store') }}" class="d-none">
            @csrf
            <input type="hidden" name="site_id" value="{{ $r['site_id'] }}">
            <input type="hidden" name="device_pin" value="{{ $r['device_pin'] }}">
            <input type="hidden" name="work_date" value="{{ $r['work_date'] }}">
        </form>
        @if ($r['note'])
            <form id="nd-{{ $i }}" method="POST" action="{{ route('notes.destroy', $r['note']) }}" class="d-none">
                @csrf @method('DELETE')
            </form>
        @endif
    @endforeach
@endcan

@endsection
