@extends('layouts.app')
@section('title', 'التقرير الشهري')

@php
    use App\Services\AttendanceGrid;
    use Illuminate\Support\Carbon;

    $dates  = $calendar['dates'];
    $months = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
               'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
@endphp

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">التقرير الشهري — {{ $months[$month] }} {{ $year }}</h1>
    @can('page.print')
        <div class="no-print">
            <a href="{{ route('reports.monthly.excel', request()->query()) }}" class="btn btn-sm btn-outline-success">
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
            <label class="form-label small">الشهر</label>
            <select name="month" class="form-select form-select-sm">
                @foreach ($months as $m => $label)
                    <option value="{{ $m }}" @selected($month === $m)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">السنة</label>
            <input type="number" name="year" value="{{ $year }}" min="2000" max="2100"
                   class="form-control form-control-sm num">
        </div>
        <div class="col-8 col-md-3">
            <label class="form-label small">الموقع</label>
            <select name="site_id" class="form-select form-select-sm">
                <option value="">كل المواقع</option>
                @foreach ($sites as $s)
                    <option value="{{ $s->id }}" @selected($siteId === $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-4 col-md-2">
            <button class="btn btn-sm btn-primary w-100" style="background: var(--brand); border: 0;">عرض</button>
        </div>
    </form>
</div></div>

{{--
    سطرُ الحساب قبل الجدول.

    وهو أوّلُ ما يُقرأ: كلُّ رقمٍ في عمود «الفرق» مقيسٌ عليه، فإن كان
    المطلوبُ خطأً فكلُّ الأعمدة خطأ. فيُعرض مفصّلاً لا مجملاً —
    أيامُ الشهر، والجُمَع، والعطل، وما بقي، وحاصلُ ضربه.
--}}
<div class="alert alert-light border mb-3">
    <div class="row g-2 align-items-center">
        <div class="col-md-8">
            <span class="num">{{ count($dates) }}</span> يوماً في الشهر
            − <span class="num">{{ $calendar['weekend'] }}</span> جمعة
            @if ($calendar['holiday'])
                − <span class="num">{{ $calendar['holiday'] }}</span> عطلة
            @endif
            = <strong class="num">{{ $calendar['working'] }}</strong> يومَ دوام
            × <span class="num">{{ rtrim(rtrim(number_format($hours, 1), '0'), '.') }}</span> ساعات
            = <strong class="num text-success fs-6">{{ AttendanceGrid::hhmm($target, '0:00') }}</strong> ساعةَ عمل مطلوبة
        </div>
        @can('holidays.manage')
            <div class="col-md-4 text-md-start no-print">
                <button class="btn btn-sm btn-outline-secondary" type="button"
                        data-bs-toggle="collapse" data-bs-target="#holidays">
                    <i class="bi bi-calendar-x"></i> اختيار عطل الشهر
                </button>
            </div>
        @endcan
    </div>
</div>

@can('holidays.manage')
    <div class="collapse no-print" id="holidays">
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6">عطلُ الشهر</h2>
            <p class="small text-muted">
                أشّر على أيام العطل. <strong>والجمعةُ محسوبةٌ تلقائياً</strong> فلا تحتاج تأشيراً —
                وهي مُظلَّلةٌ في الجدول. وما يُحفظ هنا يخصّ هذا الشهر وحدَه.
            </p>

            <form method="POST" action="{{ route('reports.holidays') }}">
                @csrf
                <input type="hidden" name="from" value="{{ $from }}">
                <input type="hidden" name="to" value="{{ $to }}">

                <div class="d-flex flex-wrap gap-1 mb-3">
                    @foreach ($dates as $date => $kind)
                        @php $d = Carbon::parse($date); @endphp
                        <div class="form-check form-check-inline m-0">
                            <input type="checkbox" name="days[]" value="{{ $date }}"
                                   id="h-{{ $date }}" class="form-check-input"
                                   @checked($kind === 'holiday') @disabled($kind === 'weekend')>
                            <label for="h-{{ $date }}"
                                   class="form-check-label small {{ $kind === 'weekend' ? 'text-muted' : '' }}"
                                   style="min-width: 2.6rem">
                                {{ $d->format('j') }}
                                <span class="d-block" style="font-size: .65rem">
                                    {{ ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'][$d->dayOfWeek] }}
                                </span>
                            </label>
                        </div>
                    @endforeach
                </div>

                <button class="btn btn-sm btn-primary" style="background: var(--brand); border: 0;">حفظ العطل</button>
            </form>
        </div></div>
    </div>
@endcan

@forelse ($groups as $g)
    <div class="card mb-3">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0"><i class="bi bi-geo-alt"></i> {{ $g['name'] }}</h2>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0 grid">
                    <thead>
                        <tr>
                            <th class="sticky-1">م</th>
                            <th class="sticky-2">الموظف</th>
                            <th class="sticky-3">المسمّى</th>
                            @foreach ($dates as $date => $kind)
                                @php $d = Carbon::parse($date); @endphp
                                {{--
                                    العطلةُ لا تُعرَف بلونها وحدَه: الطابعةُ
                                    قد تكون بالأبيض والأسود، وصاحبُ الورقة
                                    لا يرى ما رآه صاحبُ الشاشة. فتحتها حرفٌ
                                    يقولها — «ج» للجمعة و«ع» للعطلة.
                                --}}
                                <th class="day {{ $kind !== 'working' ? 'off' : '' }}"
                                    title="{{ $kind === 'weekend' ? 'الجمعة' : ($kind === 'holiday' ? 'عطلة' : $date) }}">
                                    {{ $d->format('j') }}
                                    <span class="d-block mark">
                                        @if ($kind === 'weekend') ج
                                        @elseif ($kind === 'holiday') ع
                                        @endif
                                    </span>
                                </th>
                            @endforeach
                            <th class="tot">المجموع</th>
                            <th class="tot">الفرق</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($g['people'] as $i => $p)
                        @php $diff = $p['total'] - $target; @endphp
                        <tr>
                            <td class="sticky-1 num">{{ $i + 1 }}</td>
                            {{--
                                الاسمُ بابٌ إلى تفصيله: مَن رأى فرقاً في
                                السطر سأل «لماذا؟»، والجوابُ في تقريره
                                يوماً بيوم — فيُفتح بضغطةٍ لا ببحث.
                            --}}
                            {{--
                                المربوطُ يقود إلى تفصيله، وغيرُ المربوط
                                يقود إلى علاجه — لا إلى تقريرٍ بلا اسم.
                                فمن رأى العلّةَ رأى معها بابَها.
                            --}}
                            <td class="sticky-2">
                                @if ($p['name'])
                                    <a href="{{ route('reports.employee', [
                                            'site_id' => $g['site_id'], 'pin' => $p['pin'],
                                            'from' => $from, 'to' => $to,
                                       ]) }}" class="text-decoration-none text-body">
                                        {{ $p['name'] }}
                                        <span class="text-muted mono" style="font-size:.7rem">{{ $p['pin'] }}</span>
                                    </a>
                                @else
                                    <a href="{{ route('pins.index', ['site_id' => $g['site_id']]) }}"
                                       class="text-decoration-none text-warning" title="اربط هذا الرقم باسم">
                                        غير مربوط
                                        <span class="mono" style="font-size:.7rem">{{ $p['pin'] }}</span>
                                    </a>
                                @endif
                            </td>
                            <td class="sticky-3 small text-muted">{{ $p['job'] ?: '—' }}</td>

                            @foreach ($dates as $date => $kind)
                                @php $cell = $p['days'][$date] ?? null; @endphp
                                <td class="day num {{ $kind !== 'working' ? 'off' : '' }}
                                           {{ $cell && ! $cell['complete'] ? 'partial' : '' }}"
                                    @if ($cell) title="{{ $cell['first'] }} ← {{ $cell['last'] }} · {{ $cell['punches'] }} بصمة" @endif>
                                    {{ $cell ? AttendanceGrid::hhmm($cell['minutes'], '·') : '' }}
                                </td>
                            @endforeach

                            <td class="tot num fw-bold">{{ AttendanceGrid::hhmm($p['total'], '0:00') }}</td>
                            <td class="tot num fw-bold {{ $diff < 0 ? 'text-danger' : 'text-success' }}">
                                {{ AttendanceGrid::hhmm($diff, '0:00') }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@empty
    <div class="card"><div class="card-body text-center text-muted py-5">
        لا مواقعَ ولا موظفين. أضف موقعاً واربط أرقامه بالأسماء أوّلاً.
    </div></div>
@endforelse

@endsection

@push('head')
<style>
    /* جدولٌ بأربعين عموداً: خطٌّ صغير، وأعمدةٌ ثابتةٌ لا تغيب عند التمرير */
    .grid { font-size: .72rem; white-space: nowrap; }
    .grid th, .grid td { padding: .2rem .25rem; }
    .grid .day { min-width: 2.6rem; text-align: center; }
    .grid .tot { min-width: 3.6rem; text-align: center; background: var(--brand-soft); }
    .grid .off { background: #f1f3f5; color: #adb5bd; }
    .grid .partial { background: #fff8e1; }
    .grid .mark { font-size: .6rem; font-weight: 400; color: #868e96; line-height: 1; }

    .grid .sticky-1, .grid .sticky-2, .grid .sticky-3 {
        position: sticky; background: #fff; z-index: 2;
    }
    .grid thead .sticky-1, .grid thead .sticky-2, .grid thead .sticky-3 { background: var(--brand-soft); z-index: 3; }
    .grid .sticky-1 { right: 0;      min-width: 2rem;   text-align: center; }
    .grid .sticky-2 { right: 2rem;   min-width: 11rem;  }
    .grid .sticky-3 { right: 13rem;  min-width: 7rem;   }

    /* ═══ الورقة ═══════════════════════════════════════════════════════
     *
     * أحدٌ وثلاثون عمودَ يومٍ وخمسةُ أعمدةٍ ثابتة — ستّةٌ وثلاثون عموداً
     * على ورقةٍ واحدة. فالعرضُ أفقيٌّ لا رأسيّ، والعروضُ مقدَّرةٌ
     * بالمليمتر لا متروكةٌ للمتصفّح: A4 أفقيّاً ٢٩٧ مم، يبقى منها بعد
     * الهوامش ٢٨٧، فالأعمدةُ الثابتةُ ٩١ والمجاميعُ ٢٦ وما بقي — نحو ١٧٠
     * مم — يُقسَّم على أيام الشهر، فيصيب كلَّ يومٍ ٥.٥ مم تقريباً.
     *
     * و`table-layout: fixed` شرطٌ لذلك: بغيره يوزّع المتصفّحُ العروضَ على
     * أطول محتوًى في كل عمود، فيتضخّم عمودُ الاسم ويُسحق باقي الجدول.
     */
    @media print {
        @page { size: A4 landscape; margin: 5mm; }

        .grid { font-size: 5.8pt; table-layout: fixed; }
        .grid th, .grid td { padding: 1.3mm 0.2mm !important; border: 0.4pt solid #888 !important; }

        /* الأعمدةُ الثابتةُ لا تُثبَّت على الورق — لا تمرير فيه */
        .grid .sticky-1, .grid .sticky-2, .grid .sticky-3 { position: static !important; }

        .grid .sticky-1 { width: 6mm;  }
        .grid .sticky-2 { width: 40mm; font-size: 6.6pt; white-space: normal; line-height: 1.15; }
        .grid .sticky-3 { width: 20mm; font-size: 6pt; white-space: normal; line-height: 1.15; }
        .grid .day      { width: auto; }
        .grid .tot      { width: 13mm; font-size: 6.6pt; }

        .grid thead th  { background: #e8f2f2 !important; font-size: 6pt; }
        .grid .off      { background: #e9ecef !important; }
        .grid .partial  { background: #fff3cd !important; }

        .card + .card { break-before: page; page-break-before: always; }
    }
</style>
@endpush
