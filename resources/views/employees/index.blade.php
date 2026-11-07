@extends('layouts.app')
@section('title', 'الموظفون')

@php
    use App\Support\Clock;
    use App\Support\Employment;
@endphp

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">
        الموظفون
        <span class="small text-muted">مَن على رأس العمل، ومَن تركَ، ومَن في إجازة</span>
    </h1>
    @can('page.print')
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary no-print">
            <i class="bi bi-printer"></i> طباعة
        </button>
    @endcan
</div>

{{--
    الأحواضُ الأربعة أزراراً لا قائمةً منسدلة: العددُ يُرى قبل أن يُضغط،
    و«لم تُسجَّل حالتُهم» هو قائمةُ العمل — تتقلّص كلّما سُجّل أحد.
--}}
<div class="card mb-3 no-print"><div class="card-body">
    <form class="row g-2 align-items-end mb-3">
        <div class="col-6 col-md-4">
            <label class="form-label small">الموقع</label>
            <select name="site_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">كل المواقع</option>
                @foreach ($sites as $s)
                    <option value="{{ $s->id }}" @selected($siteId === $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-5">
            <label class="form-label small">بحث</label>
            <input name="q" value="{{ $q }}" class="form-control form-control-sm" placeholder="اسم أو رقم…">
        </div>
        <input type="hidden" name="status" value="{{ $status }}">
        <div class="col-6 col-md-2">
            <button class="btn btn-sm btn-outline-secondary w-100">بحث</button>
        </div>
    </form>

    <div class="btn-group btn-group-sm flex-wrap" role="group">
        @php
            $tabs = [
                'serving' => ['على رأس العمل', $counts['serving'], 'btn-outline-success'],
                'leave'    => ['في إجازة',       $counts['leave'],    'btn-outline-secondary'],
                'awaiting' => ['نُقلوا ولم يبصموا', $counts['awaiting'], 'btn-outline-primary'],
                'dormant' => ['لم تُسجَّل حالتُهم', $counts['dormant'], 'btn-outline-warning'],
                'left'    => ['تركوا العمل',    $counts['left'],    'btn-outline-danger'],
                'all'     => ['الكل',            $total,             'btn-outline-dark'],
            ];
        @endphp

        @foreach ($tabs as $key => [$label, $count, $style])
            <a href="{{ route('employees.index', array_merge(request()->query(), ['status' => $key])) }}"
               class="btn {{ $style }} {{ $status === $key ? 'active' : '' }}">
                {{ $label }} <span class="num">({{ number_format($count) }})</span>
            </a>
        @endforeach
    </div>

    @if ($status === 'awaiting' && $counts['awaiting'] > 0)
        <div class="alert alert-primary py-2 small mt-3 mb-0">
            <i class="bi bi-arrow-left-right"></i>
            هؤلاء نُقلوا إلى مواقعهم الجديدة ولم تصل منهم بصمةٌ بعد.
            <strong>والبرنامجُ لا ينقل البصمةَ نفسَها</strong> — فقوالبُ الأصابع في الأجهزة ولا
            تُخزَّن هنا. فليُسجَّل إصبعُ كلٍّ منهم على جهاز موقعه الجديد
            <strong>بالرقم المكتوب في السطر</strong>. ولا يُتَّهمون بغيابٍ حتى تصل أوّلُ بصمة،
            وتنتهي الحالُ من نفسها عندها.
            <br>
            <span class="text-muted">
                وإن سُجّل أحدُهم برقمٍ آخرَ ظهرت بصماتُه «غير مربوطة» — وتُصحَّح بضغطة
                «انسخ الاسم» في شاشة ربط الأرقام.
            </span>
        </div>
    @endif

    @if ($status === 'dormant' && $counts['dormant'] > 0)
        {{--
            وهذا الحوضُ هو العلّةُ التي جاءت الصفحةُ لها.

            فالنظامُ كان يطوي هؤلاء بتخمين — «لم تصل منه بصمةٌ منذ ستّين
            يوماً» — ويُعلن عددَهم ولا يُسمّيهم. وهنا يُسمَّون، ولكلٍّ
            زرّان: نهايةُ خدمةٍ أو إجازة. وكلُّ تسجيلٍ يُنقص العددَ واحداً.
        --}}
        <div class="alert alert-warning py-2 small mt-3 mb-0">
            <i class="bi bi-exclamation-triangle"></i>
            هؤلاء لم تصل منهم بصمةٌ منذ
            <span class="num">{{ (int) config('zk.absence.dormant_days', 60) }}</span> يوماً،
            ولم يُسجَّل لهم تركُ عملٍ ولا إجازة. <strong>فالنظامُ يُخمّن أنهم ليسوا على رأس
            العمل ولا يسأل عن غيابهم</strong> — وتخمينٌ يُخطئ يُسكت غيابَ رجلٍ يعمل.
            سجّل حالةَ كلٍّ منهم ليصير الكشفُ على يقين.
        </div>
    @endif
</div></div>

<div class="card"><div class="card-body p-0">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 emp">
            <thead><tr>
                <th>الموقع</th>
                <th>الرقم</th>
                <th>الموظف</th>
                <th>المسمّى</th>
                <th>الحالة</th>
                <th>آخر بصمة</th>
                @canany(['employees.leave', 'employees.end'])<th class="no-print"></th>@endcanany
            </tr></thead>
            <tbody>
            @forelse ($rows as $p)
                <tr class="{{ $p->until ? 'table-light text-muted' : '' }}">
                    <td class="small">{{ $p->site?->name ?? '—' }}</td>
                    <td class="num mono">{{ $p->device_pin }}</td>
                    <td>
                        {{ $p->employee_name }}
                        @if ($p->employee_ref)
                            <span class="text-muted small">({{ $p->employee_ref }})</span>
                        @endif
                    </td>
                    <td class="small text-muted">{{ $p->job_title ?: '—' }}</td>

                    <td class="small">
                        @if ($p->until)
                            <span class="badge bg-danger-subtle text-danger-emphasis">{{ $p->leftLabel() }}</span>
                            <span class="d-block num text-muted" style="font-size:.72rem">
                                آخرُ يومِ عملٍ {{ $p->until }}
                            </span>

                            {{--
                                والقرارُ قد يكون مكتوباً على صفٍّ آخرَ لهذا
                                الاسم. فيُقال أين هو، وإلّا ضغط المالكُ
                                «أعِدْه إلى العمل» على خانةٍ فارغةٍ أصلاً
                                فلا يتغيّر شيء، فيظنّ الأزرارَ تكذب.
                            --}}
                            @if ($p->decided_elsewhere)
                                <span class="d-block text-muted" style="font-size:.7rem">
                                    <i class="bi bi-link-45deg"></i> مسجَّلٌ على رقمٍ آخرَ يحمل الاسمَ نفسَه
                                </span>
                            @endif

                            @if ($p->punched_after_end)
                                <span class="d-block text-danger fw-bold" style="font-size:.72rem">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    بصم بعد نهاية خدمته ({{ $p->last_punch }})
                                </span>
                            @endif

                        @elseif ($p->on_leave)
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">
                                {{ Employment::kind($p->on_leave['kind']) }}
                            </span>
                            <span class="d-block num text-muted" style="font-size:.72rem">
                                يعود {{ date('Y-m-d', strtotime($p->on_leave['to'].' +1 day')) }}
                            </span>

                        @elseif ($p->awaiting)
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">نُقل ولم يبصم بعد</span>
                            <span class="d-block num text-muted" style="font-size:.72rem">
                                وصل {{ $p->arrived_on }}
                            </span>

                        @elseif ($p->moved_out)
                            <span class="badge bg-light text-dark border">نُقل إلى موقعٍ آخر</span>

                        @elseif ($p->dormant)
                            <span class="badge bg-warning-subtle text-warning-emphasis">لم تُسجَّل حالتُه</span>

                        @else
                            <span class="badge bg-success-subtle text-success-emphasis">على رأس العمل</span>
                        @endif
                    </td>

                    <td class="num small">{{ $p->last_punch ?? '— لم يبصم قطّ —' }}</td>

                    {{-- الخليةُ تتبع العمود لا الزرّ --}}
                    @canany(['employees.leave', 'employees.end'])
                        <td class="no-print text-nowrap">
                            @can('employees.leave')
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0"
                                        data-bs-toggle="modal" data-bs-target="#leave"
                                        data-pin="{{ $p->id }}" data-name="{{ $p->employee_name }}"
                                        data-action="{{ route('employees.leave', $p) }}">
                                    إجازة
                                </button>
                            @endcan

                            @can('employees.move')
                                @unless ($p->until)
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0"
                                            data-bs-toggle="modal" data-bs-target="#movesvc"
                                            data-name="{{ $p->employee_name }}"
                                            data-site="{{ $p->site_id }}"
                                            data-pin="{{ $p->device_pin }}"
                                            data-action="{{ route('employees.move', $p) }}">
                                        نقل
                                    </button>
                                @endunless
                            @endcan

                            @can('employees.end')
                                @if ($p->until)
                                    <button form="back-{{ $p->id }}" class="btn btn-sm btn-outline-success py-0"
                                            onclick="return confirm('إعادةُ {{ $p->employee_name }} إلى العمل تُزيل تسجيلَ نهاية الخدمة عن كل رقمٍ يحمل اسمَه. متأكّد؟')">
                                        أعِدْه للعمل
                                    </button>
                                @else
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0"
                                            data-bs-toggle="modal" data-bs-target="#endsvc"
                                            data-name="{{ $p->employee_name }}"
                                            data-action="{{ route('employees.end', $p) }}">
                                        انتهت خدمتُه
                                    </button>
                                @endif
                            @endcan
                        </td>
                    @endcanany
                </tr>

                {{-- إجازاتُه المسجَّلة — سطرٌ تابعٌ لا عمودٌ يُضيّق الجدول --}}
                @if ($p->own_leaves && ! $p->until)
                    <tr class="{{ $p->until ? '' : '' }}">
                        <td colspan="{{ auth()->user()->can('employees.leave') || auth()->user()->can('employees.end') ? 7 : 6 }}"
                            class="pt-0 pb-2 small text-muted">
                            <span class="me-2">إجازاتُه:</span>
                            @foreach ($p->own_leaves as $l)
                                <span class="badge bg-light text-dark border me-1 num">
                                    {{ Employment::kind($l->kind) }}
                                    {{ substr((string) $l->from_date, 0, 10) }}
                                    ←
                                    {{ substr((string) $l->to_date, 0, 10) }}
                                    @can('employees.leave')
                                        <button form="dl-{{ $l->id }}" class="btn btn-link btn-sm p-0 text-danger no-print"
                                                style="font-size:.7rem"
                                                onclick="return confirm('حذفُ هذه الإجازة يُعيد السؤالَ عن غياب صاحبها في أيامها. متأكّد؟')">✕</button>
                                    @endcan
                                </span>
                            @endforeach
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="7" class="text-center text-muted py-5">
                    @if ($status === 'left')
                        لم يُسجَّل تركُ عملٍ لأحدٍ بعد.
                    @elseif ($status === 'leave')
                        لا أحدَ في إجازةٍ اليوم.
                    @elseif ($status === 'dormant')
                        لا أحدَ بلا حالةٍ مسجَّلة — الكشفُ على يقين.
                    @else
                        لا موظفين. وتُربط الأرقامُ بالأسماء من شاشة «ربط الأرقام».
                    @endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div></div>

{{--
    النماذجُ خارج الجدول — فالمتصفّح ينتزع <form> من داخل <tr> فيفسد
    البناء — والأزرارُ تُنسب إليها بالسمة form.
--}}
@can('employees.end')
    @foreach ($rows as $p)
        @if ($p->until)
            <form id="back-{{ $p->id }}" method="POST" action="{{ route('employees.unend', $p) }}" class="d-none">
                @csrf @method('PUT')
            </form>
        @endif
    @endforeach
@endcan

@can('employees.leave')
    @foreach ($rows as $p)
        @foreach ($p->own_leaves as $l)
            <form id="dl-{{ $l->id }}" method="POST" action="{{ route('employees.leave.destroy', $l) }}" class="d-none">
                @csrf @method('DELETE')
            </form>
        @endforeach
    @endforeach

    {{-- نافذةٌ واحدةٌ تُعاد لكل صفّ — لا نافذةٌ لكل سطر --}}
    <div class="modal fade" id="leave" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" id="leave-form" class="modal-content">
                @csrf
                <div class="modal-header py-2">
                    <strong>تسجيلُ إجازة — <span id="leave-name"></span></strong>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">
                        ولا يُسأل صاحبُها عن غيابٍ في أيامها، ويظهر في كشف الأيام
                        <span class="badge bg-light text-dark border">في إجازة</span> لا «لم يبصم».
                        <br>
                        <strong>وتُسجَّل مرّةً واحدة</strong> وتسري على كل رقمٍ يحمل اسمَه في المواقع كلِّها.
                    </p>

                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small">من تاريخ</label>
                            <input type="date" name="from_date" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">إلى تاريخ</label>
                            <input type="date" name="to_date" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small">نوعُها</label>
                            <select name="kind" class="form-select form-select-sm">
                                @foreach ($kinds as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small">ملاحظة</label>
                            <input name="note" class="form-control form-control-sm" placeholder="اختياري">
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-1">
                    <button class="btn btn-sm btn-primary" style="background: var(--brand); border: 0;">حفظ</button>
                </div>
            </form>
        </div>
    </div>
@endcan

@can('employees.move')
    <div class="modal fade" id="movesvc" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" id="move-form" class="modal-content">
                @csrf
                <div class="modal-header py-2">
                    <strong>نقلٌ إلى موقعٍ آخر — <span id="move-name"></span></strong>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">
                        يُنشأ اسمُه في الموقع الجديد <strong>مفعَّلاً</strong>، فتُنسب بصماتُه هناك إليه
                        من أوّل بصمة. ويتوقّف السؤالُ عن غيابه في موقعه القديم من يوم النقل،
                        <strong>ويبقى تاريخُه في الاثنين كما هو</strong>.
                    </p>

                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>والبرنامجُ لا ينقل البصمةَ نفسَها.</strong>
                        فقوالبُ الأصابع محفوظةٌ في الأجهزة ولا تُخزَّن هنا، ولا تعبر بين طرازٍ وطراز.
                        فبعد الحفظ: سجّل إصبعَه على جهاز الموقع الجديد <strong>بالرقم المكتوب
                        أدناه بعينه</strong>. وحتى تصل أوّلُ بصمةٍ منه يظهر في الكشف
                        <span class="badge bg-secondary-subtle text-secondary-emphasis">نُقل ولم يبصم بعد</span>
                        رماديّاً — ولا يُتَّهم بغياب.
                    </div>

                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label small">الموقع الجديد</label>
                            <select name="site_id" class="form-select form-select-sm" required>
                                <option value="">اختر…</option>
                                @foreach ($sites as $s)
                                    <option value="{{ $s->id }}" data-site="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">يومُ النقل (أوّلُ يومِ دوامٍ هناك)</label>
                            <input type="date" name="moved_on" class="form-control form-control-sm"
                                   value="{{ $today }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">رقمُه في جهاز الموقع الجديد</label>
                            <input name="device_pin" class="form-control form-control-sm mono" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small">ملاحظة</label>
                            <input name="note" class="form-control form-control-sm" placeholder="اختياري">
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-1">
                    <button class="btn btn-sm btn-primary" style="background: var(--brand); border: 0;">نقل</button>
                </div>
            </form>
        </div>
    </div>
@endcan

@can('employees.end')
    <div class="modal fade" id="endsvc" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" id="end-form" class="modal-content">
                @csrf
                <div class="modal-header py-2">
                    <strong>نهايةُ الخدمة — <span id="end-name"></span></strong>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">
                        ولا يُسأل عن غيابه بعد هذا اليوم، ويختفي من كشف الأيام بعده.
                        <strong>وما قبله يبقى كما هو</strong> — فأيامُه عملٌ صُرف عليه أجرُه،
                        وإعادةُ طبع الورقة لا تُسقطها.
                    </p>

                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small">آخرُ يومِ عمل</label>
                            <input type="date" name="left_on" class="form-control form-control-sm"
                                   value="{{ $today }}" max="{{ $today }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">السبب</label>
                            <select name="left_reason" class="form-select form-select-sm">
                                @foreach ($reasons as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-1">
                    <button class="btn btn-sm btn-danger">تسجيل</button>
                </div>
            </form>
        </div>
    </div>
@endcan

@endsection

@push('scripts')
<script>
    /* نافذةٌ واحدةٌ تُملأ من الزرّ الذي فتحها */
    for (const [id, formId, nameId] of [['leave', 'leave-form', 'leave-name'], ['endsvc', 'end-form', 'end-name'], ['movesvc', 'move-form', 'move-name']]) {
        document.getElementById(id)?.addEventListener('show.bs.modal', function (e) {
            const b = e.relatedTarget;
            if (!b) return;

            document.getElementById(formId).action = b.dataset.action;
            document.getElementById(nameId).textContent = b.dataset.name;

            /* ورقمُه الحاليُّ يُقترَح: أكثرُ الأحوال أن يُسجَّل بالرقم نفسِه */
            const pinField = document.querySelector('#move-form [name="device_pin"]');
            if (id === 'movesvc' && pinField) pinField.value = b.dataset.pin || '';
        });
    }
</script>
@endpush

@push('head')
<style>
    .emp { font-size: .85rem; }
    .emp th, .emp td { padding: .4rem .5rem; }

    @media print {
        @page { size: A4; margin: 10mm; }

        .emp { font-size: 9pt; }
        .emp th, .emp td { padding: 2mm 1.4mm !important; border: .5pt solid #888 !important; }
        .emp th { background: #e8f2f2 !important; }
    }
</style>
@endpush
